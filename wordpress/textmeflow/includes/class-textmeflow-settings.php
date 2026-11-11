<?php
/**
 * Settings, default texts and sanitising.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * All plugin settings live in one option: textmeflow_settings.
 */
class TextMeFlow_Settings {

	const OPTION = 'textmeflow_settings';
	const GROUP  = 'textmeflow';

	/**
	 * Order statuses a customer message can be sent for (without the "wc-" prefix).
	 *
	 * @return string[]
	 */
	public static function wc_statuses() {
		return array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed' );
	}

	/**
	 * Form plugins supported (slug => label).
	 *
	 * @return array<string,string>
	 */
	public static function form_sources() {
		return array(
			'cf7'       => 'Contact Form 7',
			'wpforms'   => 'WPForms',
			'elementor' => 'Elementor Forms',
			'gravity'   => 'Gravity Forms',
		);
	}

	/**
	 * Language used for default texts: the site language (nl, fr, de, otherwise en).
	 *
	 * @return string
	 */
	public static function lang() {
		$lang = strtolower( substr( (string) get_locale(), 0, 2 ) );
		return in_array( $lang, array( 'nl', 'fr', 'de' ), true ) ? $lang : 'en';
	}

	/**
	 * Default message texts per key and language. These are customer-facing
	 * message templates in the site language, independent of the admin language.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function texts() {
		return array(
			'processing'    => array(
				'en' => 'Hi {first_name}, thanks for your order #{order_number} at {shop_name}. We received it and are getting it ready.',
				'nl' => 'Dag {first_name}, bedankt voor je bestelling #{order_number} bij {shop_name}. We hebben ze ontvangen en maken ze klaar.',
				'fr' => 'Bonjour {first_name}, merci pour votre commande n°{order_number} chez {shop_name}. Nous l\'avons bien reçue et la préparons.',
				'de' => 'Hallo {first_name}, danke für deine Bestellung Nr. {order_number} bei {shop_name}. Wir haben sie erhalten und bereiten sie vor.',
			),
			'completed'     => array(
				'en' => "Hi {first_name}, your order #{order_number} at {shop_name} is complete. Thanks for shopping with us!\nTrack your parcel: {tracking_url}",
				'nl' => "Dag {first_name}, je bestelling #{order_number} bij {shop_name} is afgerond. Bedankt voor je aankoop!\nVolg je pakket: {tracking_url}",
				'fr' => "Bonjour {first_name}, votre commande n°{order_number} chez {shop_name} est terminée. Merci pour votre achat !\nSuivez votre colis : {tracking_url}",
				'de' => "Hallo {first_name}, deine Bestellung Nr. {order_number} bei {shop_name} ist abgeschlossen. Danke für deinen Einkauf!\nSendung verfolgen: {tracking_url}",
			),
			'on-hold'       => array(
				'en' => 'Hi {first_name}, your order #{order_number} at {shop_name} is on hold until we have received or confirmed your payment.',
				'nl' => 'Dag {first_name}, je bestelling #{order_number} bij {shop_name} staat in de wacht tot we je betaling hebben ontvangen of bevestigd.',
				'fr' => 'Bonjour {first_name}, votre commande n°{order_number} chez {shop_name} est en attente jusqu\'à la réception ou la confirmation de votre paiement.',
				'de' => 'Hallo {first_name}, deine Bestellung Nr. {order_number} bei {shop_name} ist in der Warteschleife, bis wir deine Zahlung erhalten oder bestätigt haben.',
			),
			'cancelled'     => array(
				'en' => 'Hi {first_name}, your order #{order_number} at {shop_name} has been cancelled. Questions? Please contact us through our website.',
				'nl' => 'Dag {first_name}, je bestelling #{order_number} bij {shop_name} is geannuleerd. Vragen? Neem contact met ons op via onze website.',
				'fr' => 'Bonjour {first_name}, votre commande n°{order_number} chez {shop_name} a été annulée. Des questions ? Contactez-nous via notre site.',
				'de' => 'Hallo {first_name}, deine Bestellung Nr. {order_number} bei {shop_name} wurde storniert. Fragen? Kontaktiere uns bitte über unsere Website.',
			),
			'refunded'      => array(
				'en' => 'Hi {first_name}, your order #{order_number} at {shop_name} has been refunded ({order_total}).',
				'nl' => 'Dag {first_name}, je bestelling #{order_number} bij {shop_name} is terugbetaald ({order_total}).',
				'fr' => 'Bonjour {first_name}, votre commande n°{order_number} chez {shop_name} a été remboursée ({order_total}).',
				'de' => 'Hallo {first_name}, deine Bestellung Nr. {order_number} bei {shop_name} wurde erstattet ({order_total}).',
			),
			'failed'        => array(
				'en' => 'Hi {first_name}, the payment for your order #{order_number} at {shop_name} did not go through. You can try again here: {payment_url}',
				'nl' => 'Dag {first_name}, de betaling voor je bestelling #{order_number} bij {shop_name} is niet gelukt. Je kan het hier opnieuw proberen: {payment_url}',
				'fr' => 'Bonjour {first_name}, le paiement de votre commande n°{order_number} chez {shop_name} n\'a pas abouti. Vous pouvez réessayer ici : {payment_url}',
				'de' => 'Hallo {first_name}, die Zahlung für deine Bestellung Nr. {order_number} bei {shop_name} ist fehlgeschlagen. Du kannst es hier erneut versuchen: {payment_url}',
			),
			'owner_order'   => array(
				'en' => 'New order #{order_number} on {shop_name}: {order_total} from {first_name} {last_name}.',
				'nl' => 'Nieuwe bestelling #{order_number} op {shop_name}: {order_total} van {first_name} {last_name}.',
				'fr' => 'Nouvelle commande n°{order_number} sur {shop_name} : {order_total} de {first_name} {last_name}.',
				'de' => 'Neue Bestellung Nr. {order_number} auf {shop_name}: {order_total} von {first_name} {last_name}.',
			),
			'optin_label'   => array(
				'en' => 'Send me updates about my order via WhatsApp',
				'nl' => 'Stuur mij updates over mijn bestelling via WhatsApp',
				'fr' => 'Envoyez-moi des informations sur ma commande par WhatsApp',
				'de' => 'Sendet mir Updates zu meiner Bestellung per WhatsApp',
			),
			'form_owner'    => array(
				'en' => "New submission via {form_name} on {site_name}:\n{summary}",
				'nl' => "Nieuwe inzending via {form_name} op {site_name}:\n{summary}",
				'fr' => "Nouvel envoi via {form_name} sur {site_name} :\n{summary}",
				'de' => "Neue Einsendung über {form_name} auf {site_name}:\n{summary}",
			),
			'form_confirm'  => array(
				'en' => 'Thanks for your message! We received it and will get back to you soon. - {site_name}',
				'nl' => 'Bedankt voor je bericht! We hebben het ontvangen en komen er snel op terug. - {site_name}',
				'fr' => 'Merci pour votre message ! Nous l\'avons bien reçu et revenons vers vous rapidement. - {site_name}',
				'de' => 'Danke für deine Nachricht! Wir haben sie erhalten und melden uns bald bei dir. - {site_name}',
			),
			'test_message'  => array(
				'en' => 'This is a test message from TextMeFlow on {site_name}. Everything works!',
				'nl' => 'Dit is een testbericht van TextMeFlow op {site_name}. Alles werkt!',
				'fr' => 'Ceci est un message de test de TextMeFlow sur {site_name}. Tout fonctionne !',
				'de' => 'Dies ist eine Testnachricht von TextMeFlow auf {site_name}. Alles funktioniert!',
			),
		);
	}

	/**
	 * Default text for a key in the site language.
	 *
	 * @param string $key Text key.
	 * @return string
	 */
	public static function default_text( $key ) {
		$texts = self::texts();
		if ( ! isset( $texts[ $key ] ) ) {
			return '';
		}
		$lang = self::lang();
		return $texts[ $key ][ $lang ];
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$statuses = array();
		foreach ( self::wc_statuses() as $status ) {
			$statuses[ $status ] = array(
				'enabled' => in_array( $status, array( 'processing', 'completed' ), true ),
				'text'    => '',
			);
		}

		$owner   = array();
		$confirm = array();
		foreach ( array_keys( self::form_sources() ) as $source ) {
			$owner[ $source ]   = true;
			$confirm[ $source ] = false;
		}

		return array(
			'owner_phone'     => '',
			'default_country' => '',
			'wc'              => array(
				'statuses'          => $statuses,
				'optin_mode'        => 'checkbox', // "checkbox" = ask at checkout, "none" = consent collected elsewhere.
				'optin_label'       => '',
				'owner_new_order'   => false,
				'owner_order_text'  => '',
			),
			'forms'           => array(
				'owner'        => $owner,
				'confirm'      => $confirm,
				'owner_text'   => '',
				'confirm_text' => '',
			),
		);
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return self::merge( self::defaults(), $stored );
	}

	/**
	 * Recursive merge where stored scalars override defaults (lists are not appended).
	 *
	 * @param array $base     Defaults.
	 * @param array $override Stored values.
	 * @return array
	 */
	private static function merge( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} elseif ( array_key_exists( $key, $base ) ) {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Text to use for a template: the shop's own text or the default.
	 *
	 * @param string $custom Stored custom text.
	 * @param string $key    Default text key.
	 * @return string
	 */
	public static function text_or_default( $custom, $key ) {
		$custom = (string) $custom;
		return '' !== trim( $custom ) ? $custom : self::default_text( $key );
	}

	/**
	 * Country used to turn national phone numbers into international ones.
	 * Setting first, then the WooCommerce shop country, then the site locale.
	 *
	 * @param string $preferred Country from the order or form (ISO alpha-2), may be empty.
	 * @return string ISO alpha-2 or empty string.
	 */
	public static function country( $preferred = '' ) {
		$countries = TextMeFlow_Phone::countries();
		$preferred = strtoupper( (string) $preferred );
		if ( isset( $countries[ $preferred ] ) ) {
			return $preferred;
		}

		$settings = self::get();
		if ( isset( $countries[ $settings['default_country'] ] ) ) {
			return $settings['default_country'];
		}

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$base = strtoupper( (string) WC()->countries->get_base_country() );
			if ( isset( $countries[ $base ] ) ) {
				return $base;
			}
		}

		$locale = (string) get_locale();
		if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) && isset( $countries[ $m[1] ] ) ) {
			return $m[1];
		}
		$by_lang = array(
			'nl' => 'NL',
			'fr' => 'FR',
			'de' => 'DE',
			'en' => 'GB',
		);
		return $by_lang[ self::lang() ];
	}

	/**
	 * Owner's own WhatsApp number in E.164, or an empty string.
	 *
	 * @return string
	 */
	public static function owner_phone() {
		$settings = self::get();
		return TextMeFlow_Phone::normalize( $settings['owner_phone'], self::country() );
	}

	/**
	 * Sanitise a message template: plain text, newlines kept, empty when equal to the default.
	 *
	 * @param mixed  $value Submitted text.
	 * @param string $key   Default text key.
	 * @return string
	 */
	private static function clean_text( $value, $key ) {
		$value = trim( sanitize_textarea_field( (string) $value ) );
		$value = mb_substr( $value, 0, 1000 );
		return ( $value === self::default_text( $key ) ) ? '' : $value;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Sections that are not part of the submitted form (for instance because
	 * WooCommerce is not active) keep their stored values.
	 *
	 * @param mixed $input Raw submitted data.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::get();

		if ( isset( $input['owner_phone'] ) ) {
			$out['owner_phone'] = trim( preg_replace( '/[^0-9+\s().-]/', '', (string) $input['owner_phone'] ) );
		}

		if ( isset( $input['default_country'] ) ) {
			$country                = strtoupper( sanitize_text_field( (string) $input['default_country'] ) );
			$out['default_country'] = isset( TextMeFlow_Phone::countries()[ $country ] ) ? $country : '';
		}

		if ( isset( $input['wc']['_present'] ) ) {
			$wc = $input['wc'];
			foreach ( self::wc_statuses() as $status ) {
				$out['wc']['statuses'][ $status ] = array(
					'enabled' => ! empty( $wc['statuses'][ $status ]['enabled'] ),
					'text'    => self::clean_text( isset( $wc['statuses'][ $status ]['text'] ) ? $wc['statuses'][ $status ]['text'] : '', $status ),
				);
			}
			$mode                         = isset( $wc['optin_mode'] ) ? (string) $wc['optin_mode'] : 'checkbox';
			$out['wc']['optin_mode']      = in_array( $mode, array( 'checkbox', 'none' ), true ) ? $mode : 'checkbox';
			$out['wc']['optin_label']     = self::clean_text( isset( $wc['optin_label'] ) ? $wc['optin_label'] : '', 'optin_label' );
			$out['wc']['owner_new_order'] = ! empty( $wc['owner_new_order'] );
			$out['wc']['owner_order_text'] = self::clean_text( isset( $wc['owner_order_text'] ) ? $wc['owner_order_text'] : '', 'owner_order' );
		}

		if ( isset( $input['forms']['_present'] ) ) {
			$forms = $input['forms'];
			foreach ( array_keys( self::form_sources() ) as $source ) {
				$out['forms']['owner'][ $source ]   = ! empty( $forms['owner'][ $source ] );
				$out['forms']['confirm'][ $source ] = ! empty( $forms['confirm'][ $source ] );
			}
			$out['forms']['owner_text']   = self::clean_text( isset( $forms['owner_text'] ) ? $forms['owner_text'] : '', 'form_owner' );
			$out['forms']['confirm_text'] = self::clean_text( isset( $forms['confirm_text'] ) ? $forms['confirm_text'] : '', 'form_confirm' );
		}

		return $out;
	}

	/**
	 * Replace {placeholders} in a template.
	 *
	 * A line that contains a placeholder whose value is empty is dropped
	 * (for example the tracking line when there is no tracking link).
	 *
	 * @param string               $template Template text.
	 * @param array<string,string> $values   Placeholder values (without braces).
	 * @return string
	 */
	public static function render( $template, array $values ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $template );
		$out   = array();
		foreach ( $lines as $line ) {
			$drop = false;
			foreach ( $values as $name => $value ) {
				if ( '' === (string) $value && false !== strpos( $line, '{' . $name . '}' ) && in_array( $name, array( 'tracking_url', 'payment_url', 'summary' ), true ) ) {
					$drop = true;
					break;
				}
			}
			if ( ! $drop ) {
				$out[] = $line;
			}
		}
		$text = implode( "\n", $out );
		// Single pass, so submitted values that look like placeholders are never replaced again.
		$text = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $match ) use ( $values ) {
				return isset( $values[ $match[1] ] ) ? (string) $values[ $match[1] ] : '';
			},
			$text
		);
		return trim( $text );
	}
}
