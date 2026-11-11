'use strict';

const { version: platformVersion } = require('zapier-platform-core');
const { version } = require('./package.json');
const authentication = require('./src/authentication');
const sendMessage = require('./src/creates/send_message');
const findMessageStatus = require('./src/searches/find_message_status');
const newIncomingMessage = require('./src/triggers/new_incoming_message');

/** Adds the API key as a Bearer token to every outgoing request. */
const addAuth = (request, z, bundle) => {
  if (bundle.authData && bundle.authData.api_key) {
    request.headers = request.headers || {};
    request.headers.Authorization = `Bearer ${bundle.authData.api_key}`;
  }
  return request;
};

/** Turns API errors into readable Zapier errors (no raw payloads). */
const handleErrors = (response, z) => {
  if (response.request && response.request.skipThrowForStatus) return response;
  if (response.status === 401) {
    throw new z.errors.RefreshAuthError('Your TextMeFlow API key is no longer valid. Reconnect your account.');
  }
  if (response.status === 429) {
    throw new z.errors.ThrottledError('TextMeFlow limit reached (quota or rate limit).', 60);
  }
  if (response.status >= 400) {
    const reason = response.data && typeof response.data.error === 'string' ? response.data.error : '';
    throw new z.errors.Error(`TextMeFlow error ${response.status}${reason ? `: ${reason}` : ''}`, 'ApiError', response.status);
  }
  return response;
};

module.exports = {
  version,
  platformVersion,
  authentication,
  beforeRequest: [addAuth],
  afterResponse: [handleErrors],
  creates: { [sendMessage.key]: sendMessage },
  searches: { [findMessageStatus.key]: findMessageStatus },
  triggers: { [newIncomingMessage.key]: newIncomingMessage },
};
