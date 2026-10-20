from __future__ import annotations

import copy
import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from invoicegen.expected import dumps_expected
from invoicegen.jsonc import load_jsonc
from invoicegen.rates import (
    Rate, RatesError, accounting_date, fetch_missing, load_lists, needed_dates, per_unit, rate_for, reserve_for,
    select_lists, validate_list,
)
from tests.fixtures import DATA_DOMESTIC, DATA_EU_SERVICES, DATA_NO_VAT, IDENTITIES, RATE_LISTS, REPO_ROOT, data, rate_list

D = Decimal
CORPUS_DIR = REPO_ROOT / "tests" / "corpus" / "cz"
RATES_DIR = CORPUS_DIR / "exchange-rates"

# Týden 2026-09-21 (po) … 2026-09-25 (pá); 2026-09-28 (po) je svátek bez lístku.
WEEK = [
    rate_list("2026-09-21", "26182", EUR="24.33", USD="20.90"),
    rate_list("2026-09-22", "26183", EUR="24.345", USD="20.91"),
    rate_list("2026-09-25", "26186", EUR="24.40", USD="20.95", JPY="14.01"),
]


class AccountingDateTest(unittest.TestCase):
    def test_tax_point_date_else_issue(self) -> None:
        self.assertEqual("2026-08-31", accounting_date(DATA_DOMESTIC))
        self.assertEqual("2026-09-04", accounting_date(DATA_NO_VAT), "bez DUZP datum vystavení")

    def test_needed_dates_only_foreign_currency_cells(self) -> None:
        docs = {
            "0101-domestic-goods": data(DATA_DOMESTIC),
            "0501-eu-services": data(DATA_EU_SERVICES),
            "0801-third-country-services": data(DATA_NO_VAT, currency="USD", dates={"issue": "2026-09-01", "tax": None, "due": "2026-09-01"}),
            "0502-eu-services": data(DATA_EU_SERVICES, dates={"issue": "2026-10-01", "tax": "2026-09-30", "due": "2026-10-15"}),
        }
        self.assertEqual({"2026-08-31": ("EUR",), "2026-09-01": ("USD",), "2026-09-30": ("EUR",)}, needed_dates(docs, IDENTITIES))
        self.assertEqual({}, needed_dates({"0101-domestic-goods": data(DATA_DOMESTIC)}, IDENTITIES))


class RateForTest(unittest.TestCase):
    def test_per_unit_rounds_half_up_to_six_places(self) -> None:
        self.assertEqual(D("0.139800"), per_unit("13.98", 100))
        self.assertEqual(D("24.125000"), per_unit(D("24.125"), 1))
        self.assertEqual(D("0.001175"), per_unit("1.175", 1000))
        self.assertEqual(D("0.000001"), per_unit("0.0000005", 1), "ROUND_HALF_UP")

    def test_working_day_takes_its_own_list(self) -> None:
        rate = rate_for(WEEK, "EUR", "2026-09-22")
        self.assertEqual(Rate(D("24.345000"), "cz.cnb.daily", "2026-09-22", "26183"), rate)

    def test_weekend_and_holiday_take_the_last_list(self) -> None:
        self.assertEqual("2026-09-25", rate_for(WEEK, "EUR", "2026-09-26").valid_from, "sobota → páteční lístek")
        self.assertEqual("2026-09-25", rate_for(WEEK, "EUR", "2026-09-28").valid_from, "svátek → páteční lístek")
        self.assertEqual(D("24.400000"), rate_for(WEEK, "EUR", "2026-09-27").rate)

    def test_older_than_max_age_is_not_found(self) -> None:
        self.assertIsNotNone(rate_for(WEEK, "EUR", "2026-10-02"), "7 dní ještě platí")
        self.assertIsNone(rate_for(WEEK, "EUR", "2026-10-03"), "8 dní = lístek starý")
        self.assertIsNone(rate_for(WEEK, "EUR", "2026-09-20"), "před prvním lístkem")

    def test_missing_currency_does_not_fall_back_to_older_list(self) -> None:
        self.assertIsNone(rate_for(WEEK, "JPY", "2026-09-22"), "JPY jen v pátečním lístku — starší/jiný se nezkouší")
        self.assertIsNotNone(rate_for(WEEK, "JPY", "2026-09-25"))
        lists = [rate_list("2026-09-21", "1", EUR="24.33", JPY="14.00"), rate_list("2026-09-22", "2", EUR="24.34")]
        self.assertIsNone(rate_for(lists, "JPY", "2026-09-22"), "měna vyřazená z posledního lístku nesahá do historie")

    def test_home_currency_is_one_without_list(self) -> None:
        self.assertEqual(Rate(D("1"), None, None, None), rate_for([], "CZK", "2026-09-22"))
        self.assertEqual(Rate.home(), rate_for(WEEK, "EUR", "2026-09-22", home="EUR"))

    def test_valid_to_bounds_the_list(self) -> None:
        monthly = dict(rate_list("2026-09-01", "9", EUR="24.30"), validTo="2026-09-30")
        self.assertIsNotNone(rate_for([monthly], "EUR", "2026-09-30"))
        self.assertIsNone(rate_for([monthly], "EUR", "2026-10-01"))

    def test_reserve_is_the_previous_list(self) -> None:
        self.assertEqual("2026-09-21", reserve_for(WEEK, WEEK[1])["validFrom"])
        self.assertIsNone(reserve_for(WEEK, WEEK[0]))

    def test_select_lists_keeps_used_and_reserve(self) -> None:
        selection = select_lists(WEEK, [("EUR", "2026-09-26"), ("USD", "2026-09-22"), ("CZK", "2026-09-22"), ("JPY", "2026-09-22")])
        self.assertEqual([("JPY", "2026-09-22")], selection.missing)
        self.assertEqual({("EUR", "2026-09-26"), ("USD", "2026-09-22"), ("CZK", "2026-09-22")}, set(selection.rates))
        self.assertEqual(["2026-09-21", "2026-09-22", "2026-09-25"], [lst["validFrom"] for lst in selection.lists],
                         "použité 09-22 a 09-25, rezervy 09-21 a 09-22; domácí měna bez lístku")


class ListFilesTest(unittest.TestCase):
    def test_validate_list(self) -> None:
        self.assertEqual([], validate_list(RATE_LISTS[0]))
        bad = copy.deepcopy(RATE_LISTS[0])
        bad["rates"].append({"currency": "EUR", "amount": 1, "rate": D("1")})
        bad["rates"].append({"currency": "usd", "amount": 0, "rate": D("0")})
        bad["validTo"] = "x"
        bad["extra"] = 1
        problems = validate_list(bad)
        self.assertEqual(6, len(problems), problems)
        self.assertTrue(any("float" in p for p in validate_list({**RATE_LISTS[0], "rates": [{"currency": "EUR", "amount": 1, "rate": 24.1}]})))

    def test_load_lists_checks_names_and_sorts(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.assertEqual([], load_lists(root / "missing"), "chybějící adresář = žádné lístky")
            for lst in reversed(RATE_LISTS):
                (root / f"{lst['validFrom']}-cz.cnb.daily.json").write_text(dumps_expected(lst), encoding="utf-8")
            loaded = load_lists(root)
            self.assertEqual(["2026-08-28", "2026-08-31"], [lst["validFrom"] for lst in loaded])
            self.assertEqual(D("24.125"), loaded[1]["rates"][0]["rate"], "čísla jako Decimal")
            (root / "2026-09-01-cz.cnb.daily.json").write_text(dumps_expected(RATE_LISTS[0]), encoding="utf-8")
            with self.assertRaises(RatesError):
                load_lists(root)

    def test_fetch_missing_stores_last_two_lists_verbatim(self) -> None:
        calls: list[str] = []

        def fake_fetch(url: str) -> dict:
            calls.append(url)
            return {"valid": 1, "data": copy.deepcopy(WEEK + [rate_list("2026-09-29", "26187", EUR="24.41")])}

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "rates"
            out = fetch_missing(root, ["2026-09-26", "2026-09-22"], "https://svc.example/feed", fetch=fake_fetch)
            self.assertEqual([
                "https://svc.example/feed?dateFrom=2026-09-15&dateTo=2026-09-22&listType=1",
                "https://svc.example/feed?dateFrom=2026-09-19&dateTo=2026-09-26&listType=1",
            ], calls, "dotaz per datum: D − 7 … D, seřazeno")
            self.assertEqual([
                ("2026-09-21-cz.cnb.daily.json", "stažen (pro 2026-09-22)"),
                ("2026-09-22-cz.cnb.daily.json", "stažen (pro 2026-09-22)"),
                ("2026-09-22-cz.cnb.daily.json", "beze změny"),
                ("2026-09-25-cz.cnb.daily.json", "stažen (pro 2026-09-26)"),
            ], out, "poslední dva lístky ≤ D; 09-29 > D se nebere; existující se nepřepisuje")
            raw = (root / "2026-09-25-cz.cnb.daily.json").read_text(encoding="utf-8")
            self.assertEqual(json.loads(raw, parse_float=Decimal), WEEK[2], "lístek služby doslova")
            self.assertTrue(raw.startswith('{\n  "format"') and raw.endswith("}\n"), "2 mezery, \\n na konci")
            self.assertEqual(load_lists(root)[0]["validFrom"], "2026-09-21")

    def test_fetch_missing_without_list_for_day_is_an_error(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaises(RatesError):
                fetch_missing(Path(tmp), ["2026-09-20"], fetch=lambda url: {"valid": 1, "data": copy.deepcopy(WEEK)})
            with self.assertRaises(RatesError):
                fetch_missing(Path(tmp), ["2026-09-22"], fetch=lambda url: {"valid": 0})


class CorpusListsTest(unittest.TestCase):
    """Skutečné lístky korpusu (tests/corpus/cz/exchange-rates/): každý doklad
    v cizí měně má lístek přímo ke svému účetnímu datu, rezerva (předchozí
    pracovní den) se nikdy nevybere a nic nechybí."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.lists = load_lists(RATES_DIR)
        cls.docs = {p.stem: load_jsonc(p) for p in sorted((CORPUS_DIR / "data").glob("*.jsonc"))}
        cls.identities = load_jsonc(CORPUS_DIR / "identities.jsonc")

    def test_lists_cover_every_accounting_date(self) -> None:
        needed = needed_dates(self.docs, self.identities)
        self.assertEqual(["2026-08-18", "2026-08-31", "2026-09-01", "2026-09-22", "2026-09-30", "2026-10-01"], list(needed))
        selection = select_lists(self.lists, [(c, day) for day, currencies in needed.items() for c in currencies])
        self.assertEqual([], selection.missing)
        used = {}
        for (currency, day), rate in selection.rates.items():
            self.assertEqual(day, rate.valid_from, f"{currency} k {day}: účetní datum je pracovní den — vlastní lístek")
            self.assertEqual("cz.cnb.daily", rate.list_type)
            self.assertEqual(rate.rate, rate.rate.quantize(D("0.000001")))
            used[day] = rate
        self.assertEqual({"EUR": ["2026-08-18", "2026-08-31", "2026-09-22", "2026-09-30"], "USD": ["2026-09-01", "2026-10-01"]},
                         {c: sorted(day for (cc, day) in selection.rates if cc == c) for c in ("EUR", "USD")})
        reserves = sorted({lst["validFrom"] for lst in selection.lists} - set(used))
        self.assertEqual(["2026-08-17", "2026-08-28", "2026-09-21", "2026-09-29"], reserves, "rezervy = předchozí pracovní den")
        self.assertEqual(sorted(p.name for p in RATES_DIR.glob("*.json")),
                         [f"{lst['validFrom']}-cz.cnb.daily.json" for lst in selection.lists], "korpus nese právě použité a rezervy")

    def test_corpus_lists_are_real_cnb_lists(self) -> None:
        for lst in self.lists:
            self.assertEqual("CZK", lst["homeCurrency"])
            self.assertIsNone(lst["validTo"])
            self.assertTrue(lst["listNumber"].isdigit())
            currencies = {r["currency"] for r in lst["rates"]}
            self.assertTrue({"EUR", "USD", "XDR"} <= currencies, "denní lístek ČNB nese i XDR (seed ho vyřadí)")


if __name__ == "__main__":
    unittest.main()
