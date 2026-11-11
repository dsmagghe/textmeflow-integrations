<?php declare(strict_types=1);

namespace TextMeFlow\Controller;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TextMeFlow\Exception\TextMeFlowApiException;
use TextMeFlow\Service\ConnectionService;
use TextMeFlow\Service\DefaultTexts;
use TextMeFlow\Service\Settings;

/**
 * Admin-API proxy for the administration module. The TextMeFlow token stays on the server:
 * the browser only ever talks to these routes, authenticated as the logged-in administrator.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
final class ConnectController
{
    private const ADMIN_MODULE_URL = '/admin#/sw/settings/textmeflow/index';

    public function __construct(
        private readonly ConnectionService $connection,
        private readonly Settings $settings,
        private readonly SystemConfigService $systemConfig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/api/_action/textmeflow/status', name: 'api.action.textmeflow.status', methods: ['GET'], defaults: ['_acl' => ['system_config:read']])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->connection->status());
    }

    #[Route('/api/_action/textmeflow/connect/start', name: 'api.action.textmeflow.connect.start', methods: ['POST'], defaults: ['_acl' => ['system_config:update']])]
    public function startConnect(Request $request): JsonResponse
    {
        $returnUrl = $this->urlGenerator->generate('api.action.textmeflow.connect.callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBasePath();

        try {
            $url = $this->connection->startConnect($siteUrl, $returnUrl, (string) $request->request->get('locale', 'en'));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['url' => $url]);
    }

    /**
     * The browser returns here from textmeflow.eu. There is no admin token on a redirect, so the
     * route is public and protected by the single-use random "state" saved when the flow started.
     */
    #[Route('/api/_action/textmeflow/connect/callback', name: 'api.action.textmeflow.connect.callback', methods: ['GET'], defaults: ['auth_required' => false])]
    public function callback(Request $request): RedirectResponse
    {
        $base = $request->getSchemeAndHttpHost() . $request->getBasePath() . self::ADMIN_MODULE_URL;
        $state = (string) $request->query->get('state', '');

        if ($request->query->has('error')) {
            // Consume the flow so the state cannot be reused.
            $this->settings->consumeConnectFlow();

            return new RedirectResponse($base . '?tmf=denied');
        }

        $ok = $this->connection->completeConnect((string) $request->query->get('code', ''), $state);

        return new RedirectResponse($base . '?tmf=' . ($ok ? 'connected' : 'failed'));
    }

    #[Route('/api/_action/textmeflow/pair', name: 'api.action.textmeflow.pair.start', methods: ['POST'], defaults: ['_acl' => ['system_config:update']])]
    public function startPair(): JsonResponse
    {
        $shopName = (string) ($this->systemConfig->get('core.basicInformation.shopName') ?: 'Shopware');

        return $this->guard(fn (): array => $this->connection->startPair($shopName));
    }

    #[Route('/api/_action/textmeflow/pair/{pairId}', name: 'api.action.textmeflow.pair.status', methods: ['GET'], requirements: ['pairId' => '[A-Za-z0-9_\-]{1,64}'], defaults: ['_acl' => ['system_config:read']])]
    public function pairStatus(string $pairId): JsonResponse
    {
        return $this->guard(fn (): array => $this->connection->pairStatus($pairId));
    }

    #[Route('/api/_action/textmeflow/test', name: 'api.action.textmeflow.test', methods: ['POST'], defaults: ['_acl' => ['system_config:update']])]
    public function test(Request $request): JsonResponse
    {
        $locale = (string) $request->request->get('locale', 'en');

        return $this->guard(function () use ($request, $locale): array {
            $to = $this->connection->sendTest(
                (string) $request->request->get('phone', ''),
                $this->settings->getDefaultCountry(null),
                DefaultTexts::testMessage($locale)
            );

            return ['sentTo' => $to];
        });
    }

    #[Route('/api/_action/textmeflow/disconnect', name: 'api.action.textmeflow.disconnect', methods: ['POST'], defaults: ['_acl' => ['system_config:update']])]
    public function disconnect(): JsonResponse
    {
        $this->connection->disconnect();

        return new JsonResponse(['connected' => false]);
    }

    /**
     * Runs an API call and turns failures into JSON the administration can translate.
     *
     * @param callable(): array<string, mixed> $call
     */
    private function guard(callable $call): JsonResponse
    {
        try {
            return new JsonResponse($call());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TextMeFlowApiException $e) {
            $status = match (true) {
                $e->isDeviceLimit() => Response::HTTP_CONFLICT,
                $e->getErrorCode() === 'not_connected' => Response::HTTP_BAD_REQUEST,
                default => Response::HTTP_BAD_GATEWAY,
            };

            return new JsonResponse(['error' => $e->getErrorCode(), 'limit' => $e->getLimit()], $status);
        }
    }
}
