from __future__ import annotations

import unittest
from datetime import date
from decimal import Decimal

from invoicegen.timeline import (
    clamp_day, fiscal_year, in_period, manifest_created, month_date, month_end, period_for, period_label,
    quarter_label, quarter_offsets, spread, spread_decimal, ts,
)


class PeriodTest(unittest.TestCase):
    def test_default_anchor_gives_january_to_october(self) -> None:
        period = period_for(date(2026, 10, 20), 9)
        self.assertEqual(date(2026, 1, 1), period.start)
        self.assertEqual(10, len(period.months))
        self.assertEqual(list(range(-9, 1)), list(period.offsets))
        self.assertEqual(date(2026, 10, 1), period.month_start(0))
        self.assertEqual(date(2026, 1, 1), period.month_start(-9))
        with self.assertRaises(ValueError):
            period.month_start(-10)

    def test_period_crossing_a_year_boundary(self) -> None:
        period = period_for(date(2027, 3, 15), 9)
        self.assertEqual(date(2026, 6, 1), period.start)
        # Okno červen–březen má jen tři začátky čtvrtletí — posun kotvy mimo
        # násobek tří měsíců mění počet čtvrtletních dokladů (záměr, D7).
        self.assertEqual([-8, -5, -2], quarter_offsets(period))
        self.assertEqual([date(2026, 7, 1), date(2026, 10, 1), date(2027, 1, 1)],
                         [period.month_start(o) for o in quarter_offsets(period)])

    def test_days_are_clamped_and_period_bounds_hold(self) -> None:
        period = period_for(date(2026, 10, 20), 9)
        self.assertEqual(date(2026, 2, 28), month_date(period, -8, 31))
        self.assertEqual(date(2026, 4, 30), month_end(date(2026, 4, 1)))
        self.assertEqual(date(2026, 10, 31), clamp_day(date(2026, 10, 1), 31))
        self.assertTrue(in_period(period, date(2026, 10, 20)))
        self.assertFalse(in_period(period, date(2026, 10, 21)), "po dni kotvy nic")
        self.assertFalse(in_period(period, date(2025, 12, 31)))
        self.assertEqual([-9, -6, -3, 0], quarter_offsets(period))

    def test_labels_and_timestamps(self) -> None:
        self.assertEqual("03/2026", period_label(date(2026, 3, 1)))
        self.assertEqual("Q4/2026", quarter_label(date(2026, 10, 1)))
        self.assertEqual("2026-01-02T08:05:00", ts(date(2026, 1, 2), 8, 5))
        self.assertEqual("2026-10-20T00:00:00Z", manifest_created(date(2026, 10, 20)))
        self.assertEqual(2026, fiscal_year(date(2026, 12, 31)))
        self.assertEqual(2025, fiscal_year(date(2026, 3, 1), start_month=4))


class SpreadTest(unittest.TestCase):
    def test_bounds_and_determinism(self) -> None:
        values = {spread(2, 6, m, c, 1) for m in range(-9, 1) for c in range(14)}
        self.assertTrue(values <= set(range(2, 7)))
        self.assertGreater(len(values), 1, "různé klíče dávají různé hodnoty")
        self.assertEqual(spread(2, 6, -3, 4, 1), spread(2, 6, -3, 4, 1))
        self.assertEqual(5, spread(5, 5, 1, 2, 3))
        with self.assertRaises(ValueError):
            spread(6, 2, 1)

    def test_decimal_spread_follows_step(self) -> None:
        amounts = {spread_decimal(Decimal("120"), Decimal("480"), Decimal("10"), m, 0, 3) for m in range(-9, 1)}
        for amount in amounts:
            self.assertEqual(0, amount % 10)
            self.assertTrue(Decimal("120") <= amount <= Decimal("480"))
        qty = spread_decimal(Decimal("0.62"), Decimal("0.95"), Decimal("0.001"), -5, 4, 0)
        self.assertTrue(Decimal("0.62") <= qty <= Decimal("0.95"))


if __name__ == "__main__":
    unittest.main()
