'use strict';

const { API_BASE } = require('./common');

// API key authentication. OAuth2 is possible later, see OAUTH2.md.
module.exports = {
  type: 'custom',
  fields: [
    {
      key: 'api_key',
      label: 'API key',
      type: 'password',
      required: true,
      helpText:
        'Log in at [textmeflow.eu](https://textmeflow.eu), open the portal and copy your key under **API key**. Link your WhatsApp number there first (WhatsApp > Settings > Linked devices).',
    },
  ],
  test: async (z) => {
    const response = await z.request({ url: `${API_BASE}/accounts/me` });
    return response.data;
  },
  connectionLabel: '{{json.email}}',
};
