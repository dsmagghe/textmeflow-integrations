<?php
/**
 * WooCommerce integration: order status messages, opt-in, new order alert.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends WhatsApp messages for WooCommerce orders. Only active when
 * WooCommerce is active.
 */
class TextMeFlow_WooCommerce {

	const META_OPTIN      = '_textmeflow_optin';
	const META_BLOCK      = '_wc_other/textmeflow/optin';
	const META_LAST       = '_textmeflow_last_status';
	const META_OWNER_SENT = '_textmeflow_owner_sent';

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Register hooks when WooCommerce is available.
	 */
	public static function init() {
		if ( ! self::is_active() ) {
			return;
		}

		foreach ( TextMeFlow_Settings::wc_statuses() as $status ) {
			add_action(
				'woocommerce_order_status_' . $status,
				static function ( $order_id, $order = null ) use ( $status ) {
					self::on_status( $order_id, $order, $status );
				},
				20,
				2
			);
		}

		// Opt-in checkbox: classic checkout and the Checkout block.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_classic_optin' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_optin' ), 10, 1 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_optin' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin_optin' ) );

		// Message to the shop owner for each new order.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_new_order_classic' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_new_order_block' ), 20, 1 );
	}

	/**
	 * Label of the opt-in checkbox.
	 *
	 * @return string
	 */
	private static function optin_label() {
		$settings = TextMeFlow_Settings::get();
		return TextMeFlow_Settings::text_or_default( $settings['wc']['optin_label'], 'optin_label' );
	}

	/**
	 * Whether the opt-in checkbox is shown at checkout.
	 *
	 * @return bool
	 */
	private static function optin_enabled() {
		$settings = TextMeFlow_Settings::get();
		return 'checkbox' === $settings['wc']['optin_mode'];
	}

	/**
	 * Classic checkout: unchecked checkbox before the order button.
	 */
	public static function render_classic_optin() {
		if ( ! self::optin_enabled() ) {
			return;
		}
		$checked = false;
		if ( function_exists( 'WC' ) && WC()->checkout() ) {
			$checked = (bool) WC()->checkout()->get_value( 'textmeflow_optin' );
		}
		woocommerce_form_field(
			'textmeflow_optin',
			array(
				'type'     => 'checkbox',
				'label'    => esc_html( self::optin_label() ),
				'required' => false,
				'class'    => array( 'form-row-wide', 'textmeflow-optin' ),
			),
			$checked ? 1 : 0
		);
	}

	/**
	 * Classic checkout: store the choice on the order (works with HPOS).
	 *
	 * @param WC_Order $order Order being created.
	 */
	public static function save_classic_optin( $order ) {
		// Only classic checkout posts the WooCommerce checkout nonce; verify it before reading input.
		$nonce = isset( $_POST['woocommerce-process-checkout-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce-process_checkout' ) ) {
			return;
		}
		if ( ! self::optin_enabled() ) {
			return;
		}
		$order->update_meta_data( self::META_OPTIN, empty( $_POST['textmeflow_optin'] ) ? 'no' : 'yes' );
	}

	/**
	 * Checkout block: register an additional checkout field (WooCommerce 8.6+).
	 */
	public static function register_block_optin() {
		if ( ! self::optin_enabled() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'textmeflow/optin',
					'label'    => self::optin_label(),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( Exception $e ) {
			TextMeFlow_Log::add( 'error', 'Checkout block field: ' . $e->getMessage() );
		}
	}

	/**
	 * Show the customer's choice on the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_admin_optin( $order ) {
		if ( ! TextMeFlow_Connection::is_connected() ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'WhatsApp updates:', 'textmeflow' ) . '</strong> ';
		echo esc_html( self::customer_allows( $order ) ? __( 'Yes', 'textmeflow' ) : __( 'No', 'textmeflow' ) );
		echo '</p>';
	}

	/**
	 * Whether this customer may receive WhatsApp messages.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function customer_allows( $order ) {
		$settings = TextMeFlow_Settings::get();
		if ( 'none' === $settings['wc']['optin_mode'] ) {
			return true; // The shop collects consent elsewhere and says so in the settings.
		}
		if ( 'yes' === $order->get_meta( self::META_OPTIN ) ) {
			return true;
		}
		$block = $order->get_meta( self::META_BLOCK );
		return ! in_array( $block, array( '', '0', 'no', false, null ), true );
	}

	/**
	 * Order status changed: queue the customer message.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 * @param string        $status   New status without "wc-".
	 */
	private static function on_status( $order_id, $order, $status ) {
		if ( ! TextMeFlow_Connection::is_connected() ) {
			return;
		}
		$settings = TextMeFlow_Settings::get();
		if ( empty( $settings['wc']['statuses'][ $status ]['enabled'] ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Never send the same status twice in a row.
		if ( $order->get_meta( self::META_LAST ) === $status ) {
			return;
		}
		if ( ! self::customer_allows( $order ) ) {
			return;
		}

		$raw     = $order->get_billing_phone();
		$country = $order->get_billing_country();
		if ( '' === trim( (string) $raw ) ) {
			$raw     = $order->get_shipping_phone();
			$country = $order->get_shipping_country();
		}
		$to = TextMeFlow_Phone::normalize( $raw, TextMeFlow_Settings::country( $country ) );
		if ( '' === $to ) {
			$order->add_order_note( 'TextMeFlow: ' . __( 'No WhatsApp message sent: no valid phone number on this order.', 'textmeflow' ) );
			return;
		}

		$template = TextMeFlow_Settings::text_or_default( $settings['wc']['statuses'][ $status ]['text'], $status );
		$text     = TextMeFlow_Settings::render( $template, self::placeholders( $order ) );

		if ( TextMeFlow_Sender::queue( $to, $text, array( 'order_id' => $order->get_id() ) ) ) {
			$order->update_meta_data( self::META_LAST, $status );
			$order->save_meta_data();
		}
	}

	/**
	 * Placeholder values for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	private static function placeholders( $order ) {
		return array(
			'first_name'   => (string) $order->get_billing_first_name(),
			'last_name'    => (string) $order->get_billing_last_name(),
			'order_number' => (string) $order->get_order_number(),
			'order_total'  => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'order_status' => wc_get_order_status_name( $order->get_status() ),
			'shop_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'order_url'    => (string) $order->get_view_order_url(),
			'payment_url'  => (string) $order->get_checkout_payment_url(),
			'tracking_url' => self::tracking_url( $order ),
		);
	}

	/**
	 * Tracking link: WooCommerce Shipment Tracking data, or a filter for other plugins.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function tracking_url( $order ) {
		$url   = '';
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) && ! empty( $items ) ) {
			$first = reset( $items );
			if ( is_array( $first ) && ! empty( $first['custom_tracking_link'] ) ) {
				$url = (string) $first['custom_tracking_link'];
			} elseif ( is_array( $first ) && ! empty( $first['formatted_tracking_link'] ) ) {
				$url = (string) $first['formatted_tracking_link'];
			}
		}
		/**
		 * Filter the tracking URL used in the {tracking_url} placeholder.
		 *
		 * @param string   $url   Tracking URL or empty string.
		 * @param WC_Order $order Order.
		 */
		$url = apply_filters( 'textmeflow_order_tracking_url', $url, $order );
		return esc_url_raw( (string) $url );
	}

	/**
	 * New order, classic checkout.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_new_order_classic( $order_id ) {
		self::notify_owner( wc_get_order( $order_id ) );
	}

	/**
	 * New order, Checkout block (Store API).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_new_order_block( $order ) {
		self::notify_owner( $order );
	}

	/**
	 * Tell the shop owner about a new order. Needs no customer consent.
	 *
	 * @param WC_Order|false $order Order.
	 */
	private static function notify_owner( $order ) {
		if ( ! $order instanceof WC_Order || ! TextMeFlow_Connection::is_connected() ) {
			return;
		}
		$settings = TextMeFlow_Settings::get();
		if ( empty( $settings['wc']['owner_new_order'] ) ) {
			return;
		}
		$to = TextMeFlow_Settings::owner_phone();
		if ( '' === $to || $order->get_meta( self::META_OWNER_SENT ) ) {
			return;
		}

		$template = TextMeFlow_Settings::text_or_default( $settings['wc']['owner_order_text'], 'owner_order' );
		$text     = TextMeFlow_Settings::render( $template, self::placeholders( $order ) );
		if ( TextMeFlow_Sender::queue( $to, $text, array( 'order_id' => $order->get_id() ) ) ) {
			$order->update_meta_data( self::META_OWNER_SENT, time() );
			$order->save_meta_data();
		}
	}
}
