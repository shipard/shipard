from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from invoicegen.corpus import VARIANTS
from invoicegen.reporting import ReportingError, cost_record, load_vat_reporting, vat_record
from tests.fixtures import DATA_DOMESTIC, DATA_EU_SERVICES, DATA_NO_VAT, DATA_RECEIPT, REPO_ROOT, data
from tests.test_expected import expected_for

D = Decimal
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
        record = vat_record("MSG-1", "domestic-goods", expected, REPORTING)
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
        record = vat_record("MSG-2", "domestic-two-rates", expected_for(doc), REPORTING)
        self.assertEqual({"40": {"base": D("1000.00"), "tax": D("210.00")}, "41": {"base": D("500.00"), "tax": D("60.00")}}, record["return"])
        self.assertEqual("B.3", record["controlStatement"]["section"])
        self.assertEqual(["21", "12"], list(record["controlStatement"]["rates"]))

    def test_receipt_is_always_b3(self) -> None:
        record = vat_record("MSG-3", "receipt", expected_for(data(DATA_RECEIPT)), REPORTING)
        self.assertEqual("B.3", record["controlStatement"]["section"])
        self.assertIn("40", record["return"])

    def test_domestic_reverse_charge_self_assessment(self) -> None:
        doc = data(DATA_DOMESTIC, vatTreatment="reverseCharge", rows=[
            {"text": "Práce", "qty": 1, "unit": "ks", "unitPrice": D("48500.00"), "vatPct": 0, "reverseChargeCode": "4"},
            {"text": "Materiál", "qty": 1, "unit": "ks", "unitPrice": D("21340.00"), "vatPct": 0, "reverseChargeCode": "4"},
        ])
        record = vat_record("MSG-4", "domestic-reverse-charge", expected_for(doc), REPORTING)
        self.assertEqual({"10": {"base": D("69840.00"), "tax": D("14666.40")}, "43": {"base": D("69840.00"), "tax": D("14666.40")}}, record["return"])
        cs = record["controlStatement"]
        self.assertEqual("B.1", cs["section"])
        self.assertEqual("4", cs["reverseChargeCode"])
        self.assertEqual({"21": {"base": D("69840.00"), "tax": D("14666.40")}}, cs["rates"])

    def test_eu_services_in_document_currency(self) -> None:
        record = vat_record("MSG-5", "eu-services", expected_for(data(DATA_EU_SERVICES)), REPORTING)
        self.assertEqual("EUR", record["currency"])
        self.assertEqual({"5", "43"}, set(record["return"]))
        self.assertEqual("A.2", record["controlStatement"]["section"])
        self.assertEqual(record["return"]["5"], record["return"]["43"])

    def test_no_vat_has_no_rows(self) -> None:
        record = vat_record("MSG-6", "domestic-non-payer-supplier", expected_for(data(DATA_NO_VAT)), REPORTING)
        self.assertEqual({}, record["return"])
        self.assertIsNone(record["controlStatement"])
        self.assertIsNone(record["period"])

    def test_self_assessment_rejects_nonzero_rate(self) -> None:
        with self.assertRaises(ReportingError):
            vat_record("MSG-7", "domestic-reverse-charge", expected_for(data(DATA_DOMESTIC)), REPORTING)

    def test_unknown_variant(self) -> None:
        with self.assertRaises(ReportingError):
            vat_record("MSG-8", "credit-note", expected_for(data(DATA_DOMESTIC)), REPORTING)


class CostRecordTest(unittest.TestCase):
    def test_payer_base_non_payer_gross(self) -> None:
        expected = expected_for(data(DATA_DOMESTIC))
        payer = cost_record("MSG-1", expected, vat_payer=True)
        self.assertEqual(["messageId", "currency", "cost"], list(payer))
        self.assertEqual(expected["totals"]["totalBase"], payer["cost"])
        gross = cost_record("MSG-1", expected, vat_payer=False)
        self.assertEqual(expected["totals"]["totalBase"] + expected["totals"]["totalVat"], gross["cost"])

    def test_rounding_is_not_a_cost(self) -> None:
        doc = data(DATA_DOMESTIC, rounding="czk", rows=[
            {"text": "A", "qty": 1, "unit": "ks", "unitPrice": D("3905.19"), "vatPct": 21},
        ])
        expected = expected_for(doc, "non-payer")
        self.assertNotEqual(0, expected["totals"]["totalRounding"])
        self.assertEqual(D("4725.28"), cost_record("MSG-2", expected, vat_payer=False)["cost"])
        self.assertEqual(D("3905.19"), cost_record("MSG-2", expected, vat_payer=True)["cost"])

    def test_no_vat_is_total_amount(self) -> None:
        expected = expected_for(data(DATA_NO_VAT))
        self.assertEqual(expected["totals"]["totalAmount"], cost_record("MSG-3", expected, vat_payer=True)["cost"])
        self.assertEqual(expected["totals"]["totalAmount"], cost_record("MSG-3", expected, vat_payer=False)["cost"])
