from odoo import models


class StockPicking(models.Model):
    _inherit = "stock.picking"

    def _action_done(self):
        result = super()._action_done()
        messages = self.env["textmeflow.message"]
        for picking in self:
            # Only outgoing deliveries that belong to a sales order, once they are really done.
            if picking.state == "done" and picking.picking_type_code == "outgoing" and picking.sale_id:
                messages._auto_queue(picking, "delivery_done", picking.partner_id or picking.sale_id.partner_id)
        return result
