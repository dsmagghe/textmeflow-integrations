<?php
/**
 * Small activity log (last 20 entries) shown on the settings page.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a short, privacy-friendly record of what the plugin did.
 */
class TextMeFlow_Log {

	const OPTION = 'textmeflow_log';
	const MAX    = 20;

	/**
	 * Add an entry.
	 *
	 * @param string $status  "ok" or "error".
	 * @param string $message Short description (no personal data).
	 */
	public static function add( $status, $message ) {
		$log = self::get();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'status'  => 'ok' === $status ? 'ok' : 'error',
				'message' => mb_substr( wp_strip_all_tags( (string) $message ), 0, 300 ),
			)
		);
		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Get all entries, newest first.
	 *
	 * @return array<int,array{time:int,status:string,message:string}>
	 */
	public static function get() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empty the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
