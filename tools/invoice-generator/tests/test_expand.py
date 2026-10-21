from __future__ import annotations

import unittest
from collections import Counter
from dataclasses import replace
from datetime import date
from decimal import Decimal

from invoicegen.catalogs import load_catalogs
from invoicegen.doccalc import calc_document
from invoicegen.expand import DOC_ID_CODES, ExpandError, expand, foreign_unit_price, format_number, payment_events
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
        self.assertEqual(135, by_type[("invno", 40)], "paušály (ukončený do července, nový od května — fáze 2d) + jednorázové faktury")
        self.assertEqual(4, by_type[("invno", 10)])
        self.assertEqual(1, by_type[("invpo", 40)])
        self.assertEqual(59, by_type[("invni", 40)], "48 tuzemských + nákup serveru (fáze 2c) + 10 od dodavatele v EUR (D28)")
        self.assertEqual(39, by_type[("cashreg", 40)])
        self.assertEqual(20, by_type[("cash", 40)])
        self.assertEqual(37, by_type[("cmnbkp", 40)], "9 mezd + otevírací doklad + 27 vyrovnávacích (fáze 2a)")
        self.assertEqual(26, len(self.records.persons))
        self.assertEqual(["F%05d" % i for i in range(1, 27)], [p.person_id for p in self.records.persons])
        self.assertEqual(63, len(self.records.mails))
        self.assertEqual(59, sum(1 for m in self.records.mails if m.doc_state == 40))
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
            self.assertEqual(Decimal("0.00"), doc.total_vat)
            if doc.doc_type in ("invno", "invpo"):
                self.assertTrue(all(r.vat_code == "cz-202" and r.vat_pct == 0 for r in doc.rows))
                self.assertEqual(len(doc.rows) and 1, len({l.vat_code for l in doc.recap}))
            else:
                # Přijetí služby z EU (D28): samovyměření cz-217 / 21 %, daň jen
                # informativně, celkem = základ, pár cz-207 v rekapitulaci.
                self.assertEqual("invni", doc.doc_type)
                self.assertTrue(all(r.vat_code == "cz-217" and r.vat_pct == 21 and r.supply_kind == "services" for r in doc.rows))
                for row in doc.rows:
                    self.assertEqual((row.total_price * Decimal("0.21")).quantize(Decimal("0.01")), row.vat_amount)
                    self.assertEqual(row.total_price, row.vat_total)
                self.assertEqual(["cz-217", "cz-207"], [l.vat_code for l in doc.recap])
                primary, pair = doc.recap
                self.assertFalse(primary.is_reverse_pair)
                self.assertTrue(pair.is_reverse_pair)
                self.assertEqual((primary.base, primary.base), (primary.total, doc.total_amount))
                self.assertEqual(primary.tax, sum(r.vat_amount for r in doc.rows))
                self.assertEqual((primary.base, primary.tax, primary.base + primary.tax), (pair.base, pair.tax, pair.total))
                self.assertEqual(doc.total_base, doc.total_amount)
        domestic = [d for d in self.records.docs if d.doc_type == "invno" and d.currency == "CZK"]
        self.assertTrue(all(r.vat_code == "cz-120" for d in domestic for r in d.rows))

    def test_eu_supplier_mail_in_english_with_reverse_charge_paper(self) -> None:
        docs = [d for d in self.records.docs if d.doc_type == "invni" and d.currency == "EUR"]
        self.assertTrue(docs)
        supplier = self.story.suppliers[docs[0].partner_key]
        self.assertEqual(("en", "en", "reverseCharge"), (supplier.language, supplier.template, supplier.vat_treatment))
        for doc in docs:
            mail = doc.mail
            self.assertIsNotNone(mail)
            self.assertEqual(f"Invoice {doc.supplier_doc_number}", mail.subject)
            self.assertIn("please find attached invoice", mail.body)
            data = mail.canonical_data
            self.assertEqual(("EUR", "reverseCharge"), (data["currency"], data["vatTreatment"]))
            self.assertTrue(all(r["vatPct"] == 0 and r["supplyKind"] == "services" and r["unit"] == "pcs" for r in data["rows"]),
                            "papír: 0 % + druh plnění, jednotka anglicky")
            self.assertEqual(doc.total_amount, mail.computed["totalAmount"])
            self.assertEqual(Decimal("0.00"), mail.computed["totalVat"])
            self.assertIn("Reverse charge", mail.html)
            self.assertIn("Netherlands", mail.html)
            self.assertIn("Czech Republic", mail.html)
            self.assertIn("EUR", mail.html)
            self.assertNotIn("Kč", mail.html)
            self.assertTrue(mail.ai_title.endswith("EUR"))
            self.assertIn(mail.content_tag, ("it.software", "it.hosting"))
        czech = next(d.mail for d in self.records.docs if d.doc_type == "invni" and d.currency == "CZK")
        self.assertTrue(czech.subject.startswith("Faktura "))
        self.assertEqual("CZK", czech.canonical_data["currency"])
        self.assertNotIn("supplyKind", czech.canonical_data["rows"][0])

    def test_catalog_prices_on_eur_invoices_follow_the_rate(self) -> None:
        """D27: cena z katalogu (CZK) ÷ kurz měsíce vystavení na celé centy;
        vlastní `price` pravidla je cena v měně faktury."""
        items = self.story.items
        rates = self.story.rates["EUR"]
        by_key = {d.key: d for d in self.records.docs}
        monitors = next(d for d in self.records.docs
                        if d.currency == "EUR" and any(r.item_code == "MON-27" for r in d.rows))
        offset = monitors.issue.month - self.story.anchor.month
        row = next(r for r in monitors.rows if r.item_code == "MON-27")
        self.assertEqual(foreign_unit_price(items["MON-27"].price, rates[offset]), row.unit_price)
        self.assertEqual((items["MON-27"].price / rates[offset]).quantize(Decimal("0.01")), row.unit_price)
        self.assertLess(row.unit_price, Decimal("400"), "monitor za stovky EUR, ne za tisíce")
        hours = [r for d in self.records.docs if d.currency == "EUR" and d.key.startswith("adhoc:")
                 for r in d.rows if r.item_code == "PRACE-H"]
        self.assertTrue(hours)
        for doc in (d for d in self.records.docs if d.currency == "EUR" and d.key.startswith("adhoc:")):
            for r in doc.rows:
                self.assertEqual(foreign_unit_price(items[r.item_code].price, doc.rate), r.unit_price, doc.key)
        for index, rule in enumerate(self.story.billing):
            customer = self.story.customers[rule["customer"]]
            if customer.currency != "EUR":
                continue
            doc = by_key[f"billing:{index}:0"]
            self.assertEqual(Decimal(rule["price"]), doc.rows[0].unit_price, "vlastní cena v EUR beze změny")
        self.assertEqual(Decimal("0.13"), foreign_unit_price(Decimal("1"), Decimal("8")), "ROUND_HALF_UP")

    def test_foreign_currency_without_rate_is_an_error(self) -> None:
        with self.assertRaises(ExpandError) as ctx:
            expand(replace(self.story, rates={}), CATALOGS, invoice_templates=TEMPLATES,
                   registry_templates=REGISTRY_TEMPLATES)
        self.assertIn("nemá kurz", str(ctx.exception))

    def test_totals_are_consistent(self) -> None:
        for doc in self.records.docs:
            if doc.recap:
                # Párový řádek samovyměření (cz-207) do součtů hlavičky nevstupuje.
                primary = [l for l in doc.recap if not l.is_reverse_pair]
                self.assertEqual(sum(l.base for l in primary), doc.total_base, doc.key)
                self.assertEqual(sum(l.total for l in primary) + doc.total_rounding, doc.total_amount, doc.key)
                for line in primary:
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
        invoices = [d for d in self.records.docs if d.doc_type in ("invno", "invpo", "invni")]
        self.assertEqual(len(invoices) + kinds["remainder"], sum(kinds.values()),
                         "událost per fakturu (i koncept, i přijatou) + doplatky částečných úhrad")

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

    def test_self_assessed_eu_services(self) -> None:
        """`DocDocument` u cz-217 (noPayTax + reverseVatCode): řádek nese daň
        informativně, celkem = základ; rekapitulace primární řádek + pár
        cz-207 (`is_reverse_pair`); součty hlavičky základ / 0 / základ."""
        doc = DocRec(
            key="t", doc_type="invni", ordinal=1, issue=date(2026, 1, 5), accounting=date(2026, 1, 5), due=None,
            tax_point=None, vat_obligation=None, currency="EUR", rate=Decimal("25.12"), vat_mode="fromBase",
            vat_place="intracom", payment_method="bankTransfer", partner_key=None, self_party="customer", state=40,
            rows=[
                RowRec(operation="acc.entry", quantity=Decimal("1"), unit_price=Decimal("149"), vat_pct=21, vat_code="cz-217"),
                RowRec(operation="acc.entry", quantity=Decimal("1"), unit_price=Decimal("39"), vat_pct=21, vat_code="cz-217"),
            ],
        )
        calc_document(doc)
        self.assertEqual([(Decimal("149.00"), Decimal("31.29"), Decimal("149.00")), (Decimal("39.00"), Decimal("8.19"), Decimal("39.00"))],
                         [(r.vat_base, r.vat_amount, r.vat_total) for r in doc.rows])
        self.assertEqual([("cz-217", Decimal("188.00"), Decimal("39.48"), Decimal("188.00"), False),
                          ("cz-207", Decimal("188.00"), Decimal("39.48"), Decimal("227.48"), True)],
                         [(l.vat_code, l.base, l.tax, l.total, l.is_reverse_pair) for l in doc.recap])
        self.assertEqual((Decimal("188.00"), Decimal("0.00"), Decimal("188.00")), (doc.total_base, doc.total_vat, doc.total_amount))

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


class AssetsExpandTest(unittest.TestCase):
    """Majetek (#131 fáze 2c, D41): server z vlastní faktury velkoobchodu
    s řádkem pořízení, zařazení pár dní po dodání, hosting s kartou na
    řádcích od první faktury po zařazení, plán odpisů jen pro souhrn."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.story, cls.records = records()
        cls.by_key = {d.key: d for d in cls.records.docs}

    def test_asset_card_from_story(self) -> None:
        self.assertEqual(1, len(self.records.assets))
        asset = self.records.assets[0]
        self.assertEqual(("asset:server", "MA0001", "Výpočetní technika", "022", "042100"),
                         (asset.key, asset.number, asset.type_name, asset.accounting_group, asset.acquisition_account),
                         "typ a skupina ze setup/, účet pořízení z účetní skupiny")
        self.assertEqual(("straight", "cz-1", "as_tax", "tangible", "single"),
                         (asset.tax_method, asset.tax_rule, asset.acc_method, asset.category, asset.tracking))
        self.assertEqual((date(2026, 2, 14), Decimal("150000"), "received:cipdeska:-8"),
                         (asset.activation, asset.amount, asset.purchase_doc_key), "zařazení 4 dny po dodání 10. 2.")
        self.assertEqual([(date(2026, m, 1), Decimal("3000")) for m in range(3, 11)], list(asset.acc_plan),
                         "měsíční účetní odpisy v okně od března: 150 000 × 20 % / 10 měsíců")

    def test_purchase_invoice_is_separate_with_asset_row(self) -> None:
        doc = self.by_key["received:cipdeska:-8"]
        self.assertEqual((date(2026, 2, 10), date(2026, 2, 20), 40, "cipdeska", "FV-2026-2210"),
                         (doc.issue, doc.due, doc.state, doc.partner_key, doc.supplier_doc_number),
                         "samostatná faktura v měsíci mimo pravidlo, první číslo dodavatele v roce")
        self.assertEqual("FV-2026-2211", self.by_key["received:cipdeska:-6"].supplier_doc_number, "běžné faktury se posunuly o jedna")
        server, kit = doc.rows
        self.assertEqual(("purchase.asset", None, "042100", "MA0001", Decimal("1"), Decimal("150000"), "cz-110"),
                         (server.operation, server.item_code, server.account, server.asset, server.quantity, server.unit_price, server.vat_code),
                         "řádek pořízení: účet přímo, karta na řádku, bez položky")
        self.assertEqual(self.records.assets[0].name, server.description, "text řádku = název karty")
        self.assertEqual(("acc.entry", "501100", None, None, Decimal("1290"), "Montážní sada do racku, lišty"),
                         (kit.operation, kit.item_code, kit.account, kit.asset, kit.unit_price, kit.description), "běžný nákup na téže faktuře")
        self.assertEqual((Decimal("151290.00"), Decimal("183060.90")), (doc.total_base, doc.total_amount))
        self.assertIsNotNone(doc.mail, "zpracovaná zpráva s PDF jako u ostatních přijatých faktur")
        self.assertEqual("MSG-DEMO-CIPDES-2602", doc.mail.message_id)
        self.assertEqual([r.description for r in doc.rows], [r["text"] for r in doc.mail.canonical_data["rows"]])
        events = [e for e in payment_events(self.story, self.records.docs) if e.doc_key == doc.key]
        self.assertEqual([("onTime", date(2026, 2, 19), doc.total_amount, 2)],
                         [(e.kind, e.paid_on, e.amount, e.direction) for e in events], "úhrada podle chování dodavatele")
        self.assertTrue(all(not any(r.operation == "purchase.asset" for r in d.rows)
                            for d in self.records.docs if d.key != doc.key), "jediný řádek pořízení v sadě")

    def test_hosting_rows_carry_the_card_from_the_first_invoice_after_activation(self) -> None:
        february = self.by_key["received:mrak:-8"]
        self.assertLess(february.issue, self.records.assets[0].activation)
        self.assertTrue(all(r.asset is None for r in february.rows), "faktura před zařazením bez karty")
        for offset in range(-7, 1):
            doc = self.by_key[f"received:mrak:{offset}"]
            self.assertTrue(all(r.asset == "MA0001" for r in doc.rows), f"{doc.key}: hosting s kartou na řádku")
            self.assertIsNone(doc.work_order)
        others = [d for d in self.records.docs if d.doc_type == "invni" and not d.key.startswith(("received:mrak:", "received:cipdeska:-8"))]
        self.assertTrue(all(r.asset is None for d in others for r in d.rows), "ostatní dodavatelé bez karty")

    def test_anchor_shift_moves_purchase_activation_and_plan(self) -> None:
        story, recs = records(date(2027, 1, 20))
        asset = recs.assets[0]
        doc = next(d for d in recs.docs if d.key == asset.purchase_doc_key)
        self.assertEqual((date(2026, 5, 10), date(2026, 5, 14)), (doc.issue, asset.activation))
        months = [m for m, _ in asset.acc_plan]
        self.assertEqual([date(2026, m, 1) for m in range(6, 13)] + [date(2027, 1, 1)], months, "od měsíce po zařazení do měsíce kotvy")
        self.assertEqual(Decimal("30000"), sum(a for m, a in asset.acc_plan if m.year == 2026), "roční odpis 1. roku celý v roce zařazení")
        self.assertEqual(Decimal("5000"), dict(asset.acc_plan)[date(2027, 1, 1)], "2. rok 40 % / 12")
        self.assertTrue(all(r.asset == "MA0001" for d in recs.docs if d.key.startswith("received:mrak:") and d.issue >= asset.activation for r in d.rows))


if __name__ == "__main__":
    unittest.main()
