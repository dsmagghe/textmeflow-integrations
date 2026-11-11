"""Store screenshots (1920x1080) of a local test shop.

Usage: python screenshots.py <outdir> <step...>
Steps: admin-module-<name>, admin-qr, admin-config, admin-settings-tile, checkout.
Needs: pip install playwright (uses the installed Google Chrome), a Shopware shop on
http://localhost:8000 (admin/shopware, a product TMF-ROSES, phone field enabled in the
registration) with TEXTMEFLOW_API_URL pointing at dev/mock-api.php on 127.0.0.1:8099.
"""
import sys, time
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8000"
OUT = sys.argv[1]
STEPS = sys.argv[2:]
HIDE = """
.sw-notification-center, .sw-notifications, .mt-notification, .sw-notification__container,
.sw-notifications__container, .sw-admin-menu__user-actions-indicator { display: none !important; }
"""


def admin_login(page):
    page.goto(BASE + "/admin")
    page.wait_for_selector("#sw-field--username", timeout=60000)
    page.fill("#sw-field--username", "admin")
    page.fill("#sw-field--password", "shopware")
    page.keyboard.press("Enter")
    page.wait_for_selector(".sw-admin-menu", timeout=60000)


def shot(page, name):
    page.add_style_tag(content=HIDE)
    page.evaluate("document.querySelectorAll('.sw-notifications__notification, .mt-notification').forEach(e => e.remove())")
    time.sleep(1)
    page.screenshot(path=f"{OUT}/{name}.png")
    print("saved", name)


with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    ctx = browser.new_context(viewport={"width": 1280, "height": 720}, device_scale_factor=1.5, locale="en-GB")
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append("console." + m.type + ": " + m.text) if m.type == "error" else None)

    if any(s.startswith("admin") for s in STEPS):
        admin_login(page)
        page.evaluate("document.querySelectorAll('.sw-notifications__notification').forEach(e => e.remove())")

    for step in STEPS:
        if step.startswith("admin-module"):
            page.goto(BASE + "/admin#/sw/settings/textmeflow/index")
            page.wait_for_selector(".sw-card", timeout=60000)
            time.sleep(4)
            shot(page, step)
        elif step == "admin-qr":
            page.goto(BASE + "/admin#/sw/settings/textmeflow/index")
            page.wait_for_selector(".sw-card", timeout=60000)
            time.sleep(3)
            url = page.evaluate("Shopware.Service('textmeflowApiService').startConnect('en').then(r => r.url)")
            # Never go to the real textmeflow.eu: the local mock plays the approval screen.
            url = url.replace("https://textmeflow.eu", "http://127.0.0.1:8099")
            page.goto(url)
            page.wait_for_url("**/sw/settings/textmeflow/**", timeout=60000)
            page.wait_for_selector("img", timeout=60000)
            time.sleep(5)
            shot(page, step)
        elif step == "admin-config":
            page.goto(BASE + "/admin#/sw/extension/config/TextMeFlow")
            page.wait_for_selector(".sw-system-config, .sw-extension-config", timeout=60000)
            time.sleep(4)
            shot(page, step)
        elif step == "admin-settings-tile":
            page.goto(BASE + "/admin#/sw/settings/index/plugins")
            time.sleep(5)
            shot(page, step)
        elif step == "checkout":
            page.goto(BASE + "/Bouquet-of-red-roses/TMF-ROSES")
            btn = page.locator(".js-cookie-permission-button button, .cookie-permission-button button")
            if btn.count():
                btn.first.click()
            page.click(".btn-buy")
            time.sleep(3)
            page.goto(BASE + "/checkout/register")
            page.fill("#personalFirstName", "Emma")
            page.fill("#personalLastName", "Janssens")
            page.fill("#personalMail", "emma.janssens@example.com")
            page.fill("#billingAddressAddressStreet", "Stationsstraat 12")
            page.fill("#billingAddressAddressZipcode", "9000")
            page.fill("#billingAddressAddressCity", "Gent")
            page.select_option("#billingAddressAddressCountry", label="Belgium")
            page.fill("#billingAddressAddressPhoneNumber", "0470 11 22 33")
            page.click(".register-submit button, form[name=frmRegister] button[type=submit]")
            page.wait_for_url("**/checkout/confirm", timeout=60000)
            page.evaluate("document.querySelector('[name=tmfWhatsappOptin]').scrollIntoView({block: 'center'})")
            time.sleep(1)
            page.evaluate("window.scrollBy(0, 120)")
            shot(page, "checkout")
    print("ERRORS:", errors)
    browser.close()
