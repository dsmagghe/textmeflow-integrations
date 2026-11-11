{
    "name": "TextMeFlow WhatsApp",
    "version": "17.0.1.0.0",
    "category": "Marketing",
    "summary": "Send WhatsApp messages from quotes, deliveries and invoices with your own number.",
    "description": """
TextMeFlow WhatsApp
===================

Connect your own WhatsApp number through TextMeFlow (EU-hosted) in three clicks,
then send order confirmations, delivery updates and invoice links to customers
who agreed to receive them. Works with your own number: scan one QR code.

Data (phone number and message text) is sent to TextMeFlow (Fades Management EOOD,
Bulgaria, EU-hosted). See https://textmeflow.eu/legal/privacy.
""",
    "author": "TextMeFlow (Fades Management EOOD)",
    "website": "https://textmeflow.eu",
    "license": "LGPL-3",
    "depends": ["base", "mail", "portal", "account", "sale", "sale_stock"],
    "external_dependencies": {"python": ["requests"]},
    "data": [
        "security/textmeflow_security.xml",
        "security/ir.model.access.csv",
        "data/textmeflow_template_data.xml",
        "data/ir_cron_data.xml",
        "views/res_config_settings_views.xml",
        "views/textmeflow_template_views.xml",
        "views/textmeflow_message_views.xml",
        "views/res_partner_views.xml",
        "views/sale_order_views.xml",
        "views/account_move_views.xml",
        "wizards/textmeflow_send_wizard_views.xml",
        "views/menus.xml",
    ],
    "assets": {
        "web.assets_backend": [
            "textmeflow/static/src/js/pair_screen.js",
            "textmeflow/static/src/xml/pair_screen.xml",
        ],
    },
    "images": ["static/description/banner.png"],
    "post_init_hook": "post_init_hook",
    "uninstall_hook": "uninstall_hook",
    "application": True,
    "installable": True,
}
