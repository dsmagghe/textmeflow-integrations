import logging

from werkzeug.exceptions import Forbidden

from odoo import _, http
from odoo.http import request

_logger = logging.getLogger(__name__)

SETTINGS_URL = "/web#action=textmeflow.action_textmeflow_settings"


class TextMeFlowConnect(http.Controller):
    @http.route("/textmeflow/connect/callback", type="http", auth="user", methods=["GET"])
    def connect_callback(self, code=None, state=None, error=None, **kwargs):
        """Landing page after the user approved (or refused) on textmeflow.eu.

        The ``state`` value was created for this administrator when the Connect
        button was pressed; without a matching state nothing is stored (CSRF).
        """
        env = request.env
        if not env.user.has_group("base.group_system"):
            raise Forbidden()
        settings = env["res.config.settings"]
        if not settings._textmeflow_consume_state(state):
            return self._message(_("This connection request expired or is not valid. Please try again from Settings."))
        if error or not code:
            return request.redirect(SETTINGS_URL)

        params = env["ir.config_parameter"].sudo()
        return_url = (params.get_param("web.base.url") or "").rstrip("/") + "/textmeflow/connect/callback"
        status, data = env["textmeflow.api"]._exchange_code(code, return_url)
        token = data.get("token")
        if status != 200 or not token:
            _logger.warning("TextMeFlow: code exchange failed with status %s.", status)
            return self._message(_("TextMeFlow did not accept the code. Please try again from Settings."))

        params.set_param("textmeflow.token", token)
        params.set_param("textmeflow.account_email", data.get("account_email") or "")
        # Ready-made messages are switched on after connecting; consent is still checked per customer.
        for key in ("textmeflow.auto_order", "textmeflow.auto_delivery", "textmeflow.auto_invoice"):
            if params.get_param(key) is False:
                params.set_param(key, "True")
        return request.redirect(SETTINGS_URL)

    def _message(self, text):
        from markupsafe import escape

        body = (
            "<!doctype html><meta charset='utf-8'><title>TextMeFlow</title>"
            "<p style='font-family:sans-serif;margin:3rem auto;max-width:32rem'>%s</p>"
            "<p style='font-family:sans-serif;margin:0 auto;max-width:32rem'><a href='%s'>%s</a></p>"
        ) % (escape(text), SETTINGS_URL, escape(_("Back to Odoo")))
        return request.make_response(body, headers=[("Content-Type", "text/html; charset=utf-8")])
