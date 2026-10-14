#!/usr/bin/env python3
"""Apply the HiTechPay brand to the Hyperswitch frontends (white-labelling).

Usage:
    python3 tools/whitelabel.py [--brand branding/brand.json]
                                [--only control-center|web] [--dry-run] [-v]

Targets (git submodules under upstream/):
  * hyperswitch-control-center  (ReScript merchant dashboard)
  * hyperswitch-web              (ReScript payment SDK)

The script is idempotent: running it twice produces no further changes.
It only edits user-visible strings, assets and config values. Module names,
identifiers, file names, API hosts/paths, config keys, npm package names, CSS
classes and the SDK's public JS API (window.Hyper, HyperLoader.js, postMessage
event names) are left untouched.

Stdlib only.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CC_DIR = ROOT / "upstream" / "hyperswitch-control-center"
WEB_DIR = ROOT / "upstream" / "hyperswitch-web"

# Where brand assets are copied inside the control center. Everything under
# public/hyperswitch/ is copied to the web root by webpack (CopyPlugin), so the
# files are served at /assets/hitechpay/... -- the same root-absolute form the
# upstream defaults ("/HyperswitchFavicon.png", "/assets/Light/...") use.
CC_ASSET_DIR_REL = "public/hyperswitch/assets/hitechpay"
CC_ASSET_URL = "/assets/hitechpay"

# ---------------------------------------------------------------------------
# Text replacement rules (applied ONLY inside ReScript string literals)
# ---------------------------------------------------------------------------

# A literal containing any of these markers is never rewritten (false
# positives): the Australian BECS mandate in the web SDK names a legal entity
# -- we must not invent "HiTechPay Payments Australia Pty Ltd" -- and internal
# log/error messages that mention the "hyperswitch" vault type.
LITERAL_EXCLUDE_MARKERS = ("Pty Ltd", "hyperswitch vault")

# Tokens inside a literal that are never rewritten: URLs, hosts and e-mail
# addresses (API hosts such as app.hyperswitch.io stay as they are).
PROTECTED_TOKEN = re.compile(r"[\w.+-]+@[\w.-]+|\b[a-z][\w+.-]*://[^\s\"'`<>)]*|\b[\w-]+(?:\.[\w-]+)+\.(?:io|com|in|vn|net|org)\b[^\s\"'`<>)]*")

# Paths (relative to the repo) that are never rewritten.
PATH_EXCLUDES = (
    "node_modules/",
    "lib/",
    "shared-code/",
    "Hyperswitch-React-Demo-App/",
    "cypress",
    "playwright-tests/",
)

# Plain user-facing help links -> HiTechPay docs. API hosts
# (app/live/integ/eu/sandbox.hyperswitch.io, ...) are deliberately NOT here.
def link_rules(brand: dict) -> list[tuple[re.Pattern, str]]:
    docs = brand.get("docs_url", "https://docs.hitechpay.vn").rstrip("/")
    site = brand["website"].rstrip("/")
    return [
        # Keep the path so docs.hitechpay.vn can mirror the upstream structure.
        (re.compile(r"https://docs\.hyperswitch\.io"), docs),
        (re.compile(r"https://hyperswitch\.io/docs/?$"), docs),
        (re.compile(r"https://hyperswitch\.io/(terms-of-services|privacy-policy|blog)$"),
         site + r"/\1"),
        # support contact shown in the Apple Pay setup instructions
        (re.compile(r"\bbiz@hyperswitch\.io\b"), brand["support_email"]),
    ]


def text_rules(brand: dict) -> list[tuple[re.Pattern, str]]:
    name = brand["name"]
    dash = brand.get("dashboard_name", name + " Dashboard")
    return [
        (re.compile(r"\bHyper[Ss]witch Control Center\b"), dash),
        # Estonian adessive form ("pakkujal Hyperswitchil")
        (re.compile(r"\bHyper[Ss]witchil\b"), name + "'l"),
        (re.compile(r"\bHyper[Ss]witch\b"), name),
        (re.compile(r"\bHYPERSWITCH\b"), name.upper()),
        # lower-case brand used in prose ("... your hyperswitch experience?")
        (re.compile(r"(?<=\s)hyperswitch(?=[\s.,!?])"), name),
    ]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

class Ctx:
    def __init__(self, dry_run: bool, verbose: bool):
        self.dry_run = dry_run
        self.verbose = verbose
        self.changed: list[Path] = []

    def write_text(self, path: Path, new: str) -> None:
        old = path.read_text(encoding="utf-8") if path.exists() else None
        if old == new:
            return
        self.changed.append(path)
        if self.verbose or self.dry_run:
            print(f"  ~ {path}")
        if not self.dry_run:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(new, encoding="utf-8")

    def copy(self, src: Path, dst: Path) -> None:
        if dst.exists() and dst.read_bytes() == src.read_bytes():
            return
        self.changed.append(dst)
        if self.verbose or self.dry_run:
            print(f"  + {dst}")
        if not self.dry_run:
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(src, dst)


def iter_res_files(repo: Path, sub: str = "src"):
    for dirpath, dirnames, filenames in os.walk(repo / sub):
        rel_dir = os.path.relpath(dirpath, repo).replace(os.sep, "/") + "/"
        dirnames[:] = [
            d for d in dirnames
            if not any((rel_dir + d + "/").startswith(p) or ("/" + p) in ("/" + rel_dir + d + "/")
                       for p in PATH_EXCLUDES)
        ]
        for fn in sorted(filenames):
            if fn.endswith((".res", ".resi")):
                yield Path(dirpath) / fn


_CHAR_LIT = re.compile(r"'(\\(?:[\\'\"ntbr ]|\d{3}|x[0-9a-fA-F]{2}|o[0-7]{3})|[^\\'\n])'")


def rewrite_rescript_literals(src: str, fn) -> str:
    """Call fn(text) on the text of every string literal (both "..." strings and
    the static parts of `template` strings, excluding ${...} interpolations),
    skipping comments and char literals. Returns the rewritten source."""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        two = src[i:i + 2]
        if two == "//":
            j = src.find("\n", i)
            j = n if j == -1 else j
            out.append(src[i:j]); i = j
        elif two == "/*":
            depth, j = 1, i + 2
            while j < n and depth:
                if src.startswith("/*", j):
                    depth += 1; j += 2
                elif src.startswith("*/", j):
                    depth -= 1; j += 2
                else:
                    j += 1
            out.append(src[i:j]); i = j
        elif c == "'":
            m = _CHAR_LIT.match(src, i)
            if m:
                out.append(m.group(0)); i = m.end()
            else:
                out.append(c); i += 1
        elif c == '"':
            j = i + 1
            while j < n and src[j] != '"':
                j += 2 if src[j] == "\\" else 1
            body = src[i + 1:j]
            out.append('"' + fn(body) + '"'); i = j + 1
        elif c == "`":
            j = i + 1
            out.append("`")
            seg_start = j
            while j < n and src[j] != "`":
                if src[j] == "\\":
                    j += 2
                elif src.startswith("${", j):
                    out.append(fn(src[seg_start:j]))
                    # skip the interpolation with brace matching (recursively
                    # rewriting nested literals is unnecessary: leave code as is)
                    depth, k = 1, j + 2
                    while k < n and depth:
                        if src[k] == "{":
                            depth += 1
                        elif src[k] == "}":
                            depth -= 1
                        elif src[k] == '"':
                            k += 1
                            while k < n and src[k] != '"':
                                k += 2 if src[k] == "\\" else 1
                        k += 1
                    out.append(src[j:k]); j = k; seg_start = j
                else:
                    j += 1
            out.append(fn(src[seg_start:j]))
            out.append("`"); i = j + 1
        else:
            out.append(c); i += 1
    return "".join(out)


def make_literal_fn(rules, links, report: list):
    def fn(text: str) -> str:
        new = text
        for pat, rep in links:
            new = pat.sub(rep, new)
        if not any(m in new for m in LITERAL_EXCLUDE_MARKERS):
            # rewrite only the text between protected tokens (urls/emails/hosts)
            parts, last = [], 0
            for m in PROTECTED_TOKEN.finditer(new):
                parts.append((new[last:m.start()], True))
                parts.append((m.group(0), False))
                last = m.end()
            parts.append((new[last:], True))
            out = []
            for chunk, editable in parts:
                if editable:
                    for pat, rep in rules:
                        chunk = pat.sub(rep, chunk)
                out.append(chunk)
            new = "".join(out)
        if new != text:
            report.append((text, new))
        return new
    return fn


def rebrand_sources(repo: Path, brand: dict, ctx: Ctx, with_links: bool) -> int:
    rules = text_rules(brand)
    links = link_rules(brand) if with_links else []
    total = 0
    for path in iter_res_files(repo):
        src = path.read_text(encoding="utf-8")
        report: list = []
        new = rewrite_rescript_literals(src, make_literal_fn(rules, links, report))
        if new != src:
            total += len(report)
            if ctx.verbose:
                for a, b in report:
                    print(f"    {path.relative_to(repo)}: {a[:70]!r} -> {b[:70]!r}")
            ctx.write_text(path, new)
    return total


def replace_exact(path: Path, pairs, ctx: Ctx, required: bool = True) -> None:
    src = path.read_text(encoding="utf-8")
    new = src
    for old, rep in pairs:
        if old in new:
            new = new.replace(old, rep)
        elif required and rep not in new:
            print(f"  ! {path}: pattern not found: {old!r}", file=sys.stderr)
    ctx.write_text(path, new)


# ---------------------------------------------------------------------------
# Control center
# ---------------------------------------------------------------------------

def apply_control_center(brand: dict, brand_dir: Path, ctx: Ctx) -> None:
    repo = CC_DIR
    print(f"[control-center] {repo}")
    a = brand["assets"]
    c = brand["colors"]
    asset = lambda k: brand_dir / a[k]
    dst = repo / CC_ASSET_DIR_REL

    # 1. Brand assets, served at /assets/hitechpay/...
    for name, key in [("logo.svg", "logo"), ("logo-dark.svg", "logo_dark"),
                      ("icon.svg", "icon"), ("favicon.svg", "favicon"),
                      ("Light/logo.svg", "logo"), ("Light/icon.svg", "icon"),
                      ("Dark/logo.svg", "logo_dark"), ("Dark/icon.svg", "icon")]:
        ctx.copy(asset(key), dst / name)
    logo_url = f"{CC_ASSET_URL}/Light/logo.svg"
    favicon_url = f"{CC_ASSET_URL}/favicon.svg"

    # 2. config/config.toml -- only the branding urls; api_url etc. untouched.
    cfg = repo / "config" / "config.toml"
    s = cfg.read_text(encoding="utf-8")
    s = re.sub(r'(?m)^logo_url\s*=.*$', f'logo_url="{logo_url}"', s)
    s = re.sub(r'(?m)^favicon_url\s*=.*$', f'favicon_url="{favicon_url}"', s)
    ctx.write_text(cfg, s)

    # 3. config/theme.json
    theme_path = repo / "config" / "theme.json"
    theme = json.loads(theme_path.read_text(encoding="utf-8"))
    st = theme.setdefault("settings", {})
    st.setdefault("colors", {})["primary"] = c["primary"]
    st["sidebar"] = {**st.get("sidebar", {}),
                     "primary": c["sidebar_background"],
                     "textColor": c["sidebar_text"],
                     "textColorPrimary": c["sidebar_text_active"]}
    btn = st.setdefault("buttons", {})
    btn["primary"] = {**btn.get("primary", {}), "backgroundColor": c["primary"],
                      "textColor": "#ffffff", "hoverBackgroundColor": c["primary_hover"]}
    btn["secondary"] = {**btn.get("secondary", {}),
                        "backgroundColor": c["secondary_background"],
                        "textColor": c["secondary_text"],
                        "hoverBackgroundColor": c["secondary_hover"]}
    theme["urls"] = {**theme.get("urls", {}), "faviconUrl": favicon_url, "logoUrl": logo_url}
    ctx.write_text(theme_path, json.dumps(theme, indent=2, ensure_ascii=False) + "\n")

    # 4. Built-in fallback theme in ThemeProvider.res ("keep in sync with
    #    config/theme.json") + default asset paths used when config urls are empty.
    tp = repo / "src" / "context" / "ThemeProvider.res"
    s = tp.read_text(encoding="utf-8")
    m = re.search(r"let fallbackThemeConfig[^\n]*\n(.*?)\n}\n", s, re.S)
    if m:
        block = m.group(1)
        values = {
            ("colors", "primary"): c["primary"],
            ("sidebar", "primary"): c["sidebar_background"],
            ("sidebar", "textColor"): c["sidebar_text"],
            ("sidebar", "textColorPrimary"): c["sidebar_text_active"],
            ("primary", "backgroundColor"): c["primary"],
            ("primary", "textColor"): "#ffffff",
            ("primary", "hoverBackgroundColor"): c["primary_hover"],
            ("secondary", "backgroundColor"): c["secondary_background"],
            ("secondary", "textColor"): c["secondary_text"],
            ("secondary", "hoverBackgroundColor"): c["secondary_hover"],
        }
        stack: list[str] = []
        lines = []
        for line in block.split("\n"):
            mo = re.match(r"\s*(\w+):\s*\{\s*$", line)
            if mo:
                stack.append(mo.group(1))
            elif re.match(r"\s*\},?\s*$", line) and stack:
                stack.pop()
            else:
                kv = re.match(r'(\s*)(\w+):\s*"[^"]*",?(\s*)$', line)
                if kv and stack and (stack[-1], kv.group(2)) in values:
                    line = f'{kv.group(1)}{kv.group(2)}: "{values[(stack[-1], kv.group(2))]}",'
            lines.append(line)
        block = "\n".join(lines)
        block = re.sub(r'logoUrl: Some\("[^"]*"\)', f'logoUrl: Some("{logo_url}")', block)
        s = s[:m.start(1)] + block + s[m.end(1):]
    s = re.sub(r'(primary_color: )"[^"]*"', rf'\1"{c["primary"]}"', s)
    ctx.write_text(tp, s)

    default_asset_paths = [
        ('"/assets/Light/juspayHyperswitchLogoIconWithText.svg"', f'"{logo_url}"'),
        ('`assets/Light/juspayHyperswitchLogoIconWithText.svg`', f'`{logo_url}`'),
        ("/assets/${iconFolder}/hyperswitchLogoIcon.svg", CC_ASSET_URL + "/${iconFolder}/icon.svg"),
        ("/assets/${iconFolder}/hyperswitchLogoText.svg", CC_ASSET_URL + "/${iconFolder}/logo.svg"),
        ("/assets/${iconFolder}/juspayHyperswitchLogoIconWithText.svg",
         CC_ASSET_URL + "/${iconFolder}/logo.svg"),
        ('"/HyperswitchFavicon.png"', f'"{favicon_url}"'),
    ]
    for path in iter_res_files(repo):
        s = path.read_text(encoding="utf-8")
        new = s
        for old, rep in default_asset_paths:
            new = new.replace(old, rep)
        ctx.write_text(path, new)

    # Browser tab title: "<Page> - Dashboard" -> "<Page> - HiTechPay Dashboard"
    replace_exact(repo / "src" / "entryPoints" / "HyperSwitchEntry.res",
                  [("`${page} - Dashboard`", "`${page} - " + brand["dashboard_name"] + "`")], ctx)

    # 5. index.html: title + default favicon before JS boots.
    idx = repo / "public" / "hyperswitch" / "index.html"
    s = idx.read_text(encoding="utf-8")
    s = re.sub(r"<title>.*?</title>", f"<title>{brand['dashboard_name']}</title>", s, count=1)
    link = f'<link rel="shortcut icon" type="image/svg+xml" href="{favicon_url}" />'
    if 'rel="shortcut icon"' not in s:
        s = re.sub(r"(\n(\s*)<title>.*?</title>)", lambda mm: mm.group(1) + "\n" + mm.group(2) + link, s, count=1)
    else:
        s = re.sub(r'<link rel="shortcut icon"[^>]*/>', link, s)
    ctx.write_text(idx, s)

    # 6. User-visible "Hyperswitch" text in string literals + help links.
    n = rebrand_sources(repo, brand, ctx, with_links=True)
    print(f"  rewrote {n} string literal(s)")


# ---------------------------------------------------------------------------
# Web SDK
# ---------------------------------------------------------------------------

def powered_by_symbol(brand: dict) -> str:
    name = brand["name"]
    return (
        '<symbol\n'
        '    id="powerd-by-hyper"\n'
        '    xmlns="http://www.w3.org/2000/svg"\n'
        '    width="130"\n'
        '    height="13"\n'
        '    viewBox="0 0 130 13"\n'
        '    fill="none"\n'
        '  >\n'
        '    <text x="65" y="10" text-anchor="middle" fill="currentColor"'
        ' font-family="Inter, -apple-system, \'Segoe UI\', Roboto, Arial, sans-serif"'
        ' font-size="10">\n'
        f'      <tspan opacity="0.4">Powered by </tspan><tspan opacity="0.6"'
        f' font-weight="700">{name}</tspan>\n'
        '    </text>\n'
        '  </symbol>'
    )


def apply_web(brand: dict, brand_dir: Path, ctx: Ctx) -> None:
    repo = WEB_DIR
    print(f"[web] {repo}")

    # 1. Page titles of the iframe / fullscreen templates.
    for html in sorted((repo / "public").glob("*.html")):
        s = html.read_text(encoding="utf-8")
        s = re.sub(r"<title>\s*Hyper[Ss]witch\s*</title>", f"<title>{brand['name']}</title>", s)
        ctx.write_text(html, s)

    # 2. "Powered by" logo in the SVG sprite (symbol id kept for compatibility).
    sprite = repo / "public" / "icons" / "orca.svg"
    s = sprite.read_text(encoding="utf-8")
    s2, cnt = re.subn(r'<symbol\s+id="powerd-by-hyper".*?</symbol>', powered_by_symbol(brand), s,
                      count=1, flags=re.S)
    if not cnt:
        print("  ! powerd-by-hyper symbol not found in orca.svg", file=sys.stderr)
    ctx.write_text(sprite, s2)

    # 3. User-visible text (locale strings incl. poweredBy, mandate texts,
    #    default collector name). JS API names are identifiers, never touched.
    n = rebrand_sources(repo, brand, ctx, with_links=True)
    print(f"  rewrote {n} string literal(s)")

    # 4. Sanity: default locale fallback must remain English.
    helper = repo / "src" / "LocaleStrings" / "LocaleStringHelper.res"
    if helper.exists() and not re.search(r"\|\s*_\s*=>\s*EN\b", helper.read_text(encoding="utf-8")):
        print("  ! LocaleStringHelper.res: default fallback is no longer EN", file=sys.stderr)


# ---------------------------------------------------------------------------

def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--brand", default=str(ROOT / "branding" / "brand.json"))
    ap.add_argument("--only", choices=["control-center", "web"])
    ap.add_argument("--dry-run", action="store_true", help="show what would change")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args(argv)

    brand_path = Path(args.brand)
    if not brand_path.is_absolute() and not brand_path.exists():
        brand_path = ROOT / brand_path
    brand = json.loads(brand_path.read_text(encoding="utf-8"))
    brand.setdefault("dashboard_name", brand["name"] + " Dashboard")
    brand_dir = brand_path.resolve().parent
    ctx = Ctx(args.dry_run, args.verbose)

    if args.only in (None, "control-center"):
        apply_control_center(brand, brand_dir, ctx)
    if args.only in (None, "web"):
        apply_web(brand, brand_dir, ctx)

    verb = "would change" if args.dry_run else "changed"
    print(f"{len(set(ctx.changed))} file(s) {verb}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
