<?php
/**
 * Unit tests for the PrestaShop-independent parts of the module (no PrestaShop needed).
 * Run: php integrations/prestashop/dev/tests/run.php
 * Exit code 0 when all assertions pass.
 */
define('_PS_VERSION_', '8.2.0');

/** Minimal stand-in for PrestaShop's Configuration (only what the tested code reads). */
class Configuration
{
    public static $values = array();

    public static function get($key)
    {
        return isset(self::$values[$key]) ? self::$values[$key] : false;
    }
}

$base = dirname(__DIR__, 2) . '/textmeflow';
require $base . '/classes/TextMeFlowClient.php';
require $base . '/classes/TextMeFlowPhone.php';
require $base . '/classes/TextMeFlowTemplates.php';

$failures = 0;
$count = 0;
function check($label, $expected, $actual)
{
    global $failures, $count;
    ++$count;
    if ($expected !== $actual) {
        ++$failures;
        echo "FAIL $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// --- API / site URL override (TEXTMEFLOW_API_URL, TEXTMEFLOW_SITE_URL) ---
check('no env -> production API', 'https://api.textmeflow.eu/v1', TextMeFlowClient::resolveBaseUrl(false, TextMeFlowClient::API_BASE));
check('empty -> production', 'https://api.textmeflow.eu/v1', TextMeFlowClient::resolveBaseUrl('  ', TextMeFlowClient::API_BASE));
check('http local URL accepted, slash trimmed', 'http://127.0.0.1:8099/v1', TextMeFlowClient::resolveBaseUrl('http://127.0.0.1:8099/v1/', TextMeFlowClient::API_BASE));
check('https accepted', 'https://staging.textmeflow.eu/v1', TextMeFlowClient::resolveBaseUrl('https://staging.textmeflow.eu/v1', TextMeFlowClient::API_BASE));
check('other scheme rejected', 'https://api.textmeflow.eu/v1', TextMeFlowClient::resolveBaseUrl('file:///etc/passwd', TextMeFlowClient::API_BASE));
check('relative rejected', 'https://textmeflow.eu', TextMeFlowClient::resolveBaseUrl('/connect', TextMeFlowClient::SITE_BASE));
check('no host rejected', 'https://textmeflow.eu', TextMeFlowClient::resolveBaseUrl('http://', TextMeFlowClient::SITE_BASE));

putenv('TEXTMEFLOW_API_URL');
putenv('TEXTMEFLOW_SITE_URL');
check('getApiBase default', 'https://api.textmeflow.eu/v1', TextMeFlowClient::getApiBase());
check('getSiteBase default', 'https://textmeflow.eu', TextMeFlowClient::getSiteBase());
putenv('TEXTMEFLOW_API_URL=http://127.0.0.1:8099/v1');
putenv('TEXTMEFLOW_SITE_URL=http://localhost:8099/');
check('getApiBase from env', 'http://127.0.0.1:8099/v1', TextMeFlowClient::getApiBase());
check('getSiteBase from env', 'http://localhost:8099', TextMeFlowClient::getSiteBase());
putenv('TEXTMEFLOW_API_URL=javascript:alert(1)');
check('getApiBase invalid env falls back', 'https://api.textmeflow.eu/v1', TextMeFlowClient::getApiBase());
putenv('TEXTMEFLOW_API_URL');
putenv('TEXTMEFLOW_SITE_URL');

// --- Phone numbers ---
check('BE mobile with trunk zero', '+32470123456', TextMeFlowPhone::toE164('0470 12 34 56', 'BE'));
check('plus kept', '+32470123456', TextMeFlowPhone::toE164('+32 470/12.34.56', 'NL'));
check('00 prefix', '+31612345678', TextMeFlowPhone::toE164('0031 6 12345678', 'BE'));
check('dial code without plus', '+32470123456', TextMeFlowPhone::toE164('32470123456', 'BE'));
check('NL mobile', '+31612345678', TextMeFlowPhone::toE164('06-12345678', 'NL'));
check('FR mobile', '+33612345678', TextMeFlowPhone::toE164('06 12 34 56 78', 'FR'));
check('unknown country', null, TextMeFlowPhone::toE164('0470123456', 'ZZ'));
check('empty', null, TextMeFlowPhone::toE164('', 'BE'));
check('too short', null, TextMeFlowPhone::toE164('12', 'BE'));

// --- Templates ---
check('default nl', 'Hallo {first_name}, je bestelling {order_number} bij {shop_name} is geleverd. Veel plezier ermee!', TextMeFlowTemplates::getDefault('delivered', 'NL'));
check('unknown language falls back to en', TextMeFlowTemplates::getDefault('payment', 'en'), TextMeFlowTemplates::getDefault('payment', 'es'));
check('unknown kind -> generic', TextMeFlowTemplates::getDefault('generic', 'fr'), TextMeFlowTemplates::getDefault('nope', 'fr'));
check('render strips tags and double spaces', 'Hi Emma, order X', TextMeFlowTemplates::render('Hi  {first_name}, <b>order</b> {n}', array('{first_name}' => 'Emma', '{n}' => 'X')));
check('render placeholders', 'Hi Emma, order ABC at Shop.', TextMeFlowTemplates::render('Hi {first_name}, order {order_number} at {shop_name}.', array('{first_name}' => 'Emma', '{order_number}' => 'ABC', '{shop_name}' => 'Shop')));
Configuration::$values = array('PS_OS_PAYMENT' => '2', 'PS_OS_SHIPPING' => '4', 'PS_OS_DELIVERED' => '5');
check('default kind map', array(2 => 'payment', 4 => 'shipping', 5 => 'delivered'), TextMeFlowTemplates::getDefaultKindMap());

echo $count . ' assertions, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
