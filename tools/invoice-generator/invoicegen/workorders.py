"""Periodické zakázky příběhu (#131 fáze 2b, D32–D34): čisté funkce, které
zrcadlí PHP periodické fakturace, aby faktury v sadě vypadaly přesně tak,
jak by je složil `InvoiceBuilder` nad naseedovanou zakázkou:

- `period_bounds` / `due_periods` = `PeriodCalendar` (kalendářní období,
  fakturace na počátku období, splatná období k datu běhu);
- `period_label` = `PeriodLabel` (měsíc přes ICU `LLLL yyyy` — názvy
  měsíců v 1. pádě ověřené proti PHP 8.5 / ICU 74; čtvrtletí a pololetí
  z cfgItem `economy.workOrders.periodTexts`);
- `doc_text` = `DocTextTemplate` (`{období}` / `{period}`, prázdná šablona =
  název zakázky + štítek, ořez na 200 znaků);
- `rows_valid_at` = `InvoiceBuilder::rowsValidAt` (platnost řádku předpisu
  k datu vystavení);
- `invoice_from` / `date_start_for` = „fakturovat od“ a zahájení zakázky
  relativně ke kotvě (D34, fáze 2d: zahájení i uvnitř okna) — žijí
  v `timeline.py`, aby je mohla použít validace příběhu; tady re-export.

Jazyk dokladu je jazyk osoby (`DocumentLanguageResolver`: jazyk na osobě,
je-li mezi jazyky dokumentů; odběratelé příběhu ho mají vždy).
"""

from __future__ import annotations

from datetime import date, timedelta

from .model import WorkOrderRowRec
from .story import Party
from .timeline import add_months, date_start_for, invoice_from

__all__ = ["MONTH_NAMES", "PERIODIC_RULES", "date_start_for", "doc_text", "document_language", "due_periods",
           "invoice_from", "ordinal_in_year", "period_bounds", "period_label", "rows_valid_at"]

# Pravidla billing, ze kterých vzniká periodická zakázka (`once` zůstává mimo).
PERIODIC_RULES = ("month", "quarter")

# `IntlDateFormatter` vzor `LLLL yyyy` (samostatný tvar měsíce), locale
# cs_CZ / sk_SK / de_DE / en_GB — PeriodLabel::LOCALES.
MONTH_NAMES = {
    "cs": ("leden", "únor", "březen", "duben", "květen", "červen",
           "červenec", "srpen", "září", "říjen", "listopad", "prosinec"),
    "sk": ("január", "február", "marec", "apríl", "máj", "jún",
           "júl", "august", "september", "október", "november", "december"),
    "de": ("Januar", "Februar", "März", "April", "Mai", "Juni",
           "Juli", "August", "September", "Oktober", "November", "Dezember"),
    "en": ("January", "February", "March", "April", "May", "June",
           "July", "August", "September", "October", "November", "December"),
}

# PeriodLabel::FALLBACK_TEXTS — bez katalogu anglicky.
FALLBACK_TEXTS = {"quarter": "Q{n} {year}", "halfyear": "H{n} {year}"}
PERIODICITY_MONTHS = {"month": 1, "quarter": 3, "halfyear": 6, "year": 12}
PLACEHOLDERS = ("{období}", "{period}")
DOC_TEXT_MAX_LENGTH = 200
MAX_PERIODS = 1200


def period_bounds(day: date, periodicity: str) -> tuple[date, date]:
    """`PeriodCalendar::containing`: kalendářní období obsahující datum."""
    months = PERIODICITY_MONTHS[periodicity]
    start = date(day.year, (day.month - 1) // months * months + 1, 1)
    return start, add_months(start, months) - timedelta(days=1)


def ordinal_in_year(start: date, periodicity: str) -> int:
    """`PeriodCalendar::ordinalInYear`: pořadí období v roce."""
    return (start.month - 1) // PERIODICITY_MONTHS[periodicity] + 1


def period_label(start: date, periodicity: str, language: str, texts: dict[str, dict[str, str]]) -> str:
    """`PeriodLabel::format` v jazyce dokladu; `texts` = katalog
    `periodTexts` (periodicita → jazyk → text)."""
    if periodicity == "month":
        names = MONTH_NAMES.get(language, MONTH_NAMES["en"])
        return f"{names[start.month - 1]} {start.year}"
    if periodicity == "year":
        return str(start.year)
    if periodicity in ("quarter", "halfyear"):
        by_language = texts.get(periodicity) or {}
        text = by_language.get(language) or by_language.get("en") or FALLBACK_TEXTS[periodicity]
        return text.replace("{n}", str(ordinal_in_year(start, periodicity))).replace("{year}", str(start.year))
    end = add_months(start, PERIODICITY_MONTHS.get(periodicity, 1)) - timedelta(days=1)
    return f"{start.isoformat()} – {end.isoformat()}"


def doc_text(template: str | None, title: str, label: str) -> str:
    """`DocTextTemplate::render`: `{období}` / `{period}` → štítek; prázdná
    šablona = název zakázky a štítek; ořez na délku sloupce."""
    text = (template or "").strip()
    if text == "":
        text = f"{title} {label}".strip()
    else:
        for placeholder in PLACEHOLDERS:
            text = text.replace(placeholder, label)
    return text[:DOC_TEXT_MAX_LENGTH]


def due_periods(invoice_from: date, run_date: date, periodicity: str,
                date_end: date | None = None) -> list[tuple[date, date]]:
    """`PeriodCalendar::duePeriods` s fakturací na počátku období: od
    období obsahujícího „fakturovat od“ po poslední, jehož začátek ≤ datum
    běhu; období začínající po ukončení se nevystaví."""
    out: list[tuple[date, date]] = []
    start, end = period_bounds(invoice_from, periodicity)
    while len(out) < MAX_PERIODS:
        if date_end is not None and start > date_end:
            break
        if start > run_date:
            break
        out.append((start, end))
        start, end = period_bounds(end + timedelta(days=1), periodicity)
    return out


def rows_valid_at(rows: list[WorkOrderRowRec], day: date) -> list[WorkOrderRowRec]:
    """`InvoiceBuilder::rowsValidAt`: `valid_from ≤ datum ≤ valid_to`, None
    neomezeno; pořadí zůstává."""
    return [r for r in rows
            if (r.valid_from is None or r.valid_from <= day) and (r.valid_to is None or r.valid_to >= day)]


def document_language(party: Party) -> str:
    """Jazyk dokladu = jazyk osoby (odběratelé ho mají vždy), jinak anglicky
    jako `DocumentLanguageResolver` bez známé země."""
    return party.language if party.language in MONTH_NAMES else "en"
