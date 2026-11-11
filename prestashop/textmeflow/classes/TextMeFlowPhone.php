<?php

/**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <info@fades.tech>
 * @copyright Fades Management EOOD
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Converts phone numbers as typed by customers into the international format
 * required by the API, using the country of the address.
 */
class TextMeFlowPhone
{
    /** @var array<string,string> ISO 3166-1 alpha-2 => dial code (digits only) */
    private static $dialCodes = [
        'AT' => '43', 'BE' => '32', 'BG' => '359', 'HR' => '385', 'CY' => '357',
        'CZ' => '420', 'DK' => '45', 'EE' => '372', 'FI' => '358', 'FR' => '33',
        'DE' => '49', 'GR' => '30', 'HU' => '36', 'IE' => '353', 'IT' => '39',
        'LV' => '371', 'LT' => '370', 'LU' => '352', 'MT' => '356', 'NL' => '31',
        'PL' => '48', 'PT' => '351', 'RO' => '40', 'SK' => '421', 'SI' => '386',
        'ES' => '34', 'SE' => '46', 'GB' => '44', 'US' => '1', 'CA' => '1',
        'CH' => '41', 'NO' => '47', 'IS' => '354', 'LI' => '423',
    ];

    /**
     * @param string $raw Number as typed by the customer
     * @param string $countryIso ISO code of the address country
     *
     * @return string|null number like +32470123456, or null when it cannot be normalised
     */
    public static function toE164($raw, $countryIso)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (strpos($raw, '+') === 0) {
            $number = $digits;
        } elseif (strpos($digits, '00') === 0) {
            $number = substr($digits, 2);
        } else {
            $iso = strtoupper((string) $countryIso);
            if (!isset(self::$dialCodes[$iso])) {
                return null;
            }
            $code = self::$dialCodes[$iso];
            // A number that already starts with the dial code and is long enough is left alone
            // (e.g. 32470123456 typed without a plus); otherwise drop the national trunk zero.
            $national = ltrim($digits, '0');
            $number = $code . $national;
            if (strpos($digits, $code) === 0 && strlen($digits) >= strlen($code) + 8 && $digits[0] !== '0') {
                $number = $digits;
            }
        }
        if (!preg_match('/^[1-9]\d{7,14}$/', $number)) {
            return null;
        }

        return '+' . $number;
    }
}
