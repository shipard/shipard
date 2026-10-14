#!/usr/bin/env python3
"""Build korpusu testovacích dokladů (tasks/testing-phase1.md, docs/test-corpus.md).

Pro každý datový soubor data/NNNN-<slug>.jsonc a každý profil vlastní firmy
(identities.jsonc, bez skipProfiles) spočítá doklad (Decimal), zapíše
očekávaný výsledek expected/<profil>/NNNN-<slug>.json (vždy — je
deterministický) a vyrenderuje out/<profil>/NNNN-<slug>.pdf přes Gotenberg
jen tehdy, když PDF chybí, hash HTML + voleb papíru se liší od
build-state.json, nebo s --force (PDF z Chromia není bajtově deterministické).

    python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities]

--verify-identities ověří identity v ARES a VIES (síť; po změně identit),
při úspěchu zapíše dnešní datum do verifiedAt a pokračuje buildem; selhání
= exit 1, nedostupný registr = exit 2 (identity se neoznačí jako ověřené).
Env: GOTENBERG_URL (default http://10.199.6.210:3000). Exit != 0 = chyba.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from decimal import Decimal
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
CORPUS_DIR = BASE_DIR.parent
REPO_ROOT = BASE_DIR.parents[2]
sys.path.insert(0, str(REPO_ROOT / "tools" / "invoice-generator"))

from invoicegen.checks import validate_identities  # noqa: E402
from invoicegen.compute import VAT_RATES, compute  # noqa: E402
from invoicegen.corpus import (  # noqa: E402
    PROFILES, Cell, DataFileError, SkippedCell, cells_for, validate_data_file, validate_variant_pairs,
)
from invoicegen.expected import apply_projection, build_expected, dumps_expected, load_projection  # noqa: E402
from invoicegen.jsonc import load_jsonc  # noqa: E402
from invoicegen.registries import (  # noqa: E402
    RegistryUnavailable, format_check, update_verified_at, verify_identities,
)
from invoicegen.render import (  # noqa: E402
    NNBSP, esc, fmt_date, fmt_iban, fmt_money, fmt_phone, fmt_zip, pdf_options_from_html, render_hash,
    render_html, render_kv_rows, render_pdf, render_rows, render_vat_recap,
)

STATE_FILE = BASE_DIR / "build-state.json"
TAXONOMY_FILE = REPO_ROOT / "modules" / "core" / "exchange" / "config" / "contentTags.jsonc"
DEMO_IDENTITIES = REPO_ROOT / "demo" / "invoices" / "suppliers.jsonc"
TEMPLATE_LOCALE = {"cs-a": "cs", "cs-b": "cs", "cs-c": "cs", "receipt": "cs", "en": "en"}

COUNTRY_NAMES = {
    "cs": {"cz": "Česká republika", "de": "Německo", "us": "USA"},
    "en": {"cz": "Czech Republic", "de": "Germany", "us": "United States"},
}

LABELS = {
    "cs": {
        "titleTax": "FAKTURA — DAŇOVÝ DOKLAD", "titlePlain": "FAKTURA",
        "titleReceipt": "ÚČTENKA — ZJEDNODUŠENÝ DAŇOVÝ DOKLAD",
        "supplier": "Dodavatel", "customer": "Odběratel",
        "companyId": "IČO", "vatId": "DIČ",
        "account": "Bankovní účet", "iban": "IBAN", "bic": "BIC",
        "vs": "Variabilní symbol", "ks": "Konstantní symbol", "ss": "Specifický symbol",
        "method": "Způsob úhrady", "currency": "Měna",
        "bankTransfer": "Převodem", "card": "Platební kartou", "cash": "Hotově",
        "issue": "Datum vystavení", "tax": "Datum usk. zdan. plnění", "due": "Datum splatnosti",
        "period": "Období plnění", "saleDate": "Datum prodeje",
        "hText": "Označení dodávky", "hQty": "Množství", "hUnit": "MJ", "hPrice": "Cena/MJ",
        "hDisc": "Sleva", "hVat": "DPH", "hTotalNet": "Celkem bez DPH", "hTotalGross": "Celkem s DPH",
        "hTotal": "Celkem",
        "rPct": "Sazba", "rBase": "Základ", "rTax": "DPH", "rTotal": "Celkem",
        "tBase": "Základ daně", "tVat": "DPH celkem", "tRounding": "Zaokrouhlení",
        "tGrand": "K úhradě celkem", "tGrandReceipt": "Celkem k úhradě",
        "clauseReverseCharge": "Daň odvede zákazník (§ 92a zákona o DPH — režim přenesení daňové povinnosti).",
        "clauseNoVat": "Nejsem plátce DPH.",
    },
    "en": {
        "titleTax": "INVOICE", "titlePlain": "INVOICE", "titleReceipt": "RECEIPT",
        "supplier": "Supplier", "customer": "Bill to",
        "companyId": "Company ID", "vatId": "VAT ID",
        "account": "Bank account", "iban": "IBAN", "bic": "BIC",
        "vs": "Payment reference", "ks": "Constant symbol", "ss": "Specific symbol",
        "method": "Payment", "currency": "Currency",
        "bankTransfer": "Bank transfer", "card": "Paid by card", "cash": "Cash",
        "issue": "Invoice date", "tax": "Date of supply", "due": "Due date",
        "period": "Service period", "saleDate": "Date",
        "hText": "Description", "hQty": "Qty", "hUnit": "Unit", "hPrice": "Unit price",
        "hDisc": "Discount", "hVat": "VAT", "hTotalNet": "Amount (net)", "hTotalGross": "Amount (gross)",
        "hTotal": "Amount",
        "rPct": "VAT rate", "rBase": "Net", "rTax": "VAT", "rTotal": "Gross",
        "tBase": "Subtotal (net)", "tVat": "VAT", "tRounding": "Rounding",
        "tGrand": "Total due", "tGrandReceipt": "Total",
        "clauseReverseCharge": "Reverse charge — VAT to be accounted for by the recipient "
                               "(Art. 196 of Council Directive 2006/112/EC).",
        "clauseExempt": "Intra-Community supply of goods exempt from VAT "
                        "(Art. 138 of Council Directive 2006/112/EC).",
    },
}


# ── Hodnoty pro šablony ──────────────────────────────────────────────────────

def money(value: Decimal, currency: str, locale: str) -> str:
    unit = "Kč" if currency == "CZK" and locale == "cs" else currency
    return f"{fmt_money(value, locale)}{NNBSP}{unit}"


def address_html(party: dict, locale: str, *, with_country: bool) -> str:
    a = party["address"]
    hints = party.get("print") or {}
    if a["country"] == "us":
        lines = [a["street"], f"{a['city']}, {hints.get('state', '')} {a['zip']}".replace("  ", " ")]
    else:
        lines = [f"{a['street']} {a['houseNumber']}", f"{fmt_zip(a['zip'], a['country'])} {a['city']}"]
    if with_country:
        lines.append(COUNTRY_NAMES[locale][a["country"]])
    return "<br>".join(esc(line) for line in lines)


def ids_html(party: dict, labels: dict) -> str:
    parts = []
    if party.get("companyId"):
        parts.append(f'<span class="nw">{labels["companyId"]}: <span class="num">{esc(party["companyId"])}</span></span>')
    if party.get("vatId"):
        parts.append(f'<span class="nw">{labels["vatId"]}: <span class="num">{esc(party["vatId"])}</span></span>')
    return " · ".join(parts)


def contact_html(party: dict) -> str:
    c = party.get("contact") or {}
    parts = [f'<span class="nw">{esc(x)}</span>'
             for x in (c.get("email"), fmt_phone(c["phone"]) if c.get("phone") else None, c.get("web")) if x]
    return " · ".join(parts)


def build_values(cell: Cell, computed: dict) -> dict[str, str]:
    data = cell.data
    supplier = cell.supplier
    locale = TEMPLATE_LOCALE[cell.template]
    labels = LABELS[locale]
    treatment = data["vatTreatment"]
    currency = data["currency"]
    foreign = supplier["country"] != "cz"
    payment = data.get("payment") or {}
    method = payment.get("method", "bankTransfer")
    dates = data["dates"]
    bank = supplier.get("bankAccount") or {}
    vat_column = treatment != "noVat"
    gross_rows = treatment == "pricesWithVat"

    if cell.receipt:
        title = labels["titleReceipt"]
    elif treatment == "noVat":
        title = labels["titlePlain"]
    else:
        title = labels["titleTax"]

    payment_rows: list[tuple[str, str]] = []
    if method == "bankTransfer":
        if bank.get("accountNumber"):
            payment_rows.append((labels["account"], f'<span class="num">{esc(bank["accountNumber"])}</span>'))
        if bank.get("iban"):
            payment_rows.append((labels["iban"], f'<span class="num">{esc(fmt_iban(bank["iban"]))}</span>'))
        if bank.get("bic"):
            payment_rows.append((labels["bic"], f'<span class="num">{esc(bank["bic"])}</span>'))
    if data.get("paymentReference"):
        payment_rows.append((labels["vs"], f'<span class="num">{esc(data["paymentReference"])}</span>'))
    if payment.get("constantSymbol"):
        payment_rows.append((labels["ks"], f'<span class="num">{esc(payment["constantSymbol"])}</span>'))
    if payment.get("specificSymbol"):
        payment_rows.append((labels["ss"], f'<span class="num">{esc(payment["specificSymbol"])}</span>'))
    payment_rows.append((labels["method"], labels[method]))
    if not cell.receipt:
        payment_rows.append((labels["currency"], currency))

    date_rows: list[tuple[str, str]] = []
    if cell.receipt:
        date_rows.append((labels["saleDate"], f'<span class="num">{fmt_date(dates["issue"], locale)}</span>'))
    else:
        date_rows.append((labels["issue"], f'<span class="num">{fmt_date(dates["issue"], locale)}</span>'))
        if dates.get("tax"):
            date_rows.append((labels["tax"], f'<span class="num">{fmt_date(dates["tax"], locale)}</span>'))
        if dates.get("due"):
            date_rows.append((labels["due"], f'<span class="num">{fmt_date(dates["due"], locale)}</span>'))
        if dates.get("periodFrom"):
            date_rows.append((labels["period"], f'<span class="num">{fmt_date(dates["periodFrom"], locale)} – '
                                                f'{fmt_date(dates["periodTo"], locale)}</span>'))

    head = [f'<th class="h-text">{labels["hText"]}</th>', f'<th class="h-qty">{labels["hQty"]}</th>',
            f'<th class="h-unit">{labels["hUnit"]}</th>', f'<th class="h-price">{labels["hPrice"]}</th>']
    if computed["hasDiscount"]:
        head.append(f'<th class="h-disc">{labels["hDisc"]}</th>')
    if vat_column:
        head.append(f'<th class="h-vat">{labels["hVat"]}</th>')
    total_label = labels["hTotal"] if treatment == "noVat" else labels["hTotalGross"] if gross_rows else labels["hTotalNet"]
    head.append(f'<th class="h-total">{total_label}</th>')

    if computed["recap"]:
        recap_block = (
            '<table class="vat-recap">\n\t\t\t<thead>\n\t\t\t\t<tr>'
            f'<th>{labels["rPct"]}</th><th>{labels["rBase"]}</th><th>{labels["rTax"]}</th><th>{labels["rTotal"]}</th>'
            '</tr>\n\t\t\t</thead>\n\t\t\t<tbody>\n'
            + render_vat_recap(computed, locale=locale)
            + "\n\t\t\t</tbody>\n\t\t</table>"
        )
    else:
        recap_block = ""

    totals: list[str] = []
    if treatment != "noVat":
        totals.append(f'<tr><th>{labels["tBase"]}</th><td>{money(computed["totalBase"], currency, locale)}</td></tr>')
        totals.append(f'<tr><th>{labels["tVat"]}</th><td>{money(computed["totalVat"], currency, locale)}</td></tr>')
        if computed["totalRounding"] != 0:
            totals.append(f'<tr><th>{labels["tRounding"]}</th><td>{money(computed["totalRounding"], currency, locale)}</td></tr>')
    grand_label = labels["tGrandReceipt"] if cell.receipt else labels["tGrand"]
    totals.append(f'<tr class="grand"><th>{grand_label}</th><td>{money(computed["totalAmount"], currency, locale)}</td></tr>')

    clause = ""
    if treatment == "reverseCharge":
        clause = labels["clauseReverseCharge"]
    elif treatment == "exemptIntraCommunity":
        clause = labels["clauseExempt"]
    elif treatment == "noVat" and not foreign:
        clause = labels["clauseNoVat"]

    note = (data.get("notes") or {}).get("onDocument")
    profile = cell.profile_identity
    return {
        "docTitle": title,
        "docNumber": esc(data["docNumber"]),
        "supplierLabel": labels["supplier"],
        "supplierName": esc(supplier["name"]),
        "supplierAddress": address_html(supplier, locale, with_country=foreign or locale == "en"),
        "supplierIds": ids_html(supplier, labels),
        "supplierContact": contact_html(supplier),
        "supplierCourtReg": esc(supplier["courtRegistration"]) if supplier.get("courtRegistration") else "",
        "customerLabel": labels["customer"],
        "customerName": esc(profile["name"]),
        "customerAddress": address_html(profile, locale, with_country=locale == "en"),
        "customerIds": ids_html(profile, labels),
        "paymentRows": render_kv_rows(payment_rows),
        "dateRows": render_kv_rows(date_rows),
        "itemsHead": "<tr>" + "".join(head) + "</tr>",
        "rows": render_rows(computed, locale=locale, unit_display=None,
                            discount_column=computed["hasDiscount"], vat_column=vat_column),
        "vatRecapBlock": recap_block,
        "totalsRows": "\n\t\t\t".join(totals),
        "vatClause": f'<div class="clause">{esc(clause)}</div>' if clause else "",
        "noteBlock": f'<div class="note">{esc(note)}</div>' if note else "",
        "accent": supplier.get("accent", "#0e7490"),
    }


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

    projection = load_projection(CORPUS_DIR / "projection.jsonc")
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
    return 0


if __name__ == "__main__":
    sys.exit(main())
