from odoo import models


class SaleOrder(models.Model):
    _name = "sale.order"
    _inherit = ["sale.order", "textmeflow.mixin"]

    def _textmeflow_partner(self):
        return self.partner_id

    def _action_confirm(self):
        result = super()._action_confirm()
        messages = self.env["textmeflow.message"]
        for order in self:
            messages._auto_queue(order, "order_confirmed", order.partner_id)
        return result
