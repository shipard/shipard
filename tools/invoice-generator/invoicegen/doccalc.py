"""Zrcadlo výpočtu dokladu v Shipardu (`DocDocument::computeAmounts`) pro
tvar dumpu: řádkové `computed`, rekapitulace DPH metodou „z hlavičky“
(`vat_calc_source = 0`: daň jednou ze součtu cen skupiny), součty hlavičky,
zaokrouhlení celkové částky a dorovnání řádků na rekapitulaci
(`reconcileRowsToRecap`). Jen `Decimal`, ROUND_HALF_UP = PHP `round()`.

`compute.py` počítá doklad „jak ho vidí papír“ (očekávaný výsledek
analýzy); tenhle modul počítá, co po seedu uloží `DocDocument` — obě
cesty se musí shodnout v součtech, jinak by dump nesedl na sadu.
"""

from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP

from .model import DocRec, RecapLine, RowRec

CENTS = Decimal("0.01")
WHOLE = Decimal("1")
HUNDRED = Decimal("100")

# DPH kódy bez placené daně (výstup do EU, osvobozené, nulové) — daň 0,
# celkem = základ. Tuzemské samovyměření (cz-115/117, vstupní RC) sada nemá.
NO_TAX_CODES = frozenset({"cz-112", "cz-122", "cz-123", "cz-201", "cz-202"})


def r2(value: Decimal) -> Decimal:
    return value.quantize(CENTS, rounding=ROUND_HALF_UP)


def row_price(row: RowRec) -> None:
    """`DocRowCalculator::computePrice` (fromUnitPrice): cena řádku =
    round(qty × unitPrice, 2); kontační řádek nese částku přímo."""
    if row.quantity is not None and row.unit_price is not None:
        row.total_price = r2(row.quantity * row.unit_price)
    elif row.total_price is None:
        raise ValueError(f"řádek {row.description!r} nemá cenu")


def row_vat(row: RowRec, vat_mode: str) -> None:
    """`DocRowCalculator::computeVat`: mode none nebo řádek bez kódu /
    sazby → základ = celkem = cena; mode fromBase → daň = základ × pct;
    mode fromTotal → základ = celkem / (1 + pct)."""
    net = row.total_price if row.total_price is not None else Decimal("0")
    pct = row.vat_pct or 0
    if vat_mode == "none" or not row.vat_code or pct == 0:
        row.vat_base, row.vat_amount, row.vat_total = net, Decimal("0.00"), net
        return
    if row.vat_code in NO_TAX_CODES:
        row.vat_base, row.vat_amount, row.vat_total = net, Decimal("0.00"), net
        return
    rate = Decimal(pct) / HUNDRED
    if vat_mode == "fromBase":
        amount = r2(net * rate)
        row.vat_base, row.vat_amount, row.vat_total = net, amount, r2(net + amount)
        return
    base = r2(net / (1 + rate))
    row.vat_base, row.vat_amount, row.vat_total = base, r2(net - base), net


def _recap(rows: list[RowRec], vat_mode: str) -> list[RecapLine]:
    """`buildVatRecapitulation` metodou z hlavičky: skupiny (kód, sazba)
    v pořadí výskytu, cena = Σ cen řádků, daň jednou ze součtu."""
    grouped: dict[tuple[str, int], Decimal] = {}
    for row in rows:
        if not row.vat_code:
            continue
        key = (row.vat_code, row.vat_pct or 0)
        grouped[key] = grouped.get(key, Decimal("0")) + (row.total_price or Decimal("0"))
    recap: list[RecapLine] = []
    for (code, pct), price in grouped.items():
        price = r2(price)
        rate = Decimal(pct) / HUNDRED
        if code in NO_TAX_CODES or pct == 0:
            base, tax = price, Decimal("0.00")
        elif vat_mode == "fromTotal":
            base = r2(price / (1 + rate))
            tax = r2(price - base)
        else:
            base, tax = price, r2(price * rate)
        payable = Decimal("0.00") if code in NO_TAX_CODES else tax
        recap.append(RecapLine(vat_code=code, vat_pct=pct, base=base, tax=tax, total=r2(base + payable)))
    return recap


def _reconcile(rows: list[RowRec], recap: list[RecapLine], vat_mode: str) -> None:
    """`reconcileRowsToRecap` + `finalizeRowVatTotals`: rozdíl součtu
    řádků proti rekapitulaci nese poslední řádek skupiny s nenulovou
    hodnotou; celkem řádku = základ + daň jen tam, kde se daň platí."""
    for line in recap:
        members = [r for r in rows if r.vat_code == line.vat_code and (r.vat_pct or 0) == line.vat_pct]
        if not members:
            continue
        sum_base = sum((r.vat_base or Decimal("0")) for r in members)
        sum_amount = sum((r.vat_amount or Decimal("0")) for r in members)
        diff_base = r2(line.base - sum_base)
        if diff_base != 0:
            target = next((r for r in reversed(members) if r.vat_base), members[-1])
            target.vat_base = r2((target.vat_base or Decimal("0")) + diff_base)
        diff_amount = r2(line.tax - sum_amount)
        if diff_amount != 0:
            target = next((r for r in reversed(members) if r.vat_amount), members[-1])
            target.vat_amount = r2((target.vat_amount or Decimal("0")) + diff_amount)
    for row in rows:
        if vat_mode != "fromTotal" and row.vat_code not in NO_TAX_CODES and row.vat_code:
            row.vat_total = r2((row.vat_base or Decimal("0")) + (row.vat_amount or Decimal("0")))


def calc_document(doc: DocRec) -> None:
    """Doplní řádkům cenu a DPH, dokladu rekapitulaci a součty — jako
    `DocDocument::computeAmounts` při uložení."""
    for row in doc.rows:
        row_price(row)
        row_vat(row, doc.vat_mode)
    recap = _recap(doc.rows, doc.vat_mode)
    base = r2(sum((line.base for line in recap), Decimal("0")))
    vat = r2(sum((line.tax for line in recap if line.vat_code not in NO_TAX_CODES), Decimal("0")))
    total = r2(sum((line.total for line in recap), Decimal("0")))
    if not recap:
        # Doklad bez DPH kódů: součty z řádků mimo rekapitulaci
        # (`sumRowsOutsideRecap`); účetní doklad (`AccountingDocument`)
        # sčítá jen stranu MD — obrat dokladu, ne obě strany.
        counted = [r for r in doc.rows if r.acc_side != "credit"] if doc.doc_type == "cmnbkp" else doc.rows
        base = r2(sum((r.total_price or Decimal("0")) for r in counted))
        vat = Decimal("0.00")
        total = base
    rounding = Decimal("0.00")
    if doc.rounding == "czk":
        rounded = total.quantize(WHOLE, rounding=ROUND_HALF_UP)
        rounding = r2(rounded - total)
        total = rounded
    _reconcile(doc.rows, recap, doc.vat_mode)
    doc.recap = recap
    doc.total_base, doc.total_vat, doc.total_amount, doc.total_rounding = base, vat, total, rounding
