#!/usr/bin/env python3
"""Kontrast hlídaných dvojic tokenů v obou vzhledech (#153 D88–D95).

Tónové tokeny jsou laděné na pozadí stránky. Jinde — na sidebaru, na
tónovaném podkladu, na vybraném řádku a v tmavém vzhledu — kontrast
nestačil a nikdo to nehlídal: barva se změní v jednom tokenu a rozbije
dvojici o tři sekce dál. Skript proto počítá **dvojice popředí × pozadí**,
na kterých design systém stojí, z hodnot ve
`frontend/src/styles/variables.css`:

- světlý vzhled = blok `:root`;
- tmavý vzhled = `:root` přepsaný blokem `[data-theme="dark"]` (token,
  který tmavý blok nemá, dědí světlou hodnotu).

Odkazy `var(--x)` (i řetězené) se vyhodnocují **až v cílovém vzhledu** —
token, který ve světlém vzhledu drží hex a v tmavém odkazuje jinam, dá
v každém vzhledu jinou barvu. Hodnota je hex (`#rgb`, `#rrggbb`,
`#rrggbbaa`) nebo `rgb(…)` / `rgba(…)`; průhledné popředí se složí nad
pozadím dvojice. Relativní luminance a poměr kontrastu podle WCAG 2.x.

Prahy: text **4,5 : 1**, značka bez textu, ikona a obrys **3 : 1**.

Dvojice jsou v konstantě `PAIRS`, každá skupina s rozhodnutím, které ji
zavedlo. Dvojice, jejíž token v daném vzhledu neexistuje, se vynechá
a `--list` ji uvede. **Výjimky** jsou v konstantě `EXCEPTIONS` — každá
s důvodem a odkazem na rozhodnutí; výjimka, kterou dvojice už nepotřebuje
(práh splňuje), je nález, aby seznam nezastarával.

Co skript nehlídá:

- vývojářský dashboard (`dev/theme.css`, jen světlý vzhled, vlastní
  hodnoty tokenů);
- vlastní vzhled (barva sidebaru je data uživatele) — proto má značka na
  sidebaru obrys v `text-on-fill` (D88) a hlídá se dvojice značka × obrys;
- vykreslené barvy: `opacity`, `color-mix()` a pozadí složené přes předky
  změří jen prohlížeč. Tónový `color-mix` pod textem zakazuje
  `check-css-tokens.py` (D90).

Použití:
    python3 scripts/check-contrast.py          # kontrola, exit 1 při nálezu
    python3 scripts/check-contrast.py --list   # všechny dvojice s poměrem

Volá se z `pre-commit` hooku. Exit 1 = dvojice pod prahem (výpis popředí,
pozadí, vzhled, poměr, práh na stderr), zbytečná výjimka nebo token, který
nejde vyhodnotit.
"""

from __future__ import annotations

import re
import sys
from dataclasses import dataclass
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
VARIABLES_CSS = REPO_ROOT / "frontend" / "src" / "styles" / "variables.css"

PREFIX = "--shpd-color-"

TEXT = 4.5   # text
MARK = 3.0   # značka bez textu, ikona, obrys

THEMES = ("světlý", "tmavý")

PAGE = ("bg", "bg-hover", "bg-secondary")
TONES = ("danger", "warning", "success")
STATES = ("concept", "edit", "confirmed", "archive", "trash", "cancelled", "done", "error")


@dataclass(frozen=True)
class Pair:
    fg: str          # popředí — název tokenu bez `--shpd-color-`
    bg: str          # pozadí
    minimum: float   # práh
    why: str         # rozhodnutí nebo role dvojice


def _pairs() -> list[Pair]:
    pairs: list[Pair] = []

    def add(fgs, bgs, minimum, why):
        pairs.extend(Pair(fg, bg, minimum, why) for fg in fgs for bg in bgs)

    # Tón jako text a ikona na pozadí stránky (#133 D70).
    add([f"{t}-text" for t in TONES], PAGE, TEXT, "D70 tón jako text")
    # Značka bez textu (#133 D74); `info` v tmavém vzhledu D93.
    add([f"{t}-mark" for t in ("info", *TONES)], PAGE, MARK, "D74, D93 značka")
    # Bílý text na ploše (#133 D69).
    for tone in (*TONES, "primary"):
        add(["text-on-fill"], [tone, f"{tone}-hover"], TEXT, "D69 text na ploše")
    # Tečka na sidebaru, aktivní položce a chipu: plocha vůči obrysu (D88);
    # `success` = tečka klidného tónu na vybraném řádku seznamu (D99).
    add(["danger", "warning", "success"], ["text-on-fill"], MARK, "D88, D99 značka s obrysem")
    # Primary jako text, ikona a odkaz (D92); na `primary-soft` Badge `primary` (D90).
    add(["primary-text"], PAGE, TEXT, "D92 primary jako text")
    add(["primary-text"], ["primary-soft"], TEXT, "D90, D92 Badge primary")
    # Obrys fokusu (D95).
    add(["border-focus"], PAGE, MARK, "D95 obrys fokusu")
    # Běžný a sekundární text.
    add(["text", "text-secondary"], PAGE, TEXT, "text")
    # Vybraný řádek: tónové a šedé texty mají barvu běžného textu (D91).
    add(["text"], ["bg-selected", "bg-selected-hover"], TEXT, "D91 vybraný řádek")
    # Sidebar: text i ikona chyby v barvě textu sidebaru (D89).
    add(["text-sidebar"], ["bg-sidebar"], TEXT, "D89 sidebar")
    # Text na tónovaném podkladu jen přes Badge — dvojice stavů (D90).
    for state in STATES:
        add([f"state-{state}-text"], [f"state-{state}-bg"], TEXT, "D90 Badge")
    # Hláška Notice.
    for tone in ("info", "warning", "error", "success"):
        add([f"alert-{tone}-text"], [f"alert-{tone}-bg"], TEXT, "Notice")
    return pairs


PAIRS = _pairs()


@dataclass(frozen=True)
class Exemption:
    fg: str
    bg: str
    themes: tuple[str, ...]
    minimum: float
    reason: str


# Zdůvodněné výjimky. Dvojice z `PAIRS` uvedená tady není nález; dvojice mimo
# `PAIRS` (D94) je tu jen kvůli dokumentaci — `--list` ji vypíše s poměrem.
EXCEPTIONS = (
    Exemption(
        "danger-hover", "bg", ("tmavý",), MARK,
        "D94: okraj tlačítka `danger` pod kurzorem v tmavém vzhledu; tlačítko"
        " identifikuje text (6,47 : 1), ne okraj",
    ),
)

COMMENT_RE = re.compile(r"/\*.*?\*/", re.S)
DECLARATION_RE = re.compile(rf"{re.escape(PREFIX)}([A-Za-z0-9_-]+)\s*:\s*([^;]+);")
VAR_RE = re.compile(rf"^var\(\s*{re.escape(PREFIX)}([A-Za-z0-9_-]+)\s*\)$")
HEX_RE = re.compile(r"^#([0-9a-fA-F]{3,8})$")
RGB_RE = re.compile(r"^rgba?\(([^)]+)\)$")


class TokenError(Exception):
    """Token nejde vyhodnotit na barvu (neznámý zápis, cyklus odkazů)."""


def block(css: str, selector: str) -> dict[str, str]:
    """Tokeny `--shpd-color-*` z bloku se zadaným selektorem."""
    m = re.search(re.escape(selector) + r"\s*\{(.*?)\n\}", css, re.S)
    if m is None:
        raise SystemExit(f"check-contrast: ve variables.css chybí blok `{selector}`")
    return {name: value.strip() for name, value in DECLARATION_RE.findall(m.group(1))}


def load_themes() -> dict[str, dict[str, str]]:
    css = COMMENT_RE.sub("", VARIABLES_CSS.read_text(encoding="utf-8"))
    light = block(css, ":root")
    dark = {**light, **block(css, '[data-theme="dark"]')}
    return {"světlý": light, "tmavý": dark}


def parse_color(value: str) -> tuple[float, float, float, float]:
    """Hex nebo `rgb()` → (r, g, b v 0–255, alfa 0–1)."""
    m = HEX_RE.match(value)
    if m:
        digits = m.group(1)
        if len(digits) in (3, 4):
            digits = "".join(ch * 2 for ch in digits)
        if len(digits) not in (6, 8):
            raise TokenError(f"neplatný hex `{value}`")
        channels = [int(digits[i:i + 2], 16) for i in range(0, len(digits), 2)]
        alpha = channels[3] / 255 if len(channels) == 4 else 1.0
        return channels[0], channels[1], channels[2], alpha
    m = RGB_RE.match(value)
    if m:
        parts = [p for p in re.split(r"[\s,/]+", m.group(1).strip()) if p]
        if len(parts) not in (3, 4):
            raise TokenError(f"neplatné rgb `{value}`")

        def number(part: str, scale: float) -> float:
            return float(part[:-1]) / 100 * scale if part.endswith("%") else float(part)

        r, g, b = (number(p, 255) for p in parts[:3])
        alpha = number(parts[3], 1) if len(parts) == 4 else 1.0
        return r, g, b, alpha
    raise TokenError(f"hodnotu `{value}` skript neumí (hex, rgb(), var())")


def resolve(tokens: dict[str, str], name: str, seen: tuple[str, ...] = ()) -> tuple[float, float, float, float]:
    """Barva tokenu ve vzhledu; `var(--x)` se sleduje do hodnoty."""
    if name in seen:
        raise TokenError("cyklus odkazů " + " → ".join((*seen, name)))
    value = tokens[name]
    m = VAR_RE.match(value)
    if m:
        target = m.group(1)
        if target not in tokens:
            raise TokenError(f"`{PREFIX}{name}` odkazuje na nedefinovaný `{PREFIX}{target}`")
        return resolve(tokens, target, (*seen, name))
    return parse_color(value)


def luminance(rgb: tuple[float, float, float]) -> float:
    def channel(v: float) -> float:
        v /= 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4

    r, g, b = (channel(v) for v in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def contrast(tokens: dict[str, str], fg: str, bg: str) -> float:
    """Poměr kontrastu popředí vůči pozadí; průhledné popředí se složí nad pozadím."""
    br, bg_g, bb, b_alpha = resolve(tokens, bg)
    if b_alpha < 1:
        raise TokenError(f"pozadí `{PREFIX}{bg}` je průhledné — dvojice nemá určitou barvu")
    fr, fg_g, fb, f_alpha = resolve(tokens, fg)
    front = tuple(f * f_alpha + b * (1 - f_alpha) for f, b in ((fr, br), (fg_g, bg_g), (fb, bb)))
    hi, lo = sorted((luminance(front), luminance((br, bg_g, bb))), reverse=True)
    return (hi + 0.05) / (lo + 0.05)


def fmt(ratio: float) -> str:
    return f"{ratio:.2f}".replace(".", ",")


def exemption_for(fg: str, bg: str, theme: str) -> Exemption | None:
    for ex in EXCEPTIONS:
        if ex.fg == fg and ex.bg == bg and theme in ex.themes:
            return ex
    return None


def main(argv: list[str]) -> int:
    show_list = "--list" in argv
    themes = load_themes()

    rows: list[tuple[Pair, dict[str, float | None]]] = []  # dvojice → poměr per vzhled
    skipped: list[tuple[Pair, str]] = []
    findings: list[str] = []
    errors: list[str] = []

    for pair in PAIRS:
        ratios: dict[str, float | None] = {}
        for theme in THEMES:
            tokens = themes[theme]
            if pair.fg not in tokens or pair.bg not in tokens:
                ratios[theme] = None
                skipped.append((pair, theme))
                continue
            try:
                ratio = contrast(tokens, pair.fg, pair.bg)
            except TokenError as e:
                ratios[theme] = None
                errors.append(f"  {pair.fg} / {pair.bg} ({theme}): {e}")
                continue
            ratios[theme] = ratio
            exempt = exemption_for(pair.fg, pair.bg, theme) is not None
            if ratio < pair.minimum and not exempt:
                findings.append(
                    f"  {PREFIX}{pair.fg} na {PREFIX}{pair.bg}  {theme} vzhled"
                    f"  {fmt(ratio)} : 1  (práh {fmt(pair.minimum)})  — {pair.why}"
                )
        rows.append((pair, ratios))

    stale: list[str] = []
    exception_rows: list[tuple[Exemption, str, float | None]] = []
    for ex in EXCEPTIONS:
        for theme in ex.themes:
            tokens = themes[theme]
            try:
                ratio = contrast(tokens, ex.fg, ex.bg) if ex.fg in tokens and ex.bg in tokens else None
            except TokenError as e:
                errors.append(f"  výjimka {ex.fg} / {ex.bg} ({theme}): {e}")
                ratio = None
            exception_rows.append((ex, theme, ratio))
            if ratio is not None and ratio >= ex.minimum:
                stale.append(
                    f"  {PREFIX}{ex.fg} na {PREFIX}{ex.bg}  {theme} vzhled"
                    f"  {fmt(ratio)} : 1 práh {fmt(ex.minimum)} splňuje"
                )

    if show_list:
        width_fg = max(len(p.fg) for p in PAIRS)
        width_bg = max(len(p.bg) for p in PAIRS)
        print(f"{'popředí':<{width_fg}}  {'pozadí':<{width_bg}}  {'práh':>4}  {'světlý':>6}  {'tmavý':>6}  proč")
        for pair, ratios in rows:
            cells = []
            for theme in THEMES:
                ratio = ratios[theme]
                mark = "*" if exemption_for(pair.fg, pair.bg, theme) else ""
                cells.append(f"{(fmt(ratio) + mark) if ratio is not None else '—':>6}")
            print(f"{pair.fg:<{width_fg}}  {pair.bg:<{width_bg}}  {fmt(pair.minimum)[:3]:>4}  {cells[0]}  {cells[1]}  {pair.why}")
        print("\n* = zdůvodněná výjimka")
        if skipped:
            print("\nVynechané dvojice (token ve vzhledu není):")
            for pair, theme in skipped:
                print(f"  {pair.fg} / {pair.bg}  {theme}")
        print("\nVýjimky:")
        for ex, theme, ratio in exception_rows:
            value = fmt(ratio) if ratio is not None else "—"
            print(f"  {ex.fg} / {ex.bg}  {theme}  {value} : 1 (práh {fmt(ex.minimum)})\n      {ex.reason}")
        print()

    failed = False
    if errors:
        print("Tokeny, které nejdou vyhodnotit:\n", file=sys.stderr)
        print("\n".join(errors), file=sys.stderr)
        failed = True
    if findings:
        if failed:
            print(file=sys.stderr)
        print("Dvojice tokenů pod prahem kontrastu:\n", file=sys.stderr)
        print("\n".join(findings), file=sys.stderr)
        print(
            "\nUprav hodnotu tokenu ve frontend/src/styles/variables.css; výjimku"
            " jen s rozhodnutím (konstanta EXCEPTIONS, docs/design-system.md §3).",
            file=sys.stderr,
        )
        failed = True
    if stale:
        if failed:
            print(file=sys.stderr)
        print("Výjimky, které už nejsou potřeba:\n", file=sys.stderr)
        print("\n".join(stale), file=sys.stderr)
        print("\nOdstraň je z konstanty EXCEPTIONS.", file=sys.stderr)
        failed = True

    if failed:
        return 1

    count = sum(1 for _, ratios in rows for r in ratios.values() if r is not None)
    print(
        f"Kontrast tokenů v pořádku: {len(PAIRS)} dvojic ve dvou vzhledech"
        f" ({count} měření), výjimek: {len(EXCEPTIONS)}."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
