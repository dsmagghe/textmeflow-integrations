import logging
import threading

from markupsafe import Markup

from odoo import _, api, fields, models
from odoo.tools import html_escape
from odoo.tools.misc import format_date, formatLang

from .phone import normalize_phone

_logger = logging.getLogger(__name__)

MAX_ATTEMPTS = 3
AUTO_EVENTS = {
    "order_confirmed": "textmeflow.auto_order",
    "delivery_done": "textmeflow.auto_delivery",
    "invoice_posted": "textmeflow.auto_invoice",
}


class TextMeFlowMessage(models.Model):
    """Outgoing message queue and log.

    Sending never happens inside the user's transaction: records are created
    as ``pending`` and a cron job (``_cron_send_pending``) delivers them, so a
    slow or unreachable API can never block confirming an order or posting an
    invoice.
    """

    _name = "textmeflow.message"
    _description = "WhatsApp message (TextMeFlow)"
    _order = "id desc"

    partner_id = fields.Many2one("res.partner", ondelete="set null", index=True)
    phone = fields.Char(string="Phone number")
    body = fields.Text(string="Message", required=True)
    event = fields.Char()
    res_model = fields.Char(string="Document model")
    res_id = fields.Integer(string="Document ID")
    state = fields.Selection(
        [("pending", "Queued"), ("sent", "Sent"), ("failed", "Failed"), ("skipped", "Skipped")],
        default="pending",
        required=True,
        index=True,
    )
    error = fields.Char()
    attempts = fields.Integer(default=0)
    remote_id = fields.Char(string="TextMeFlow ID")
    company_id = fields.Many2one("res.company", default=lambda self: self.env.company)

    # ------------------------------------------------------------------
    # Queueing
    # ------------------------------------------------------------------
    @api.model
    def _trigger_cron(self):
        try:
            self.env.ref("textmeflow.ir_cron_send_pending").sudo()._trigger()
        except Exception:  # noqa: BLE001 - the regular schedule will pick the queue up anyway
            _logger.debug("TextMeFlow: could not trigger the send cron.", exc_info=True)

    @api.model
    def _country_code(self, partner):
        return (partner.country_id or self.env.company.country_id).code

    @api.model
    def _enqueue(self, partner, body, record=None, event=None, phone=None):
        """Create a queued message (consent is checked by the callers)."""
        number = normalize_phone(phone or partner.mobile or partner.phone, self._country_code(partner))
        vals = {
            "partner_id": partner.id,
            "phone": number or (phone or partner.mobile or partner.phone),
            "body": body,
            "event": event,
            "res_model": record._name if record else False,
            "res_id": record.id if record else 0,
        }
        if not number:
            vals.update(state="skipped", error=_("No valid phone number for this contact."))
        message = self.sudo().create(vals)
        if message.state == "skipped":
            message._log_to_chatter()
        else:
            self._trigger_cron()
        return message

    @api.model
    def _auto_queue(self, record, event, partner):
        """Queue an automatic message for ``record``. Never raises."""
        try:
            with self.env.cr.savepoint():
                params = self.env["ir.config_parameter"].sudo()
                if not params.get_param("textmeflow.token") or not params.get_param(AUTO_EVENTS[event]):
                    return False
                if not partner or not partner.textmeflow_optin:
                    return False  # GDPR: no consent, no message
                if self.sudo().search_count(
                    [("res_model", "=", record._name), ("res_id", "=", record.id), ("event", "=", event)]
                ):
                    return False  # one automatic message per document and event
                body = self._compose(event, partner, record)
                if not body:
                    return False
                return self._enqueue(partner, body, record=record, event=event)
        except Exception:  # noqa: BLE001 - messaging must never break an order, delivery or invoice
            _logger.exception("TextMeFlow: could not queue the %s message.", event)
            return False

    # ------------------------------------------------------------------
    # Composition
    # ------------------------------------------------------------------
    @api.model
    def _compose(self, event, partner, record=None, template=None):
        template = template or self.env["textmeflow.template"]._pick(
            event, self.env["textmeflow.template"]._lang_code(partner.lang)
        )
        if not template:
            return ""
        return self.env["textmeflow.template"]._render(template.body, self._values(partner, record))

    @api.model
    def _values(self, partner, record=None):
        """Placeholder values for a recipient and an optional document."""
        company = (record.company_id if record and "company_id" in record._fields else False) or self.env.company
        name = partner.name or ""
        first = name if partner.is_company else (name.split(" ")[0] if name else "")
        values = {
            "first_name": first,
            "name": name,
            "shop_name": company.name or "",
            "order_number": "",
            "order_total": "",
            "invoice_number": "",
            "invoice_total": "",
            "due_date": "",
            "portal_url": "",
            "tracking_url": "",
        }
        if not record:
            return values
        sudo = record.sudo()
        lang_env = sudo.with_context(lang=partner.lang or self.env.lang)
        if record._name == "sale.order":
            values["order_number"] = sudo.name
            values["order_total"] = formatLang(lang_env.env, sudo.amount_total, currency_obj=sudo.currency_id)
            values["portal_url"] = self._portal_url(sudo)
        elif record._name == "account.move":
            values["invoice_number"] = sudo.name or ""
            values["order_number"] = sudo.name or ""
            values["invoice_total"] = formatLang(lang_env.env, sudo.amount_total, currency_obj=sudo.currency_id)
            if sudo.invoice_date_due:
                values["due_date"] = format_date(lang_env.env, sudo.invoice_date_due)
            values["portal_url"] = self._portal_url(sudo)
        elif record._name == "stock.picking":
            order = sudo.sale_id
            values["order_number"] = order.name or sudo.origin or sudo.name
            if order:
                values["portal_url"] = self._portal_url(order)
            # carrier_tracking_url only exists when the Delivery module is installed.
            values["tracking_url"] = getattr(sudo, "carrier_tracking_url", "") or ""
        return values

    @api.model
    def _portal_url(self, record):
        try:
            return record.get_base_url() + record.get_portal_url()
        except Exception:  # noqa: BLE001 - a missing link is better than a missing message
            return ""

    # ------------------------------------------------------------------
    # Sending (cron)
    # ------------------------------------------------------------------
    @api.model
    def _cron_send_pending(self, limit=50):
        client = self.env["textmeflow.api"]
        if not client._token():
            return
        for message in self.search([("state", "=", "pending")], order="id", limit=limit):
            status, data = client._send(message.phone, message.body)
            error = data.get("error") or ""
            if status in (200, 201, 202):
                message.write({"state": "sent", "remote_id": str(data.get("id") or ""), "error": False})
            elif status == 429:
                break  # quota / rate limit: stay queued, retry on the next run
            elif status in (401, 403):
                message.write({"state": "failed", "error": _("TextMeFlow refused the connection. Reconnect in Settings.")})
            elif status in (400, 402, 404, 422):
                message.write({"state": "failed", "error": error or _("Rejected by TextMeFlow.")})
            else:  # unreachable or server error: retry a few times
                attempts = message.attempts + 1
                message.write(
                    {
                        "attempts": attempts,
                        "state": "failed" if attempts >= MAX_ATTEMPTS else "pending",
                        "error": _("TextMeFlow could not be reached."),
                    }
                )
            if message.state in ("sent", "failed"):
                message._log_to_chatter()
            if not getattr(threading.current_thread(), "testing", False):  # same idiom as mail.mail
                self.env.cr.commit()  # keep what is done if a later message raises

    def _log_to_chatter(self):
        """Write the outcome as an internal note on the document or contact."""
        for message in self:
            model = message.res_model or ("res.partner" if message.partner_id else False)
            res_id = message.res_id if message.res_model else message.partner_id.id
            if not model or not res_id or model not in self.env:
                continue
            record = self.env[model].sudo().browse(res_id).exists()
            if not record or not hasattr(record, "message_post"):
                continue
            states = dict(message._fields["state"]._description_selection(self.env))
            body = Markup("<b>WhatsApp (TextMeFlow)</b> - %s %s<br/>%s%s") % (
                html_escape(states.get(message.state, "")),
                html_escape(message.phone or ""),
                html_escape(message.body or ""),
                Markup("<br/><i>%s</i>") % html_escape(message.error) if message.error else Markup(""),
            )
            record.message_post(body=body, message_type="notification", subtype_xmlid="mail.mt_note")

    def action_retry(self):
        self.filtered(lambda m: m.state == "failed").write({"state": "pending", "attempts": 0, "error": False})
        self._trigger_cron()
        return True
