<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use TextMeFlow\Exception\TextMeFlowApiException;

/**
 * Everything the administration module does: connect, show status, pair WhatsApp,
 * send a test message and disconnect. The token never leaves the server.
 */
class ConnectionService
{
    public const WEBSITE_URL = 'https://textmeflow.eu';

    public function __construct(
        private readonly Settings $settings,
        private readonly TextMeFlowClient $client,
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * Builds the URL of the TextMeFlow approval screen and remembers the state for the callback.
     *
     * @throws \InvalidArgumentException when the return URL is not https (or localhost)
     */
    public function startConnect(string $siteUrl, string $returnUrl, string $locale): string
    {
        if (!$this->isAllowedReturnUrl($returnUrl)) {
            throw new \InvalidArgumentException('https_required');
        }

        $state = bin2hex(random_bytes(16));
        $this->settings->saveConnectFlow($state, $returnUrl);

        return self::WEBSITE_URL . '/connect?' . http_build_query([
            'app' => 'shopware',
            'site' => $siteUrl,
            'return' => $returnUrl,
            'state' => $state,
            'locale' => $this->supportedLocale($locale),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finishes the connect flow after the browser came back from TextMeFlow.
     *
     * @return bool true when the token was stored
     */
    public function completeConnect(string $code, string $state): bool
    {
        $flow = $this->settings->consumeConnectFlow();
        if ($flow === null || $flow['expires'] < time() || !hash_equals($flow['state'], $state) || $code === '') {
            return false;
        }

        try {
            $result = $this->client->exchangeCode($code, $flow['return']);
        } catch (TextMeFlowApiException) {
            return false;
        }

        if (!isset($result['token']) || !\is_string($result['token']) || $result['token'] === '') {
            return false;
        }

        $this->settings->setToken($result['token']);

        return true;
    }

    /**
     * @return array{connected: bool, tokenInvalid: bool, email: ?string, plan: ?string, phone: ?string, apiError: ?string}
     */
    public function status(): array
    {
        $status = ['connected' => false, 'tokenInvalid' => false, 'email' => null, 'plan' => null, 'phone' => null, 'apiError' => null];
        $token = $this->settings->getToken();
        if ($token === null) {
            return $status;
        }

        try {
            $me = $this->client->me($token);
        } catch (TextMeFlowApiException $e) {
            if ($e->getHttpStatus() === 401 || $e->getHttpStatus() === 403) {
                // Revoked in the TextMeFlow portal: behave as disconnected.
                $this->settings->clearToken();
                $status['tokenInvalid'] = true;

                return $status;
            }
            $status['connected'] = true;
            $status['apiError'] = $e->getErrorCode();

            return $status;
        }

        $status['connected'] = true;
        $status['email'] = isset($me['email']) && \is_string($me['email']) ? $me['email'] : null;
        $status['plan'] = isset($me['plan_code']) && \is_string($me['plan_code']) ? $me['plan_code'] : null;
        foreach ((array) ($me['wa_sessions'] ?? []) as $session) {
            if (\is_array($session) && ($session['status'] ?? null) === 'connected') {
                $status['phone'] = isset($session['phone_number']) ? (string) $session['phone_number'] : '';
                break;
            }
        }

        return $status;
    }

    /**
     * Starts a QR pairing. The QR image is passed on, the token is not.
     *
     * @return array<string, mixed>
     */
    public function startPair(string $shopName): array
    {
        return $this->sanitizePair($this->client->startPair($this->requireToken(), $shopName));
    }

    /**
     * @return array<string, mixed>
     */
    public function pairStatus(string $pairId): array
    {
        return $this->sanitizePair($this->client->pairStatus($this->requireToken(), $pairId));
    }

    /**
     * Sends a test message to the merchant's own number.
     *
     * @return string the E.164 number the message went to
     *
     * @throws \InvalidArgumentException invalid_number when the number cannot be made international
     */
    public function sendTest(string $rawNumber, ?string $countryIso, string $text): string
    {
        $to = $this->phoneNormalizer->normalize($rawNumber, $countryIso);
        if ($to === null) {
            throw new \InvalidArgumentException('invalid_number');
        }

        $this->client->sendMessage($this->requireToken(), $to, $text);

        return $to;
    }

    /**
     * Revokes the token at TextMeFlow (best effort) and forgets it locally.
     */
    public function disconnect(): void
    {
        $token = $this->settings->getToken();
        if ($token !== null) {
            try {
                $this->client->revoke($token);
            } catch (TextMeFlowApiException) {
                // Already revoked or unreachable: the local token is removed either way.
            }
        }
        $this->settings->clearToken();
    }

    public function isAllowedReturnUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1'], true));
    }

    private function supportedLocale(string $locale): string
    {
        $language = strtolower(substr($locale, 0, 2));

        return \in_array($language, ['nl', 'en', 'fr', 'de'], true) ? $language : 'en';
    }

    private function requireToken(): string
    {
        $token = $this->settings->getToken();
        if ($token === null) {
            throw new TextMeFlowApiException(401, 'not_connected');
        }

        return $token;
    }

    /**
     * Only lets through what the page needs; the QR must be a PNG data URL before it reaches an <img>.
     *
     * @param array<string, mixed> $pair
     *
     * @return array<string, mixed>
     */
    private function sanitizePair(array $pair): array
    {
        $qr = $pair['qr_png_b64'] ?? null;
        if (!\is_string($qr) || preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $qr) !== 1) {
            $qr = null;
        }

        return [
            'pairId' => isset($pair['pair_id']) ? (string) $pair['pair_id'] : null,
            'status' => isset($pair['status']) ? (string) $pair['status'] : 'pending',
            'qr' => $qr,
            'expiresAt' => isset($pair['expires_at']) ? (string) $pair['expires_at'] : null,
        ];
    }
}
