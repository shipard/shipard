"""Port plánu účetních odpisů `as_tax` nad rovnoměrnou metodou
(invoicegen/assets.py, #131 fáze 2c): hodnoty ověřené proti PHP
`AsTaxAnnualMethod` + `CzTaxDepreciationRules` (docs/assets.md §5.2)."""

from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.assets import acc_monthly_plan, ceil_whole, straight_annual, straight_rate
from invoicegen.catalogs import load_catalogs
from tests.fixtures import REPO_ROOT

RULES = load_catalogs(REPO_ROOT).asset_rules


def months(*years: int) -> list[date]:
    return [date(year, month, 1) for year in years for month in range(1, 13)]


class StraightRateTest(unittest.TestCase):
    def test_rates_follow_activation_date(self) -> None:
        current = straight_rate(RULES, "cz-1", date(2026, 2, 14))
        self.assertEqual((Decimal("20.0"), Decimal("40.0"), Decimal("33.3")), (current.first, current.next, current.increased))
        old = straight_rate(RULES, "cz-1", date(2004, 6, 1))
        self.assertEqual(Decimal("14.2"), old.first, "sazba platná k datu zařazení (do 2004)")
        with self.assertRaises(ValueError):
            straight_rate(RULES, "cz-99", date(2026, 1, 1))

    def test_annual_amount_is_rounded_up_and_capped(self) -> None:
        self.assertEqual(Decimal("30000"), straight_annual(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), 0, Decimal("150000")))
        self.assertEqual(Decimal("60000"), straight_annual(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), 1, Decimal("120000")))
        self.assertEqual(Decimal("10000"), straight_annual(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), 2, Decimal("10000")), "nejvýš zůstatek")
        self.assertEqual(Decimal("2000"), straight_annual(RULES, "cz-1", Decimal("9999"), date(2026, 2, 14), 0, Decimal("9999")), "nahoru na koruny (1 999,8)")
        self.assertEqual(Decimal("0"), ceil_whole(Decimal("0.00004")), "srovnání na 4 místa před zaokrouhlením (ceil(round(x, 4)))")
        self.assertEqual(Decimal("1"), ceil_whole(Decimal("1.00004")))
        self.assertEqual(Decimal("2"), ceil_whole(Decimal("1.00005")))
        self.assertEqual(Decimal("4286"), ceil_whole(Decimal("30000") / Decimal("7")))


class MonthlyPlanTest(unittest.TestCase):
    def test_first_year_spreads_annual_amount_from_month_after_activation(self) -> None:
        plan = acc_monthly_plan(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), months(2026, 2027, 2028, 2029))
        self.assertEqual([date(2026, m, 1) for m in range(3, 13)], [m for m in plan if m.year == 2026], "od března, ne od února")
        self.assertEqual({Decimal("3000")}, {a for m, a in plan.items() if m.year == 2026}, "30 000 / 10 měsíců")
        self.assertEqual({Decimal("5000")}, {a for m, a in plan.items() if m.year in (2027, 2028)}, "40 % / 12")
        self.assertEqual(Decimal("150000"), sum(plan.values()), "odepsáno do nuly")
        self.assertEqual(date(2028, 12, 1), max(plan), "3 roky, nic v roce 2029")

    def test_uneven_months_round_up_and_last_month_tops_up(self) -> None:
        plan = acc_monthly_plan(RULES, "cz-1", Decimal("150000"), date(2026, 5, 14), months(2026))
        self.assertEqual([Decimal("4286")] * 5 + [Decimal("4285")] * 2, [plan[date(2026, m, 1)] for m in range(6, 13)],
                         "30 000 / 7: podíl ze zbytku nahoru, konec roku dorovná")
        self.assertEqual(Decimal("30000"), sum(plan.values()))

    def test_activation_in_december_takes_the_whole_year_in_december(self) -> None:
        plan = acc_monthly_plan(RULES, "cz-1", Decimal("12000"), date(2026, 12, 5), months(2026, 2027))
        self.assertEqual(Decimal("2400"), plan[date(2026, 12, 1)])
        self.assertEqual({Decimal("400")}, {a for m, a in plan.items() if m.year == 2027})

    def test_window_outside_use_is_empty(self) -> None:
        self.assertEqual({}, acc_monthly_plan(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), [date(2026, 1, 1), date(2026, 2, 1)]))
        self.assertEqual({}, acc_monthly_plan(RULES, "cz-1", Decimal("150000"), date(2026, 2, 14), []))


if __name__ == "__main__":
    unittest.main()
