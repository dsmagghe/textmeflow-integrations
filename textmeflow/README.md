# TextMeFlow WhatsApp for Odoo 17 and 18

Send order confirmations, delivery updates and invoice links to customers from
your own WhatsApp number, via [TextMeFlow](https://textmeflow.eu) (EU-hosted,
free up to 50 messages per month).

## Installation in 3 steps (no technical knowledge needed)

1. **Install the module.** Apps > search "TextMeFlow" > Install. (Self-hosted:
   copy the `textmeflow` folder into your addons path, restart Odoo, Apps >
   Update Apps List > install.) Requires the Sales, Inventory and Invoicing apps.
2. **Connect.** Open TextMeFlow > Settings and press **Connect with TextMeFlow**.
   Create an account or log in on textmeflow.eu and press **Allow**. You come
   back to Odoo, which is now connected.
3. **Link WhatsApp.** Press **Link WhatsApp (QR code)**, scan the code with
   WhatsApp (Settings > Linked devices > Link a device). Press **Send me a test
   message** to check.

Then tick **WhatsApp messages allowed** on the contacts who agreed to receive
messages. Automatic messages (order confirmed, delivery shipped, invoice posted)
are on by default after connecting and can be switched off in Settings.

## What is where

| Feature | Where |
| --- | --- |
| Connect / link / test / disconnect | TextMeFlow > Settings |
| Automatic messages on/off | TextMeFlow > Settings > Automatic messages |
| Edit texts (en, nl, fr, de) | TextMeFlow > Message templates |
| Manual message | "Send via WhatsApp" on a contact, sales order or customer invoice |
| Log of all messages (and retry) | TextMeFlow > Messages, plus an internal note in the document's chatter |
| Consent | Checkbox "WhatsApp messages allowed" on the contact (date stored) |

Placeholders: `{first_name}`, `{name}`, `{order_number}`, `{order_total}`,
`{invoice_number}`, `{invoice_total}`, `{due_date}`, `{shop_name}`,
`{portal_url}`, `{tracking_url}`.

## Developer notes

- Odoo 17 and 18 from one code base. The folder is the 17.0 flavour;
  `integrations/odoo/make-18.sh` builds the 18.0 flavour (version string and
  `<tree>` to `<list>`, the only differences). Views use the direct
  `invisible="..."` syntax (valid since 17). The send cron's `numbercall`
  (Odoo 17 only) is set in the post-init hook.
- **Connect flow** (`docs/connect-contract.md`): `res.config.settings` button
  creates a random `state` bound to the admin user (kept in `ir.config_parameter`,
  valid 30 minutes, one use), redirects to `https://textmeflow.eu/connect`.
  `/textmeflow/connect/callback` (`auth="user"`, admin only) checks the state and
  exchanges the code server-to-server at `POST /v1/connect/token`. The token is
  stored in `ir.config_parameter` (`textmeflow.token`, readable by system admins
  only) and is never sent to the browser.
- **QR screen**: OWL client action `textmeflow_pair_screen` polls the Odoo
  server every 3 s; the server calls the TextMeFlow API. Only the QR image and
  a status word reach the browser. Auto-restarts the pairing up to 3 times.
- **Sending is asynchronous**: records in `textmeflow.message` are created as
  `pending`; `ir.cron` "TextMeFlow: send queued WhatsApp messages" (every 5
  minutes, plus an immediate `_trigger()` after queueing) delivers them. 429
  keeps messages queued, network errors are retried 3 times, 422 fails
  immediately. Outcomes are posted as internal notes on the document.
- **Hooks**: `sale.order._action_confirm`, `stock.picking._action_done`
  (outgoing, linked to a sales order) and `account.move._post` (customer
  invoices). All wrapped in a savepoint and `try/except`: messaging can never
  break an order, delivery or invoice. One automatic message per document and event.
- **Consent**: automatic messages require `res.partner.textmeflow_optin`. The
  manual wizard also refuses without consent (the user can tick it after asking).
- **Templates** are rendered with a plain placeholder replacement, not
  `str.format`, so editable text cannot reach object attributes.
- **No external calls without a user action**: nothing contacts TextMeFlow when a
  settings page is opened; only buttons, the queue cron and the QR screen do.
- **Uninstall**: the token is revoked remotely (best effort) and all
  `textmeflow.*` system parameters are removed.
- Phone numbers are normalised to E.164 (`models/phone.py`) using the contact's
  country, falling back to the company country.

## Tests

```bash
python3 -m py_compile $(find . -name '*.py')
docker run --rm -d --name pg -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres postgres:16-alpine
docker run --rm --link pg:db -v "$PWD":/mnt/extra-addons/textmeflow odoo:17.0 \
  odoo -d t17 -i textmeflow --stop-after-init --without-demo=all \
  --db_host=db --db_user=odoo --db_password=odoo
```

Unit tests run with `--test-tags /textmeflow` (see `tests/`); they pass on
odoo:17.0 and on odoo:18.0 (via `make-18.sh`).

License: LGPL-3.
