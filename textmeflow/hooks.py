"""Uninstall hook for TextMeFlow."""
import logging

from odoo import SUPERUSER_ID, api

_logger = logging.getLogger(__name__)

PARAMS = (
    "textmeflow.token",
    "textmeflow.account_email",
    "textmeflow.phone",
    "textmeflow.connect_state",
    "textmeflow.connect_state_ts",
    "textmeflow.auto_order",
    "textmeflow.auto_delivery",
    "textmeflow.auto_invoice",
    "textmeflow.api_base",
)


def _env(cr_or_env):
    if isinstance(cr_or_env, api.Environment):
        return cr_or_env
    return api.Environment(cr_or_env, SUPERUSER_ID, {})


def post_init_hook(cr_or_env, registry=None):
    """Make the send cron unlimited on Odoo 17 (the field was removed in Odoo 18)."""
    env = _env(cr_or_env)
    cron = env.ref("textmeflow.ir_cron_send_pending", raise_if_not_found=False)
    if cron and "numbercall" in cron._fields:
        cron.write({"numbercall": -1})


def uninstall_hook(cr_or_env, registry=None):
    """Remove the stored token and all settings when the module is uninstalled.

    The token is also revoked at TextMeFlow (best effort, never blocks the
    uninstall) so no orphan connection stays listed in the customer's portal.
    Odoo 17 passes (cr, registry), Odoo 18 passes (env).
    """
    env = _env(cr_or_env)
    try:
        env["textmeflow.api"]._revoke_token()
    except Exception:  # noqa: BLE001 - uninstall must never fail on network issues
        _logger.info("TextMeFlow: token could not be revoked remotely on uninstall.")
    params = env["ir.config_parameter"].sudo()
    for key in PARAMS:
        params.search([("key", "=", key)]).unlink()
