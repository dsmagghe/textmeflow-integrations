# TextMeFlow for Make

Custom app definition in the layout used by the Make Apps SDK VS Code extension (`makecomapp.json`, `general/`, `connections/`, `modules/`, `webhooks/`, IML JSON files).

**Status: not tested inside Make.** There is no way to validate IML locally without a Make account; the JSON files are syntax-checked only. Expect small corrections on first import (the exact `makecomapp.json` keys differ slightly between extension versions). Fallback: create the app in the Make web builder and paste each `*.iml.json` into the matching editor tab (Base, Connection, Module Communication / Parameters / Interface).

## For the user
1. Add a "TextMeFlow" module (Send a WhatsApp Message) to a scenario.
2. Create a connection and paste your API key (textmeflow.eu portal > API key; link your WhatsApp number there by QR first).
3. Fill phone number (with country code), message, optional image URL. Run once.

## Modules
- Send a WhatsApp Message: `POST /messages` (`to`, `text`, `media_url`), returns `id`, `status`.
- Get a Message: `GET /messages/{id}`.
- Watch Incoming Messages (instant trigger, dedicated webhook of type `web`): when the scenario is activated Make calls `POST /webhooks` with its own webhook address (`attach`, body `{url, event: "message.inbound"}`; the returned `id` is kept as `webhook.data.id`) and on deactivation or deletion `DELETE /webhooks/{id}` (`detach`). The user pastes nothing. The webhook uses the TextMeFlow connection. The `X-TextMeFlow-Signature` header cannot be verified inside Make, so the secret returned by the API is not used. Unverified inside Make: the exact IML keys for `attach.response` and `{{webhook.data.id}}` may need a small correction on first import.

## Import (developer)
1. Install the "Make Apps Editor" VS Code extension, set your Make API key/zone.
2. Open this folder, run "Deploy to Make" from the extension (or "Import" into a new app via its context menu), or use the web builder as in the fallback above.
3. Check `base.iml.json`: base URL `https://api.textmeflow.eu/v1`, header `Authorization: Bearer {{connection.apiKey}}`.
4. Test each module on a scenario; then see SUBMIT.md.
