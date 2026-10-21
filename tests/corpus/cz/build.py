#!/usr/bin/env python3
"""Build korpusu testovacích dokladů (tasks/testing-phase1.md, docs/test-corpus.md).

Pro každý datový soubor data/NNNN-<slug>.jsonc a každý profil vlastní firmy
(identities.jsonc, bez skipProfiles) spočítá doklad (Decimal), zapíše
očekávaný výsledek expected/<profil>/NNNN-<slug>.json (vždy — je
deterministický) a vyrenderuje out/<profil>/NNNN-<slug>.pdf přes Gotenberg
jen tehdy, když PDF chybí, hash HTML + voleb papíru se liší od
build-state.json, nebo s --force (PDF z Chromia není bajtově deterministické).

    python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities] [--no-datasets]

--verify-identities ověří identity v ARES a VIES (síť; po změně identit),
při úspěchu zapíše dnešní datum do verifiedAt a pokračuje buildem; selhání
= exit 1, nedostupný registr = exit 2 (identity se neoznačí jako ověřené).
Po korpusu sestaví datové sady tests/datasets/cz/<profil>/ ze základů
profilů profiles/<profil>/ (tasks/testing-phase2.md; --no-datasets přeskočí;
sady se stavějí vždy ze všech datových souborů, i s --only).
Env: GOTENBERG_URL (default http://10.199.6.210:3000). Exit != 0 = chyba.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
CORPUS_DIR = BASE_DIR.parent
REPO_ROOT = BASE_DIR.parents[2]
sys.path.insert(0, str(REPO_ROOT / "tools" / "invoice-generator"))

from invoicegen.checks import validate_identities  # noqa: E402
from invoicegen.compute import VAT_RATES, compute  # noqa: E402
from invoicegen.corpus import (  # noqa: E402
    PROFILES, TEMPLATE_LOCALE, VARIANTS, Cell, DataFileError, SkippedCell, cells_for, validate_data_file,
    validate_variant_pairs,
)
from invoicegen.corpusprint import template_values  # noqa: E402
from invoicegen.dataset import DatasetBuildError, build_datasets  # noqa: E402
from invoicegen.expected import apply_projection, build_expected, dumps_expected, load_projection  # noqa: E402
from invoicegen.jsonc import load_jsonc  # noqa: E402
from invoicegen.registries import (  # noqa: E402
    RegistryUnavailable, format_check, update_verified_at, verify_identities,
)
from invoicegen.reporting import ReportingError, load_vat_reporting  # noqa: E402
from invoicegen.render import fmt_money, pdf_options_from_html, render_hash, render_html, render_pdf  # noqa: E402

STATE_FILE = BASE_DIR / "build-state.json"
PROJECTION_FILE = CORPUS_DIR / "projection.jsonc"
VAT_REPORTING_FILE = BASE_DIR / "vat-reporting.jsonc"
TAXONOMY_FILE = REPO_ROOT / "modules" / "core" / "exchange" / "config" / "contentTags.jsonc"
DEMO_IDENTITIES = REPO_ROOT / "demo" / "invoices" / "suppliers.jsonc"
DATASETS_DIR = REPO_ROOT / "tests" / "datasets" / "cz"

# ── Hodnoty pro šablony ──────────────────────────────────────────────────────
# `COUNTRY_NAMES`, `LABELS` a stavba hodnot žijí v `invoicegen.corpusprint`
# (sdílené s demo sadou pro faktury zahraničních dodavatelů, #131 D28).

def build_values(cell: Cell, computed: dict) -> dict[str, str]:
    return template_values(cell.data, cell.supplier, cell.profile_identity, computed,
                           locale=TEMPLATE_LOCALE[cell.template], receipt=cell.receipt)


# ── Build ────────────────────────────────────────────────────────────────────

def load_state() -> dict[str, str]:
    if not STATE_FILE.exists():
        return {}
    return json.loads(STATE_FILE.read_text(encoding="utf-8"))


def save_state(state: dict[str, str]) -> None:
    STATE_FILE.write_text(json.dumps(dict(sorted(state.items())), indent=2) + "\n", encoding="utf-8")


def verify(identities: dict) -> int:
    try:
        checks = verify_identities(identities)
    except RegistryUnavailable as e:
        print(f"registr nedostupný: {e} — identity neoznačeny jako ověřené", file=sys.stderr)
        return 2
    for check in checks:
        print(format_check(check))
    failed = [c for c in checks if not c.ok]
    if failed:
        print(f"identity: {len(checks)} kontrol, {len(failed)} selhání — identity vyměň a spusť znovu", file=sys.stderr)
        return 1
    today = date.today().isoformat()
    path = BASE_DIR / "identities.jsonc"
    path.write_text(update_verified_at(path.read_text(encoding="utf-8"), today), encoding="utf-8")
    print(f"identity: {len(checks)} kontrol, 0 selhání → verifiedAt {today}")
    return 0


def summary_line(cell: Cell, computed: dict, status: str) -> str:
    locale = TEMPLATE_LOCALE[cell.template]
    currency = cell.data["currency"]
    if computed["totalBase"] is None:
        amounts = f"celkem {fmt_money(computed['totalAmount'], locale)} {currency} (bez DPH)"
    else:
        rounding = (f", zaokrouhlení {fmt_money(computed['totalRounding'], locale)}"
                    if computed["totalRounding"] != 0 else "")
        amounts = (f"základ {fmt_money(computed['totalBase'], locale)} + DPH {fmt_money(computed['totalVat'], locale)}"
                   f"{rounding} = {fmt_money(computed['totalAmount'], locale)} {currency}")
    return f"{cell.stem} [{cell.profile}]: {amounts} — {status}"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build korpusu testovacích dokladů.")
    parser.add_argument("--only", action="append", default=[], metavar="NNNN",
                        help="jen datové soubory s tímto číslem (lze opakovat)")
    parser.add_argument("--force", action="store_true", help="přegenerovat všechna PDF")
    parser.add_argument("--verify-identities", action="store_true",
                        help="ověřit identity v ARES a VIES (síť) a zapsat verifiedAt")
    parser.add_argument("--no-datasets", action="store_true", help="nesestavovat datové sady tests/datasets/cz/")
    args = parser.parse_args(argv)

    identities = load_jsonc(BASE_DIR / "identities.jsonc")
    problems = validate_identities(identities, reserved=load_jsonc(DEMO_IDENTITIES))
    if set(identities["profiles"]) != set(PROFILES):
        problems.append(f"profily musí být právě {', '.join(PROFILES)}")
    if problems:
        print("identities.jsonc:\n  " + "\n  ".join(problems), file=sys.stderr)
        return 1
    if args.verify_identities:
        rc = verify(identities)
        if rc:
            return rc

    projection = load_projection(PROJECTION_FILE)
    try:
        reporting = load_vat_reporting(VAT_REPORTING_FILE, required_variants=frozenset(VARIANTS))
    except ReportingError as e:
        print(f"mapování DPH: {e}", file=sys.stderr)
        return 1
    taxonomy = set(load_jsonc(TAXONOMY_FILE))
    templates = {p.stem: p.read_text(encoding="utf-8") for p in sorted((BASE_DIR / "templates").glob("*.html"))}

    docs: dict[str, dict] = {}
    try:
        for path in sorted((BASE_DIR / "data").glob("*.jsonc")):
            doc = load_jsonc(path)
            validate_data_file(doc, path.stem, identities=identities, templates=set(templates), taxonomy=taxonomy)
            docs[path.stem] = doc
        validate_variant_pairs(docs)
    except DataFileError as e:
        print(f"datový soubor: {e}", file=sys.stderr)
        return 1
    if not docs:
        print("0 datových souborů v data/ — nic k sestavení")
        return 0

    only = set(args.only)
    selected = [stem for stem in docs if not only or stem[:4] in only]
    unknown = only - {stem[:4] for stem in docs}
    if unknown:
        print(f"--only: neznámá čísla {', '.join(sorted(unknown))}", file=sys.stderr)
        return 1

    state = load_state()
    new_state = dict(state)
    produced: set[str] = set()
    counts = {"cells": 0, "rendered": 0, "unchanged": 0, "skipped": 0}
    try:
        for stem in selected:
            for cell in cells_for(docs[stem], stem, identities):
                if isinstance(cell, SkippedCell):
                    counts["skipped"] += 1
                    print(f"{cell.stem} [{cell.profile}]: vynecháno — {cell.reason}")
                    continue
                counts["cells"] += 1
                supplier_country = cell.supplier["country"]
                computed = compute(cell.data, vat_treatment=cell.data["vatTreatment"],
                                   allowed_pcts=VAT_RATES.get(supplier_country, VAT_RATES["cz"]))
                expected = apply_projection(
                    build_expected(cell.data, cell.supplier, cell.profile_identity, computed, receipt=cell.receipt),
                    projection, taxonomy=taxonomy,
                )
                expected_path = BASE_DIR / "expected" / cell.profile / f"{stem}.json"
                expected_path.parent.mkdir(parents=True, exist_ok=True)
                expected_path.write_text(dumps_expected(expected), encoding="utf-8")

                html = render_html(templates[cell.template], build_values(cell, computed))
                fields = pdf_options_from_html(html)
                digest = render_hash(html, fields)
                key = f"{cell.profile}/{stem}"
                pdf_path = BASE_DIR / "out" / cell.profile / f"{stem}.pdf"
                if args.force or not pdf_path.exists() or state.get(key) != digest:
                    pdf = render_pdf(html, fields=fields)
                    pdf_path.parent.mkdir(parents=True, exist_ok=True)
                    pdf_path.write_bytes(pdf)
                    status = f"PDF přegenerováno ({len(pdf) // 1024} kB)"
                    counts["rendered"] += 1
                else:
                    status = "PDF beze změny"
                    counts["unchanged"] += 1
                new_state[key] = digest
                produced.add(key)
                print(summary_line(cell, computed, status))
                if cell.form == "pdfIsdoc":
                    print("  ISDOC: čeká na generátor (#119)")
        if not only:
            new_state = {k: v for k, v in new_state.items() if k in produced}
    finally:
        save_state(new_state)

    print(f"OK: {counts['cells']} buněk ({counts['rendered']} PDF přegenerováno, "
          f"{counts['unchanged']} beze změny), {counts['skipped']} vynecháno")

    if not args.no_datasets:
        try:
            summaries = build_datasets(BASE_DIR, DATASETS_DIR, identities, docs,
                                       reporting=reporting, projection_path=PROJECTION_FILE)
        except (DatasetBuildError, ReportingError) as e:
            print(f"datová sada: {e}", file=sys.stderr)
            return 1
        for s in summaries:
            print(f"{s.name}: {s.messages} zpráv, {s.skipped} vynechaných buněk matice → "
                  f"{s.out_dir.relative_to(REPO_ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
