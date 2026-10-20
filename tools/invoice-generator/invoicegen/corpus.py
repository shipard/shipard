"""Korpus testovacích dokladů (tasks/testing-phase1.md): datový soubor
dokladu, přepisy per profil (`overrides`), vynechané buňky (`skipProfiles`)
a matice doklad × profil vlastní firmy."""

from __future__ import annotations

import copy
import re
from dataclasses import dataclass
from datetime import date
from decimal import Decimal

from .compute import PRICE_CALC_MODES, VAT_TREATMENTS
from .expected import EU_COUNTRIES

PROFILES = ("vat-payer", "non-payer", "association")
# Podoba dokladu je jen pole `form`; název souboru nese číslo varianty a pořadí
# dokladu (`01` / `02`), ne podobu (#119 krok 1).
FORMS = ("pdf", "pdfIsdoc")
STEM_RE = re.compile(r"^([0-9]{2})(0[12])-([a-z0-9-]+)$")
VARIANTS = {
    "domestic-goods": 1,
    "domestic-two-rates": 2,
    "domestic-rounding": 3,
    "domestic-reverse-charge": 4,
    "eu-services": 5,
    "eu-goods": 6,
    "domestic-non-payer-supplier": 7,
    "third-country-services": 8,
    "receipt": 9,
    "long-document": 10,
}
RECEIPT_TEMPLATES = frozenset({"receipt"})
# Jazyk šablony — řídí jazyk tisku i předmět / tělo zprávy v datové sadě.
TEMPLATE_LOCALE = {"cs-a": "cs", "cs-b": "cs", "cs-c": "cs", "receipt": "cs", "en": "en"}
PAYMENT_METHODS = ("bankTransfer", "card", "cash")
ROUNDINGS = ("none", "czk")


class DataFileError(ValueError):
    pass


@dataclass(frozen=True)
class Cell:
    stem: str
    profile: str
    data: dict
    supplier: dict
    profile_identity: dict
    template: str
    form: str
    receipt: bool


@dataclass(frozen=True)
class SkippedCell:
    stem: str
    profile: str
    reason: str


def deep_merge(base: dict, override: dict) -> dict:
    """Objekty se slučují do hloubky, pole a skaláry nahrazují; `null`
    nastavuje null. Vstupy se nemění."""
    result = copy.deepcopy(base)
    for key, value in override.items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = deep_merge(result[key], value)
        else:
            result[key] = copy.deepcopy(value)
    return result


def parse_stem(stem: str) -> tuple[int, str, str]:
    """`NNNN-<slug>` → (číslo varianty, pořadí dokladu `01` / `02`, slug)."""
    m = STEM_RE.fullmatch(stem)
    if m is None:
        raise DataFileError(f"{stem}: název neodpovídá NNNN-<slug> (dvojčíslí varianty, pořadí 01 / 02, slug varianty)")
    return int(m.group(1)), m.group(2), m.group(3)


def stem_for(doc: dict, ordinal: str) -> str:
    """Název datového souboru pro variantu dokladu a pořadí (`01` / `02`)."""
    return f"{VARIANTS[doc['variant']]:02d}{ordinal}-{doc['variant']}"


def resolve_overrides(doc: dict) -> dict[str, dict]:
    """`overrides` s aliasy: hodnota = jméno jiného profilu přebírá jeho přepis."""
    raw = doc.get("overrides") or {}
    resolved: dict[str, dict] = {}
    for profile, value in raw.items():
        if isinstance(value, str):
            target = raw.get(value)
            if not isinstance(target, dict):
                raise DataFileError(f"overrides.{profile}: alias na {value!r}, který není přepis")
            resolved[profile] = target
        elif isinstance(value, dict):
            resolved[profile] = value
        else:
            raise DataFileError(f"overrides.{profile}: očekáván objekt nebo alias")
    return resolved


def _is_date(value) -> bool:
    try:
        date.fromisoformat(value)
        return True
    except (TypeError, ValueError):
        return False


def _scale(value) -> int:
    if isinstance(value, Decimal):
        return max(0, -value.as_tuple().exponent)
    return 0


def _text(value) -> bool:
    return isinstance(value, str) and value == value.strip() and value != ""


def validate_document(doc: dict, *, supplier: dict, templates: set[str], taxonomy: set[str],
                      label: str) -> None:
    """Obsahová validace dokladu (po sloučení přepisu profilu)."""
    problems: list[str] = []

    def bad(msg: str) -> None:
        problems.append(f"{label}: {msg}")

    template = doc.get("template")
    if template not in templates:
        bad(f"neznámá šablona {template!r}")
    receipt = template in RECEIPT_TEMPLATES
    treatment = doc.get("vatTreatment")
    if treatment not in VAT_TREATMENTS:
        bad(f"neznámý vatTreatment {treatment!r}")
    country = supplier["country"]
    foreign = country != "cz"
    # D10 (#119): ISDOC jen mezi dvěma českými subjekty s IČO — zahraničnímu
    # dodavateli, dodavateli bez IČO ani účtence (bez odběratele) k ničemu není.
    if doc.get("form") == "pdfIsdoc" and (foreign or not supplier.get("companyId") or receipt):
        bad("podoba pdfIsdoc jen u českého dodavatele s IČO a dokladu s odběratelem (D10 #119)")
    if treatment == "noVat" and not foreign and supplier.get("vatPayer"):
        bad("noVat u tuzemského plátce")
    if treatment in ("standard", "pricesWithVat", "reverseCharge") and not supplier.get("vatPayer"):
        bad(f"{treatment} u dodavatele bez DIČ")
    if treatment == "exemptIntraCommunity" and (not foreign or country not in EU_COUNTRIES):
        bad("exemptIntraCommunity jen u dodavatele z jiného státu EU")
    if treatment == "reverseCharge" and foreign and country not in EU_COUNTRIES:
        bad("reverseCharge jen u dodavatele z ČR nebo EU")
    domestic_rc = treatment == "reverseCharge" and not foreign

    if not _text(doc.get("docNumber")):
        bad("chybí docNumber")
    currency = doc.get("currency")
    if not isinstance(currency, str) or not re.fullmatch(r"[A-Z]{3}", currency):
        bad(f"currency {currency!r} není ISO 4217 velkými")
    rounding = doc.get("rounding", "none")
    if rounding not in ROUNDINGS:
        bad(f"neznámý rounding {rounding!r}")
    if rounding == "czk" and currency != "CZK":
        bad("rounding czk jen u CZK")

    dates = doc.get("dates") or {}
    if not _is_date(dates.get("issue")):
        bad("dates.issue chybí nebo není ISO datum")
    for key in ("tax", "due", "periodFrom", "periodTo"):
        if dates.get(key) is not None and not _is_date(dates[key]):
            bad(f"dates.{key} není ISO datum")
    if treatment != "noVat" and dates.get("tax") is None:
        bad("dates.tax chybí (jen noVat ho nemá)")
    if receipt:
        if dates.get("due") is not None:
            bad("účtenka nemá splatnost")
    elif dates.get("due") is None:
        bad("dates.due chybí")
    if (dates.get("periodFrom") is None) != (dates.get("periodTo") is None):
        bad("periodFrom a periodTo jen spolu")

    reference = doc.get("paymentReference")
    if reference is not None and not (isinstance(reference, str) and re.fullmatch(r"[0-9]{1,10}", reference)):
        bad(f"paymentReference {reference!r} — jen číslice, max 10")
    if receipt and reference is not None:
        bad("účtenka nemá variabilní symbol")
    payment = doc.get("payment") or {}
    method = payment.get("method", "bankTransfer")
    if method not in PAYMENT_METHODS:
        bad(f"neznámý payment.method {method!r}")
    if receipt and method == "bankTransfer":
        bad("účtenka: platba kartou nebo hotově")
    for key in ("specificSymbol", "constantSymbol"):
        value = payment.get(key)
        if value is not None and not (isinstance(value, str) and re.fullmatch(r"[0-9]{1,10}", value)):
            bad(f"payment.{key} {value!r} — jen číslice")

    tag = doc.get("contentTag")
    if tag not in taxonomy:
        bad(f"contentTag {tag!r} není v taxonomii")

    rows = doc.get("rows")
    if not isinstance(rows, list) or not rows:
        bad("chybí rows")
        rows = []
    for i, row in enumerate(rows):
        where = f"rows[{i}]"
        if not isinstance(row, dict):
            bad(f"{where}: není objekt")
            continue
        if not _text(row.get("text")):
            bad(f"{where}: chybí text")
        if not _text(row.get("unit")):
            bad(f"{where}: chybí unit")
        qty = row.get("qty")
        if not isinstance(qty, (int, Decimal)) or isinstance(qty, bool) or qty <= 0 or _scale(qty) > 3:
            bad(f"{where}: qty {qty!r} — kladné, nejvýš 3 desetinná místa")
        price = row.get("unitPrice")
        if not isinstance(price, (int, Decimal)) or isinstance(price, bool) or _scale(price) > 2:
            bad(f"{where}: unitPrice {price!r} — nejvýš 2 desetinná místa")
        mode = row.get("priceCalcMode", "fromUnitPrice")
        if mode not in PRICE_CALC_MODES:
            bad(f"{where}: neznámý priceCalcMode {mode!r}")
        if (mode == "fromTotal") != ("totalPrice" in row):
            bad(f"{where}: totalPrice právě s priceCalcMode fromTotal")
        disc = row.get("discountPct")
        if disc is not None and (not isinstance(disc, (int, Decimal)) or _scale(disc) > 2 or not 0 < disc <= 100):
            bad(f"{where}: discountPct {disc!r} mimo (0; 100]")
        kind = row.get("supplyKind")
        if foreign and kind not in ("goods", "services"):
            bad(f"{where}: supplyKind povinný u dodavatele mimo ČR")
        if not foreign and kind is not None:
            bad(f"{where}: supplyKind jen u dodavatele mimo ČR")
        code = row.get("reverseChargeCode")
        if domestic_rc and not (isinstance(code, str) and re.fullmatch(r"[0-9]{1,2}", code)):
            bad(f"{where}: tuzemské přenesení chce reverseChargeCode")
        if not domestic_rc and code is not None:
            bad(f"{where}: reverseChargeCode jen u tuzemského přenesení")
        if row.get("tag") is not None and row["tag"] not in taxonomy:
            bad(f"{where}: štítek {row['tag']!r} není v taxonomii")
        if row.get("tag") == tag:
            bad(f"{where}: výjimka štítku shodná se štítkem dokladu")
        for key in ("description", "supplierCode", "ean"):
            if row.get(key) is not None and not _text(row[key]):
                bad(f"{where}: {key} není text")

    if problems:
        raise DataFileError("\n".join(problems))


def validate_data_file(doc: dict, stem: str, *, identities: dict, templates: set[str],
                       taxonomy: set[str]) -> None:
    """Validace datového souboru: název (číslo varianty, pořadí dokladu, slug),
    varianta a podoba, dodavatel, `skipProfiles` / `overrides`, obsah pro
    každý profil matice."""
    problems: list[str] = []
    variant = doc.get("variant")
    form = doc.get("form")
    if variant not in VARIANTS:
        problems.append(f"{stem}: neznámá varianta {variant!r}")
    if form not in FORMS:
        problems.append(f"{stem}: neznámá podoba {form!r}")
    try:
        number, _ordinal, slug = parse_stem(stem)
    except DataFileError as e:
        problems.append(str(e))
    else:
        if variant in VARIANTS and (number != VARIANTS[variant] or slug != variant):
            problems.append(f"{stem}: název souboru neodpovídá variantě ({stem_for(doc, _ordinal)})")
    supplier = identities["suppliers"].get(doc.get("supplier"))
    if supplier is None:
        problems.append(f"{stem}: neznámý dodavatel {doc.get('supplier')!r}")

    skip = doc.get("skipProfiles") or {}
    for profile, reason in skip.items():
        if profile not in PROFILES:
            problems.append(f"{stem}: skipProfiles.{profile}: neznámý profil")
        if not _text(reason):
            problems.append(f"{stem}: skipProfiles.{profile}: chybí důvod")
    overrides_raw = doc.get("overrides") or {}
    for profile in overrides_raw:
        if profile not in PROFILES:
            problems.append(f"{stem}: overrides.{profile}: neznámý profil")
        if profile in skip:
            problems.append(f"{stem}: {profile} je zároveň v skipProfiles i overrides")
    if problems:
        raise DataFileError("\n".join(problems))

    overrides = resolve_overrides(doc)
    for profile in PROFILES:
        if profile in skip:
            continue
        merged = deep_merge(_without_matrix(doc), overrides.get(profile, {}))
        validate_document(merged, supplier=supplier, templates=templates, taxonomy=taxonomy,
                          label=f"{stem} [{profile}]")


def _without_matrix(doc: dict) -> dict:
    return {k: v for k, v in doc.items() if k not in ("skipProfiles", "overrides")}


def validate_variant_pairs(docs: dict[str, dict]) -> None:
    """Oba doklady varianty (pořadí `01` a `02`) jsou téhož dodavatele a každé
    pořadí je ve variantě nejvýš jednou. Klíče = názvy souborů (stačí `NNNN`)."""
    by_variant: dict[str, set[str]] = {}
    ordinals: dict[str, list[str]] = {}
    for stem, doc in docs.items():
        by_variant.setdefault(doc["variant"], set()).add(doc["supplier"])
        ordinals.setdefault(doc["variant"], []).append(stem[2:4])
    problems: list[str] = []
    wrong = {v: s for v, s in by_variant.items() if len(s) > 1}
    if wrong:
        problems.append("varianta má víc dodavatelů: " + ", ".join(f"{v} {sorted(s)}" for v, s in wrong.items()))
    for variant, seen in ordinals.items():
        dupes = sorted({o for o in seen if seen.count(o) > 1})
        if dupes:
            problems.append(f"varianta {variant}: pořadí {', '.join(dupes)} víckrát")
    if problems:
        raise DataFileError("\n".join(problems))


def cells_for(doc: dict, stem: str, identities: dict) -> list[Cell | SkippedCell]:
    """Buňky matice pro jeden datový soubor (v pořadí PROFILES)."""
    skip = doc.get("skipProfiles") or {}
    overrides = resolve_overrides(doc)
    supplier = identities["suppliers"][doc["supplier"]]
    cells: list[Cell | SkippedCell] = []
    for profile in PROFILES:
        if profile in skip:
            cells.append(SkippedCell(stem, profile, skip[profile]))
            continue
        data = deep_merge(_without_matrix(doc), overrides.get(profile, {}))
        cells.append(Cell(
            stem=stem, profile=profile, data=data, supplier=supplier,
            profile_identity=identities["profiles"][profile], template=data["template"],
            form=data["form"], receipt=data["template"] in RECEIPT_TEMPLATES,
        ))
    return cells
