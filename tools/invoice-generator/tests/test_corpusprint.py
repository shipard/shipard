"""Hodnoty pro šablony korpusu (`invoicegen.corpusprint.template_values`)."""

from __future__ import annotations

import unittest

from invoicegen.compute import compute
from invoicegen.corpusprint import template_values
from tests.fixtures import DATA_DOMESTIC, DATA_EU_SERVICES, DATA_RECEIPT, IDENTITIES, data


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


if __name__ == "__main__":
    unittest.main()
