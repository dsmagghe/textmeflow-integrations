<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TextMeFlow\Exception\TextMeFlowApiException;

/**
 * Thin server-to-server client for the TextMeFlow API (see the shared connect contract).
 * It only ever runs as the result of a merchant action or a queued message, never on page load.
 */
class TextMeFlowClient
{
    public const DEFAULT_BASE_URL = 'https://api.textmeflow.eu/v1';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    /**
     * Exchanges the one-time code from the browser redirect for a connection token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $returnUrl): array
    {
        return $this->request('POST', '/connect/token', null, ['code' => $code, 'return' => $returnUrl]);
    }

    /**
     * @return array<string, mixed>
     */
    public function me(string $token): array
    {
        return $this->request('GET', '/accounts/me', $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function startPair(string $token, string $label): array
    {
        return $this->request('POST', '/accounts/me/pair', $token, ['label' => $label]);
    }

    /**
     * @return array<string, mixed>
     */
    public function pairStatus(string $token, string $pairId): array
    {
        return $this->request('GET', '/accounts/me/pair/' . rawurlencode($pairId), $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $token, string $to, string $text): array
    {
        return $this->request('POST', '/messages', $token, ['to' => $to, 'text' => $text]);
    }

    public function revoke(string $token): void
    {
        $this->request('DELETE', '/connect/token', $token);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $token = null, ?array $json = null): array
    {
        $options = [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'TextMeFlow-Shopware/1.0',
            ],
        ];
        if ($token !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $status = $response->getStatusCode();
            $body = $status === 204 ? [] : $response->toArray(false);
        } catch (ExceptionInterface) {
            // Network problems and non-JSON bodies are both "could not talk to the API".
            throw new TextMeFlowApiException(0, 'network_error');
        }

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        throw new TextMeFlowApiException(
            $status,
            isset($body['error']) && \is_string($body['error']) ? $body['error'] : 'http_' . $status,
            isset($body['limit']) && is_numeric($body['limit']) ? (int) $body['limit'] : null,
        );
    }
}
