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
| Trigger | New Incoming Message (REST hook) | implemented, **hidden** until the server has webhook registration |

### Why the trigger is hidden
The API today has one `webhook_url` per account, configured in the portal. There is no endpoint to register a webhook per Zap, and no endpoint to list inbound messages, so neither a REST hook nor polling can work. The trigger code expects:

```
POST   /v1/webhooks        { "url": "<zapier target url>", "event": "message.inbound" } -> { "id": "..." }
DELETE /v1/webhooks/{id}
```
delivering the existing InboundMessage payload (`X-TextMeFlow-Event-Id` header is used for dedupe). When that exists: remove `hidden: true` in `src/triggers/new_incoming_message.js`, add a live test Zap, push a new version. Workaround for users today: "Webhooks by Zapier > Catch Hook" with its URL pasted into the TextMeFlow portal webhook page.

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
