<?php
/**
 * Stores the connection token (encrypted) and account details.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection state. The token never leaves the server: it is not printed
 * on any page and not passed to JavaScript.
 */
class TextMeFlow_Connection {

	const TOKEN_OPTION = 'textmeflow_token';
	const INFO_OPTION  = 'textmeflow_connection';

	/**
	 * Symmetric key derived from the site's secret salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'textmeflow', true );
	}

	/**
	 * Save the token, encrypted with libsodium (bundled with WordPress).
	 *
	 * @param string $token Connection token.
	 */
	public static function set_token( $token ) {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) $token, $nonce, self::key() );
		update_option( self::TOKEN_OPTION, 'v1:' . base64_encode( $nonce . $cipher ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
	}

	/**
	 * Get the decrypted token.
	 *
	 * @return string Empty string when not connected or undecryptable.
	 */
	public static function get_token() {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a usable token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::get_token();
	}

	/**
	 * Save account details returned when connecting.
	 *
	 * @param string $email Account e-mail.
	 * @param string $plan  Plan code.
	 */
	public static function set_info( $email, $plan ) {
		update_option(
			self::INFO_OPTION,
			array(
				'email' => sanitize_email( (string) $email ),
				'plan'  => sanitize_text_field( (string) $plan ),
			),
			false
		);
	}

	/**
	 * Account details.
	 *
	 * @return array{email:string,plan:string}
	 */
	public static function get_info() {
		$info = get_option( self::INFO_OPTION, array() );
		$info = is_array( $info ) ? $info : array();
		return wp_parse_args(
			$info,
			array(
				'email' => '',
				'plan'  => '',
			)
		);
	}

	/**
	 * Forget the connection locally.
	 */
	public static function clear() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::INFO_OPTION );
	}
}
