"""Majetek demo sady (tasks/demo-dataset-phase2c-assets.md, #131 D41): port
měsíčního plánu účetních odpisů metodou „účetně jako daňově“ nad
rovnoměrnou daňovou metodou — PHP `AsTaxAnnualMethod` +
`CzTaxDepreciationRules::annualAmount` (modules/economy/assets,
modules/world/assets). Souhrn ekonomiky z něj vypisuje, kolik by Odpisy za
období v okně příběhu zaúčtovaly; sada sama odpisy nenese (D38).

Pravidla portu:
  - roční daňový odpis = vstupní cena × sazba / 100 (1. rok `first`, další
    roky `next`), zaokrouhleno podle pravidel země (CZ nahoru na koruny),
    nejvýš zůstatková cena;
  - roční částka se rozpouští do měsíců v užívání: od měsíce po zařazení
    do prosince (celá roční částka i za zkrácený první rok), v dalších
    letech od ledna; každý měsíc dostane podíl ze zbytku roční částky
    (nahoru na koruny), poslední měsíc roku dorovná;
  - majetek zařazený v prosinci má roční odpis celý v prosinci;
  - rok s odpisem posune počitadlo let (sazba `next`), rok bez odpisu ne.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal, ROUND_CEILING, ROUND_HALF_UP
from typing import Iterable

from .catalogs import AssetRules, StraightRate
from .timeline import add_months

__all__ = ["acc_monthly_plan", "ceil_whole", "straight_annual", "straight_rate"]

ONE = Decimal("1")


def ceil_whole(value: Decimal) -> Decimal:
    """`Amounts::ceil`: nahoru na celé koruny po srovnání na 4 místa
    (`ceil(round($amount, 4))`)."""
    return value.quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP).quantize(ONE, rounding=ROUND_CEILING)


def _round_tax(value: Decimal, rules: AssetRules) -> Decimal:
    """`TaxDepreciationRules::round`: podle `rounding` pravidel země."""
    factor = Decimal(10) ** rules.rounding_precision
    scaled = (value * factor).quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)
    rounded = scaled.quantize(ONE, rounding=ROUND_HALF_UP if rules.rounding_mode == "round" else ROUND_CEILING)
    return rounded / factor


def straight_rate(rules: AssetRules, rule: str, acquired: date) -> StraightRate:
    """Sazby skupiny platné k datu zařazení (`rateFor`)."""
    for rate in rules.straight_rates:
        if rate.code == rule and rate.valid_at(acquired):
            return rate
    raise ValueError(f"odpisová skupina {rule!r} nemá sazbu rovnoměrné metody platnou k {acquired}")


def straight_annual(rules: AssetRules, rule: str, price: Decimal, acquired: date, years_applied: int,
                    residual: Decimal) -> Decimal:
    """Roční odpis rovnoměrné metody (§ 31 odst. 7): setina součinu vstupní
    ceny a sazby, zaokrouhleno, nejvýš zůstatek."""
    rate = straight_rate(rules, rule, acquired)
    pct = rate.first if years_applied == 0 else rate.next
    amount = _round_tax(price * pct / Decimal(100), rules)
    return min(amount, max(residual, Decimal("0")))


def acc_monthly_plan(rules: AssetRules, rule: str, price: Decimal, activation: date,
                     months: Iterable[date]) -> dict[date, Decimal]:
    """Měsíční účetní odpisy `as_tax` pro první dny měsíců `months`
    (vzestupně): měsíc → částka; měsíce před užíváním a po odepsání
    v mapě nejsou."""
    use_from = add_months(activation.replace(day=1), 1)
    residual = price
    years = 0
    year: int | None = None
    allocated = Decimal("0")
    depreciated = False
    out: dict[date, Decimal] = {}
    for month in sorted(months):
        if month.year != year:
            if depreciated:
                years += 1
            year, allocated, depreciated = month.year, Decimal("0"), False
        year_residual = residual + allocated
        if year_residual <= 0:
            break
        year_end = date(month.year, 12, 1)
        use_from_year = max(date(month.year, 1, 1), use_from)
        if use_from_year > year_end:
            if activation.year != month.year:
                continue
            use_from_year = year_end          # zařazeno v prosinci — roční odpis celý v něm
        if month < use_from_year:
            continue
        annual = straight_annual(rules, rule, price, activation, years, year_residual)
        remaining = (year_end.year - month.year) * 12 + year_end.month - month.month + 1
        rest = annual - allocated
        if rest <= 0:
            continue
        amount = ceil_whole(rest / remaining) if remaining > 1 else rest
        amount = min(amount, residual)
        if amount <= 0:
            continue
        out[month] = amount
        residual -= amount
        allocated += amount
        depreciated = True
    return out
