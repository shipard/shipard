"""Hodnoty pro demo šablony faktur (`demo/invoices/templates/{a,b,c}.html`,
placeholdery `{{key}}`) — původně v `demo/invoices/build.py`, sdílené
s generátorem demo sad (přijaté faktury dodavatelů jako PDF, `demo.py`).

Vstup je plochá demo identita (`demo/invoices/suppliers.jsonc`: name,
street, city, zip, ico, dic, account, iban, email, courtReg, accent)
a faktura ve tvaru `demo/invoices/data/*.jsonc`. Výstup HTML dema je
pinnutý regresním testem (`tests/test_demo_regression.py`) — tady se nic
nemění, jen přidává.
"""

from __future__ import annotations

import re
from datetime import date
from decimal import Decimal

from .render import NNBSP, esc, fmt_date, fmt_iban, fmt_money, fmt_zip, render_rows, render_vat_recap


def validate_header(invoice: dict) -> None:
    vs = invoice["variableSymbol"]
    assert re.fullmatch(r"\d{1,10}", vs), f"VS '{vs}' — jen číslice, max 10"
    assert invoice["docNumber"], "chybí docNumber"
    assert invoice["currency"] == "CZK", "sada je CZK-only"
    for key in ("issue", "tax", "due"):
        date.fromisoformat(invoice["dates"][key])  # ValueError = nevalidní


def invoice_values(invoice: dict, supplier: dict, own: dict, computed: dict) -> dict[str, str]:
    rounding_row = ""
    if computed["totalRounding"] != 0:
        rounding_row = (
            f"<tr><th>Zaokrouhlení</th><td>{fmt_money(computed['totalRounding'])}"
            f"{NNBSP}Kč</td></tr>"
        )
    return {
        "supplierName": esc(supplier["name"]),
        "supplierStreet": esc(supplier["street"]),
        "supplierZipCity": esc(f"{supplier['zip']} {supplier['city']}"),
        "supplierIco": supplier["ico"],
        "supplierDic": supplier["dic"],
        "supplierEmail": esc(supplier["email"]),
        "supplierCourtReg": esc(supplier["courtReg"]),
        "supplierAccount": supplier["account"],
        "supplierIban": fmt_iban(supplier["iban"]),
        "customerName": esc(own["name"]),
        "customerStreet": esc(own["street"]),
        "customerZipCity": esc(f"{own['zip']} {own['city']}"),
        "customerIco": own["ico"],
        "customerDic": own["dic"],
        "docNumber": esc(invoice["docNumber"]),
        "vs": invoice["variableSymbol"],
        "issueDate": fmt_date(invoice["dates"]["issue"]),
        "taxDate": fmt_date(invoice["dates"]["tax"]),
        "dueDate": fmt_date(invoice["dates"]["due"]),
        "rows": render_rows(computed),
        "vatRecap": render_vat_recap(computed),
        "totalBase": fmt_money(computed["totalBase"]),
        "totalVat": fmt_money(computed["totalVat"]),
        "roundingRow": rounding_row,
        "totalAmount": fmt_money(computed["totalAmount"]),
        "note": esc(invoice["notes"]["onDocument"]),
        "accent": supplier.get("accent", "#0e7490"),
    }


# ── Demo sady: identita korpusu → plochý tvar dema, dokumenty spisovny ─────────

def flat_party(identity: dict, accent: str | None = None) -> dict:
    """Kanonická identita (name, companyId, vatId, address{}, contact{},
    bankAccount{}) → plochý tvar demo šablon (`suppliers.jsonc`). Klíč
    `accent` jen když je — `invoice_values` by jinak vytiskl „None“."""
    address = identity.get("address") or {}
    street = address.get("street") or ""
    if address.get("houseNumber"):
        street = f"{street} {address['houseNumber']}"
    bank = identity.get("bankAccount") or {}
    contact = identity.get("contact") or {}
    flat = {
        "name": identity["name"],
        "street": street,
        "city": address.get("city") or "",
        "zip": fmt_zip(str(address.get("zip") or ""), str(address.get("country") or identity.get("country") or "")),
        "ico": identity.get("companyId") or "",
        "dic": identity.get("vatId") or identity.get("taxId") or "",
        "account": bank.get("accountNumber") or "",
        "iban": bank.get("iban") or "",
        "email": contact.get("email") or "",
        "courtReg": identity.get("courtRegistration") or "",
    }
    if accent:
        flat["accent"] = accent
    return flat


def _party_ids(identity: dict) -> str:
    company_id = identity.get("companyId")
    vat_id = identity.get("vatId")
    parts = []
    if company_id:
        parts.append(f"IČO {company_id}")
    if vat_id:
        parts.append(("DIČ " if identity.get("country") == "cz" else "VAT ID ") + vat_id)
    return " · ".join(parts)


def _party_address(identity: dict) -> str:
    flat = flat_party(identity)
    return f"{flat['street']}, {flat['zip']} {flat['city']}".strip(", ")


def registry_values(rec, partner_identity: dict, own_flat: dict) -> dict[str, str]:
    """Hodnoty pro šablony `demo/datasets/templates/{contract,insurance,lease}.html`.
    `rec` je `model.RegistryRec`; řádky navíc (`extraRows`) podle druhu
    dokumentu a šablony (tabulka vs. seznam definic)."""
    fields = rec.kind_fields
    row_tpl = "<dt>{}</dt><dd>{}</dd>" if rec.template == "lease" else "<tr><th>{}</th><td>{}</td></tr>"
    extra: list[tuple[str, str]] = []
    if rec.doc_type == "insurance":
        if fields.get("annualPremium") is not None:
            extra.append(("Roční pojistné", f"{fmt_money(Decimal(str(fields['annualPremium'])))}{NNBSP}{fields.get('currency', 'CZK')}"))
        if fields.get("insurer"):
            extra.append(("Pojistitel", str(fields["insurer"])))
    else:
        if fields.get("counterparty"):
            extra.append(("Smluvní strana", str(fields["counterparty"])))
    extra_rows = "\n  ".join(row_tpl.format(esc(k), esc(v)) for k, v in extra)
    subject = fields.get("insuredSubject") if rec.doc_type == "insurance" else fields.get("subject")
    return {
        "title": esc(rec.title),
        "refNumber": esc(rec.ref_number),
        "ownName": esc(own_flat["name"]),
        "ownAddress": esc(f"{own_flat['street']}, {own_flat['zip']} {own_flat['city']}"),
        "ownIco": own_flat["ico"],
        "ownDic": own_flat["dic"],
        "partnerName": esc(partner_identity["name"]),
        "partnerAddress": esc(_party_address(partner_identity)),
        "partnerIds": esc(_party_ids(partner_identity)),
        "subject": esc(str(subject or rec.title)),
        "summary": esc(rec.summary),
        "validFrom": fmt_date(rec.valid_from.isoformat()),
        "validTo": fmt_date(rec.valid_to.isoformat()),
        "noticePeriod": esc(str(fields.get("noticePeriod") or "")),
        "signedDate": fmt_date(rec.signed.isoformat()) if rec.signed else fmt_date(rec.valid_from.isoformat()),
        "extraRows": extra_rows,
    }
