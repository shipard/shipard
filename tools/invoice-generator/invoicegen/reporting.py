"""Očekávání přiznání DPH, kontrolního hlášení a nákladů per doklad
(tasks/testing-phase3.md, #103 D28 / D29) — spočítané z očekávaného
canonicalu dokladu a schváleného mapování `tests/corpus/cz/vat-reporting.jsonc`,
nezávisle na Shipardu (D5). Výstup jde do `expected/vat.json`
a `expected/costs.json` datové sady; čte je `shpd-ds dataset-verify`.

Mapování: varianta → režim (řádky přiznání podle sazby) + oddíl kontrolního
hlášení. Samovyměření (přenesení, EU, třetí země) má na dokladu 0 %, českou
sazbu dává `czRate`, daň = základ × sazba (Decimal, ROUND_HALF_UP). Doklady
v cizí měně dostanou záznam v měně dokladu (`currency`) — mapování je úplné,
kurz doplní až #121; verifikátor je do té doby vynechá (D30).

Náklad dokladu = Σ MD na účtech třídy 5 bez zaokrouhlení: plátce základ
(bez DPH ⇒ celková částka), neplátce a spolek základ + DPH (#97).
"""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

from .compute import CENTS
from .jsonc import load_jsonc

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


def _rates(expected: dict, spec: dict, label: str) -> dict[int, tuple[Decimal, Decimal]]:
    """Základ a daň po českých sazbách z rekapitulace očekávání. Tuzemský
    odpočet bere sazbu z dokladu (0 % = osvobozené, bez řádku); samovyměření
    vyžaduje na dokladu 0 % a daň dopočítá z `czRate`."""
    rate = spec["czRate"]
    out: dict[int, tuple[Decimal, Decimal]] = {}
    for entry in expected.get("vatRecap") or []:
        pct = int(entry["vatPct"])
        base = Decimal(str(entry["base"]))
        if rate is None:
            if pct == 0:
                continue
            out[pct] = (base + out.get(pct, (Decimal("0"), Decimal("0")))[0],
                        Decimal(str(entry["tax"])) + out.get(pct, (Decimal("0"), Decimal("0")))[1])
            continue
        if pct != 0:
            raise ReportingError(f"{label}: samovyměření čeká na dokladu 0 %, rekapitulace má {pct} %")
        prev = out.get(rate, (Decimal("0"), Decimal("0")))
        out[rate] = (prev[0] + base, prev[1] + _cents(base * rate / 100))
    return out


def vat_record(message_id: str, variant: str, expected: dict, reporting: VatReporting) -> dict:
    """Záznam `expected/vat.json`: období podle DUZP, řádky přiznání
    `{řádek: {base, tax}}` a oddíl KH s údaji dokladu (u B.3 jen informativně —
    hlášení nese součty)."""
    spec = reporting.variants.get(variant)
    if spec is None:
        raise ReportingError(f"{message_id}: varianta {variant!r} nemá mapování DPH")
    duzp = expected["dates"]["taxPointDate"]
    record = {
        "messageId": message_id,
        "variant": variant,
        "currency": expected["currency"],
        "period": duzp[:7] if duzp else None,
        "return": {},
        "controlStatement": None,
    }
    if spec["regime"] == REGIME_NONE:
        record["period"] = None
        return record

    rates = _rates(expected, spec, message_id)
    rows: dict[int, dict[str, Decimal]] = {}
    for pct, (base, tax) in rates.items():
        mapping = reporting.regimes[spec["regime"]].get(pct)
        if mapping is None:
            raise ReportingError(f"{message_id}: režim {spec['regime']} nezná sazbu {pct} %")
        for row in mapping.values():
            cell = rows.setdefault(row, {"base": Decimal("0"), "tax": Decimal("0")})
            cell["base"] += base
            cell["tax"] += tax
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
            "taxPointDate": duzp,
            "rates": {str(pct): {"base": base, "tax": tax} for pct, (base, tax) in sorted(rates.items(), reverse=True)},
            "reverseChargeCode": next(iter(codes)) if codes else None,
        }
    return record


def cost_record(message_id: str, expected: dict, *, vat_payer: bool) -> dict:
    """Záznam `expected/costs.json`: náklad bez zaokrouhlení — plátce základ,
    neplátce základ + DPH; doklad bez DPH celková částka."""
    totals = expected["totals"]
    if totals["totalBase"] is None:
        cost = Decimal(str(totals["totalAmount"]))
    elif vat_payer:
        cost = Decimal(str(totals["totalBase"]))
    else:
        cost = Decimal(str(totals["totalBase"])) + Decimal(str(totals["totalVat"]))
    return {"messageId": message_id, "currency": expected["currency"], "cost": _cents(cost)}
