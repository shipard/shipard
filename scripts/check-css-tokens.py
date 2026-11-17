#!/usr/bin/env python3
"""Kontrola CSS tokenů ve frontendu (#129 D14, #133 D10, D61, D71, #153 D90).

Čtyři kontroly nad celým stromem `frontend/src`.

**1. Nedefinované tokeny**

Token `--shpd-…` (aplikace) nebo `--dev-…` (vývojářský dashboard) použitý
ve `var(…)` musí být někde ve `frontend/src` definovaný — **s fallbackem
i bez**. Bez fallbacku prohlížeč vlastnost tiše zahodí (karta dialogu bez
pozadí, text bez barvy). S fallbackem tiše platí fallback a token, který
nikdy neexistoval, vypadá jako použitý design systém: tak žil
`--shpd-font-mono` v devíti komponentách a dřív
`--shpd-color-alert-danger-bg`. Skript to hlídá za celý strom, ne jen za
staged soubory: je rychlý a nedefinovaný token vznikne i smazáním definice
v jiném souboru.

Co se počítá jako **definice**:

- `--shpd-x: hodnota` kdekoli — CSS, `style="…"` v markupu, klíč JS objektu
  (`'--shpd-x': …`);
- přiřazení do JS objektu `tokens['--shpd-x'] = …` (tokeny vlastního
  vzhledu v `utils/themeColor.js`, např. `--shpd-sidebar-bg-image`);
- Svelte direktiva `style:--shpd-x={…}` (`FormSection.svelte`);
- `setProperty('--shpd-x', …)` v JS.

Co se počítá jako **použití**: každé `var(--shpd-x)` i `var(--shpd-x, …)`.
U `var(--a, var(--b))` se hlídají oba tokeny.

Seznam výjimek tahle kontrola nemá. Token, který se definuje způsobem, jaký
skript nepozná, se řeší rozšířením vzoru definice, ne výjimkou.

**2. Tokeny hlášek jen v `Notice` a `PageBanner` (#133 D61)**

Rodina `--shpd-color-alert-*` (pozadí, text a rámeček tónu) **je** vzhled
hlášky. Kdo ji použije jinde, kopíruje `Notice` — a kopie se rozejde:
jiný rámeček, jiné zaoblení, tmavý vzhled napůl. Proto smí být rodina
**použitá** (`var(…)`) jen v `components/ui/Notice.svelte` (hláška
v obsahu) a `components/ui/PageBanner.svelte` (pruh nad obsahem); definice
ve `variables.css` platí dál. Chyba nebo varování u jednoho pole, položky
nebo řádku není hláška — zůstává textem u prvku a tón nese jen barvou
textu (`--shpd-color-danger`, `--shpd-color-warning`).

Jediná výjimka je vyjmenovaná níže v pojmenované konstantě i s důvodem:
vývojářský dashboard s vlastním vzhledem.

**3. Hex barvy jen v souborech s definicemi tokenů (#133 D71)**

Barva zapsaná hexem (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`) patří jen do
souborů, které tokeny definují: `styles/variables.css` a `dev/theme.css`.
Komponenta bere token — hex v ní se nepřebarví v tmavém vzhledu a rozejde
se s paletou. Platí i pro fallback ve `var(--x, #hex)`: token je
definovaný (kontrola 1), fallback je jen druhá, časem zastaralá hodnota.

Kde se hledá:

- soubory `.css` a bloky `<style>` v `.svelte` — jen v **hodnotách
  deklarací** (`vlastnost: hodnota`), takže selektor `#app` ani kotva
  v `url(…)` nejsou nález;
- atribut `style="…"` / `style={…}` a direktiva `style:vlastnost=…`
  v markupu `.svelte`.

Komentáře (`/* */`, `<!-- -->`, `//` ve výrazu atributu) se vynechávají
dřív, než se hledá: odkaz na issue `#133` je formálně platný hex.

Výjimky jsou po souborech v konstantě `HEX_EXEMPT`, každá s důvodem.

Vědomá mez: hex v JS datech mimo CSS — výchozí barvy v `<script>`
(`defaultColor = '#000000'`), řetězec stylu složený ve skriptu
(`'background:#00345C'`), soubory `.js` — kontrola **nehlídá**. Rozlišit
barvu od jiného řetězce s mřížkou tam spolehlivě nejde a barvy v datech
(vlastní vzhled) jsou legitimní.

**4. Tónový `color-mix` jen s výjimkou (#153 D90)**

`color-mix(…)` s tónovým tokenem (`--shpd-color-danger…`, `-warning…`,
`-success…`, `-info…`) vyrábí tónovaný podklad na místě. Text na něm
kontrast nedrží — `danger-text` na `color-mix(danger 14 %)` má ve světlém
vzhledu 3,9 : 1 — a žádný skript ho nespočítá, protože barva vzniká až
v prohlížeči. Text na tónovaném podkladu proto kreslí jen sdílený `Badge`
(dvojice tokenů `state-…-bg` / `-text`, hlídá `check-contrast.py`).

Hledá se tam, kde hex barvy: hodnoty deklarací v `.css` a blocích
`<style>`, atribut `style` a direktiva `style:` v markupu, mimo komentáře.
Tónovaná plocha **bez textu** smí zůstat jen jako výjimka po souborech
v konstantě `TONE_MIX_EXEMPT` s důvodem. `primary` s nízkým podílem jako
podklad cíle přetažení souboru (`AttachmentPanel`, `Dashboard`,
`MailUploadModal`) tón není a kontrola ho nehlídá.

Použití:
    python3 scripts/check-css-tokens.py

Volá se z `pre-commit` hooku. Exit 1 = nedefinovaný token (výpis
token → soubory na stderr), token hlášky mimo povolené soubory, hex
barva mimo definice nebo tónový `color-mix` (vše výpis soubor:řádek
hodnota na stderr); běh vypíše všechny druhy nálezů najednou.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FRONTEND_SRC = REPO_ROOT / "frontend" / "src"

SCAN_SUFFIXES = {".css", ".svelte", ".js"}
SKIP_DIRS = {"node_modules"}

TOKEN = r"--(?:shpd|dev)-[A-Za-z0-9_-]+"

DEFINITION_RES = (
    # CSS deklarace, inline style, klíč JS objektu (i v uvozovkách)
    re.compile(rf"({TOKEN})['\"]?\s*:"),
    # JS: přiřazení do objektu — tokens['--x'] = …
    re.compile(rf"\[\s*['\"]({TOKEN})['\"]\s*\]\s*="),
    # Svelte direktiva style:--x={…}
    re.compile(rf"style:({TOKEN})="),
    # JS: el.style.setProperty('--x', …)
    re.compile(rf"setProperty\(\s*['\"]({TOKEN})['\"]"),
)

# Skupina 2 = `,` (použití s fallbackem) nebo `)` (bez). Vnořené
# `var(--a, var(--b))` najde finditer jako dvě použití.
USAGE_RE = re.compile(rf"var\(\s*({TOKEN})\s*([,)])")

# --- Tokeny hlášek (#133 D61) ---------------------------------------------

ALERT_USAGE_RE = re.compile(r"var\(\s*(--shpd-color-alert-[A-Za-z0-9_-]+)")

# Jediná dvě místa, která rodinu smí použít: hláška v obsahu a pruh nad
# obsahem (docs/design-system.md §8 Hlášky a pruhy).
ALERT_OWNERS = frozenset({
    "frontend/src/components/ui/Notice.svelte",
    "frontend/src/components/ui/PageBanner.svelte",
})

# Vývojářský dashboard má vlastní vzhled mimo design systém aplikace
# (#133 D12) — `Notice` aplikace nepoužívá, hlášky si kreslí v `dev.css`.
ALERT_EXEMPT_DIRS = (
    "frontend/src/dev/",
)

ALERT_ADVICE = (
    "hláška v obsahu = `Notice`, pruh nad obsahem = `PageBanner`"
    " (docs/design-system.md §8)"
)

# --- Hex barvy (#133 D71) ---------------------------------------------------

# Delší tvar první, za barvou nesmí pokračovat identifikátor (`#abcdefg`).
HEX_RE = re.compile(
    r"#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![0-9A-Za-z_-])"
)

HEX_SUFFIXES = {".css", ".svelte"}

# Soubory s definicemi tokenů — jediné místo, kde hex žije.
HEX_DEFINITION_FILES = frozenset({
    "frontend/src/styles/variables.css",
    "frontend/src/dev/theme.css",
})

# Výjimky po souborech, každá s důvodem (D71). Čtyři komponenty vlastního
# vzhledu dnes mají hex jen ve `<script>`, kam kontrola nesahá — výjimka
# říká, že barva jako data smí i do jejich stylu (náhled, vzorek).
HEX_EXEMPT = {
    "frontend/src/components/settings/ThemeField.svelte":
        "výchozí a náhledové barvy vlastního vzhledu jsou data",
    "frontend/src/components/settings/DsThemeField.svelte":
        "výchozí barva vlastního vzhledu zdroje dat je data",
    "frontend/src/components/settings/ThemeSwatches.svelte":
        "vzorky barev vlastního vzhledu jsou data",
    "frontend/src/components/settings/ColorField.svelte":
        "pole pro výběr barvy pracuje s hodnotou barvy",
    "frontend/src/components/ui/SandboxedHtml.svelte":
        "dokument cizího e-mailu v iframe má bílý papír i v tmavém vzhledu",
}

HEX_ADVICE = (
    "bílý text na ploše → `--shpd-color-text-on-fill`, jinak token"
    " z `styles/variables.css` (dashboard `dev/theme.css`); fallback ve"
    " `var()` nepiš (docs/design-system.md §6)"
)

# --- Tónový color-mix (#153 D90) --------------------------------------------

COLOR_MIX_RE = re.compile(r"color-mix\(")
TONE_TOKEN_RE = re.compile(r"--shpd-color-(?:danger|warning|success|info)(?![A-Za-z0-9_])[A-Za-z0-9_-]*")

# Výjimky po souborech, každá s důvodem — jen tónovaná plocha bez textu.
TONE_MIX_EXEMPT: dict[str, str] = {}

TONE_MIX_ADVICE = (
    "text na tónovaném podkladu kreslí jen sdílený `Badge`"
    " (docs/design-system.md §5); tónovaná plocha bez textu = výjimka"
    " s důvodem v `TONE_MIX_EXEMPT`"
)

CSS_COMMENT_RE = re.compile(r"/\*.*?\*/", re.S)
HTML_COMMENT_RE = re.compile(r"<!--.*?-->", re.S)
URL_RE = re.compile(r"url\([^)]*\)")
STYLE_BLOCK_RE = re.compile(r"(<style\b[^>]*>)(.*?)(</style>)", re.S)
SCRIPT_BLOCK_RE = re.compile(r"<script\b[^>]*>.*?</script>", re.S)
# `style=` nebo `style:vlastnost=` v markupu; hodnotu čte `attr_value_end`.
STYLE_ATTR_RE = re.compile(r"(?<![\w:-])style(?::[\w-]+)?\s*=\s*")
# `//` komentář ve výrazu atributu — ne `http://`.
LINE_COMMENT_RE = re.compile(r"(?<![:\w])//[^\n]*")


def blank(text: str, rx: re.Pattern[str]) -> str:
    """Nahradí shody mezerami; konce řádků a délku zachová (čísla řádků)."""
    return rx.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def css_declaration_values(css: str):
    """Hodnoty deklarací v CSS bez komentářů: dvojice (posun, text hodnoty).

    Úsek ukončený `;` nebo `}` (nebo koncem textu), který obsahuje `:`, je
    deklarace; úsek ukončený `{` je selektor nebo hlavička at-pravidla
    (`a:hover {`, `#app {`, `@media (min-width: 48rem) {`) a přeskakuje se.
    """
    start = 0
    for m in re.finditer(r"[{};]", css):
        if m.group(0) != "{":
            segment = css[start:m.start()]
            colon = segment.find(":")
            if colon != -1:
                yield start + colon + 1, segment[colon + 1:]
        start = m.end()
    tail = css[start:]
    colon = tail.find(":")
    if colon != -1:
        yield start + colon + 1, tail[colon + 1:]


def attr_value_end(text: str, pos: int) -> int:
    """Konec hodnoty atributu začínající na `pos` (uvozovky nebo `{…}`)."""
    if pos >= len(text):
        return pos
    opener = text[pos]
    if opener in "\"'":
        end = text.find(opener, pos + 1)
        return len(text) if end == -1 else end + 1
    if opener == "{":
        depth = 0
        for i in range(pos, len(text)):
            if text[i] == "{":
                depth += 1
            elif text[i] == "}":
                depth -= 1
                if depth == 0:
                    return i + 1
        return len(text)
    return pos


def style_spans(path: Path, text: str) -> list[tuple[int, str]]:
    """Hodnoty stylů v CSS souboru nebo komponentě: seznam (posun, text).

    Hodnoty deklarací v `.css` a blocích `<style>`, atribut `style`
    a direktiva `style:` v markupu — bez komentářů a `url(…)`.
    """
    spans: list[tuple[int, str]] = []  # (posun v souboru, prohledávaný text)

    def add_css(offset: int, css: str) -> None:
        css = blank(blank(css, CSS_COMMENT_RE), URL_RE)
        spans.extend((offset + pos, value) for pos, value in css_declaration_values(css))

    if path.suffix == ".css":
        add_css(0, text)
    else:
        for m in STYLE_BLOCK_RE.finditer(text):
            add_css(m.start(2), m.group(2))
        markup = blank(blank(blank(text, STYLE_BLOCK_RE), SCRIPT_BLOCK_RE), HTML_COMMENT_RE)
        for m in STYLE_ATTR_RE.finditer(markup):
            end = attr_value_end(markup, m.end())
            value = blank(blank(blank(markup[m.end():end], CSS_COMMENT_RE), LINE_COMMENT_RE), URL_RE)
            spans.append((m.end(), value))
    return spans


def hex_colors(text: str, spans: list[tuple[int, str]]) -> list[tuple[int, str]]:
    """Hex barvy v hodnotách stylů: seznam (řádek, hodnota)."""
    found = [
        (text.count("\n", 0, offset + m.start()) + 1, m.group(0))
        for offset, value in spans
        for m in HEX_RE.finditer(value)
    ]
    return sorted(found)


def tone_mixes(text: str, spans: list[tuple[int, str]]) -> list[tuple[int, str]]:
    """`color-mix(…)` s tónovým tokenem: seznam (řádek, token)."""
    found = []
    for offset, value in spans:
        for m in COLOR_MIX_RE.finditer(value):
            depth, end = 0, len(value)
            for i in range(m.end() - 1, len(value)):
                if value[i] == "(":
                    depth += 1
                elif value[i] == ")":
                    depth -= 1
                    if depth == 0:
                        end = i
                        break
            tone = TONE_TOKEN_RE.search(value, m.end(), end)
            if tone:
                found.append((text.count("\n", 0, offset + m.start()) + 1, tone.group(0)))
    return sorted(found)


def scannable(path: Path) -> bool:
    if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
        return False
    return not any(part in SKIP_DIRS for part in path.parts)


def alert_family_allowed(rel: str) -> bool:
    """Smí soubor (cesta od kořene repozitáře) použít `--shpd-color-alert-*`?"""
    if rel in ALERT_OWNERS:
        return True
    return rel.startswith(ALERT_EXEMPT_DIRS)


def main() -> int:
    defined: set[str] = set()
    used: dict[str, set[str]] = {}
    with_fallback: set[str] = set()
    alert_misuse: list[tuple[str, int, str]] = []
    hex_misuse: list[tuple[str, int, str]] = []
    tone_mix_misuse: list[tuple[str, int, str]] = []

    for path in sorted(FRONTEND_SRC.rglob("*")):
        if not scannable(path):
            continue
        text = path.read_text(encoding="utf-8")
        for rx in DEFINITION_RES:
            defined.update(m.group(1) for m in rx.finditer(text))
        rel = path.relative_to(REPO_ROOT).as_posix()
        for m in USAGE_RE.finditer(text):
            used.setdefault(m.group(1), set()).add(rel)
            if m.group(2) == ",":
                with_fallback.add(m.group(1))
        if not alert_family_allowed(rel):
            for lineno, line in enumerate(text.splitlines(), start=1):
                alert_misuse.extend(
                    (rel, lineno, m.group(1)) for m in ALERT_USAGE_RE.finditer(line)
                )
        if path.suffix in HEX_SUFFIXES:
            spans = style_spans(path, text)
            if rel not in HEX_DEFINITION_FILES and rel not in HEX_EXEMPT:
                hex_misuse.extend((rel, lineno, value) for lineno, value in hex_colors(text, spans))
            if rel not in TONE_MIX_EXEMPT:
                tone_mix_misuse.extend((rel, lineno, token) for lineno, token in tone_mixes(text, spans))

    failed = False

    undefined = {token: files for token, files in used.items() if token not in defined}
    if undefined:
        print("Nedefinované CSS tokeny:\n", file=sys.stderr)
        for token in sorted(undefined):
            note = "  (má fallback — ten skrývá chybu)" if token in with_fallback else ""
            print(f"  {token}{note}", file=sys.stderr)
            for rel in sorted(undefined[token]):
                print(f"      {rel}", file=sys.stderr)
        print(
            "\nNahraď token existujícím (frontend/src/styles/variables.css,"
            " frontend/src/dev/theme.css), nebo ho definuj.",
            file=sys.stderr,
        )
        failed = True

    if alert_misuse:
        if failed:
            print(file=sys.stderr)
        print("Tokeny hlášek mimo Notice a PageBanner:\n", file=sys.stderr)
        for rel, lineno, token in alert_misuse:
            print(f"  {rel}:{lineno}  {token}", file=sys.stderr)
        print(f"\nRada: {ALERT_ADVICE}.", file=sys.stderr)
        failed = True

    if hex_misuse:
        if failed:
            print(file=sys.stderr)
        print("Hex barvy mimo soubory s definicemi tokenů:\n", file=sys.stderr)
        for rel, lineno, value in hex_misuse:
            print(f"  {rel}:{lineno}  {value}", file=sys.stderr)
        print(f"\nRada: {HEX_ADVICE}.", file=sys.stderr)
        failed = True

    if tone_mix_misuse:
        if failed:
            print(file=sys.stderr)
        print("Tónový color-mix v komponentě:\n", file=sys.stderr)
        for rel, lineno, token in tone_mix_misuse:
            print(f"  {rel}:{lineno}  color-mix(… {token} …)", file=sys.stderr)
        print(f"\nRada: {TONE_MIX_ADVICE}.", file=sys.stderr)
        failed = True

    if failed:
        return 1

    print(f"CSS tokeny v pořádku: {len(defined)} definic, {len(used)} použitých tokenů.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
