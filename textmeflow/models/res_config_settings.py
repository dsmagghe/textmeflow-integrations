import hmac
import secrets
from urllib.parse import urlencode

from odoo import _, api, fields, models
from odoo.exceptions import AccessError, UserError

CONNECT_URL = "https://textmeflow.eu/connect"
STATE_TTL_SECONDS = 30 * 60


class TextMeFlowPair(models.AbstractModel):
    """Server side of the QR screen (``pair_screen.js``).

    The browser only ever receives the QR picture and a status word. The
    TextMeFlow token stays on the server.
    """

    _name = "textmeflow.pair"
    _description = "TextMeFlow QR pairing"

    @api.model
    def _check_admin(self):
        if not self.env.user.has_group("base.group_system"):
            raise AccessError(_("Only administrators can link WhatsApp."))

    @api.model
    def _answer(self, status, data=None, pair_id=None, error=None):
        data = data or {}
        return {
            "status": status,
            "pair_id": pair_id or data.get("pair_id"),
            "qr": data.get("qr_png_b64") or False,
            "error": error or False,
            "upgrade_url": "https://textmeflow.eu/pricing" if error == "device_limit_reached" else False,
        }

    @api.model
    def start_pair(self):
        """Start a new QR session (contract section 4)."""
        self._check_admin()
        client = self.env["textmeflow.api"]
        if not client._token():
            return self._answer("error", error="not_connected")
        label = self.env.company.name or "Odoo"
        status, data = client._request("POST", "/accounts/me/pair", {"label": label})
        if status in (402, 409) or data.get("error") == "device_limit_reached":
            return self._answer("error", error="device_limit_reached")
        if status not in (200, 201, 202):
            return self._answer("error", error="unreachable" if status == 0 else "failed")
        return self._answer(data.get("status") or "pending", data)

    @api.model
    def poll_pair(self, pair_id):
        """Poll an existing QR session; remembers the phone number once connected."""
        self._check_admin()
        client = self.env["textmeflow.api"]
        if not isinstance(pair_id, str) or not pair_id.replace("-", "").replace("_", "").isalnum():
            return self._answer("error", error="failed")
        status, data = client._request("GET", "/accounts/me/pair/%s" % pair_id)
        if status != 200:
            return self._answer("pending", pair_id=pair_id)  # transient problem: keep polling
        if data.get("status") == "connected":
            code, me = client._me()
            phone = client._connected_phone(me) if code == 200 else False
            self.env["ir.config_parameter"].sudo().set_param(
                "textmeflow.phone", phone if isinstance(phone, str) else ""
            )
        return self._answer(data.get("status") or "pending", data, pair_id=pair_id)


class ResConfigSettings(models.TransientModel):
    _inherit = "res.config.settings"

    textmeflow_connected = fields.Boolean(compute="_compute_textmeflow_state")
    textmeflow_account_email = fields.Char(compute="_compute_textmeflow_state")
    textmeflow_phone = fields.Char(compute="_compute_textmeflow_state")
    textmeflow_auto_order = fields.Boolean(
        string="Order confirmed",
        config_parameter="textmeflow.auto_order",
        help="Message the customer when a quotation is confirmed. Only customers who agreed to WhatsApp messages.",
    )
    textmeflow_auto_delivery = fields.Boolean(
        string="Delivery shipped",
        config_parameter="textmeflow.auto_delivery",
        help="Message the customer when a delivery is done. Only customers who agreed to WhatsApp messages.",
    )
    textmeflow_auto_invoice = fields.Boolean(
        string="Invoice posted",
        config_parameter="textmeflow.auto_invoice",
        help="Message the customer when an invoice is posted. Only customers who agreed to WhatsApp messages.",
    )

    @api.depends_context("company")
    def _compute_textmeflow_state(self):
        params = self.env["ir.config_parameter"].sudo()
        connected = bool(params.get_param("textmeflow.token"))
        for rec in self:
            rec.textmeflow_connected = connected
            rec.textmeflow_account_email = params.get_param("textmeflow.account_email") or False
            rec.textmeflow_phone = params.get_param("textmeflow.phone") or False

    def _textmeflow_check_admin(self):
        if not self.env.user.has_group("base.group_system"):
            raise AccessError(_("Only administrators can change the TextMeFlow connection."))

    @staticmethod
    def _textmeflow_notify(message, kind="success", sticky=False):
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {"title": "TextMeFlow", "message": message, "type": kind, "sticky": sticky},
        }

    # -- Connect (contract section 1) -----------------------------------
    def action_textmeflow_connect(self):
        """Send the administrator to textmeflow.eu to approve the connection."""
        self._textmeflow_check_admin()
        params = self.env["ir.config_parameter"].sudo()
        base_url = (params.get_param("web.base.url") or "").rstrip("/")
        return_url = base_url + "/textmeflow/connect/callback"
        if not (base_url.startswith("https://") or base_url.startswith("http://localhost")):
            raise UserError(
                _("Your Odoo address must start with https:// to connect. Check System Parameters > web.base.url.")
            )
        state = secrets.token_urlsafe(24)
        params.set_param("textmeflow.connect_state", "%s:%s" % (state, self.env.uid))
        params.set_param("textmeflow.connect_state_ts", str(int(fields.Datetime.now().timestamp())))
        lang = self.env["textmeflow.template"]._lang_code(self.env.user.lang)
        query = urlencode(
            {"app": "odoo", "site": base_url, "return": return_url, "state": state, "locale": lang}
        )
        return {"type": "ir.actions.act_url", "url": "%s?%s" % (CONNECT_URL, query), "target": "self"}

    @api.model
    def _textmeflow_consume_state(self, state):
        """Check and burn the connect state. Returns True when it is valid for the current user."""
        params = self.env["ir.config_parameter"].sudo()
        stored = params.get_param("textmeflow.connect_state") or ""
        stamp = int(params.get_param("textmeflow.connect_state_ts") or 0)
        params.set_param("textmeflow.connect_state", "")  # one use only
        expected = "%s:%s" % (state or "", self.env.uid)
        age = fields.Datetime.now().timestamp() - stamp
        return bool(stored) and hmac.compare_digest(stored, expected) and 0 <= age <= STATE_TTL_SECONDS

    # -- Link WhatsApp (contract section 4) -------------------------------
    def action_textmeflow_pair(self):
        self._textmeflow_check_admin()
        return {
            "type": "ir.actions.client",
            "tag": "textmeflow_pair_screen",
            "target": "new",
            "name": _("Link WhatsApp"),
        }

    def action_textmeflow_check(self):
        """Ask TextMeFlow whether a WhatsApp number is linked."""
        self._textmeflow_check_admin()
        client = self.env["textmeflow.api"]
        status, data = client._me()
        if status != 200:
            return self._textmeflow_notify(_("TextMeFlow could not be reached or refused the connection."), "danger")
        phone = client._connected_phone(data)
        self.env["ir.config_parameter"].sudo().set_param("textmeflow.phone", phone if isinstance(phone, str) else "")
        if phone:
            return self._textmeflow_notify(_("WhatsApp is linked."))
        return self._textmeflow_notify(_("No WhatsApp number is linked yet. Press 'Link WhatsApp'."), "warning")

    # -- Test message ------------------------------------------------------
    def action_textmeflow_test(self):
        """Send a test message to the linked WhatsApp number itself."""
        self._textmeflow_check_admin()
        client = self.env["textmeflow.api"]
        status, data = client._me()
        phone = client._connected_phone(data) if status == 200 else False
        if not isinstance(phone, str):
            return self._textmeflow_notify(_("Link WhatsApp first."), "warning")
        status, data = client._send(phone, _("Test message from TextMeFlow in Odoo. Everything works."))
        if status in (200, 201, 202):
            return self._textmeflow_notify(_("Test message sent to %s.", phone))
        return self._textmeflow_notify(_("The test message could not be sent (%s).", data.get("error") or status), "danger")

    # -- Disconnect (contract section 6) ----------------------------------
    def action_textmeflow_disconnect(self):
        self._textmeflow_check_admin()
        self.env["textmeflow.api"]._revoke_token()
        params = self.env["ir.config_parameter"].sudo()
        for key in ("textmeflow.token", "textmeflow.account_email", "textmeflow.phone"):
            params.set_param(key, "")
        return {"type": "ir.actions.client", "tag": "reload"}
