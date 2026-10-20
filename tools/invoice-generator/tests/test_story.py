from __future__ import annotations

import re
import shutil
import tempfile
import unittest
from datetime import date
from decimal import Decimal
from pathlib import Path

from invoicegen.catalogs import load_catalogs
from invoicegen.jsonc import load_jsonc
from invoicegen.story import StoryError, identities_for_checks, load_story
from tests.fixtures import REPO_ROOT

STORY_DIR = REPO_ROOT / "demo" / "datasets" / "stories" / "it-servis"
CATALOGS = load_catalogs(REPO_ROOT)
RESERVED_DEMO = load_jsonc(REPO_ROOT / "demo" / "invoices" / "suppliers.jsonc")
RESERVED_CORPUS = load_jsonc(REPO_ROOT / "tests" / "corpus" / "cz" / "identities.jsonc")


def load(story_dir: Path = STORY_DIR, **kwargs):
    return load_story(story_dir, CATALOGS, reserved_demo=RESERVED_DEMO, reserved_corpus=RESERVED_CORPUS, **kwargs)


class StoryLoadTest(unittest.TestCase):
    def test_real_story_loads(self) -> None:
        story = load()
        self.assertEqual("it-servis", story.name)
        self.assertEqual(date(2026, 10, 20), story.anchor)
        self.assertEqual(date(2026, 1, 1), story.period.start)
        self.assertEqual(14, len(story.customers))
        self.assertEqual({"cs", "sk", "de"}, {c.language for c in story.customers.values()})
        self.assertEqual(3, sum(1 for c in story.customers.values() if c.currency == "EUR"))
        self.assertTrue(all(c.send_purposes == ("invoices",) for c in story.customers.values()))
        self.assertEqual("BU1", story.bank_account_code)
        self.assertEqual({"CZK": "BU1", "EUR": "BU2"}, story.bank_accounts, "účet per měnu (U1)")
        self.assertEqual("BU2", story.bank_account_for("EUR"))
        self.assertEqual("POK", story.cash_desk_code)
        self.assertEqual(("Nájem", "Pojištění", "Smlouvy"), story.binders)
        self.assertEqual(Decimal("24.47"), story.rates["EUR"][0])
        self.assertEqual("2026-01-01", story.setup["bank_accounts"]["rows"][0]["valid_from"], "@period.start doplněno")
        self.assertEqual(sorted(story.setup), sorted(["settings", "accounts", "bank_accounts", "cash_desks", "cost_centers",
                                                      "vat_registrations", "binders", "mailboxes"]))
        self.assertEqual("221200", story.setup["accounts"]["rows"][0]["number"], "analytika pro BU2 (fáze 2a)")
        self.assertEqual(169, story.bank["accounts"]["BU1"]["fee"]["amount"])
        self.assertEqual(["net", "insurance", "tax"], [r["amount"] for r in story.bank["payroll"]])
        self.assertEqual({"behaviour": "late", "days": 6}, story.suppliers["klicek"].payment)
        self.assertEqual({}, story.suppliers["mrak"].payment, "bez payment = včas")
        self.assertEqual("Zelený Terminál s.r.o.", story.own.name)

    def test_anchor_override_shifts_period(self) -> None:
        story = load(anchor=date(2027, 3, 15))
        self.assertEqual(date(2026, 6, 1), story.period.start)
        self.assertEqual("2026-06-01", story.setup["vat_registrations"]["rows"][0]["valid_from"])

    def test_identities_for_checks_shape(self) -> None:
        story_raw = load_jsonc(STORY_DIR / "story.jsonc")
        partners = load_jsonc(STORY_DIR / "partners.jsonc")
        ids = identities_for_checks(story_raw["own"], {**partners["customers"], **partners["suppliers"]}, partners["verifiedAt"])
        self.assertEqual({"verifiedAt", "profiles", "suppliers"}, set(ids))
        self.assertEqual(["own"], list(ids["profiles"]))
        self.assertNotIn("language", ids["profiles"]["own"])
        self.assertIn("vatPayer", ids["suppliers"]["kopretina"])
        self.assertNotIn("payment", ids["suppliers"]["kopretina"])


class StoryValidationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name) / "it-servis"
        shutil.copytree(STORY_DIR, self.dir, ignore=shutil.ignore_patterns("build-state.json"))

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def edit(self, name: str, old: str, new: str, count: int = 1) -> None:
        path = self.dir / name
        text = path.read_text(encoding="utf-8")
        self.assertIn(old, text)
        path.write_text(text.replace(old, new, count), encoding="utf-8")

    def assert_problem(self, fragment: str) -> None:
        with self.assertRaises(StoryError) as ctx:
            load(self.dir)
        self.assertIn(fragment, str(ctx.exception))

    def test_bad_ico_checksum(self) -> None:
        self.edit("partners.jsonc", '"companyId": "83715266"', '"companyId": "83715267"')
        self.assert_problem("kontrolní součet")

    def test_domain_outside_example(self) -> None:
        self.edit("partners.jsonc", "uctarna@kopretina-doprava.example", "uctarna@kopretina-doprava.cz")
        self.assert_problem("doména .example")

    def test_unknown_item_in_billing(self) -> None:
        self.edit("story.jsonc", '"item": "SPRAVA-IT", "price": 6500 }', '"item": "NEEXISTUJE", "price": 6500 }')
        self.assert_problem("není v items.jsonc")

    def test_concept_outside_anchor_month(self) -> None:
        self.edit("story.jsonc", '"month": 0,  "day": 7,  "customer": "usmevov"', '"month": -1,  "day": 7,  "customer": "usmevov"')
        self.assert_problem("koncept jen v měsíci kotvy")

    def test_payroll_must_balance(self) -> None:
        self.edit("story.jsonc", '"net": 70896', '"net": 70000')
        self.assert_problem("MD")

    def test_missing_rate_month(self) -> None:
        self.edit("story.jsonc", '"-9": 25.12, ', "")
        self.assert_problem("chybí kurz EUR")

    def test_offer_code_unknown(self) -> None:
        self.edit("story.jsonc", '"item": "518203"', '"item": "999999"')
        self.assert_problem("není kód nabídky")

    def test_registry_template_and_promoted_fields(self) -> None:
        self.edit("registry.jsonc", '"template": "contract"', '"template": "chybi"', 1)
        self.assert_problem("template musí být šablona")

    def test_fragile_name_order_is_rejected(self) -> None:
        self.edit("partners.jsonc", '"name": "Autodoprava Kopretina s.r.o."', '"name": "Cukrarna U Medvidka s.r.o."')
        self.assert_problem("diakritikou")

    def test_overlap_with_demo_identities(self) -> None:
        self.edit("partners.jsonc", '"name": "Serverovna Mrak s.r.o."', '"name": "Softaro s.r.o."')
        self.assert_problem("překrývá")

    def test_overlap_with_corpus_identities(self) -> None:
        self.edit("partners.jsonc", '"name": "Serverovna Mrak s.r.o."', '"name": "Dřevomat Haná s.r.o."')
        self.assert_problem("korpusu")

    def test_setup_bank_account_code_must_exist(self) -> None:
        self.edit("story.jsonc", '"EUR": "BU2"', '"EUR": "BU9"')
        self.assert_problem("není v setup/bank_accounts.jsonc")

    def test_bank_account_currency_must_match(self) -> None:
        self.edit("story.jsonc", '"EUR": "BU2"', '"EUR": "BU1"')
        self.assert_problem("účet BU1 je v měně CZK, ne EUR")

    def test_foreign_currency_account_opens_at_zero(self) -> None:
        self.edit("story.jsonc", '"BU2": { "openingBalance": 0 }', '"BU2": { "openingBalance": 100 }')
        self.assert_problem("začíná na nule")

    def test_payroll_counterparty_must_not_belong_to_anyone(self) -> None:
        self.edit("story.jsonc", '"1000000013/0800"', '"2440913574/0300"')
        self.assert_problem("patří osobě nebo vlastnímu účtu")

    def test_supplier_payment_behaviour_is_limited(self) -> None:
        self.edit("partners.jsonc", '"payment": { "behaviour": "late", "days": 6 }', '"payment": { "behaviour": "partial", "pct": 50 }')
        self.assert_problem("payment musí být {behaviour z onTime, late")

    def test_unmatched_needs_paying_customer_and_remainder_needs_partial(self) -> None:
        self.edit("partners.jsonc", '"payment": { "behaviour": "never" }',
                  '"payment": { "behaviour": "never", "unmatched": [ { "month": -1, "reference": "missing" } ], "remainderDays": 5 }')
        self.assert_problem("payment.unmatched")
        self.assert_problem("payment.remainderDays jen u partial")

    def test_all_problems_are_reported_together(self) -> None:
        self.edit("story.jsonc", '"net": 70896', '"net": 70000')
        self.edit("story.jsonc", '"item": "518203"', '"item": "999999"')
        with self.assertRaises(StoryError) as ctx:
            load(self.dir)
        self.assertTrue(len(re.findall(r"; ", str(ctx.exception))) >= 1)
        self.assertIn("MD", str(ctx.exception))
        self.assertIn("není kód nabídky", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
