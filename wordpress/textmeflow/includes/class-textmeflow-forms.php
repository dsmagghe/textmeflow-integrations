<?php
/**
 * Form plugin integrations: Contact Form 7, WPForms, Elementor Forms, Gravity Forms.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a "new submission" message to the site owner and, when the visitor
 * gave a phone number and explicit WhatsApp consent, a confirmation to the visitor.
 *
 * A consent field is a checkbox/acceptance field whose name or label contains
 * a word such as "whatsapp", "consent" or "toestemming" (see consent_words()).
 */
class TextMeFlow_Forms {

	/**
	 * Hook into the supported form plugins. The hooks are harmless when a plugin is absent.
	 */
	public static function init() {
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'handle_cf7' ), 20, 1 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'handle_wpforms' ), 20, 4 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'handle_elementor' ), 20, 2 );
		add_action( 'gform_after_submission', array( __CLASS__, 'handle_gravity' ), 20, 2 );
	}

	/**
	 * Which form plugins are active (slug => bool).
	 *
	 * @return array<string,bool>
	 */
	public static function active_sources() {
		return array(
			'cf7'       => defined( 'WPCF7_VERSION' ),
			'wpforms'   => function_exists( 'wpforms' ),
			'elementor' => defined( 'ELEMENTOR_PRO_VERSION' ),
			'gravity'   => class_exists( 'GFForms' ),
		);
	}

	/**
	 * Words that mark a field as the WhatsApp consent checkbox.
	 *
	 * @return string[]
	 */
	public static function consent_words() {
		/**
		 * Filter the words that identify a WhatsApp consent field by its name or label.
		 *
		 * @param string[] $words Lower-case words.
		 */
		return (array) apply_filters( 'textmeflow_consent_words', array( 'whatsapp', 'optin', 'opt-in', 'opt_in', 'toestemming', 'consent', 'consentement', 'einwilligung' ) );
	}

	/**
	 * Words that mark a text field as a phone number.
	 *
	 * @return string[]
	 */
	private static function phone_words() {
		return array( 'phone', 'tel', 'gsm', 'mobile', 'mobiel', 'telefoon', 'telefon', 'portable', 'handy', 'whatsapp' );
	}

	/**
	 * Whether a name or label contains one of the given words.
	 *
	 * @param string   $haystack Field name or label.
	 * @param string[] $words    Words.
	 * @return bool
	 */
	private static function has_word( $haystack, array $words ) {
		$haystack = strtolower( (string) $haystack );
		foreach ( $words as $word ) {
			if ( '' !== $word && false !== strpos( $haystack, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Flatten a value to a short single-line string.
	 *
	 * @param mixed $value Value (string or array).
	 * @return string
	 */
	private static function to_string( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( array( __CLASS__, 'to_string' ), $value ) ) );
		}
		$value = wp_strip_all_tags( (string) $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Contact Form 7.
	 *
	 * @param WPCF7_ContactForm $form Form.
	 */
	public static function handle_cf7( $form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) || ! is_object( $form ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$data   = (array) $submission->get_posted_data();
		$fields = array();
		$phone  = '';
		$accept = false;

		foreach ( $form->scan_form_tags() as $tag ) {
			if ( empty( $tag->name ) || in_array( $tag->basetype, array( 'submit', 'captchar', 'captchac', 'quiz' ), true ) ) {
				continue;
			}
			$value = isset( $data[ $tag->name ] ) ? self::to_string( $data[ $tag->name ] ) : '';

			if ( in_array( $tag->basetype, array( 'acceptance', 'checkbox' ), true ) && self::has_word( $tag->name, self::consent_words() ) ) {
				$accept = $accept || '' !== $value;
				continue;
			}
			if ( '' === $phone && '' !== $value && ( 'tel' === $tag->basetype || ( 'text' === $tag->basetype && self::has_word( $tag->name, self::phone_words() ) ) ) ) {
				$phone = $value;
			}
			if ( 'acceptance' === $tag->basetype || 'file' === $tag->basetype ) {
				continue;
			}
			$fields[] = array(
				'label' => $tag->name,
				'value' => $value,
			);
		}

		self::handle( 'cf7', $form->title(), $fields, $phone, $accept );
	}

	/**
	 * WPForms.
	 *
	 * @param array $entry_fields Processed fields.
	 * @param array $entry        Raw entry.
	 * @param array $form_data    Form settings.
	 */
	public static function handle_wpforms( $entry_fields, $entry, $form_data ) {
		$fields = array();
		$phone  = '';
		$accept = false;

		foreach ( (array) $entry_fields as $field ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$label = isset( $field['name'] ) ? (string) $field['name'] : '';
			$value = isset( $field['value'] ) ? self::to_string( $field['value'] ) : '';

			if ( in_array( $type, array( 'divider', 'html', 'pagebreak', 'content', 'captcha', 'stripe-credit-card', 'file-upload' ), true ) ) {
				continue;
			}
			if ( in_array( $type, array( 'checkbox', 'gdpr-checkbox' ), true ) && self::has_word( $label, self::consent_words() ) ) {
				$accept = $accept || '' !== $value;
				continue;
			}
			if ( '' === $phone && 'phone' === $type && '' !== $value ) {
				$phone = $value;
			}
			$fields[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$title = isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : 'WPForms';
		self::handle( 'wpforms', $title, $fields, $phone, $accept );
	}

	/**
	 * Elementor Pro Forms.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler (unused).
	 */
	public static function handle_elementor( $record, $handler = null ) {
		unset( $handler );
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$fields = array();
		$phone  = '';
		$accept = false;

		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$label = ! empty( $field['title'] ) ? (string) $field['title'] : (string) $id;
			$value = isset( $field['value'] ) ? self::to_string( $field['value'] ) : '';

			if ( in_array( $type, array( 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'honeypot', 'step', 'upload', 'html' ), true ) ) {
				continue;
			}
			if ( in_array( $type, array( 'acceptance', 'checkbox' ), true ) && ( self::has_word( $id, self::consent_words() ) || self::has_word( $label, self::consent_words() ) ) ) {
				$accept = $accept || '' !== $value;
				continue;
			}
			if ( '' === $phone && '' !== $value && ( 'tel' === $type || ( 'text' === $type && self::has_word( $id . ' ' . $label, self::phone_words() ) ) ) ) {
				$phone = $value;
			}
			$fields[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$settings = (array) $record->get( 'form_settings' );
		$title    = ! empty( $settings['form_name'] ) ? (string) $settings['form_name'] : 'Elementor Form';
		self::handle( 'elementor', $title, $fields, $phone, $accept );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function handle_gravity( $entry, $form ) {
		if ( ! class_exists( 'GFFormsModel' ) || empty( $form['fields'] ) ) {
			return;
		}
		$fields = array();
		$phone  = '';
		$accept = false;

		foreach ( $form['fields'] as $field ) {
			$type  = isset( $field->type ) ? (string) $field->type : '';
			$label = isset( $field->label ) ? (string) $field->label : '';
			if ( in_array( $type, array( 'page', 'section', 'html', 'captcha', 'honeypot', 'fileupload', 'creditcard' ), true ) ) {
				continue;
			}
			$raw = GFFormsModel::get_lead_field_value( $entry, $field );

			if ( in_array( $type, array( 'consent', 'checkbox' ), true ) && self::has_word( $label, self::consent_words() ) ) {
				// A consent field stores "1" in input "<id>.1".
				$ticked = is_array( $raw ) ? ! empty( $raw[ $field->id . '.1' ] ) || ( 'checkbox' === $type && ! empty( array_filter( $raw ) ) ) : '' !== (string) $raw;
				$accept = $accept || $ticked;
				continue;
			}
			$value = self::to_string( $raw );
			if ( '' === $phone && 'phone' === $type && '' !== $value ) {
				$phone = $value;
			}
			$fields[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		self::handle( 'gravity', isset( $form['title'] ) ? (string) $form['title'] : 'Gravity Forms', $fields, $phone, $accept );
	}

	/**
	 * Common handling: owner notification and optional visitor confirmation.
	 *
	 * @param string $source  Form plugin slug.
	 * @param string $title   Form title.
	 * @param array  $fields  List of array{label:string,value:string}.
	 * @param string $phone   Phone number typed by the visitor (may be empty).
	 * @param bool   $consent Whether the visitor ticked the WhatsApp consent field.
	 */
	private static function handle( $source, $title, array $fields, $phone, $consent ) {
		if ( ! TextMeFlow_Connection::is_connected() ) {
			return;
		}
		$settings = TextMeFlow_Settings::get();
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$values   = array(
			'form_name' => wp_strip_all_tags( (string) $title ),
			'site_name' => $site,
			'summary'   => self::summary( $fields ),
		);

		$owner = TextMeFlow_Settings::owner_phone();
		if ( '' !== $owner && ! empty( $settings['forms']['owner'][ $source ] ) ) {
			$template = TextMeFlow_Settings::text_or_default( $settings['forms']['owner_text'], 'form_owner' );
			TextMeFlow_Sender::queue( $owner, TextMeFlow_Settings::render( $template, $values ) );
		}

		if ( $consent && '' !== $phone && ! empty( $settings['forms']['confirm'][ $source ] ) ) {
			$to = TextMeFlow_Phone::normalize( $phone, TextMeFlow_Settings::country() );
			if ( '' !== $to && $to !== $owner ) {
				$template = TextMeFlow_Settings::text_or_default( $settings['forms']['confirm_text'], 'form_confirm' );
				TextMeFlow_Sender::queue( $to, TextMeFlow_Settings::render( $template, $values ) );
			}
		}
	}

	/**
	 * "Label: value" lines, truncated to keep the message short.
	 *
	 * @param array $fields List of array{label:string,value:string}.
	 * @return string
	 */
	private static function summary( array $fields ) {
		$lines = array();
		$total = 0;
		foreach ( $fields as $field ) {
			if ( '' === $field['value'] ) {
				continue;
			}
			$line   = wp_strip_all_tags( $field['label'] ) . ': ' . mb_substr( $field['value'], 0, 200 );
			$total += mb_strlen( $line );
			if ( $total > 900 ) {
				$lines[] = '...';
				break;
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}
}
