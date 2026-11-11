"""Builds integrations/prestashop/dist/textmeflow.zip (top-level folder textmeflow/).

Usage: python3 build-zip.py
Python's zipfile is used instead of macOS zip: no __MACOSX, ._* or .DS_Store entries.
Files PrestaShop generates inside the module folder (config_xx.xml) are left out.
"""
import os, re, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, ".."))
SRC = os.path.join(ROOT, "textmeflow")
DIST = os.path.join(ROOT, "dist")
os.makedirs(DIST, exist_ok=True)
out = os.path.join(DIST, "textmeflow.zip")
SKIP = re.compile(r"(^|/)(\.DS_Store|\._.*|__MACOSX|\.git.*|config_[a-z]{2}\.xml|.*~|.*\.swp)$")

entries = []
for base, dirs, files in os.walk(SRC):
    dirs.sort()
    rel = os.path.relpath(base, SRC)
    for name in sorted(files):
        path = name if rel == "." else f"{rel}/{name}"
        if SKIP.search(path):
            continue
        entries.append(path)

if os.path.exists(out):
    os.remove(out)
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for d in sorted({os.path.dirname(e) for e in entries if os.path.dirname(e)} | {""}):
        # explicit directory entries, as most PrestaShop module zips have
        parts = d.split("/") if d else []
        for i in range(len(parts) + 1):
            name = "/".join(["textmeflow"] + parts[:i]) + "/"
            if name not in z.namelist():
                info = zipfile.ZipInfo(name, date_time=(2026, 11, 11, 0, 0, 0))
                info.external_attr = 0o40755 << 16
                z.writestr(info, "")
    for e in entries:
        info = zipfile.ZipInfo("textmeflow/" + e, date_time=(2026, 11, 11, 0, 0, 0))
        info.external_attr = 0o644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        with open(os.path.join(SRC, e), "rb") as f:
            z.writestr(info, f.read())
print(out, len(entries), "files", os.path.getsize(out), "bytes")
