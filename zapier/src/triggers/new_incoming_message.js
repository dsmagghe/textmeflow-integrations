'use strict';

const { API_BASE } = require('../common');

/*
 * REST hook. Needs two endpoints the TextMeFlow API does not have yet
 * (the API today only knows ONE webhook_url per account, set in the portal):
 *   POST   /v1/webhooks        { url, event: "message.inbound" } -> { id }
 *   DELETE /v1/webhooks/{id}
 * The trigger is therefore hidden until the server supports this.
 * See README.md and SUBMIT.md.
 */
const subscribe = async (z, bundle) => {
  const response = await z.request({
    method: 'POST',
    url: `${API_BASE}/webhooks`,
    body: { url: bundle.targetUrl, event: 'message.inbound' },
  });
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
  return [{ id, type: p.type, from: p.from, from_name: p.from_name, to: p.to, message: p.message, file: p.file === 'null' ? null : p.file }];
};

const SAMPLE = { id: 'sample-event-1', type: 'text', from: '32472932208', from_name: 'Jan Janssens', to: '32499123456', message: 'Thanks for the confirmation!', file: null };

module.exports = {
  key: 'new_incoming_message',
  noun: 'Incoming message',
  display: {
    label: 'New Incoming Message',
    description: 'Triggers when someone replies to your linked WhatsApp number.',
    hidden: true,
  },
  operation: {
    type: 'hook',
    performSubscribe: subscribe,
    performUnsubscribe: unsubscribe,
    perform: parsePayload,
    performList: async () => [SAMPLE],
    sample: SAMPLE,
  },
};
