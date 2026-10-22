from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.datasetio import dumps_dataset
from invoicegen.dumpshape import (
    asset_record, asset_slug, asset_sort_key, bank_record, bank_slug, bank_sort_key, doc_record, doc_slug, doc_sort_key,
    php_float, work_order_record, work_order_slug, work_order_sort_key,
)
from invoicegen.model import AssetRec, DocRec, PeriodRec, RecapLine, RowRec, StatementRec, TxRec, WorkOrderRec, WorkOrderRowRec
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


class DocDimensionsTest(unittest.TestCase):
    def test_source_and_dimensions_sit_where_the_exporter_puts_them(self) -> None:
        # #131 fáze 2b: `source` hned za formatVersion, `dimensions` hlavičky
        # za notes před rows, `dimensions` řádku jako poslední klíč bloku.
        story = load_story(REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis", load_catalogs(REPO_ROOT))
        row = RowRec(operation="sale.services", item_code="SPRAVA-IT", item_name="Správa IT", description="paušál", unit="mnth",
                     quantity=Decimal("1"), unit_price=Decimal("100"), total_price=Decimal("100"), vat_pct=21, vat_code="cz-120",
                     work_order="P0001")
        plain = doc("invno", partner_key="kopretina", self_party="supplier", number="1260001", sequence=1, rows=[row])
        record = doc_record(plain, story)
        self.assertNotIn("source", record)
        self.assertNotIn("dimensions", record)
        self.assertEqual("dimensions", list(record["rows"][0])[-1])
        self.assertEqual({"workOrder": "P0001"}, record["rows"][0]["dimensions"])

        plain_row = RowRec(operation="sale.services", item_code="SPRAVA-IT", item_name="Správa IT", description="paušál", unit="mnth",
                           quantity=Decimal("1"), unit_price=Decimal("100"), total_price=Decimal("100"), vat_pct=21, vat_code="cz-120")
        periodic = doc("invno", partner_key="kopretina", self_party="supplier", number="1260002", sequence=2, rows=[plain_row],
                       source_kind="workOrder", work_order="S0001", doc_text="Správa IT — paušál leden 2026",
                       period_from=date(2026, 1, 1), period_to=date(2026, 1, 31))
        record = doc_record(periodic, story)
        keys = list(record)
        self.assertEqual(keys.index("formatVersion") + 1, keys.index("source"))
        self.assertEqual({"kind": "workOrder"}, record["source"])
        self.assertEqual(keys.index("payment") + 1, keys.index("dimensions"), "za payment (notes prázdné → pryč), před rows")
        self.assertEqual(keys.index("dimensions") + 1, keys.index("rows"))
        self.assertNotIn("dimensions", record["rows"][0], "řádek bez dimenze ji nenese")
        self.assertEqual({"workOrder": "S0001"}, record["dimensions"])
        self.assertEqual(("2026-01-01", "2026-01-31"), (record["dates"]["periodFrom"], record["dates"]["periodTo"]))

    def test_asset_purchase_row_carries_account_and_card(self) -> None:
        # #131 fáze 2c: řádek pořízení bez položky, účet přímo, karta jako dimenze
        # za zakázkou (pořadí deklarace dimenzí); řádek nákladu jen s kartou.
        story = load_story(REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis", load_catalogs(REPO_ROOT))
        server = RowRec(operation="purchase.asset", description="Server", unit="pcs", quantity=Decimal("1"),
                        unit_price=Decimal("150000"), total_price=Decimal("150000"), vat_pct=21, vat_code="cz-110",
                        account="042100", work_order="P0001", asset="MA0001")
        hosting = RowRec(operation="acc.entry", item_code="518206", item_name="Hosting", description="Hosting", unit="pcs",
                         quantity=Decimal("1"), unit_price=Decimal("4900"), total_price=Decimal("4900"), vat_pct=21, vat_code="cz-110",
                         asset="MA0001")
        record = doc_record(doc("invni", partner_key="cipdeska", self_party="customer", number="2260011", sequence=11, rows=[server, hosting]), story)
        row = record["rows"][0]
        self.assertNotIn("item", row, "řádek pořízení bez položky")
        self.assertEqual(("purchase.asset", "042100"), (row["operation"], row["account"]))
        self.assertEqual(["workOrder", "asset"], list(row["dimensions"]))
        self.assertEqual({"asset": "MA0001"}, record["rows"][1]["dimensions"])
        self.assertEqual("dimensions", list(row)[-1])
        self.assertEqual(["description", "account", "dimensions"], list(row)[-3:])


class WorkOrderRecordTest(unittest.TestCase):
    def test_key_order_and_pruning_mirror_exporter(self) -> None:
        story = load_story(REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis", load_catalogs(REPO_ROOT))
        rec = WorkOrderRec(
            key="billing:0", ordinal=0, series_code="S", sequence=1, number="S0001", title="Správa IT — Kopretina",
            state="confirmed", date_start=date(2024, 4, 1), customer_key="kopretina", currency="CZK", periodicity="month",
            invoice_from=date(2026, 1, 1), doc_text="Správa IT — paušál {období}", bank_account=None,
            rows=[WorkOrderRowRec(item_code="SPRAVA-IT", description="Správa IT — měsíční paušál", quantity=Decimal("1"),
                                  unit="mnth", unit_price=Decimal("16000"), vat_code="cz-120", valid_to=date(2026, 5, 31))],
            periods=[PeriodRec(date(2026, 1, 1), date(2026, 1, 31), "billing:0:-9"), PeriodRec(date(2026, 10, 1), date(2026, 10, 31), "billing:0:0")],
        )
        record = work_order_record(rec, story, {"billing:0:-9": "1260001", "billing:0:0": "!0000000155"})
        self.assertEqual(["format", "workOrder", "rows", "periods"], list(record))
        self.assertEqual("shpd.dataset.workOrder.v1", record["format"])
        self.assertEqual(["number", "sequenceNumber", "numberSeries", "title", "state", "dateStart", "customer", "currency", "invoicing"],
                         list(record["workOrder"]), "null hodnoty pryč (dateEnd, costCenter, paymentReference, parent)")
        self.assertEqual({"name": "Autodoprava Kopretina s.r.o.", "country": "cz", "companyId": "83715266", "taxId": "CZ83715266",
                          "vatId": "CZ83715266"}, record["workOrder"]["customer"], "štíhlá strana")
        self.assertEqual("czk", record["workOrder"]["currency"])
        self.assertEqual({"periodicity": "month", "invoiceFrom": "2026-01-01", "docText": "Správa IT — paušál {období}"},
                         record["workOrder"]["invoicing"], "přepisy druhu jen vyplněné")
        self.assertEqual('{\n    "item": "SPRAVA-IT",\n    "description": "Správa IT — měsíční paušál",\n    "quantity": 1.0,\n'
                         '    "unit": "mnth",\n    "unitPrice": 16000.0,\n    "vatCode": "cz-120",\n    "validTo": "2026-05-31"\n}\n',
                         dumps_dataset(record["rows"][0]), "čísla float jako V::float")
        self.assertEqual([{"from": "2026-01-01", "to": "2026-01-31", "state": "issued", "docNumber": "1260001"},
                          {"from": "2026-10-01", "to": "2026-10-31", "state": "issued", "docNumber": "!0000000155"}], record["periods"])

        project = WorkOrderRec(key="project:sit", ordinal=0, series_code="P", sequence=1, number="P0001", title="Výměna sítě",
                               state="finished", date_start=date(2026, 3, 17), date_end=date(2026, 4, 22),
                               customer_key="galvanovna", currency="CZK")
        record = work_order_record(project, story, {})
        self.assertEqual(["format", "workOrder"], list(record), "projekt bez předpisu, řádků a období")
        self.assertEqual("2026-04-22", record["workOrder"]["dateEnd"])
        self.assertEqual("P0001", work_order_slug(project))
        self.assertLess(work_order_sort_key(project), work_order_sort_key(rec), "P před S — podle čísla")


class AssetRecordTest(unittest.TestCase):
    def test_key_order_and_pruning_mirror_exporter(self) -> None:
        rec = AssetRec(key="asset:server", number="MA0001", name="Server 1U", type_name="Výpočetní technika", accounting_group="022",
                       acquisition_account="042100", tax_method="straight", tax_rule="cz-1", acc_method="as_tax",
                       activation=date(2026, 2, 14), amount=Decimal("150000"), purchase_doc_key="received:cipdeska:-8",
                       acc_plan=((date(2026, 3, 1), Decimal("3000")),))
        record = asset_record(rec)
        self.assertEqual(["format", "asset", "events"], list(record))
        self.assertEqual("shpd.dataset.asset.v1", record["format"])
        self.assertEqual(["assetNumber", "name", "type", "category", "tracking", "accountingGroup", "taxMethod", "taxRule", "accMethod", "state"],
                         list(record["asset"]), "null hodnoty pryč: shortName, note, foreign, owner, data a cena dlouhodobé karty, accMonths")
        self.assertEqual("confirmed", record["asset"]["state"])
        self.assertEqual([{"kind": "activation", "scope": "both", "date": "2026-02-14", "amount": Decimal("150000.0"), "origin": "manual"}],
                         record["events"], "jediná událost: zařazení s původem manual, plán odpisů se nepřenáší")
        self.assertEqual('{\n    "kind": "activation",\n    "scope": "both",\n    "date": "2026-02-14",\n    "amount": 150000.0,\n'
                         '    "origin": "manual"\n}\n', dumps_dataset(record["events"][0]), "částka float jako V::float")
        self.assertEqual("MA0001", asset_slug(rec))
        other = AssetRec(key="asset:x", number="MA0002", name="X", type_name="T", accounting_group="022", acquisition_account="042100",
                         tax_method="straight", tax_rule="cz-1", acc_method="as_tax", activation=date(2026, 1, 1),
                         amount=Decimal("1"), purchase_doc_key="k")
        self.assertLess(asset_sort_key(rec), asset_sort_key(other), "podle inventárního čísla")


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
