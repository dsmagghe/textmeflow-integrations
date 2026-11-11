<?php
/**
 * Admin: menu, settings page, connect flow, AJAX proxy for the QR code.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that appears in wp-admin. All browser requests to TextMeFlow go
 * through the server (admin-ajax), so the token never reaches the browser.
 */
class TextMeFlow_Admin {

	const PAGE  = 'textmeflow';
	const NONCE = 'textmeflow_ajax';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TEXTMEFLOW_FILE ), array( __CLASS__, 'action_links' ) );

		add_action( 'admin_post_textmeflow_connect', array( __CLASS__, 'connect_start' ) );
		add_action( 'admin_post_textmeflow_connect_callback', array( __CLASS__, 'connect_callback' ) );
		add_action( 'admin_post_textmeflow_disconnect', array( __CLASS__, 'disconnect' ) );

		add_action( 'wp_ajax_textmeflow_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_textmeflow_pair_start', array( __CLASS__, 'ajax_pair_start' ) );
		add_action( 'wp_ajax_textmeflow_pair_poll', array( __CLASS__, 'ajax_pair_poll' ) );
		add_action( 'wp_ajax_textmeflow_test', array( __CLASS__, 'ajax_test' ) );
	}

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Add the top-level menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'TextMeFlow', 'textmeflow' ),
			__( 'TextMeFlow', 'textmeflow' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-chat',
			58
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'textmeflow' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the settings option with its sanitize callback.
	 */
	public static function register_settings() {
		register_setting(
			TextMeFlow_Settings::GROUP,
			TextMeFlow_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'TextMeFlow_Settings', 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Load CSS and JS on our page only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'textmeflow-admin', TEXTMEFLOW_URL . 'assets/admin.css', array(), TEXTMEFLOW_VERSION );
		wp_enqueue_script( 'textmeflow-admin', TEXTMEFLOW_URL . 'assets/admin.js', array(), TEXTMEFLOW_VERSION, true );
		wp_localize_script(
			'textmeflow-admin',
			'textmeflowAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'connected' => TextMeFlow_Connection::is_connected(),
				'i18n'      => array(
					'checking'     => __( 'Checking connection...', 'textmeflow' ),
					/* translators: %s: the linked WhatsApp phone number, e.g. +32470123456 */
					'connectedTo'  => __( 'WhatsApp connected with number %s', 'textmeflow' ),
					'connectedNo'  => __( 'WhatsApp connected', 'textmeflow' ),
					'notLinked'    => __( 'Your WhatsApp number is not linked yet.', 'textmeflow' ),
					'preparing'    => __( 'Preparing your code...', 'textmeflow' ),
					'scanQr'       => __( 'Scan this code with WhatsApp.', 'textmeflow' ),
					'expired'      => __( 'The code expired.', 'textmeflow' ),
					'newCode'      => __( 'Get a new code', 'textmeflow' ),
					'upgrade'      => __( 'Upgrade your plan', 'textmeflow' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'textmeflow' ),
					'sending'      => __( 'Sending...', 'textmeflow' ),
					'testOk'       => __( 'Test message sent. Check your WhatsApp.', 'textmeflow' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Connect / disconnect
	 * ------------------------------------------------------------------ */

	/**
	 * Step 1: send the shop owner to textmeflow.eu to approve the connection.
	 */
	public static function connect_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'textmeflow' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'textmeflow_connect' );

		$state = wp_generate_password( 40, false, false );
		set_transient( 'textmeflow_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$params = array(
			'app'    => 'wordpress',
			'site'   => home_url(),
			'return' => admin_url( 'admin-post.php?action=textmeflow_connect_callback' ),
			'state'  => $state,
			'locale' => TextMeFlow_Settings::lang(),
		);
		$url    = TextMeFlow_API::site_base() . '/connect?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		// External redirect to the fixed TextMeFlow address; wp_safe_redirect would block it.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Step 2: back from textmeflow.eu with a one-time code. The code is
	 * exchanged server-to-server for a connection token.
	 */
	public static function connect_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'textmeflow' ), '', array( 'response' => 403 ) );
		}

		// The one-time "state" value takes the place of a nonce here: this request comes from textmeflow.eu.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error    = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$key      = 'textmeflow_state_' . get_current_user_id();
		$expected = get_transient( $key );
		delete_transient( $key );

		if ( ! is_string( $expected ) || '' === $state || ! hash_equals( $expected, $state ) ) {
			self::back( 'invalid_state' );
		}
		if ( '' !== $error || '' === $code ) {
			self::back( 'access_denied' === $error ? 'denied' : 'failed' );
		}

		$response = TextMeFlow_API::request(
			'POST',
			'/connect/token',
			array(
				'code'   => $code,
				'return' => admin_url( 'admin-post.php?action=textmeflow_connect_callback' ),
			),
			''
		);
		if ( is_wp_error( $response ) || 200 !== $response['code'] || empty( $response['body']['token'] ) || ! is_string( $response['body']['token'] ) ) {
			self::back( 'failed' );
		}

		TextMeFlow_Connection::set_token( $response['body']['token'] );
		TextMeFlow_Connection::set_info(
			isset( $response['body']['account_email'] ) ? $response['body']['account_email'] : '',
			isset( $response['body']['plan'] ) ? $response['body']['plan'] : ''
		);
		TextMeFlow_Log::add( 'ok', __( 'Connected to TextMeFlow.', 'textmeflow' ) );
		self::back( 'connected' );
	}

	/**
	 * Disconnect: revoke the token at TextMeFlow, then forget it locally.
	 */
	public static function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'textmeflow' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'textmeflow_disconnect' );

		if ( TextMeFlow_Connection::is_connected() ) {
			TextMeFlow_API::request( 'DELETE', '/connect/token' ); // Best effort; the token is forgotten locally either way.
		}
		TextMeFlow_Connection::clear();
		TextMeFlow_Log::add( 'ok', __( 'Disconnected from TextMeFlow.', 'textmeflow' ) );
		self::back( 'disconnected' );
	}

	/**
	 * Redirect to the settings page with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private static function back( $notice ) {
		wp_safe_redirect( self::page_url( array( 'tmf_notice' => $notice ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * AJAX proxy
	 * ------------------------------------------------------------------ */

	/**
	 * Common AJAX checks: nonce, capability, connected.
	 */
	private static function ajax_guard() {
		check_ajax_referer( self::NONCE, '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'textmeflow' ) ), 403 );
		}
		if ( ! TextMeFlow_Connection::is_connected() ) {
			wp_send_json_error(
				array(
					'code'    => 'not_connected',
					'message' => __( 'Not connected to TextMeFlow.', 'textmeflow' ),
				)
			);
		}
	}

	/**
	 * Turn an API response into a JSON error when it is not a success.
	 *
	 * @param array|WP_Error $response API response.
	 * @param int[]          $ok       Accepted status codes.
	 */
	private static function ajax_fail_unless_ok( $response, array $ok = array( 200 ) ) {
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not reach TextMeFlow. Please try again.', 'textmeflow' ) ) );
		}
		if ( in_array( $response['code'], $ok, true ) ) {
			return;
		}
		$error = isset( $response['body']['error'] ) ? (string) $response['body']['error'] : '';

		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			wp_send_json_error(
				array(
					'code'    => 'revoked',
					'message' => __( 'The connection with TextMeFlow is no longer valid. Please disconnect and connect again.', 'textmeflow' ),
				)
			);
		}
		if ( in_array( $response['code'], array( 402, 409 ), true ) && 'device_limit_reached' === $error ) {
			wp_send_json_error(
				array(
					'code'        => 'device_limit',
					'message'     => __( 'Your plan allows no more linked WhatsApp numbers.', 'textmeflow' ),
					'upgrade_url' => TextMeFlow_API::site_base() . '/pricing',
				)
			);
		}
		if ( 429 === $response['code'] ) {
			wp_send_json_error( array( 'message' => __( 'Your message quota is used up or you are sending too fast.', 'textmeflow' ) ) );
		}
		if ( 422 === $response['code'] ) {
			wp_send_json_error( array( 'message' => __( 'That phone number is not valid.', 'textmeflow' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'textmeflow' ) ) );
	}

	/**
	 * Connection status and linked WhatsApp number.
	 */
	public static function ajax_status() {
		self::ajax_guard();
		$response = TextMeFlow_API::request( 'GET', '/accounts/me' );
		self::ajax_fail_unless_ok( $response );

		$phone = '';
		if ( ! empty( $response['body']['wa_sessions'] ) && is_array( $response['body']['wa_sessions'] ) ) {
			foreach ( $response['body']['wa_sessions'] as $session ) {
				if ( is_array( $session ) && isset( $session['status'] ) && 'connected' === $session['status'] ) {
					$phone = isset( $session['phone_number'] ) ? sanitize_text_field( (string) $session['phone_number'] ) : '';
					wp_send_json_success(
						array(
							'linked' => true,
							'phone'  => $phone,
						)
					);
				}
			}
		}
		wp_send_json_success(
			array(
				'linked' => false,
				'phone'  => '',
			)
		);
	}

	/**
	 * Reduce a pair response to the few fields the browser needs. The QR is
	 * only passed on if it really is a PNG data URI.
	 *
	 * @param array $body Response body.
	 * @return array
	 */
	private static function pair_payload( array $body ) {
		$qr = '';
		if ( isset( $body['qr_png_b64'] ) && is_string( $body['qr_png_b64'] ) && preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $body['qr_png_b64'] ) ) {
			$qr = $body['qr_png_b64'];
		}
		return array(
			'pair_id' => isset( $body['pair_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $body['pair_id'] ) : '',
			'status'  => isset( $body['status'] ) ? sanitize_key( (string) $body['status'] ) : '',
			'qr'      => $qr,
		);
	}

	/**
	 * Start linking a WhatsApp number (returns the first QR).
	 */
	public static function ajax_pair_start() {
		self::ajax_guard();
		$label    = mb_substr( wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 60 );
		$response = TextMeFlow_API::request( 'POST', '/accounts/me/pair', array( 'label' => $label ) );
		self::ajax_fail_unless_ok( $response, array( 200, 201, 202 ) );
		wp_send_json_success( self::pair_payload( $response['body'] ) );
	}

	/**
	 * Poll a pairing attempt.
	 */
	public static function ajax_pair_poll() {
		self::ajax_guard();
		$pair_id = isset( $_POST['pair_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pair_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $pair_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'textmeflow' ) ) );
		}
		$response = TextMeFlow_API::request( 'GET', '/accounts/me/pair/' . rawurlencode( $pair_id ) );
		self::ajax_fail_unless_ok( $response );
		$payload            = self::pair_payload( $response['body'] );
		$payload['pair_id'] = $pair_id;
		wp_send_json_success( $payload );
	}

	/**
	 * Send a test message to the number in the settings form.
	 */
	public static function ajax_test() {
		self::ajax_guard();
		$raw = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		$to  = TextMeFlow_Phone::normalize( $raw, TextMeFlow_Settings::country() );
		if ( '' === $to ) {
			wp_send_json_error( array( 'message' => __( 'Enter your own WhatsApp number first, including the country if you can.', 'textmeflow' ) ) );
		}
		$text     = TextMeFlow_Settings::render(
			TextMeFlow_Settings::default_text( 'test_message' ),
			array( 'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
		);
		$response = TextMeFlow_API::send_message( $to, $text );
		self::ajax_fail_unless_ok( $response, array( 200, 201, 202 ) );
		TextMeFlow_Log::add( 'ok', __( 'Test message sent.', 'textmeflow' ) );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ *
	 * Page rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Name attribute for a settings field.
	 *
	 * @param string $path Path such as "wc][optin_mode" (without the option name).
	 * @return string
	 */
	private static function name( $path ) {
		return TextMeFlow_Settings::OPTION . '[' . $path . ']';
	}

	/**
	 * Notice text for a code in ?tmf_notice=.
	 *
	 * @return array{type:string,text:string}|null
	 */
	private static function notice() {
		$code = isset( $_GET['tmf_notice'] ) ? sanitize_key( wp_unslash( $_GET['tmf_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$map  = array(
			'connected'     => array( 'success', __( 'Connected to TextMeFlow. Now link your WhatsApp number below.', 'textmeflow' ) ),
			'disconnected'  => array( 'success', __( 'Disconnected from TextMeFlow.', 'textmeflow' ) ),
			'denied'        => array( 'warning', __( 'The connection was not approved. You can try again whenever you like.', 'textmeflow' ) ),
			'failed'        => array( 'error', __( 'Connecting did not work. Please try again.', 'textmeflow' ) ),
			'invalid_state' => array( 'error', __( 'The connection request expired. Please try again.', 'textmeflow' ) ),
		);
		if ( isset( $map[ $code ] ) ) {
			return array(
				'type' => $map[ $code ][0],
				'text' => $map[ $code ][1],
			);
		}
		return null;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$connected = TextMeFlow_Connection::is_connected();
		$notice    = self::notice();
		?>
		<div class="wrap textmeflow-wrap">
			<h1><?php esc_html_e( 'TextMeFlow', 'textmeflow' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $connected ) : ?>
				<?php self::render_connect_card(); ?>
			<?php else : ?>
				<?php self::render_connected_card(); ?>
				<?php settings_errors( TextMeFlow_Settings::OPTION ); ?>
				<form method="post" action="options.php" class="tmf-form">
					<?php settings_fields( TextMeFlow_Settings::GROUP ); ?>
					<?php self::render_number_section(); ?>
					<?php self::render_woocommerce_section(); ?>
					<?php self::render_forms_section(); ?>
					<?php submit_button(); ?>
				</form>
				<?php self::render_log(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The big "Connect" button.
	 */
	private static function render_connect_card() {
		?>
		<div class="tmf-card tmf-hero">
			<h2><?php esc_html_e( 'Send WhatsApp messages from your own number', 'textmeflow' ); ?></h2>
			<ol class="tmf-steps">
				<li><?php esc_html_e( 'Click the button below and log in or create a free TextMeFlow account.', 'textmeflow' ); ?></li>
				<li><?php esc_html_e( 'Approve the connection. You come back here automatically.', 'textmeflow' ); ?></li>
				<li><?php esc_html_e( 'Scan the code with WhatsApp. Done.', 'textmeflow' ); ?></li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="textmeflow_connect" />
				<?php wp_nonce_field( 'textmeflow_connect' ); ?>
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Connect to TextMeFlow', 'textmeflow' ); ?></button>
			</form>
			<p class="description">
				<?php
				printf(
					/* translators: 1: link to privacy policy, 2: link to terms */
					esc_html__( 'Phone numbers and message texts are sent to TextMeFlow (hosted in the EU) only to deliver your messages. See the %1$s and the %2$s.', 'textmeflow' ),
					'<a href="https://textmeflow.eu/legal/privacy" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'textmeflow' ) . '</a>',
					'<a href="https://textmeflow.eu/legal/terms" target="_blank" rel="noopener">' . esc_html__( 'terms', 'textmeflow' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Connection status, QR area and disconnect button.
	 */
	private static function render_connected_card() {
		$info = TextMeFlow_Connection::get_info();
		?>
		<div class="tmf-card" id="tmf-connection">
			<h2><?php esc_html_e( 'Connection', 'textmeflow' ); ?></h2>
			<p class="tmf-status" id="tmf-status" aria-live="polite"><?php esc_html_e( 'Checking connection...', 'textmeflow' ); ?></p>

			<div id="tmf-qr" hidden>
				<p><?php esc_html_e( 'Open WhatsApp on your phone, go to Settings, then Linked devices, then Link a device, and scan this code.', 'textmeflow' ); ?></p>
				<img id="tmf-qr-img" alt="<?php esc_attr_e( 'QR code to link WhatsApp', 'textmeflow' ); ?>" width="240" height="240" hidden />
				<p id="tmf-qr-msg" aria-live="polite"></p>
				<p>
					<button type="button" class="button" id="tmf-new-code" hidden><?php esc_html_e( 'Get a new code', 'textmeflow' ); ?></button>
					<a class="button button-primary" id="tmf-upgrade" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Upgrade your plan', 'textmeflow' ); ?></a>
				</p>
			</div>

			<p class="description">
				<?php if ( '' !== $info['email'] ) : ?>
					<?php
					/* translators: %s: TextMeFlow account e-mail */
					printf( esc_html__( 'TextMeFlow account: %s', 'textmeflow' ), esc_html( $info['email'] ) );
					?>
					&middot;
				<?php endif; ?>
				<a href="https://textmeflow.eu" target="_blank" rel="noopener"><?php esc_html_e( 'Open your TextMeFlow account', 'textmeflow' ); ?></a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="textmeflow_disconnect" />
				<?php wp_nonce_field( 'textmeflow_disconnect' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'textmeflow' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Owner number, country and test message.
	 */
	private static function render_number_section() {
		$settings  = TextMeFlow_Settings::get();
		$countries = array_keys( TextMeFlow_Phone::countries() );
		?>
		<div class="tmf-card">
			<h2><?php esc_html_e( 'Your number', 'textmeflow' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tmf-owner-phone"><?php esc_html_e( 'Your WhatsApp number', 'textmeflow' ); ?></label></th>
					<td>
						<input type="tel" id="tmf-owner-phone" class="regular-text" name="<?php echo esc_attr( self::name( 'owner_phone' ) ); ?>" value="<?php echo esc_attr( $settings['owner_phone'] ); ?>" placeholder="+32 470 12 34 56" />
						<button type="button" class="button" id="tmf-test"><?php esc_html_e( 'Send me a test message', 'textmeflow' ); ?></button>
						<span id="tmf-test-result" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Alerts about new orders and form submissions go to this number.', 'textmeflow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tmf-country"><?php esc_html_e( 'Country for numbers without country code', 'textmeflow' ); ?></label></th>
					<td>
						<select id="tmf-country" name="<?php echo esc_attr( self::name( 'default_country' ) ); ?>">
							<option value=""><?php esc_html_e( 'Automatic (shop country or site language)', 'textmeflow' ); ?></option>
							<?php foreach ( $countries as $iso ) : ?>
								<option value="<?php echo esc_attr( $iso ); ?>" <?php selected( $settings['default_country'], $iso ); ?>><?php echo esc_html( $iso . ' (+' . TextMeFlow_Phone::countries()[ $iso ] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * WooCommerce settings.
	 */
	private static function render_woocommerce_section() {
		?>
		<div class="tmf-card">
			<h2><?php esc_html_e( 'WooCommerce orders', 'textmeflow' ); ?></h2>
		<?php
		if ( ! TextMeFlow_WooCommerce::is_active() ) {
			echo '<p>' . esc_html__( 'WooCommerce is not active on this site. Install and activate it to send order messages.', 'textmeflow' ) . '</p></div>';
			return;
		}

		$settings = TextMeFlow_Settings::get();
		$wc       = $settings['wc'];
		$labels   = array(
			'processing' => __( 'Processing (order received / paid)', 'textmeflow' ),
			'completed'  => __( 'Completed', 'textmeflow' ),
			'on-hold'    => __( 'On hold', 'textmeflow' ),
			'cancelled'  => __( 'Cancelled', 'textmeflow' ),
			'refunded'   => __( 'Refunded', 'textmeflow' ),
			'failed'     => __( 'Failed payment', 'textmeflow' ),
		);
		?>
			<input type="hidden" name="<?php echo esc_attr( self::name( 'wc][_present' ) ); ?>" value="1" />

			<h3><?php esc_html_e( 'Customer permission', 'textmeflow' ); ?></h3>
			<fieldset>
				<label>
					<input type="radio" name="<?php echo esc_attr( self::name( 'wc][optin_mode' ) ); ?>" value="checkbox" <?php checked( $wc['optin_mode'], 'checkbox' ); ?> />
					<?php esc_html_e( 'Ask at checkout with a checkbox (recommended). Messages are only sent to customers who tick it.', 'textmeflow' ); ?>
				</label><br />
				<label>
					<input type="radio" name="<?php echo esc_attr( self::name( 'wc][optin_mode' ) ); ?>" value="none" <?php checked( $wc['optin_mode'], 'none' ); ?> />
					<?php esc_html_e( 'I already have my customers\' permission to message them on WhatsApp (no checkbox).', 'textmeflow' ); ?>
				</label>
			</fieldset>
			<p>
				<label for="tmf-optin-label"><?php esc_html_e( 'Checkbox text', 'textmeflow' ); ?></label><br />
				<input type="text" id="tmf-optin-label" class="large-text" name="<?php echo esc_attr( self::name( 'wc][optin_label' ) ); ?>" value="<?php echo esc_attr( TextMeFlow_Settings::text_or_default( $wc['optin_label'], 'optin_label' ) ); ?>" />
			</p>

			<h3><?php esc_html_e( 'Messages to your customers', 'textmeflow' ); ?></h3>
			<?php self::render_placeholder_help( array( '{first_name}', '{last_name}', '{order_number}', '{order_total}', '{order_status}', '{shop_name}', '{order_url}', '{payment_url}', '{tracking_url}' ) ); ?>
			<?php foreach ( TextMeFlow_Settings::wc_statuses() as $status ) : ?>
				<div class="tmf-message">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::name( 'wc][statuses][' . $status . '][enabled' ) ); ?>" value="1" <?php checked( ! empty( $wc['statuses'][ $status ]['enabled'] ) ); ?> />
						<strong><?php echo esc_html( $labels[ $status ] ); ?></strong>
					</label>
					<textarea rows="3" class="large-text" name="<?php echo esc_attr( self::name( 'wc][statuses][' . $status . '][text' ) ); ?>"><?php echo esc_textarea( TextMeFlow_Settings::text_or_default( $wc['statuses'][ $status ]['text'], $status ) ); ?></textarea>
				</div>
			<?php endforeach; ?>

			<h3><?php esc_html_e( 'Alert to yourself', 'textmeflow' ); ?></h3>
			<div class="tmf-message">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'wc][owner_new_order' ) ); ?>" value="1" <?php checked( ! empty( $wc['owner_new_order'] ) ); ?> />
					<strong><?php esc_html_e( 'Message me for every new order (uses your number above)', 'textmeflow' ); ?></strong>
				</label>
				<textarea rows="2" class="large-text" name="<?php echo esc_attr( self::name( 'wc][owner_order_text' ) ); ?>"><?php echo esc_textarea( TextMeFlow_Settings::text_or_default( $wc['owner_order_text'], 'owner_order' ) ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Form plugin settings.
	 */
	private static function render_forms_section() {
		$settings = TextMeFlow_Settings::get();
		$forms    = $settings['forms'];
		$active   = TextMeFlow_Forms::active_sources();
		?>
		<div class="tmf-card">
			<h2><?php esc_html_e( 'Forms', 'textmeflow' ); ?></h2>
			<input type="hidden" name="<?php echo esc_attr( self::name( 'forms][_present' ) ); ?>" value="1" />
			<table class="widefat striped tmf-forms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Form plugin', 'textmeflow' ); ?></th>
						<th><?php esc_html_e( 'Alert me about new submissions', 'textmeflow' ); ?></th>
						<th><?php esc_html_e( 'Confirm to the visitor', 'textmeflow' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( TextMeFlow_Settings::form_sources() as $slug => $label ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $label ); ?>
							<?php if ( empty( $active[ $slug ] ) ) : ?>
								<br /><span class="description"><?php esc_html_e( 'Not active on this site', 'textmeflow' ); ?></span>
							<?php endif; ?>
						</td>
						<td><input type="checkbox" name="<?php echo esc_attr( self::name( 'forms][owner][' . $slug ) ); ?>" value="1" <?php checked( ! empty( $forms['owner'][ $slug ] ) ); ?> aria-label="<?php echo esc_attr( $label ); ?>" /></td>
						<td><input type="checkbox" name="<?php echo esc_attr( self::name( 'forms][confirm][' . $slug ) ); ?>" value="1" <?php checked( ! empty( $forms['confirm'][ $slug ] ) ); ?> aria-label="<?php echo esc_attr( $label ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'A confirmation is only sent when the form has a phone field and a checkbox for WhatsApp permission. Give that checkbox a name or label that contains "whatsapp" (for example "whatsapp-consent"). Without that ticked checkbox, nothing is sent to the visitor.', 'textmeflow' ); ?>
			</p>

			<?php self::render_placeholder_help( array( '{form_name}', '{site_name}', '{summary}' ) ); ?>
			<p>
				<label for="tmf-form-owner"><strong><?php esc_html_e( 'Alert to you', 'textmeflow' ); ?></strong></label><br />
				<textarea id="tmf-form-owner" rows="3" class="large-text" name="<?php echo esc_attr( self::name( 'forms][owner_text' ) ); ?>"><?php echo esc_textarea( TextMeFlow_Settings::text_or_default( $forms['owner_text'], 'form_owner' ) ); ?></textarea>
			</p>
			<p>
				<label for="tmf-form-confirm"><strong><?php esc_html_e( 'Confirmation to the visitor', 'textmeflow' ); ?></strong></label><br />
				<textarea id="tmf-form-confirm" rows="3" class="large-text" name="<?php echo esc_attr( self::name( 'forms][confirm_text' ) ); ?>"><?php echo esc_textarea( TextMeFlow_Settings::text_or_default( $forms['confirm_text'], 'form_confirm' ) ); ?></textarea>
			</p>
		</div>
		<?php
	}

	/**
	 * Hint listing the placeholders that can be used in a text.
	 *
	 * @param string[] $placeholders Placeholders including braces.
	 */
	private static function render_placeholder_help( array $placeholders ) {
		echo '<p class="description">' . esc_html__( 'You can use these placeholders:', 'textmeflow' );
		foreach ( $placeholders as $placeholder ) {
			echo ' <code>' . esc_html( $placeholder ) . '</code>';
		}
		echo '</p>';
	}

	/**
	 * Recent activity.
	 */
	private static function render_log() {
		$log = TextMeFlow_Log::get();
		?>
		<div class="tmf-card">
			<h2><?php esc_html_e( 'Recent activity', 'textmeflow' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'Nothing yet.', 'textmeflow' ); ?></p>
			<?php else : ?>
				<ul class="tmf-log">
				<?php foreach ( $log as $entry ) : ?>
					<li class="tmf-log-<?php echo esc_attr( $entry['status'] ); ?>">
						<time><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></time>
						<?php echo esc_html( $entry['message'] ); ?>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
