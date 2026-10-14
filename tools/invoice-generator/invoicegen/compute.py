"""Výpočet dokladu: per-řádek totály, rekapitulace DPH po sazbách, celkové
součty. Jen Decimal, ROUND_HALF_UP na setiny (D5 v tasks/testing-phase1.md) —
částky se počítají nezávisle na Shipardu."""

from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP

CENTS = Decimal("0.01")
WHOLE = Decimal("1")

VALID_VAT_PCTS = {0, 12, 21}


def compute(invoice: dict) -> dict:
    """Per-řádek totály, rekapitulace po sazbách, celkové součty."""
    rows = []
    for row in invoice["rows"]:
        qty = Decimal(str(row["qty"]))
        unit_price = Decimal(str(row["unitPrice"]))
        vat_pct = int(row["vatPct"])
        assert vat_pct in VALID_VAT_PCTS, f"neznámá sazba DPH {vat_pct}"
        assert qty > 0, f"neplatné množství {qty}"
        total = (qty * unit_price).quantize(CENTS, rounding=ROUND_HALF_UP)
        rows.append({**row, "qty": qty, "unitPrice": unit_price,
                     "vatPct": vat_pct, "total": total})

    recap: dict[int, dict] = {}
    for row in rows:
        r = recap.setdefault(row["vatPct"], {"base": Decimal("0")})
        r["base"] += row["total"]
    for pct, r in recap.items():
        r["tax"] = (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)
        r["total"] = r["base"] + r["tax"]

    total_base = sum(r["base"] for r in recap.values())
    total_vat = sum(r["tax"] for r in recap.values())
    computed_total = total_base + total_vat

    rounding_mode = invoice.get("rounding", "none")
    if rounding_mode == "czk":
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
    assert sum(r["total"] for r in rows) == total_base
    assert sum(r["base"] for r in recap.values()) == total_base
    assert sum(r["total"] for r in recap.values()) == computed_total
    for pct, r in recap.items():
        assert r["base"] + r["tax"] == r["total"]
        assert r["tax"] == (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)

    return {
        "rows": rows,
        "recap": dict(sorted(recap.items(), reverse=True)),
        "totalBase": total_base,
        "totalVat": total_vat,
        "totalAmount": total_amount,
        "totalRounding": total_rounding,
    }
