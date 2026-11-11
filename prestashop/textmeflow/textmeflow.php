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

require_once __DIR__ . '/classes/TextMeFlowClient.php';
require_once __DIR__ . '/classes/TextMeFlowPhone.php';
require_once __DIR__ . '/classes/TextMeFlowTemplates.php';
require_once __DIR__ . '/classes/TextMeFlowService.php';

class TextMeFlow extends Module
{
    public const MODULE_VERSION = '1.0.0';

    /** Configuration keys removed on uninstall */
    public const CONFIG_KEYS = [
        'TEXTMEFLOW_TOKEN', 'TEXTMEFLOW_ACCOUNT', 'TEXTMEFLOW_STATE', 'TEXTMEFLOW_STATE_TS', 'TEXTMEFLOW_ADMIN_URL', 'TEXTMEFLOW_RETURN_URL',
        'TEXTMEFLOW_STATUS_CFG', 'TEXTMEFLOW_MERCHANT_NOTIFY', 'TEXTMEFLOW_MERCHANT_PHONE',
    ];

    public function __construct()
    {
        $this->name = 'textmeflow';
        $this->tab = 'emailing';
        $this->version = self::MODULE_VERSION;
        $this->author = 'TextMeFlow';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('TextMeFlow - WhatsApp order updates', [], 'Modules.Textmeflow.Admin');
        $this->description = $this->trans('Send your customers order updates on WhatsApp, from your own number. Connect in one click.', [], 'Modules.Textmeflow.Admin');
        $this->confirmUninstall = $this->trans('Are you sure? The connection with TextMeFlow will be removed.', [], 'Modules.Textmeflow.Admin');
    }

    /**
     * The module ships XLIFF catalogues (translations/<locale>/) for the domains
     * Modules.Textmeflow.Admin and Modules.Textmeflow.Shop.
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installTab()
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('additionalCustomerFormFields')
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionCustomerAccountUpdate')
            && $this->registerHook('actionObjectCustomerDeleteAfter')
            && Configuration::updateValue('TEXTMEFLOW_MERCHANT_NOTIFY', 0);
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Best effort: revoke the connection on the TextMeFlow side too.
        $client = TextMeFlowClient::fromConfiguration();
        if ($client) {
            $client->revoke();
        }
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }

        return $this->uninstallTab()
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'textmeflow_optin`')
            && parent::uninstall();
    }

    /**
     * @return bool
     */
    private function installDb()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'textmeflow_optin` (
                `id_customer` INT UNSIGNED NOT NULL,
                `optin` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Hidden back-office controller used for the AJAX calls (QR, polling, test).
     *
     * @return bool
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = false;
        $tab->class_name = 'AdminTextMeFlow';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'TextMeFlow';
        }

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminTextMeFlow');
        if ($idTab) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }

    /**
     * Configuration page.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';
        $adminUrl = $this->getConfigureUrl();

        if (Tools::isSubmit('tmf_connect')) {
            $this->startConnect($adminUrl); // redirects
        }
        if (Tools::isSubmit('tmf_disconnect')) {
            $this->disconnect();
            $output .= $this->displayConfirmation($this->trans('The connection has been removed.', [], 'Modules.Textmeflow.Admin'));
        }
        if (Tools::isSubmit('tmf_save')) {
            $output .= $this->saveSettings();
        }
        if (Tools::getValue('tmf_connected') === '1') {
            $output .= $this->displayConfirmation($this->trans('Connected to TextMeFlow. Now scan the QR code with WhatsApp.', [], 'Modules.Textmeflow.Admin'));
        } elseif (Tools::getValue('tmf_error') !== false && Tools::getValue('tmf_error') !== '') {
            $output .= $this->displayError($this->trans('The connection was not completed. Please try again.', [], 'Modules.Textmeflow.Admin'));
        }

        $idLang = (int) $this->context->language->id;
        $config = TextMeFlowService::getStatusConfig();
        $kinds = TextMeFlowTemplates::getDefaultKindMap();
        $statuses = [];
        foreach (OrderState::getOrderStates($idLang) as $state) {
            $id = (int) $state['id_order_state'];
            $statuses[] = [
                'id' => $id,
                'name' => (string) $state['name'],
                'enabled' => isset($config[$id]) ? !empty($config[$id]['enabled']) : isset($kinds[$id]),
                'text' => isset($config[$id]['text']) ? (string) $config[$id]['text'] : '',
            ];
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        $this->context->smarty->assign([
            'tmf_connected' => TextMeFlowService::isConnected(),
            'tmf_form_url' => $adminUrl,
            'tmf_ajax_url' => $this->context->link->getAdminLink('AdminTextMeFlow'),
            'tmf_statuses' => $statuses,
            'tmf_notify' => (int) Configuration::get('TEXTMEFLOW_MERCHANT_NOTIFY'),
            'tmf_merchant_phone' => (string) Configuration::get('TEXTMEFLOW_MERCHANT_PHONE'),
            'tmf_placeholders' => TextMeFlowTemplates::PLACEHOLDERS,
            'tmf_js_strings' => json_encode($this->getJsStrings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * @return string
     */
    private function getConfigureUrl()
    {
        return $this->context->link->getAdminLink(
            'AdminModules',
            true,
            [],
            ['configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name]
        );
    }

    /**
     * Start the browser hand-off to textmeflow.eu (see the shared connect contract).
     *
     * @param string $adminUrl where to return in the back office afterwards
     */
    private function startConnect($adminUrl)
    {
        $state = bin2hex(random_bytes(16));
        Configuration::updateValue('TEXTMEFLOW_STATE', $state);
        Configuration::updateValue('TEXTMEFLOW_STATE_TS', time());
        Configuration::updateValue('TEXTMEFLOW_ADMIN_URL', $adminUrl);
        // The code is bound to the exact return URL. Keep it: in a multilingual shop the
        // callback page may be reached in another language than the employee's one.
        $returnUrl = $this->context->link->getModuleLink($this->name, 'callback', [], true);
        Configuration::updateValue('TEXTMEFLOW_RETURN_URL', $returnUrl);

        $locale = Tools::strtolower((string) $this->context->language->iso_code);
        $query = http_build_query([
            'app' => 'prestashop',
            'site' => Tools::getShopDomainSsl(true),
            'return' => $returnUrl,
            'state' => $state,
            'locale' => in_array($locale, ['nl', 'en', 'fr', 'de'], true) ? $locale : 'en',
        ]);
        Tools::redirect(TextMeFlowClient::getSiteBase() . '/connect?' . $query);
    }

    private function disconnect()
    {
        $client = TextMeFlowClient::fromConfiguration();
        if ($client) {
            $client->revoke();
        }
        Configuration::deleteByName('TEXTMEFLOW_TOKEN');
        Configuration::deleteByName('TEXTMEFLOW_ACCOUNT');
    }

    /**
     * @return string HTML feedback
     */
    private function saveSettings()
    {
        $posted = Tools::getValue('tmf_status');
        $posted = is_array($posted) ? $posted : [];
        $config = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $id = (int) $state['id_order_state'];
            $row = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : [];
            $text = isset($row['text']) ? trim(strip_tags((string) $row['text'])) : '';
            $text = Tools::substr($text, 0, 1000);
            if ($text !== '' && !Validate::isCleanHtml($text)) {
                $text = '';
            }
            $config[$id] = ['enabled' => !empty($row['enabled']), 'text' => $text];
        }
        $notify = (int) (bool) Tools::getValue('tmf_notify');
        $phone = trim((string) Tools::getValue('tmf_merchant_phone'));
        if ($phone !== '' && !preg_match('/^[0-9 +().\/-]{6,25}$/', $phone)) {
            return $this->displayError($this->trans('Please enter a valid mobile number.', [], 'Modules.Textmeflow.Admin'));
        }
        if ($notify && $phone === '') {
            return $this->displayError($this->trans('Enter your own mobile number to receive new-order messages.', [], 'Modules.Textmeflow.Admin'));
        }
        Configuration::updateValue('TEXTMEFLOW_STATUS_CFG', json_encode($config));
        Configuration::updateValue('TEXTMEFLOW_MERCHANT_NOTIFY', $notify);
        Configuration::updateValue('TEXTMEFLOW_MERCHANT_PHONE', $phone);

        return $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Textmeflow.Admin'));
    }

    /**
     * UI strings for the JavaScript on the configuration page (translated server-side).
     *
     * @return array<string,string>
     */
    private function getJsStrings()
    {
        $d = 'Modules.Textmeflow.Admin';

        // trans() returns HTML-escaped text; the JavaScript sets textContent, so decode it here.
        return array_map([$this, 'decodeJsString'], [
            'checking' => $this->trans('Checking...', [], $d),
            'connectedTo' => $this->trans('Connected with WhatsApp number', [], $d),
            'notLinked' => $this->trans('Your WhatsApp is not linked yet.', [], $d),
            'scanHelp' => $this->trans('Open WhatsApp on your phone, go to Settings > Linked devices > Link a device and scan this code.', [], $d),
            'showQr' => $this->trans('Show QR code', [], $d),
            'newCode' => $this->trans('New code', [], $d),
            'waiting' => $this->trans('Waiting for the scan...', [], $d),
            'linked' => $this->trans('WhatsApp connected', [], $d),
            'expired' => $this->trans('The code expired.', [], $d),
            'limit' => $this->trans('Your plan allows no more linked numbers. Upgrade your plan to add another number.', [], $d),
            'upgrade' => $this->trans('Upgrade your plan', [], $d),
            'error' => $this->trans('Something went wrong. Please try again.', [], $d),
            'testSent' => $this->trans('Test message sent. Check your WhatsApp.', [], $d),
            'testInvalid' => $this->trans('Please enter a valid mobile number first.', [], $d),
            'testFailed' => $this->trans('The test message could not be sent. Is your WhatsApp linked?', [], $d),
            'quota' => $this->trans('You have used all messages of your plan this month.', [], $d),
        ]);
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function decodeJsString($text)
    {
        return html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Opt-in checkbox on the customer form (account creation and checkout personal-information step).
     * Never ticked by default.
     *
     * @param array $params
     *
     * @return array<string,FormField>
     */
    public function hookAdditionalCustomerFormFields($params)
    {
        if (!TextMeFlowService::isConnected()) {
            return [];
        }
        $field = new FormField();
        $field->setName('tmf_optin');
        $field->setType('checkbox');
        $field->setRequired(false);
        $field->setLabel($this->trans('Send me my order updates via WhatsApp (via TextMeFlow). You can withdraw this at any time.', [], 'Modules.Textmeflow.Shop'));
        $customer = $this->context->customer;
        if (Validate::isLoadedObject($customer) && $customer->id) {
            $field->setValue(TextMeFlowService::hasOptIn((int) $customer->id) ? '1' : '');
        }

        return ['tmf_optin' => $field];
    }

    /**
     * @param array $params
     */
    public function hookActionCustomerAccountAdd($params)
    {
        $this->storeOptIn(isset($params['newCustomer']) ? $params['newCustomer'] : null);
    }

    /**
     * @param array $params
     */
    public function hookActionCustomerAccountUpdate($params)
    {
        $this->storeOptIn(isset($params['customer']) ? $params['customer'] : null);
    }

    /**
     * Forget the WhatsApp consent of a deleted customer.
     *
     * @param array $params
     */
    public function hookActionObjectCustomerDeleteAfter($params)
    {
        if (isset($params['object']) && $params['object'] instanceof Customer && $params['object']->id) {
            TextMeFlowService::deleteOptIn((int) $params['object']->id);
        }
    }

    /**
     * @param Customer|null $customer
     */
    private function storeOptIn($customer)
    {
        if (!Validate::isLoadedObject($customer) || !TextMeFlowService::isConnected()) {
            return;
        }
        TextMeFlowService::setOptIn((int) $customer->id, (bool) Tools::getValue('tmf_optin'));
    }

    /**
     * @param array $params
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
                return;
            }
            $order = new Order((int) $params['id_order']);
            if (Validate::isLoadedObject($order)) {
                TextMeFlowService::handleStatusChange($order, (int) $params['newOrderStatus']->id);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('TextMeFlow: ' . $e->getMessage(), 3);
        }
    }

    /**
     * @param array $params
     */
    public function hookActionValidateOrder($params)
    {
        try {
            if (!empty($params['order']) && Validate::isLoadedObject($params['order'])) {
                TextMeFlowService::handleNewOrder($params['order']);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('TextMeFlow: ' . $e->getMessage(), 3);
        }
    }
}
