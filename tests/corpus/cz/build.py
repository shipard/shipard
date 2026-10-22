#!/usr/bin/env python3
"""Build korpusu testovacích dokladů (tasks/testing-phase1.md, docs/test-corpus.md).

Pro každý datový soubor data/NNNN-<slug>.jsonc a každý profil vlastní firmy
(identities.jsonc, bez skipProfiles) spočítá doklad (Decimal), zapíše
očekávaný výsledek expected/<profil>/NNNN-<slug>.json (vždy — je
deterministický) a vyrenderuje out/<profil>/NNNN-<slug>.pdf přes Gotenberg
jen tehdy, když PDF chybí, hash HTML + voleb papíru se liší od
build-state.json, nebo s --force (PDF z Chromia není bajtově deterministické).
U buňky podoby pdfIsdoc navíc vždy vyrobí out/<profil>/NNNN-<slug>.isdoc
přes `php bin/shpd-server einvoice-render` (#119 krok 5): vstup je
očekávaný výsledek + rows[].computed z compute(), UUID z cesty buňky,
takže je výstup deterministický — výpis říká „ISDOC zapsán / beze změny"
podle obsahu, build-state.json se ho netýká. Buňce podoby pdf se .isdoc
smaže; plný běh smaže i sirotky. Vyžaduje `composer install` (vendor/).

    python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities] [--fetch-rates] [--no-datasets]

--verify-identities ověří identity v ARES a VIES (síť; po změně identit),
při úspěchu zapíše dnešní datum do verifiedAt a pokračuje buildem; selhání
= exit 1, nedostupný registr = exit 2 (identity se neoznačí jako ověřené).
Kurzovní lístky (#103 D72, tasks/testing-rates-isdoc.md): doklad v cizí
měně potřebuje v exchange-rates/ lístek ČNB k účetnímu datu (DUZP, bez něj
datum vystavení) a rezervu z předchozího pracovního dne; --fetch-rates je
jednorázově stáhne ze služby kurzů (síť), jinak build jen čte a chybějící
lístek je chyba. Plný běh (bez --only) smaže lístky, které žádný doklad
nepotřebuje.
Po korpusu sestaví datové sady tests/datasets/cz/<profil>/ ze základů
profilů profiles/<profil>/ (tasks/testing-phase2.md; --no-datasets přeskočí;
sady se stavějí vždy ze všech datových souborů, i s --only).
Env: GOTENBERG_URL (default http://10.199.6.210:3000). Exit != 0 = chyba.
"""

from __future__ import annotations

import argparse
import copy
import json
import os
import subprocess
import sys
import tempfile
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
from invoicegen.rates import RatesError, fetch_missing, load_lists, needed_dates, select_lists  # noqa: E402
from invoicegen.registries import (  # noqa: E402
    RegistryUnavailable, format_check, update_verified_at, verify_identities,
)
from invoicegen.reporting import ReportingError, load_vat_reporting  # noqa: E402
from invoicegen.render import fmt_money, pdf_options_from_html, render_hash, render_html, render_pdf  # noqa: E402

STATE_FILE = BASE_DIR / "build-state.json"
RATES_DIR = BASE_DIR / "exchange-rates"
CURRENCIES_FILE = REPO_ROOT / "modules" / "world" / "base" / "config" / "currencies.jsonc"
SHPD_SERVER = REPO_ROOT / "bin" / "shpd-server"
VENDOR_AUTOLOAD = REPO_ROOT / "vendor" / "autoload.php"
COUNTRY = BASE_DIR.name
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


class IsdocBuildError(RuntimeError):
    pass


def isdoc_payload(expected: dict, computed: dict) -> dict:
    """Canonical pro e-fakturu (#119 D2): očekávaný výsledek + rows[].computed."""
    payload = copy.deepcopy(expected)
    assert len(payload["rows"]) == len(computed["rows"]), "řádky očekávání × výpočtu"
    for row, calc in zip(payload["rows"], computed["rows"]):
        row["computed"] = {"vatBase": calc["vatBase"], "vatAmount": calc["vatAmount"], "vatTotal": calc["vatTotal"]}
    return payload


def write_isdoc(cell: Cell, expected: dict, computed: dict, path: Path) -> str:
    """Vyrobí .isdoc buňky přes shpd-server einvoice-render; vrací stav podle obsahu."""
    if not VENDOR_AUTOLOAD.exists():
        raise IsdocBuildError(f"chybí {VENDOR_AUTOLOAD.relative_to(REPO_ROOT)} — spusť `composer install`")
    path.parent.mkdir(parents=True, exist_ok=True)
    before = path.read_bytes() if path.exists() else None
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, prefix=f".{cell.stem}.",
                                     suffix=".json", delete=False) as tmp:
        tmp.write(dumps_expected(isdoc_payload(expected, computed)))
        payload_path = Path(tmp.name)
    try:
        result = subprocess.run(
            [os.environ.get("PHP", "php"), str(SHPD_SERVER), "einvoice-render", "--format=isdoc",
             f"--input={payload_path}", f"--output={path}", f"--uuid-name=corpus/{COUNTRY}/{cell.profile}/{cell.stem}"],
            capture_output=True, text=True, cwd=REPO_ROOT, check=False,
        )
    finally:
        payload_path.unlink(missing_ok=True)
    if result.returncode != 0:
        raise IsdocBuildError(
            f"{cell.stem} [{cell.profile}]: einvoice-render skončil s kódem {result.returncode}\n"
            + (result.stderr or result.stdout).rstrip()
        )
    return "ISDOC beze změny" if path.read_bytes() == before else "ISDOC zapsán"


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
    parser.add_argument("--fetch-rates", action="store_true",
                        help="stáhnout chybějící kurzovní lístky ČNB ze služby kurzů (síť) do exchange-rates/")
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

    # Kurzovní lístky pro doklady v cizí měně (D72): stažení jen s --fetch-rates,
    # jinak jen čtení; chybějící lístek k účetnímu datu je chyba.
    needed = needed_dates(docs, identities)
    try:
        if args.fetch_rates:
            for name, status in fetch_missing(RATES_DIR, needed):
                print(f"exchange-rates/{name}: {status}")
        rate_lists = load_lists(RATES_DIR)
    except RatesError as e:
        print(f"kurzy: {e}", file=sys.stderr)
        return 2 if args.fetch_rates else 1
    except OSError as e:
        print(f"kurzy: služba nedostupná: {e}", file=sys.stderr)
        return 2
    selection = select_lists(rate_lists, [(c, day) for day, currencies in needed.items() for c in currencies])
    if selection.missing:
        print("kurzy: chybí lístek pro " + ", ".join(f"{c} k {day}" for c, day in selection.missing)
              + " — spusť build.py --fetch-rates", file=sys.stderr)
        return 1
    for (currency, day), rate in selection.rates.items():
        print(f"kurz {currency} k {day}: lístek {rate.valid_from} (č. {rate.list_number}) → {rate.rate}")
    if not only:
        kept = {lst["validFrom"] for lst in selection.lists}
        for orphan in sorted(RATES_DIR.glob("*.json")) if RATES_DIR.is_dir() else []:
            if orphan.stem[:10] not in kept:
                orphan.unlink()
                print(f"exchange-rates/{orphan.name}: lístek bez dokladu smazán")
        rate_lists = [lst for lst in rate_lists if lst["validFrom"] in kept]
    currencies = frozenset(code.upper() for code in load_jsonc(CURRENCIES_FILE))

    state = load_state()
    new_state = dict(state)
    produced: set[str] = set()
    isdoc_files: set[Path] = set()
    counts = {"cells": 0, "rendered": 0, "unchanged": 0, "skipped": 0, "isdoc": 0, "isdocUnchanged": 0}
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
                isdoc_path = BASE_DIR / "out" / cell.profile / f"{stem}.isdoc"
                if cell.form == "pdfIsdoc":
                    try:
                        isdoc_status = write_isdoc(cell, expected, computed, isdoc_path)
                    except IsdocBuildError as e:
                        print(summary_line(cell, computed, status))
                        print(f"ISDOC: {e}", file=sys.stderr)
                        return 1
                    counts["isdocUnchanged" if isdoc_status.endswith("beze změny") else "isdoc"] += 1
                    isdoc_files.add(isdoc_path)
                    status = f"{status}, {isdoc_status}"
                elif isdoc_path.exists():
                    isdoc_path.unlink()
                    status = f"{status}, ISDOC smazán (podoba {cell.form})"
                print(summary_line(cell, computed, status))
        if not only:
            new_state = {k: v for k, v in new_state.items() if k in produced}
            for orphan in sorted((BASE_DIR / "out").glob("*/*.isdoc")):
                if orphan not in isdoc_files:
                    orphan.unlink()
                    print(f"{orphan.relative_to(BASE_DIR)}: sirotek bez buňky pdfIsdoc smazán")
    finally:
        save_state(new_state)

    print(f"OK: {counts['cells']} buněk ({counts['rendered']} PDF přegenerováno, "
          f"{counts['unchanged']} beze změny; {counts['isdoc']} ISDOC zapsáno, "
          f"{counts['isdocUnchanged']} beze změny), {counts['skipped']} vynecháno")

    if not args.no_datasets:
        try:
            summaries = build_datasets(BASE_DIR, DATASETS_DIR, identities, docs,
                                       reporting=reporting, projection_path=PROJECTION_FILE,
                                       rate_lists=rate_lists, currencies=currencies)
        except (DatasetBuildError, ReportingError) as e:
            print(f"datová sada: {e}", file=sys.stderr)
            return 1
        for s in summaries:
            print(f"{s.name}: {s.messages} zpráv, {s.exchange_rate_lists} kurzovních lístků, "
                  f"{s.skipped} vynechaných buněk matice → {s.out_dir.relative_to(REPO_ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
