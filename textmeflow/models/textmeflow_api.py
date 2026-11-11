"""Thin server-side client for the TextMeFlow API (docs/connect-contract.md).

Every method starts with an underscore on purpose: public methods of an
abstract model can be called over RPC by any logged-in user, and the token
must never leave the server.
"""
import logging

import requests

from odoo import api, models

_logger = logging.getLogger(__name__)

DEFAULT_API_BASE = "https://api.textmeflow.eu/v1"
TOKEN_PARAM = "textmeflow.token"
TIMEOUT = 15


class TextMeFlowApi(models.AbstractModel):
    _name = "textmeflow.api"
    _description = "TextMeFlow API client"

    @api.model
    def _params(self):
        return self.env["ir.config_parameter"].sudo()

    @api.model
    def _api_base(self):
        return (self._params().get_param("textmeflow.api_base") or DEFAULT_API_BASE).rstrip("/")

    @api.model
    def _token(self):
        return self._params().get_param(TOKEN_PARAM) or False

    @api.model
    def _request(self, method, path, payload=None, token=None):
        """Perform a request. Returns ``(status_code, json_dict)``.

        ``status_code`` is 0 when the service could not be reached. The token is
        only ever sent as a Bearer header to the TextMeFlow API.
        """
        headers = {"Accept": "application/json", "User-Agent": "TextMeFlow-Odoo/1.0"}
        token = token or self._token()
        if token:
            headers["Authorization"] = "Bearer %s" % token
        try:
            response = requests.request(
                method,
                self._api_base() + path,
                json=payload,
                headers=headers,
                timeout=TIMEOUT,
                allow_redirects=False,
            )
        except requests.RequestException as exc:
            _logger.warning("TextMeFlow request %s %s failed: %s", method, path, exc.__class__.__name__)
            return 0, {"error": "unreachable"}
        try:
            data = response.json()
            if not isinstance(data, dict):
                data = {}
        except ValueError:
            data = {}
        return response.status_code, data

    @api.model
    def _exchange_code(self, code, return_url):
        """Trade the one-time code for a connection token (contract section 2)."""
        return self._request("POST", "/connect/token", {"code": code, "return": return_url}, token=False)

    @api.model
    def _me(self):
        return self._request("GET", "/accounts/me")

    @api.model
    def _connected_phone(self, data):
        """Phone number of the first connected WhatsApp session in an /accounts/me answer."""
        for session in data.get("wa_sessions") or []:
            if session.get("status") == "connected":
                return session.get("phone_number") or True
        return False

    @api.model
    def _send(self, to, text):
        return self._request("POST", "/messages", {"to": to, "text": text})

    @api.model
    def _revoke_token(self):
        token = self._token()
        if token:
            self._request("DELETE", "/connect/token", token=token)
