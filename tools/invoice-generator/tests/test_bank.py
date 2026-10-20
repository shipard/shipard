"""Bankovní výpisy demo sady (fáze 2a, #131 D17, D18, D22, U1): výpisy
navazují a končí kotvou, transakce jen s tím, co dodá banka, platby podle
chování odběratelů a dodavatelů, záměrně nespárované platby s partnerem,
mzdy a odvody s vyrovnávacím dokladem, poplatky, otevírací doklad,
determinismus a posun kotvy."""

from __future__ import annotations

import unittest
from collections import Counter
from datetime import date, timedelta
from decimal import Decimal

from invoicegen.bank import normalize_account, payroll_payment_date
from invoicegen.catalogs import load_catalogs
from invoicegen.dumpshape import bank_record
from invoicegen.expand import expand, payment_events, typo_reference
from invoicegen.story import load_story
from invoicegen.timeline import month_end
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"
CATALOGS = load_catalogs(REPO_ROOT)
TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "invoices" / "templates").glob("*.html")}
REGISTRY_TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "datasets" / "templates").glob("*.html")}


def records(anchor: date | None = None):
    story = load_story(STORY_DIR, CATALOGS, anchor=anchor)
    return story, expand(story, CATALOGS, invoice_templates=TEMPLATES, registry_templates=REGISTRY_TEMPLATES)


def transactions(recs):
    return [t for s in recs.bank for t in s.transactions]


class StatementsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.story, cls.records = records()
        cls.docs = {d.key: d for d in cls.records.docs}

    def test_statements_chain_balances_and_end_at_anchor(self) -> None:
        by_account: dict[str, list] = {}
        for statement in self.records.bank:
            by_account.setdefault(statement.account_code, []).append(statement)
        self.assertEqual({"BU1", "BU2"}, set(by_account))
        self.assertEqual(10, len(by_account["BU1"]), "leden–říjen, poplatek drží každý měsíc neprázdný")
        for code, statements in by_account.items():
            balance = Decimal(self.story.bank["accounts"][code]["openingBalance"])
            for statement in statements:
                self.assertEqual(balance, statement.opening, statement.external_id)
                self.assertEqual(statement.opening + sum(t.amount for t in statement.transactions), statement.closing)
                self.assertTrue(statement.transactions, "prázdný měsíc bez výpisu")
                self.assertEqual(1, statement.period_start.day)
                self.assertEqual(f"{statement.period_start.month}/{statement.period_start.year}", statement.number)
                self.assertEqual(f"{code}-{statement.period_start:%Y-%m}", statement.external_id)
                self.assertEqual(
                    [f"{code}-{statement.period_start:%Y%m}-{i:03d}" for i in range(1, len(statement.transactions) + 1)],
                    [t.external_id for t in statement.transactions],
                )
                balance = statement.closing
            self.assertEqual(self.story.anchor, statements[-1].period_end, "poslední výpis končí kotvou")
            self.assertTrue(all(s.period_end == month_end(s.period_start) for s in statements[:-1]))

    def test_transactions_sorted_inside_period_and_balance_never_negative(self) -> None:
        for statement in self.records.bank:
            dates = [t.date for t in statement.transactions]
            self.assertEqual(sorted(dates), dates, statement.external_id)
            running = statement.opening
            for tx in statement.transactions:
                self.assertTrue(statement.period_start <= tx.date <= statement.period_end, tx.key)
                self.assertTrue(self.story.period.start <= tx.date <= self.story.anchor, tx.key)
                running += tx.amount
                self.assertGreaterEqual(running, 0, tx.key)

    def test_currency_split_and_invoice_rate(self) -> None:
        for statement in self.records.bank:
            for tx in statement.transactions:
                if statement.account_code == "BU2":
                    self.assertEqual("EUR", statement.currency)
                    doc = self.docs[tx.doc_key]
                    self.assertEqual("EUR", doc.currency)
                    self.assertEqual(doc.rate, tx.exchange_rate, "kurz platby = kurz hrazené faktury (U1)")
                    self.assertGreater(tx.amount, 0, "na eurový účet jen příjmy")
                    self.assertTrue(tx.counterparty_account.startswith(("SK", "DE")), "IBAN zahraničního odběratele")
                else:
                    self.assertEqual("CZK", statement.currency)
                    self.assertIsNone(tx.exchange_rate)
                    if tx.key.startswith("pay:"):
                        self.assertEqual("CZK", self.docs[tx.doc_key].currency)

    def test_payments_follow_behaviour(self) -> None:
        paid: dict[str, list] = {}
        for tx in transactions(self.records):
            if tx.key.startswith("pay:"):
                paid.setdefault(tx.doc_key, []).append(tx)
        for key in paid:
            doc = self.docs[key]
            self.assertEqual(40, doc.state, "koncept se neplatí")
            self.assertNotEqual("cash", doc.payment_method, "hotovostní faktura je uhrazená pokladnou")
        for doc in self.records.docs:
            if doc.partner_key == "stranka" and doc.doc_type == "invno":
                self.assertNotIn(doc.key, paid, "never: bez platby")
            if doc.partner_key == "kopretina" and doc.doc_type == "invno" and doc.state == 40 and doc.payment_method != "cash":
                self.assertEqual([doc.due - timedelta(days=2)], [t.date for t in paid[doc.key]], "onTime: 2 dny před splatností")
            if doc.partner_key == "medvidek" and doc.state == 40 and doc.payment_method != "cash" and doc.key in paid:
                self.assertEqual([doc.due + timedelta(days=12)], [t.date for t in paid[doc.key]], "late: 12 dní po splatnosti")
            if doc.partner_key == "galvanovna" and doc.state == 40 and doc.key in paid:
                amounts = [t.amount for t in paid[doc.key]]
                self.assertEqual((doc.total_amount * Decimal("0.5")).quantize(Decimal("0.01")), amounts[0], "partial 50 % v den splatnosti")
                self.assertEqual(doc.due, paid[doc.key][0].date)
                if len(amounts) == 2:
                    self.assertEqual(doc.total_amount, sum(amounts), "doplatek dorovná fakturu")
                    self.assertEqual(doc.due + timedelta(days=25), paid[doc.key][1].date)
            if doc.doc_type == "invni" and doc.key in paid:
                tx = paid[doc.key][0]
                self.assertEqual(-doc.total_amount, tx.amount, "přijatá faktura = výdaj")
                self.assertEqual(doc.payment_reference, tx.payment_reference, "VS přijaté faktury")
                supplier = self.story.suppliers[doc.partner_key]
                self.assertEqual(supplier.identity["bankAccount"]["accountNumber"], tx.counterparty_account)
                days = {"klicek": 6, "jiskra": 15}.get(doc.partner_key, -1)
                self.assertEqual(doc.due + timedelta(days=days), tx.date, doc.key)
        galvanovna = [k for k in paid if self.docs[k].partner_key == "galvanovna"]
        self.assertTrue(any(len(paid[k]) == 2 for k in galvanovna), "starší faktury galvanovny doplacené")
        self.assertTrue(any(len(paid[k]) == 1 for k in galvanovna), "poslední faktura galvanovny jen částečně")
        for tx in transactions(self.records):
            if tx.key.startswith("pay:") and tx.fault is None and self.docs[tx.doc_key].doc_type in ("invno", "invpo"):
                self.assertEqual(self.docs[tx.doc_key].number, tx.payment_reference, "VS = číslo faktury")
                self.assertEqual(self.story.party(self.docs[tx.doc_key].partner_key).name, tx.counterparty_name)
        proforma = next(d for d in self.records.docs if d.doc_type == "invpo")
        self.assertEqual(1, len(paid[proforma.key]), "zálohová faktura uhrazená")

    def test_unmatched_payments_keep_partner_and_break_reference(self) -> None:
        faulty = [t for t in transactions(self.records) if t.fault]
        self.assertEqual({"missing": 2, "typo": 1}, Counter(t.fault for t in faulty))
        numbers = {d.number for d in self.records.docs if d.number}
        for tx in faulty:
            self.assertTrue(tx.counterparty_account, "nespárovaná platba se známým protiúčtem (D18)")
            self.assertEqual("BU1", tx.account_code)
            if tx.fault == "missing":
                self.assertIsNone(tx.payment_reference)
            else:
                self.assertNotIn(tx.payment_reference, numbers)
                self.assertEqual(len(self.docs[tx.doc_key].number), len(tx.payment_reference))

    def test_payroll_payments_pair_with_settlement_documents(self) -> None:
        payroll_txs = [t for t in transactions(self.records) if t.key.startswith("payroll:")]
        self.assertEqual(27, len(payroll_txs), "9 měsíců mezd × 3 platby (leden–září placené únor–říjen)")
        liabilities = {"net": "331100", "insurance": "336100", "tax": "342100"}
        for tx in payroll_txs:
            self.assertIsNone(tx.payment_reference, "bez VS (D22)")
            self.assertEqual("BU1", tx.account_code)
            self.assertLess(tx.amount, 0)
            settlement = self.docs[tx.doc_key]
            self.assertEqual(tx.date, settlement.issue, "vyrovnávací doklad téhož dne")
            self.assertEqual(-tx.amount, settlement.total_amount)
            self.assertEqual("cmnbkp", settlement.doc_type)
            self.assertEqual([("debit", liabilities[tx.key.split(":")[2]]), ("credit", "261300")],
                             [(r.acc_side, r.account) for r in settlement.rows])
            self.assertIn(tx.message.split(" ")[0], ("Výplata", "Pojistné", "Záloha"))
        self.assertEqual(27, sum(1 for d in self.records.docs if d.key.startswith("settlement:")))
        taken: set[str] = set()
        for party in self.story.parties().values():
            bank = party.identity.get("bankAccount") or {}
            for value in (bank.get("accountNumber"), bank.get("iban")):
                if value:
                    taken |= set(normalize_account(value))
        for tx in payroll_txs:
            self.assertFalse(set(normalize_account(tx.counterparty_account)) & taken, "protiúčet mezd nepatří nikomu v sadě")
        # Mzdy za měsíc kotvy ještě nejsou (doklad 31. 10. je za kotvou); září se platí v říjnu do kotvy.
        self.assertIsNone(payroll_payment_date(self.story, 0, self.story.bank["payroll"][0]))
        self.assertEqual(date(2026, 10, 10), payroll_payment_date(self.story, -1, self.story.bank["payroll"][0]))

    def test_fees_and_opening_document(self) -> None:
        fees = [t for t in transactions(self.records) if t.operation == "fee.out"]
        self.assertEqual(9, len(fees), "každý dokončený měsíc; říjen až s koncem měsíce")
        for tx in fees:
            self.assertEqual("BU1", tx.account_code)
            self.assertEqual(Decimal("-169"), tx.amount)
            self.assertEqual(month_end(tx.date), tx.date)
            self.assertIsNone(tx.counterparty_account)
            self.assertIsNone(tx.payment_reference)
        self.assertTrue(all(t.operation in (None, "fee.out") for t in transactions(self.records)))
        opening = self.docs["opening"]
        self.assertEqual("opening", opening.fiscal_period_type)
        self.assertEqual(self.story.period.start, opening.issue)
        self.assertEqual([("221100", "debit"), ("701100", "credit")], [(r.account, r.acc_side) for r in opening.rows])
        self.assertEqual(Decimal(self.story.bank["accounts"]["BU1"]["openingBalance"]), opening.total_amount)
        self.assertEqual("60260001", opening.number, "první interní doklad roku; mzdy se posunou")
        self.assertTrue(all(d.fiscal_period_type is None for d in self.records.docs if d.key != "opening"))

    def test_payment_events_cover_all_invoices(self) -> None:
        events = payment_events(self.story, self.records.docs)
        kinds = Counter(e.kind for e in events)
        invoices = [d for d in self.records.docs if d.doc_type in ("invno", "invpo", "invni")]
        self.assertEqual(len(invoices) + kinds["remainder"], sum(kinds.values()), "událost per faktura + doplatky")
        self.assertEqual(kinds["partial"], kinds["remainder"], "každá částečná úhrada má naplánovaný doplatek")
        self.assertEqual(3, kinds["cash"])
        self.assertTrue({e.direction for e in events if self.docs[e.doc_key].doc_type == "invni"} == {2})

    def test_typo_reference(self) -> None:
        self.assertEqual("1260050", typo_reference("1260005"))
        self.assertEqual("1260012", typo_reference("1260021"))
        self.assertEqual("1260075", typo_reference("1260057"))
        self.assertEqual("1260507", typo_reference("1260057", frozenset({"1260075"})), "kolize → další dvojice od konce")
        self.assertEqual("11110", typo_reference("1111"))


class DeterminismTest(unittest.TestCase):
    def test_two_runs_give_the_same_statements(self) -> None:
        _, first = records()
        _, second = records()
        self.assertEqual([bank_record(s) for s in first.bank], [bank_record(s) for s in second.bank])

    def test_anchor_shift_moves_statements_and_keeps_content(self) -> None:
        _, base = records()
        _, shifted = records(anchor=date(2027, 1, 20))
        self.assertEqual(len(base.bank), len(shifted.bank))
        # Aritmetika dnů (splatnost + N dní) může platbu posunout přes hranici
        # měsíce, proto se srovnává obsah za celé období, ne per výpis.
        for code in ("BU1", "BU2"):
            base_statements = [s for s in base.bank if s.account_code == code]
            shifted_statements = [s for s in shifted.bank if s.account_code == code]
            self.assertEqual(len(base_statements), len(shifted_statements), code)
            self.assertEqual(base_statements[0].opening, shifted_statements[0].opening, code)
            self.assertEqual(base_statements[-1].closing, shifted_statements[-1].closing, f"{code}: stejné platby za období")
            self.assertEqual(
                Counter((t.amount, t.operation, t.fault, t.doc_key) for s in base_statements for t in s.transactions),
                Counter((t.amount, t.operation, t.fault, t.doc_key) for s in shifted_statements for t in s.transactions),
                code,
            )
        deltas = {(s.period_start.year - b.period_start.year) * 12 + s.period_start.month - b.period_start.month
                  for b, s in zip(base.bank, shifted.bank)}
        self.assertEqual({3}, deltas, "výpisy posunuté o tři měsíce")
        self.assertEqual(date(2027, 1, 20), [s for s in shifted.bank if s.account_code == "BU1"][-1].period_end)
        self.assertEqual("1/2027", [s for s in shifted.bank if s.account_code == "BU1"][-1].number)


if __name__ == "__main__":
    unittest.main()
