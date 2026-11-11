<?php declare(strict_types=1);

namespace TextMeFlow\Service;

/**
 * Turns a phone number as typed by a customer into E.164 (+<country code><number>),
 * which is the only format the TextMeFlow API accepts.
 */
final class PhoneNormalizer
{
    /**
     * Calling codes for the EU member states plus UK, US, CH, NO, IS and LI.
     *
     * @var array<string, string>
     */
    public const CALLING_CODES = [
        'AT' => '43', 'BE' => '32', 'BG' => '359', 'HR' => '385', 'CY' => '357',
        'CZ' => '420', 'DK' => '45', 'EE' => '372', 'FI' => '358', 'FR' => '33',
        'DE' => '49', 'GR' => '30', 'HU' => '36', 'IE' => '353', 'IT' => '39',
        'LV' => '371', 'LT' => '370', 'LU' => '352', 'MT' => '356', 'NL' => '31',
        'PL' => '48', 'PT' => '351', 'RO' => '40', 'SK' => '421', 'SI' => '386',
        'ES' => '34', 'SE' => '46', 'GB' => '44', 'US' => '1', 'CH' => '41',
        'NO' => '47', 'IS' => '354', 'LI' => '423',
    ];

    /**
     * Countries whose national numbers keep a leading zero after the country code (Italy).
     */
    private const KEEP_LEADING_ZERO = ['IT'];

    /**
     * @param string|null $countryIso ISO 3166-1 alpha-2 code of the customer's (delivery or billing) country
     *
     * @return string|null E.164 number, or null when it cannot be made valid
     */
    public function normalize(?string $raw, ?string $countryIso): ?string
    {
        if ($raw === null) {
            return null;
        }

        $number = trim($raw);
        if ($number === '') {
            return null;
        }

        // "+32 (0)470 12 34 56": the (0) is a trunk prefix that must be dropped.
        $number = (string) preg_replace('/\(\s*0\s*\)/', '', $number);
        // Keep only digits and a leading plus; drop spaces, dashes, dots, slashes and brackets.
        $hasPlus = str_starts_with($number, '+');
        $digits = (string) preg_replace('/\D+/', '', $number);
        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            return $this->validate('+' . $digits);
        }

        if (str_starts_with($digits, '00')) {
            return $this->validate('+' . substr($digits, 2));
        }

        $iso = $countryIso === null ? '' : strtoupper(trim($countryIso));
        if (!isset(self::CALLING_CODES[$iso])) {
            return null;
        }

        $code = self::CALLING_CODES[$iso];
        if (str_starts_with($digits, '0') && !\in_array($iso, self::KEEP_LEADING_ZERO, true)) {
            $digits = substr($digits, 1);
        }

        return $this->validate('+' . $code . $digits);
    }

    private function validate(string $e164): ?string
    {
        return preg_match('/^\+[1-9]\d{7,14}$/', $e164) === 1 ? $e164 : null;
    }
}
