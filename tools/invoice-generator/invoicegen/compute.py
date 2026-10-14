"""Výpočet dokladu: per-řádek totály, rekapitulace DPH po sazbách, celkové
součty. Jen Decimal, ROUND_HALF_UP na setiny (D5 v tasks/testing-phase1.md) —
částky se počítají nezávisle na Shipardu, takže chyba výpočtu v Shipardu
nemůže souhlasit sama se sebou.

`vatTreatment` říká, jak doklad pojednává DPH:
  standard              sazby, ceny bez DPH; rekapitulace základ → daň
  pricesWithVat         sazby, ceny s DPH (účtenka); rekapitulace celkem → daň
  reverseCharge         0 % + přenesení daňové povinnosti / reverse charge
  exemptIntraCommunity  0 % + osvobozené dodání do EU (čl. 138)
  noVat                 DPH vůbec (neplátce, třetí země); bez rekapitulace
"""

from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP

CENTS = Decimal("0.01")
WHOLE = Decimal("1")

VALID_VAT_PCTS = frozenset({0, 12, 21})
VAT_RATES: dict[str, frozenset[int]] = {
    "cz": VALID_VAT_PCTS,
    "de": frozenset({0, 7, 19}),
}
VAT_TREATMENTS = ("standard", "pricesWithVat", "reverseCharge", "exemptIntraCommunity", "noVat")
PRICE_CALC_MODES = ("fromUnitPrice", "fromTotal")


def _decimal(value, what: str) -> Decimal:
    assert not isinstance(value, float), f"{what}: float ve vstupu — čísla musí být Decimal nebo int"
    return Decimal(str(value))


def _row(row: dict, vat_treatment: str, allowed_pcts: frozenset[int]) -> dict:
    qty = _decimal(row["qty"], "qty")
    unit_price = _decimal(row["unitPrice"], "unitPrice")
    assert qty > 0, f"neplatné množství {qty}"

    vat_pct_raw = row.get("vatPct")
    if vat_treatment == "noVat":
        assert vat_pct_raw is None, f"noVat: řádek nesmí mít sazbu ({vat_pct_raw})"
        vat_pct = None
    else:
        assert vat_pct_raw is not None, "chybí vatPct"
        vat_pct = int(vat_pct_raw)
        assert vat_pct in allowed_pcts, f"neznámá sazba DPH {vat_pct}"
        if vat_treatment in ("reverseCharge", "exemptIntraCommunity"):
            assert vat_pct == 0, f"{vat_treatment}: řádek musí mít 0 % ({vat_pct})"

    gross = (qty * unit_price).quantize(CENTS, rounding=ROUND_HALF_UP)
    mode = row.get("priceCalcMode", "fromUnitPrice")
    assert mode in PRICE_CALC_MODES, f"neznámý priceCalcMode {mode}"
    discount_raw = row.get("discountPct")

    if mode == "fromTotal":
        assert "totalPrice" in row, "fromTotal: chybí totalPrice"
        assert discount_raw is None, "fromTotal: sleva se nekombinuje s autoritativní cenou"
        total = _decimal(row["totalPrice"], "totalPrice")
        assert total == total.quantize(CENTS), f"totalPrice {total} není na setiny"
        assert abs(gross - total) < WHOLE, (
            f"totalPrice {total} se od qty × unitPrice = {gross} liší o 1,00 a víc"
        )
        discount_pct = None
        discount_amount = None
    else:
        assert "totalPrice" not in row, "totalPrice jen s priceCalcMode fromTotal"
        if discount_raw is None:
            discount_pct = None
            total = gross
            discount_amount = None
        else:
            discount_pct = _decimal(discount_raw, "discountPct")
            assert 0 < discount_pct <= 100, f"sleva {discount_pct} % mimo (0; 100]"
            total = (qty * unit_price * (1 - discount_pct / 100)).quantize(CENTS, rounding=ROUND_HALF_UP)
            discount_amount = gross - total

    return {
        **row, "qty": qty, "unitPrice": unit_price, "vatPct": vat_pct, "total": total,
        "gross": gross, "discountPct": discount_pct, "discountAmount": discount_amount,
        "priceCalcMode": mode,
    }


def compute(invoice: dict, *, vat_treatment: str = "standard",
            allowed_pcts: frozenset[int] = VALID_VAT_PCTS) -> dict:
    """Per-řádek totály, rekapitulace po sazbách, celkové součty.

    Výchozí volání `compute(doc)` odpovídá demo kolekci (standard, CZ sazby).
    U `noVat` je `recap` prázdný a `totalBase` / `totalVat` None.
    """
    assert vat_treatment in VAT_TREATMENTS, f"neznámý vatTreatment {vat_treatment}"
    rows = [_row(row, vat_treatment, allowed_pcts) for row in invoice["rows"]]
    assert rows, "doklad bez řádků"
    rows_sum = sum(r["total"] for r in rows)

    recap: dict[int, dict] = {}
    if vat_treatment == "noVat":
        total_base = None
        total_vat = None
        computed_total = rows_sum
    else:
        for row in rows:
            r = recap.setdefault(row["vatPct"], {"sum": Decimal("0")})
            r["sum"] += row["total"]
        for pct, r in recap.items():
            if vat_treatment == "pricesWithVat":
                r["total"] = r.pop("sum")
                r["tax"] = (r["total"] * pct / (100 + pct)).quantize(CENTS, rounding=ROUND_HALF_UP)
                r["base"] = r["total"] - r["tax"]
            else:
                r["base"] = r.pop("sum")
                r["tax"] = (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)
                r["total"] = r["base"] + r["tax"]
        total_base = sum(r["base"] for r in recap.values())
        total_vat = sum(r["tax"] for r in recap.values())
        computed_total = total_base + total_vat

    rounding_mode = invoice.get("rounding", "none")
    if rounding_mode == "czk":
        assert vat_treatment != "noVat", "noVat: zaokrouhlení celkové částky se nepoužívá"
        total_amount = computed_total.quantize(WHOLE, rounding=ROUND_HALF_UP)
        total_rounding = total_amount - computed_total
        assert CENTS < abs(total_rounding) < WHOLE, (
            f"zaokrouhlení {total_rounding} mimo pásmo (0,01; 1,00) — "
            f"derivace total_rounding_mode by nezabrala, uprav částky"
        )
    elif rounding_mode == "none":
        total_amount = computed_total
        total_rounding = Decimal("0")
    else:
        raise AssertionError(f"neznámý rounding: {rounding_mode}")

    # Integrita: Σ řádků == Σ rekapitulace == totals
    if vat_treatment == "noVat":
        assert total_amount == rows_sum
    elif vat_treatment == "pricesWithVat":
        assert sum(r["total"] for r in recap.values()) == rows_sum
    else:
        assert rows_sum == total_base
    if recap:
        assert sum(r["base"] for r in recap.values()) == total_base
        assert sum(r["total"] for r in recap.values()) == computed_total
        for pct, r in recap.items():
            assert r["base"] + r["tax"] == r["total"]
            if vat_treatment == "pricesWithVat":
                assert r["tax"] == (r["total"] * pct / (100 + pct)).quantize(CENTS, rounding=ROUND_HALF_UP)
            else:
                assert r["tax"] == (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)

    return {
        "rows": rows,
        "recap": {pct: {"base": r["base"], "tax": r["tax"], "total": r["total"]}
                  for pct, r in sorted(recap.items(), reverse=True)},
        "totalBase": total_base,
        "totalVat": total_vat,
        "totalAmount": total_amount,
        "totalRounding": total_rounding,
        "hasDiscount": any(r["discountPct"] is not None for r in rows),
        "vatTreatment": vat_treatment,
    }
