"""Očekávání přiznání DPH, kontrolního hlášení a nákladů per doklad
(tasks/testing-phase3.md, #103 D28 / D29) — spočítané z očekávaného
canonicalu dokladu a schváleného mapování `tests/corpus/cz/vat-reporting.jsonc`,
nezávisle na Shipardu (D5). Výstup jde do `expected/vat.json`
a `expected/costs.json` datové sady; čte je `shpd-ds dataset-verify`.

Mapování: varianta → režim (řádky přiznání podle sazby) + oddíl kontrolního
hlášení. Samovyměření (přenesení, EU, třetí země) má na dokladu 0 %, českou
sazbu dává `czRate`, daň = základ × sazba (Decimal, ROUND_HALF_UP). Základ
samovyměření jde z řádků a u plnění z EU rozhoduje druh plnění řádku
(`supplyKind`): zboží → intracomGoods (ř. 3), služba → intracomServices
(ř. 5) — dopravné na faktuře za zboží je služba (#103 nález G, 2026-10-20);
každá skupina se přepočítá a zdaní zvlášť, jako položka rekapitulace per kód.

Částky jsou v Kč (#103 D73): doklad v cizí měně se přepočítá kurzem
k účetnímu datu (DUZP, bez něj datum vystavení — `rates.py`), základ
v Kč = základ v měně × kurz na haléře per sazba, daň samovyměření
= základ v Kč × sazba, daň dodavatele = daň v měně × kurz; `currency`
záznamu zůstává měna dokladu. Samovyměření bez rekapitulace (služba ze
třetí země bez DPH) bere základ z celkové částky. Období a datum
v oddílu KH jdou z účetního data. Kurz per doklad nese
`expected/exchange-rates.json` (`exchange_rate_record`).

Náklad dokladu = Σ MD na účtech třídy 5 bez zaokrouhlení, v Kč — každá
účtovaná částka se přepočítá zvlášť (nález H): plátce Σ položek
rekapitulace (základ per sazba, u samovyměření per druh plnění; bez DPH
celková částka), neplátce a spolek Σ řádků s DPH dodavatele v ceně (#97),
dorovnaných na rekapitulaci papíru per sazba; haléřový zbytek proti
celkem × kurz jde v Shipardu na účet zaokrouhlení.
"""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

from .compute import CENTS
from .jsonc import load_jsonc
from .rates import Rate

VAT_REPORTING_FORMAT = "shpd.corpus.vatReporting"
VAT_REPORTING_VERSION = "1"
REGIME_NONE = "none"
ROW_KINDS = ("output", "deduction")
CS_SECTIONS = {"A2": "A.2", "B1": "B.1", "B2orB3": None, "B3": "B.3"}   # None = podle prahu
# Řádky formuláře DP3 (přiznání k DPH), na které smí mapování mířit.
DP3_ROWS = frozenset({
    1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 20, 21, 22, 23, 24, 25, 26, 30, 31, 32, 33,
    40, 41, 42, 43, 44, 45, 46, 47, 50, 51, 52, 53, 60, 61, 62, 63, 64, 65, 66,
})


class ReportingError(ValueError):
    pass


@dataclass(frozen=True)
class VatReporting:
    threshold: Decimal
    regimes: dict[str, dict[int, dict[str, int]]]   # režim → sazba → {output?, deduction?}
    variants: dict[str, dict]                        # varianta → {regime, controlStatement, czRate?}


def load_vat_reporting(path: Path, *, required_variants: set[str] | frozenset[str] | None = None) -> VatReporting:
    raw = load_jsonc(path)
    problems: list[str] = []
    if raw.get("format") != VAT_REPORTING_FORMAT or str(raw.get("formatVersion")) != VAT_REPORTING_VERSION:
        raise ReportingError(f"{path}: není {VAT_REPORTING_FORMAT} v{VAT_REPORTING_VERSION}")
    unknown = set(raw) - {"format", "formatVersion", "controlStatementThreshold", "regimes", "variants"}
    if unknown:
        problems.append("neznámé klíče: " + ", ".join(sorted(unknown)))

    threshold = raw.get("controlStatementThreshold")
    if not isinstance(threshold, (int, Decimal)) or isinstance(threshold, bool) or threshold <= 0:
        problems.append("controlStatementThreshold musí být kladné číslo")
        threshold = Decimal("0")

    regimes: dict[str, dict[int, dict[str, int]]] = {}
    for regime, rates in (raw.get("regimes") or {}).items():
        if regime == REGIME_NONE:
            problems.append(f"regimes.{regime}: jméno 'none' je vyhrazené")
            continue
        if not isinstance(rates, dict) or not rates:
            problems.append(f"regimes.{regime}: očekáván objekt sazba → řádky")
            continue
        regimes[regime] = {}
        for rate_key, rows in rates.items():
            if not str(rate_key).isdigit() or int(rate_key) <= 0:
                problems.append(f"regimes.{regime}.{rate_key}: sazba musí být kladné celé číslo")
                continue
            if not isinstance(rows, dict) or not rows or set(rows) - set(ROW_KINDS):
                problems.append(f"regimes.{regime}.{rate_key}: klíče jen {', '.join(ROW_KINDS)}")
                continue
            bad = [k for k, v in rows.items() if not isinstance(v, int) or isinstance(v, bool) or v not in DP3_ROWS]
            if bad:
                problems.append(f"regimes.{regime}.{rate_key}: řádek mimo formulář DP3 ({', '.join(bad)})")
                continue
            regimes[regime][int(rate_key)] = dict(rows)

    variants: dict[str, dict] = {}
    for variant, spec in (raw.get("variants") or {}).items():
        if not isinstance(spec, dict):
            problems.append(f"variants.{variant}: očekáván objekt")
            continue
        extra = set(spec) - {"regime", "controlStatement", "czRate"}
        if extra:
            problems.append(f"variants.{variant}: neznámé klíče {', '.join(sorted(extra))}")
        regime = spec.get("regime")
        if regime != REGIME_NONE and regime not in regimes:
            problems.append(f"variants.{variant}: neznámý režim {regime!r}")
        section = spec.get("controlStatement")
        if section is not None and section not in CS_SECTIONS:
            problems.append(f"variants.{variant}: neznámý oddíl KH {section!r}")
        rate = spec.get("czRate")
        if rate is not None and (not isinstance(rate, int) or isinstance(rate, bool) or rate <= 0):
            problems.append(f"variants.{variant}: czRate musí být kladné celé číslo")
        if regime == REGIME_NONE and (section is not None or rate is not None):
            problems.append(f"variants.{variant}: režim none nemá oddíl KH ani czRate")
        variants[variant] = {"regime": regime, "controlStatement": section, "czRate": rate}

    if required_variants is not None:
        missing = sorted(set(required_variants) - set(variants))
        if missing:
            problems.append("varianty bez mapování: " + ", ".join(missing))
        orphan = sorted(set(variants) - set(required_variants))
        if orphan:
            problems.append("mapování pro neznámé varianty: " + ", ".join(orphan))
    if problems:
        raise ReportingError(f"{path}: " + "; ".join(problems))
    return VatReporting(threshold=Decimal(threshold), regimes=regimes, variants=variants)


def _cents(value: Decimal) -> Decimal:
    return value.quantize(CENTS, rounding=ROUND_HALF_UP)


def accounting_date_of(expected: dict) -> str:
    """Účetní datum z očekávaného canonicalu (D73): DUZP, bez něj datum
    vystavení — totéž co `rates.accounting_date` nad datovým souborem."""
    dates = expected["dates"]
    return dates.get("taxPointDate") or dates["issueDate"]


INTRACOM_REGIMES = {"goods": "intracomGoods", "services": "intracomServices"}


def _item_rows(expected: dict) -> list[dict]:
    return [r for r in expected.get("rows") or [] if r.get("rowKind", "item") == "item"]


def _row_regime(regime: str, supply_kind: str | None) -> str:
    """Režim per řádek (nález G): u plnění z EU rozhoduje druh plnění řádku,
    řádek bez druhu dědí režim varianty; ostatní režimy se per řádek nemění."""
    if regime in INTRACOM_REGIMES.values() and supply_kind in INTRACOM_REGIMES:
        return INTRACOM_REGIMES[supply_kind]
    return regime


def _groups(expected: dict, spec: dict, label: str, rate: Decimal) -> dict[tuple[str, int], tuple[Decimal, Decimal]]:
    """(režim, sazba) → (základ, daň) v Kč. Tuzemský odpočet bere sazbu
    z rekapitulace (0 % = osvobozené, bez řádku) a daň dodavatele přepočte
    kurzem; samovyměření vyžaduje na dokladu 0 %, základ bere z řádků po
    režimech (druh plnění) a daň dopočítá z `czRate` ze základu v Kč."""
    cz_rate = spec["czRate"]
    regime = spec["regime"]
    sums: dict[tuple[str, int], tuple[Decimal, Decimal]] = {}   # (režim, sazba) → (základ, daň) v měně dokladu
    recap = expected.get("vatRecap") or []
    if cz_rate is None:
        for entry in recap:
            pct = int(entry["vatPct"])
            if pct == 0:
                continue
            prev = sums.get((regime, pct), (Decimal("0"), Decimal("0")))
            sums[(regime, pct)] = (prev[0] + Decimal(str(entry["base"])), prev[1] + Decimal(str(entry["tax"])))
    else:
        for entry in recap:
            if int(entry["vatPct"]) != 0:
                raise ReportingError(f"{label}: samovyměření čeká na dokladu 0 %, rekapitulace má {entry['vatPct']} %")
        for row in _item_rows(expected):
            key = (_row_regime(regime, (row.get("vat") or {}).get("supplyKind")), cz_rate)
            prev = sums.get(key, (Decimal("0"), Decimal("0")))
            sums[key] = (prev[0] + Decimal(str(row["totalPrice"])), Decimal("0"))

    out: dict[tuple[str, int], tuple[Decimal, Decimal]] = {}
    for (reg, pct), (base, tax) in sums.items():
        base_czk = _cents(base * rate)
        out[(reg, pct)] = (base_czk, _cents(tax * rate) if cz_rate is None else _cents(base_czk * pct / 100))
    return out


def vat_record(message_id: str, variant: str, expected: dict, reporting: VatReporting, *, rate: Decimal) -> dict:
    """Záznam `expected/vat.json`: období podle účetního data, řádky přiznání
    `{řádek: {base, tax}}` v Kč a oddíl KH s údaji dokladu (u B.3 jen
    informativně — hlášení nese součty). `rate` = kurz za jednotku měny
    dokladu k účetnímu datu (1 u domácí měny)."""
    spec = reporting.variants.get(variant)
    if spec is None:
        raise ReportingError(f"{message_id}: varianta {variant!r} nemá mapování DPH")
    acc_date = accounting_date_of(expected)
    record = {
        "messageId": message_id,
        "variant": variant,
        "currency": expected["currency"],
        "period": acc_date[:7],
        "return": {},
        "controlStatement": None,
    }
    if spec["regime"] == REGIME_NONE:
        record["period"] = None
        return record

    groups = _groups(expected, spec, message_id, rate)
    rows: dict[int, dict[str, Decimal]] = {}
    rates: dict[int, tuple[Decimal, Decimal]] = {}   # sazba → (základ, daň) přes režimy (oddíl KH)
    for (regime, pct), (base, tax) in groups.items():
        mapping = reporting.regimes.get(regime, {}).get(pct)
        if mapping is None:
            raise ReportingError(f"{message_id}: režim {regime} nezná sazbu {pct} %")
        for row in mapping.values():
            cell = rows.setdefault(row, {"base": Decimal("0"), "tax": Decimal("0")})
            cell["base"] += base
            cell["tax"] += tax
        prev = rates.get(pct, (Decimal("0"), Decimal("0")))
        rates[pct] = (prev[0] + base, prev[1] + tax)
    record["return"] = {str(row): rows[row] for row in sorted(rows)}

    section_key = spec["controlStatement"]
    if section_key is not None:
        section = CS_SECTIONS[section_key]
        if section is None:
            total = Decimal(str(expected["totals"]["totalAmount"]))
            section = "B.2" if total > reporting.threshold else "B.3"
        codes = {r["vat"]["reverseChargeCode"] for r in expected["rows"] if r["vat"].get("reverseChargeCode")}
        if len(codes) > 1:
            raise ReportingError(f"{message_id}: řádky mají různé kódy přenesení {sorted(codes)}")
        record["controlStatement"] = {
            "section": section,
            "supplierVatId": expected["supplier"]["vatId"],
            "documentNumber": expected["docNumber"],
            "taxPointDate": acc_date,
            "rates": {str(pct): {"base": base, "tax": tax} for pct, (base, tax) in sorted(rates.items(), reverse=True)},
            "reverseChargeCode": next(iter(codes)) if codes else None,
        }
    return record


def _payer_cost_lines(expected: dict) -> list[Decimal]:
    """Účtované základy plátce v měně dokladu: položky rekapitulace per sazba;
    samovyměření (0 %) po druzích plnění řádků, jak je vede Shipard per kód
    (nález G); bez rekapitulace celková částka."""
    recap = expected.get("vatRecap") or []
    if not recap:
        return [Decimal(str(expected["totals"]["totalAmount"]))]
    lines: list[Decimal] = []
    for entry in recap:
        pct = int(entry["vatPct"])
        base = Decimal(str(entry["base"]))
        if pct != 0:
            lines.append(base)
            continue
        by_kind: dict[str | None, Decimal] = {}
        for row in _item_rows(expected):
            vat = row.get("vat") or {}
            if int(vat.get("pct") or 0) != 0:
                continue
            kind = vat.get("supplyKind")
            by_kind[kind] = by_kind.get(kind, Decimal("0")) + Decimal(str(row["totalPrice"]))
        lines.extend(by_kind.values() if sum(by_kind.values(), Decimal("0")) == base else [base])
    return lines


def _non_payer_cost(expected: dict, rate: Decimal) -> Decimal:
    """Σ řádků s DPH dodavatele v ceně (#97), dorovnaných na rekapitulaci
    papíru per sazba (rozdíl na řádek s největší cenou), každý přepočtený
    zvlášť (nález H)."""
    gross: list[Decimal] = []
    pcts: list[int] = []
    for row in _item_rows(expected):
        pct = int((row.get("vat") or {}).get("pct") or 0)
        gross.append(_cents(Decimal(str(row["totalPrice"])) * (1 + Decimal(pct) / 100)))
        pcts.append(pct)
    for entry in expected.get("vatRecap") or []:
        pct = int(entry["vatPct"])
        idx = [i for i, p in enumerate(pcts) if p == pct]
        if not idx:
            continue
        diff = Decimal(str(entry["total"])) - sum((gross[i] for i in idx), Decimal("0"))
        if diff:
            target = max(idx, key=lambda i: abs(gross[i]))
            gross[target] += diff
    return sum((_cents(g * rate) for g in gross), Decimal("0"))


def cost_record(message_id: str, expected: dict, *, vat_payer: bool, rate: Decimal) -> dict:
    """Záznam `expected/costs.json`: náklad v Kč bez zaokrouhlení, každá
    účtovaná částka přepočtená zvlášť — plátce položky rekapitulace
    (samovyměření po druzích plnění), bez DPH celková částka; neplátce
    řádky s DPH dodavatele. `currency` zůstává měna dokladu."""
    if vat_payer:
        cost = sum((_cents(line * rate) for line in _payer_cost_lines(expected)), Decimal("0"))
    else:
        cost = _non_payer_cost(expected, rate)
    return {"messageId": message_id, "currency": expected["currency"], "cost": _cents(cost)}


def exchange_rate_record(message_id: str, expected: dict, rate: Rate) -> dict:
    """Záznam `expected/exchange-rates.json` — jen doklad v cizí měně: datum
    kurzu (účetní datum), lístek, který generátor použil, a kurz za
    jednotku. `dataset-verify` ho srovná s kurzem, který doplnil Shipard."""
    if rate.list_type is None:
        raise ReportingError(f"{message_id}: záznam kurzu jen pro doklad v cizí měně")
    return {
        "messageId": message_id,
        "currency": expected["currency"],
        "rateDate": accounting_date_of(expected),
        "listType": rate.list_type,
        "validFrom": rate.valid_from,
        "rate": rate.rate,
    }
