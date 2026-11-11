import re

from odoo import api, fields, models

PLACEHOLDER = re.compile(r"\{(\w+)\}")

EVENTS = [
    ("manual", "Message to a contact"),
    ("order", "Quotation / order link"),
    ("order_confirmed", "Order confirmed (automatic)"),
    ("delivery_done", "Delivery shipped (automatic)"),
    ("invoice", "Invoice link"),
    ("invoice_posted", "Invoice posted (automatic)"),
]
LANGS = [("en", "English"), ("nl", "Nederlands"), ("fr", "Français"), ("de", "Deutsch")]


class TextMeFlowTemplate(models.Model):
    _name = "textmeflow.template"
    _description = "WhatsApp message template"
    _order = "event, lang"

    name = fields.Char(compute="_compute_name", store=True)
    event = fields.Selection(EVENTS, required=True)
    lang = fields.Selection(LANGS, string="Language", required=True, default="en")
    body = fields.Text(
        string="Message",
        required=True,
        help="Placeholders: {first_name}, {name}, {order_number}, {order_total}, "
        "{invoice_number}, {invoice_total}, {due_date}, {shop_name}, {portal_url}, {tracking_url}.",
    )
    active = fields.Boolean(default=True)

    _sql_constraints = [
        ("event_lang_uniq", "unique(event, lang)", "There is already a template for this event and language."),
    ]

    @api.depends("event", "lang")
    def _compute_name(self):
        events = dict(self._fields["event"]._description_selection(self.env))
        for rec in self:
            rec.name = "%s (%s)" % (events.get(rec.event, ""), (rec.lang or "").upper())

    @api.model
    def _render(self, body, values):
        """Replace ``{placeholder}`` tokens. Unknown tokens become empty.

        Deliberately not ``str.format``: template text is editable by users and
        format strings can reach object attributes.
        """
        text = PLACEHOLDER.sub(lambda m: str(values.get(m.group(1), "") or ""), body or "")
        return re.sub(r"[ \t]+\n", "\n", text).strip()

    @api.model
    def _pick(self, event, lang):
        """Active template for ``event`` in ``lang`` with English as fallback."""
        domain = [("event", "=", event)]
        for code in (lang, "en"):
            tpl = self.search(domain + [("lang", "=", code)], limit=1)
            if tpl:
                return tpl
        return self.browse()

    @api.model
    def _lang_code(self, lang):
        """'nl_BE' -> 'nl', unsupported -> 'en'."""
        code = (lang or "").split("_")[0].lower()
        return code if code in dict(LANGS) else "en"
