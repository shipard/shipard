from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.datasetio import dumps_dataset
from invoicegen.dumpshape import doc_slug, doc_sort_key, php_float
from invoicegen.model import DocRec


def doc(doc_type: str, **kwargs) -> DocRec:
    base = dict(key="k", doc_type=doc_type, ordinal=1, issue=date(2026, 5, 1), accounting=date(2026, 5, 1), due=None,
                tax_point=None, vat_obligation=None, currency="CZK", rate=Decimal("1"), vat_mode="fromBase",
                vat_place="domestic", payment_method="bankTransfer", partner_key=None, self_party=None, state=40, rows=[])
    base.update(kwargs)
    return DocRec(**base)


class PhpFloatTest(unittest.TestCase):
    def test_renders_like_php_float_columns(self) -> None:
        value = {"a": php_float(1), "b": php_float(Decimal("21")), "c": php_float(Decimal("486.50")),
                 "d": php_float(Decimal("2340.00")), "e": php_float(Decimal("0.718")), "f": php_float(0)}
        self.assertEqual('{\n    "a": 1.0,\n    "b": 21.0,\n    "c": 486.5,\n    "d": 2340.0,\n    "e": 0.718,\n    "f": 0.0\n}\n',
                         dumps_dataset(value))


class DocOrderTest(unittest.TestCase):
    def test_order_mirrors_document_exporter(self) -> None:
        docs = [
            doc("invno", ordinal=5, number="1260002", sequence=2),
            doc("invno", ordinal=6, state=10, issue=date(2026, 10, 7)),
            doc("invno", ordinal=4, number="1260001", sequence=1),
            doc("cashreg", ordinal=3, number="14POK2600001", sequence=1, series_code="POK"),
            doc("cash", ordinal=2, number="31POK2600001", sequence=1, series_code="POK"),
            doc("cmnbkp", ordinal=1, number="60260001", sequence=1),
            doc("invpo", ordinal=7, number="12260001", sequence=1),
            doc("invni", ordinal=8, number="2260001", sequence=1),
        ]
        ordered = [d.doc_type + ":" + (d.number or "koncept") for d in sorted(docs, key=doc_sort_key)]
        self.assertEqual(["cash:31POK2600001", "cashreg:14POK2600001", "cmnbkp:60260001", "invni:2260001",
                          "invno:koncept", "invno:1260001", "invno:1260002", "invpo:12260001"], ordered)

    def test_slug_of_concept_and_placeholder(self) -> None:
        self.assertEqual("koncept invno", doc_slug(doc("invno", state=10)))
        self.assertEqual("!0000000117", doc_slug(doc("invno", state=10, number="!0000000117")))


if __name__ == "__main__":
    unittest.main()
