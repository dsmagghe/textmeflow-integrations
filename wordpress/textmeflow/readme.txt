=== TextMeFlow - WhatsApp notifications for WooCommerce & WordPress forms ===
Contributors: textmeflow
Tags: whatsapp, woocommerce, notifications, contact form 7, order notifications
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WhatsApp messages for WooCommerce orders and form submissions from your own WhatsApp number. Connect in one click, no API keys to copy.

== Description ==

TextMeFlow sends WhatsApp messages from **your own WhatsApp number**. This plugin connects your WordPress site to TextMeFlow with one button: no API key to copy or paste.

**How it works (3 steps)**

1. Install the plugin and click **Connect to TextMeFlow**.
2. Log in or create a free TextMeFlow account and approve the connection. You return to your site automatically.
3. Scan the QR code shown in your WordPress admin with WhatsApp (Settings, Linked devices). Done: ready-made messages in your site's language are already switched on.

**WooCommerce**

* Message your customers when an order is processing, completed, on hold, cancelled, refunded or failed. Each message can be switched on or off and the text can be edited with placeholders such as {first_name}, {order_number}, {order_total}, {shop_name} and {tracking_url}.
* Ready-made texts in English, Dutch, French and German, chosen from your site language.
* Opt-in checkbox at checkout (classic checkout and the Checkout block). Customers only receive messages if they tick it. Not ticked by default.
* Optional alert to yourself for every new order.
* Messages are sent in the background (Action Scheduler), so checkout never waits. The result is added as an order note.
* Phone numbers are converted to the international format using the billing country.
* Compatible with High-Performance Order Storage (HPOS) and the Cart and Checkout blocks.

**Forms**

Works with Contact Form 7, WPForms, Elementor Forms (Pro) and Gravity Forms, each only when installed and active:

* Alert to you for every new submission, with a summary of the fields.
* Optional confirmation to the visitor, only if the form has a phone field and a WhatsApp consent checkbox that the visitor ticked.

**Good to know**

* TextMeFlow is free for up to 50 messages per month. Bigger volumes need a paid plan.
* The WhatsApp number you link is a normal WhatsApp account. Only send messages people have agreed to receive and follow WhatsApp's terms of use.
* The plugin never shows or stores your TextMeFlow connection token in the browser. It is stored encrypted in your database and can be revoked any time from the plugin or from your TextMeFlow account.

== External services ==

This plugin connects to the **TextMeFlow** service (https://textmeflow.eu) to send the WhatsApp messages you configure. TextMeFlow is operated by Fades Management EOOD (Bulgaria) and hosted in the European Union. Without a TextMeFlow account the plugin does nothing.

**What is sent and when**

* When you click "Connect to TextMeFlow", your browser is sent to https://textmeflow.eu/connect with your site address, the site's return URL and a random security value. After you approve, your site exchanges a one-time code for a connection token at https://api.textmeflow.eu/v1/connect/token.
* While the settings page is open: your site asks https://api.textmeflow.eu/v1/accounts/me for the connection status and linked WhatsApp number, and requests the QR code used to link your WhatsApp (https://api.textmeflow.eu/v1/accounts/me/pair), together with your site name as a label.
* When a message is sent (an order changes status, a form is submitted, or you press the test button): the recipient's phone number and the message text (which may contain the customer's first name, order number and total, or the submitted form fields) are sent to https://api.textmeflow.eu/v1/messages.
* When you click "Disconnect" or delete the plugin: a request to https://api.textmeflow.eu/v1/connect/token revokes the connection token.

No data is sent before you connect, and no tracking or advertising scripts are loaded.

Terms of use: https://textmeflow.eu/legal/terms
Privacy policy: https://textmeflow.eu/legal/privacy

== Installation ==

1. Install the plugin from the Plugins screen in WordPress (Plugins, Add New) and activate it.
2. Open **TextMeFlow** in the admin menu and click **Connect to TextMeFlow**. Log in or create a free account and approve the connection.
3. Scan the QR code with WhatsApp (Settings, Linked devices, Link a device). Enter your own number, press **Send me a test message** and save.

== Frequently Asked Questions ==

= Do I need an API key? =

No. The connect button handles everything. You never copy or paste a key.

= Does it cost anything? =

TextMeFlow is free for up to 50 messages per month. See https://textmeflow.eu/pricing for paid plans.

= Which number do the messages come from? =

From the WhatsApp number you link by scanning the QR code, so customers see your own number.

= Do customers have to agree? =

Yes. By default WooCommerce customers see an unticked checkbox at checkout and only receive messages if they tick it. For forms, the visitor is only messaged if the form has a phone field and a ticked consent checkbox. Alerts to yourself do not need consent.

= How do I make a consent checkbox in my form? =

Add a checkbox or acceptance field and give it a name or label that contains "whatsapp" (for example "whatsapp-consent"). Add a phone field as well.

= Are my customers' phone numbers stored by TextMeFlow? =

Numbers and message texts are sent to TextMeFlow to deliver the message. See the privacy policy for retention: https://textmeflow.eu/legal/privacy

= What happens to the data when I delete the plugin? =

The connection is revoked and all plugin settings are removed from your database. Order notes and the opt-in value stored on orders are kept, because they belong to your orders.

= Does it work with the new WooCommerce Checkout block? =

Yes, on WooCommerce 8.6 and newer the opt-in appears as an extra checkbox in the Checkout block. The classic checkout is supported as well.

== Screenshots ==

1. Connect with one button.
2. Scan the QR code in your WordPress admin.
3. Connected: status and test message.
4. WooCommerce messages with editable texts.
5. Opt-in checkbox at checkout.
6. Form alerts and confirmations.

== Changelog ==

= 1.0.1 =
* Sites in Belgian Dutch/French, Swiss or Austrian German now show the plugin in Dutch, French or German instead of English.
* The "Get a new code" and "Upgrade your plan" buttons only appear when they apply.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
Shows the plugin in your language on regional sites (nl_BE, fr_BE, de_AT, ...) and hides buttons that do not apply.

= 1.0.0 =
First release.
