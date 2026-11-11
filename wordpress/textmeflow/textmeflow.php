<?php
/**
 * Plugin Name:       TextMeFlow – WhatsApp notifications for WooCommerce & WordPress forms
 * Plugin URI:        https://textmeflow.eu/integrations/wordpress
 * Description:       Send WhatsApp messages for WooCommerce orders and form submissions (Contact Form 7, WPForms, Elementor Forms, Gravity Forms) through your own WhatsApp number. Connect with one click.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            TextMeFlow
 * Author URI:        https://textmeflow.eu
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       textmeflow
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.3
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

define( 'TEXTMEFLOW_VERSION', '1.0.1' );
define( 'TEXTMEFLOW_FILE', __FILE__ );
define( 'TEXTMEFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEXTMEFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-phone.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-settings.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-log.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-connection.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-api.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-sender.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-admin.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-woocommerce.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-forms.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage and
 * the Cart/Checkout blocks. Must run before WooCommerce initialises.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TEXTMEFLOW_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TEXTMEFLOW_FILE, true );
		}
	}
);

/**
 * Map a regional locale to the bundled translation of the same language.
 *
 * @param string $locale Current locale.
 * @return string
 */
function textmeflow_regional_locale( $locale ) {
	$bundled = array(
		'nl' => 'nl_NL',
		'fr' => 'fr_FR',
		'de' => 'de_DE',
	);
	$language = strtolower( substr( (string) $locale, 0, 2 ) );
	if ( isset( $bundled[ $language ] ) && ! is_readable( TEXTMEFLOW_DIR . 'languages/textmeflow-' . $locale . '.mo' ) ) {
		return $bundled[ $language ];
	}
	return $locale;
}

/**
 * Load translations and start the components.
 */
function textmeflow_boot() {
	// Bundled translations (nl, fr, de); other languages come from translate.wordpress.org.
	// Regional variants (nl_BE, fr_BE, de_AT, ...) fall back to the bundled file of the
	// same language, so a Belgian site does not show the plugin in English.
	$textmeflow_locale = determine_locale();
	$textmeflow_mofile = TEXTMEFLOW_DIR . 'languages/textmeflow-' . textmeflow_regional_locale( $textmeflow_locale ) . '.mo';
	if ( textmeflow_regional_locale( $textmeflow_locale ) !== $textmeflow_locale && is_readable( $textmeflow_mofile ) ) {
		// Load nl_NL for an nl_BE site under the site's own locale (just-in-time loading ignores plugin_locale).
		load_textdomain( 'textmeflow', $textmeflow_mofile, $textmeflow_locale );
	}
	load_plugin_textdomain( 'textmeflow', false, dirname( plugin_basename( TEXTMEFLOW_FILE ) ) . '/languages' );

	TextMeFlow_Sender::init();
	TextMeFlow_Forms::init();
	TextMeFlow_WooCommerce::init();

	if ( is_admin() ) {
		TextMeFlow_Admin::init();
	}
}
add_action( 'plugins_loaded', 'textmeflow_boot', 20 );
