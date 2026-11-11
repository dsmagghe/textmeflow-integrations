"""End-to-end test + Addons screenshots for the TextMeFlow PrestaShop module.

Usage: python e2e.py <outdir> [step ...]   (default: all steps, in order)
Needs: pip install playwright (uses the installed Google Chrome) and the stack from
docker-compose.yml (shop on http://localhost:8088, admin demo@prestashop.com /
prestashop_demo, mock API on :8099). Never talks to textmeflow.eu: the connect
redirect goes to the mock through TEXTMEFLOW_SITE_URL.

Every step asserts what it checks against the requests the mock received.
Screenshots are 1920x1500 (viewport 1280x1000 @1.5); the translated sets go to <outdir>/<lang>/.
Step "upload-zip" installs dist/textmeflow.zip through Modules > Upload a module (start the
stack with MODULE_SRC=<empty dir> for that); otherwise install with bin/console first.
"""
import json, os, re, sys, time, urllib.request
from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get("PS_BASE", "http://localhost:8088")
ADMIN = BASE + "/admin-dev/index.php"
MOCK = "http://localhost:8099"
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, "shots")
STEPS = sys.argv[2:] or [
    "upload-zip", "reset", "connect-button", "connect", "qr", "scan", "test-message", "settings",
    "register-optin", "register-no-optin", "order-optin", "order-no-optin", "status-change",
    "order-note", "guest-order", "account-withdraw", "api-failure", "disconnect", "reconnect",
    "install-languages", "lang-shots", "connect-inactive-language", "uninstall",
]
ZIP = os.path.join(HERE, "..", "dist", "textmeflow.zip")
CONTAINER = os.environ.get("PS_CONTAINER", "tmfps-shop-1")
DB_CONTAINER = CONTAINER.replace("-shop-", "-db-")
HIDE = "#notif .count, .growl, #debug-mode, .debug-mode-tag, .onboarding-navbar { display: none !important; }"
os.makedirs(OUT, exist_ok=True)


def mock(path):
    return urllib.request.urlopen(MOCK + path).read()


def requests_log():
    # Read through the mock itself: the bind-mounted file can lag behind on the host.
    raw = mock("/mock/requests").decode()
    return [json.loads(line) for line in raw.splitlines() if line.strip()]


def calls(method, path):
    return [r for r in requests_log() if r["method"] == method and r["path"] == path]


def wait_calls(method, path, n, timeout=15):
    end = time.time() + timeout
    while time.time() < end:
        found = calls(method, path)
        if len(found) >= n:
            return found
        time.sleep(0.5)
    raise AssertionError(f"expected {n} x {method} {path}, got {len(calls(method, path))}")


def shot(page, name):
    page.add_style_tag(content=HIDE)
    time.sleep(0.8)
    page.screenshot(path=f"{OUT}/{name}.png")
    print("  saved", name)


class Shop:
    def __init__(self, page):
        self.page = page
        self.token = None

    def admin_login(self):
        p = self.page
        p.goto(ADMIN + "?controller=AdminLogin")
        if p.locator("#email").count():
            p.fill("#email", "demo@prestashop.com")
            p.fill("#passwd", "prestashop_demo")
            p.click("#submit_login")
            p.wait_for_url(re.compile(r"token="), timeout=60000)
        p.wait_for_load_state()
        href = p.locator("a[href*='_token=']").first.get_attribute("href", timeout=60000)
        self.token = re.search(r"_token=([\w\-.]+)", href).group(1)

    def configure(self):
        if not self.token:
            self.admin_login()
        self.page.goto(f"{ADMIN}/improve/modules/manage/action/configure/textmeflow?_token={self.token}")
        self.page.wait_for_selector("#tmf-app", timeout=60000)

    def logout_front(self):
        self.page.goto(BASE + "/?mylogout=")


def sql(query):
    import subprocess
    subprocess.run(["docker", "exec", DB_CONTAINER, "mariadb", "-uroot", "-padmin", "prestashop", "-e", query], check=True)


def frame_box(p, box):
    """Opt-in box in the lower part of the screen, with the form fields above it (and no shop footer)."""
    box.evaluate("el => { el.scrollIntoView({block: 'end'}); window.scrollBy(0, 260); }")


def step_front_shots(s):
    """Screenshots of the opt-in box only (no account or order is created)."""
    p = s.page
    s.logout_front()
    p.goto(BASE + "/registration")
    if p.locator("text=This page has moved").count():  # several languages: /en/registration
        p.goto(p.locator("a").first.get_attribute("href"))
    box = p.locator("input[name=tmf_optin]")
    expect(box).not_to_be_checked()
    box.check()
    frame_box(p, box)
    shot(p, "front-register-optin")
    p.goto(BASE + "/index.php?id_product=1&controller=product")
    if p.locator("text=This page has moved").count():
        p.goto(p.locator("a").first.get_attribute("href"))
    p.click("button[data-button-action=add-to-cart]")
    p.wait_for_selector("#blockcart-modal, .blockcart-modal, .modal.show", timeout=30000)
    p.goto(BASE + "/order")
    if p.locator("text=This page has moved").count():
        p.goto(p.locator("a").first.get_attribute("href"))
    box = p.locator("#customer-form input[name=tmf_optin]")
    expect(box).not_to_be_checked()
    p.locator("#customer-form input[name=firstname]").fill("Sofie")
    p.locator("#customer-form input[name=lastname]").fill("Maes")
    p.locator("#customer-form input[name=email]").fill("sofie@example.com")
    box.check()
    frame_box(p, box)
    shot(p, "front-checkout-optin")


def step_upload_zip(s):
    """The merchant flow: Modules > Module Manager > Upload a module > textmeflow.zip."""
    p = s.page
    if not s.token:
        s.admin_login()
    p.goto(f"{ADMIN}/improve/modules/manage?_token={s.token}")
    p.click("#page-header-desc-configuration-add_module")
    p.set_input_files("#importDropzone input[type=file], .dz-hidden-input", ZIP)
    expect(p.locator(".module-import-success")).to_be_visible(timeout=120000)
    print("  uploaded and installed from", os.path.relpath(ZIP, HERE))


def step_install_languages(s):
    """French, Dutch and German packs (International > Translations), for the translated screenshots."""
    p = s.page
    if not s.token:
        s.admin_login()
    for iso in ("fr", "nl", "de"):
        p.goto(f"{ADMIN}/improve/international/translations/settings?_token={s.token}")
        with p.expect_navigation(timeout=300000):
            p.evaluate("iso => { const s = document.querySelector('#form_iso_localization_pack'); s.value = iso; s.form.submit(); }", iso)
        print("  language", iso, "installed")


def step_lang_shots(s):
    """Three screenshots per language: connect button, connected + test message, registration opt-in."""
    global OUT
    p = s.page
    base_out = OUT
    ids = {"fr": 2, "nl": 3, "de": 4}
    mock("/mock/scan")  # WhatsApp linked
    try:
        for lang, id_lang in ids.items():
            OUT = os.path.join(base_out, lang)
            os.makedirs(OUT, exist_ok=True)
            sql(f"UPDATE ps_employee SET id_lang = {id_lang} WHERE id_employee = 1")
            s.configure()
            expect(p.locator("#tmf-status.is-ok")).to_be_visible(timeout=15000)
            p.fill("#tmf_merchant_phone", "+32 470 99 88 77")
            p.click("#tmf-test-btn")
            expect(p.locator("#tmf-test-msg")).not_to_be_empty(timeout=15000)
            expect(p.locator("#tmf-test-msg")).not_to_have_text(re.compile(r"\.\.\.$"), timeout=15000)
            shot(p, "admin-connected-test")
            s.logout_front()
            p.goto(f"{BASE}/{lang}/registration")
            box = p.locator("input[name=tmf_optin]")
            expect(box).to_be_visible()
            box.check()
            frame_box(p, box)
            shot(p, "front-register-optin")
        # The connect button needs the disconnected state.
        s.configure()
        p.click("button[name=tmf_disconnect]")
        p.wait_for_selector("button[name=tmf_connect]")
        for lang, id_lang in ids.items():
            OUT = os.path.join(base_out, lang)
            sql(f"UPDATE ps_employee SET id_lang = {id_lang} WHERE id_employee = 1")
            s.configure()
            shot(p, "admin-connect")
    finally:
        OUT = base_out
        sql("UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1")
    step_connect(s)


def step_connect_inactive_language(s):
    """Employee works in a language that is not active in the shop front (German switched off):
    the callback lands in another language, the code exchange must still use the exact return URL."""
    p = s.page
    s.configure()
    if p.locator("button[name=tmf_disconnect]").count():
        p.click("button[name=tmf_disconnect]")
        p.wait_for_selector("button[name=tmf_connect]")
    sql("UPDATE ps_lang SET active = 0 WHERE iso_code = 'de'")
    sql("UPDATE ps_employee SET id_lang = 4 WHERE id_employee = 1")
    try:
        s.configure()
        p.click("button[name=tmf_connect]")
        p.wait_for_selector("#tmf-app[data-connected='1']", timeout=60000)
        ex = json.loads(calls("POST", "/v1/connect/token")[-1]["body"])
        sent = calls("GET", "/connect")[-1]["query"]["return"]
        assert ex["return"] == sent, (ex["return"], sent)
        print("  connected with employee language inactive in the front; return URL", sent)
    finally:
        sql("UPDATE ps_lang SET active = 1 WHERE iso_code = 'de'")
        sql("UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1")


def step_reset(s):
    mock("/mock/reset")


def step_connect_button(s):
    s.configure()
    expect(s.page.locator("button[name=tmf_connect]")).to_be_visible()
    shot(s.page, "admin-connect")


def step_connect(s):
    p = s.page
    s.configure()
    p.click("button[name=tmf_connect]")
    p.wait_for_selector("#tmf-app[data-connected='1']", timeout=60000)
    expect(p.locator(".alert-success")).to_contain_text("Connected to TextMeFlow")
    ex = calls("POST", "/v1/connect/token")
    assert ex and json.loads(ex[-1]["body"])["code"] == "mockcode123", ex
    connect = calls("GET", "/connect")[-1]["query"]
    assert connect["app"] == "prestashop" and len(connect["state"]) >= 16, connect
    assert json.loads(ex[-1]["body"])["return"] == connect["return"], "return URL must match"
    # A replayed callback (same state) must be refused.
    before = len(calls("POST", "/v1/connect/token"))
    p.goto(connect["return"] + ("&" if "?" in connect["return"] else "?") + "code=mockcode123&state=" + connect["state"])
    assert len(calls("POST", "/v1/connect/token")) == before, "replayed state accepted"
    print("  connect ok, replay refused")


def step_qr(s):
    p = s.page
    s.configure()
    p.wait_for_selector("#tmf-qr:not([hidden])", timeout=30000)
    wait_calls("GET", "/v1/accounts/me/pair/pair_1", 1)
    shot(p, "admin-qr")


def step_scan(s):
    p = s.page
    s.configure()
    p.wait_for_selector("#tmf-qr:not([hidden])", timeout=30000)
    mock("/mock/scan")
    expect(p.locator("#tmf-status")).to_contain_text("+32470123456", timeout=15000)
    print("  linked after scan")


def step_test_message(s):
    p = s.page
    s.configure()
    expect(p.locator("#tmf-status")).to_contain_text("+32470123456", timeout=15000)
    p.fill("#tmf_merchant_phone", "0470 99 88 77")
    n = len(calls("POST", "/v1/messages"))
    p.click("#tmf-test-btn")
    expect(p.locator("#tmf-test-msg")).to_contain_text("Test message sent", timeout=15000)
    msg = json.loads(calls("POST", "/v1/messages")[n]["body"])
    assert msg["to"] == "+32470998877", msg
    p.locator("#tmf-test-msg").scroll_into_view_if_needed()
    p.evaluate("window.scrollTo(0, 0)")
    shot(p, "admin-connected-test")


def step_settings(s):
    p = s.page
    s.configure()
    p.fill("#tmf_merchant_phone", "+32 470 99 88 77")
    p.check("input[name=tmf_notify]")
    # Own text for "Shipped" (id from PS_OS_SHIPPING = 4 in a default install).
    p.fill("textarea[name='tmf_status[4][text]']", "Hi {first_name}, your order {order_number} is on its way: {tracking_url}")
    p.check("input[name='tmf_status[3][enabled]']")  # Processing in progress
    p.click("button[name=tmf_save]")
    expect(p.locator(".alert-success")).to_contain_text("Settings saved")
    expect(p.locator("input[name='tmf_status[3][enabled]']")).to_be_checked()
    p.locator("#tmf-settings").scroll_into_view_if_needed()
    p.evaluate("document.querySelector('#tmf-settings').scrollIntoView({block: 'start'}); window.scrollBy(0, -90)")
    shot(p, "admin-settings")
    p.evaluate("document.querySelector('#tmf-settings .tmf-table tbody tr:nth-child(3)').scrollIntoView({block: 'start'}); window.scrollBy(0, -140)")
    shot(p, "admin-status-texts")
    # Invalid phone is refused
    p.fill("#tmf_merchant_phone", "abc<script>")
    p.click("button[name=tmf_save]")
    expect(p.locator(".alert-danger")).to_contain_text("valid mobile number")


def register(s, email, optin, shot_name=None):
    p = s.page
    s.logout_front()
    p.goto(BASE + "/registration")
    if p.locator("input[name=id_gender][value='2']").count():
        p.check("input[name=id_gender][value='2']")
    p.fill("input[name=firstname]", "Emma" if optin else "Lucas")
    p.fill("input[name=lastname]", "Janssens" if optin else "Peeters")
    p.fill("input[name=email]", email)
    p.fill("input[name=password]", "Test-Pass-2026!x")
    box = p.locator("input[name=tmf_optin]")
    expect(box).to_be_visible()
    expect(box).not_to_be_checked()
    if optin:
        box.check()
    for name in ("psgdpr", "customer_privacy"):
        if p.locator(f"input[name={name}]").count():
            p.check(f"input[name={name}]")
    if shot_name:
        frame_box(p, box)
        shot(p, shot_name)
    p.click("form#customer-form button[type=submit], button[data-link-action=save-customer]")
    p.wait_for_load_state()
    assert "registration" not in p.url or p.locator(".alert-danger").count() == 0, p.url


def step_register_optin(s):
    register(s, "emma.optin@example.com", True, "front-register-optin")


def step_register_no_optin(s):
    register(s, "lucas.nooptin@example.com", False)


def front_login(s, email):
    p = s.page
    s.logout_front()
    p.goto(BASE + "/login")
    p.fill("#login-form input[name=email]", email)
    p.fill("#login-form input[name=password]", "Test-Pass-2026!x")
    p.click("#submit-login")
    p.wait_for_load_state()


def checkout(s, email, phone, guest_optin=None, shot_name=None):
    p = s.page
    if guest_optin is None:
        front_login(s, email)
    else:
        s.logout_front()
    p.goto(BASE + "/index.php?id_product=1&controller=product")
    if p.locator("text=This page has moved").count():  # debug mode shows the canonical link
        p.goto(p.locator("a").first.get_attribute("href"))
    p.click("button[data-button-action=add-to-cart]")
    p.wait_for_selector("#blockcart-modal, .blockcart-modal, .modal.show", timeout=30000)
    time.sleep(1)
    p.goto(BASE + "/order")
    if guest_optin is not None:
        # Personal information step of the checkout (guest): the opt-in box is there too.
        form = p.locator("#customer-form")
        form.locator("input[name=firstname]").fill("Sofie")
        form.locator("input[name=lastname]").fill("Maes")
        form.locator("input[name=email]").fill(email)
        box = form.locator("input[name=tmf_optin]")
        expect(box).to_be_visible()
        expect(box).not_to_be_checked()
        if guest_optin:
            box.check()
        for name in ("psgdpr", "customer_privacy"):
            if form.locator(f"input[name={name}]").count():
                form.locator(f"input[name={name}]").check()
        if shot_name:
            frame_box(p, box)
            shot(p, shot_name)
        form.locator("button[name=continue]").click()
        p.wait_for_selector("input[name=address1]", timeout=30000)
    # Address step
    if p.locator("input[name=address1]").count():
        p.fill("input[name=address1]", "Stationsstraat 12")
        p.fill("input[name=postcode]", "9000")
        p.fill("input[name=city]", "Gent")
        p.select_option("select[name=id_country]", label="Belgium")
        time.sleep(1.5)
        p.fill("input[name=address1]", "Stationsstraat 12")
        p.fill("input[name=postcode]", "9000")
        p.fill("input[name=city]", "Gent")
        p.fill("input[name=phone]", phone)
        p.click("button[name=confirm-addresses]")
    else:
        p.click("button[name=confirm-addresses]")
    p.wait_for_selector("button[name=confirmDeliveryOption]", timeout=30000)
    p.click("button[name=confirmDeliveryOption]")
    p.wait_for_selector("input[name=payment-option]", timeout=30000)
    p.locator("input[name=payment-option]").first.check()
    p.check("input[id^=conditions_to_approve]")
    p.click("#payment-confirmation button")
    p.wait_for_url("**order-confirmation**", timeout=60000)
    m = re.search(r"id_order=(\d+)", p.url)
    return int(m.group(1))


def step_order_optin(s):
    n_before = len(calls("POST", "/v1/messages"))
    s.order_optin = checkout(s, "emma.optin@example.com", "0470 11 22 33")
    msgs = wait_calls("POST", "/v1/messages", n_before + 1)
    bodies = [json.loads(m["body"]) for m in msgs[n_before:]]
    assert any(b["to"] == "+32470998877" and "New order" in b["text"] for b in bodies), bodies
    print("  order", s.order_optin, "-> merchant message ok:", [b["to"] for b in bodies])


def step_order_no_optin(s):
    s.order_no_optin = checkout(s, "lucas.nooptin@example.com", "0470 44 55 66")
    print("  order", s.order_no_optin)


def step_guest_order(s):
    """Guest checkout with the box ticked in the personal-information step."""
    s.order_guest = checkout(s, "sofie.guest+" + str(int(time.time())) + "@example.com", "+31 6 12345678", guest_optin=True, shot_name="front-checkout-optin")
    n = len(calls("POST", "/v1/messages"))
    bo_set_status(s, s.order_guest, "Payment accepted")
    msgs = wait_calls("POST", "/v1/messages", n + 1)
    b = json.loads(msgs[n]["body"])
    assert b["to"] == "+31612345678" and b["text"].startswith("Hi Sofie"), b
    print("  guest order", s.order_guest, "->", b["text"])


def step_account_withdraw(s):
    """The customer withdraws the consent in My account > Information."""
    p = s.page
    front_login(s, "emma.optin@example.com")
    p.goto(BASE + "/identity")
    box = p.locator("#customer-form input[name=tmf_optin]")
    expect(box).to_be_checked()
    box.uncheck()
    p.fill("#customer-form input[name=password]", "Test-Pass-2026!x")
    for name in ("psgdpr", "customer_privacy"):
        if p.locator(f"#customer-form input[name={name}]").count():
            p.check(f"#customer-form input[name={name}]")
    p.click("#customer-form button[type=submit]")
    p.wait_for_load_state()
    p.goto(BASE + "/identity")
    expect(p.locator("#customer-form input[name=tmf_optin]")).not_to_be_checked()
    n = len(calls("POST", "/v1/messages"))
    bo_set_status(s, getattr(s, "order_optin", None) or 6, "Delivered")
    time.sleep(3)
    assert len(calls("POST", "/v1/messages")) == n, "message sent after withdrawal"
    print("  withdrawn: no message on Delivered")
    # Opt in again for the remaining steps.
    front_login(s, "emma.optin@example.com")
    p.goto(BASE + "/identity")
    p.check("#customer-form input[name=tmf_optin]")
    p.fill("#customer-form input[name=password]", "Test-Pass-2026!x")
    for name in ("psgdpr", "customer_privacy"):
        if p.locator(f"#customer-form input[name={name}]").count():
            p.check(f"#customer-form input[name={name}]")
    p.click("#customer-form button[type=submit]")
    p.wait_for_load_state()


def step_api_failure(s):
    """API refuses (token revoked on the server): logged + private note, the status change still works."""
    mock("/mock/revoke")
    try:
        bo_set_status(s, getattr(s, "order_optin", None) or 6, "Processing in progress")
        p = s.page
        p.reload()
        p.wait_for_selector("text=not sent (unauthorized)", timeout=30000)
        print("  failure note on the order: ok")
    finally:
        mock("/mock/unrevoke")


def bo_set_status(s, id_order, state_label):
    p = s.page
    if not s.token:
        s.admin_login()
    p.goto(f"{ADMIN}/sell/orders/{id_order}/view?_token={s.token}")
    sel = p.locator("#update_order_status_new_order_status_id")
    value = sel.locator(f"option:text-is('{state_label}')").get_attribute("value")
    with p.expect_navigation():
        p.evaluate("v => { const s = document.querySelector('#update_order_status_new_order_status_id'); s.value = v; s.form.submit(); }", value)
    time.sleep(2)


def step_status_change(s):
    if not getattr(s, "order_optin", None):
        s.order_optin, s.order_no_optin = 6, 7
    n = len(calls("POST", "/v1/messages"))
    bo_set_status(s, s.order_no_optin, "Payment accepted")
    time.sleep(3)
    assert not [json.loads(m["body"]) for m in calls("POST", "/v1/messages")[n:] if "+32470445566" in m["body"]], "message without opt-in"
    print("  no message without opt-in: ok")
    n = len(calls("POST", "/v1/messages"))
    bo_set_status(s, s.order_optin, "Payment accepted")
    msgs = wait_calls("POST", "/v1/messages", n + 1)
    b = json.loads(msgs[n]["body"])
    assert b["to"] == "+32470112233" and "payment" in b["text"].lower(), b
    print("  payment message:", b["text"])
    n = len(calls("POST", "/v1/messages"))
    bo_set_status(s, s.order_optin, "Shipped")
    msgs = wait_calls("POST", "/v1/messages", n + 1)
    b = json.loads(msgs[n]["body"])
    assert b["text"].startswith("Hi Emma, your order") and "on its way" in b["text"], b
    print("  shipped message (own text):", b["text"])


def step_order_note(s):
    p = s.page
    if not s.token:
        s.admin_login()
    if not getattr(s, "order_optin", None):
        s.order_optin = 6
    p.goto(f"{ADMIN}/sell/orders/{s.order_optin}/view?_token={s.token}")
    p.wait_for_selector("text=/WhatsApp message .+ sent to/", timeout=30000)
    # Messages card at the top of the screen (below the sticky order header).
    card = p.locator(".card", has=p.locator("text=/WhatsApp message .+ sent to/")).last
    card.evaluate("el => { el.scrollIntoView({block: 'start'}); window.scrollBy(0, -170); }")
    shot(p, "admin-order-note")


def step_disconnect(s):
    p = s.page
    s.configure()

    n = len(calls("DELETE", "/v1/connect/token"))
    p.click("button[name=tmf_disconnect]")
    expect(p.locator(".alert-success")).to_contain_text("connection has been removed")
    expect(p.locator("button[name=tmf_connect]")).to_be_visible()
    wait_calls("DELETE", "/v1/connect/token", n + 1)
    print("  disconnect revoked token")


def step_reconnect(s):
    step_connect(s)


def step_uninstall(s):
    import subprocess
    n = len(calls("DELETE", "/v1/connect/token"))
    out = subprocess.run(["docker", "exec", "-u", "www-data", CONTAINER, "php",
                          "/var/www/html/bin/console", "prestashop:module", "uninstall", "textmeflow"],
                         capture_output=True, text=True)
    print("  ", out.stdout.strip().splitlines()[-1] if out.stdout.strip() else out.stderr)
    wait_calls("DELETE", "/v1/connect/token", n + 1)
    print("  uninstall revoked token")


with sync_playwright() as pw:
    browser = pw.chromium.launch(channel="chrome", headless=True)
    ctx = browser.new_context(viewport={"width": 1280, "height": 1000}, device_scale_factor=1.5, locale="en-GB")
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("dialog", lambda d: d.accept())  # "Remove the connection?" confirm
    shop = Shop(page)
    for st in STEPS:
        print("step", st)
        try:
            globals()["step_" + st.replace("-", "_")](shop)
        except Exception:
            page.screenshot(path=f"{OUT}/FAILED-{st}.png")
            print("  url:", page.url)
            raise
    print("JS errors:", errors)
    browser.close()
