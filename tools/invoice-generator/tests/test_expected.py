from __future__ import annotations

import json
import re
import unittest
from decimal import Decimal

from invoicegen.compute import VAT_RATES, compute
from invoicegen.corpus import cells_for
from invoicegen.expected import (
    ProjectionError, apply_projection, build_expected, check_shape, derive_vat, dumps_expected, load_projection,
    vat_place,
)
from invoicegen.jsonc import load_jsonc
from tests.fixtures import (
    DATA_DOMESTIC, DATA_EU_SERVICES, DATA_EU_SERVICES_REFERENCE, DATA_NO_VAT, DATA_RECEIPT, DATA_THIRD_COUNTRY,
    DATA_THIRD_COUNTRY_PERIOD, IDENTITIES, PROJECTION_PATH, TAXONOMY_PATH,
)

D = Decimal
PROJECTION = load_projection(PROJECTION_PATH)
TAXONOMY = set(load_jsonc(TAXONOMY_PATH))


def expected_for(data: dict, profile: str = "vat-payer") -> dict:
    cell = next(c for c in cells_for(data, "x", IDENTITIES) if getattr(c, "profile", None) == profile and hasattr(c, "data"))
    computed = compute(cell.data, vat_treatment=cell.data["vatTreatment"],
                       allowed_pcts=VAT_RATES.get(cell.supplier["country"], VAT_RATES["cz"]))
    doc = build_expected(cell.data, cell.supplier, cell.profile_identity, computed, receipt=cell.receipt)
    return apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)


class ProjectionFileTest(unittest.TestCase):
    def test_shapes_compile_and_refs_resolve(self) -> None:
        for name, shape in PROJECTION.shapes.items():
            if "regex" in shape:
                re.compile(shape["regex"])
                self.assertNotIn("~", shape["regex"], name)
        self.assertEqual({"customer"}, set(PROJECTION.nullable_nodes))
        self.assertEqual(["Party", "Row", "VatRecapEntry"], sorted(PROJECTION.defs))

    def test_document_key_order_matches_task(self) -> None:
        self.assertEqual(
            ["format", "formatVersion", "docType", "docNumber", "selfParty", "supplier", "customer", "dates",
             "currency", "exchangeRate", "vat", "payment", "notes", "rows", "vatRecap", "totals", "_resolve"],
            list(PROJECTION.document),
        )
        self.assertEqual(["name", "country", "companyId", "taxId", "vatId", "courtRegistration",
                          "address", "contact", "bankAccount"], list(PROJECTION.defs["Party"]))


class DerivedFieldsTest(unittest.TestCase):
    def test_vat_place(self) -> None:
        self.assertEqual("domestic", vat_place({"vatId": "CZ12345678", "country": "cz"}))
        self.assertEqual("intracom", vat_place({"vatId": "DE136695976", "country": "de"}))
        self.assertEqual("intracom", vat_place({"vatId": "IE1234567X", "country": "us"}))
        self.assertEqual("intracom", vat_place({"vatId": "EL123456789", "country": "gr"}))
        self.assertEqual("intracom", vat_place({"vatId": None, "country": "de"}))
        self.assertEqual("domestic", vat_place({"vatId": None, "country": "cz"}))
        self.assertEqual("thirdCountry", vat_place({"vatId": None, "country": "us"}))
        self.assertEqual("thirdCountry", vat_place({"vatId": "CHE123456789", "country": "ch"}))

    def test_derive_vat(self) -> None:
        self.assertEqual(("fromBase", False), derive_vat("standard"))
        self.assertEqual(("fromTotal", False), derive_vat("pricesWithVat"))
        self.assertEqual(("fromBase", True), derive_vat("reverseCharge"))
        self.assertEqual(("fromBase", False), derive_vat("exemptIntraCommunity"))
        self.assertEqual(("none", False), derive_vat("noVat"))


class BuildExpectedTest(unittest.TestCase):
    def test_domestic_invoice(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        self.assertEqual(list(PROJECTION.document), list(doc))
        self.assertEqual("invoiceReceived", doc["docType"])
        self.assertEqual("customer", doc["selfParty"])
        self.assertEqual("Testovna s.r.o.", doc["customer"]["name"])
        self.assertEqual({"name": None, "email": None, "phone": None, "web": None}, doc["customer"]["contact"])
        self.assertEqual({"accountNumber": None, "iban": None, "bic": None}, doc["customer"]["bankAccount"])
        self.assertIsNone(doc["customer"]["courtRegistration"])
        self.assertNotIn("vatPayer", doc["supplier"])
        self.assertEqual("KOMBCZPP", doc["supplier"]["bankAccount"]["bic"])
        self.assertEqual({"mode": "fromBase", "place": "domestic", "reverseCharge": False}, doc["vat"])
        self.assertEqual({"method": "bankTransfer", "paymentReference": "20260101", "specificSymbol": None,
                          "constantSymbol": "0308"}, doc["payment"])
        self.assertEqual({"issueDate": "2026-09-01", "dueDate": "2026-09-15", "taxPointDate": "2026-08-31",
                          "periodFrom": None, "periodTo": None}, doc["dates"])
        self.assertIsNone(doc["exchangeRate"])
        row = doc["rows"][0]
        self.assertEqual(["rowKind", "item", "unit", "quantity", "unitPrice", "totalPrice", "priceCalcMode",
                          "discountPct", "discountAmount", "vat"], list(row))
        self.assertEqual("item", row["rowKind"])
        self.assertEqual({"supplierCode": None, "ean": None, "name": "Spárovka smrková 18 mm", "description": None}, row["item"])
        self.assertEqual("m2", row["unit"])
        self.assertEqual(D("12.5"), row["quantity"])
        self.assertEqual(D("486.50"), row["unitPrice"])
        self.assertEqual(D("6081.25"), row["totalPrice"])
        self.assertEqual("fromUnitPrice", row["priceCalcMode"])
        self.assertEqual({"pct": 21, "supplyKind": None, "reverseChargeCode": None}, row["vat"])
        self.assertEqual(D("10"), doc["rows"][1]["discountPct"])
        self.assertIsNone(doc["rows"][1]["discountAmount"])
        self.assertEqual(D("585.00"), doc["rows"][1]["totalPrice"])
        self.assertEqual([{"vatPct": 21, "base": D("6666.25"), "tax": D("1399.91"), "total": D("8066.16")}], doc["vatRecap"])
        self.assertEqual({"totalBase": D("6666.25"), "totalVat": D("1399.91"), "totalAmount": D("8066.16"),
                          "totalRounding": D("0")}, doc["totals"])
        self.assertEqual({"contentTag": {"tag": "goods.stock", "rowExceptions": [{"rowIndex": 1, "tag": "services.shipping"}]}},
                         doc["_resolve"])
        self.assertEqual("Zboží zůstává do zaplacení majetkem dodavatele.", doc["notes"]["onDocument"])

    def test_eu_services(self) -> None:
        doc = expected_for(DATA_EU_SERVICES)
        self.assertEqual({"mode": "fromBase", "place": "intracom", "reverseCharge": True}, doc["vat"])
        self.assertEqual("services", doc["rows"][0]["vat"]["supplyKind"])
        self.assertEqual(0, doc["rows"][0]["vat"]["pct"])
        self.assertIsNone(doc["rows"][0]["vat"]["reverseChargeCode"])
        self.assertEqual("MK8S-M", doc["rows"][0]["item"]["supplierCode"])
        self.assertEqual("Billing period 08/2026", doc["rows"][0]["item"]["description"])
        self.assertEqual(D("1.25"), doc["rows"][1]["quantity"])
        self.assertEqual("EUR", doc["currency"])
        self.assertEqual("2026-08-01", doc["dates"]["periodFrom"])
        self.assertIsNone(doc["payment"]["paymentReference"])
        self.assertIsNone(doc["notes"]["onDocument"])
        self.assertEqual({"accountNumber": None, "iban": "DE72100700000532013000", "bic": "TESTDEBBXXX"},
                         doc["supplier"]["bankAccount"])
        self.assertIsNone(doc["supplier"]["companyId"])
        self.assertEqual([], doc["_resolve"]["contentTag"]["rowExceptions"])
        self.assertEqual([{"vatPct": 0, "base": D("432.70"), "tax": D("0.00"), "total": D("432.70")}], doc["vatRecap"])

    # Hodnoty netištěné samostatně (#103 D110, D112)
    def test_unprinted_reference_is_doc_number(self) -> None:
        doc = expected_for(DATA_EU_SERVICES_REFERENCE)
        self.assertEqual("INV-2026-0388", doc["payment"]["paymentReference"])
        self.assertEqual("Please quote the invoice number with your payment.", doc["notes"]["onDocument"])
        self.assertIsNone(expected_for(DATA_EU_SERVICES)["payment"]["paymentReference"])

    def test_unprinted_period_stays_in_dates(self) -> None:
        doc = expected_for(DATA_THIRD_COUNTRY_PERIOD)
        self.assertEqual("2026-09-01", doc["dates"]["periodFrom"])
        self.assertEqual("2026-09-30", doc["dates"]["periodTo"])
        self.assertEqual("12 seats, 09/01/2026 – 09/30/2026", doc["rows"][0]["item"]["description"])
        plain = expected_for(DATA_THIRD_COUNTRY)
        self.assertIsNone(plain["dates"]["periodFrom"])
        self.assertIsNone(plain["dates"]["periodTo"])

    def test_reference_shape(self) -> None:
        for value in ("INV-2026-0388", "QS-204611", "2026-0163", "20260163", "FV/2026/12"):
            self.assertIsNone(check_shape("reference", value, None, PROJECTION, taxonomy=None), value)
        for value in ("inv-2026-0388", "INV 2026-0388", "-2026", "", "X" * 36):
            self.assertIsNotNone(check_shape("reference", value, None, PROJECTION, taxonomy=None), value)
        self.assertEqual("reference", PROJECTION.document["payment"]["paymentReference"])
        self.assertEqual("symbol", PROJECTION.document["payment"]["constantSymbol"])

    def test_receipt(self) -> None:
        doc = expected_for(DATA_RECEIPT)
        self.assertIsNone(doc["customer"])
        self.assertIsNone(doc["dates"]["dueDate"])
        self.assertEqual("2026-09-03", doc["dates"]["taxPointDate"])
        self.assertEqual("card", doc["payment"]["method"])
        self.assertIsNone(doc["payment"]["paymentReference"])
        self.assertEqual("fromTotal", doc["vat"]["mode"])
        self.assertEqual("fromTotal", doc["rows"][0]["priceCalcMode"])
        self.assertEqual(D("1500.00"), doc["rows"][0]["totalPrice"])
        self.assertEqual(D("38.56"), doc["rows"][0]["quantity"])
        self.assertEqual(D("1545.00"), doc["totals"]["totalAmount"])
        self.assertEqual(2, len(doc["vatRecap"]))
        self.assertEqual([{"rowIndex": 1, "tag": "people.catering"}], doc["_resolve"]["contentTag"]["rowExceptions"])

    def test_no_vat(self) -> None:
        doc = expected_for(DATA_NO_VAT)
        self.assertEqual({"mode": "none", "place": "domestic", "reverseCharge": False}, doc["vat"])
        self.assertEqual([], doc["vatRecap"])
        self.assertEqual({"totalBase": None, "totalVat": None, "totalAmount": D("4800.00"), "totalRounding": D("0")},
                         doc["totals"])
        self.assertIsNone(doc["rows"][0]["vat"]["pct"])
        self.assertIsNone(doc["dates"]["taxPointDate"])
        self.assertIsNone(doc["supplier"]["vatId"])
        self.assertIsNone(doc["supplier"]["taxId"])

    def test_domestic_reverse_charge(self) -> None:
        data = {**DATA_DOMESTIC, "vatTreatment": "reverseCharge", "contentTag": "premises.maintenance",
                "rows": [{"text": "Oprava střechy", "qty": 1, "unit": "ks", "unitPrice": D("50000.00"),
                          "vatPct": 0, "reverseChargeCode": "4"}]}
        doc = expected_for(data)
        self.assertEqual({"mode": "fromBase", "place": "domestic", "reverseCharge": True}, doc["vat"])
        self.assertEqual({"pct": 0, "supplyKind": None, "reverseChargeCode": "4"}, doc["rows"][0]["vat"])

    def test_non_payer_profile_customer_without_vat_id(self) -> None:
        doc = expected_for(DATA_DOMESTIC, "association")
        self.assertEqual("Spolek Testík, z. s.", doc["customer"]["name"])
        self.assertIsNone(doc["customer"]["vatId"])
        self.assertEqual("40519775", doc["customer"]["companyId"])


class ApplyProjectionTest(unittest.TestCase):
    def test_extra_key_rejected(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        doc["supplier"]["vatPayer"] = True
        with self.assertRaises(ProjectionError) as ctx:
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        self.assertIn("supplier: klíče mimo projekci vatPayer", str(ctx.exception))

    def test_shape_violations(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        doc["supplier"]["address"]["zip"] = "616 00"
        doc["supplier"]["country"] = "CZ"
        doc["currency"] = "czk"
        doc["rows"][0]["unitPrice"] = D("1.005")
        doc["_resolve"]["contentTag"]["tag"] = "nope.tag"
        doc["rowsX"] = 1
        with self.assertRaises(ProjectionError) as ctx:
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        msg = str(ctx.exception)
        for fragment in ("supplier.address.zip", "supplier.country", "currency", "rows[0].unitPrice",
                         "_resolve.contentTag.tag", "klíče mimo projekci rowsX"):
            self.assertIn(fragment, msg)

    def test_us_house_number_must_be_null(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        doc["supplier"]["country"] = doc["supplier"]["address"]["country"] = "us"
        doc["supplier"]["companyId"] = doc["supplier"]["vatId"] = doc["supplier"]["taxId"] = None
        doc["supplier"]["bankAccount"]["accountNumber"] = None
        with self.assertRaises(ProjectionError) as ctx:
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        self.assertIn("supplier.address.houseNumber", str(ctx.exception))

    def test_nullable_nodes(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        doc["customer"] = None
        apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        doc["vatRecap"] = None
        with self.assertRaises(ProjectionError):
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        doc["vatRecap"] = []
        doc["supplier"]["contact"] = None
        out = apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        self.assertEqual({"name": None, "email": None, "phone": None, "web": None}, out["supplier"]["contact"])
        doc["rows"] = None
        with self.assertRaises(ProjectionError):
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)

    def test_const_and_float(self) -> None:
        doc = expected_for(DATA_DOMESTIC)
        doc["formatVersion"] = "1"
        doc["totals"]["totalAmount"] = 8066.16
        with self.assertRaises(ProjectionError) as ctx:
            apply_projection(doc, PROJECTION, taxonomy=TAXONOMY)
        self.assertIn("formatVersion", str(ctx.exception))
        self.assertIn("totals.totalAmount: float", str(ctx.exception))


class DumpsTest(unittest.TestCase):
    def test_numbers_and_layout(self) -> None:
        out = dumps_expected({"a": D("8500.00"), "b": D("-0.49"), "c": D("0"), "d": D("1E+2"), "e": D("0.643"),
                              "f": D("1.25"), "g": 1, "h": True, "i": None, "j": "ř\"", "k": [], "l": {}, "m": [1]})
        self.assertEqual(
            '{\n  "a": 8500.00,\n  "b": -0.49,\n  "c": 0,\n  "d": 100,\n  "e": 0.643,\n  "f": 1.25,\n  "g": 1,\n'
            '  "h": true,\n  "i": null,\n  "j": "ř\\"",\n  "k": [],\n  "l": {},\n  "m": [\n    1\n  ]\n}\n', out)
        self.assertEqual("-0.00\n".replace("-", ""), dumps_expected(D("-0.00")))

    def test_float_rejected(self) -> None:
        with self.assertRaises(TypeError):
            dumps_expected({"a": 1.5})

    def test_round_trip(self) -> None:
        doc = expected_for(DATA_RECEIPT)
        text = dumps_expected(doc)
        self.assertTrue(text.endswith("\n"))
        self.assertNotIn("\\u", text)
        back = json.loads(text, parse_float=D)
        self.assertEqual(doc, back)
        self.assertEqual(list(doc), list(back))


if __name__ == "__main__":
    unittest.main()
