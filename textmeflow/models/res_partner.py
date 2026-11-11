from odoo import _, api, fields, models


class TextMeFlowMixin(models.AbstractModel):
    """Adds the 'Send via WhatsApp' button logic to a document model."""

    _name = "textmeflow.mixin"
    _description = "TextMeFlow send button"

    def _textmeflow_partner(self):
        """Recipient of the message. Overridden per model."""
        return self.env["res.partner"]

    def action_textmeflow_send(self):
        self.ensure_one()
        return self.env["textmeflow.send.wizard"]._open_for(self)


class ResPartner(models.Model):
    _name = "res.partner"
    _inherit = ["res.partner", "textmeflow.mixin"]

    textmeflow_optin = fields.Boolean(
        string="WhatsApp messages allowed",
        tracking=True,
        copy=False,
        help="Tick only when this person agreed to receive WhatsApp messages. "
        "Without this consent no automatic message is sent.",
    )
    textmeflow_optin_date = fields.Datetime(string="WhatsApp consent given on", copy=False, readonly=True)

    def _textmeflow_partner(self):
        return self

    @api.model_create_multi
    def create(self, vals_list):
        for vals in vals_list:
            if vals.get("textmeflow_optin"):
                vals["textmeflow_optin_date"] = fields.Datetime.now()
        return super().create(vals_list)

    def write(self, vals):
        if "textmeflow_optin" in vals:
            vals = dict(vals, textmeflow_optin_date=fields.Datetime.now() if vals["textmeflow_optin"] else False)
        return super().write(vals)
