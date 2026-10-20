"""Kotva a relativní data příběhu (tasks/demo-dataset-phase1.md D7, D14)
a deterministická „rozmanitost bez náhody“ (D6).

Období sady = `months_before` celých měsíců před měsícem kotvy a měsíc
kotvy do dne kotvy. Všechna data příběhu jsou relativní: posun měsíce od
měsíce kotvy (0 = měsíc kotvy, záporný = minulost) a den v měsíci
(ořezaný na délku měsíce). `build.py --anchor` tak posune celou sadu.

Žádný `random`, žádné `datetime.now()`: různé částky, počty kusů a minuty
přijetí plynou ze `spread()` nad klíči (měsíc, pořadí partnera, pořadí
pravidla), takže dva běhy dají stejné bajty.
"""

from __future__ import annotations

import calendar
from dataclasses import dataclass
from datetime import date, timedelta
from decimal import Decimal


@dataclass(frozen=True)
class Period:
    anchor: date
    months: tuple[date, ...]   # první dny měsíců, nejstarší první, poslední = měsíc kotvy

    @property
    def start(self) -> date:
        return self.months[0]

    @property
    def offsets(self) -> range:
        """Posuny měsíců od kotvy: -(n-1) … 0."""
        return range(-(len(self.months) - 1), 1)

    def month_start(self, offset: int) -> date:
        if offset not in self.offsets:
            raise ValueError(f"měsíc {offset} je mimo období {self.offsets.start}..0")
        return self.months[offset + len(self.months) - 1]


def add_months(first_day: date, n: int) -> date:
    """První den měsíce posunutého o `n` měsíců."""
    index = first_day.year * 12 + (first_day.month - 1) + n
    return date(index // 12, index % 12 + 1, 1)


def period_for(anchor: date, months_before: int) -> Period:
    if months_before < 0:
        raise ValueError("monthsBefore musí být >= 0")
    anchor_month = anchor.replace(day=1)
    months = tuple(add_months(anchor_month, -k) for k in range(months_before, -1, -1))
    return Period(anchor=anchor, months=months)


def month_end(first_day: date) -> date:
    return first_day.replace(day=calendar.monthrange(first_day.year, first_day.month)[1])


def clamp_day(first_day: date, day: int) -> date:
    """Den v měsíci ořezaný na jeho délku (31 → 30 / 28)."""
    if day < 1:
        raise ValueError(f"den {day} musí být >= 1")
    last = calendar.monthrange(first_day.year, first_day.month)[1]
    return first_day.replace(day=min(day, last))


def month_date(period: Period, offset: int, day: int) -> date:
    return clamp_day(period.month_start(offset), day)


def in_period(period: Period, d: date) -> bool:
    """Datum spadá do období sady (do dne kotvy včetně)."""
    return period.start <= d <= period.anchor


def quarter_offsets(period: Period) -> list[int]:
    """Posuny měsíců, které začínají kalendářní čtvrtletí (leden, duben, …)."""
    return [offset for offset in period.offsets if period.month_start(offset).month in (1, 4, 7, 10)]


def fiscal_year(d: date, start_month: int = 1) -> int:
    """Označení fiskálního roku pro datum (rok, ve kterém fiskální rok začíná)."""
    return d.year if d.month >= start_month else d.year - 1


_WEIGHTS = (7, 13, 31, 101, 211)


def spread(lo: int, hi: int, *keys: int) -> int:
    """Deterministická hodnota v <lo, hi> z celočíselných klíčů — náhrada
    za RNG: stejné klíče = stejná hodnota, sousední klíče dávají jiná čísla."""
    if hi < lo:
        raise ValueError(f"rozsah <{lo}, {hi}> je prázdný")
    total = sum(abs(int(k)) * w for k, w in zip(keys, _WEIGHTS))
    return lo + total % (hi - lo + 1)


def spread_decimal(lo: Decimal, hi: Decimal, step: Decimal, *keys: int) -> Decimal:
    """Totéž pro částky: `lo + step · spread(0, (hi - lo) / step)`."""
    if step <= 0 or hi < lo:
        raise ValueError("rozsah nebo krok částky není kladný")
    steps = int((hi - lo) / step)
    return lo + step * spread(0, steps, *keys)


def ts(d: date, hour: int, minute: int = 0, second: int = 0) -> str:
    """Lokální razítko `YYYY-MM-DDTHH:MM:SS` jako v sadě."""
    return f"{d.isoformat()}T{hour:02d}:{minute:02d}:{second:02d}"


def manifest_created(anchor: date) -> str:
    """`manifest.created` = kotva o půlnoci UTC (D7)."""
    return f"{anchor.isoformat()}T00:00:00Z"


def period_label(first_day: date) -> str:
    """`MM/YYYY` do textů paušálů."""
    return f"{first_day.month:02d}/{first_day.year}"


def quarter_label(first_day: date) -> str:
    """`Q1/2026` do textů čtvrtletních paušálů."""
    return f"Q{(first_day.month - 1) // 3 + 1}/{first_day.year}"


def days_before(anchor: date, days: int) -> date:
    return anchor - timedelta(days=days)
