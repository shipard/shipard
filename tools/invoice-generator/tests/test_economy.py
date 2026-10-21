"""Ekonomika příběhu „IT servis“ (tasks/demo-dataset-economy.md, #131 D25):
cíle nad výchozí kotvou — zisk 8–15 % výnosů, ztrátové nejvýš tři měsíce,
zahraniční odběratelé 10–20 % tržeb, zboží s maržou, počáteční zůstatek BU1
nejvýš 300 tisíc a zůstatky nikdy pod nulou — a vnitřní konzistence souhrnu."""

from __future__ import annotations

import unittest
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.economy import format_summary, summarize
from invoicegen.expand import expand
from invoicegen.story import load_story
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"
CATALOGS = load_catalogs(REPO_ROOT)
TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "invoices" / "templates").glob("*.html")}
REGISTRY_TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "datasets" / "templates").glob("*.html")}


class EconomyTargetsTest(unittest.TestCase):
    """Cíle D25 nad výchozí kotvou příběhu (posun kotvy hlídá jen validace zůstatků v bank.py)."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.story = load_story(STORY_DIR, CATALOGS)
        cls.records = expand(cls.story, CATALOGS, invoice_templates=TEMPLATES, registry_templates=REGISTRY_TEMPLATES)
        cls.summary = summarize(cls.story, cls.records)

    def test_profit_between_8_and_15_percent_of_revenue(self) -> None:
        self.assertGreater(self.summary.revenue, 0)
        self.assertGreaterEqual(self.summary.margin, Decimal("0.08"), format_summary(self.summary)[0])
        self.assertLessEqual(self.summary.margin, Decimal("0.15"), format_summary(self.summary)[0])

    def test_at_most_three_months_in_loss(self) -> None:
        losses = self.summary.loss_months
        self.assertLessEqual(len(losses), 3, format_summary(self.summary)[1])
        self.assertEqual(len(self.story.period.months), len(self.summary.months), "každý měsíc období má řádek")

    def test_foreign_customers_between_10_and_20_percent(self) -> None:
        self.assertGreaterEqual(self.summary.foreign_share, Decimal("0.10"), format_summary(self.summary)[2])
        self.assertLessEqual(self.summary.foreign_share, Decimal("0.20"), format_summary(self.summary)[2])

    def test_goods_sold_with_margin(self) -> None:
        self.assertGreater(self.summary.goods_bought, 0, "velkoobchod v příběhu dodává zboží")
        self.assertGreaterEqual(self.summary.goods_sold, self.summary.goods_bought * Decimal("1.15"),
                                format_summary(self.summary)[2])

    def test_opening_balance_and_minimums(self) -> None:
        czk = self.summary.balance(self.story.bank_account_code)
        self.assertLessEqual(czk.opening, Decimal("300000"))
        self.assertEqual(Decimal(self.story.bank["accounts"][czk.code]["openingBalance"]), czk.opening)
        for balance in self.summary.balances:
            self.assertGreaterEqual(balance.lowest, 0, balance.code)
            self.assertLessEqual(balance.lowest, balance.opening, balance.code)
            self.assertLessEqual(balance.lowest, balance.closing, balance.code)

    def test_wages_unchanged(self) -> None:
        figures = self.story.payroll["figures"]
        months = self.story.payroll.get("months") or {}
        expected = Decimal("0")
        for offset in self.story.period.offsets:
            amounts = {**figures, **(months.get(str(offset)) or {})}
            paid = [d for d in self.records.docs if d.key == f"payroll:{offset}"]
            if paid:
                expected += Decimal(amounts["gross"]) + Decimal(amounts["employer"])
        self.assertEqual(expected, self.summary.wages)


class EconomySummaryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.story = load_story(STORY_DIR, CATALOGS)
        cls.records = expand(cls.story, CATALOGS, invoice_templates=TEMPLATES, registry_templates=REGISTRY_TEMPLATES)
        cls.summary = summarize(cls.story, cls.records)

    def test_months_add_up_to_totals(self) -> None:
        s = self.summary
        self.assertEqual(s.revenue, sum((m.revenue for m in s.months), Decimal("0")))
        self.assertEqual(s.costs, sum((m.costs for m in s.months), Decimal("0")))
        self.assertEqual(s.costs, s.wages + s.suppliers + s.cash_expenses + s.fees)
        self.assertEqual(s.result, s.revenue - s.costs)
        self.assertEqual([m.month for m in s.months], list(self.story.period.months))

    def test_only_accounted_documents_count(self) -> None:
        concepts = [d for d in self.records.docs if d.state != 40]
        self.assertTrue(concepts)
        docs = [d for d in self.records.docs if d.state == 40 and d.doc_type in ("invno", "cashreg")]
        expected = sum(((d.total_base * d.rate).quantize(Decimal("0.01")) for d in docs), Decimal("0"))
        self.assertEqual(expected, self.summary.revenue)
        proformas = [d for d in self.records.docs if d.doc_type == "invpo"]
        self.assertTrue(proformas, "zálohová faktura v příběhu je, do výnosů nepatří")

    def test_foreign_revenue_and_goods(self) -> None:
        s = self.summary
        foreign = [d for d in self.records.docs if d.state == 40 and d.doc_type == "invno"
                   and self.story.party(d.partner_key).country != "cz"]
        self.assertEqual(sum(((d.total_base * d.rate).quantize(Decimal("0.01")) for d in foreign), Decimal("0")),
                         s.foreign_revenue)
        self.assertGreater(s.goods_sold, 0)
        self.assertGreater(s.goods_bought, 0)

    def test_fees_and_balances_come_from_statements(self) -> None:
        s = self.summary
        fees = [t for st in self.records.bank for t in st.transactions if t.operation == "fee.out"]
        self.assertEqual(sum((-t.amount for t in fees), Decimal("0")), s.fees)
        self.assertEqual({st.account_code for st in self.records.bank}, {b.code for b in s.balances})
        last = {st.account_code: st for st in sorted(self.records.bank, key=lambda x: x.period_start)}
        for balance in s.balances:
            self.assertEqual(last[balance.code].closing, balance.closing)

    def test_format_summary_lines(self) -> None:
        lines = format_summary(self.summary)
        self.assertTrue(lines[0].startswith("výnosy "))
        self.assertIn("% výnosů", lines[0])
        self.assertTrue(lines[1].startswith("měsíce ve ztrátě: "))
        self.assertIn("zahraniční odběratelé", lines[2])
        self.assertEqual(2 + len(self.summary.balances), len(lines) - 1)
        self.assertTrue(any(line.startswith("účet BU1: počátek") for line in lines))


if __name__ == "__main__":
    unittest.main()
