<?php
/**
 * Runs when the plugin is deleted in wp-admin: revoke the connection and remove all plugin data.
 *
 * @package TextMeFlow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'TEXTMEFLOW_VERSION', '1.0.0' );
define( 'TEXTMEFLOW_DIR', plugin_dir_path( __FILE__ ) );
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-connection.php';
require_once TEXTMEFLOW_DIR . 'includes/class-textmeflow-api.php';

// Best effort: revoke the connection token at TextMeFlow so it cannot be used any more.
if ( TextMeFlow_Connection::is_connected() ) {
	TextMeFlow_API::request( 'DELETE', '/connect/token' );
}

delete_option( 'textmeflow_settings' );
delete_option( 'textmeflow_token' );
delete_option( 'textmeflow_connection' );
delete_option( 'textmeflow_log' );

// Connect-state transients (one per user, short-lived).
foreach ( get_users( array( 'fields' => 'ID' ) ) as $textmeflow_user_id ) {
	delete_transient( 'textmeflow_state_' . $textmeflow_user_id );
}

// Pending background messages.
wp_clear_scheduled_hook( 'textmeflow_send_message' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'textmeflow_send_message' );
}
