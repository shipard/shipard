#!/usr/bin/env python3
"""Kontrola citlivých údajů ve staged souborech.

Konvence projektu (tasks/README.md → Citlivé údaje z reálných dat):
commitované texty nesmí obsahovat identifikátory reálných datových zdrojů,
názvy firem, čísla dokladů ani id záznamů. Repozitář je veřejný.

Co skript hlídá:

1. **Vzor ID datového zdroje** (`xxxx-xxxx-xxxx-xxxx`) mimo seznam
   povolených placeholderů níže.
2. **Vlastní termíny** z nepovinného souboru `.git/sensitive-terms`
   (jeden termín na řádek, `#` = komentář). Tento soubor **není** v gitu —
   proto do něj patří skutečné názvy firem a datových zdrojů, které se
   nesmí objevit v commitu. Bez něj se kontrola termínů přeskočí.

Použití:
    python3 scripts/check-sensitive.py            # staged soubory
    python3 scripts/check-sensitive.py --all      # celý pracovní strom

Volá se z `pre-commit` hooku. Exit 1 = nález.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
TERMS_FILE = REPO_ROOT / ".git" / "sensitive-terms"

# Placeholdery, které se v dokumentaci a testech používají záměrně.
ALLOWED = {
    "a3f2-b8c1-d4e7-f9a0",
    "x9y8-w7v6-u5t4-s3r2",
    "shpd-font-size-base",
    "ab12-cd34-ef56-gh78",  # příklad --ds-id v docs/cli.md a DsCreateCommandTest
    "4l3j-z0bz-kz39-echj",  # ukázkový/testovací DS — z anonymizace vyjmutý (CLAUDE.md → Zdroje dat ve veřejných textech)
}

# Skupiny, ze kterých se skládají zjevně syntetická ID (testovací fixtures,
# příklady v dokumentaci). ID, jehož všechny čtyři skupiny jsou odsud, se
# nehlásí. Reálná ID jsou náhodná, takže sem nespadnou.
DUMMY_GROUPS = (
    {c * 4 for c in "abcdefghijklmnopqrstuvwxyz0123456789"}
    | {
        "test", "abcd", "efgh", "ijkl", "mnop", "1234", "5678",
        "dead", "beef", "shpd", "font", "size", "base", "oidc",
        "ctrl", "0001", "0002",
    }
)


def is_synthetic(ds_id: str) -> bool:
    if ds_id in ALLOWED:
        return True
    return all(part in DUMMY_GROUPS for part in ds_id.split("-"))


DS_ID_RE = re.compile(r"\b[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}\b")

SCAN_SUFFIXES = {
    ".md", ".jsonc", ".json", ".php", ".js", ".svelte", ".css",
    ".sh", ".py", ".conf", ".yml", ".yaml", ".txt",
}
SKIP_DIRS = {"vendor", "node_modules", "public", ".git"}


def staged_files() -> list[Path]:
    out = subprocess.run(
        ["git", "diff", "--cached", "--name-only", "--diff-filter=ACMR"],
        cwd=REPO_ROOT, capture_output=True, text=True, check=True,
    ).stdout
    return [REPO_ROOT / line for line in out.splitlines() if line]


def all_files() -> list[Path]:
    out = subprocess.run(
        ["git", "ls-files"], cwd=REPO_ROOT,
        capture_output=True, text=True, check=True,
    ).stdout
    return [REPO_ROOT / line for line in out.splitlines() if line]


def load_terms() -> list[str]:
    if not TERMS_FILE.exists():
        return []
    terms = []
    for line in TERMS_FILE.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            terms.append(line)
    return terms


def scannable(path: Path) -> bool:
    if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
        return False
    return not any(part in SKIP_DIRS for part in path.parts)


def main() -> int:
    paths = all_files() if "--all" in sys.argv else staged_files()
    terms = load_terms()
    term_res = [
        (t, re.compile(r"\b" + re.escape(t) + r"\b", re.IGNORECASE))
        for t in terms
    ]

    findings: list[str] = []
    for path in paths:
        if not scannable(path):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        rel = path.relative_to(REPO_ROOT)
        for lineno, line in enumerate(text.splitlines(), 1):
            for match in DS_ID_RE.finditer(line):
                if not is_synthetic(match.group(0)):
                    findings.append(
                        f"{rel}:{lineno}: vzor ID datov\u00e9ho zdroje "
                        f"\u2192 {match.group(0)}"
                    )
            for term, rx in term_res:
                if rx.search(line):
                    findings.append(
                        f"{rel}:{lineno}: citliv\u00fd termín \u2192 {term}"
                    )

    if findings:
        print("Nalezeny citliv\u00e9 \u00fadaje:\n", file=sys.stderr)
        for f in findings:
            print(f"  {f}", file=sys.stderr)
        print(
            "\nAnonymizuj se zachov\u00e1n\u00edm pom\u011br\u016f "
            "(tasks/README.md \u2192 Konvence).\n"
            "Pokud je nález planý, p\u0159idej placeholder do ALLOWED "
            "ve scripts/check-sensitive.py.",
            file=sys.stderr,
        )
        return 1

    if not terms:
        print(
            "Pozn\u00e1mka: .git/sensitive-terms neexistuje \u2014 "
            "kontrola n\u00e1zv\u016f firem se p\u0159eskakuje."
        )
    return 0


if __name__ == "__main__":
    sys.exit(main())
