# TextMeFlow for Zapier

Zapier Platform CLI app (`zapier-platform-core` 19.3.0, Node 22).

## For the user (3 steps)

1. In Zapier, add a "TextMeFlow" step and choose "Send WhatsApp Message".
2. Click "Connect", paste your API key (textmeflow.eu portal > API key). Link your WhatsApp number there first by scanning the QR code.
3. Fill in the phone number (with country code), the message and optionally an image URL. Done.

Note: the one-click "Verbind met TextMeFlow" flow from `docs/connect-contract.md` is not used here yet, because Zapier needs OAuth2 for that. See [OAUTH2.md](OAUTH2.md).

## What is in the app

| Type | Name | Status |
| --- | --- | --- |
| Action | Send WhatsApp Message (`to`, `text`, `media_url`) | works against `POST /v1/messages` |
| Search | Find Message Status | works against `GET /v1/messages/{id}`; 404 gives an empty result |
| Trigger | New Incoming Message (REST hook) | live; registers itself per Zap through `POST /v1/webhooks` and removes itself on `DELETE /v1/webhooks/{id}` |

### How the trigger works
When a Zap is switched on, Zapier calls `POST /v1/webhooks` with its target URL and `event: "message.inbound"`; when the Zap is switched off or deleted it calls `DELETE /v1/webhooks/{id}`. Users paste nothing. The `X-TextMeFlow-Event-Id` header is used for dedupe. The API has no endpoint that lists inbound messages, so the "test trigger" step in the editor uses a fixed sample message (`performList`). Still to do before publishing: a live test Zap, then push a new version.

## Development

```bash
cd integrations/zapier
npm install
npm test                 # jest + nock, no network
npm run validate         # zapier-platform validate --without-style (local, no login)
npx zapier-platform login && npx zapier-platform register "TextMeFlow"   # once, by the account owner
npx zapier-platform push
```

Behaviour: the number is normalised to E.164 (spaces/dashes removed, `00` becomes `+`); numbers without a country code are rejected with a clear message. 401 becomes a "reconnect" error, 429 is handled as throttling by the platform. The API key is only sent as `Authorization: Bearer`, never logged.
`TEXTMEFLOW_API_BASE` overrides the API base for local testing.
