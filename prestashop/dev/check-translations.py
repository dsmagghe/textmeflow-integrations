"""Checks that every UI string of the module has a translation in nl/fr/de (XLIFF).

Usage: python3 check-translations.py   (exit code 1 when something is missing)
"""
import glob, html, os, re, sys

MODULE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "textmeflow")
os.chdir(MODULE)
src = {}
for f in glob.glob("**/*.php", recursive=True):
    s = open(f).read()
    for m in re.finditer(r"->trans\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*\[\]\s*,\s*('Modules\.Textmeflow\.\w+'|\$d)", s):
        dom = m.group(2).strip("'")
        if dom == "$d":
            dom = "Modules.Textmeflow.Admin"
        src.setdefault(dom, set()).add(m.group(1).replace("\\'", "'"))
for f in glob.glob("**/*.tpl", recursive=True):
    s = open(f).read()
    for m in re.finditer(r"\{l s='((?:[^'\\]|\\.)*)' d='([\w.]+)'", s):
        src.setdefault(m.group(2), set()).add(m.group(1).replace("\\'", "'"))

bad = False
for loc in ("nl-NL", "fr-FR", "de-DE"):
    for dom, strings in sorted(src.items()):
        fn = f"translations/{loc}/{dom.replace('.', '')}.{loc}.xlf"
        x = open(fn).read()
        units = re.findall(r"<source>(.*?)</source>\s*<target>(.*?)</target>", x, re.S)
        have = {html.unescape(a) for a, b in units if b.strip()}
        missing = strings - have
        unused = have - strings
        print(f"{loc} {dom}: {len(strings)} strings, missing {len(missing)}, unused {len(unused)}")
        for m in sorted(missing):
            print("   missing:", m)
        for m in sorted(unused):
            print("   unused:", m)
        bad = bad or bool(missing)
sys.exit(1 if bad else 0)
