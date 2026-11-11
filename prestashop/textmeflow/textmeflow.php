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

require_once __DIR__ . '/classes/TextMeFlowClient.php';
require_once __DIR__ . '/classes/TextMeFlowPhone.php';
require_once __DIR__ . '/classes/TextMeFlowTemplates.php';
require_once __DIR__ . '/classes/TextMeFlowService.php';

class TextMeFlow extends Module
{
    const MODULE_VERSION = '1.0.0';

    /** Configuration keys removed on uninstall */
    const CONFIG_KEYS = array(
        'TMF_TOKEN', 'TMF_ACCOUNT', 'TMF_STATE', 'TMF_STATE_TS', 'TMF_ADMIN_URL',
        'TMF_STATUS_CFG', 'TMF_MERCHANT_NOTIFY', 'TMF_MERCHANT_PHONE',
    );

    public function __construct()
    {
        $this->name = 'textmeflow';
        $this->tab = 'emailing';
        $this->version = self::MODULE_VERSION;
        $this->author = 'TextMeFlow';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->trans('TextMeFlow - WhatsApp order updates', array(), 'Modules.Textmeflow.Admin');
        $this->description = $this->trans('Send your customers order updates on WhatsApp, from your own number. Connect in one click.', array(), 'Modules.Textmeflow.Admin');
        $this->confirmUninstall = $this->trans('Are you sure? The connection with TextMeFlow will be removed.', array(), 'Modules.Textmeflow.Admin');
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
            && Configuration::updateValue('TMF_MERCHANT_NOTIFY', 0);
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
        $tab->active = 0;
        $tab->class_name = 'AdminTextMeFlow';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->name = array();
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
            $output .= $this->displayConfirmation($this->trans('The connection has been removed.', array(), 'Modules.Textmeflow.Admin'));
        }
        if (Tools::isSubmit('tmf_save')) {
            $output .= $this->saveSettings();
        }
        if (Tools::getValue('tmf_connected') === '1') {
            $output .= $this->displayConfirmation($this->trans('Connected to TextMeFlow. Now scan the QR code with WhatsApp.', array(), 'Modules.Textmeflow.Admin'));
        } elseif (Tools::getValue('tmf_error') !== false && Tools::getValue('tmf_error') !== '') {
            $output .= $this->displayError($this->trans('The connection was not completed. Please try again.', array(), 'Modules.Textmeflow.Admin'));
        }

        $idLang = (int) $this->context->language->id;
        $config = TextMeFlowService::getStatusConfig();
        $kinds = TextMeFlowTemplates::getDefaultKindMap();
        $statuses = array();
        foreach (OrderState::getOrderStates($idLang) as $state) {
            $id = (int) $state['id_order_state'];
            $statuses[] = array(
                'id' => $id,
                'name' => (string) $state['name'],
                'enabled' => isset($config[$id]) ? !empty($config[$id]['enabled']) : isset($kinds[$id]),
                'text' => isset($config[$id]['text']) ? (string) $config[$id]['text'] : '',
            );
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        $this->context->smarty->assign(array(
            'tmf_connected' => TextMeFlowService::isConnected(),
            'tmf_form_url' => $adminUrl,
            'tmf_ajax_url' => $this->context->link->getAdminLink('AdminTextMeFlow'),
            'tmf_statuses' => $statuses,
            'tmf_notify' => (int) Configuration::get('TMF_MERCHANT_NOTIFY'),
            'tmf_merchant_phone' => (string) Configuration::get('TMF_MERCHANT_PHONE'),
            'tmf_placeholders' => TextMeFlowTemplates::PLACEHOLDERS,
            'tmf_js_strings' => json_encode($this->getJsStrings()),
        ));

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
            array(),
            array('configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name)
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
        Configuration::updateValue('TMF_STATE', $state);
        Configuration::updateValue('TMF_STATE_TS', time());
        Configuration::updateValue('TMF_ADMIN_URL', $adminUrl);

        $locale = Tools::strtolower((string) $this->context->language->iso_code);
        $query = http_build_query(array(
            'app' => 'prestashop',
            'site' => Tools::getShopDomainSsl(true),
            'return' => $this->context->link->getModuleLink($this->name, 'callback', array(), true),
            'state' => $state,
            'locale' => in_array($locale, array('nl', 'en', 'fr', 'de'), true) ? $locale : 'en',
        ));
        Tools::redirect(TextMeFlowClient::SITE_BASE . '/connect?' . $query);
    }

    private function disconnect()
    {
        $client = TextMeFlowClient::fromConfiguration();
        if ($client) {
            $client->revoke();
        }
        Configuration::deleteByName('TMF_TOKEN');
        Configuration::deleteByName('TMF_ACCOUNT');
    }

    /**
     * @return string HTML feedback
     */
    private function saveSettings()
    {
        $posted = Tools::getValue('tmf_status');
        $posted = is_array($posted) ? $posted : array();
        $config = array();
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $id = (int) $state['id_order_state'];
            $row = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : array();
            $text = isset($row['text']) ? trim(strip_tags((string) $row['text'])) : '';
            $text = Tools::substr($text, 0, 1000);
            if ($text !== '' && !Validate::isCleanHtml($text)) {
                $text = '';
            }
            $config[$id] = array('enabled' => !empty($row['enabled']), 'text' => $text);
        }
        $notify = (int) (bool) Tools::getValue('tmf_notify');
        $phone = trim((string) Tools::getValue('tmf_merchant_phone'));
        if ($phone !== '' && !preg_match('/^[0-9 +().\/-]{6,25}$/', $phone)) {
            return $this->displayError($this->trans('Please enter a valid mobile number.', array(), 'Modules.Textmeflow.Admin'));
        }
        if ($notify && $phone === '') {
            return $this->displayError($this->trans('Enter your own mobile number to receive new-order messages.', array(), 'Modules.Textmeflow.Admin'));
        }
        Configuration::updateValue('TMF_STATUS_CFG', json_encode($config));
        Configuration::updateValue('TMF_MERCHANT_NOTIFY', $notify);
        Configuration::updateValue('TMF_MERCHANT_PHONE', $phone);

        return $this->displayConfirmation($this->trans('Settings saved.', array(), 'Modules.Textmeflow.Admin'));
    }

    /**
     * UI strings for the JavaScript on the configuration page (translated server-side).
     *
     * @return array<string,string>
     */
    private function getJsStrings()
    {
        $d = 'Modules.Textmeflow.Admin';

        return array(
            'checking' => $this->trans('Checking...', array(), $d),
            'connectedTo' => $this->trans('Connected with WhatsApp number', array(), $d),
            'notLinked' => $this->trans('Your WhatsApp is not linked yet.', array(), $d),
            'scanHelp' => $this->trans('Open WhatsApp on your phone, go to Settings > Linked devices > Link a device and scan this code.', array(), $d),
            'showQr' => $this->trans('Show QR code', array(), $d),
            'newCode' => $this->trans('New code', array(), $d),
            'waiting' => $this->trans('Waiting for the scan...', array(), $d),
            'linked' => $this->trans('WhatsApp connected', array(), $d),
            'expired' => $this->trans('The code expired.', array(), $d),
            'limit' => $this->trans('Your plan allows no more linked numbers. Upgrade your plan to add another number.', array(), $d),
            'upgrade' => $this->trans('Upgrade your plan', array(), $d),
            'error' => $this->trans('Something went wrong. Please try again.', array(), $d),
            'testSent' => $this->trans('Test message sent. Check your WhatsApp.', array(), $d),
            'testInvalid' => $this->trans('Please enter a valid mobile number first.', array(), $d),
            'testFailed' => $this->trans('The test message could not be sent. Is your WhatsApp linked?', array(), $d),
            'quota' => $this->trans('You have used all messages of your plan this month.', array(), $d),
        );
    }

    /**
     * Opt-in checkbox on the customer form (account creation and checkout personal-information step).
     * Never ticked by default.
     *
     * @param array $params
     *
     * @return array<int,FormField>
     */
    public function hookAdditionalCustomerFormFields($params)
    {
        if (!TextMeFlowService::isConnected()) {
            return array();
        }
        $field = new FormField();
        $field->setName('tmf_optin');
        $field->setType('checkbox');
        $field->setRequired(false);
        $field->setLabel($this->trans('Send me my order updates via WhatsApp (via TextMeFlow). You can withdraw this at any time.', array(), 'Modules.Textmeflow.Shop'));
        $customer = $this->context->customer;
        if (Validate::isLoadedObject($customer) && $customer->id) {
            $field->setValue(TextMeFlowService::hasOptIn((int) $customer->id) ? '1' : '');
        }

        return array('tmf_optin' => $field);
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
