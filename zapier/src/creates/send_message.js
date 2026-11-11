'use strict';

const { API_BASE, toE164 } = require('../common');

const perform = async (z, bundle) => {
  const body = {
    to: toE164(z, bundle.inputData.to),
    text: bundle.inputData.text,
  };
  if (bundle.inputData.media_url) body.media_url = bundle.inputData.media_url;
  const response = await z.request({ method: 'POST', url: `${API_BASE}/messages`, body });
  const d = response.data || {};
  return { id: d.message_id || d.id, status: d.status || 'queued', to: body.to };
};

module.exports = {
  key: 'send_message',
  noun: 'Message',
  display: {
    label: 'Send WhatsApp Message',
    description: 'Sends a WhatsApp message from your linked number, optionally with an image or file.',
  },
  operation: {
    inputFields: [
      { key: 'to', label: 'Phone number', required: true, helpText: 'With country code, for example +32470123456. Only message people who agreed to receive WhatsApp messages from you.' },
      { key: 'text', label: 'Message', required: true, type: 'text' },
      { key: 'media_url', label: 'Image or file URL', required: false, helpText: 'Optional. A public https link to an image or file.' },
    ],
    perform,
    sample: { id: '3f2b1c0e-6d1a-4c55-9d52-0a1f5e3f9a11', status: 'queued', to: '+32470123456' },
    outputFields: [
      { key: 'id', label: 'Message ID' },
      { key: 'status', label: 'Status' },
      { key: 'to', label: 'Phone number' },
    ],
  },
};
