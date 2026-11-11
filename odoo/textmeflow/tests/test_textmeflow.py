from unittest.mock import patch

from odoo.exceptions import UserError
from odoo.tests import TransactionCase, tagged

from ..models.phone import normalize_phone


@tagged("post_install", "-at_install", "textmeflow")
class TestTextMeFlow(TransactionCase):
    def setUp(self):
        super().setUp()
        self.params = self.env["ir.config_parameter"].sudo()
        self.params.set_param("textmeflow.token", "tmfc_test")
        self.params.set_param("textmeflow.auto_order", "True")
        self.env["res.lang"]._activate_lang("nl_BE")
        be = self.env.ref("base.be")
        self.partner = self.env["res.partner"].create(
            {"name": "Jan Peeters", "mobile": "0470 12 34 56", "country_id": be.id, "lang": "nl_BE"}
        )

    def test_phone_normalisation(self):
        self.assertEqual(normalize_phone("0470 12 34 56", "BE"), "+32470123456")
        self.assertEqual(normalize_phone("0032 470-12-34-56", None), "+32470123456")
        self.assertEqual(normalize_phone("+32 470 12 34 56", "NL"), "+32470123456")
        self.assertFalse(normalize_phone("0470123456", None))
        self.assertFalse(normalize_phone("12", "BE"))

    def test_render_ignores_unknown_and_attribute_access(self):
        tpl = self.env["textmeflow.template"]
        self.assertEqual(tpl._render("Hi {first_name}{x} {0.__class__}", {"first_name": "Jan"}), "Hi Jan {0.__class__}")

    def test_no_consent_no_automatic_message(self):
        order = self.env["sale.order"].create({"partner_id": self.partner.id})
        self.assertFalse(self.env["textmeflow.message"]._auto_queue(order, "order_confirmed", self.partner))

    def test_automatic_message_with_consent_is_queued_once(self):
        self.partner.textmeflow_optin = True
        self.assertTrue(self.partner.textmeflow_optin_date)
        order = self.env["sale.order"].create({"partner_id": self.partner.id})
        messages = self.env["textmeflow.message"]
        msg = messages._auto_queue(order, "order_confirmed", self.partner)
        self.assertEqual(msg.state, "pending")
        self.assertEqual(msg.phone, "+32470123456")
        self.assertIn("Jan", msg.body)  # Dutch template, first name
        self.assertIn(order.name, msg.body)
        self.assertFalse(messages._auto_queue(order, "order_confirmed", self.partner))

    def test_cron_sends_and_logs(self):
        self.partner.textmeflow_optin = True
        order = self.env["sale.order"].create({"partner_id": self.partner.id})
        msg = self.env["textmeflow.message"]._auto_queue(order, "order_confirmed", self.partner)
        api = "odoo.addons.textmeflow.models.textmeflow_api.TextMeFlowApi._send"
        with patch(api, return_value=(202, {"id": "m1", "status": "queued"})):
            self.env["textmeflow.message"]._cron_send_pending()
        self.assertEqual(msg.state, "sent")
        self.assertTrue(order.message_ids.filtered(lambda m: "WhatsApp" in (m.body or "")))

    def test_manual_wizard_requires_consent(self):
        wizard = self.env["textmeflow.send.wizard"].create(
            {"res_model": "res.partner", "res_id": self.partner.id, "partner_id": self.partner.id}
        )
        self.assertTrue(wizard.body)
        with self.assertRaises(UserError):
            wizard.action_send()
        wizard.optin = True
        wizard.action_send()
        self.assertEqual(self.env["textmeflow.message"].search_count([("partner_id", "=", self.partner.id)]), 1)
