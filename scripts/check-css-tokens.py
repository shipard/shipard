#!/usr/bin/env python3
"""Kontrola CSS tokenů ve frontendu (#129 D14, #133 D10).

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

Seznam výjimek skript nemá. Token, který se definuje způsobem, jaký skript
nepozná, se řeší rozšířením vzoru definice, ne výjimkou.

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


def scannable(path: Path) -> bool:
    if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
        return False
    return not any(part in SKIP_DIRS for part in path.parts)


def main() -> int:
    defined: set[str] = set()
    used: dict[str, set[str]] = {}
    with_fallback: set[str] = set()

    for path in sorted(FRONTEND_SRC.rglob("*")):
        if not scannable(path):
            continue
        text = path.read_text(encoding="utf-8")
        for rx in DEFINITION_RES:
            defined.update(m.group(1) for m in rx.finditer(text))
        rel = str(path.relative_to(REPO_ROOT))
        for m in USAGE_RE.finditer(text):
            used.setdefault(m.group(1), set()).add(rel)
            if m.group(2) == ",":
                with_fallback.add(m.group(1))

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
        return 1

    print(f"CSS tokeny v pořádku: {len(defined)} definic, {len(used)} použitých tokenů.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
