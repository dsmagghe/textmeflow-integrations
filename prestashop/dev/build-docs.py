"""Renders dev/docs/readme_en.html to the module's docs/readme_en.pdf (Addons wants the guide in docs/).

Usage: python build-docs.py <icon-512.png>   (needs playwright + Google Chrome)
"""
import os, shutil, sys
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "docs")
OUT = os.path.join(HERE, "..", "textmeflow", "docs")
os.makedirs(OUT, exist_ok=True)
shutil.copy(sys.argv[1], os.path.join(SRC, "icon.png"))
with sync_playwright() as pw:
    b = pw.chromium.launch(channel="chrome", headless=True)
    p = b.new_page()
    p.goto("file://" + os.path.join(SRC, "readme_en.html"))
    p.pdf(path=os.path.join(OUT, "readme_en.pdf"), format="A4", print_background=True)
    b.close()
os.remove(os.path.join(SRC, "icon.png"))
print("ok", os.path.getsize(os.path.join(OUT, "readme_en.pdf")), "bytes")
