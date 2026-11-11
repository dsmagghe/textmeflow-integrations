'use strict';

const { API_BASE } = require('../common');

/*
 * REST hook on the per-app webhook registration of the TextMeFlow API
 * (docs/connect-contract.md section 5b):
 *   POST   /v1/webhooks        { url, event: "message.inbound" } -> 201 { id, url, event, secret }
 *   DELETE /v1/webhooks/{id}   -> 204
 * Zapier gets the payload unsigned from its own side; the secret is not needed here.
 */
const subscribe = async (z, bundle) => {
  const response = await z.request({
    method: 'POST',
    url: `${API_BASE}/webhooks`,
    body: { url: bundle.targetUrl, event: 'message.inbound' },
  });
  // Keep only the id: the secret is not used by Zapier and must not be stored.
  return { id: response.data.id };
};

const unsubscribe = async (z, bundle) => {
  await z.request({ method: 'DELETE', url: `${API_BASE}/webhooks/${encodeURIComponent(bundle.subscribeData.id)}` });
  return {};
};

// Payload shape per docs/api.openapi.yaml (InboundMessage). Zapier needs an id for dedupe.
const parsePayload = async (z, bundle) => {
  const p = bundle.cleanedRequest || {};
  const headers = (bundle.rawRequest && bundle.rawRequest.headers) || {};
  const id = headers['x-textmeflow-event-id'] || headers['X-TextMeFlow-Event-Id'] || `${p.from}-${Date.now()}`;
  // Messages sent by the account itself are not "incoming".
  if (p.from_me === true || p.from_me === 'true') return [];
  return [{ id, type: p.type, from: p.from, from_name: p.from_name, to: p.to, message: p.message, file: p.file === 'null' ? null : p.file }];
};

const SAMPLE = { id: 'sample-event-1', type: 'text', from: '32472932208', from_name: 'Jan Janssens', to: '32499123456', message: 'Thanks for the confirmation!', file: null };

module.exports = {
  key: 'new_incoming_message',
  noun: 'Incoming message',
  display: {
    label: 'New Incoming Message',
    description: 'Triggers when someone replies to your linked WhatsApp number.',
  },
  operation: {
    type: 'hook',
    performSubscribe: subscribe,
    performUnsubscribe: unsubscribe,
    perform: parsePayload,
    // The API has no endpoint that lists inbound messages (GET /v1/messages does not exist),
    // so the editor test step uses a fixed sample.
    performList: async () => [SAMPLE],
    sample: SAMPLE,
  },
};
