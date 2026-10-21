from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.datasetio import dumps_dataset
from invoicegen.dumpshape import bank_record, bank_slug, bank_sort_key, doc_record, doc_slug, doc_sort_key, php_float
from invoicegen.model import DocRec, RecapLine, StatementRec, TxRec
from invoicegen.story import load_story
from tests.fixtures import REPO_ROOT


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

    def test_reverse_pair_recap_line_mirrors_exporter(self) -> None:
        """`DocumentExporter::vatRecap`: `isReversePair` jen true, jinak klíč vynechaný."""
        story = load_story(REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis", load_catalogs(REPO_ROOT))
        record = doc_record(doc("invni", currency="EUR", rate=Decimal("25.12"), vat_place="intracom", self_party="customer",
                                recap=[RecapLine("cz-217", 21, Decimal("188.00"), Decimal("39.48"), Decimal("188.00")),
                                       RecapLine("cz-207", 21, Decimal("188.00"), Decimal("39.48"), Decimal("227.48"), True)]),
                            story)
        self.assertEqual([{"vatCode": "cz-217", "vatPct": php_float(21), "base": Decimal("188.00"), "tax": Decimal("39.48"),
                           "total": Decimal("188.00")},
                          {"vatCode": "cz-207", "vatPct": php_float(21), "base": Decimal("188.00"), "tax": Decimal("39.48"),
                           "total": Decimal("227.48"), "isReversePair": True}], record["vatRecap"])
        self.assertEqual(("EUR", "intracom"), (record["currency"], record["vat"]["place"]))


class BankRecordTest(unittest.TestCase):
    """`BankStatementExporter`: top-level klíče pevně, uvnitř prune, částky
    float, výchozí pohyb a kurz na účtu v domácí měně vynechané."""

    def statement(self, **over) -> StatementRec:
        base = dict(
            account_code="BU1", currency="CZK", period_start=date(2026, 2, 1), period_end=date(2026, 2, 28), number="2/2026",
            external_id="BU1-2026-02", opening=Decimal("480000"), closing=Decimal("488543.00"),
            transactions=[
                TxRec(key="a", account_code="BU1", date=date(2026, 2, 9), amount=Decimal("8712.00"), ordinal=1,
                      counterparty_account="2440913574/0300", counterparty_name="Autodoprava", payment_reference="1260005",
                      external_id="BU1-202602-001"),
                TxRec(key="b", account_code="BU1", date=date(2026, 2, 28), amount=Decimal("-169"), ordinal=2,
                      message="Poplatek", operation="fee.out", external_id="BU1-202602-002"),
            ],
        )
        base.update(over)
        return StatementRec(**base)

    def test_key_order_and_pruning_mirror_exporter(self) -> None:
        record = bank_record(self.statement())
        self.assertEqual(["format", "bankAccount", "statement", "transactions", "docState"], list(record))
        self.assertEqual("shpd.dataset.bankStatement.v1", record["format"])
        self.assertEqual(["statementNumber", "externalId", "periodStart", "periodEnd", "openingBalance", "closingBalance", "currency"],
                         list(record["statement"]))
        self.assertEqual(["externalId", "amount", "dateTransaction", "counterpartyAccount", "counterpartyName", "paymentReference"],
                         list(record["transactions"][0]))
        self.assertEqual(["externalId", "amount", "dateTransaction", "message", "operation"], list(record["transactions"][1]))
        self.assertEqual(40, record["docState"])
        text = dumps_dataset(record)
        for fragment in ('"amount": 8712.0', '"amount": -169.0', '"openingBalance": 480000.0', '"closingBalance": 488543.0'):
            self.assertIn(fragment, text)
        self.assertNotIn("exchangeRate", text)

    def test_foreign_account_carries_rate(self) -> None:
        eur = self.statement(account_code="BU2", currency="EUR", external_id="BU2-2026-02", opening=Decimal("0"), closing=Decimal("1200"),
                             transactions=[TxRec(key="c", account_code="BU2", date=date(2026, 2, 12), amount=Decimal("1200.00"), ordinal=1,
                                                 counterparty_account="SK3111000000002612345678", counterparty_name="Tatranka",
                                                 payment_reference="1260007", exchange_rate=Decimal("25.08"), external_id="BU2-202602-001")])
        text = dumps_dataset(bank_record(eur))
        self.assertIn('"exchangeRate": 25.08', text)
        self.assertIn('"currency": "EUR"', text)

    def test_empty_statement_keeps_transactions_key(self) -> None:
        record = bank_record(self.statement(transactions=[]))
        self.assertEqual([], record["transactions"])
        self.assertEqual(["format", "bankAccount", "statement", "transactions", "docState"], list(record))

    def test_order_and_slug_mirror_exporter(self) -> None:
        statement = self.statement()
        self.assertEqual(("BU1", date(2026, 2, 1), "2/2026"), bank_sort_key(statement))
        self.assertEqual("BU1-2026-02", bank_slug(statement))


if __name__ == "__main__":
    unittest.main()
