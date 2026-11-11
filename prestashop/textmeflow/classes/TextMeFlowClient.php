<?php
/**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <support@textmeflow.eu>
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
    const API_BASE = 'https://api.textmeflow.eu/v1';
    const SITE_BASE = 'https://textmeflow.eu';

    /** @var string|null */
    private $token;

    /** @var int */
    private $timeout;

    /**
     * @param string|null $token   Connection token, or null for unauthenticated calls
     * @param int         $timeout Timeout in seconds
     */
    public function __construct($token = null, $timeout = 8)
    {
        $this->token = $token;
        $this->timeout = (int) $timeout;
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
        $stored = (string) Configuration::get('TMF_TOKEN');
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
        Configuration::updateValue('TMF_TOKEN', $crypto->encrypt((string) $token));
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
        return $this->request('POST', '/connect/token', array('code' => $code, 'return' => $returnUrl));
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
        return $this->request('POST', '/accounts/me/pair', array('label' => $label));
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
     * @param string $to   E.164 number
     * @param string $text
     *
     * @return array{ok:bool,status:int,data:array}
     */
    public function sendMessage($to, $text)
    {
        return $this->request('POST', '/messages', array('to' => $to, 'text' => $text));
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
     * @param string     $method
     * @param string     $path
     * @param array|null $body
     *
     * @return array{ok:bool,status:int,data:array}
     */
    private function request($method, $path, $body = null)
    {
        $headers = array('Accept: application/json', 'User-Agent: TextMeFlow-PrestaShop/' . TextMeFlow::MODULE_VERSION);
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $ch = curl_init(self::API_BASE . $path);
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
        curl_close($ch);

        if ($raw === false) {
            return array('ok' => false, 'status' => 0, 'data' => array('error' => $error ?: 'network_error'));
        }
        $data = json_decode((string) $raw, true);

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : array(),
        );
    }
}
