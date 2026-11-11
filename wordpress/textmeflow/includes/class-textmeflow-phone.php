<?php
/**
 * Phone number normalisation to international (E.164) format.
 *
 * @package TextMeFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts the phone numbers people type into the international format
 * the TextMeFlow service requires.
 */
class TextMeFlow_Phone {

	/**
	 * Calling codes by ISO 3166-1 alpha-2 country code (EU, UK, US, CH, NO, IS, LI).
	 *
	 * @return array<string,string>
	 */
	public static function countries() {
		return array(
			'AT' => '43',
			'BE' => '32',
			'BG' => '359',
			'CH' => '41',
			'CY' => '357',
			'CZ' => '420',
			'DE' => '49',
			'DK' => '45',
			'EE' => '372',
			'ES' => '34',
			'FI' => '358',
			'FR' => '33',
			'GB' => '44',
			'GR' => '30',
			'HR' => '385',
			'HU' => '36',
			'IE' => '353',
			'IS' => '354',
			'IT' => '39',
			'LI' => '423',
			'LT' => '370',
			'LU' => '352',
			'LV' => '371',
			'MT' => '356',
			'NL' => '31',
			'NO' => '47',
			'PL' => '48',
			'PT' => '351',
			'RO' => '40',
			'SE' => '46',
			'SI' => '386',
			'SK' => '421',
			'US' => '1',
		);
	}

	/**
	 * Normalise a number to E.164 ("+32470123456").
	 *
	 * @param string $raw     Number as typed by the user.
	 * @param string $country ISO 3166-1 alpha-2 country used for national numbers.
	 * @return string E.164 number, or an empty string if it cannot be made valid.
	 */
	public static function normalize( $raw, $country = '' ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$international = ( 0 === strpos( $raw, '+' ) );
		$digits        = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}

		// "0032..." is the same as "+32...".
		if ( ! $international && 0 === strpos( $digits, '00' ) ) {
			$digits        = substr( $digits, 2 );
			$international = true;
		}

		$countries = self::countries();

		if ( $international ) {
			// "+32 (0)470 ..." - drop the national trunk zero after the calling code.
			foreach ( array( 3, 2, 1 ) as $len ) {
				$prefix = substr( $digits, 0, $len );
				if ( in_array( $prefix, $countries, true ) && '39' !== $prefix && '0' === substr( $digits, $len, 1 ) ) {
					$digits = $prefix . substr( $digits, $len + 1 );
					break;
				}
			}
		} else {
			$country = strtoupper( (string) $country );
			if ( ! isset( $countries[ $country ] ) ) {
				return '';
			}
			$national = preg_replace( '/^0+/', '', $digits );
			// Italy keeps the leading zero of landlines; everything else drops the trunk zero.
			if ( 'IT' === $country && 0 === strpos( $digits, '0' ) ) {
				$national = $digits;
			}
			$digits = $countries[ $country ] . $national;
		}

		$length = strlen( $digits );
		if ( $length < 8 || $length > 15 ) {
			return '';
		}

		return '+' . $digits;
	}

	/**
	 * Mask a number for logs: keep the calling code start and the last two digits.
	 *
	 * @param string $number E.164 number.
	 * @return string
	 */
	public static function mask( $number ) {
		$number = (string) $number;
		if ( strlen( $number ) < 7 ) {
			return '***';
		}
		return substr( $number, 0, 3 ) . str_repeat( '*', strlen( $number ) - 5 ) . substr( $number, -2 );
	}
}
