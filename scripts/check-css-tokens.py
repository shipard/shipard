#!/usr/bin/env python3
"""Kontrola CSS tokenů ve frontendu (#129 D14).

Token `--shpd-…` (aplikace) nebo `--dev-…` (vývojářský dashboard) použitý
jako `var(--x)` bez fallbacku musí být někde ve `frontend/src` definovaný —
jinak prohlížeč vlastnost tiše zahodí (karta dialogu bez pozadí, text bez
barvy). Skript to hlídá za celý strom, ne jen za staged soubory: je rychlý
a nedefinovaný token vznikne i smazáním definice v jiném souboru.

Co se počítá jako **definice**:

- `--shpd-x: hodnota` kdekoli — CSS, `style="…"` v markupu, klíč JS objektu
  (`'--shpd-x': …`, tokeny vlastního vzhledu v `utils/themeColor.js`);
- Svelte direktiva `style:--shpd-x={…}` (`FormSection.svelte`);
- `setProperty('--shpd-x', …)` v JS.

Co se počítá jako **použití bez fallbacku**: `var(--shpd-x)` bez čárky.
`var(--shpd-x, hodnota)` je v pořádku; u `var(--a, var(--b))` se hlídá
`--b` — to je hodnota, která nakonec platí.

Použití:
    python3 scripts/check-css-tokens.py

Volá se z `pre-commit` hooku. Exit 1 = nedefinovaný token (výpis
token → soubory na stderr).
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
    # Svelte direktiva style:--x={…}
    re.compile(rf"style:({TOKEN})="),
    # JS: el.style.setProperty('--x', …)
    re.compile(rf"setProperty\(\s*['\"]({TOKEN})['\"]"),
)

USAGE_RE = re.compile(rf"var\(\s*({TOKEN})\s*\)")


def scannable(path: Path) -> bool:
    if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
        return False
    return not any(part in SKIP_DIRS for part in path.parts)


def main() -> int:
    defined: set[str] = set()
    used: dict[str, set[str]] = {}

    for path in sorted(FRONTEND_SRC.rglob("*")):
        if not scannable(path):
            continue
        text = path.read_text(encoding="utf-8")
        for rx in DEFINITION_RES:
            defined.update(m.group(1) for m in rx.finditer(text))
        rel = str(path.relative_to(REPO_ROOT))
        for m in USAGE_RE.finditer(text):
            used.setdefault(m.group(1), set()).add(rel)

    undefined = {token: files for token, files in used.items() if token not in defined}
    if undefined:
        print("Nedefinované CSS tokeny použité bez fallbacku:\n", file=sys.stderr)
        for token in sorted(undefined):
            print(f"  {token}", file=sys.stderr)
            for rel in sorted(undefined[token]):
                print(f"      {rel}", file=sys.stderr)
        print(
            "\nNahraď token existujícím (frontend/src/styles/variables.css,"
            " frontend/src/dev/theme.css), definuj ho, nebo dej fallback"
            " var(--x, hodnota).",
            file=sys.stderr,
        )
        return 1

    print(
        f"CSS tokeny v pořádku: {len(defined)} definic, "
        f"{len(used)} tokenů použitých bez fallbacku."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
