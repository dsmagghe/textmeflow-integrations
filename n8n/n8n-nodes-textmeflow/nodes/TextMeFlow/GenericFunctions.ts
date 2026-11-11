/**
 * Dial codes for the countries most merchants send to: EU/EEA, UK, CH, US.
 * Keys are ISO 3166-1 alpha-2 codes.
 */
export const COUNTRY_DIAL_CODES: Record<string, string> = {
	AT: '43',
	BE: '32',
	BG: '359',
	CH: '41',
	CY: '357',
	CZ: '420',
	DE: '49',
	DK: '45',
	EE: '372',
	ES: '34',
	FI: '358',
	FR: '33',
	GB: '44',
	GR: '30',
	HR: '385',
	HU: '36',
	IE: '353',
	IS: '354',
	IT: '39',
	LI: '423',
	LT: '370',
	LU: '352',
	LV: '371',
	MT: '356',
	NL: '31',
	NO: '47',
	PL: '48',
	PT: '351',
	RO: '40',
	SE: '46',
	SI: '386',
	SK: '421',
	US: '1',
};

/**
 * Normalise a phone number to E.164 (+<country code><number>).
 *
 * - Spaces, dots, dashes and brackets are removed.
 * - A leading "00" becomes "+".
 * - A national leading "0" is replaced by the dial code of `country` (ISO code).
 * - Numbers that already start with "+" are kept as they are.
 *
 * When the number cannot be made E.164 (a national number without a country),
 * the cleaned digits are returned unchanged and the API answers with a clear
 * "invalid recipient" error.
 */
export function normalizePhoneNumber(input: string, country?: string): string {
	// "+32 (0)470 ..." is a common way to write "+32 470 ...": drop the "(0)".
	const cleaned = input
		.trim()
		.replace(/\(0\)/g, '')
		.replace(/[\s().\-/]/g, '');

	if (cleaned.startsWith('+')) {
		return '+' + cleaned.slice(1).replace(/\D/g, '');
	}

	const digits = cleaned.replace(/\D/g, '');

	if (digits.startsWith('00')) {
		return '+' + digits.slice(2);
	}

	const dial = country ? COUNTRY_DIAL_CODES[country.toUpperCase()] : undefined;
	if (dial && digits.startsWith('0')) {
		return '+' + dial + digits.slice(1);
	}

	return digits;
}
