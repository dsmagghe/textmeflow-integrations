"""Builds the Shopware Store images: preview (1920x1080), icon (112x112) and numbered screenshots."""
import shutil, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

SHOTS, OUT = sys.argv[1], sys.argv[2]
GREEN = (37, 211, 102)
DARK = (16, 42, 67)
FONT = "/System/Library/Fonts/Supplemental/Arial.ttf"
FONT_BOLD = "/System/Library/Fonts/Supplemental/Arial Bold.ttf"


def bubble(size, rounded):
    s = 4
    w = size * s
    im = Image.new("RGB", (w, w), GREEN)
    d = ImageDraw.Draw(im)
    if rounded:
        im = Image.new("RGBA", (w, w), (0, 0, 0, 0))
        d = ImageDraw.Draw(im)
        d.rounded_rectangle([0, 0, w - 1, w - 1], radius=int(w * 0.22), fill=GREEN)
    k = w / 256
    d.rounded_rectangle([52 * k, 64 * k, 204 * k, 166 * k], radius=22 * k, fill="white")
    d.polygon([(76 * k, 160 * k), (68 * k, 200 * k), (108 * k, 164 * k)], fill="white")
    for cx in (96, 128, 160):
        r = 11 * k
        d.ellipse([cx * k - r, 115 * k - r, cx * k + r, 115 * k + r], fill=GREEN)
    return im.resize((size, size), Image.LANCZOS)


# Store icon: full-bleed square, no transparency, no rounded corners.
bubble(112, rounded=False).save(f"{OUT}/icon-112x112.png", optimize=True)

# Screenshots in listing order.
order = [
    ("admin-module-connect", "01-connect-button.png"),
    ("admin-qr", "02-link-whatsapp-qr.png"),
    ("admin-module-connected", "03-connected-test-message.png"),
    ("admin-config", "04-message-settings.png"),
    ("checkout", "05-checkout-opt-in.png"),
]
for src, dst in order:
    im = Image.open(f"{SHOTS}/{src}.png").convert("RGB")
    assert im.size == (1920, 1080), im.size
    im.save(f"{OUT}/{dst}", optimize=True)

# Preview: 1920x1080, key content inside a generous safe area.
W, H = 1920, 1080
pv = Image.new("RGB", (W, H), (244, 247, 250))
d = ImageDraw.Draw(pv)
d.rectangle([0, 0, 760, H], fill=DARK)
icon = bubble(150, rounded=True)
pv.paste(icon, (140, 250), icon)
title = ImageFont.truetype(FONT_BOLD, 76)
sub = ImageFont.truetype(FONT_BOLD, 40)
body = ImageFont.truetype(FONT, 28)
d.text((140, 440), "TextMeFlow", font=title, fill="white")
d.text((140, 535), "WhatsApp order updates", font=sub, fill=GREEN)
lines = ["Connect in one click, scan a QR code,", "and keep customers updated on WhatsApp,", "only with their consent. EU-hosted."]
for i, line in enumerate(lines):
    d.text((140, 620 + i * 42), line, font=body, fill=(220, 228, 236))

shot = Image.open(f"{SHOTS}/admin-module-connected.png").convert("RGB")
shot = shot.resize((1040, 585), Image.LANCZOS)
x, y = 820, 248
shadow = Image.new("RGBA", (shot.width + 60, shot.height + 60), (0, 0, 0, 0))
ImageDraw.Draw(shadow).rectangle([30, 30, 30 + shot.width, 30 + shot.height], fill=(0, 0, 0, 70))
shadow = shadow.filter(ImageFilter.GaussianBlur(14))
pv.paste(shadow, (x - 30, y - 18), shadow)
pv.paste(shot, (x, y))
pv.save(f"{OUT}/preview-1920x1080.png", optimize=True)
print("ok")
