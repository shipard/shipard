#!/usr/bin/env python3
"""Kontrola jednotek a breakpointů v CSS frontendu (#133 D1–D2, D42, D43, D46, D47; #134 D10).

Mezery, odsazení, zaoblení, velikost písma a pevné rozměry se píší v rem
nebo tokenem (`--shpd-space-*`, `--shpd-radius-*`, `--shpd-font-size-*`),
ne v px — px patří jen čarám, okrajům, obrysům, stínům a stavovému /
aktivnímu proužku (#133 D1, D46). Skript hlídá v celém `frontend/src`
(`.css` a `.svelte`: blok `<style>`, atribut `style="…"` i direktiva
`style:vlastnost=…`), ne jen ve staged souborech — stejně jako
`check-css-tokens.py`:

- mezery a písmo (#134 D10): `padding*`, `margin*`, `gap`, `row-gap`,
  `column-gap`, `font-size`, `border-radius*`;
- rozměry (#133 D46, D47): `width`, `height`, `min-*` / `max-*`,
  `inline-size`, `block-size` (i `min-` / `max-`), `flex-basis` a zkratku
  `flex` (px v ní je `flex-basis`) — i uvnitř `min()`, `clamp()`, `calc()`;
- rozměrové propy komponent: atribut `width` / `height` s px na značce
  s velkým počátečním písmenem (`<Modal width="480px">`); `<svg width="16">`
  a `<img>` projdou;
- layout tokeny: custom property `--shpd-*-width*` / `--shpd-*-height*`
  s px ve `styles/variables.css`.

Co se **nehlídá**: ostatní custom properties (`--x: 4px` je definice
tokenu), komentáře `/* … */`, `0px` (projde, ale piš `0`), rozměry podle
okna (`vh`, `dvh`, `vw` — nejsou px), ikony (`em`), atributy `width` /
`height` na `<svg>` a `<img>`, vlastnosti mimo seznam (polohování, okraje,
stíny, `grid-template-*`) a JavaScript v `<script>` — tedy ani výchozí
hodnota propu (`width = '40rem'`), ani styl složený v JS (`colStyle`).
Čáry jinde než v rozměru nejsou rozměry a neprojdou sem vůbec:
`border-width`, `outline-width`, `stroke-width`, `column-rule-width`
i `line-height` končí na hlídaný název, ale lookbehind v `DECLARATION_RE`
(před názvem nesmí být písmeno ani pomlčka) je vyřadí — ověřeno testem
regulárního výrazu při zavedení (#138 task 4).

Výjimka jen značkou na stejném řádku s důvodem:

    padding: 1px; /* px-ok: optické zarovnání ikony na základní čáru */
    height: 1px; /* px-ok: čára */

U propu komponenty smí být značka i jako `<!-- px-ok: důvod -->`. Značka bez
textu důvodu je chyba. Každá výjimka patří do popisu commitu.

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

Volá se z `pre-commit` hooku. Exit 1 = px v hlídané vlastnosti, propu
komponenty nebo layout tokenu, nebo breakpoint mimo povolené hodnoty (výpis
`soubor:řádek: deklarace` na stderr). Pravidla a tokeny:
docs/design-system.md → Jednotky a mezery, breakpointy → Layout konvence →
Responzivita.
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
VARIABLES_CSS = FRONTEND_SRC / "styles" / "variables.css"

# Hlídané vlastnosti (#134 D10; rozměry #133 D46, D47). Logické varianty
# (padding-block-start…, inline-size…) patří k těm fyzickým. Px ve zkratce
# `flex` je `flex-basis`.
PROPERTY = r"""(?:
    (?:padding|margin)(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?
  | gap | row-gap | column-gap
  | font-size
  | border-radius
  | border-(?:top|bottom)-(?:left|right)-radius
  | border-(?:start|end)-(?:start|end)-radius
  | (?:min-|max-)?(?:width|height|inline-size|block-size)
  | flex-basis | flex
)"""

# Deklarace `vlastnost: hodnota` v CSS textu. Lookbehind vyřadí custom
# properties (`--shpd-space-xs: 4px`, `--shpd-sidebar-width: …`) i delší
# názvy: `scroll-padding`, a u rozměrů hlavně čáry — `border-width`,
# `outline-width`, `stroke-width`, `column-rule-width` — a `line-height`.
# `flex-direction`, `flex-grow` a spol. neprojdou přes `\s*:` za `flex`.
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

# Rozměrový prop komponenty v šabloně (#133 D47): `width="480px"`,
# `height={'90vh'}`. Lookbehind vyřadí `style:width=`, `stroke-width=`,
# `data-width=`; o komponentu jde jen u značky s velkým počátečním písmenem.
SIZE_PROP_RE = re.compile(
    r"""(?<![\w:.-])(?P<name>width|height)\s*=\s*
        (?: "(?P<dq>[^"]*)" | '(?P<sq>[^']*)' | \{(?P<br>[^}]*)\} )""",
    re.VERBOSE,
)
# Otevírací značka — poslední před atributem je ta, ke které atribut patří
# (hledat `<Tag …>` nejde: `>` je i v `onClose={() => …}`).
TAG_OPEN_RE = re.compile(r"<(?P<name>[A-Za-z][\w.:-]*)")

# Layout token (#133 D46, D47): `--shpd-sidebar-width`, `--shpd-header-height`,
# `--shpd-sidebar-width-collapsed` — jen ve `styles/variables.css`.
LAYOUT_TOKEN_RE = re.compile(
    r"(?<![\w-])(?P<name>--shpd-(?:[\w]+-)*(?:width|height)(?:-[\w-]+)?)\s*:\s*(?P<value>[^;{}]*)"
)

COMMENT_RE = re.compile(r"/\*.*?\*/", re.DOTALL)
MARKER_RE = re.compile(r"/\*\s*px-ok(?::[ \t]*(?P<reason>.*?))?\s*\*/")
# Značka u propu komponenty smí být i HTML komentář.
HTML_MARKER_RE = re.compile(r"<!--\s*px-ok(?::[ \t]*(?P<reason>.*?))?\s*-->")

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
    findings.extend(check_size_props(path, text, lines))
    findings.extend(check_layout_tokens(path, text, lines))
    return sorted(set(findings))


def marker_allows(line: str, lineno: int, findings: list[tuple[int, str]]) -> bool:
    """Značka px-ok na řádku (CSS i HTML komentář); bez důvodu je nález."""
    marker = MARKER_RE.search(line) or HTML_MARKER_RE.search(line)
    if marker is None:
        return False
    if not marker.group("reason"):
        findings.append((lineno, "značka px-ok bez důvodu"))
        return False
    return True


def check_size_props(path: Path, text: str, lines: list[str]) -> list[tuple[int, str]]:
    """Nálezy: prop `width` / `height` s px na komponentě (#133 D47)."""
    if path.suffix != ".svelte":
        return []
    markup = SCRIPT_BLOCK_RE.sub(lambda m: blank(m.group(0)), text)
    markup = STYLE_BLOCK_RE.sub(lambda m: blank(m.group(0)), markup)
    findings: list[tuple[int, str]] = []
    for attr in SIZE_PROP_RE.finditer(markup):
        value = next(attr.group(g) for g in ("dq", "sq", "br") if attr.group(g) is not None)
        if not has_px(value):
            continue
        tags = list(TAG_OPEN_RE.finditer(markup, 0, attr.start()))
        if not tags or not tags[-1].group("name")[0].isupper():
            continue
        lineno = line_of(text, attr.start())
        if marker_allows(lines[lineno - 1], lineno, findings):
            continue
        attribute = " ".join(attr.group(0).split())
        findings.append((lineno, f"<{tags[-1].group('name')} {attribute}> — prop komponenty"))
    return findings


def check_layout_tokens(path: Path, text: str, lines: list[str]) -> list[tuple[int, str]]:
    """Nálezy: layout token `--shpd-*-width*` / `--shpd-*-height*` s px (#133 D47)."""
    if path != VARIABLES_CSS:
        return []
    findings: list[tuple[int, str]] = []
    for token in LAYOUT_TOKEN_RE.finditer(strip_comments(text)):
        if not has_px(token.group("value")):
            continue
        lineno = line_of(text, token.start())
        if marker_allows(lines[lineno - 1], lineno, findings):
            continue
        findings.append((lineno, f"{' '.join(token.group(0).split())} — layout token"))
    return findings


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
        print("Px v mezerách, odsazení, zaoblení, písmu nebo rozměrech (#133 D1, D46):\n", file=sys.stderr)
        print("\n".join(px_problems), file=sys.stderr)
        print(
            "\nPřeveď na rem (px / 16) nebo token --shpd-space-* / --shpd-radius-* /"
            " --shpd-font-size-* (docs/design-system.md → Jednotky a mezery)."
            " V px zůstávají jen čáry, okraje, obrysy, stíny a proužek — výjimka"
            " jen se značkou /* px-ok: důvod */ na stejném řádku.\n",
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

    print(f"CSS jednotky, rozměry a breakpointy v pořádku: {scanned} souborů prošlo.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
