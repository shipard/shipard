#!/usr/bin/env python3
"""Generátor rozcestníku uživatelské dokumentace do help/README.md.

Zdroj pravdy je YAML hlavička každé stránky v help/ (klíče `title`,
`summary`, `keywords`, volitelně `related`). Tento skript z nich sestaví
rozcestník po oblastech a vloží ho do help/README.md mezi značky
OBSAH:BEGIN / OBSAH:END.

Kromě indexu ověřuje konzistenci: povinné klíče, soulad `title` s H1,
existenci cílů v `related` i v odkazech v těle stránky, to, že každý
podadresář help/ je vědomě zaveden v SECTIONS, a soulad katalogu agend
(help/co-shipard-umi.md) s levým menu aplikace — každá úlohová stránka
z něj musí být odkázaná a každá agenda z menu v něm musí mít řádek.

Pravidla pro psaní stránek: docs/help-authoring.md

Použití:
    python3 scripts/help-index.py           # přepíše blok v help/README.md
    python3 scripts/help-index.py --check   # jen ověří, exit 1 když nesedí

Volá se z git hooku (pre-commit) v režimu --check.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
HELP_DIR = REPO_ROOT / "help"
INDEX_FILE = HELP_DIR / "README.md"

BEGIN = "<!-- OBSAH:BEGIN — generováno scripts/help-index.py, needituj ručně -->"
END = "<!-- OBSAH:END -->"

# Oblasti v pořadí, v jakém se vypisují. Klíč "" = stránky přímo v help/.
# Nový podadresář se přidává sem vědomě — jinak --check zastaví commit.
SECTIONS: list[tuple[str, str]] = [
    ("", "Základy"),
    ("osoby", "Osoby"),
    ("polozky", "Položky"),
    ("posta", "Pošta"),
    ("faktury-prijate", "Faktury přijaté"),
    ("faktury-vydane", "Faktury vydané"),
    ("pokladna", "Pokladna"),
    ("zakazky", "Zakázky"),
    ("majetek", "Majetek"),
    ("uctarna", "Účtárna"),
]

# Soubory, které nejsou stránkami dokumentace.
SKIP = {"README.md"}

REQUIRED_KEYS = ("title", "summary", "keywords")

# Nad tolik řádků stránka přestává být jedna úloha (viz docs/help-authoring.md).
LONG_PAGE_LINES = 200

# Katalog agend — stránka, která říká, co aplikace umí a k čemu už je návod.
# Bez odkazu z něj uživatel ani asistent novou stránku nenajde.
CATALOG = "co-shipard-umi.md"

# Stránky, které nejsou úlohou, takže odkaz z katalogu nepotřebují.
# Nová výjimka se přidává vědomě — jinak --check zastaví commit.
CATALOG_EXEMPT = {CATALOG, "co-dnes-nejde.md", "slovnicek.md"}

# Položky levého menu, které nejsou viewer modulu — drží je
# NavigationController, takže je v module.jsonc nenajdeme.
CATALOG_EXTRA_AGENDAS = {
    "Dashboard",
    "Chat",
    # Reporty — položky menu z report deklarací (nejsou viewery),
    # viz docs/reports.md §12.
    "Hlavní kniha",
    "Výsledovka",
    "Rozvaha",
    "Přiznání k DPH — živě",
    "Kontrolní hlášení — živě",
    "Souhrnné hlášení — živě",
}

# Řádek tabulky katalogu: `| **Agenda** | …`
CATALOG_ROW_RE = re.compile(r"^\|\s*\*\*(.+?)\*\*\s*\|")

# Odkaz v markdownu — použitý pro kontrolu cest i pro obsah katalogu.
LINK_RE = re.compile(r"\[[^\]]*\]\(([^)\s]+)\)")

H1_RE = re.compile(r"^#\s+(.+?)\s*$")
KEY_RE = re.compile(r"^([a-z_]+):\s*(.*)$")


class Page:
    def __init__(self, rel_path: str, meta: dict, h1: str, lines: int):
        self.rel_path = rel_path
        self.meta = meta
        self.h1 = h1
        self.lines = lines

    @property
    def title(self) -> str:
        return self.meta.get("title", "")

    @property
    def summary(self) -> str:
        return self.meta.get("summary", "")


def parse_value(raw: str):
    """Skalár, nebo inline seznam `[a, b]` → list[str]."""
    raw = raw.strip()
    if raw.startswith("[") and raw.endswith("]"):
        inner = raw[1:-1].strip()
        if not inner:
            return []
        return [item.strip() for item in inner.split(",") if item.strip()]
    return raw


def parse_page(path: Path) -> tuple[Page | None, list[str]]:
    """Načte hlavičku a H1. Vrátí (Page | None, seznam chyb)."""
    rel = path.relative_to(HELP_DIR).as_posix()
    text = path.read_text(encoding="utf-8")
    lines = text.splitlines()
    errors: list[str] = []

    if not lines or lines[0].strip() != "---":
        return None, [f"{rel}: chybí YAML hlavička (viz docs/help-authoring.md)"]

    meta: dict = {}
    body_start = None
    for i, line in enumerate(lines[1:], start=1):
        if line.strip() == "---":
            body_start = i + 1
            break
        m = KEY_RE.match(line)
        if m:
            meta[m.group(1)] = parse_value(m.group(2))
    if body_start is None:
        return None, [f"{rel}: hlavička není uzavřená značkou ---"]

    for key in REQUIRED_KEYS:
        value = meta.get(key)
        if not value:
            errors.append(f"{rel}: chybí nebo je prázdný klíč `{key}`")

    h1 = ""
    for line in lines[body_start:]:
        m = H1_RE.match(line)
        if m:
            h1 = m.group(1)
            break
    if not h1:
        errors.append(f"{rel}: chybí nadpis H1")
    elif meta.get("title") and h1 != meta["title"]:
        errors.append(
            f"{rel}: `title` se rozch\u00e1z\u00ed s H1 "
            f"(\u201e{meta['title']}\u201c vs. \u201e{h1}\u201c)"
        )

    return Page(rel, meta, h1, len(lines)), errors


def strip_jsonc(text: str) -> str:
    """Odstraní // a /* */ komentáře mimo stringy a koncové čárky.

    Komentáře se hledají znak po znaku, aby `//` uvnitř stringu (třeba
    v URL) neodřízlo zbytek řádku.
    """
    out: list[str] = []
    i, n = 0, len(text)
    in_string = False
    while i < n:
        ch = text[i]
        if in_string:
            out.append(ch)
            if ch == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if ch == '"':
                in_string = False
            i += 1
            continue
        if ch == '"':
            in_string = True
            out.append(ch)
            i += 1
            continue
        if ch == "/" and i + 1 < n and text[i + 1] == "/":
            while i < n and text[i] != "\n":
                i += 1
            continue
        if ch == "/" and i + 1 < n and text[i + 1] == "*":
            i += 2
            while i + 1 < n and not (text[i] == "*" and text[i + 1] == "/"):
                i += 1
            i += 2
            continue
        out.append(ch)
        i += 1
    return re.sub(r",(\s*[}\]])", r"\1", "".join(out))


def nav_agendas() -> tuple[dict[str, str], list[str]]:
    """Agendy levého menu: {český popisek: id vieweru}.

    Zdroj pravdy je `navSection` u vieweru v module.jsonc — stejně jako
    ho čte NavigationController.
    """
    agendas: dict[str, str] = {}
    errors: list[str] = []
    for path in sorted((REPO_ROOT / "modules").glob("*/*/module.jsonc")):
        rel = path.relative_to(REPO_ROOT).as_posix()
        try:
            data = json.loads(strip_jsonc(path.read_text(encoding="utf-8")))
        except ValueError as exc:
            errors.append(f"{rel}: nejde přečíst pro kontrolu katalogu ({exc})")
            continue
        for viewer in data.get("viewers", []):
            if "navSection" not in viewer:
                continue
            label = (
                viewer.get("name:cs")
                or viewer.get("name")
                or viewer.get("id", "?")
            )
            agendas[label] = viewer.get("id", "?")
    return agendas, errors


def link_errors(all_pages: list[Page]) -> list[str]:
    """Relativní odkazy v těle stránek musí mířit na existující soubor."""
    errors: list[str] = []
    for page in all_pages:
        path = HELP_DIR / page.rel_path
        text = path.read_text(encoding="utf-8")
        for target in LINK_RE.findall(text):
            if target.startswith(("http://", "https://", "mailto:", "#")):
                continue
            clean = target.split("#", 1)[0]
            if not clean:
                continue
            if not (path.parent / clean).is_file():
                errors.append(
                    f"{page.rel_path}: odkaz míří na neexistující `{target}`"
                )
    return errors


def catalog_errors(all_pages: list[Page]) -> list[str]:
    """Katalog agend musí odkazovat na všechny úlohové stránky a sedět na menu."""
    catalog = HELP_DIR / CATALOG
    if not catalog.is_file():
        return [
            f"help/{CATALOG} chybí — když se katalog přejmenoval, uprav "
            "CATALOG v scripts/help-index.py"
        ]

    text = catalog.read_text(encoding="utf-8")
    errors: list[str] = []

    linked = {target.split("#", 1)[0] for target in LINK_RE.findall(text)}
    for page in all_pages:
        if page.rel_path in CATALOG_EXEMPT or page.rel_path in linked:
            continue
        errors.append(
            f"{page.rel_path}: není odkázaná z help/{CATALOG} "
            "(sloupec Návod)"
        )

    rows = {
        m.group(1)
        for m in (CATALOG_ROW_RE.match(line) for line in text.splitlines())
        if m
    }
    agendas, nav_err = nav_agendas()
    errors.extend(nav_err)
    for label, viewer_id in sorted(agendas.items()):
        if label not in rows:
            errors.append(
                f"help/{CATALOG}: chybí řádek pro agendu „{label}“ "
                f"(viewer {viewer_id}) — nová agenda v menu patří do katalogu"
            )
    for label in sorted(rows - set(agendas) - CATALOG_EXTRA_AGENDAS):
        errors.append(
            f"help/{CATALOG}: řádek „{label}“ neodpovídá žádné agendě "
            "v levém menu — zmizela z aplikace, nebo se přejmenovala?"
        )
    return errors


def collect() -> tuple[list[tuple[str, list[Page]]], list[str], list[str]]:
    sections: list[tuple[str, list[Page]]] = []
    errors: list[str] = []
    warnings: list[str] = []

    known_dirs = {sub for sub, _ in SECTIONS if sub}
    for child in sorted(HELP_DIR.iterdir()):
        if child.is_dir() and child.name not in known_dirs:
            errors.append(
                f"help/{child.name}/: neznámá oblast — přidej ji do SECTIONS "
                "v scripts/help-index.py"
            )

    all_pages: list[Page] = []
    for sub, label in SECTIONS:
        base = HELP_DIR / sub if sub else HELP_DIR
        if not base.is_dir():
            continue
        pages: list[Page] = []
        for path in sorted(base.glob("*.md")):
            if path.name in SKIP:
                continue
            page, page_errors = parse_page(path)
            errors.extend(page_errors)
            if page is None:
                continue
            if page.lines > LONG_PAGE_LINES:
                warnings.append(
                    f"{page.rel_path}: {page.lines} řádků — zvaž rozdělení "
                    "na víc úloh"
                )
            pages.append(page)
            all_pages.append(page)
        if pages:
            sections.append((label, pages))

    # Odkazy v `related` musí mířit na existující soubor.
    for page in all_pages:
        related = page.meta.get("related") or []
        if isinstance(related, str):
            related = [related]
        for target in related:
            if not (HELP_DIR / target).is_file():
                errors.append(
                    f"{page.rel_path}: `related` míří na neexistující "
                    f"`{target}`"
                )

    errors.extend(link_errors(all_pages))
    errors.extend(catalog_errors(all_pages))

    return sections, errors, warnings


def render(sections) -> str:
    total = sum(len(pages) for _, pages in sections)
    lines = [BEGIN, ""]
    lines.append("## Obsah")
    lines.append("")
    if total == 0:
        lines.append("Zatím tu nejsou žádné stránky.")
        lines.append("")
        lines.append(END)
        return "\n".join(lines)

    for label, pages in sections:
        lines.append(f"### {label}")
        lines.append("")
        lines.append("| Stránka | Co v ní najdeš |")
        lines.append("|---------|----------------|")
        for page in pages:
            lines.append(f"| [{page.title}]({page.rel_path}) | {page.summary} |")
        lines.append("")

    lines.append(END)
    return "\n".join(lines)


def splice(original: str, block: str) -> str:
    if BEGIN in original and END in original:
        pre = original.split(BEGIN)[0]
        post = original.split(END, 1)[1]
        return pre + block + post
    return original.rstrip() + "\n\n" + block + "\n"


def main() -> int:
    check = "--check" in sys.argv
    if not HELP_DIR.is_dir():
        return 0

    sections, errors, warnings = collect()
    block = render(sections)
    original = INDEX_FILE.read_text(encoding="utf-8")
    updated = splice(original, block)

    for warning in warnings:
        print("Pozor — " + warning, file=sys.stderr if check else sys.stdout)

    if check:
        failed = False
        if updated != original:
            print(
                "help/README.md není aktuální — spusť "
                "`python3 scripts/help-index.py`",
                file=sys.stderr,
            )
            failed = True
        if errors:
            print("Chyby v uživatelské dokumentaci:", file=sys.stderr)
            for error in errors:
                print("  - " + error, file=sys.stderr)
            failed = True
        return 1 if failed else 0

    if updated != original:
        INDEX_FILE.write_text(updated, encoding="utf-8")
        print("help/README.md aktualizován.")
    else:
        print("help/README.md je aktuální.")
    if errors:
        print("Chyby v uživatelské dokumentaci:")
        for error in errors:
            print("  - " + error)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
