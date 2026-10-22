#!/usr/bin/env python3
"""Generátor fiktivních přijatých faktur (PDF) pro demo videa.

Zadání: tasks/demo-invoices.md. Načte identity ze suppliers.jsonc a data
faktur z data/*.jsonc, spočítá DPH a součty (Decimal, ROUND_HALF_UP),
ověří kontrolní součty identifikátorů, vyrenderuje HTML ze šablony
(templates/{a,b,c}.html, placeholdery {{key}}) a přes Gotenberg
(Chromium, docs/render.md) uloží out/NNNN-<slug>.pdf.

Výpočty, kontrolní součty, JSONC, render i skládání hodnot pro demo
šablony (`invoicegen.demoprint`) jsou ve sdíleném balíku `invoicegen`
(tools/invoice-generator/, jen stdlib) — tady zůstává jen smyčka přes
data. Použití:

    python3 demo/invoices/build.py

Env: GOTENBERG_URL (default http://10.199.6.210:3000).
Exit != 0 = kterýkoli assert nebo render selhal.
"""

from __future__ import annotations

import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE_DIR.parents[1] / "tools" / "invoice-generator"))

from invoicegen.checks import validate_party  # noqa: E402
from invoicegen.compute import compute  # noqa: E402
from invoicegen.demoprint import invoice_values as build_values, validate_header  # noqa: E402, F401
from invoicegen.jsonc import load_jsonc  # noqa: E402
from invoicegen.render import fmt_money, render_html, render_pdf  # noqa: E402


def main() -> int:
    identities = load_jsonc(BASE_DIR / "suppliers.jsonc")
    own = identities["ownCompany"]
    validate_party("ownCompany", own, with_bank=False)
    for key, supplier in identities["suppliers"].items():
        validate_party(key, supplier, with_bank=True)

    templates = {
        p.stem: p.read_text(encoding="utf-8")
        for p in sorted((BASE_DIR / "templates").glob("*.html"))
    }

    data_files = sorted((BASE_DIR / "data").glob("*.jsonc"))
    assert data_files, "data/*.jsonc nenalezena"
    out_dir = BASE_DIR / "out"
    out_dir.mkdir(exist_ok=True)

    for path in data_files:
        invoice = load_jsonc(path)
        validate_header(invoice)
        supplier = identities["suppliers"][invoice["supplier"]]
        computed = compute(invoice)

        template = templates[invoice["template"]]
        rendered = render_html(template, build_values(invoice, supplier, own, computed))
        pdf = render_pdf(rendered)

        out_path = out_dir / (path.stem + ".pdf")
        out_path.write_bytes(pdf)

        rounding_note = (
            f", zaokrouhlení {fmt_money(computed['totalRounding'])}"
            if computed["totalRounding"] != 0 else ""
        )
        print(
            f"{path.stem}: základ {fmt_money(computed['totalBase'])} "
            f"+ DPH {fmt_money(computed['totalVat'])}{rounding_note} "
            f"= {fmt_money(computed['totalAmount'])} Kč "
            f"→ {out_path.relative_to(BASE_DIR)} ({len(pdf) // 1024} kB)"
        )

    print(f"OK: {len(data_files)} PDF v {out_dir.relative_to(Path.cwd()) if out_dir.is_relative_to(Path.cwd()) else out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
