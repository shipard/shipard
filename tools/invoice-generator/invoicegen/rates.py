"""Kurzovní lístky korpusu (tasks/testing-rates-isdoc.md, #103 D72 / D73).

Korpus nese skutečné denní lístky ČNB (`cz.cnb.daily`) ve formátu
`shpd.exchangeRates.list.v1` v `tests/corpus/cz/exchange-rates/
<validFrom>-cz.cnb.daily.json` — pro účetní datum každého dokladu v cizí
měně lístek, který resolver použije, a lístek z předchozího pracovního dne
jako rezerva (D81). Stahují se jednou ze služby kurzů (`build.py
--fetch-rates`, docs/exchange-rates.md § 9); build je pak jen čte.

`rate_for` zrcadlí `ExchangeRateResolver` (docs/exchange-rates.md § 4)
nezávisle na Shipardu: domácí měna = 1; jinak v typu `cz.cnb.daily`
POSLEDNÍ lístek s `validFrom` ≤ D bez ohledu na měnu, platný nejvýš
`maxAgeDays` (7) dní (lístek s `validTo` do něj), měna v něm — chybí-li,
starší lístek se nezkouší; kurz za jednotku = `rate / amount` na 6 míst
ROUND_HALF_UP. Jiné typy lístků korpus nenese. Očekávání v Kč se z toho
počítá v `reporting.py`; shoda s resolverem se ověřuje v kroku
`dataset-verify`, očekávání se podle Shipardu neupravuje.
"""

from __future__ import annotations

import json
import re
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Callable, Iterable

from .corpus import SkippedCell, cells_for
from .expected import dumps_expected

LIST_FORMAT = "shpd.exchangeRates.list.v1"
LIST_TYPE = "cz.cnb.daily"       # jediný typ v korpusu (modules/world/cz/config/exchangeRateLists.jsonc)
SERVICE_LIST_TYPE = 1            # `listType` služby pro cz.cnb.daily (download.serviceListType)
MAX_AGE_DAYS = 7                 # maxAgeDays typu cz.cnb.daily (U1)
HOME_CURRENCY = "CZK"
RATE_PLACES = Decimal("0.000001")
SERVICE_URL = "https://data.shipard.app/feed/money-exchange-rates"
FILE_SUFFIX = f"-{LIST_TYPE}.json"
DATE_RE = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}$")
CURRENCY_RE = re.compile(r"^[A-Z]{3}$")


class RatesError(ValueError):
    pass


@dataclass(frozen=True)
class Rate:
    """Dohledaný kurz: za jednotku měny na 6 míst; domácí měna má `list_type` None."""
    rate: Decimal
    list_type: str | None
    valid_from: str | None
    list_number: str | None

    @staticmethod
    def home() -> "Rate":
        return Rate(Decimal("1"), None, None, None)


def accounting_date(data: dict) -> str:
    """Účetní datum dokladu korpusu (D73): DUZP, bez něj datum vystavení
    (doklad bez DPH — zrcadlí výjimku věrnosti `EXCEPTIONS_NO_VAT`)."""
    dates = data["dates"]
    return dates.get("tax") or dates["issue"]


def per_unit(rate, amount) -> Decimal:
    """Kurz za jednotku z vyhlášeného kurzu za `amount` jednotek (`ExchangeRate::perUnit`)."""
    return (Decimal(str(rate)) / Decimal(int(amount))).quantize(RATE_PLACES, rounding=ROUND_HALF_UP)


def needed_dates(docs: dict[str, dict], identities: dict, *, home: str = HOME_CURRENCY) -> dict[str, tuple[str, ...]]:
    """Účetní datum → měny, pro které buňky matice (doklady v cizí měně)
    potřebují kurz; seřazeno podle data."""
    pairs: dict[str, set[str]] = {}
    for stem, doc in docs.items():
        for cell in cells_for(doc, stem, identities):
            if isinstance(cell, SkippedCell) or cell.data["currency"] == home:
                continue
            pairs.setdefault(accounting_date(cell.data), set()).add(cell.data["currency"])
    return {day: tuple(sorted(currencies)) for day, currencies in sorted(pairs.items())}


def file_name(valid_from: str) -> str:
    return f"{valid_from}{FILE_SUFFIX}"


def validate_list(raw) -> list[str]:
    """Tvar lístku `shpd.exchangeRates.list.v1` (schéma formátu + bez měny dvakrát)."""
    if not isinstance(raw, dict):
        return ["lístek není objekt"]
    problems: list[str] = []
    if raw.get("format") != LIST_FORMAT:
        problems.append(f"format musí být {LIST_FORMAT}")
    if not isinstance(raw.get("listType"), str) or not raw["listType"]:
        problems.append("listType chybí")
    home = raw.get("homeCurrency")
    if not isinstance(home, str) or not CURRENCY_RE.fullmatch(home):
        problems.append("homeCurrency není kód měny velkými")
    if not isinstance(raw.get("validFrom"), str) or not DATE_RE.fullmatch(raw["validFrom"]):
        problems.append("validFrom není YYYY-MM-DD")
    valid_to = raw.get("validTo")
    if valid_to is not None and (not isinstance(valid_to, str) or not DATE_RE.fullmatch(valid_to)):
        problems.append("validTo není YYYY-MM-DD ani null")
    number = raw.get("listNumber")
    if number is not None and not isinstance(number, str):
        problems.append("listNumber není řetězec ani null")
    rates = raw.get("rates")
    if not isinstance(rates, list) or not rates:
        problems.append("rates musí být neprázdné pole")
        rates = []
    seen: set[str] = set()
    for i, entry in enumerate(rates):
        where = f"rates[{i}]"
        if not isinstance(entry, dict):
            problems.append(f"{where}: není objekt")
            continue
        currency = entry.get("currency")
        if not isinstance(currency, str) or not CURRENCY_RE.fullmatch(currency):
            problems.append(f"{where}: currency není kód měny velkými")
        elif currency in seen:
            problems.append(f"{where}: měna {currency} dvakrát")
        else:
            seen.add(currency)
        amount = entry.get("amount")
        if not isinstance(amount, int) or isinstance(amount, bool) or amount < 1:
            problems.append(f"{where}: amount musí být celé číslo ≥ 1")
        rate = entry.get("rate")
        if isinstance(rate, float):
            problems.append(f"{where}: rate je float — čísla jen Decimal / int")
        elif not isinstance(rate, (int, Decimal)) or isinstance(rate, bool) or rate <= 0:
            problems.append(f"{where}: rate musí být kladné číslo")
    unknown = set(raw) - {"format", "listType", "homeCurrency", "validFrom", "validTo", "listNumber", "rates"}
    if unknown:
        problems.append("neznámé klíče: " + ", ".join(sorted(unknown)))
    return problems


def load_lists(rates_dir: Path) -> list[dict]:
    """Lístky korpusu (`*.json`) seřazené podle (listType, validFrom); název
    souboru musí odpovídat obsahu. Chybějící adresář = žádné lístky."""
    lists: list[dict] = []
    for path in sorted(rates_dir.glob("*.json")) if rates_dir.is_dir() else []:
        raw = json.loads(path.read_text(encoding="utf-8"), parse_float=Decimal)
        problems = validate_list(raw)
        if problems:
            raise RatesError(f"{path.name}: " + "; ".join(problems))
        if raw["listType"] != LIST_TYPE:
            raise RatesError(f"{path.name}: korpus nese jen lístky typu {LIST_TYPE}")
        if path.name != file_name(raw["validFrom"]):
            raise RatesError(f"{path.name}: název neodpovídá lístku ({file_name(raw['validFrom'])})")
        lists.append(raw)
    lists.sort(key=lambda lst: (lst["listType"], lst["validFrom"]))
    if len({lst["validFrom"] for lst in lists}) != len(lists):
        raise RatesError(f"{rates_dir}: dva lístky se stejnou platností")
    return lists


def _latest(lists: list[dict], day: str) -> dict | None:
    candidates = [lst for lst in lists if lst["listType"] == LIST_TYPE and lst["validFrom"] <= day]
    return max(candidates, key=lambda lst: lst["validFrom"]) if candidates else None


def rate_for(lists: list[dict], currency: str, day: str, *, home: str = HOME_CURRENCY,
             max_age_days: int = MAX_AGE_DAYS) -> Rate | None:
    """Kurz měny k datu podle pravidla resolveru (docstring modulu); None = nenalezeno."""
    if currency == home:
        return Rate.home()
    latest = _latest(lists, day)
    if latest is None:
        return None
    valid_to = latest.get("validTo")
    if valid_to is not None:
        if day > valid_to:
            return None
    elif (date.fromisoformat(day) - date.fromisoformat(latest["validFrom"])).days > max_age_days:
        return None
    entry = next((r for r in latest["rates"] if r["currency"] == currency), None)
    if entry is None:
        return None
    return Rate(per_unit(entry["rate"], entry["amount"]), latest["listType"], latest["validFrom"], latest.get("listNumber"))


def reserve_for(lists: list[dict], used: dict) -> dict | None:
    """Lístek téhož typu bezprostředně před použitým — rezerva z předchozího
    pracovního dne (D81), kterou korpus i sada nesou spolu s použitým."""
    older = [lst for lst in lists if lst["listType"] == used["listType"] and lst["validFrom"] < used["validFrom"]]
    return max(older, key=lambda lst: lst["validFrom"]) if older else None


@dataclass(frozen=True)
class Selection:
    rates: dict[tuple[str, str], Rate]   # (měna, datum) → kurz
    lists: list[dict]                    # použité + rezervní lístky v pořadí (listType, validFrom)
    missing: list[tuple[str, str]]       # dvojice bez kurzu


def select_lists(lists: list[dict], pairs: Iterable[tuple[str, str]], *, home: str = HOME_CURRENCY) -> Selection:
    """Kurzy pro dvojice (měna, datum) a lístky, které k nim patří: použitý
    a jeho rezerva. Dvojice bez kurzu končí v `missing` — o chybě rozhoduje
    volající (build radí `--fetch-rates`)."""
    rates: dict[tuple[str, str], Rate] = {}
    missing: list[tuple[str, str]] = []
    kept: dict[str, dict] = {}
    for currency, day in sorted(set(pairs)):
        rate = rate_for(lists, currency, day, home=home)
        if rate is None:
            missing.append((currency, day))
            continue
        rates[(currency, day)] = rate
        if rate.list_type is None:
            continue
        used = next(lst for lst in lists if lst["listType"] == rate.list_type and lst["validFrom"] == rate.valid_from)
        kept[used["validFrom"]] = used
        reserve = reserve_for(lists, used)
        if reserve is not None:
            kept[reserve["validFrom"]] = reserve
    ordered = sorted(kept.values(), key=lambda lst: (lst["listType"], lst["validFrom"]))
    return Selection(rates=rates, lists=ordered, missing=missing)


# ── Stažení ze služby (jen build.py --fetch-rates) ───────────────────────────

def _http_get_json(url: str) -> dict:
    with urllib.request.urlopen(url, timeout=30) as response:   # noqa: S310 — pevná HTTPS adresa služby
        return json.loads(response.read().decode("utf-8"), parse_float=Decimal)


def fetch_missing(rates_dir: Path, days: Iterable[str], service_url: str = SERVICE_URL, *,
                  fetch: Callable[[str], dict] = _http_get_json) -> list[tuple[str, str]]:
    """Pro každé účetní datum D stáhne okno D − 7 … D (`listType` 1) a uloží
    poslední DVA lístky s `validFrom` ≤ D — použitý a předchozí pracovní den
    jako rezervu, bez vlastního kalendáře — jako `<validFrom>-cz.cnb.daily.json`
    (lístek služby doslova, 2 mezery, `\\n` na konci). Existující soubor se
    nepřepisuje. Žádný lístek pro D = chyba. Vrací (soubor, stav) pro výpis."""
    rates_dir.mkdir(parents=True, exist_ok=True)
    out: list[tuple[str, str]] = []
    for day in sorted(set(days)):
        if not DATE_RE.fullmatch(day):
            raise RatesError(f"{day}: není datum")
        since = (date.fromisoformat(day) - timedelta(days=MAX_AGE_DAYS)).isoformat()
        query = urllib.parse.urlencode({"dateFrom": since, "dateTo": day, "listType": SERVICE_LIST_TYPE})
        payload = fetch(f"{service_url}?{query}")
        if not isinstance(payload, dict) or payload.get("valid") != 1 or not isinstance(payload.get("data"), list):
            raise RatesError(f"{day}: služba nevrátila platnou odpověď")
        window: list[dict] = []
        for raw in payload["data"]:
            problems = validate_list(raw)
            if problems:
                raise RatesError(f"{day}: lístek služby neodpovídá formátu: " + "; ".join(problems))
            if raw["listType"] == LIST_TYPE and raw["validFrom"] <= day:
                window.append(raw)
        window.sort(key=lambda lst: lst["validFrom"])
        if not window:
            raise RatesError(f"{day}: služba nemá lístek {LIST_TYPE} s platností ≤ {day} (okno od {since})")
        for raw in window[-2:]:
            path = rates_dir / file_name(raw["validFrom"])
            if path.exists():
                out.append((path.name, "beze změny"))
                continue
            path.write_text(dumps_expected(raw), encoding="utf-8")
            out.append((path.name, f"stažen (pro {day})"))
    return out
