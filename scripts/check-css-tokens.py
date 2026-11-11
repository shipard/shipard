#!/usr/bin/env python3
"""Kontrola CSS tokenů ve frontendu (#129 D14, #133 D10, D61).

Dvě kontroly nad celým stromem `frontend/src`.

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

Použití:
    python3 scripts/check-css-tokens.py

Volá se z `pre-commit` hooku. Exit 1 = nedefinovaný token (výpis
token → soubory na stderr) nebo token hlášky mimo povolené soubory (výpis
soubor:řádek token na stderr); běh vypíše oba druhy nálezů najednou.
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

    if failed:
        return 1

    print(f"CSS tokeny v pořádku: {len(defined)} definic, {len(used)} použitých tokenů.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
