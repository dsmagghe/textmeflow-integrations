# TextMeFlow integrations

Official plugins and apps that connect popular software to
[TextMeFlow](https://textmeflow.eu) — the WhatsApp messaging API that works
with your own number (link it once with a QR code), hosted in the EU.

Most plugins connect in three steps, without copying API keys:

1. Install the plugin.
2. Click **Connect with TextMeFlow** and approve on textmeflow.eu.
3. Scan the QR code with WhatsApp. Ready-made messages in English, Dutch,
   French and German are switched on.

| Folder | Platform | Download / info |
|---|---|---|
| [`wordpress/`](wordpress/) | WordPress + WooCommerce, Contact Form 7, WPForms, Elementor, Gravity Forms | [textmeflow.eu/integrations/woocommerce](https://textmeflow.eu/en/integrations/woocommerce) |
| [`prestashop/`](prestashop/) | PrestaShop 8 and 9 | [textmeflow.eu/integrations/prestashop](https://textmeflow.eu/en/integrations/prestashop) |
| [`shopware/`](shopware/) | Shopware 6.5 / 6.6 | [textmeflow.eu/integrations/shopware](https://textmeflow.eu/en/integrations/shopware) |
| [`odoo/`](odoo/) | Odoo 17 and 18 | [textmeflow.eu/integrations/odoo](https://textmeflow.eu/en/integrations/odoo) |
| [`n8n/`](n8n/) | n8n community node | [textmeflow.eu/integrations/n8n](https://textmeflow.eu/en/integrations/n8n) |
| [`zapier/`](zapier/) | Zapier app | [textmeflow.eu/integrations/zapier](https://textmeflow.eu/en/integrations/zapier) |
| [`make/`](make/) | Make.com custom app | [textmeflow.eu/integrations/make](https://textmeflow.eu/en/integrations/make) |
| [`shopify/`](shopify/) | Shopify (hosted app by TextMeFlow — app config and docs) | [textmeflow.eu/integrations/shopify](https://textmeflow.eu/en/integrations/shopify) |
| [`teamleader/`](teamleader/) | Teamleader Focus (hosted by TextMeFlow — docs only) | [textmeflow.eu/integrations/teamleader](https://textmeflow.eu/en/integrations/teamleader) |

Everything else talks to the plain REST API: one `POST /v1/messages` call.
See the [API reference](https://textmeflow.eu/en/docs/api).

## Privacy

The plugins send the recipient's phone number and the message text to
TextMeFlow (EU-hosted, operated by Fades Management EOOD, Bulgaria), only
for customers who opted in. See the [privacy policy](https://textmeflow.eu/en/legal/privacy)
and [terms](https://textmeflow.eu/en/legal/terms).

## Support and contributions

Questions, bugs or an integration you would like to see: open an issue here or
email info@fades.tech.

This repository is published automatically from the TextMeFlow codebase; pull
requests are welcome and will be applied there.

## License

Each folder carries the license its marketplace requires — see [LICENSE.md](LICENSE.md).
