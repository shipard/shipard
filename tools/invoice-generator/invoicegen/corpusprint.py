"""Hodnoty pro šablony korpusu (`tests/corpus/cz/templates/*.html`,
placeholdery `{{docTitle}}`, `{{itemsHead}}`, `{{paymentRows}}`, …) —
lokalizované popisky, adresy se zemí, platební a datové řádky, klauzule
přenesení daňové povinnosti / osvobození, měna u částek. Původně
`build_values` v `tests/corpus/cz/build.py`; sdílené s demo sadou pro
faktury zahraničních dodavatelů v angličtině (`expand.py`, #131 D28).
Výstup korpusu je pinnutý hashem HTML v `tests/corpus/cz/build-state.json`
— tady se nic nemění, jen přidává.

Vstup: datový soubor korpusu (`data`: docNumber, dates, currency,
vatTreatment, payment, paymentReference, notes), identita dodavatele
a vlastní firmy v kanonickém tvaru (`identities.jsonc`), `compute.compute`.
"""

from __future__ import annotations

from decimal import Decimal

from .render import NNBSP, esc, fmt_date, fmt_iban, fmt_money, fmt_phone, fmt_zip, render_kv_rows, render_rows, render_vat_recap

__all__ = ["COUNTRY_NAMES", "LABELS", "address_html", "contact_html", "ids_html", "money", "template_values"]

COUNTRY_NAMES = {
    "cs": {"cz": "Česká republika", "de": "Německo", "nl": "Nizozemsko", "sk": "Slovensko", "us": "USA"},
    "en": {"cz": "Czech Republic", "de": "Germany", "nl": "Netherlands", "sk": "Slovakia", "us": "United States"},
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


def template_values(data: dict, supplier: dict, profile: dict, computed: dict, *, locale: str,
                    receipt: bool = False) -> dict[str, str]:
    """Hodnoty placeholderů šablony korpusu: `data` ve tvaru datového
    souboru korpusu, `supplier` / `profile` kanonické identity, `computed`
    z `compute.compute`, `locale` cs | en (`corpus.TEMPLATE_LOCALE`)."""
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

    if receipt:
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
    if not receipt:
        payment_rows.append((labels["currency"], currency))

    date_rows: list[tuple[str, str]] = []
    if receipt:
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
    grand_label = labels["tGrandReceipt"] if receipt else labels["tGrand"]
    totals.append(f'<tr class="grand"><th>{grand_label}</th><td>{money(computed["totalAmount"], currency, locale)}</td></tr>')

    clause = ""
    if treatment == "reverseCharge":
        clause = labels["clauseReverseCharge"]
    elif treatment == "exemptIntraCommunity":
        clause = labels["clauseExempt"]
    elif treatment == "noVat" and not foreign:
        clause = labels["clauseNoVat"]

    note = (data.get("notes") or {}).get("onDocument")
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
