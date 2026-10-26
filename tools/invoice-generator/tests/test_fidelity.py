from __future__ import annotations

import unittest
from pathlib import Path

from invoicegen.fidelity import (
    IDENTIFIER_PATHS, FidelityError, Finding, identifier_values, missing_identifiers, pdf_text, squash, summarize,
)

EXPECTED = {
    "docNumber": "FV-2026-1180",
    "supplier": {
        "name": "Dodavatelna s.r.o.", "companyId": "06388451", "vatId": "CZ06388451",
        "courtRegistration": "C 99999 vedená u Krajského soudu v Brně",
        "contact": {"name": None, "email": "fakturace@dodavatelna.example", "phone": "+420000123456", "web": None},
        "bankAccount": {"accountNumber": "2308417557/2010", "iban": "CZ8520100000002308417557", "bic": None},
    },
    "customer": {"companyId": "07123451", "vatId": None},
    "payment": {"method": "bankTransfer", "paymentReference": "20261180", "specificSymbol": None, "constantSymbol": "0308"},
    "rows": [
        {"item": {"supplierCode": "SP-SM-18", "ean": None, "name": "Spárovka", "description": None}},
        {"item": {"supplierCode": None, "ean": "4260999100013", "name": "Deska", "description": "Billing period 08/2026"}},
    ],
}

# Text, jak ho dává pdftotext: IBAN po čtveřicích, telefon s mezerami,
# spisová značka zalomená přes řádek, popisek sloupce s roztaženým písmem.
PDF_TEXT = """Dodavatelna s.r.o.
FAKTURA — DAŇOVÝ DOKLAD
č. FV-2026-1180
IČO: 06388451 · DIČ: CZ06388451
fakturace@dodavatelna.example · +420 000 123 456
O D B Ě R AT E L
IČO: 07123451
Bankovní účet
2308417557/2010
IBAN
CZ85 2010 0000 0023 0841 7557
Variabilní symbol
20261180
Konstantní symbol
0308
SP-SM-18
Spárovka
Deska
EAN 4260999100013
Billing period
08/2026
Spisová značka: C 99999 vedená u Krajského
soudu v Brně
"""


class IdentifierValuesTest(unittest.TestCase):
    def test_non_null_values_in_path_order(self) -> None:
        values = identifier_values(EXPECTED)
        self.assertEqual(("docNumber", "FV-2026-1180"), values[0])
        self.assertIn(("supplier.bankAccount.iban", "CZ8520100000002308417557"), values)
        self.assertIn(("customer.companyId", "07123451"), values)
        self.assertIn(("rows[0].item.supplierCode", "SP-SM-18"), values)
        self.assertIn(("rows[1].item.ean", "4260999100013"), values)
        self.assertIn(("rows[1].item.description", "Billing period 08/2026"), values)
        paths = [path for path, _ in values]
        self.assertNotIn("supplier.contact.web", paths)          # null se přeskočí
        self.assertNotIn("rows[0].item.ean", paths)
        self.assertNotIn("supplier.bankAccount.bic", paths)
        self.assertEqual(len(IDENTIFIER_PATHS), 20)


    def test_missing_objects_are_tolerated(self) -> None:
        self.assertEqual([("docNumber", "X")], identifier_values({"docNumber": "X", "customer": None, "rows": None}))


class MissingIdentifiersTest(unittest.TestCase):
    def test_squash(self) -> None:
        self.assertEqual("1234", squash("1 2 3 \n4"))

    def test_everything_found_despite_print_formatting(self) -> None:
        self.assertEqual([], missing_identifiers(EXPECTED, PDF_TEXT))

    def test_missing_values_with_row_index(self) -> None:
        text = PDF_TEXT.replace("SP-SM-18\n", "").replace("EAN 4260999100013\n", "").replace("CZ85 2010", "CZ85 2011")
        findings = missing_identifiers(EXPECTED, text)
        self.assertEqual(
            [Finding("supplier.bankAccount.iban", "CZ8520100000002308417557"),
             Finding("rows[0].item.supplierCode", "SP-SM-18"),
             Finding("rows[1].item.ean", "4260999100013")],
            findings,
        )
        self.assertEqual("rows[].item.ean", findings[2].node)
        self.assertEqual({"supplier.bankAccount.iban": 1, "rows[].item.supplierCode": 1, "rows[].item.ean": 1},
                         dict(summarize(findings)))

    def test_reference_equal_to_doc_number_is_found_as_doc_number(self) -> None:
        # D110 (#103): referencí je číslo dokladu — na PDF je jen jako číslo dokladu, řádek VS není.
        expected = {"docNumber": "INV-2026-0388", "payment": {"paymentReference": "INV-2026-0388"}}
        text = "INVOICE\nNo. INV-2026-0388\nPayment details\nIBAN\nDE92 1005 0000 6681 2300 67\n" \
               "Please quote the invoice number with your payment.\n"
        self.assertEqual([], missing_identifiers(expected, text))
        self.assertEqual(
            [Finding("docNumber", "INV-2026-0388"), Finding("payment.paymentReference", "INV-2026-0388")],
            missing_identifiers(expected, text.replace("INV-2026-0388", "INV-2026-0399")),
        )

    def test_summary_counts_rows_together(self) -> None:
        findings = [Finding("rows[0].item.supplierCode", "A"), Finding("rows[7].item.supplierCode", "B")]
        self.assertEqual([("rows[].item.supplierCode", 2)], summarize(findings).most_common())


class PdfTextTest(unittest.TestCase):
    def test_missing_tool_is_fidelity_error(self) -> None:
        with self.assertRaises(FidelityError) as ctx:
            pdf_text(Path("/nonexistent.pdf"), command="pdftotext-which-does-not-exist")
        self.assertIn("poppler-utils", str(ctx.exception))

    def test_failed_run_is_fidelity_error(self) -> None:
        with self.assertRaises(FidelityError) as ctx:
            pdf_text(Path("/nonexistent/file.pdf"), command="false")
        self.assertIn("kódem", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
