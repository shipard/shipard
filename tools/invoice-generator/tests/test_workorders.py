"""Periodické zakázky (#131 fáze 2b, D32–D34): porty PeriodLabel /
DocTextTemplate / PeriodCalendar, zakázky z pravidel billing, faktury ve
tvaru InvoiceBuilder a úplná evidence období i při posunu kotvy."""

from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.expand import expand, expand_work_orders
from invoicegen.story import load_story
from invoicegen.timeline import add_months, period_for
from invoicegen.workorders import (
    MONTH_NAMES, date_start_for, doc_text, due_periods, invoice_from, period_bounds, period_label, rows_valid_at,
)
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"
CATALOGS = load_catalogs(REPO_ROOT)
TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "invoices" / "templates").glob("*.html")}
REGISTRY_TEMPLATES = {p.stem: p.read_text(encoding="utf-8") for p in (REPO_ROOT / "demo" / "datasets" / "templates").glob("*.html")}

# IntlDateFormatter `LLLL yyyy` (PHP 8.5, ICU 74.2) — PeriodLabel::monthName.
ICU_MONTHS = {
    "cs": "leden | únor | březen | duben | květen | červen | červenec | srpen | září | říjen | listopad | prosinec",
    "sk": "január | február | marec | apríl | máj | jún | júl | august | september | október | november | december",
    "de": "Januar | Februar | März | April | Mai | Juni | Juli | August | September | Oktober | November | Dezember",
    "en": "January | February | March | April | May | June | July | August | September | October | November | December",
}


def records(anchor: date | None = None):
    story = load_story(STORY_DIR, CATALOGS, anchor=anchor)
    return story, expand(story, CATALOGS, invoice_templates=TEMPLATES, registry_templates=REGISTRY_TEMPLATES)


class PeriodLabelTest(unittest.TestCase):
    def test_month_names_match_icu(self) -> None:
        for language, expected in ICU_MONTHS.items():
            labels = [period_label(date(2026, m, 1), "month", language, CATALOGS.period_texts) for m in range(1, 13)]
            self.assertEqual(expected.split(" | "), [label.removesuffix(" 2026") for label in labels], language)
            self.assertTrue(all(label.endswith(" 2026") for label in labels))
        self.assertEqual(tuple(MONTH_NAMES), ("cs", "sk", "de", "en"))
        self.assertEqual("January 2026", period_label(date(2026, 1, 1), "month", "xx", CATALOGS.period_texts), "neznámý jazyk anglicky")

    def test_quarter_and_halfyear_texts_from_catalog(self) -> None:
        self.assertEqual("1. čtvrtletí 2026", period_label(date(2026, 1, 1), "quarter", "cs", CATALOGS.period_texts))
        self.assertEqual("4. štvrťrok 2026", period_label(date(2026, 10, 1), "quarter", "sk", CATALOGS.period_texts))
        self.assertEqual("2. Quartal 2026", period_label(date(2026, 4, 1), "quarter", "de", CATALOGS.period_texts))
        self.assertEqual("Q3 2026", period_label(date(2026, 7, 1), "quarter", "en", CATALOGS.period_texts))
        self.assertEqual("2. pololetí 2026", period_label(date(2026, 7, 1), "halfyear", "cs", CATALOGS.period_texts))
        self.assertEqual("H1 2026", period_label(date(2026, 1, 1), "halfyear", "en", {}), "bez katalogu anglický fallback")
        self.assertEqual("2026", period_label(date(2026, 1, 1), "year", "cs", CATALOGS.period_texts))

    def test_doc_text_template(self) -> None:
        self.assertEqual("Správa IT — paušál leden 2026", doc_text("Správa IT — paušál {období}", "Správa IT", "leden 2026"))
        self.assertEqual("Flat fee January 2026", doc_text("Flat fee {period}", "x", "January 2026"))
        self.assertEqual("Správa IT leden 2026", doc_text(None, "Správa IT", "leden 2026"), "prázdná šablona = název + štítek")
        self.assertEqual(200, len(doc_text("x" * 250, "t", "l")), "ořez na délku sloupce")


class PeriodCalendarTest(unittest.TestCase):
    def test_bounds(self) -> None:
        self.assertEqual((date(2026, 2, 1), date(2026, 2, 28)), period_bounds(date(2026, 2, 14), "month"))
        self.assertEqual((date(2026, 4, 1), date(2026, 6, 30)), period_bounds(date(2026, 5, 1), "quarter"))
        self.assertEqual((date(2026, 7, 1), date(2026, 12, 31)), period_bounds(date(2026, 11, 30), "halfyear"))
        self.assertEqual((date(2026, 1, 1), date(2026, 12, 31)), period_bounds(date(2026, 6, 6), "year"))

    def test_due_periods_until_run_date(self) -> None:
        due = due_periods(date(2026, 1, 1), date(2026, 3, 20), "month")
        self.assertEqual([date(2026, 1, 1), date(2026, 2, 1), date(2026, 3, 1)], [start for start, _ in due])
        self.assertEqual([date(2026, 1, 1), date(2026, 4, 1), date(2026, 7, 1), date(2026, 10, 1)],
                         [s for s, _ in due_periods(date(2026, 1, 1), date(2026, 10, 20), "quarter")])
        self.assertEqual([], due_periods(date(2026, 11, 1), date(2026, 10, 20), "month"), "období po datu běhu")
        self.assertEqual(2, len(due_periods(date(2026, 1, 1), date(2026, 10, 20), "month", date_end=date(2026, 2, 10))),
                         "období přes ukončení se vystaví celé, další už ne")

    def test_invoice_from_and_start(self) -> None:
        default = period_for(date(2026, 10, 20), 9)
        self.assertEqual(date(2026, 1, 1), invoice_from(default, "month"))
        self.assertEqual(date(2026, 1, 1), invoice_from(default, "quarter"))
        shifted = period_for(date(2026, 11, 20), 9)
        self.assertEqual(date(2026, 2, 1), invoice_from(shifted, "month"))
        self.assertEqual(date(2026, 4, 1), invoice_from(shifted, "quarter"), "první čtvrtletí v okně")
        self.assertEqual(date(2024, 4, 1), date_start_for(default, -30, "month"))
        self.assertEqual(date(2024, 1, 1), date_start_for(default, -33, "quarter"))
        self.assertEqual(date(2024, 1, 1), date_start_for(shifted, -33, "quarter"), "zarovnání na začátek čtvrtletí")

    def test_rows_valid_at(self) -> None:
        story, _ = records()
        jelen = next(wo for wo in expand_work_orders(story) if wo.customer_key == "jelen")
        self.assertEqual(2, len(jelen.rows), "změna ceny = dva řádky s platností")
        old, new = jelen.rows
        self.assertEqual((Decimal("25000"), None, date(2026, 5, 31)), (old.unit_price, old.valid_from, old.valid_to))
        self.assertEqual((Decimal("27500"), date(2026, 6, 1), None), (new.unit_price, new.valid_from, new.valid_to))
        self.assertEqual([old], rows_valid_at(jelen.rows, date(2026, 5, 1)))
        self.assertEqual([new], rows_valid_at(jelen.rows, date(2026, 6, 1)))


class WorkOrdersExpandTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.story, cls.records = records()

    def test_periodic_work_orders_from_billing(self) -> None:
        work_orders = [wo for wo in self.records.work_orders if wo.periodicity is not None]
        self.assertEqual(13, len(work_orders), "10 měsíčních + 3 čtvrtletní; zálohová faktura mimo zakázky")
        self.assertEqual(15, len(self.records.work_orders), "+ dva projekty (D36)")
        self.assertEqual([f"S{i:04d}" for i in range(1, 14)], [wo.number for wo in work_orders])
        self.assertEqual(list(range(1, 14)), [wo.sequence for wo in work_orders])
        self.assertTrue(all(wo.series_code == "S" and wo.state == "confirmed" and wo.date_end is None for wo in work_orders))
        self.assertTrue(all(wo.date_start < self.story.period.start for wo in work_orders), "zahájení před oknem (D34)")
        self.assertTrue(all(wo.invoice_from == date(2026, 1, 1) for wo in work_orders), "fakturovat od = začátek okna")
        self.assertEqual({"month": 10, "quarter": 3}, {p: sum(1 for wo in work_orders if wo.periodicity == p) for p in ("month", "quarter")})
        kopretina = work_orders[0]
        self.assertEqual("Správa IT — Autodoprava Kopretina s.r.o.", kopretina.title)
        self.assertEqual(("kopretina", "CZK", None), (kopretina.customer_key, kopretina.currency, kopretina.bank_account))
        self.assertEqual("Správa IT — paušál {období}", kopretina.doc_text)
        row = kopretina.rows[0]
        self.assertEqual(("SPRAVA-IT", "Správa IT — měsíční paušál", Decimal("1"), "mnth", Decimal("16000"), "cz-120"),
                         (row.item_code, row.description, row.quantity, row.unit, row.unit_price, row.vat_code))
        quarterly = next(wo for wo in work_orders if wo.customer_key == "pohybna")
        self.assertEqual((Decimal("3"), date(2024, 1, 1)), (quarterly.rows[0].quantity, quarterly.date_start))
        nitra = next(wo for wo in work_orders if wo.customer_key == "nitra")
        self.assertEqual(("EUR", "BU2", "cz-202", "Správa IT — mesačný paušál"),
                         (nitra.currency, nitra.bank_account, nitra.rows[0].vat_code, nitra.rows[0].description))
        wolkenschiff = next(wo for wo in work_orders if wo.customer_key == "wolkenschiff")
        self.assertEqual("IT-Betreuung — Pauschale {období}", wolkenschiff.doc_text)

    def test_invoices_look_like_invoice_builder_output(self) -> None:
        by_key = {d.key: d for d in self.records.docs}
        january = by_key["billing:0:-9"]
        self.assertEqual("Správa IT — paušál leden 2026", january.doc_text)
        self.assertEqual((date(2026, 1, 1), date(2026, 1, 31)), (january.period_from, january.period_to))
        self.assertEqual(("workOrder", "S0001"), (january.source_kind, january.work_order))
        self.assertEqual(january.issue, january.tax_point)
        self.assertEqual("Správa IT — měsíční paušál", january.rows[0].description)
        self.assertEqual(Decimal("16000"), january.rows[0].unit_price)
        quarter = by_key["billing:3:-6"]
        self.assertEqual("Správa IT — paušál 2. čtvrtletí 2026", quarter.doc_text)
        self.assertEqual((date(2026, 4, 1), date(2026, 6, 30)), (quarter.period_from, quarter.period_to))
        self.assertEqual("Správa IT — paušál január 2026", by_key["billing:11:-9"].doc_text, "slovenský odběratel")
        self.assertEqual("IT-Betreuung — Pauschale Januar 2026", by_key["billing:13:-9"].doc_text, "německý odběratel")
        self.assertEqual("Správa IT — paušál 1. štvrťrok 2026", by_key["billing:12:-9"].doc_text)
        jelen_june = by_key["billing:5:-4"]
        self.assertEqual(Decimal("27500"), jelen_june.rows[0].unit_price, "řádek předpisu platný od června")
        self.assertEqual(Decimal("25000"), by_key["billing:5:-5"].rows[0].unit_price)
        proforma = by_key["billing:6:-8"]
        self.assertEqual(("invpo", None, None, None), (proforma.doc_type, proforma.doc_text, proforma.source_kind, proforma.work_order))
        adhoc = [d for d in self.records.docs if d.key.startswith("adhoc:")]
        self.assertTrue(all(d.source_kind is None and d.doc_text is None for d in adhoc), "jednorázové faktury nejsou z periodické fakturace")

    def test_periods_cover_every_due_period(self) -> None:
        for anchor in (None, date(2026, 11, 20), date(2026, 12, 20), date(2027, 1, 20), date(2027, 10, 20)):
            story, recs = records(anchor)
            by_key = {d.key: d for d in recs.docs}
            for wo in recs.work_orders:
                if wo.periodicity is None:
                    self.assertEqual(([], []), (wo.rows, wo.periods), f"{wo.number}: projekt bez předpisu a období")
                    continue
                expected = due_periods(wo.invoice_from, story.anchor, wo.periodicity)
                self.assertEqual(expected, [(p.start, p.end) for p in wo.periods], f"{anchor}: {wo.number} evidence úplná")
                self.assertTrue(all(by_key[p.doc_key].work_order == wo.number for p in wo.periods))
                self.assertTrue(all(rows_valid_at(wo.rows, by_key[p.doc_key].issue) for p in wo.periods), "každé období má platný řádek")
            concept = next(d for d in recs.docs if d.key == "billing:1:0")
            self.assertEqual(10, concept.state, "koncept v měsíci kotvy je vystavené období s dokladem v Konceptu")
            self.assertIn(concept.key, [p.doc_key for p in recs.work_orders[1].periods])

    def test_quarterly_work_orders_align_with_shifted_anchor(self) -> None:
        _, recs = records(date(2026, 11, 20))
        quarterly = [wo for wo in recs.work_orders if wo.periodicity == "quarter"]
        self.assertEqual({date(2026, 4, 1)}, {wo.invoice_from for wo in quarterly}, "okno únor–listopad: první čtvrtletí v okně od dubna")
        self.assertEqual({date(2026, 2, 1)}, {wo.invoice_from for wo in recs.work_orders if wo.periodicity == "month"})
        self.assertTrue(all(wo.date_start.month in (1, 4, 7, 10) for wo in quarterly))
        self.assertEqual([date(2026, 4, 1), date(2026, 7, 1), date(2026, 10, 1)], [p.start for p in quarterly[0].periods])


class ProjectsTest(unittest.TestCase):
    """Projekty (D36): zakázky Ukončeno s dimenzí na fakturách a na řádcích nákupu."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.story, cls.records = records()
        cls.by_key = {d.key: d for d in cls.records.docs}

    def test_project_work_orders(self) -> None:
        projects = [wo for wo in self.records.work_orders if wo.key.startswith("project:")]
        self.assertEqual(["P0001", "P0002"], [wo.number for wo in projects])
        self.assertEqual([1, 2], [wo.sequence for wo in projects])
        self.assertTrue(all(wo.series_code == "P" and wo.state == "finished" and wo.periodicity is None for wo in projects))
        network, wifi = projects
        self.assertEqual(("Výměna sítě", "galvanovna", "CZK", date(2026, 3, 17), date(2026, 4, 22)),
                         (network.title, network.customer_key, network.currency, network.date_start, network.date_end),
                         "zahájení = první faktura − 3 dny, ukončení = dodávka")
        self.assertEqual(("Wi-Fi síť hotelu", "jelen", date(2026, 5, 17), date(2026, 6, 18)),
                         (wifi.title, wifi.customer_key, wifi.date_start, wifi.date_end))

    def test_project_invoices_carry_the_dimension(self) -> None:
        for key, number in (("adhoc:4", "P0001"), ("adhoc:5", "P0001"), ("adhoc:10", "P0002"), ("adhoc:11", "P0002")):
            self.assertEqual(number, self.by_key[key].work_order, key)
            self.assertTrue(all(r.work_order is None for r in self.by_key[key].rows), "dimenze jen na hlavičce, deník ji dědí")
        others = [d for d in self.records.docs if d.key.startswith("adhoc:") and d.key not in ("adhoc:4", "adhoc:5", "adhoc:10", "adhoc:11")]
        self.assertTrue(all(d.work_order is None for d in others))
        self.assertEqual(date(2026, 6, 18), self.by_key["adhoc:11"].issue, "dodávka Wi-Fi až po nákupu 15. 6.")
        self.assertEqual("Průzkum lokality a návrh Wi-Fi sítě", self.by_key["adhoc:10"].rows[0].description, "text řádku z příběhu")

    def test_purchases_follow_sold_quantities(self) -> None:
        april = self.by_key["received:cipdeska:-6"]
        by_text = {r.description: r for r in april.rows}
        self.assertEqual(("2", "6400", "P0001"), (str(by_text["Síťový switch 24 portů, PoE"].quantity),
                                                   str(by_text["Síťový switch 24 portů, PoE"].unit_price),
                                                   by_text["Síťový switch 24 portů, PoE"].work_order),
                         "běžný řádek nahrazen projektovým: množství = prodané kusy")
        self.assertEqual(("1", "4490", "P0001", "501100"), (str(by_text["Router Wi-Fi 6, 4× GbE"].quantity),
                                                            str(by_text["Router Wi-Fi 6, 4× GbE"].unit_price),
                                                            by_text["Router Wi-Fi 6, 4× GbE"].work_order,
                                                            by_text["Router Wi-Fi 6, 4× GbE"].item_code), "router přibyl")
        self.assertEqual([r.description for r in april.rows][:5],
                         ['Notebook 14" business, 16 GB RAM', "Síťový switch 24 portů, PoE", 'Monitor 27" IPS',
                          "Přístupový bod Wi-Fi 6", "Kabel HDMI 2.1, 2 m"], "nahrazení na místě, ostatní řádky beze změny")
        self.assertEqual({None, "P0001"}, {r.work_order for r in april.rows})
        self.assertEqual(2, sum(1 for r in april.rows if r.work_order == "P0001"), "jen switch a router nesou dimenzi")
        june = self.by_key["received:cipdeska:-4"]
        ap = next(r for r in june.rows if r.description == "Přístupový bod Wi-Fi 6")
        self.assertEqual(("4", "P0002"), (str(ap.quantity), ap.work_order))
        self.assertEqual(5, len(june.rows), "bez přidaných řádků")
        self.assertTrue(all(r.work_order is None for r in june.rows if r is not ap))
        for key in ("received:cipdeska:-3", "received:cipdeska:-1"):
            self.assertTrue(all(r.work_order is None for r in self.by_key[key].rows), f"{key}: jiné měsíce beze změny")
            self.assertEqual("1", str(next(r for r in self.by_key[key].rows if r.description == "Síťový switch 24 portů, PoE").quantity))
        self.assertLess(april.issue, self.by_key["adhoc:5"].issue, "nákup před dodávkou")
        self.assertLess(june.issue, self.by_key["adhoc:11"].issue)


if __name__ == "__main__":
    unittest.main()
