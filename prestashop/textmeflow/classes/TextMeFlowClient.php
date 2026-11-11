<?php

/**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <info@fades.tech>
 * @copyright Fades Management EOOD
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Minimal server-to-server client for the TextMeFlow API.
 *
 * The connection token is stored encrypted in the PrestaShop configuration and is
 * only ever used here, on the server. It is never handed to the browser.
 */
class TextMeFlowClient
{
    public const API_BASE = 'https://api.textmeflow.eu/v1';
    public const SITE_BASE = 'https://textmeflow.eu';

    /** Optional environment variable to point the module at another API (for example a local test server). */
    public const API_URL_ENV = 'TEXTMEFLOW_API_URL';

    /** Optional environment variable for the website that serves the /connect page (same purpose). */
    public const SITE_URL_ENV = 'TEXTMEFLOW_SITE_URL';

    /** @var string|null */
    private $token;

    /** @var int */
    private $timeout;

    /**
     * @param string|null $token Connection token, or null for unauthenticated calls
     * @param int $timeout Timeout in seconds
     */
    public function __construct($token = null, $timeout = 8)
    {
        $this->token = $token;
        $this->timeout = (int) $timeout;
    }

    /**
     * Base URL of the API: production unless TEXTMEFLOW_API_URL is set.
     *
     * @return string
     */
    public static function getApiBase()
    {
        return self::resolveBaseUrl(getenv(self::API_URL_ENV), self::API_BASE);
    }

    /**
     * Base URL of the website with the /connect page: production unless TEXTMEFLOW_SITE_URL is set.
     *
     * @return string
     */
    public static function getSiteBase()
    {
        return self::resolveBaseUrl(getenv(self::SITE_URL_ENV), self::SITE_BASE);
    }

    /**
     * Accepts only an absolute http(s) URL; anything else falls back to the default.
     *
     * @param string|false|null $value
     * @param string $default
     *
     * @return string
     */
    public static function resolveBaseUrl($value, $default)
    {
        $value = is_string($value) ? trim($value) : '';
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($value === '' || !in_array($scheme, ['http', 'https'], true) || !is_string(parse_url($value, PHP_URL_HOST))) {
            return $default;
        }

        return rtrim($value, '/');
    }

    /**
     * Build the client from the stored (encrypted) token.
     *
     * @return TextMeFlowClient|null null when the shop is not connected
     */
    public static function fromConfiguration()
    {
        $token = self::getStoredToken();

        return $token === '' ? null : new self($token);
    }

    /**
     * @return string decrypted token or empty string
     */
    public static function getStoredToken()
    {
        $stored = (string) Configuration::get('TEXTMEFLOW_TOKEN');
        if ($stored === '') {
            return '';
        }
        try {
            $crypto = new PhpEncryption(_NEW_COOKIE_KEY_);
            $plain = $crypto->decrypt($stored);

            return is_string($plain) ? $plain : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Encrypt and store the token.
     *
     * @param string $token
     */
    public static function storeToken($token)
    {
        $crypto = new PhpEncryption(_NEW_COOKIE_KEY_);
        Configuration::updateValue('TEXTMEFLOW_TOKEN', $crypto->encrypt((string) $token));
    }

    /**
     * Exchange the one-time code from the /connect redirect for a connection token.
     *
     * @param string $code
     * @param string $returnUrl
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function exchangeCode($code, $returnUrl)
    {
        return $this->request('POST', '/connect/token', ['code' => $code, 'return' => $returnUrl]);
    }

    /**
     * @return array{ok:bool,status:int,data:array}
     */
    public function me()
    {
        return $this->request('GET', '/accounts/me');
    }

    /**
     * @param string $label
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function startPair($label)
    {
        return $this->request('POST', '/accounts/me/pair', ['label' => $label]);
    }

    /**
     * @param string $pairId
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function pairStatus($pairId)
    {
        return $this->request('GET', '/accounts/me/pair/' . rawurlencode($pairId));
    }

    /**
     * @param string $to E.164 number
     * @param string $text
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function sendMessage($to, $text)
    {
        return $this->request('POST', '/messages', ['to' => $to, 'text' => $text]);
    }

    /**
     * Revoke the connection token on the TextMeFlow side.
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function revoke()
    {
        return $this->request('DELETE', '/connect/token');
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $body
     *
     * @return array{ok:bool,status:int,data:array}
     */
    private function request($method, $path, $body = null)
    {
        $headers = ['Accept: application/json', 'User-Agent: TextMeFlow-PrestaShop/' . TextMeFlow::MODULE_VERSION];
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $ch = curl_init(self::getApiBase() . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // no-op since PHP 8.0, deprecated in 8.5
        }

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => $error ?: 'network_error']];
        }
        $data = json_decode((string) $raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : [],
        ];
    }
}
