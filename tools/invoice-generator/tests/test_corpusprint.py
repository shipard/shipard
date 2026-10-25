"""Hodnoty pro šablony korpusu (`invoicegen.corpusprint.template_values`)."""

from __future__ import annotations

import unittest

from invoicegen.compute import compute
from invoicegen.corpusprint import template_values
from tests.fixtures import (
    DATA_DOMESTIC, DATA_EU_SERVICES, DATA_EU_SERVICES_REFERENCE, DATA_RECEIPT, DATA_THIRD_COUNTRY_PERIOD, IDENTITIES,
    data,
)


def values(doc: dict, *, locale: str, receipt: bool = False) -> dict[str, str]:
    computed = compute(doc, vat_treatment=doc["vatTreatment"])
    return template_values(doc, IDENTITIES["suppliers"][doc["supplier"]], IDENTITIES["profiles"]["vat-payer"],
                           computed, locale=locale, receipt=receipt)


class ItemsTest(unittest.TestCase):
    def test_code_column_only_when_a_row_has_code(self) -> None:
        plain = values(data(DATA_DOMESTIC), locale="cs")
        self.assertTrue(plain["itemsHead"].startswith('<tr><th class="h-text">Označení dodávky</th>'), plain["itemsHead"])
        self.assertNotIn("c-code", plain["rows"])

        coded = values(data(DATA_EU_SERVICES), locale="en")
        self.assertTrue(coded["itemsHead"].startswith('<tr><th class="h-code">Code</th><th class="h-text">Description</th>'),
                        coded["itemsHead"])
        rows = coded["rows"].split("\n")
        self.assertIn('<td class="c-code">MK8S-M</td><td class="c-text">Managed Kubernetes cluster'
                      '<span class="item-detail">Billing period 08/2026</span></td>', rows[0])
        self.assertIn('<td class="c-code"></td><td class="c-text">Object storage</td>', rows[1])

    def test_czech_code_label(self) -> None:
        doc = data(DATA_DOMESTIC)
        doc["rows"][0]["supplierCode"] = "SP-18"
        self.assertIn('<th class="h-code">Kód</th><th class="h-text">Označení dodávky</th>', values(doc, locale="cs")["itemsHead"])

    def test_court_registration_label_by_locale(self) -> None:
        # D103 (#103): hodnota bez popisku, popisek tiskne šablona podle jazyka.
        self.assertEqual("Spisová značka: C 99999 vedená u Krajského soudu v Brně",
                         values(data(DATA_DOMESTIC), locale="cs")["supplierCourtReg"])
        self.assertEqual("Amtsgericht Berlin-Charlottenburg, HRB 999999",
                         values(data(DATA_EU_SERVICES), locale="en")["supplierCourtReg"])
        without = data(DATA_EU_SERVICES)
        without["supplier"] = "us"  # identita bez spisové značky → prázdná patička
        self.assertEqual("", values(without, locale="en")["supplierCourtReg"])

    def test_receipt_has_no_code_column(self) -> None:
        receipt = values(data(DATA_RECEIPT), locale="cs", receipt=True)
        self.assertNotIn("h-code", receipt["itemsHead"])
        self.assertIn("ÚČTENKA", receipt["docTitle"])


class HeaderRowsTest(unittest.TestCase):
    """Hodnoty netištěné samostatně (#103 D110, D112): očekávání je nese, šablona ne."""

    def test_period_row_only_when_header_prints_it(self) -> None:
        header = values(data(DATA_EU_SERVICES), locale="en")
        self.assertIn("Service period", header["dateRows"])
        self.assertIn("2026-08-01 – 2026-08-31", header["dateRows"])
        in_rows = values(DATA_THIRD_COUNTRY_PERIOD, locale="en")
        self.assertNotIn("Service period", in_rows["dateRows"])
        self.assertNotIn("2026-09-30", in_rows["dateRows"])
        self.assertIn("12 seats, 09/01/2026 – 09/30/2026", in_rows["rows"])
        # bez bloku unprinted by se totéž období tisklo v hlavičce
        self.assertIn("Service period", values(data(DATA_THIRD_COUNTRY_PERIOD, unprinted=None), locale="en")["dateRows"])

    def test_reference_equal_to_doc_number_has_no_own_row(self) -> None:
        printed = values(DATA_EU_SERVICES_REFERENCE, locale="en")
        self.assertNotIn("Payment reference", printed["paymentRows"])
        self.assertEqual("INV-2026-0388", printed["docNumber"])
        self.assertIn("Please quote the invoice number", printed["noteBlock"])
        with_vs = values(data(DATA_DOMESTIC), locale="cs")
        self.assertIn("Variabilní symbol", with_vs["paymentRows"])


if __name__ == "__main__":
    unittest.main()
