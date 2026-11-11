<?php
/**
 * Asynchronous message sending.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues messages with Action Scheduler (bundled with WooCommerce) or WP-Cron
 * and sends them in the background, so checkout and form submissions never
 * wait for the TextMeFlow API.
 */
class TextMeFlow_Sender {

	const HOOK      = 'textmeflow_send_message';
	const GROUP     = 'textmeflow';
	const MAX_TRIES = 3;

	/**
	 * Register the background task handler.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 4 );
	}

	/**
	 * Queue a message.
	 *
	 * @param string $to      E.164 recipient.
	 * @param string $text    Message text.
	 * @param array  $context Optional: order_id (int) to receive an order note, label (string).
	 * @return bool Whether the message was queued.
	 */
	public static function queue( $to, $text, array $context = array() ) {
		if ( '' === $to || '' === trim( (string) $text ) || ! TextMeFlow_Connection::is_connected() ) {
			return false;
		}

		$context['uid'] = wp_generate_uuid4(); // Keeps identical messages from being de-duplicated by WP-Cron.
		$args           = array( $to, mb_substr( (string) $text, 0, 4000 ), $context, 1 );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time(), self::HOOK, $args );
		}
		return true;
	}

	/**
	 * Background task: deliver one message and record the result.
	 *
	 * @param string $to      E.164 recipient.
	 * @param string $text    Message text.
	 * @param array  $context Context passed when queueing.
	 * @param int    $attempt Attempt number, starting at 1.
	 */
	public static function process( $to, $text, $context = array(), $attempt = 1 ) {
		$context  = is_array( $context ) ? $context : array();
		$attempt  = max( 1, (int) $attempt );
		$response = TextMeFlow_API::send_message( (string) $to, (string) $text );

		if ( ! is_wp_error( $response ) && in_array( $response['code'], array( 200, 201, 202 ), true ) ) {
			self::report( $context, 'ok', sprintf(
				/* translators: %s: masked phone number */
				__( 'WhatsApp message queued for %s.', 'textmeflow' ),
				TextMeFlow_Phone::mask( (string) $to )
			) );
			return;
		}

		$retry   = false;
		$reason  = '';
		if ( is_wp_error( $response ) ) {
			$retry  = true;
			$reason = $response->get_error_message();
		} else {
			$code   = $response['code'];
			$error  = isset( $response['body']['error'] ) ? (string) $response['body']['error'] : '';
			$reason = $error ? $error : 'HTTP ' . $code;
			$retry  = ( 429 === $code || $code >= 500 );
			if ( 401 === $code || 403 === $code ) {
				$reason = __( 'the connection with TextMeFlow is no longer valid. Please reconnect.', 'textmeflow' );
			} elseif ( 422 === $code ) {
				$reason = __( 'the phone number is not valid.', 'textmeflow' );
			} elseif ( 429 === $code && 'quota_exceeded' === $error ) {
				$reason = __( 'the monthly message quota is used up.', 'textmeflow' );
				$retry  = false;
			}
		}

		if ( $retry && $attempt < self::MAX_TRIES ) {
			$args = array( $to, $text, $context, $attempt + 1 );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, $args, self::GROUP );
			} else {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, $args );
			}
			return;
		}

		self::report( $context, 'error', sprintf(
			/* translators: 1: masked phone number, 2: reason */
			__( 'WhatsApp message to %1$s was not sent: %2$s', 'textmeflow' ),
			TextMeFlow_Phone::mask( (string) $to ),
			$reason
		) );
	}

	/**
	 * Write the result to the activity log and, for orders, to an order note.
	 *
	 * @param array  $context Context passed when queueing.
	 * @param string $status  "ok" or "error".
	 * @param string $message Message.
	 */
	private static function report( array $context, $status, $message ) {
		TextMeFlow_Log::add( $status, $message );

		if ( ! empty( $context['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $context['order_id'] );
			if ( $order ) {
				$order->add_order_note( 'TextMeFlow: ' . $message );
			}
		}
	}
}
