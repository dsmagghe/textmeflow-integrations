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
 * Business logic: who gets which message, consent handling and asynchronous sending.
 */
class TextMeFlowService
{
    /** @var array<int,array{to:string,text:string,id_order:int,label:string}> */
    private static $queue = array();

    /** @var bool */
    private static $shutdownRegistered = false;

    /**
     * @return bool true when a connection token is stored
     */
    public static function isConnected()
    {
        return TextMeFlowClient::getStoredToken() !== '';
    }

    /**
     * Per-status settings: id_order_state => array('enabled' => bool, 'text' => string).
     *
     * @return array<int,array{enabled:bool,text:string}>
     */
    public static function getStatusConfig()
    {
        $raw = json_decode((string) Configuration::get('TMF_STATUS_CFG'), true);

        return is_array($raw) ? $raw : array();
    }

    /**
     * @param int $idCustomer
     *
     * @return bool
     */
    public static function hasOptIn($idCustomer)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT `optin` FROM `' . _DB_PREFIX_ . 'textmeflow_optin` WHERE `id_customer` = ' . (int) $idCustomer
        );
    }

    /**
     * Record (or withdraw) the WhatsApp consent of a customer.
     *
     * @param int  $idCustomer
     * @param bool $optin
     */
    public static function setOptIn($idCustomer, $optin)
    {
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'textmeflow_optin` (`id_customer`, `optin`, `date_upd`) VALUES ('
            . (int) $idCustomer . ', ' . ($optin ? 1 : 0) . ', NOW())'
        );
    }

    /**
     * Message to the customer after an order status change.
     *
     * @param Order $order
     * @param int   $idOrderState new status
     */
    public static function handleStatusChange(Order $order, $idOrderState)
    {
        if (!self::isConnected()) {
            return;
        }
        $config = self::getStatusConfig();
        $idOrderState = (int) $idOrderState;
        $kinds = TextMeFlowTemplates::getDefaultKindMap();
        if (!isset($config[$idOrderState])) {
            // Not yet configured: only the standard statuses are on by default.
            $enabled = isset($kinds[$idOrderState]);
            $custom = '';
        } else {
            $enabled = !empty($config[$idOrderState]['enabled']);
            $custom = (string) $config[$idOrderState]['text'];
        }
        if (!$enabled) {
            return;
        }
        if (!self::hasOptIn((int) $order->id_customer)) {
            return; // GDPR: only with explicit consent
        }
        $customer = new Customer((int) $order->id_customer);
        $iso = self::getLangIso((int) $order->id_lang);
        $kind = isset($kinds[$idOrderState]) ? $kinds[$idOrderState] : 'generic';
        $template = $custom !== '' ? $custom : TextMeFlowTemplates::getDefault($kind, $iso);

        $state = new OrderState($idOrderState, (int) $order->id_lang);
        $text = TextMeFlowTemplates::render($template, self::buildValues($order, $customer, (string) $state->name));
        $to = self::getCustomerNumber($order);
        if ($to === null) {
            self::logOrder($order, 'no valid phone number for the customer, message not sent', true);

            return;
        }
        self::enqueue($to, $text, (int) $order->id, 'status');
    }

    /**
     * Optional notification to the shop owner for a new order (no opt-in needed).
     *
     * @param Order $order
     */
    public static function handleNewOrder(Order $order)
    {
        if (!self::isConnected() || !(int) Configuration::get('TMF_MERCHANT_NOTIFY')) {
            return;
        }
        $number = TextMeFlowPhone::toE164((string) Configuration::get('TMF_MERCHANT_PHONE'), self::getShopCountryIso());
        if ($number === null) {
            return;
        }
        $customer = new Customer((int) $order->id_customer);
        $iso = self::getLangIso((int) Configuration::get('PS_LANG_DEFAULT'));
        $text = TextMeFlowTemplates::render(
            TextMeFlowTemplates::getDefault('merchant', $iso),
            self::buildValues($order, $customer, '')
        );
        self::enqueue($number, $text, (int) $order->id, 'merchant');
    }

    /**
     * Send a test message to the shop owner, synchronously (the owner clicked a button).
     *
     * @param string $rawNumber
     *
     * @return array{ok:bool,message:string} message is a short machine code
     */
    public static function sendTest($rawNumber)
    {
        $client = TextMeFlowClient::fromConfiguration();
        if (!$client) {
            return array('ok' => false, 'message' => 'not_connected');
        }
        $to = TextMeFlowPhone::toE164($rawNumber, self::getShopCountryIso());
        if ($to === null) {
            return array('ok' => false, 'message' => 'invalid_number');
        }
        $iso = self::getLangIso((int) Context::getContext()->language->id);
        $text = TextMeFlowTemplates::render(
            TextMeFlowTemplates::getDefault('test', $iso),
            array('{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'))
        );
        $result = $client->sendMessage($to, $text);
        if (!$result['ok']) {
            PrestaShopLogger::addLog('TextMeFlow: test message failed (HTTP ' . $result['status'] . ')', 3);

            return array('ok' => false, 'message' => self::errorCode($result));
        }

        return array('ok' => true, 'message' => 'sent');
    }

    /**
     * Queue a message; it is sent after the response has been delivered so that
     * checkout and back-office actions never wait on the API.
     *
     * @param string $to
     * @param string $text
     * @param int    $idOrder
     * @param string $label
     */
    private static function enqueue($to, $text, $idOrder, $label)
    {
        self::$queue[] = array('to' => $to, 'text' => $text, 'id_order' => $idOrder, 'label' => $label);
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(array(__CLASS__, 'flush'));
        }
    }

    /**
     * Send queued messages. Never throws: errors go to the PrestaShop log and, for
     * customer messages, to a private order message.
     */
    public static function flush()
    {
        if (!self::$queue) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $client = TextMeFlowClient::fromConfiguration();
        $queue = self::$queue;
        self::$queue = array();
        foreach ($queue as $item) {
            try {
                if (!$client) {
                    continue;
                }
                $result = $client->sendMessage($item['to'], $item['text']);
                if (!$result['ok']) {
                    $reason = self::errorCode($result);
                    PrestaShopLogger::addLog(
                        'TextMeFlow: ' . $item['label'] . ' message for order ' . (int) $item['id_order'] . ' failed: ' . $reason,
                        3,
                        null,
                        'Order',
                        (int) $item['id_order']
                    );
                    if ($item['label'] === 'status') {
                        self::addPrivateOrderMessage((int) $item['id_order'], 'TextMeFlow: WhatsApp message not sent (' . $reason . ')');
                    }
                } elseif ($item['label'] === 'status') {
                    self::addPrivateOrderMessage((int) $item['id_order'], 'TextMeFlow: WhatsApp message sent to ' . $item['to']);
                }
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('TextMeFlow: ' . $e->getMessage(), 3);
            }
        }
    }

    /**
     * @param array{ok:bool,status:int,data:array} $result
     *
     * @return string
     */
    public static function errorCode(array $result)
    {
        if (!empty($result['data']['error']) && is_string($result['data']['error'])) {
            return preg_replace('/[^a-z0-9_]/i', '', $result['data']['error']);
        }

        return 'http_' . (int) $result['status'];
    }

    /**
     * @param Order    $order
     * @param Customer $customer
     * @param string   $statusName
     *
     * @return array<string,string>
     */
    private static function buildValues(Order $order, Customer $customer, $statusName)
    {
        $context = Context::getContext();
        $currency = new Currency((int) $order->id_currency);
        $total = (float) $order->total_paid;
        try {
            $totalText = $context->getCurrentLocale()->formatPrice($total, $currency->iso_code);
        } catch (Throwable $e) {
            $totalText = number_format($total, 2, ',', '') . ' ' . $currency->iso_code;
        }

        return array(
            '{first_name}' => (string) $customer->firstname,
            '{order_number}' => (string) $order->reference,
            '{order_total}' => (string) $totalText,
            '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
            '{tracking_url}' => self::getTrackingUrl($order),
            '{status}' => $statusName,
        );
    }

    /**
     * @param Order $order
     *
     * @return string carrier tracking URL, or the order history page as fallback
     */
    private static function getTrackingUrl(Order $order)
    {
        $number = (string) $order->getShippingNumber();
        $carrier = new Carrier((int) $order->id_carrier);
        if ($number !== '' && !empty($carrier->url)) {
            return str_replace('@', rawurlencode($number), $carrier->url);
        }

        return Context::getContext()->link->getPageLink('history', true, (int) $order->id_lang);
    }

    /**
     * @param Order $order
     *
     * @return string|null E.164 number from the delivery (or invoice) address
     */
    private static function getCustomerNumber(Order $order)
    {
        foreach (array((int) $order->id_address_delivery, (int) $order->id_address_invoice) as $idAddress) {
            if ($idAddress <= 0) {
                continue;
            }
            $address = new Address($idAddress);
            $iso = Country::getIsoById((int) $address->id_country);
            foreach (array($address->phone_mobile, $address->phone) as $raw) {
                $number = TextMeFlowPhone::toE164((string) $raw, (string) $iso);
                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    /**
     * @return string
     */
    private static function getShopCountryIso()
    {
        $iso = Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));

        return $iso ? (string) $iso : 'BE';
    }

    /**
     * @param int $idLang
     *
     * @return string
     */
    private static function getLangIso($idLang)
    {
        $iso = Language::getIsoById((int) $idLang);

        return $iso ? strtolower((string) $iso) : 'en';
    }

    /**
     * @param Order  $order
     * @param string $message
     * @param bool   $error
     */
    private static function logOrder(Order $order, $message, $error)
    {
        PrestaShopLogger::addLog('TextMeFlow: order ' . (int) $order->id . ': ' . $message, $error ? 2 : 1, null, 'Order', (int) $order->id);
    }

    /**
     * Private (employee-only) message on the order, visible in the order detail page.
     *
     * @param int    $idOrder
     * @param string $text
     */
    private static function addPrivateOrderMessage($idOrder, $text)
    {
        $message = new Message();
        $message->message = $text;
        $message->id_order = (int) $idOrder;
        $message->private = 1;
        $message->add();
    }
}
