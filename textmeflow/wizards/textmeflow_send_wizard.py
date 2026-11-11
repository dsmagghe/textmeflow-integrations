from odoo import _, api, fields, models
from odoo.exceptions import UserError

from ..models.phone import normalize_phone

EVENTS_PER_MODEL = {
    "res.partner": ["manual"],
    "sale.order": ["order"],
    "account.move": ["invoice"],
}


class TextMeFlowSendWizard(models.TransientModel):
    _name = "textmeflow.send.wizard"
    _description = "Send a WhatsApp message"

    res_model = fields.Char(required=True)
    res_id = fields.Integer(required=True)
    partner_id = fields.Many2one("res.partner", string="Recipient", required=True)
    optin = fields.Boolean(
        string="WhatsApp messages allowed",
        related="partner_id.textmeflow_optin",
        readonly=False,
        help="Tick this only when the contact agreed to receive WhatsApp messages.",
    )
    phone = fields.Char(string="Phone number", compute="_compute_phone", store=True, readonly=False)
    allowed_template_ids = fields.Many2many("textmeflow.template", compute="_compute_allowed_template_ids")
    template_id = fields.Many2one(
        "textmeflow.template",
        string="Template",
        compute="_compute_template_id",
        store=True,
        readonly=False,
    )
    body = fields.Text(string="Message", compute="_compute_body", store=True, readonly=False)

    @api.model
    def _open_for(self, record):
        partner = record._textmeflow_partner()
        if not partner:
            raise UserError(_("This document has no customer to message."))
        wizard = self.create({"res_model": record._name, "res_id": record.id, "partner_id": partner.id or False})
        return {
            "type": "ir.actions.act_window",
            "name": _("Send via WhatsApp"),
            "res_model": self._name,
            "res_id": wizard.id,
            "view_mode": "form",
            "target": "new",
        }

    def _record(self):
        self.ensure_one()
        return self.env[self.res_model].browse(self.res_id).exists()

    @api.depends("partner_id")
    def _compute_phone(self):
        for wiz in self:
            partner = wiz.partner_id
            country = (partner.country_id or self.env.company.country_id).code
            raw = partner.mobile or partner.phone
            wiz.phone = normalize_phone(raw, country) or raw or False

    @api.depends("res_model")
    def _compute_allowed_template_ids(self):
        for wiz in self:
            events = EVENTS_PER_MODEL.get(wiz.res_model, ["manual"])
            wiz.allowed_template_ids = self.env["textmeflow.template"].search([("event", "in", events)])

    @api.depends("partner_id", "allowed_template_ids")
    def _compute_template_id(self):
        templates = self.env["textmeflow.template"]
        for wiz in self:
            lang = templates._lang_code(wiz.partner_id.lang)
            allowed = wiz.allowed_template_ids
            wiz.template_id = allowed.filtered(lambda t: t.lang == lang)[:1] or allowed.filtered(
                lambda t: t.lang == "en"
            )[:1]

    @api.depends("template_id", "partner_id")
    def _compute_body(self):
        messages = self.env["textmeflow.message"]
        for wiz in self:
            if wiz.template_id:
                wiz.body = messages._compose(
                    wiz.template_id.event, wiz.partner_id, wiz._record(), template=wiz.template_id
                )
            else:
                wiz.body = wiz.body or False

    def action_send(self):
        """Queue the message. The cron job delivers it so the screen never waits."""
        self.ensure_one()
        if not self.env["textmeflow.api"]._token():
            raise UserError(_("TextMeFlow is not connected yet. Go to Settings > TextMeFlow and press 'Connect'."))
        if not self.partner_id.textmeflow_optin:
            raise UserError(_("This contact has not agreed to receive WhatsApp messages. Ask for consent and tick the box."))
        country = (self.partner_id.country_id or self.env.company.country_id).code
        if not normalize_phone(self.phone, country):
            raise UserError(_("Enter a valid phone number, for example +32 470 12 34 56."))
        if not (self.body or "").strip():
            raise UserError(_("The message is empty."))
        self.env["textmeflow.message"]._enqueue(
            self.partner_id,
            self.body.strip(),
            record=self._record() or None,
            event="manual",
            phone=self.phone,
        )
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {
                "title": "TextMeFlow",
                "message": _("The message is queued and will be sent in a moment."),
                "type": "success",
                "next": {"type": "ir.actions.act_window_close"},
            },
        }
