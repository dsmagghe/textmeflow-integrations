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
 * Back-office AJAX endpoint. The browser talks to this controller (protected by the
 * admin token); this controller talks to the TextMeFlow API with the stored token,
 * which therefore never reaches the browser.
 */
class AdminTextMeFlowController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        if (!Tools::getValue('ajax')) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules'));
        }
        $module = Module::getInstanceByName('textmeflow');
        if (!$module || !Module::getPermissionStatic((int) $module->id, 'configure')) {
            $this->respond(array('ok' => false, 'error' => 'forbidden'), 403);
        }
    }

    /**
     * Connection status and linked WhatsApp number.
     */
    public function ajaxProcessStatus()
    {
        $client = TextMeFlowClient::fromConfiguration();
        if (!$client) {
            $this->respond(array('ok' => true, 'connected' => false, 'linked' => false));
        }
        $result = $client->me();
        if (!$result['ok']) {
            $this->respond(array('ok' => false, 'error' => TextMeFlowService::errorCode($result)));
        }
        $number = '';
        foreach ((array) (isset($result['data']['wa_sessions']) ? $result['data']['wa_sessions'] : array()) as $session) {
            if (isset($session['status']) && $session['status'] === 'connected') {
                $number = isset($session['phone_number']) ? (string) $session['phone_number'] : '';
                $number = $number !== '' ? $number : '-';
                break;
            }
        }
        $this->respond(array(
            'ok' => true,
            'connected' => true,
            'linked' => $number !== '',
            'number' => $number === '-' ? '' : $number,
            'email' => isset($result['data']['email']) ? (string) $result['data']['email'] : '',
        ));
    }

    /**
     * Start pairing: returns the QR code image (a data URI) and a pair id.
     */
    public function ajaxProcessPair()
    {
        $client = TextMeFlowClient::fromConfiguration();
        if (!$client) {
            $this->respond(array('ok' => false, 'error' => 'not_connected'));
        }
        $result = $client->startPair((string) Configuration::get('PS_SHOP_NAME'));
        if (!$result['ok']) {
            $limit = in_array($result['status'], array(402, 409), true);
            $this->respond(array('ok' => false, 'error' => $limit ? 'device_limit_reached' : TextMeFlowService::errorCode($result)));
        }
        $this->respond($this->pairPayload($result['data']));
    }

    /**
     * Poll the pairing state (the browser calls this every 3 seconds).
     */
    public function ajaxProcessPoll()
    {
        $pairId = (string) Tools::getValue('pair_id');
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $pairId)) {
            $this->respond(array('ok' => false, 'error' => 'invalid_request'));
        }
        $client = TextMeFlowClient::fromConfiguration();
        if (!$client) {
            $this->respond(array('ok' => false, 'error' => 'not_connected'));
        }
        $result = $client->pairStatus($pairId);
        if (!$result['ok']) {
            $this->respond(array('ok' => false, 'error' => TextMeFlowService::errorCode($result)));
        }
        $payload = $this->pairPayload($result['data']);
        $payload['pair_id'] = $pairId;
        $this->respond($payload);
    }

    /**
     * Send the test message to the number the employee typed.
     */
    public function ajaxProcessTest()
    {
        $result = TextMeFlowService::sendTest((string) Tools::getValue('phone'));
        $this->respond(array('ok' => $result['ok'], 'error' => $result['ok'] ? '' : $result['message']));
    }

    /**
     * @param array $data API pair response
     *
     * @return array
     */
    private function pairPayload(array $data)
    {
        $qr = isset($data['qr_png_b64']) && is_string($data['qr_png_b64']) ? $data['qr_png_b64'] : '';
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $qr)) {
            $qr = '';
        }

        return array(
            'ok' => true,
            'pair_id' => isset($data['pair_id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $data['pair_id']) : '',
            'status' => isset($data['status']) ? preg_replace('/[^a-z_]/', '', (string) $data['status']) : 'pending',
            'qr' => $qr,
        );
    }

    /**
     * @param array $payload
     * @param int   $status
     */
    private function respond(array $payload, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload);
        exit;
    }
}
