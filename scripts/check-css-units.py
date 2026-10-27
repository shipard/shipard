#!/usr/bin/env python3
"""Kontrola jednotek a breakpointů v CSS frontendu (#133 D1–D2, D42, D43, D47; #134 D10).

Mezery, odsazení, zaoblení a velikost písma se píší v rem nebo tokenem
(`--shpd-space-*`, `--shpd-radius-*`, `--shpd-font-size-*`), ne v px — px
patří jen okrajům, stínům a stavovému proužku (#133 D1). Skript hlídá
deklarace vlastností `padding*`, `margin*`, `gap`, `row-gap`, `column-gap`,
`font-size` a `border-radius*` v celém `frontend/src` (`.css` a `.svelte`:
blok `<style>`, atribut `style="…"` i direktiva `style:vlastnost=…`), ne jen
ve staged souborech — stejně jako `check-css-tokens.py`.

Co se **nehlídá**: custom properties (`--x: 4px` je definice tokenu),
komentáře `/* … */`, JavaScript v `<script>`, `0px` (projde, ale piš `0`)
a vlastnosti mimo seznam (šířky, polohování, okraje, stíny).

Výjimka jen značkou na stejném řádku s důvodem:

    padding: 1px; /* px-ok: optické zarovnání ikony na základní čáru */

Značka bez textu důvodu je chyba. Každá výjimka patří do popisu commitu.

Breakpointy (#133 D42, D43, D47): `@media` s rysem šířky (`width`,
`min-width`, `max-width`, i rozsahový zápis `width <= …`) smí mít jen hodnotu
shellu — `48rem` v aplikaci, `68.75rem` ve vývojářském dashboardu
(`frontend/src/dev/`); rysy bez šířky (`hover`, `pointer`, `prefers-*`,
`print`) projdou. `@container` smí mít jen prahy `30rem` a `36rem`
(pojmenovaný kontejner před závorkou je v pořádku). JS konstanta
`MOBILE_BREAKPOINT` v `stores/layout.svelte.js` musí být řetězec rovný
hodnotě shellu, bez další jednotky za dosazením. Povolené hodnoty jsou
konstanty nahoře v tomto skriptu; jiná hodnota znamená nové rozhodnutí
v #133 — značka `px-ok` tu neplatí.

Použití:
    python3 scripts/check-css-units.py

Volá se z `pre-commit` hooku. Exit 1 = px v hlídané vlastnosti nebo
breakpoint mimo povolené hodnoty (výpis `soubor:řádek: deklarace` na
stderr). Pravidla a tokeny: docs/design-system.md → Jednotky a mezery,
breakpointy → Layout konvence → Responzivita.
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

# Breakpointy (#133 D42, D43, D47) — jediné místo s povolenými hodnotami.
# Nová hodnota = nové rozhodnutí v #133; značka px-ok na ně neplatí.
SHELL_BREAKPOINT = "48rem"  # D42: shell aplikace, totéž MOBILE_BREAKPOINT v JS
DEV_SHELL_BREAKPOINT = "68.75rem"  # D42: vývojářský dashboard (frontend/src/dev/)
CONTAINER_THRESHOLDS = ("30rem", "36rem")  # D43: úzký obsah, složené řádky dokladu
DEV_DIR = "dev"
LAYOUT_STORE = FRONTEND_SRC / "stores" / "layout.svelte.js"

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

# At-rule s preludem: `@media (max-width: 48rem) {`, `@container form-section (…) {`.
AT_RULE_RE = re.compile(r"@(?P<kind>media|container)\b(?P<prelude>[^{;]*)\{", re.IGNORECASE)
# Rys šířky v media query — `(max-width: …)`, `(width <= …)`, `(… >= width)`.
WIDTH_FEATURE_RE = re.compile(
    r"(?<![\w-])(?:min-|max-)?width\s*[:<>=]|[<>=]\s*width(?![\w-])", re.IGNORECASE
)
# Délka v preludu at-rule (jen jednotky, které mají v breakpointu smysl).
LENGTH_RE = re.compile(
    r"(?<![\w.-])(?P<number>\d*\.?\d+)(?P<unit>px|rem|em|vw|vh|ch)(?![\w-])", re.IGNORECASE
)
# JS konstanta shellu — hodnota až po `;` nebo konec řádku (komentář za ní).
MOBILE_BREAKPOINT_RE = re.compile(r"MOBILE_BREAKPOINT\s*=\s*(?P<value>[^;\n]*)")
# Dosazení konstanty s jednotkou navíc (`${MOBILE_BREAKPOINT}px`).
BREAKPOINT_UNIT_RE = re.compile(r"\$\{MOBILE_BREAKPOINT\}(?P<unit>[a-z]+)")

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


def normalize_length(number: str, unit: str) -> str:
    """`48.0rem` → `48rem`, `68.75rem` zůstane — porovnává se s konstantami."""
    return f"{float(number):g}{unit.lower()}"


def is_dev(path: Path) -> bool:
    return path.relative_to(FRONTEND_SRC).parts[0] == DEV_DIR


def check_breakpoints(path: Path, text: str) -> list[tuple[int, str]]:
    """Nálezy `(řádek, hláška)`: `@media` s rysem šířky mimo hodnotu shellu,
    `@container` mimo prahy D43. Značka px-ok se tu neuplatní."""
    shell = DEV_SHELL_BREAKPOINT if is_dev(path) else SHELL_BREAKPOINT
    findings: list[tuple[int, str]] = []
    for region in css_regions(path, text):
        if region.prop is not None:
            continue
        for rule in AT_RULE_RE.finditer(region.text):
            kind = rule.group("kind").lower()
            prelude = " ".join(rule.group("prelude").split())
            if kind == "media":
                if not WIDTH_FEATURE_RE.search(prelude):
                    continue
                allowed: tuple[str, ...] = (shell,)
            else:
                allowed = CONTAINER_THRESHOLDS
            lengths = [normalize_length(m.group("number"), m.group("unit")) for m in LENGTH_RE.finditer(prelude)]
            if lengths and all(length in allowed for length in lengths):
                continue
            lineno = line_of(text, region.start + rule.start())
            findings.append((lineno, f"@{kind} {prelude} — povolené: {', '.join(allowed)}"))
    return findings


def check_layout_store() -> list[str]:
    """Hlášky k JS konstantě shellu: musí být řetězec rovný SHELL_BREAKPOINT."""
    rel = LAYOUT_STORE.relative_to(REPO_ROOT)
    expected = f"'{SHELL_BREAKPOINT}'"
    if not LAYOUT_STORE.is_file():
        return [f"  {rel}: soubor chybí — MOBILE_BREAKPOINT = {expected} nemá kde být"]
    text = LAYOUT_STORE.read_text(encoding="utf-8")
    problems: list[str] = []
    match = MOBILE_BREAKPOINT_RE.search(text)
    if match is None:
        problems.append(f"  {rel}: chybí konstanta MOBILE_BREAKPOINT = {expected}")
    else:
        value = re.sub(r"\s*//.*$", "", match.group("value")).strip()
        if value not in (expected, f'"{SHELL_BREAKPOINT}"'):
            lineno = line_of(text, match.start())
            problems.append(f"  {rel}:{lineno}: MOBILE_BREAKPOINT = {value} — musí být {expected} (řetězec, D42)")
    for match in BREAKPOINT_UNIT_RE.finditer(text):
        lineno = line_of(text, match.start())
        problems.append(f"  {rel}:{lineno}: {match.group(0)} — jednotka je už v konstantě")
    return problems


def main() -> int:
    scanned = 0
    px_problems: list[str] = []
    breakpoint_problems: list[str] = []
    for path in sorted(FRONTEND_SRC.rglob("*")):
        if not scannable(path):
            continue
        scanned += 1
        text = path.read_text(encoding="utf-8")
        rel = path.relative_to(REPO_ROOT)
        px_problems.extend(f"  {rel}:{lineno}: {message}" for lineno, message in check_file(path, text))
        breakpoint_problems.extend(
            f"  {rel}:{lineno}: {message}" for lineno, message in check_breakpoints(path, text)
        )
    breakpoint_problems.extend(check_layout_store())

    if px_problems:
        print("Px v mezerách, odsazení, zaoblení nebo písmu (#133 D1):\n", file=sys.stderr)
        print("\n".join(px_problems), file=sys.stderr)
        print(
            "\nPřeveď na rem (px / 16) nebo token --shpd-space-* / --shpd-radius-* /"
            " --shpd-font-size-* (docs/design-system.md → Jednotky a mezery)."
            " Výjimka jen se značkou /* px-ok: důvod */ na stejném řádku.\n",
            file=sys.stderr,
        )
    if breakpoint_problems:
        print("Breakpointy mimo rozhodnutí #133 (D42, D43, D47):\n", file=sys.stderr)
        print("\n".join(breakpoint_problems), file=sys.stderr)
        print(
            f"\n@media s rysem šířky jen pro shell: {SHELL_BREAKPOINT} v aplikaci,"
            f" {DEV_SHELL_BREAKPOINT} ve frontend/src/{DEV_DIR}/; @container jen prahy"
            f" {' / '.join(CONTAINER_THRESHOLDS)}; MOBILE_BREAKPOINT v"
            f" {LAYOUT_STORE.relative_to(FRONTEND_SRC)} musí být řetězec '{SHELL_BREAKPOINT}'."
            " Jiná hodnota = nové rozhodnutí v #133 (docs/design-system.md →"
            " Responzivita); značka px-ok tu neplatí.",
            file=sys.stderr,
        )
    if px_problems or breakpoint_problems:
        return 1

    print(f"CSS jednotky a breakpointy v pořádku: {scanned} souborů prošlo.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
