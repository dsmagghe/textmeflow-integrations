'use strict';

const API_BASE = process.env.TEXTMEFLOW_API_BASE || 'https://api.textmeflow.eu/v1';

/**
 * Normalises a phone number to E.164 as far as that is possible without
 * knowing the country: strips spaces/dashes/brackets and turns 00 into +.
 * Numbers without a country code are rejected with a clear message.
 */
const toE164 = (z, raw) => {
  let n = String(raw || '').replace(/[\s().-]/g, '');
  if (n.startsWith('00')) n = `+${n.slice(2)}`;
  if (!/^\+[1-9]\d{6,14}$/.test(n)) {
    throw new z.errors.Error(
      'The phone number must include the country code, for example +32470123456.',
      'InvalidNumber',
      400
    );
  }
  return n;
};

module.exports = { API_BASE, toE164 };
