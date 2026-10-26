#!/usr/bin/env python3
"""Kontrola jednotek v CSS frontendu (#133 D1–D2, #134 D10).

Mezery, odsazení, zaoblení a velikost písma se píší v rem nebo tokenem
(`--shpd-space-*`, `--shpd-radius-*`, `--shpd-font-size-*`), ne v px — px
patří jen okrajům, stínům a stavovému proužku (#133 D1). Skript hlídá
deklarace vlastností `padding*`, `margin*`, `gap`, `row-gap`, `column-gap`,
`font-size` a `border-radius*` v celém `frontend/src` (`.css` a `.svelte`:
blok `<style>`, atribut `style="…"` i direktiva `style:vlastnost=…`), ne jen
ve staged souborech — stejně jako `check-css-tokens.py`.

Co se **nehlídá**: custom properties (`--x: 4px` je definice tokenu),
komentáře `/* … */`, JavaScript v `<script>`, `0px` (projde, ale piš `0`)
a vlastnosti mimo seznam (šířky, polohování, okraje, stíny, `@media`).

Výjimka jen značkou na stejném řádku s důvodem:

    padding: 1px; /* px-ok: optické zarovnání ikony na základní čáru */

Značka bez textu důvodu je chyba. Každá výjimka patří do popisu commitu.

Použití:
    python3 scripts/check-css-units.py

Volá se z `pre-commit` hooku. Exit 1 = px v hlídané vlastnosti (výpis
`soubor:řádek: deklarace` na stderr). Pravidla a tokeny:
docs/design-system.md → Jednotky a mezery.
"""

from __future__ import annotations

import re
import sys
from dataclasses import dataclass
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FRONTEND_SRC = REPO_ROOT / "frontend" / "src"

SCAN_SUFFIXES = {".css", ".svelte"}
SKIP_DIRS = {"node_modules"}

# Hlídané vlastnosti (#134 D10). Logické varianty (padding-block-start…)
# patří k těm fyzickým.
PROPERTY = r"""(?:
    (?:padding|margin)(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?
  | gap | row-gap | column-gap
  | font-size
  | border-radius
  | border-(?:top|bottom)-(?:left|right)-radius
  | border-(?:start|end)-(?:start|end)-radius
)"""

# Deklarace `vlastnost: hodnota` v CSS textu. Lookbehind vyřadí custom
# properties (`--shpd-space-xs: 4px`) i delší názvy (`scroll-padding`).
DECLARATION_RE = re.compile(
    rf"(?<![\w-])(?P<prop>{PROPERTY})\s*:\s*(?P<value>[^;{{}}]*)",
    re.VERBOSE | re.IGNORECASE,
)

# Číslo v px uvnitř hodnoty — i v calc() a ve fallbacku var().
PX_RE = re.compile(r"(?<![\w.-])(?P<number>-?\d*\.?\d+)px(?![\w-])")

COMMENT_RE = re.compile(r"/\*.*?\*/", re.DOTALL)
MARKER_RE = re.compile(r"/\*\s*px-ok(?::[ \t]*(?P<reason>.*?))?\s*\*/")

SCRIPT_BLOCK_RE = re.compile(r"<script\b[^>]*>.*?</script>", re.DOTALL | re.IGNORECASE)
STYLE_BLOCK_RE = re.compile(r"<style\b[^>]*>(?P<css>.*?)</style>", re.DOTALL | re.IGNORECASE)
STYLE_ATTR_RE = re.compile(r"""\bstyle\s*=\s*(?P<q>["'])(?P<css>.*?)(?P=q)""", re.DOTALL)
STYLE_DIRECTIVE_RE = re.compile(
    rf"""\bstyle:(?P<prop>{PROPERTY})\s*=\s*
        (?: "(?P<dq>[^"]*)" | '(?P<sq>[^']*)' | \{{(?P<br>[^}}]*)\}} )""",
    re.VERBOSE | re.IGNORECASE,
)


@dataclass(frozen=True)
class Region:
    """Kus CSS v souboru: `text` začíná na absolutním offsetu `start`.

    `prop` je vyplněný jen u direktivy `style:vlastnost=…` — `text` je pak
    samotná hodnota, ne deklarace.
    """

    start: int
    text: str
    prop: str | None = None


def blank(text: str) -> str:
    """Nahradí text mezerami, zlomy řádků zachová (offsety i čísla řádků sedí)."""
    return re.sub(r"[^\n]", " ", text)


def strip_comments(css: str) -> str:
    return COMMENT_RE.sub(lambda m: blank(m.group(0)), css)


def css_regions(path: Path, text: str) -> list[Region]:
    """CSS oblasti souboru (bez komentářů, offsety do původního textu)."""
    if path.suffix == ".css":
        return [Region(0, strip_comments(text))]

    # .svelte: <script> pryč, pak <style> bloky, pak markup bez nich
    markup = SCRIPT_BLOCK_RE.sub(lambda m: blank(m.group(0)), text)
    regions: list[Region] = []
    for m in STYLE_BLOCK_RE.finditer(markup):
        regions.append(Region(m.start("css"), strip_comments(m.group("css"))))
    markup = STYLE_BLOCK_RE.sub(lambda m: blank(m.group(0)), markup)
    for m in STYLE_ATTR_RE.finditer(markup):
        regions.append(Region(m.start("css"), m.group("css")))
    for m in STYLE_DIRECTIVE_RE.finditer(markup):
        group = next(g for g in ("dq", "sq", "br") if m.group(g) is not None)
        regions.append(Region(m.start(group), m.group(group), prop=m.group("prop")))
    return regions


def line_of(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def scannable(path: Path) -> bool:
    if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
        return False
    return not any(part in SKIP_DIRS for part in path.parts)


def has_px(value: str) -> bool:
    return any(float(m.group("number")) != 0 for m in PX_RE.finditer(value))


def check_file(path: Path, text: str) -> list[tuple[int, str]]:
    """Nálezy `(řádek, hláška)` v jednom souboru."""
    lines = text.split("\n")
    allowed: set[int] = set()
    findings: list[tuple[int, str]] = []
    for region in css_regions(path, text):
        first = line_of(text, region.start)
        last = first + region.text.count("\n")
        for lineno in range(first, last + 1):
            marker = MARKER_RE.search(lines[lineno - 1])
            if marker is None:
                continue
            if marker.group("reason"):
                allowed.add(lineno)
            else:
                findings.append((lineno, "značka px-ok bez důvodu"))

        if region.prop is not None:
            if has_px(region.text):
                findings.append((first, f"style:{region.prop}={region.text.strip()}"))
            continue
        for decl in DECLARATION_RE.finditer(region.text):
            if not has_px(decl.group("value")):
                continue
            lineno = line_of(text, region.start + decl.start())
            if lineno in allowed:
                continue
            declaration = " ".join(decl.group(0).split())
            findings.append((lineno, declaration))
    return sorted(set(findings))


def main() -> int:
    scanned = 0
    problems: list[str] = []
    for path in sorted(FRONTEND_SRC.rglob("*")):
        if not scannable(path):
            continue
        scanned += 1
        text = path.read_text(encoding="utf-8")
        rel = path.relative_to(REPO_ROOT)
        problems.extend(f"  {rel}:{lineno}: {message}" for lineno, message in check_file(path, text))

    if problems:
        print("Px v mezerách, odsazení, zaoblení nebo písmu (#133 D1):\n", file=sys.stderr)
        print("\n".join(problems), file=sys.stderr)
        print(
            "\nPřeveď na rem (px / 16) nebo token --shpd-space-* / --shpd-radius-* /"
            " --shpd-font-size-* (docs/design-system.md → Jednotky a mezery)."
            " Výjimka jen se značkou /* px-ok: důvod */ na stejném řádku.",
            file=sys.stderr,
        )
        return 1

    print(f"CSS jednotky v pořádku: {scanned} souborů prošlo.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
