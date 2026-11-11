'use strict';

const { API_BASE } = require('../common');

const perform = async (z, bundle) => {
  const response = await z.request({
    url: `${API_BASE}/messages/${encodeURIComponent(bundle.inputData.message_id)}`,
    // A missing message is "not found" (empty result), not an error.
    skipThrowForStatus: true,
  });
  if (response.status === 404) return [];
  if (response.status === 401) throw new z.errors.RefreshAuthError('Your TextMeFlow API key is no longer valid.');
  if (response.status >= 400) {
    throw new z.errors.Error(`TextMeFlow error ${response.status}`, 'ApiError', response.status);
  }
  const m = response.data || {};
  return [{ id: m.id || m.message_id || bundle.inputData.message_id, status: m.status, to: m.to, text: m.text, created_at: m.created_at, sent_at: m.sent_at }];
};

module.exports = {
  key: 'find_message_status',
  noun: 'Message',
  display: {
    label: 'Find Message Status',
    description: 'Looks up a sent message by its ID and returns its delivery status.',
  },
  operation: {
    inputFields: [{ key: 'message_id', label: 'Message ID', required: true, helpText: 'The ID returned by "Send WhatsApp Message".' }],
    perform,
    sample: { id: '3f2b1c0e-6d1a-4c55-9d52-0a1f5e3f9a11', status: 'sent', to: '+32470123456', text: 'Hello', created_at: '2026-11-11T10:00:00Z', sent_at: '2026-11-11T10:00:05Z' },
  },
};
