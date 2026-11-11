from odoo import models


class AccountMove(models.Model):
    _name = "account.move"
    _inherit = ["account.move", "textmeflow.mixin"]

    def _textmeflow_partner(self):
        return self.partner_id

    def _post(self, soft=True):
        was_draft = self.filtered(lambda move: move.state == "draft")
        posted = super()._post(soft=soft)
        messages = self.env["textmeflow.message"]
        for move in posted & was_draft:
            if move.move_type == "out_invoice":
                messages._auto_queue(move, "invoice_posted", move.partner_id)
        return posted
