<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TextMeFlow\Exception\TextMeFlowApiException;
use TextMeFlow\Service\TextMeFlowClient;

final class TextMeFlowClientTest extends TestCase
{
    public function testSendMessageSendsBearerAndJson(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"id":"m1","status":"queued"}', ['http_code' => 202]);
        });

        $result = (new TextMeFlowClient($http))->sendMessage('tmfc_abc', '+32470123456', 'Hello');

        self::assertSame('queued', $result['status']);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.textmeflow.eu/v1/messages', $captured['url']);
        self::assertContains('Authorization: Bearer tmfc_abc', $captured['options']['headers']);
        self::assertSame('{"to":"+32470123456","text":"Hello"}', $captured['options']['body']);
    }

    public function testExchangeCodeSendsNoBearer(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('{"token":"tmfc_x","account_email":"a@b.c","plan":"free","connected_numbers":0}');
        });

        $result = (new TextMeFlowClient($http))->exchangeCode('code1', 'https://shop.example/cb');

        self::assertSame('tmfc_x', $result['token']);
        self::assertSame('{"code":"code1","return":"https:\/\/shop.example\/cb"}', $captured['body']);
        foreach ($captured['headers'] as $header) {
            self::assertStringNotContainsString('Authorization', $header);
        }
    }

    public function testInvalidCodeBecomesException(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"invalid_code"}', ['http_code' => 400]));

        try {
            (new TextMeFlowClient($http))->exchangeCode('bad', 'https://shop.example/cb');
            self::fail('Exception expected');
        } catch (TextMeFlowApiException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalid_code', $e->getErrorCode());
            self::assertFalse($e->isTransient());
        }
    }

    public function testDeviceLimitCarriesLimit(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"device_limit_reached","limit":1}', ['http_code' => 402]));

        try {
            (new TextMeFlowClient($http))->startPair('t', 'Shop');
            self::fail('Exception expected');
        } catch (TextMeFlowApiException $e) {
            self::assertTrue($e->isDeviceLimit());
            self::assertSame(1, $e->getLimit());
        }
    }

    public function testRateLimitAndServerErrorsAreTransient(): void
    {
        foreach ([429, 500, 503] as $code) {
            $http = new MockHttpClient(new MockResponse('{"error":"rate_limited"}', ['http_code' => $code]));
            try {
                (new TextMeFlowClient($http))->sendMessage('t', '+32470123456', 'x');
                self::fail('Exception expected');
            } catch (TextMeFlowApiException $e) {
                self::assertTrue($e->isTransient(), (string) $code);
            }
        }
    }

    public function testInvalidRecipientIsPermanent(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"invalid_recipient_must_be_e164"}', ['http_code' => 422]));

        try {
            (new TextMeFlowClient($http))->sendMessage('t', '0470', 'x');
            self::fail('Exception expected');
        } catch (TextMeFlowApiException $e) {
            self::assertFalse($e->isTransient());
            self::assertSame('invalid_recipient_must_be_e164', $e->getErrorCode());
        }
    }

    public function testNetworkFailureIsTransient(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        try {
            (new TextMeFlowClient($http))->me('t');
            self::fail('Exception expected');
        } catch (TextMeFlowApiException $e) {
            self::assertSame(0, $e->getHttpStatus());
            self::assertTrue($e->isTransient());
        }
    }

    public function testRevokeAccepts204(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 204]));

        (new TextMeFlowClient($http))->revoke('t');

        $this->addToAssertionCount(1);
    }

    public function testPairIdIsUrlEncoded(): void
    {
        $url = '';
        $http = new MockHttpClient(function (string $method, string $u) use (&$url): MockResponse {
            $url = $u;

            return new MockResponse('{"status":"pending"}');
        });

        (new TextMeFlowClient($http))->pairStatus('t', 'a/b c');

        self::assertSame('https://api.textmeflow.eu/v1/accounts/me/pair/a%2Fb%20c', $url);
    }

    public function testBaseUrlDefaultsToProduction(): void
    {
        self::assertSame(TextMeFlowClient::DEFAULT_BASE_URL, TextMeFlowClient::resolveBaseUrl(null));
        self::assertSame(TextMeFlowClient::DEFAULT_BASE_URL, TextMeFlowClient::resolveBaseUrl(''));
        self::assertSame(TextMeFlowClient::DEFAULT_BASE_URL, TextMeFlowClient::resolveBaseUrl('ftp://x.example/v1'));
        self::assertSame(TextMeFlowClient::DEFAULT_BASE_URL, TextMeFlowClient::resolveBaseUrl('not a url'));
    }

    public function testBaseUrlFromEnvironmentIsUsedByTheFactory(): void
    {
        self::assertSame('http://127.0.0.1:8099/v1', TextMeFlowClient::resolveBaseUrl(' http://127.0.0.1:8099/v1/ '));

        $url = null;
        $http = new MockHttpClient(function (string $method, string $requestUrl) use (&$url): MockResponse {
            $url = $requestUrl;

            return new MockResponse('', ['http_code' => 204]);
        });

        $_SERVER[TextMeFlowClient::BASE_URL_ENV] = 'https://staging.textmeflow.test/v1';
        try {
            TextMeFlowClient::create($http)->revoke('t');
        } finally {
            unset($_SERVER[TextMeFlowClient::BASE_URL_ENV]);
        }

        self::assertSame('https://staging.textmeflow.test/v1/connect/token', $url);
    }
}
