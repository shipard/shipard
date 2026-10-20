from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from invoicegen.corpus import VARIANTS
from invoicegen.rates import Rate
from invoicegen.reporting import (
    ReportingError, accounting_date_of, cost_record, exchange_rate_record, load_vat_reporting, vat_record,
)
from tests.fixtures import DATA_DOMESTIC, DATA_EU_SERVICES, DATA_NO_VAT, DATA_RECEIPT, DATA_THIRD_COUNTRY, REPO_ROOT, data
from tests.test_expected import expected_for

D = Decimal
ONE = D("1")                 # domácí měna
EUR = D("24.125000")         # lístek 2026-08-31
USD = D("20.844000")         # lístek 2026-09-01
VAT_REPORTING_PATH = REPO_ROOT / "tests" / "corpus" / "cz" / "vat-reporting.jsonc"
REPORTING = load_vat_reporting(VAT_REPORTING_PATH, required_variants=frozenset(VARIANTS))


def write_mapping(tmp: Path, **changes) -> Path:
    raw = {
        "format": "shpd.corpus.vatReporting", "formatVersion": "1", "controlStatementThreshold": 10000,
        "regimes": {"domesticDeduction": {"21": {"deduction": 40}, "12": {"deduction": 41}},
                    "domesticReverseCharge": {"21": {"output": 10, "deduction": 43}}},
        "variants": {"domestic-goods": {"regime": "domesticDeduction", "controlStatement": "B2orB3"},
                     "domestic-reverse-charge": {"regime": "domesticReverseCharge", "controlStatement": "B1", "czRate": 21},
                     "domestic-non-payer-supplier": {"regime": "none", "controlStatement": None}},
    }
    raw.update(changes)
    path = tmp / "vat-reporting.jsonc"
    path.write_text(json.dumps(raw), encoding="utf-8")
    return path


class LoadTest(unittest.TestCase):
    def test_corpus_mapping_covers_all_variants(self) -> None:
        self.assertEqual(set(VARIANTS), set(REPORTING.variants))
        self.assertEqual(D("10000"), REPORTING.threshold)
        self.assertEqual({21: {"deduction": 40}, 12: {"deduction": 41}}, REPORTING.regimes["domesticDeduction"])
        self.assertEqual({"regime": "intracomServices", "controlStatement": "A2", "czRate": 21}, REPORTING.variants["eu-services"])

    def test_minimal_mapping_loads(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            reporting = load_vat_reporting(write_mapping(Path(tmp)))
            self.assertEqual({21: {"output": 10, "deduction": 43}}, reporting.regimes["domesticReverseCharge"])

    def test_invalid_mappings(self) -> None:
        cases = [
            {"format": "x"},
            {"controlStatementThreshold": 0},
            {"regimes": {"domesticDeduction": {"21": {"deduction": 99}}}},
            {"regimes": {"domesticDeduction": {"21": {"foo": 40}}}},
            {"regimes": {"none": {"21": {"deduction": 40}}}},
            {"variants": {"domestic-goods": {"regime": "unknown", "controlStatement": None}}},
            {"variants": {"domestic-goods": {"regime": "domesticDeduction", "controlStatement": "C1"}}},
            {"variants": {"domestic-goods": {"regime": "none", "controlStatement": "B3"}}},
            {"variants": {"domestic-goods": {"regime": "domesticDeduction", "controlStatement": None, "extra": 1}}},
            {"extra": 1},
        ]
        for changes in cases:
            with tempfile.TemporaryDirectory() as tmp, self.assertRaises(ReportingError, msg=str(changes)):
                load_vat_reporting(write_mapping(Path(tmp), **changes))

    def test_required_variants(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = write_mapping(Path(tmp))
            with self.assertRaises(ReportingError) as ctx:
                load_vat_reporting(path, required_variants={"domestic-goods", "receipt"})
            self.assertIn("varianty bez mapování: receipt", str(ctx.exception))
            self.assertIn("neznámé varianty", str(ctx.exception))


class VatRecordTest(unittest.TestCase):
    def test_domestic_deduction_over_threshold(self) -> None:
        expected = expected_for(data(DATA_DOMESTIC))
        record = vat_record("MSG-1", "domestic-goods", expected, REPORTING, rate=ONE)
        self.assertEqual(["messageId", "variant", "currency", "period", "return", "controlStatement"], list(record))
        self.assertEqual(expected["dates"]["taxPointDate"][:7], record["period"])
        recap = expected["vatRecap"][0]
        self.assertEqual({"40": {"base": recap["base"], "tax": recap["tax"]}}, record["return"])
        cs = record["controlStatement"]
        self.assertEqual("B.2" if expected["totals"]["totalAmount"] > 10000 else "B.3", cs["section"])
        self.assertEqual(expected["supplier"]["vatId"], cs["supplierVatId"])
        self.assertEqual(expected["docNumber"], cs["documentNumber"])
        self.assertEqual(expected["dates"]["taxPointDate"], cs["taxPointDate"])
        self.assertEqual({"21": {"base": recap["base"], "tax": recap["tax"]}}, cs["rates"])
        self.assertIsNone(cs["reverseChargeCode"])

    def test_two_rates_and_threshold_b3(self) -> None:
        doc = data(DATA_DOMESTIC, rows=[
            {"text": "A", "qty": 1, "unit": "ks", "unitPrice": D("1000.00"), "vatPct": 21},
            {"text": "B", "qty": 1, "unit": "ks", "unitPrice": D("500.00"), "vatPct": 12},
        ])
        record = vat_record("MSG-2", "domestic-two-rates", expected_for(doc), REPORTING, rate=ONE)
        self.assertEqual({"40": {"base": D("1000.00"), "tax": D("210.00")}, "41": {"base": D("500.00"), "tax": D("60.00")}}, record["return"])
        self.assertEqual("B.3", record["controlStatement"]["section"])
        self.assertEqual(["21", "12"], list(record["controlStatement"]["rates"]))

    def test_receipt_is_always_b3(self) -> None:
        record = vat_record("MSG-3", "receipt", expected_for(data(DATA_RECEIPT)), REPORTING, rate=ONE)
        self.assertEqual("B.3", record["controlStatement"]["section"])
        self.assertIn("40", record["return"])

    def test_domestic_reverse_charge_self_assessment(self) -> None:
        doc = data(DATA_DOMESTIC, vatTreatment="reverseCharge", rows=[
            {"text": "Práce", "qty": 1, "unit": "ks", "unitPrice": D("48500.00"), "vatPct": 0, "reverseChargeCode": "4"},
            {"text": "Materiál", "qty": 1, "unit": "ks", "unitPrice": D("21340.00"), "vatPct": 0, "reverseChargeCode": "4"},
        ])
        record = vat_record("MSG-4", "domestic-reverse-charge", expected_for(doc), REPORTING, rate=ONE)
        self.assertEqual({"10": {"base": D("69840.00"), "tax": D("14666.40")}, "43": {"base": D("69840.00"), "tax": D("14666.40")}}, record["return"])
        cs = record["controlStatement"]
        self.assertEqual("B.1", cs["section"])
        self.assertEqual("4", cs["reverseChargeCode"])
        self.assertEqual({"21": {"base": D("69840.00"), "tax": D("14666.40")}}, cs["rates"])

    def test_eu_services_self_assessment_in_czk(self) -> None:
        expected = expected_for(data(DATA_EU_SERVICES))
        self.assertEqual(D("432.70"), expected["totals"]["totalBase"])
        record = vat_record("MSG-5", "eu-services", expected, REPORTING, rate=EUR)
        self.assertEqual("EUR", record["currency"], "měna dokladu zůstává")
        self.assertEqual("2026-08", record["period"])
        # 432,70 × 24,125 = 10 438,8875 → 10 438,89 Kč; daň ze základu v Kč: 2 192,1669 → 2 192,17
        # (ne z daně v EUR: 90,87 × 24,125 = 2 192,24).
        self.assertEqual({"5": {"base": D("10438.89"), "tax": D("2192.17")}, "43": {"base": D("10438.89"), "tax": D("2192.17")}},
                         record["return"])
        cs = record["controlStatement"]
        self.assertEqual(("A.2", "2026-08-31"), (cs["section"], cs["taxPointDate"]))
        self.assertEqual({"21": {"base": D("10438.89"), "tax": D("2192.17")}}, cs["rates"])

    def test_third_country_without_recap_takes_total_amount(self) -> None:
        expected = expected_for(data(DATA_THIRD_COUNTRY))
        self.assertEqual([], expected["vatRecap"])
        self.assertIsNone(expected["dates"]["taxPointDate"])
        record = vat_record("MSG-9", "third-country-services", expected, REPORTING, rate=USD)
        # 177,00 USD × 20,844 = 3 689,388 → 3 689,39 Kč; daň 21 % = 774,7719 → 774,77
        self.assertEqual({"12": {"base": D("3689.39"), "tax": D("774.77")}, "43": {"base": D("3689.39"), "tax": D("774.77")}},
                         record["return"])
        self.assertEqual("2026-09", record["period"], "období z data vystavení (bez DUZP)")
        cs = record["controlStatement"]
        self.assertEqual(("A.2", "2026-09-01", None), (cs["section"], cs["taxPointDate"], cs["supplierVatId"]))
        self.assertEqual({"21": {"base": D("3689.39"), "tax": D("774.77")}}, cs["rates"])
        self.assertEqual("USD", record["currency"])

    def test_eu_goods_with_shipping_service_splits_rows_by_supply_kind(self) -> None:
        # Dopravné na faktuře za zboží z EU je služba (nález G): zboží ř. 3, služba ř. 5, každá skupina zvlášť.
        doc = data(DATA_EU_SERVICES, variant="eu-goods", vatTreatment="exemptIntraCommunity", rows=[
            {"text": "Monitor", "qty": 4, "unit": "ks", "unitPrice": D("329.00"), "vatPct": 0, "supplyKind": "goods"},
            {"text": "Shipping", "qty": 1, "unit": "ks", "unitPrice": D("45.00"), "vatPct": 0, "supplyKind": "services"},
        ])
        expected = expected_for(doc)
        rate = D("24.345000")
        record = vat_record("MSG-6", "eu-goods", expected, REPORTING, rate=rate)
        # zboží 1 316,00 × 24,345 = 32 038,02; služba 45,00 × 24,345 = 1 095,525 → 1 095,53 (půl haléře nahoru)
        self.assertEqual({"3": {"base": D("32038.02"), "tax": D("6727.98")}, "5": {"base": D("1095.53"), "tax": D("230.06")},
                          "43": {"base": D("33133.55"), "tax": D("6958.04")}}, record["return"])
        self.assertEqual({"21": {"base": D("33133.55"), "tax": D("6958.04")}}, record["controlStatement"]["rates"])
        self.assertEqual(D("33133.55"), cost_record("MSG-6", expected, vat_payer=True, rate=rate)["cost"],
                         "náklad plátce = Σ skupin přepočtených zvlášť")

    def test_domestic_deduction_in_foreign_currency_converts_tax(self) -> None:
        expected = expected_for(data(DATA_DOMESTIC, currency="EUR"))
        record = vat_record("MSG-10", "domestic-goods", expected, REPORTING, rate=EUR)
        # základ 6 666,25 × 24,125 = 160 823,28125 → 160 823,28; daň dodavatele 1 399,91 × 24,125 = 33 772,82875 → 33 772,83
        self.assertEqual({"40": {"base": D("160823.28"), "tax": D("33772.83")}}, record["return"])
        self.assertEqual("EUR", record["currency"])

    def test_home_currency_rate_one_keeps_amounts(self) -> None:
        expected = expected_for(data(DATA_DOMESTIC))
        recap = expected["vatRecap"][0]
        record = vat_record("MSG-11", "domestic-goods", expected, REPORTING, rate=ONE)
        self.assertEqual({"40": {"base": recap["base"], "tax": recap["tax"]}}, record["return"])

    def test_no_vat_has_no_rows(self) -> None:
        record = vat_record("MSG-6", "domestic-non-payer-supplier", expected_for(data(DATA_NO_VAT)), REPORTING, rate=ONE)
        self.assertEqual({}, record["return"])
        self.assertIsNone(record["controlStatement"])
        self.assertIsNone(record["period"])

    def test_self_assessment_rejects_nonzero_rate(self) -> None:
        with self.assertRaises(ReportingError):
            vat_record("MSG-7", "domestic-reverse-charge", expected_for(data(DATA_DOMESTIC)), REPORTING, rate=ONE)

    def test_unknown_variant(self) -> None:
        with self.assertRaises(ReportingError):
            vat_record("MSG-8", "credit-note", expected_for(data(DATA_DOMESTIC)), REPORTING, rate=ONE)


class CostRecordTest(unittest.TestCase):
    def test_payer_base_non_payer_gross(self) -> None:
        expected = expected_for(data(DATA_DOMESTIC))
        payer = cost_record("MSG-1", expected, vat_payer=True, rate=ONE)
        self.assertEqual(["messageId", "currency", "cost"], list(payer))
        self.assertEqual(expected["totals"]["totalBase"], payer["cost"])
        gross = cost_record("MSG-1", expected, vat_payer=False, rate=ONE)
        self.assertEqual(expected["totals"]["totalBase"] + expected["totals"]["totalVat"], gross["cost"])

    def test_rounding_is_not_a_cost(self) -> None:
        doc = data(DATA_DOMESTIC, rounding="czk", rows=[
            {"text": "A", "qty": 1, "unit": "ks", "unitPrice": D("3905.19"), "vatPct": 21},
        ])
        expected = expected_for(doc, "non-payer")
        self.assertNotEqual(0, expected["totals"]["totalRounding"])
        self.assertEqual(D("4725.28"), cost_record("MSG-2", expected, vat_payer=False, rate=ONE)["cost"])
        self.assertEqual(D("3905.19"), cost_record("MSG-2", expected, vat_payer=True, rate=ONE)["cost"])

    def test_no_vat_is_total_amount(self) -> None:
        expected = expected_for(data(DATA_NO_VAT))
        self.assertEqual(expected["totals"]["totalAmount"], cost_record("MSG-3", expected, vat_payer=True, rate=ONE)["cost"])
        self.assertEqual(expected["totals"]["totalAmount"], cost_record("MSG-3", expected, vat_payer=False, rate=ONE)["cost"])

    def test_non_payer_foreign_currency_cost_sums_converted_rows(self) -> None:
        # Neplátce: každý řádek s DPH dodavatele se přepočítá zvlášť (nález H).
        doc = data(DATA_EU_SERVICES, variant="eu-goods", vatTreatment="standard", skipProfiles={}, rows=[
            {"text": "Monitor", "qty": 4, "unit": "ks", "unitPrice": D("329.00"), "vatPct": 19, "supplyKind": "goods"},
            {"text": "Dock", "qty": 4, "unit": "ks", "unitPrice": D("149.00"), "vatPct": 19, "supplyKind": "goods", "discountPct": 5},
            {"text": "Shipping", "qty": 1, "unit": "ks", "unitPrice": D("45.00"), "vatPct": 19, "supplyKind": "services"},
        ])
        expected = expected_for(doc, "non-payer")
        self.assertEqual(D("2293.37"), expected["totals"]["totalAmount"])
        # řádky s DPH 1 566,04 + 673,78 + 53,55 = 2 293,37 = rekapitulace; × 24,345 zvlášť:
        # 38 125,24 + 16 403,17 + 1 303,67 = 55 832,08 (celkem × kurz by dalo 55 832,09)
        self.assertEqual(D("55832.08"), cost_record("MSG-7", expected, vat_payer=False, rate=D("24.345000"))["cost"])

    def test_foreign_currency_cost_in_czk(self) -> None:
        eu = cost_record("MSG-4", expected_for(data(DATA_EU_SERVICES)), vat_payer=True, rate=EUR)
        self.assertEqual({"messageId": "MSG-4", "currency": "EUR", "cost": D("10438.89")}, eu, "432,70 × 24,125 na haléře")
        us = cost_record("MSG-5", expected_for(data(DATA_THIRD_COUNTRY)), vat_payer=True, rate=USD)
        self.assertEqual(D("3689.39"), us["cost"])
        self.assertEqual("USD", us["currency"])


class ExchangeRateRecordTest(unittest.TestCase):
    def test_accounting_date(self) -> None:
        self.assertEqual("2026-08-31", accounting_date_of(expected_for(data(DATA_EU_SERVICES))))
        self.assertEqual("2026-09-01", accounting_date_of(expected_for(data(DATA_THIRD_COUNTRY))), "bez DUZP datum vystavení")

    def test_record_shape(self) -> None:
        rate = Rate(EUR, "cz.cnb.daily", "2026-08-31", "26167")
        record = exchange_rate_record("MSG-1", expected_for(data(DATA_EU_SERVICES)), rate)
        self.assertEqual({"messageId": "MSG-1", "currency": "EUR", "rateDate": "2026-08-31", "listType": "cz.cnb.daily",
                          "validFrom": "2026-08-31", "rate": EUR}, record)
        self.assertEqual(["messageId", "currency", "rateDate", "listType", "validFrom", "rate"], list(record))

    def test_home_currency_has_no_record(self) -> None:
        with self.assertRaises(ReportingError):
            exchange_rate_record("MSG-2", expected_for(data(DATA_DOMESTIC)), Rate.home())
