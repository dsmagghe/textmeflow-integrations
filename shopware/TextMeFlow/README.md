# TextMeFlow for Shopware 6

Send WhatsApp order updates to your customers from Shopware 6.5 / 6.6 through [TextMeFlow](https://textmeflow.eu), an EU-hosted WhatsApp messaging service. Free for up to 50 messages a month.

Technical name: `TextMeFlow` - composer package `textmeflow/shopware-textmeflow`.

## Install in 3 steps (for shop owners)

1. **Install and activate** the plugin: Shopware Administration > Extensions > My extensions > TextMeFlow > Install, then switch it on. (From the Shopware Store: search for "TextMeFlow".)
2. **Connect**: Settings > Extensions > TextMeFlow > click **Connect with TextMeFlow**. Sign in (or create a free account) on textmeflow.eu and click **Allow**. You return to your shop automatically. You never copy or paste a key.
3. **Scan the QR code** shown in the same screen with WhatsApp on your phone (Settings > Linked devices > Link a device). Done: order confirmation and shipping messages are on by default.

Then use **Send me a test message** to check it, and **Edit messages** to switch messages on or off and change the texts.

Customers only get messages when they tick the (not pre-checked) WhatsApp checkbox on the checkout confirm page.

## What it does

| Event | Default | Shopware trigger |
|---|---|---|
| Order placed | on | `CheckoutOrderPlacedEvent` |
| Payment received | off | `state_enter.order_transaction.state.paid` |
| Order in progress | off | `state_enter.order.state.in_progress` |
| Order shipped | on | `state_enter.order_delivery.state.shipped` |
| Order completed | off | `state_enter.order.state.completed` |
| Order cancelled | off | `state_enter.order.state.cancelled` |
| New order to the shop owner | off | `CheckoutOrderPlacedEvent` (no customer consent needed; owner number in the plugin settings) |

* Default texts exist in nl, en, fr and de and are chosen by the **language of the order**. A text typed in the plugin settings replaces the default for all languages of that event.
* Placeholders: `{first_name}` `{last_name}` `{order_number}` `{order_total}` `{shop_name}` `{tracking_url}` `{tracking_code}`. A line that only holds the tracking link is dropped when the parcel has none.
* Plugin settings can be set per sales channel (standard Shopware inheritance).
* The phone number comes from the delivery address, else the billing address, and is converted to international format using the country of that address.
* Every message is sent through the Shopware **message queue** (`AsyncMessageInterface`), never during checkout. Make sure a queue worker runs (`bin/console messenger:consume async`, or the admin worker). A message is sent at most once per order and event (flag stored on the order); the last permanent error is stored in the order custom field `tmf_last_error` and logged. Rate limits and outages are retried by the queue.

## Privacy and external service

On connect, the admin's browser goes to `https://textmeflow.eu/connect`. Afterwards, the shop server talks to `https://api.textmeflow.eu/v1` (connect token exchange, status, QR pairing, messages). There are **no calls on page load or in the storefront**; calls only happen after an admin action or for a queued message. Data sent: recipient phone number and message text (plus the shop name when pairing). TextMeFlow is operated by Fades Management EOOD, Bulgaria, hosted in the EU. See https://textmeflow.eu/legal/privacy and https://textmeflow.eu/legal/terms.

The connection token is stored encrypted (libsodium secretbox, key derived from `APP_SECRET`) in `system_config` and is never sent to the browser: the admin module talks to this plugin's own admin-API routes (`/api/_action/textmeflow/...`) which proxy the calls. On uninstall (without "keep user data") the token is revoked at TextMeFlow and all plugin config and custom fields are removed.

## Developer notes

```
src/
  TextMeFlow.php                 plugin class: custom field set (order), uninstall clean-up
  EventType.php                  events, config keys
  Controller/ConnectController   admin-API routes: status, connect/start, connect/callback, pair, test, disconnect
  Service/Settings               system config + encrypted token + single-use connect state
  Service/ConnectionService      connect flow, status, QR pairing, test, disconnect
  Service/TextMeFlowClient       API client (Symfony HttpClient)
  Service/OrderMessageFactory    consent check, phone, text for one order/event
  Service/PhoneNormalizer        E.164 conversion (EU + GB/US/CH/NO/IS/LI)
  Service/MessageBuilder         placeholders, amount formatting
  Service/DefaultTexts           default texts nl/en/fr/de
  Subscriber/OrderEventSubscriber    order events -> queue
  Subscriber/CheckoutOptInSubscriber opt-in checkbox + storing it on the order
  Message/SendMessage(+Handler)  queue message
  Resources/config               services.xml, routes.xml, config.xml, plugin.png
  Resources/app/administration   Vue module (Settings > Extensions > TextMeFlow), snippets de/en/nl/fr
  Resources/snippet              storefront snippets de/en/nl/fr
  Resources/views                checkout checkbox (extends page/checkout/confirm/index.html.twig)
```

* The callback route `GET /api/_action/textmeflow/connect/callback` is public (`auth_required: false`), because a browser redirect carries no admin token. It is protected by the random single-use `state` (16 random bytes, 10 minutes) saved when the admin started the flow; the code is exchanged server-to-server and bound to the same `return` URL. The `return` URL must be https (http only for localhost).
* All other routes need the admin ACL `system_config:read` / `system_config:update`.
* Opt-in: a checkbox `tmfWhatsappOptin` (form attribute `confirmOrderForm`) is stored by `CartConvertedEvent` in the order custom field `tmf_whatsapp_optin`. It is only shown when the shop is connected and a customer message is on.

### Build the administration assets

Shopware plugins ship their compiled administration JS in `src/Resources/public/administration/`. These are generated, not committed here:

```bash
# inside a Shopware 6.5/6.6 development installation, plugin placed in custom/plugins/TextMeFlow
bin/console plugin:refresh && bin/console plugin:install --activate TextMeFlow
bin/build-administration.sh          # or: shopware-cli extension build custom/plugins/TextMeFlow
bin/console assets:install
```

### Tests

The unit tests cover the Shopware-independent logic (phone normalisation, message building, default texts, API client with a mocked HTTP client, token encryption). They run without a Shopware installation:

```bash
composer require --dev phpunit/phpunit symfony/http-client --ignore-platform-reqs   # or use a scratch project
vendor/bin/phpunit
```

Also checked: `php -l` on all PHP, `composer validate`, `config.xml` against Shopware's `config.xsd`, JSON snippets, and PHPStan level 6 against shopware/core + storefront 6.6.

### Not covered by automatic tests

Anything that needs a running Shopware (checkout form, state-machine events, queue worker, admin module UI) was written against the 6.6 source but still needs a manual test run in a real installation. See `SUBMIT.md` for the pre-submission test list.
