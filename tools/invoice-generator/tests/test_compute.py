from __future__ import annotations

import unittest
from decimal import Decimal

from invoicegen.compute import VAT_RATES, compute
from invoicegen.jsonc import load_jsonc
from tests.fixtures import REPO_ROOT

D = Decimal


def doc(rows, **head) -> dict:
    return {"rows": rows, **head}


def row(price, qty=1, pct=21, **extra) -> dict:
    return {"text": "x", "unit": "ks", "qty": qty, "unitPrice": D(price), "vatPct": pct, **extra}


class ComputeDemoTest(unittest.TestCase):
    """Demo kolekce je referenční výpočet — hodnoty ze zadání tasks/demo-invoices.md."""

    EXPECTED = {
        "0001-softaro-it-pausal": ("8500.00", "1785.00", "0", "10285.00", {}),
        "0002-dubretta-material": ("16998.45", "3569.67", "0", "20568.12", {}),
        "0003-papyria-kancelar": ("4207.00", "809.49", "0", "5016.49",
                                  {21: ("3385.00", "710.85"), 12: ("822.00", "98.64")}),
        "0004-energima-elektrina": ("3527.68", "740.81", "-0.49", "4268", {}),
    }

    def test_demo_totals(self) -> None:
        for stem, (base, vat, rounding, amount, recap) in self.EXPECTED.items():
            computed = compute(load_jsonc(REPO_ROOT / "demo" / "invoices" / "data" / f"{stem}.jsonc"))
            self.assertEqual((D(base), D(vat), D(rounding), D(amount)), (
                computed["totalBase"], computed["totalVat"], computed["totalRounding"], computed["totalAmount"]
            ), stem)
            for pct, (rb, rt) in recap.items():
                self.assertEqual((D(rb), D(rt)), (computed["recap"][pct]["base"], computed["recap"][pct]["tax"]))
            self.assertEqual(sorted(computed["recap"], reverse=True), list(computed["recap"]))


class ComputeRulesTest(unittest.TestCase):
    def test_discount_row(self) -> None:
        c = compute(doc([row("18.40", qty=D("1.25"), discountPct=10)]))
        r = c["rows"][0]
        self.assertEqual(D("23.00"), r["gross"])
        self.assertEqual(D("20.70"), r["total"])
        self.assertEqual(D("2.30"), r["discountAmount"])
        self.assertEqual(D("10"), r["discountPct"])
        self.assertTrue(c["hasDiscount"])
        self.assertEqual(D("20.70"), c["totalBase"])

    def test_prices_with_vat_recap(self) -> None:
        c = compute(doc([row("1210.00"), row("1417.70", pct=12)]), vat_treatment="pricesWithVat")
        self.assertEqual({"base": D("1000.00"), "tax": D("210.00"), "total": D("1210.00")}, c["recap"][21])
        self.assertEqual({"base": D("1265.80"), "tax": D("151.90"), "total": D("1417.70")}, c["recap"][12])
        self.assertEqual(D("2627.70"), c["totalAmount"])
        self.assertEqual(D("2265.80"), c["totalBase"])

    def test_prices_with_vat_21_recap(self) -> None:
        c = compute(doc([row("1417.70")]), vat_treatment="pricesWithVat")
        self.assertEqual((D("1171.65"), D("246.05")), (c["recap"][21]["base"], c["recap"][21]["tax"]))

    def test_from_total_authoritative(self) -> None:
        c = compute(doc([row("38.90", qty=D("38.56"), priceCalcMode="fromTotal", totalPrice=D("1500.00"))]),
                    vat_treatment="pricesWithVat")
        self.assertEqual(D("1500.00"), c["rows"][0]["total"])
        self.assertEqual(D("1499.98"), c["rows"][0]["gross"])
        self.assertEqual("fromTotal", c["rows"][0]["priceCalcMode"])

    def test_from_total_rejections(self) -> None:
        with self.assertRaises(AssertionError):
            compute(doc([row("38.90", qty=D("38.56"), priceCalcMode="fromTotal", totalPrice=D("1501.00"))]))
        with self.assertRaises(AssertionError):
            compute(doc([row("10.00", priceCalcMode="fromTotal", totalPrice=D("9.50"), discountPct=5)]))
        with self.assertRaises(AssertionError):
            compute(doc([row("10.00", totalPrice=D("10.00"))]))

    def test_no_vat(self) -> None:
        c = compute(doc([row("4800.00", pct=None), row("200.00", pct=None)]), vat_treatment="noVat")
        self.assertEqual({}, c["recap"])
        self.assertIsNone(c["totalBase"])
        self.assertIsNone(c["totalVat"])
        self.assertEqual(D("5000.00"), c["totalAmount"])
        self.assertEqual(D("0"), c["totalRounding"])
        self.assertIsNone(c["rows"][0]["vatPct"])
        with self.assertRaises(AssertionError):
            compute(doc([row("100.00", pct=21)]), vat_treatment="noVat")
        with self.assertRaises(AssertionError):
            compute(doc([row("100.50", pct=None)], rounding="czk"), vat_treatment="noVat")

    def test_rates_by_country(self) -> None:
        with self.assertRaises(AssertionError):
            compute(doc([row("100", pct=21)]), allowed_pcts=VAT_RATES["de"])
        with self.assertRaises(AssertionError):
            compute(doc([row("100", pct=19)]))
        c = compute(doc([row("100", pct=19)]), allowed_pcts=VAT_RATES["de"])
        self.assertEqual(D("19.00"), c["totalVat"])

    def test_reverse_charge_requires_zero(self) -> None:
        for treatment in ("reverseCharge", "exemptIntraCommunity"):
            with self.assertRaises(AssertionError):
                compute(doc([row("100", pct=21)]), vat_treatment=treatment)
            c = compute(doc([row("412.00", pct=0)]), vat_treatment=treatment)
            self.assertEqual({0: {"base": D("412.00"), "tax": D("0.00"), "total": D("412.00")}}, c["recap"])
            self.assertEqual(D("0.00"), c["totalVat"])

    def test_czk_rounding(self) -> None:
        c = compute(doc([row("3527.68")], rounding="czk"))
        self.assertEqual((D("-0.49"), D("4268")), (c["totalRounding"], c["totalAmount"]))
        with self.assertRaises(AssertionError):
            compute(doc([row("100.00")], rounding="czk"))  # 121.00 — není co zaokrouhlit
        with self.assertRaises(AssertionError):
            compute(doc([row("100")], rounding="eur"))

    def test_half_up(self) -> None:
        c = compute(doc([row("0.125", qty=1, pct=0)]))
        self.assertEqual(D("0.13"), c["rows"][0]["total"])

    def test_float_rejected(self) -> None:
        with self.assertRaises(AssertionError):
            compute(doc([{"text": "x", "unit": "ks", "qty": 1, "unitPrice": 486.5, "vatPct": 21}]))

    def test_unknown_treatment(self) -> None:
        with self.assertRaises(AssertionError):
            compute(doc([row("1")]), vat_treatment="magic")


if __name__ == "__main__":
    unittest.main()
