from __future__ import annotations

import copy
import unittest
from decimal import Decimal

from invoicegen.corpus import (
    PROFILES, Cell, DataFileError, SkippedCell, cells_for, deep_merge, parse_stem, printed_date_forms,
    resolve_overrides, row_carries_dates, stem_for, validate_data_file, validate_variant_pairs,
)
from invoicegen.jsonc import load_jsonc
from tests.fixtures import (
    DATA_DOMESTIC, DATA_EU_SERVICES, DATA_EU_SERVICES_REFERENCE, DATA_NO_VAT, DATA_RECEIPT, DATA_THIRD_COUNTRY,
    DATA_THIRD_COUNTRY_PERIOD, IDENTITIES, TAXONOMY_PATH, TEMPLATES, data,
)

D = Decimal
TAXONOMY = set(load_jsonc(TAXONOMY_PATH))


def validate(doc: dict, stem: str | None = None) -> None:
    validate_data_file(doc, stem or stem_for(doc, "01"), identities=IDENTITIES, templates=TEMPLATES, taxonomy=TAXONOMY)


class DeepMergeTest(unittest.TestCase):
    def test_merge_semantics(self) -> None:
        base = {"a": {"x": 1, "y": [1, 2]}, "b": "keep", "c": 3}
        override = {"a": {"y": [9], "z": None}, "c": None, "d": 4}
        merged = deep_merge(base, override)
        self.assertEqual({"a": {"x": 1, "y": [9], "z": None}, "b": "keep", "c": None, "d": 4}, merged)
        self.assertEqual({"a": {"x": 1, "y": [1, 2]}, "b": "keep", "c": 3}, base)
        merged["a"]["y"].append(10)
        self.assertEqual([9], override["a"]["y"])


class OverridesTest(unittest.TestCase):
    def test_alias(self) -> None:
        doc = data(DATA_DOMESTIC, overrides={"non-payer": {"docNumber": "X"}, "association": "non-payer"})
        resolved = resolve_overrides(doc)
        self.assertEqual({"docNumber": "X"}, resolved["association"])
        validate(doc)

    def test_alias_errors(self) -> None:
        with self.assertRaises(DataFileError):
            resolve_overrides(data(DATA_DOMESTIC, overrides={"association": "vat-payer"}))
        with self.assertRaises(DataFileError):
            resolve_overrides(data(DATA_DOMESTIC, overrides={"non-payer": "association", "association": "non-payer"}))
        with self.assertRaises(DataFileError):
            resolve_overrides(data(DATA_DOMESTIC, overrides={"non-payer": 5}))

    def test_skip_and_override_disjoint(self) -> None:
        with self.assertRaises(DataFileError) as ctx:
            validate(data(DATA_DOMESTIC, skipProfiles={"non-payer": "důvod"}, overrides={"non-payer": {}}))
        self.assertIn("zároveň", str(ctx.exception))
        with self.assertRaises(DataFileError):
            validate(data(DATA_DOMESTIC, skipProfiles={"non-payer": ""}))
        with self.assertRaises(DataFileError):
            validate(data(DATA_DOMESTIC, skipProfiles={"payer": "x"}))


class CellsTest(unittest.TestCase):
    def test_full_matrix(self) -> None:
        cells = cells_for(DATA_DOMESTIC, "0101-domestic-goods", IDENTITIES)
        self.assertEqual(list(PROFILES), [c.profile for c in cells])
        self.assertTrue(all(isinstance(c, Cell) for c in cells))
        self.assertFalse(cells[0].receipt)
        self.assertEqual("cs-a", cells[0].template)
        self.assertNotIn("overrides", cells[0].data)

    def test_skipped_and_override(self) -> None:
        doc = data(DATA_EU_SERVICES, skipProfiles={"association": "x"},
                   overrides={"non-payer": {"vatTreatment": "standard", "rows": [
                       {"text": "Hosting", "qty": 1, "unit": "ks", "unitPrice": D("412.00"), "vatPct": 19, "supplyKind": "services"}]}})
        cells = cells_for(doc, "0502-eu-services", IDENTITIES)
        self.assertIsInstance(cells[0], Cell)
        self.assertEqual("standard", cells[1].data["vatTreatment"])
        self.assertEqual(1, len(cells[1].data["rows"]))
        self.assertEqual("reverseCharge", cells[0].data["vatTreatment"])
        self.assertEqual(SkippedCell("0502-eu-services", "association", "x"), cells[2])
        validate(doc)

    def test_receipt_cell(self) -> None:
        cells = cells_for(DATA_RECEIPT, "0901-receipt", IDENTITIES)
        self.assertTrue(cells[0].receipt)


class ValidateDataFileTest(unittest.TestCase):
    def test_fixtures_valid(self) -> None:
        for doc in (DATA_DOMESTIC, DATA_EU_SERVICES, DATA_RECEIPT, DATA_NO_VAT, DATA_THIRD_COUNTRY,
                    DATA_EU_SERVICES_REFERENCE, DATA_THIRD_COUNTRY_PERIOD):
            validate(doc)

    def test_stem(self) -> None:
        self.assertEqual((1, "01", "domestic-goods"), parse_stem("0101-domestic-goods"))
        self.assertEqual((10, "02", "long-document"), parse_stem("1002-long-document"))
        self.assertEqual("0502-eu-services", stem_for(DATA_EU_SERVICES, "02"))
        self.assertEqual("1001-long-document", stem_for(data(DATA_DOMESTIC, variant="long-document"), "01"))
        # Název nese pořadí dokladu, ne podobu: obě podoby jsou platné pod 01 i 02.
        validate(DATA_DOMESTIC, "0102-domestic-goods")
        validate(data(DATA_DOMESTIC, form="pdfIsdoc"), "0101-domestic-goods")
        for stem in ("0201-domestic-goods", "0103-domestic-goods", "0101-domestic_goods", "101-domestic-goods"):
            with self.assertRaises(DataFileError, msg=stem):
                validate(DATA_DOMESTIC, stem)
        with self.assertRaises(DataFileError):
            validate(data(DATA_DOMESTIC, variant="mystery"), "0101-mystery")
        with self.assertRaises(DataFileError):
            parse_stem("x")

    def test_isdoc_form_only_between_czech_subjects(self) -> None:
        validate(data(DATA_DOMESTIC, form="pdfIsdoc"))
        validate(data(DATA_NO_VAT, form="pdfIsdoc"))  # neplátce s IČO
        self.rejects(data(DATA_EU_SERVICES, form="pdfIsdoc"), "D10")
        self.rejects(data(DATA_RECEIPT, form="pdfIsdoc"), "D10")
        self.rejects(data(DATA_DOMESTIC, form="xml"), "neznámá podoba")

    def rejects(self, doc: dict, fragment: str) -> None:
        with self.assertRaises(DataFileError) as ctx:
            validate(doc)
        self.assertIn(fragment, str(ctx.exception))

    def test_row_rules(self) -> None:
        def with_row(base: dict, **row_changes) -> dict:
            doc = copy.deepcopy(base)
            doc["rows"][0].update(row_changes)
            return doc

        self.rejects(with_row(DATA_DOMESTIC, supplyKind="goods"), "supplyKind jen u dodavatele mimo ČR")
        self.rejects(with_row(DATA_EU_SERVICES, supplyKind=None), "supplyKind povinný")
        self.rejects(with_row(DATA_DOMESTIC, reverseChargeCode="4"), "reverseChargeCode jen")
        rc = data(DATA_DOMESTIC, vatTreatment="reverseCharge")
        rc["rows"] = [{"text": "Oprava", "qty": 1, "unit": "ks", "unitPrice": D("10.00"), "vatPct": 0}]
        self.rejects(rc, "chce reverseChargeCode")
        self.rejects(with_row(DATA_DOMESTIC, tag="no.such"), "není v taxonomii")
        self.rejects(with_row(DATA_DOMESTIC, tag="goods.stock"), "shodná se štítkem dokladu")
        self.rejects(with_row(DATA_DOMESTIC, unitPrice=D("1.005")), "2 desetinná místa")
        self.rejects(with_row(DATA_DOMESTIC, qty=D("0.0005")), "3 desetinná místa")
        self.rejects(with_row(DATA_DOMESTIC, totalPrice=D("1.00")), "totalPrice právě s")
        self.rejects(with_row(DATA_RECEIPT, totalPrice=None, priceCalcMode="fromUnitPrice"), "totalPrice právě s")
        self.rejects(with_row(DATA_DOMESTIC, discountPct=D("100.5")), "discountPct")
        self.rejects(with_row(DATA_DOMESTIC, text=" x"), "chybí text")
        # D102 (#103): účtenka kódy netiskne, data je nesmí nést
        self.rejects(with_row(DATA_RECEIPT, supplierCode="PHM-95"), "na účtence")
        self.rejects(with_row(DATA_RECEIPT, ean="4260999100013"), "na účtence")
        validate(with_row(DATA_DOMESTIC, supplierCode="SP-18", ean="4260999100013"))

    def test_header_rules(self) -> None:
        self.rejects(data(DATA_DOMESTIC, template="z"), "neznámá šablona")
        self.rejects(data(DATA_DOMESTIC, vatTreatment="noVat"), "noVat u tuzemského plátce")
        self.rejects(data(DATA_NO_VAT, vatTreatment="standard"), "bez DIČ")
        self.rejects(data(DATA_DOMESTIC, vatTreatment="exemptIntraCommunity"), "jiného státu EU")
        self.rejects(data(DATA_DOMESTIC, currency="czk"), "ISO 4217")
        self.rejects(data(DATA_EU_SERVICES, rounding="czk"), "jen u CZK")
        self.rejects(data(DATA_DOMESTIC, dates={"issue": "2026-09-01", "due": "2026-09-15"}), "dates.tax chybí")
        self.rejects(data(DATA_DOMESTIC, dates={"issue": "2026-09-01", "tax": "2026-09-01"}), "dates.due chybí")
        self.rejects(data(DATA_RECEIPT, dates={"issue": "2026-09-03", "tax": "2026-09-03", "due": "2026-09-10"}),
                     "účtenka nemá splatnost")
        self.rejects(data(DATA_RECEIPT, paymentReference="123"), "variabilní symbol")
        self.rejects(data(DATA_RECEIPT, payment={"method": "bankTransfer"}), "kartou nebo hotově")
        self.rejects(data(DATA_DOMESTIC, paymentReference="A123"), "jen číslice")
        self.rejects(data(DATA_DOMESTIC, contentTag="x.y"), "contentTag")
        self.rejects(data(DATA_DOMESTIC, dates={**DATA_DOMESTIC["dates"], "periodFrom": "2026-08-01"}), "jen spolu")
        self.rejects(data(DATA_DOMESTIC, supplier="ghost"), "neznámý dodavatel")
        self.rejects(data(DATA_DOMESTIC, rows=[]), "chybí rows")

    # Hodnoty netištěné samostatně (#103 D110, D112)
    def test_unprinted_keys_and_values(self) -> None:
        self.rejects(data(DATA_EU_SERVICES, unprinted={"docNumber": "x"}), "unprinted.docNumber: neznámý klíč")
        self.rejects(data(DATA_EU_SERVICES, unprinted={"paymentReference": "note"}), "unprinted.paymentReference: hodnota 'note'")
        self.rejects(data(DATA_THIRD_COUNTRY_PERIOD, unprinted={"period": "header"}), "unprinted.period: hodnota 'header'")
        self.rejects(data(DATA_EU_SERVICES, unprinted="docNumber"), "unprinted není objekt")
        validate(data(DATA_EU_SERVICES, unprinted={}))
        validate(data(DATA_EU_SERVICES, unprinted=None))

    def test_unprinted_reference_rules(self) -> None:
        # D110: referencí je číslo dokladu — doklad pak nemá VS a není účtenka
        self.rejects(data(DATA_DOMESTIC, unprinted={"paymentReference": "docNumber"}), "VS i referenci")
        validate(data(DATA_DOMESTIC, paymentReference=None, unprinted={"paymentReference": "docNumber"}))
        self.rejects(data(DATA_RECEIPT, unprinted={"paymentReference": "docNumber"}), "účtenka referenci nemá")

    def test_unprinted_period_needs_dates_and_a_row_with_both(self) -> None:
        # D112: období jen v řádcích — hlavička ho má v datech, text řádku ho nese v tištěném tvaru
        self.rejects(data(DATA_THIRD_COUNTRY, unprinted={"period": "rows"}), "chybí dates.periodFrom / periodTo")
        without = copy.deepcopy(DATA_THIRD_COUNTRY_PERIOD)
        without["rows"][0]["description"] = "12 seats"
        with self.assertRaises(DataFileError) as ctx:
            validate(without)
        self.assertIn("žádný řádek nenese obě data období", str(ctx.exception))
        self.assertIn("2026-09-01 / 1. 9. 2026 / 01.09.2026 / 09/01/2026 / 01/09/2026", str(ctx.exception))
        only_from = copy.deepcopy(DATA_THIRD_COUNTRY_PERIOD)
        only_from["rows"][0]["description"] = "12 seats, from 09/01/2026"
        self.rejects(only_from, "obě data období")
        # přijaté tvary: český, ISO, tečkový bez mezer; data smí být v textu i popisu téhož řádku
        for text, description in (
            ("Předplatné 1. 9. 2026 – 30. 9. 2026", None),
            ("Team plan", "2026-09-01 – 2026-09-30"),
            ("Team plan", "01.09.2026-30.09.2026"),
            ("Team plan od 1.9.2026", "do 30. 9. 2026"),
        ):
            doc = copy.deepcopy(DATA_THIRD_COUNTRY_PERIOD)
            doc["rows"][0]["text"] = text
            doc["rows"][0]["description"] = description
            validate(doc)

    def test_printed_date_forms(self) -> None:
        self.assertEqual(("2026-09-01", "1. 9. 2026", "01.09.2026", "09/01/2026", "01/09/2026"),
                         printed_date_forms("2026-09-01"))
        self.assertEqual(("2026-10-31", "31. 10. 2026", "31.10.2026", "10/31/2026", "31/10/2026"),
                         printed_date_forms("2026-10-31"))
        self.assertTrue(row_carries_dates({"text": "Období 1. 9. 2026 – 30. 9. 2026"}, ("2026-09-01", "2026-09-30")))
        self.assertFalse(row_carries_dates({"text": "Období 1. 9. 2026", "description": None}, ("2026-09-01", "2026-09-30")))
        self.assertFalse(row_carries_dates({"text": "x"}, ("2026-09-01",)))

    def test_override_validated_per_profile(self) -> None:
        doc = data(DATA_DOMESTIC, overrides={"non-payer": {"currency": "czk"}})
        with self.assertRaises(DataFileError) as ctx:
            validate(doc)
        self.assertIn("[non-payer]", str(ctx.exception))

    def test_variant_pairs(self) -> None:
        validate_variant_pairs({"0101": DATA_DOMESTIC, "0102": data(DATA_DOMESTIC, form="pdfIsdoc")})
        with self.assertRaises(DataFileError):
            validate_variant_pairs({"0101": DATA_DOMESTIC, "0102": data(DATA_DOMESTIC, supplier="de")})
        with self.assertRaises(DataFileError) as ctx:
            validate_variant_pairs({"0101-domestic-goods": DATA_DOMESTIC, "0101-domestic-goods-b": data(DATA_DOMESTIC)})
        self.assertIn("pořadí 01 víckrát", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
