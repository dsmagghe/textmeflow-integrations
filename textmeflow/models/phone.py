"""E.164 normalisation (contract section 5)."""
import re

# ISO 3166-1 alpha-2 -> international dialling code (EU + UK/US/CH/NO and a few more).
COUNTRY_CODES = {
    "AT": "43", "BE": "32", "BG": "359", "HR": "385", "CY": "357", "CZ": "420",
    "DK": "45", "EE": "372", "FI": "358", "FR": "33", "DE": "49", "GR": "30",
    "HU": "36", "IE": "353", "IT": "39", "LV": "371", "LT": "370", "LU": "352",
    "MT": "356", "NL": "31", "PL": "48", "PT": "351", "RO": "40", "SK": "421",
    "SI": "386", "ES": "34", "SE": "46", "GB": "44", "US": "1", "CA": "1",
    "CH": "41", "NO": "47", "LI": "423", "IS": "354",
}


def normalize_phone(raw, country_code=None):
    """Return the number in E.164 (``+32470123456``) or ``False`` when impossible.

    :param raw: the number as typed by a human
    :param country_code: ISO alpha-2 country of the customer, used for national numbers
    """
    if not raw:
        return False
    number = re.sub(r"[^\d+]", "", raw.strip())
    if number.startswith("00"):
        number = "+" + number[2:]
    if number.startswith("+"):
        digits = re.sub(r"\D", "", number)
    else:
        prefix = COUNTRY_CODES.get((country_code or "").upper())
        if not prefix:
            return False
        digits = re.sub(r"\D", "", number)
        if digits.startswith("0"):
            digits = digits[1:]
        digits = prefix + digits
    if not 8 <= len(digits) <= 15:
        return False
    return "+" + digits
