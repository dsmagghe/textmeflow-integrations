"""Builds the PrestaShop Addons images: logos from our own icon and numbered screenshots.

Usage: python store-assets.py <shots-dir> <icon-512.png>
  shots-dir: output of e2e.py (and e2e.py with TMF_LANG=fr|nl|de for the translated sets)
  icon:      storage/app/textmeflow-icon-512.png (green speech bubble with arrow; never a WhatsApp logo)
Writes ../store-assets/ and the module logo ../textmeflow/logo.png (32x32).
"""
import os, sys
from PIL import Image

SHOTS, ICON = sys.argv[1], sys.argv[2]
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "store-assets")
os.makedirs(OUT, exist_ok=True)

icon = Image.open(ICON).convert("RGBA")
for size in (256, 128, 57):
    icon.resize((size, size), Image.LANCZOS).save(f"{OUT}/logo-{size}x{size}.png", optimize=True)
icon.resize((32, 32), Image.LANCZOS).save(os.path.join(HERE, "..", "textmeflow", "logo.png"), optimize=True)

# Listing order (English set). Addons: at least 1000x1000 px each, PNG/JPG.
ORDER = [
    ("admin-connect", "01-connect-button"),
    ("admin-qr", "02-qr-pairing"),
    ("admin-connected-test", "03-connected-test-message"),
    ("admin-settings", "04-message-settings"),
    ("admin-status-texts", "05-order-status-texts"),
    ("front-checkout-optin", "06-checkout-opt-in"),
    ("front-register-optin", "07-registration-opt-in"),
    ("admin-order-note", "08-order-message-note"),
]
LANG_ORDER = [("admin-connect", "01-connect-button"), ("admin-connected-test", "02-connected-test-message"),
              ("front-register-optin", "03-registration-opt-in")]


def copy(src, dst):
    im = Image.open(src).convert("RGB")
    assert im.width >= 1000 and im.height >= 1000, (src, im.size)
    im.save(dst, optimize=True)
    print(os.path.basename(dst), im.size)


for src, dst in ORDER:
    copy(f"{SHOTS}/{src}.png", f"{OUT}/{dst}.png")
for lang in ("fr", "nl", "de"):
    d = f"{SHOTS}/{lang}"
    if not os.path.isdir(d):
        continue
    os.makedirs(f"{OUT}/{lang}", exist_ok=True)
    for src, dst in LANG_ORDER:
        copy(f"{d}/{src}.png", f"{OUT}/{lang}/{dst}.png")
print("ok")
