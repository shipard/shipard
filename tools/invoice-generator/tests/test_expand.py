from __future__ import annotations

import unittest
from collections import Counter
from datetime import date
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.doccalc import calc_document
from invoicegen.expand import DOC_ID_CODES, expand, format_number, payment_events
from invoicegen.jsonc import load_jsonc
from invoicegen.model import DocRec, RowRec
from invoicegen.story import load_story
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"
CATALOGS = load_catalogs(REPO_ROOT)
TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "invoices" / "templates").glob("*.html")}
REGISTRY_TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "datasets" / "templates").glob("*.html")}


def records(anchor: date | None = None):
    story = load_story(STORY_DIR, CATALOGS, anchor=anchor)
    return story, expand(story, CATALOGS, invoice_templates=TEMPLATES, registry_templates=REGISTRY_TEMPLATES)


def signature(doc: DocRec) -> tuple:
    return (doc.doc_type, doc.state, doc.partner_key, tuple((r.item_code, r.quantity, r.total_price) for r in doc.rows),
            doc.total_amount, doc.currency)


class NumberingTest(unittest.TestCase):
    def test_formats_follow_doc_types_config(self) -> None:
        config = load_jsonc(REPO_ROOT / "modules" / "docs" / "core" / "config" / "docTypes.jsonc")
        for code, doc_id in DOC_ID_CODES.items():
            self.assertEqual(doc_id, config[code]["doc_id_code"], code)
            expected_pattern = "%D%C%y%5" if config[code].get("series_binding") == "cash_desk" else "%D%y%C%4"
            self.assertEqual(expected_pattern, config[code]["doc_number_pattern_default"], code)
        self.assertEqual("1260001", format_number("invno", "2026", 1, None))
        self.assertEqual("12260001", format_number("invpo", "2026", 1, None))
        self.assertEqual("2260012", format_number("invni", "2026", 12, None))
        self.assertEqual("60260001", format_number("cmnbkp", "2026", 1, None))
        self.assertEqual("31POK2600001", format_number("cash", "2026", 1, "POK"))
        self.assertEqual("14POK2600039", format_number("cashreg", "2026", 39, "POK"))


class ExpandTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.story, cls.records = records()

    def test_counts_and_states(self) -> None:
        by_type = Counter((d.doc_type, d.state) for d in self.records.docs)
        self.assertEqual(119, by_type[("invno", 40)])
        self.assertEqual(4, by_type[("invno", 10)])
        self.assertEqual(1, by_type[("invpo", 40)])
        self.assertEqual(48, by_type[("invni", 40)])
        self.assertEqual(39, by_type[("cashreg", 40)])
        self.assertEqual(20, by_type[("cash", 40)])
        self.assertEqual(9, by_type[("cmnbkp", 40)])
        self.assertEqual(24, len(self.records.persons))
        self.assertEqual(["F%05d" % i for i in range(1, 25)], [p.person_id for p in self.records.persons])
        self.assertEqual(52, len(self.records.mails))
        self.assertEqual(48, sum(1 for m in self.records.mails if m.doc_state == 40))
        self.assertEqual(3, sum(1 for m in self.records.mails if m.doc_state == 20))
        self.assertEqual(1, sum(1 for m in self.records.mails if m.primary_type == "other"))
        self.assertEqual(6, len(self.records.registry))

    def test_numbers_are_continuous_and_chronological(self) -> None:
        for doc_type in ("invno", "invni", "cashreg", "cash", "cmnbkp"):
            numbered = sorted((d for d in self.records.docs if d.doc_type == doc_type and d.state == 40),
                              key=lambda d: d.sequence or 0)
            self.assertEqual(list(range(1, len(numbered) + 1)), [d.sequence for d in numbered], doc_type)
            issues = [d.issue for d in numbered]
            self.assertEqual(issues, sorted(issues), f"{doc_type}: čísla v pořadí vystavení")
        concepts = [d for d in self.records.docs if d.state == 10]
        self.assertTrue(all(d.number is None and d.sequence is None and d.issue.month == 10 for d in concepts))

    def test_payment_references_and_cash(self) -> None:
        issued = [d for d in self.records.docs if d.doc_type in ("invno", "invpo") and d.state == 40]
        self.assertTrue(all(d.payment_reference == d.number for d in issued), "VS = číslo dokladu (#125)")
        cash_paid = [d for d in issued if d.payment_method == "cash"]
        self.assertEqual(3, len(cash_paid))
        self.assertTrue(all(d.cash_desk == "POK" for d in cash_paid))
        payroll = [d for d in self.records.docs if d.doc_type == "cmnbkp"]
        self.assertEqual([str(d.sequence) for d in payroll], [d.payment_reference for d in payroll])
        received = [d for d in self.records.docs if d.doc_type == "invni"]
        self.assertTrue(all(d.payment_reference and d.payment_reference.isdigit() for d in received))

    def test_eur_and_reverse_charge(self) -> None:
        eur = [d for d in self.records.docs if d.currency == "EUR"]
        self.assertTrue(eur)
        for doc in eur:
            self.assertEqual(self.story.rates["EUR"][doc.issue.month - 10 if doc.issue.year == 2026 else 0], doc.rate)
            self.assertEqual("intracom", doc.vat_place)
            self.assertTrue(all(r.vat_code == "cz-202" and r.vat_pct == 0 for r in doc.rows))
            self.assertEqual(Decimal("0.00"), doc.total_vat)
        domestic = [d for d in self.records.docs if d.doc_type == "invno" and d.currency == "CZK"]
        self.assertTrue(all(r.vat_code == "cz-120" for d in domestic for r in d.rows))

    def test_totals_are_consistent(self) -> None:
        for doc in self.records.docs:
            if doc.recap:
                self.assertEqual(sum(l.base for l in doc.recap), doc.total_base, doc.key)
                self.assertEqual(sum(l.total for l in doc.recap) + doc.total_rounding, doc.total_amount, doc.key)
                for line in doc.recap:
                    rows = [r for r in doc.rows if r.vat_code == line.vat_code]
                    self.assertEqual(line.base, sum(r.vat_base for r in rows), doc.key)
                    self.assertEqual(line.tax, sum(r.vat_amount for r in rows), doc.key)
            if doc.doc_type == "cmnbkp":
                debit = sum(r.total_price for r in doc.rows if r.acc_side == "debit")
                credit = sum(r.total_price for r in doc.rows if r.acc_side == "credit")
                self.assertEqual(debit, credit, "MD = D")
                self.assertEqual(debit, doc.total_amount)
        prodejky = [d for d in self.records.docs if d.doc_type == "cashreg"]
        self.assertTrue(all(d.total_amount == d.total_amount.to_integral_value() for d in prodejky), "prodejky na koruny")

    def test_payment_events_model(self) -> None:
        events = payment_events(self.story, self.records.docs)
        kinds = Counter(e.kind for e in events)
        self.assertEqual(3, kinds["cash"])
        self.assertIn("late", kinds)
        self.assertIn("partial", kinds)
        self.assertIn("never", kinds)
        issued = [d for d in self.records.docs if d.doc_type in ("invno", "invpo")]
        self.assertEqual(len(issued), sum(kinds.values()), "událost per vydaný doklad (i koncept)")

    def test_registry_dates_relative_to_anchor(self) -> None:
        by_ref = {r.ref_number: r for r in self.records.registry}
        self.assertEqual(date(2026, 11, 1), by_ref["SML-2026-004"].valid_to)
        self.assertEqual(date(2026, 12, 31), by_ref["POJ-884512"].valid_to)
        self.assertEqual("SML-2026-001", by_ref["SML-2026-001"].kind_fields["contractNumber"])
        self.assertEqual("POJ-884512", by_ref["POJ-884512"].kind_fields["policyNumber"])
        self.assertTrue(all(r.html for r in self.records.registry))

    def test_other_mail_deadline(self) -> None:
        other = next(m for m in self.records.mails if m.primary_type == "other")
        self.assertEqual(date(2026, 11, 1), other.action_due)
        self.assertEqual("2026-10-18T10:40:00", other.received_at)
        self.assertIn("1. 11. 2026", other.ai_title)


class DeterminismTest(unittest.TestCase):
    def test_two_expansions_are_equal(self) -> None:
        _, first = records()
        _, second = records()
        self.assertEqual([signature(d) for d in first.docs], [signature(d) for d in second.docs])
        self.assertEqual([m.message_id for m in first.mails], [m.message_id for m in second.mails])
        self.assertEqual([m.html for m in first.mails], [m.html for m in second.mails])

    def test_anchor_shift_by_quarters_keeps_content_and_moves_dates(self) -> None:
        # Posun o násobek tří měsíců zachová počty čtvrtletních dokladů;
        # okno duben 2026 – leden 2027 navíc přechází přes fiskální rok.
        _, base = records()
        _, shifted = records(anchor=date(2027, 1, 20))
        self.assertEqual(Counter(signature(d) for d in base.docs), Counter(signature(d) for d in shifted.docs))
        base_dates = sorted(d.issue for d in base.docs)
        shifted_dates = sorted(d.issue for d in shifted.docs)
        deltas = {(s.year - b.year) * 12 + s.month - b.month for b, s in zip(base_dates, shifted_dates)}
        self.assertEqual({3}, deltas, "všechna data posunutá o tři měsíce")
        self.assertEqual(len(base.mails), len(shifted.mails))
        numbers_2027 = sorted(d.number for d in shifted.docs if d.doc_type == "invno" and d.issue.year == 2027 and d.number)
        self.assertEqual("1270001", numbers_2027[0], "čítač se přes přelom roku vrací na 1")
        numbers_2026 = [d.number for d in shifted.docs if d.doc_type == "invno" and d.issue.year == 2026 and d.number]
        self.assertTrue(all(n.startswith("126") for n in numbers_2026))

    def test_anchor_shift_by_a_year_changes_only_dates(self) -> None:
        _, base = records()
        _, shifted = records(anchor=date(2027, 10, 20))
        self.assertEqual([signature(d) for d in base.docs], [signature(d) for d in shifted.docs])
        self.assertEqual([d.number.replace("26", "27", 1) if d.number else None for d in base.docs],
                         [d.number for d in shifted.docs])


class CalcTest(unittest.TestCase):
    def test_header_method_reconciles_rows_to_recap(self) -> None:
        doc = DocRec(
            key="t", doc_type="invno", ordinal=1, issue=date(2026, 1, 1), accounting=date(2026, 1, 1), due=None,
            tax_point=None, vat_obligation=None, currency="CZK", rate=Decimal("1"), vat_mode="fromBase",
            vat_place="domestic", payment_method="bankTransfer", partner_key=None, self_party="supplier", state=40,
            rows=[
                RowRec(operation="sale.services", quantity=Decimal("1"), unit_price=Decimal("0.05"), vat_pct=21, vat_code="cz-120"),
                RowRec(operation="sale.services", quantity=Decimal("1"), unit_price=Decimal("0.05"), vat_pct=21, vat_code="cz-120"),
                RowRec(operation="sale.services", quantity=Decimal("1"), unit_price=Decimal("0.05"), vat_pct=21, vat_code="cz-120"),
            ],
        )
        calc_document(doc)
        # Σ cen 0.15 → daň ze součtu 0.03; po řádcích by vyšlo 3 × 0.01 = 0.03 (shoda),
        # ale se 4 řádky po 0.05 (0.20 → 0.04 vs. 4 × 0.01 = 0.04)… zvolme případ s rozdílem:
        doc.rows.append(RowRec(operation="sale.services", quantity=Decimal("1"), unit_price=Decimal("0.07"), vat_pct=21, vat_code="cz-120"))
        calc_document(doc)
        recap = doc.recap[0]
        self.assertEqual(Decimal("0.22"), recap.base)
        self.assertEqual(Decimal("0.05"), recap.tax)   # round(0.22 × 0.21 = 0.0462) — ze součtu
        self.assertEqual(recap.tax, sum(r.vat_amount for r in doc.rows), "řádky dorovnané na rekapitulaci")
        self.assertEqual(Decimal("0.27"), doc.total_amount)
        for row in doc.rows:
            self.assertEqual(row.vat_total, row.vat_base + row.vat_amount)

    def test_rounding_to_whole_currency(self) -> None:
        doc = DocRec(
            key="t", doc_type="cashreg", ordinal=1, issue=date(2026, 1, 4), accounting=date(2026, 1, 4), due=None,
            tax_point=None, vat_obligation=None, currency="CZK", rate=Decimal("1"), vat_mode="fromBase",
            vat_place="domestic", payment_method="cash", partner_key=None, self_party="supplier", state=40, rounding="czk",
            rows=[RowRec(operation="sale.goods", quantity=Decimal("3"), unit_price=Decimal("249"), vat_pct=21, vat_code="cz-120")],
        )
        calc_document(doc)
        self.assertEqual((Decimal("747.00"), Decimal("156.87"), Decimal("904"), Decimal("0.13")),
                         (doc.total_base, doc.total_vat, doc.total_amount, doc.total_rounding))


if __name__ == "__main__":
    unittest.main()
