# n8n-nodes-textmeflow

n8n community nodes for [TextMeFlow](https://textmeflow.eu): send WhatsApp messages from your own number and start workflows when someone replies. EU-hosted, free up to 50 messages per month.

- **TextMeFlow** node: Send a message (to, text, optional media URL), Get message status.
- **TextMeFlow Trigger** node: starts a workflow on every incoming WhatsApp message, with signature check.
- Built-in phone number clean-up to international format, with an optional default country.

## Install in 3 steps (no technical knowledge needed)

1. **Install the node.** In n8n: Settings, Community nodes, Install, type `n8n-nodes-textmeflow`, confirm. (On n8n Cloud, once verified, search for "TextMeFlow" in the node panel and add it.)
2. **Add your TextMeFlow key.** Open a TextMeFlow node, click "Create new credential". Then:
   1. Log in at <https://textmeflow.eu/login> (no password: you get a link by email) or create a free account at <https://textmeflow.eu/signup>.
   2. Open <https://textmeflow.eu/admin/portal>. Your **API key** is shown there. Copy it into the credential.
   3. On the same page, scan the QR code with WhatsApp (Settings, Linked devices) so messages go out from your own number.
3. **Use it.** Add the TextMeFlow node, fill in a phone number and a message, and run it.

n8n does not support a "Connect" button for community nodes, so the API key has to be pasted once. It is the key of your own account.

## Sending a message

| Field | Meaning |
|---|---|
| To | Phone number, best as `+32470123456`. `0032...` and spaces/dashes also work. |
| Message | The text. |
| Default Country (optional) | Turns a national number like `0470 12 34 56` into `+32470123456`. Ignored when the number already starts with `+` or `00`. |
| Media URL (optional) | Public https link to an image or file. |

The node returns `message_id` and `status`. Pass `message_id` to **Get Status** later to see `queued`, `sent`, `delivered`, `read` or `failed`.

Only message people who agreed to receive WhatsApp messages from you (GDPR). Rate limiting and quota are handled by TextMeFlow; when the monthly quota is exceeded the node fails with the API error (use "Continue on fail" if you want the workflow to go on).

## Receiving messages (TextMeFlow Trigger)

TextMeFlow cannot (yet) register a webhook URL through its API, so this is a one-time copy and paste:

1. Add the **TextMeFlow Trigger** node and copy its **Webhook URL**. Use the *Test URL* while you press "Listen for test event", and the *Production URL* once the workflow is active.
2. Open <https://textmeflow.eu/admin/webhook>, paste the URL and click Save.
3. TextMeFlow now shows a **secret**. Copy it into the **Webhook Secret** field of your TextMeFlow API credential.
4. Click "Test fire" in the portal to send a sample message to the workflow.

The node verifies the `X-TextMeFlow-Signature` header (HMAC-SHA256 over the raw body) in constant time and answers 401 when it does not match or when no secret is set. Retries from TextMeFlow carry the same event id and are ignored (can be switched off). Your own sent messages are ignored by default so a reply workflow cannot trigger itself.

Output fields: `type`, `from`, `from_name`, `to`, `file`, `message`, `from_me`, `wa_message_id`, `message_id`. See <https://textmeflow.eu/docs/webhooks>.

## Privacy and external service

Phone numbers and message text you send are processed by TextMeFlow (EU-hosted, operated by Fades Management EOOD, Bulgaria). See the [privacy policy](https://textmeflow.eu/legal/privacy) and [terms](https://textmeflow.eu/legal/terms). The nodes only call `https://api.textmeflow.eu` when a workflow runs.

## Developer notes

```bash
npm install
npm run build      # n8n-node build
npm run lint       # n8n-node lint (includes @n8n/eslint-plugin-community-nodes)
npm run dev        # starts n8n with the nodes loaded, with hot reload
```

- Programmatic-style nodes, no runtime dependencies (only `crypto` from Node.js for the signature check), as required for verified community nodes.
- `nodes/TextMeFlow/GenericFunctions.ts` holds the E.164 normalisation and the country table (EU/EEA, UK, CH, US).
- Credential test calls `GET /v1/accounts/me`.
- The Trigger's `webhookMethods` are deliberate no-ops: registering the URL happens in the portal.

## License

MIT
