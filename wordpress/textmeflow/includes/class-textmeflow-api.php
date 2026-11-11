<?php
/**
 * Server-to-server client for the TextMeFlow API.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request().
 */
class TextMeFlow_API {

	/**
	 * API base URL. Can be overridden with a constant in wp-config.php (development only).
	 *
	 * @return string
	 */
	public static function api_base() {
		return defined( 'TEXTMEFLOW_API_BASE' ) ? untrailingslashit( TEXTMEFLOW_API_BASE ) : 'https://api.textmeflow.eu/v1';
	}

	/**
	 * Website base URL (browser redirects).
	 *
	 * @return string
	 */
	public static function site_base() {
		return defined( 'TEXTMEFLOW_SITE_BASE' ) ? untrailingslashit( TEXTMEFLOW_SITE_BASE ) : 'https://textmeflow.eu';
	}

	/**
	 * Perform a JSON request.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Path below the API base, starting with "/".
	 * @param array|null  $body   JSON body.
	 * @param string|null $token  Bearer token; defaults to the stored token. Pass '' for none.
	 * @return array{code:int,body:array}|WP_Error
	 */
	public static function request( $method, $path, $body = null, $token = null ) {
		if ( null === $token ) {
			$token = TextMeFlow_Connection::get_token();
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'TextMeFlow-WordPress/' . TEXTMEFLOW_VERSION . '; ' . home_url(),
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::api_base() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Send one WhatsApp message.
	 *
	 * @param string $to   E.164 recipient.
	 * @param string $text Message text.
	 * @return array{code:int,body:array}|WP_Error
	 */
	public static function send_message( $to, $text ) {
		return self::request(
			'POST',
			'/messages',
			array(
				'to'   => $to,
				'text' => $text,
			)
		);
	}
}
