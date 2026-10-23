"""Příběh firmy demo sady (tasks/demo-dataset-phase1.md D6): načtení
souborů `story.jsonc`, `partners.jsonc`, `items.jsonc`, `registry.jsonc`,
`mail.jsonc` a `setup/*.jsonc` z adresáře příběhu a jejich validace —
povinná pole, kontrolní součty identit (`checks.py`), odkazy partner ↔
položka ↔ nabídka osnovy, období relativně ke kotvě, vyváženost mezd,
jednoznačné české řazení jmen (`collation.py`).

Loader čte pravidla, ne doklady; rozvinutí dělá `expand.py`. Všechny
nálezy se sbírají a hlásí najednou (`StoryError`), jako `load_profile`
korpusu.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date, timedelta
from decimal import Decimal
from pathlib import Path

from .catalogs import Catalogs
from .checks import account_valid, iban_matches_account, validate_identities
from .collation import robust_order
from .datasetio import NAME_PATTERN, SETUP_FORMAT, SETUP_TABLES
from .jsonc import load_jsonc
from .timeline import Period, invoice_from, month_date, period_for

IDENTITY_KEYS = ("name", "country", "companyId", "taxId", "vatId", "courtRegistration", "address", "contact",
                 "bankAccount", "vatPayer")
LANGUAGES = ("cs", "sk", "de", "en")
SUPPLIER_LANGUAGES = ("cs", "en")                     # jazyk papíru dodavatele = locale šablony (#131 D28)
CURRENCIES = ("CZK", "EUR")
VAT_TREATMENTS = ("standard", "reverseCharge")
SUPPLY_KINDS = ("services",)                          # samovyměření jen u služeb z EU (cz-217); zboží sada neumí
SELF_ASSESSED_VAT_PCT = 21                            # sazba samovyměření služeb (cz-217)
PAYMENT_BEHAVIOURS = ("onTime", "late", "partial", "never")
SUPPLIER_PAYMENT_BEHAVIOURS = ("onTime", "late")      # jak platíme my (fáze 2a)
PAYMENT_REFERENCE_FAULTS = ("missing", "typo")        # záměrně nespárovaná platba (D18)
ITEM_KINDS = ("service", "stock")
ITEM_CODE_PATTERN = re.compile(r"^[A-Z0-9][A-Z0-9-]{1,9}$")
MESSAGE_ID_PATTERN = re.compile(r"^[A-Z0-9][A-Z0-9-]{0,29}$")
PARTNER_KEY_PATTERN = re.compile(r"^[a-z][a-z0-9]{1,11}$")
ANCHOR_MONTH_MODES = ("processed", "open", "skip")
ATTENTION_KINDS = ("action", "info", "promo")
PERIOD_START_PLACEHOLDER = "@period.start"
VAT_PCTS = (0, 12, 21)
# Majetek (#131 fáze 2c): číselníky v setup/, nastavení, inventární číslo,
# jediná kombinace odpisů, kterou generátor umí spočítat (invoicegen/assets.py).
ASSET_SETUP_TABLES = ("asset_accounting_groups", "asset_type_groups", "asset_types")
ASSET_SETTINGS = {"economy.assets.accPeriodicity": "month", "economy.accounting.dimension.asset": "yes"}
ASSET_NUMBER_PATTERN = re.compile(r"^[A-Z0-9][A-Z0-9-]{1,19}$")
ASSET_TAX_METHOD = "straight"
ASSET_ACC_METHOD = "as_tax"
ASSET_CATEGORY = "tangible"
ASSET_GROUP_ACCOUNTS = ("account_asset", "account_acquisition", "account_accumulated", "account_depreciation")


class StoryError(ValueError):
    pass


def template_locale(template: str) -> str:
    """Locale šablony faktur dodavatele (`demo/invoices/templates/`): `en`
    je kopie korpusové anglické šablony, ostatní jsou české."""
    return "en" if template == "en" else "cs"


@dataclass(frozen=True)
class Party:
    key: str
    kind: str                       # own | customer | supplier
    identity: dict                  # kanonická identita (klíče IDENTITY_KEYS)
    language: str | None = None
    currency: str | None = None
    vat_treatment: str | None = None
    send_purposes: tuple[str, ...] = ()
    payment: dict = field(default_factory=dict)
    template: str | None = None
    accent: str | None = None
    doc_number: dict | None = None
    invoices: dict | None = None

    @property
    def name(self) -> str:
        return self.identity["name"]

    @property
    def country(self) -> str:
        return self.identity["country"]

    @property
    def company_id(self) -> str | None:
        return self.identity.get("companyId")

    @property
    def vat_id(self) -> str | None:
        return self.identity.get("vatId")

    @property
    def email(self) -> str | None:
        return (self.identity.get("contact") or {}).get("email")


@dataclass(frozen=True)
class Item:
    code: str
    name: str
    kind: str
    unit: str
    price: Decimal
    vat_pct: int
    account: str


@dataclass(frozen=True)
class Story:
    dir: Path
    name: str
    title: str
    description: str | None
    anchor: date
    period: Period
    own: Party
    bank_account_code: str          # účet v domácí měně (CZK)
    bank_accounts: dict[str, str]   # měna → kód účtu ze setup/bank_accounts (U1: EUR → BU2)
    cash_desk_code: str
    bank: dict                      # story.bank — zůstatky, poplatky, mzdy a odvody (fáze 2a)
    rates: dict[str, dict[int, Decimal]]
    invoicing: dict
    billing: tuple[dict, ...]
    adhoc: tuple[dict, ...]
    projects: dict                  # story.projects — druh, řada a projekty (#131 fáze 2b, D36); {} = žádné
    assets: dict                    # story.assets — skupina typů, typ a karty majetku (#131 fáze 2c, D41); {} = žádné
    cash_register: dict
    cash_expenses: tuple[dict, ...]
    payroll: dict
    customers: dict[str, Party]
    suppliers: dict[str, Party]
    items: dict[str, Item]
    registry: tuple[dict, ...]
    mail: tuple[dict, ...]
    setup: dict[str, dict]          # tabulka → obsah setup/<tabulka>.jsonc s doplněnými daty
    binders: tuple[str, ...]
    verified_at: str

    def parties(self) -> dict[str, Party]:
        return {"own": self.own, **self.customers, **self.suppliers}

    def party(self, key: str) -> Party:
        return self.parties()[key]

    def bank_account_for(self, currency: str) -> str:
        """Náš účet, na který chodí platby dokladů v dané měně (a který
        nese vydaná faktura jako `importOwnBankAccount`)."""
        return self.bank_accounts[currency]


# ── Pomocné kontroly ─────────────────────────────────────────────────────────

class _Problems:
    def __init__(self) -> None:
        self.items: list[str] = []

    def add(self, where: str, message: str) -> None:
        self.items.append(f"{where}: {message}")


def _is_text(value) -> bool:
    return isinstance(value, str) and value.strip() != "" and value == value.strip()


def _is_int(value) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _is_amount(value, *, positive: bool = True) -> bool:
    if isinstance(value, bool) or not isinstance(value, (int, Decimal)):
        return False
    return Decimal(value) > 0 if positive else True


def _is_range(value, *, kind=int) -> bool:
    return (isinstance(value, list) and len(value) == 2
            and all(isinstance(v, kind) and not isinstance(v, bool) for v in value)
            and value[0] <= value[1])


def _identity(raw: dict) -> dict:
    return {k: raw[k] for k in IDENTITY_KEYS if k in raw}


def identities_for_checks(own: dict, partners: dict[str, dict], verified_at: str) -> dict:
    """Tvar identit korpusu pro `checks.validate_identities` a
    `registries.verify_identities`: vlastní firma jako profil, odběratelé
    i dodavatelé jako dodavatelé (kontroly jsou pro ně stejné)."""
    return {
        "verifiedAt": verified_at,
        "profiles": {"own": _identity(own)},
        "suppliers": {key: _identity(party) for key, party in partners.items()},
    }


def _overlap_with_corpus(parties: dict[str, dict], corpus: dict | None, problems: _Problems) -> None:
    """Identity korpusu (`tests/corpus/cz/identities.jsonc`) a demo sady se
    nesmí překrývat — stejná pravidla jako korpus vůči demu."""
    if not corpus:
        return
    taken: dict[str, str] = {}
    for section in ("profiles", "suppliers"):
        for key, party in (corpus.get(section) or {}).items():
            for fld in ("name", "companyId", "vatId"):
                value = party.get(fld)
                if value:
                    taken[f"{fld}:{str(value).lower()}"] = f"{section}.{key}"
            iban = (party.get("bankAccount") or {}).get("iban")
            if iban:
                taken[f"iban:{iban}"] = f"{section}.{key}"
    for key, party in parties.items():
        for fld in ("name", "companyId", "vatId"):
            value = party.get(fld)
            if value and f"{fld}:{str(value).lower()}" in taken:
                problems.add(key, f"{fld} {value} se překrývá s identitou korpusu {taken[f'{fld}:{str(value).lower()}']}")
        iban = (party.get("bankAccount") or {}).get("iban")
        if iban and f"iban:{iban}" in taken:
            problems.add(key, f"IBAN {iban} se překrývá s identitou korpusu {taken[f'iban:{iban}']}")


def _account_forms(text) -> set[str]:
    """Tvary čísla účtu, které porovnává `PartnerResolver` aplikace: bez
    oddělovačů celé a část před lomítkem."""
    s = str(text or "")
    full = re.sub(r"[\s\-/]", "", s).upper()
    prefix = re.sub(r"[\s\-]", "", s.split("/", 1)[0]).upper()
    return {form for form in (full, prefix) if form}


def _relative_date(value, where: str, period: Period, problems: _Problems, *, allow_day: bool = True) -> None:
    if not isinstance(value, dict):
        problems.add(where, "musí být objekt {month, day}")
        return
    if not _is_int(value.get("month")) or value["month"] not in period.offsets:
        problems.add(where, f"month musí být v rozsahu {period.offsets.start}..0")
    if allow_day and (not _is_int(value.get("day")) or not 1 <= value["day"] <= 31):
        problems.add(where, "day musí být 1..31")


# ── Načtení ──────────────────────────────────────────────────────────────────

def _load_file(story_dir: Path, name: str, problems: _Problems):
    path = story_dir / name
    if not path.is_file():
        problems.add(name, "soubor chybí")
        return None
    try:
        return load_jsonc(path)
    except Exception as e:  # noqa: BLE001 — chybu JSONC hlásíme jako nález
        problems.add(name, f"nejde načíst: {e}")
        return None


def _load_setup(story_dir: Path, period: Period, own: dict, problems: _Problems) -> tuple[dict[str, dict], tuple[str, ...]]:
    setup: dict[str, dict] = {}
    binders: list[str] = []
    setup_dir = story_dir / "setup"
    for path in sorted(setup_dir.glob("*.jsonc")) if setup_dir.is_dir() else []:
        where = f"setup/{path.name}"
        file = load_jsonc(path)
        table = file.get("table")
        if file.get("format") != SETUP_FORMAT:
            problems.add(where, f"format musí být {SETUP_FORMAT}")
        if table not in SETUP_TABLES or table != path.stem:
            problems.add(where, f"table musí být název souboru z {', '.join(SETUP_TABLES)}")
            continue
        rows = file.get("rows")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            problems.add(where, "rows musí být pole objektů")
            continue
        resolved_rows = []
        for i, row in enumerate(rows):
            resolved = {}
            for k, v in row.items():
                resolved[k] = period.start.isoformat() if v == PERIOD_START_PLACEHOLDER else v
            resolved_rows.append(resolved)
            if table == "bank_accounts":
                account = row.get("account_number")
                iban = row.get("iban")
                if not isinstance(account, str) or not account_valid(account):
                    problems.add(where, f"rows[{i}].account_number nemá mod 11")
                elif not isinstance(iban, str) or not iban_matches_account(iban, account):
                    problems.add(where, f"rows[{i}].iban neodpovídá účtu")
            if table == "vat_registrations" and row.get("vat_id") != own.get("vatId"):
                problems.add(where, f"rows[{i}].vat_id {row.get('vat_id')!r} ≠ vatId vlastní firmy")
            if table == "binders":
                if not _is_text(row.get("name")):
                    problems.add(where, f"rows[{i}].name chybí")
                else:
                    binders.append(row["name"])
        setup[table] = {"format": SETUP_FORMAT, "table": table, "rows": resolved_rows}
    return setup, tuple(binders)


def _party(key: str, kind: str, raw: dict, problems: _Problems, catalogs: Catalogs,
           rates: dict[str, dict[int, Decimal]], period: Period) -> Party:
    where = f"{kind} {key}"
    language = raw.get("language")
    currency = raw.get("currency")
    treatment = raw.get("vatTreatment")
    purposes = raw.get("sendPurposes")
    payment = raw.get("payment")
    template = raw.get("template")
    doc_number = raw.get("docNumber")
    invoices = raw.get("invoices")

    if kind == "customer":
        if language not in LANGUAGES:
            problems.add(where, f"language musí být jeden z {', '.join(LANGUAGES)}")
        if currency not in CURRENCIES:
            problems.add(where, f"currency musí být jedna z {', '.join(CURRENCIES)}")
        elif currency != "CZK":
            missing = [m for m in period.offsets if m not in rates.get(currency, {})]
            if missing:
                problems.add(where, f"chybí kurz {currency} pro měsíce {missing}")
        if treatment not in VAT_TREATMENTS:
            problems.add(where, f"vatTreatment musí být jeden z {', '.join(VAT_TREATMENTS)}")
        elif treatment == "reverseCharge" and (raw.get("country") == "cz" or not raw.get("vatId")):
            problems.add(where, "reverseCharge jen pro zahraničního plátce s vatId")
        elif treatment == "standard" and raw.get("country") != "cz":
            problems.add(where, "zahraniční odběratel ve fázi 1 jen s reverseCharge (služby B2B)")
        if not isinstance(purposes, list) or not purposes or any(p not in catalogs.send_purposes for p in purposes):
            problems.add(where, f"sendPurposes musí být neprázdný seznam z {', '.join(sorted(catalogs.send_purposes))}")
        if not isinstance(payment, dict) or payment.get("behaviour") not in PAYMENT_BEHAVIOURS:
            problems.add(where, f"payment.behaviour musí být jeden z {', '.join(PAYMENT_BEHAVIOURS)}")
        else:
            behaviour = payment["behaviour"]
            if behaviour == "late" and not (_is_int(payment.get("days")) and payment["days"] > 0):
                problems.add(where, "payment.days u late musí být kladné celé číslo")
            if behaviour == "partial" and not (_is_int(payment.get("pct")) and 0 < payment["pct"] < 100):
                problems.add(where, "payment.pct u partial musí být 1..99")
            # Doplatek částečné úhrady N dní po splatnosti (fáze 2a); bez něj nikdy.
            if "remainderDays" in payment and (behaviour != "partial"
                                               or not (_is_int(payment["remainderDays"]) and payment["remainderDays"] > 0)):
                problems.add(where, "payment.remainderDays jen u partial, kladné celé číslo")
            # Záměrně nespárované platby (D18): měsíc faktury + chyba VS.
            if "unmatched" in payment:
                unmatched = payment["unmatched"]
                if behaviour == "never" or not isinstance(unmatched, list) or not unmatched or any(
                        not isinstance(u, dict) or not _is_int(u.get("month")) or u["month"] not in period.offsets
                        or u.get("reference") not in PAYMENT_REFERENCE_FAULTS for u in unmatched):
                    problems.add(where, "payment.unmatched musí být pole {month v období, reference z "
                                        f"{', '.join(PAYMENT_REFERENCE_FAULTS)}}} u platícího odběratele")
        contact = raw.get("contact") or {}
        if not _is_text(contact.get("name")) or not contact.get("email"):
            problems.add(where, "kontakt odběratele potřebuje name a email (účel odeslání faktur)")
    elif kind == "supplier":
        # Jak platíme my (fáze 2a): bez `payment` = včas.
        if payment is not None and (not isinstance(payment, dict) or payment.get("behaviour") not in SUPPLIER_PAYMENT_BEHAVIOURS
                                    or (payment.get("behaviour") == "late"
                                        and not (_is_int(payment.get("days")) and payment["days"] > 0))):
            problems.add(where, f"payment musí být {{behaviour z {', '.join(SUPPLIER_PAYMENT_BEHAVIOURS)}, days u late}}")
        # Zahraniční dodavatel (#131 D28): jazyk papíru, měna faktur a
        # samovyměření; bez polí = česky, CZK, standard.
        language = language or "cs"
        currency = currency or "CZK"
        treatment = treatment or "standard"
        if language not in SUPPLIER_LANGUAGES:
            problems.add(where, f"language musí být jeden z {', '.join(SUPPLIER_LANGUAGES)}")
        if currency not in CURRENCIES:
            problems.add(where, f"currency musí být jedna z {', '.join(CURRENCIES)}")
        elif currency != "CZK":
            missing = [m for m in period.offsets if m not in rates.get(currency, {})]
            if missing:
                problems.add(where, f"chybí kurz {currency} pro měsíce {missing}")
        if treatment not in VAT_TREATMENTS:
            problems.add(where, f"vatTreatment musí být jeden z {', '.join(VAT_TREATMENTS)}")
        elif treatment == "reverseCharge" and (raw.get("country") == "cz" or not raw.get("vatId")):
            problems.add(where, "reverseCharge jen pro zahraničního plátce s vatId")
        elif treatment == "standard" and raw.get("country") != "cz" and invoices is not None:
            problems.add(where, "zahraniční dodavatel s fakturami jen s reverseCharge (služby z EU, samovyměření)")
        if invoices is not None:
            if template not in catalogs.invoice_templates:
                problems.add(where, f"template musí být z {', '.join(sorted(catalogs.invoice_templates))}")
            elif template_locale(template) != language:
                problems.add(where, f"template {template} je {template_locale(template)}, language je {language}")
            if not isinstance(doc_number, dict) or not _is_text(doc_number.get("pattern")) \
                    or "{seq" not in doc_number["pattern"] or not _is_int(doc_number.get("seqStart")):
                problems.add(where, "docNumber musí být {pattern s {seq…}, seqStart}")
            _supplier_invoices(where, invoices, problems, catalogs, period, reverse_charge=treatment == "reverseCharge")
            if not raw.get("bankAccount") or not (raw["bankAccount"].get("accountNumber") or raw["bankAccount"].get("iban")):
                problems.add(where, "dodavatel s fakturami potřebuje bankovní účet")

    return Party(
        key=key, kind=kind, identity=_identity(raw), language=language, currency=currency, vat_treatment=treatment,
        send_purposes=tuple(purposes) if isinstance(purposes, list) else (),
        payment=dict(payment) if isinstance(payment, dict) else {},
        template=template, accent=raw.get("accent"), doc_number=doc_number, invoices=invoices,
    )


def _supplier_invoices(where: str, rule: dict, problems: _Problems, catalogs: Catalogs, period: Period,
                       *, reverse_charge: bool = False) -> None:
    every = rule.get("every")
    months = rule.get("months")
    if (every is None) == (months is None):
        problems.add(where, "invoices: právě jedno z every | months")
    if every is not None and every not in ("month", "quarter"):
        problems.add(where, "invoices.every musí být month | quarter")
    if months is not None and (not isinstance(months, list) or not months
                               or any(not _is_int(m) or m not in period.offsets for m in months)):
        problems.add(where, f"invoices.months musí být měsíce z {period.offsets.start}..0")
    if not _is_int(rule.get("day")) or not 1 <= rule["day"] <= 31:
        problems.add(where, "invoices.day musí být 1..31")
    if not _is_int(rule.get("dueDays")) or rule["dueDays"] < 0:
        problems.add(where, "invoices.dueDays musí být celé číslo >= 0")
    if rule.get("anchorMonth") not in ANCHOR_MONTH_MODES:
        problems.add(where, f"invoices.anchorMonth musí být z {', '.join(ANCHOR_MONTH_MODES)}")
    if rule.get("period") not in (None, "previousMonth"):
        problems.add(where, "invoices.period může být jen previousMonth")
    if rule.get("rounding") not in (None, "czk"):
        problems.add(where, "invoices.rounding může být jen czk")
    rows = rule.get("rows")
    if not isinstance(rows, list) or not rows:
        problems.add(where, "invoices.rows musí být neprázdné pole")
        return
    for i, row in enumerate(rows):
        rw = f"{where} invoices.rows[{i}]"
        if row.get("item") not in catalogs.offer:
            problems.add(rw, f"item {row.get('item')!r} není kód nabídky účetních položek")
        if not _is_text(row.get("text")):
            problems.add(rw, "text chybí")
        has_amount = "amount" in row
        has_qty = "qty" in row or "unitPrice" in row
        if has_amount == has_qty:
            problems.add(rw, "právě jedno z amount | qty + unitPrice")
        elif has_amount and not _is_amount(row["amount"]):
            problems.add(rw, "amount musí být kladné číslo")
        elif has_qty:
            qty = row.get("qty")
            if not (_is_amount(qty) or _is_range(qty, kind=(int, Decimal)) or (qty == "sameAsPrevious" and i > 0)):
                problems.add(rw, "qty musí být kladné číslo, rozsah [lo, hi] nebo sameAsPrevious")
            if not _is_amount(row.get("unitPrice")):
                problems.add(rw, "unitPrice musí být kladné číslo")
            if row.get("unit") not in catalogs.units:
                problems.add(rw, f"unit musí být kód jednotky z {', '.join(sorted(catalogs.units))}")
        if row.get("vatPct") not in VAT_PCTS:
            problems.add(rw, f"vatPct musí být z {VAT_PCTS}")
        # Samovyměření (D28): `vatPct` je sazba, kterou přiznává příjemce
        # (doklad cz-217 / 21 %); papír dodavatele tiskne 0 % a klauzuli.
        supply_kind = row.get("supplyKind")
        if reverse_charge:
            if supply_kind not in SUPPLY_KINDS:
                problems.add(rw, f"u dodavatele s reverseCharge musí mít řádek supplyKind z {', '.join(SUPPLY_KINDS)}")
            if row.get("vatPct") != SELF_ASSESSED_VAT_PCT:
                problems.add(rw, f"samovyměření služeb z EU: vatPct {SELF_ASSESSED_VAT_PCT} (kód cz-217)")
        elif supply_kind is not None:
            problems.add(rw, "supplyKind jen u dodavatele s reverseCharge")


def _items(raw, problems: _Problems, catalogs: Catalogs) -> dict[str, Item]:
    items: dict[str, Item] = {}
    if not isinstance(raw, dict) or not raw:
        problems.add("items.jsonc", "musí být neprázdný objekt kód → položka")
        return items
    for code, spec in raw.items():
        where = f"items.jsonc {code}"
        if not ITEM_CODE_PATTERN.fullmatch(code):
            problems.add(where, "kód musí odpovídat ^[A-Z0-9][A-Z0-9-]{1,9}$")
        if code in catalogs.offer:
            problems.add(where, "kód koliduje s nabídkou účetních položek")
        if not isinstance(spec, dict):
            problems.add(where, "musí být objekt")
            continue
        if not _is_text(spec.get("name")):
            problems.add(where, "name chybí")
        if spec.get("kind") not in ITEM_KINDS:
            problems.add(where, f"kind musí být z {', '.join(ITEM_KINDS)}")
        if spec.get("unit") not in catalogs.units:
            problems.add(where, f"unit musí být kód jednotky z {', '.join(sorted(catalogs.units))}")
        if not _is_amount(spec.get("price")):
            problems.add(where, "price musí být kladné číslo")
        if spec.get("vatPct") not in VAT_PCTS:
            problems.add(where, f"vatPct musí být z {VAT_PCTS}")
        if spec.get("account") not in catalogs.chart_accounts:
            problems.add(where, f"account {spec.get('account')!r} není v osnově")
        if problems.items and problems.items[-1].startswith(where):
            continue
        items[code] = Item(code=code, name=spec["name"], kind=spec["kind"], unit=spec["unit"],
                           price=Decimal(spec["price"]), vat_pct=int(spec["vatPct"]), account=spec["account"])
    return items


def _work_orders_spec(raw, problems: _Problems, setup: dict[str, dict], customers: dict[str, Party]) -> None:
    """`story.invoicing.workOrders` (#131 fáze 2b, D32–D34): druh a řada
    paušálů ze setup/ (řada průběžná), název zakázky s {customer}, text
    dokladu s {období} a popis řádku předpisu v jazycích odběratelů."""
    where = "story.invoicing.workOrders"
    if not isinstance(raw, dict):
        problems.add(where, "musí být objekt {kind, series, title, docText, rowText}")
        return
    kinds = (setup.get("work_order_kinds") or {}).get("rows") or []
    series = (setup.get("work_order_series") or {}).get("rows") or []
    kind = next((r for r in kinds if isinstance(r, dict) and r.get("name") == raw.get("kind")), None)
    if kind is None:
        problems.add(where, f"kind {raw.get('kind')!r} není v setup/work_order_kinds.jsonc")
    elif kind.get("type") != "periodic":
        problems.add(where, f"druh {raw.get('kind')!r} musí mít typ periodic")
    row = next((r for r in series if isinstance(r, dict) and r.get("number_code") == raw.get("series")), None)
    if row is None:
        problems.add(where, f"series {raw.get('series')!r} není v setup/work_order_series.jsonc")
    else:
        if row.get("kind") != raw.get("kind"):
            problems.add(where, f"řada {raw.get('series')!r} musí patřit druhu {raw.get('kind')!r}")
        if row.get("reset_scope") != "none":
            problems.add(where, "řada zakázek musí být průběžná (reset_scope none, D34)")
    if not _is_text(raw.get("title")) or "{customer}" not in raw["title"]:
        problems.add(where, "title musí být text s {customer}")
    languages = sorted({c.language for c in customers.values() if c.language})
    for key in ("docText", "rowText"):
        texts = raw.get(key)
        if not isinstance(texts, dict) or not all(_is_text(texts.get(lang)) for lang in languages):
            problems.add(where, f"{key} musí nést text pro jazyky odběratelů: {', '.join(languages)}")
            continue
        if key == "docText" and not all(("{období}" in texts[lang] or "{period}" in texts[lang]) for lang in languages):
            problems.add(where, "docText musí obsahovat {období} nebo {period}")


def _billing(raw, problems: _Problems, customers: dict[str, Party], items: dict[str, Item], period: Period) -> tuple[dict, ...]:
    if not isinstance(raw, list) or not raw:
        problems.add("story.billing", "musí být neprázdné pole pravidel")
        return ()
    for i, rule in enumerate(raw):
        where = f"story.billing[{i}]"
        if not isinstance(rule, dict):
            problems.add(where, "musí být objekt")
            continue
        if rule.get("customer") not in customers:
            problems.add(where, f"customer {rule.get('customer')!r} není v partners.jsonc")
        if rule.get("item") not in items:
            problems.add(where, f"item {rule.get('item')!r} není v items.jsonc")
        every = rule.get("every")
        first_offset = None   # posun prvního fakturovaného období periodické zakázky
        until = rule.get("until")
        if every not in ("month", "quarter", "once"):
            problems.add(where, "every musí být month | quarter | once")
        elif every != "once":
            # Zahájení zakázky (D34, fáze 2d): posun měsíce od měsíce kotvy —
            # před oknem (smlouva běží roky), nebo v okně (zahájení i první
            # fakturované období v tom měsíci).
            since = rule.get("since")
            if not _is_int(since) or since > 0:
                problems.add(where, "since musí být posun měsíce zahájení zakázky (<= 0, D34)")
            else:
                try:
                    first_offset = period.offset_of(invoice_from(period, every, since))
                except ValueError as e:
                    problems.add(where, str(e))
            # Ukončení (fáze 2d, D43): posun měsíce v okně, ukončení poslední
            # den měsíce; ukončení před kotvou = Ukončeno, faktury jen za
            # období začínající nejpozději v tom měsíci.
            if until is not None:
                if not _is_int(until) or until not in period.offsets:
                    problems.add(where, f"until musí být posun měsíce ukončení v okně ({period.offsets.start}..0)")
                elif first_offset is not None and until < first_offset:
                    problems.add(where, f"until {until} je před prvním fakturovaným obdobím ({first_offset})")
                elif until < 0 and rule.get("conceptInAnchorMonth"):
                    problems.add(where, "conceptInAnchorMonth jen u zakázky, která v měsíci kotvy ještě fakturuje (until < 0 ne)")
        elif "since" in rule or "until" in rule:
            problems.add(where, "since a until jen u periodické zakázky (every month | quarter)")
        if not _is_amount(rule.get("price")):
            problems.add(where, "price musí být kladné číslo")
        if "qty" in rule and not (_is_int(rule["qty"]) and rule["qty"] >= 1):
            problems.add(where, "qty musí být celé číslo >= 1")
        if every == "once":
            if not _is_int(rule.get("month")) or rule["month"] not in period.offsets:
                problems.add(where, f"once: month musí být v rozsahu {period.offsets.start}..0")
            if not _is_int(rule.get("day")) or not 1 <= rule["day"] <= 31:
                problems.add(where, "once: day musí být 1..31")
        if rule.get("docType") not in (None, "invoiceIssued", "proformaIssued"):
            problems.add(where, "docType může být invoiceIssued | proformaIssued")
        if rule.get("docType") == "proformaIssued" and every != "once":
            problems.add(where, "zálohová faktura jen s every: once")
        if "priceFrom" in rule:
            pf = rule["priceFrom"]
            if not isinstance(pf, dict) or not _is_int(pf.get("month")) or pf["month"] not in period.offsets \
                    or not _is_amount(pf.get("price")):
                problems.add(where, "priceFrom musí být {month v období, price kladné}")
            elif first_offset is not None and not (first_offset < pf["month"] <= (until if _is_int(until) else 0)):
                problems.add(where, "priceFrom.month musí být po prvním fakturovaném období a nejpozději v měsíci ukončení")
        if "dueDays" in rule and not (_is_int(rule["dueDays"]) and rule["dueDays"] >= 0):
            problems.add(where, "dueDays musí být celé číslo >= 0")
        if "conceptInAnchorMonth" in rule and not isinstance(rule["conceptInAnchorMonth"], bool):
            problems.add(where, "conceptInAnchorMonth musí být bool")
    return tuple(raw)


def _adhoc(raw, problems: _Problems, customers: dict[str, Party], items: dict[str, Item], period: Period) -> tuple[dict, ...]:
    if raw is None:
        return ()
    if not isinstance(raw, list):
        problems.add("story.adhoc", "musí být pole")
        return ()
    for i, entry in enumerate(raw):
        where = f"story.adhoc[{i}]"
        if not isinstance(entry, dict):
            problems.add(where, "musí být objekt")
            continue
        if not _is_int(entry.get("month")) or entry["month"] not in period.offsets:
            problems.add(where, f"month musí být v rozsahu {period.offsets.start}..0")
        if not _is_int(entry.get("day")) or not 1 <= entry["day"] <= 31:
            problems.add(where, "day musí být 1..31")
        if entry.get("customer") not in customers:
            problems.add(where, f"customer {entry.get('customer')!r} není v partners.jsonc")
        if "project" in entry and not _is_text(entry["project"]):
            problems.add(where, "project musí být klíč projektu")
        rows = entry.get("rows")
        if not isinstance(rows, list) or not rows:
            problems.add(where, "rows musí být neprázdné pole")
        else:
            for j, row in enumerate(rows):
                rw = f"{where}.rows[{j}]"
                if row.get("item") not in items:
                    problems.add(rw, f"item {row.get('item')!r} není v items.jsonc")
                if ("qty" in row) == ("hours" in row):
                    problems.add(rw, "právě jedno z qty | hours")
                elif "qty" in row and not (_is_int(row["qty"]) and row["qty"] >= 1):
                    problems.add(rw, "qty musí být celé číslo >= 1")
                elif "hours" in row and not (_is_range(row["hours"]) and row["hours"][0] >= 1):
                    problems.add(rw, "hours musí být rozsah [lo, hi] celých hodin >= 1")
                if "text" in row and not _is_text(row["text"]):
                    problems.add(rw, "text musí být neprázdný text")
        if entry.get("payment") not in (None, "cash"):
            problems.add(where, "payment může být jen cash")
        if entry.get("state") not in (None, "concept"):
            problems.add(where, "state může být jen concept")
        if entry.get("state") == "concept" and entry.get("month") != 0:
            problems.add(where, "koncept jen v měsíci kotvy (month 0)")
    return tuple(raw)


def _projects(raw, problems: _Problems, setup: dict[str, dict], customers: dict[str, Party], suppliers: dict[str, Party],
              items: dict[str, Item], adhoc: tuple[dict, ...], catalogs: Catalogs, period: Period) -> dict:
    """`story.projects` (#131 fáze 2b, D36): druh typu project a průběžná
    řada ze setup/, projekty s klíčem, zákazníkem, názvem, zahájením před
    první fakturou a nákupem u dodavatele v měsíci, kdy fakturuje; nákupní
    řádky = prodávané položky zboží s textem a cenou; každý projekt má
    aspoň jednu ad hoc fakturu, nákup předchází poslední faktuře (dodávce)
    a každá nakupovaná položka se na fakturách projektu prodává."""
    if raw is None:
        return {}
    where = "story.projects"
    if not isinstance(raw, dict) or not isinstance(raw.get("items"), list):
        problems.add(where, "musí být objekt {kind, series, items[]}")
        return {}
    kinds = (setup.get("work_order_kinds") or {}).get("rows") or []
    series = (setup.get("work_order_series") or {}).get("rows") or []
    kind = next((r for r in kinds if isinstance(r, dict) and r.get("name") == raw.get("kind")), None)
    if kind is None:
        problems.add(where, f"kind {raw.get('kind')!r} není v setup/work_order_kinds.jsonc")
    elif kind.get("type") != "project":
        problems.add(where, f"druh {raw.get('kind')!r} musí mít typ project")
    row = next((r for r in series if isinstance(r, dict) and r.get("number_code") == raw.get("series")), None)
    if row is None:
        problems.add(where, f"series {raw.get('series')!r} není v setup/work_order_series.jsonc")
    elif row.get("kind") != raw.get("kind") or row.get("reset_scope") != "none":
        problems.add(where, f"řada {raw.get('series')!r} musí patřit druhu {raw.get('kind')!r} a být průběžná (D34)")

    keys: set[str] = set()
    for i, project in enumerate(raw["items"]):
        pw = f"{where}.items[{i}]"
        if not isinstance(project, dict):
            problems.add(pw, "musí být objekt")
            continue
        key = project.get("key")
        if not isinstance(key, str) or not PARTNER_KEY_PATTERN.fullmatch(key) or key in keys:
            problems.add(pw, "key musí být [a-z][a-z0-9]{1,11} a unikátní")
            continue
        keys.add(key)
        if project.get("customer") not in customers:
            problems.add(pw, f"customer {project.get('customer')!r} není v partners.jsonc")
        if not _is_text(project.get("title")):
            problems.add(pw, "title chybí")
        if not _is_int(project.get("startDaysBefore")) or project["startDaysBefore"] < 0:
            problems.add(pw, "startDaysBefore musí být celé číslo >= 0")
        invoices = [e for e in adhoc if isinstance(e, dict) and e.get("project") == key]
        if not invoices:
            problems.add(pw, "projekt nemá žádnou ad hoc fakturu s project")
        if any(e.get("customer") != project.get("customer") for e in invoices):
            problems.add(pw, "faktury projektu musí být na zákazníka projektu")
        sold = {row.get("item") for e in invoices for row in (e.get("rows") or []) if "qty" in row}
        delivery = max((month_date(period, e["month"], e["day"]) for e in invoices
                        if _is_int(e.get("month")) and e["month"] in period.offsets and _is_int(e.get("day"))), default=None)

        purchases = project.get("purchases")
        if not isinstance(purchases, dict):
            problems.add(pw, "purchases musí být objekt {supplier, month, rows}")
            continue
        supplier = suppliers.get(purchases.get("supplier"))
        rule = supplier.invoices if supplier is not None else None
        if rule is None:
            problems.add(pw, f"purchases.supplier {purchases.get('supplier')!r} není dodavatel s pravidlem invoices")
            continue
        month = purchases.get("month")
        if not _is_int(month) or month not in period.offsets:
            problems.add(pw, f"purchases.month musí být v rozsahu {period.offsets.start}..0")
            continue
        months = rule.get("months")
        if (months is not None and month not in months) or (month == 0 and rule.get("anchorMonth") == "skip") \
                or (rule.get("every") == "quarter" and period.month_start(month).month not in (1, 4, 7, 10)):
            problems.add(pw, f"dodavatel {purchases['supplier']!r} v měsíci {month} nefakturuje")
        purchase_day = month_date(period, month, int(rule["day"])) if _is_int(rule.get("day")) else None
        if purchase_day is not None and delivery is not None and purchase_day >= delivery:
            problems.add(pw, f"nákup {purchase_day} musí předcházet dodávce {delivery}")
        rows = purchases.get("rows")
        if not isinstance(rows, list) or not rows:
            problems.add(pw, "purchases.rows musí být neprázdné pole")
            continue
        for j, prow in enumerate(rows):
            rw = f"{pw}.purchases.rows[{j}]"
            item = items.get(prow.get("item"))
            if item is None or item.kind != "stock":
                problems.add(rw, f"item {prow.get('item')!r} musí být zboží z items.jsonc")
            elif item.code not in sold:
                problems.add(rw, f"položka {item.code} se na fakturách projektu neprodává — množství nákupu nemá odkud vzít")
            if not _is_text(prow.get("text")):
                problems.add(rw, "text chybí")
            if not _is_amount(prow.get("unitPrice")):
                problems.add(rw, "unitPrice musí být kladné číslo")
            if "offerItem" in prow and prow["offerItem"] not in catalogs.offer:
                problems.add(rw, f"offerItem {prow['offerItem']!r} není kód nabídky účetních položek")
    for i, entry in enumerate(adhoc):
        if isinstance(entry, dict) and "project" in entry and entry["project"] not in keys:
            problems.add(f"story.adhoc[{i}]", f"project {entry['project']!r} není v story.projects")
    return raw


def _assets(raw, problems: _Problems, setup: dict[str, dict], suppliers: dict[str, Party], catalogs: Catalogs,
            period: Period) -> dict:
    """`story.assets` (#131 fáze 2c, D41): skupina typů a typ ze setup/ (typ
    dlouhodobý hmotný s úplnou účetní skupinou — odepisovaný majetek jde
    potvrdit jen s účtem odpisů a oprávek, D57), nastavení měsíčních odpisů
    a pole Majetek na dokladech, karty s jedinečným inventárním číslem,
    rovnoměrnou metodou v odpisové skupině ze world.assets a účetně jako
    daňově; nákup = samostatná faktura tuzemského dodavatele v měsíci, kdy
    podle svého pravidla nefakturuje; zařazení pár dní po dodání, nejpozději
    v den kotvy; náklady = dodavatel s pravidlem faktur."""
    if raw is None:
        return {}
    where = "story.assets"
    if not isinstance(raw, dict) or not isinstance(raw.get("items"), list) or not raw["items"]:
        problems.add(where, "musí být objekt {typeGroup, type, items[]}")
        return {}
    for table in ASSET_SETUP_TABLES:
        if table not in setup:
            problems.add(f"setup/{table}.jsonc", "chybí (příběh má majetek)")
    groups = {r.get("code"): r for r in (setup.get("asset_accounting_groups") or {}).get("rows") or [] if isinstance(r, dict)}
    type_groups = [r.get("name") for r in (setup.get("asset_type_groups") or {}).get("rows") or [] if isinstance(r, dict)]
    types = {r.get("name"): r for r in (setup.get("asset_types") or {}).get("rows") or [] if isinstance(r, dict)}
    if raw.get("typeGroup") not in type_groups:
        problems.add(where, f"typeGroup {raw.get('typeGroup')!r} není v setup/asset_type_groups.jsonc")
    type_row = types.get(raw.get("type"))
    if type_row is None:
        problems.add(where, f"type {raw.get('type')!r} není v setup/asset_types.jsonc")
    else:
        if type_row.get("type_group") != raw.get("typeGroup"):
            problems.add(where, f"typ {raw['type']!r} musí patřit do skupiny typů {raw.get('typeGroup')!r}")
        if type_row.get("default_category") != ASSET_CATEGORY:
            problems.add(where, f"typ {raw['type']!r} musí mít výchozí druh {ASSET_CATEGORY} (dlouhodobý hmotný)")
        group_code = type_row.get("default_accounting_group")
        group = groups.get(group_code)
        if group is None:
            problems.add(where, f"výchozí účetní skupina typu {group_code!r} není v setup/asset_accounting_groups.jsonc")
        elif not all(_is_text(group.get(column)) for column in ASSET_GROUP_ACCOUNTS):
            problems.add(where, f"účetní skupina {group_code!r} musí mít účet majetku, pořízení, oprávek a odpisů (odepisovaný majetek, D57)")
    settings = {r.get("key"): r.get("value") for r in (setup.get("settings") or {}).get("rows") or [] if isinstance(r, dict)}
    for key, value in ASSET_SETTINGS.items():
        if settings.get(key) != value:
            problems.add("setup/settings.jsonc", f"{key} musí být {value!r} (měsíční účetní odpisy, pole Majetek na dokladech)")

    keys: set[str] = set()
    numbers: set[str] = set()
    straight_rules = {rate.code for rate in catalogs.asset_rules.straight_rates}
    for i, item in enumerate(raw["items"]):
        iw = f"{where}.items[{i}]"
        if not isinstance(item, dict):
            problems.add(iw, "musí být objekt")
            continue
        key = item.get("key")
        if not isinstance(key, str) or not PARTNER_KEY_PATTERN.fullmatch(key) or key in keys:
            problems.add(iw, "key musí být [a-z][a-z0-9]{1,11} a unikátní")
            continue
        keys.add(key)
        number = item.get("number")
        if not isinstance(number, str) or not ASSET_NUMBER_PATTERN.fullmatch(number) or number in numbers:
            problems.add(iw, "number musí být inventární číslo [A-Z0-9][A-Z0-9-]{1,19} a unikátní")
        else:
            numbers.add(number)
        if not _is_text(item.get("name")):
            problems.add(iw, "name chybí")
        if item.get("taxMethod") != ASSET_TAX_METHOD:
            problems.add(iw, f"taxMethod umí generátor jen {ASSET_TAX_METHOD} (rovnoměrný)")
        if item.get("taxRule") not in straight_rules:
            problems.add(iw, f"taxRule {item.get('taxRule')!r} není odpisová skupina rovnoměrné metody ze world.assets")
        if item.get("accMethod") != ASSET_ACC_METHOD:
            problems.add(iw, f"accMethod umí generátor jen {ASSET_ACC_METHOD} (účetně jako daňově)")

        purchase = item.get("purchase")
        if not isinstance(purchase, dict):
            problems.add(iw, "purchase musí být objekt {supplier, month, day, unitPrice, text?, qty?, rows?}")
            continue
        pw = f"{iw}.purchase"
        supplier = suppliers.get(purchase.get("supplier"))
        rule = supplier.invoices if supplier is not None else None
        month = purchase.get("month")
        day = purchase.get("day")
        if rule is None:
            problems.add(pw, f"supplier {purchase.get('supplier')!r} není dodavatel s pravidlem invoices")
        elif supplier.vat_treatment == "reverseCharge" or (supplier.currency or "CZK") != "CZK":
            problems.add(pw, "nákup majetku jen od tuzemského dodavatele v CZK")
        if not _is_int(month) or month not in period.offsets:
            problems.add(pw, f"month musí být v rozsahu {period.offsets.start}..0")
        elif rule is not None:
            regular = rule.get("every") is not None or (isinstance(rule.get("months"), list) and month in rule["months"])
            if regular:
                problems.add(pw, f"dodavatel {purchase['supplier']!r} v měsíci {month} fakturuje podle pravidla — nákup majetku je samostatná faktura v jiném měsíci")
            if month == 0 and rule.get("anchorMonth") == "skip":
                problems.add(pw, "v měsíci kotvy dodavatel fakturu nemá (anchorMonth skip)")
        if not _is_int(day) or not 1 <= day <= 31:
            problems.add(pw, "day musí být 1..31")
        if not _is_amount(purchase.get("unitPrice")):
            problems.add(pw, "unitPrice musí být kladné číslo")
        if "qty" in purchase and not (_is_int(purchase["qty"]) and purchase["qty"] >= 1):
            problems.add(pw, "qty musí být celé číslo >= 1")
        if "text" in purchase and not _is_text(purchase["text"]):
            problems.add(pw, "text musí být neprázdný text")
        rows = purchase.get("rows", [])
        if not isinstance(rows, list):
            problems.add(pw, "rows musí být pole")
            rows = []
        for j, prow in enumerate(rows):
            rw = f"{pw}.rows[{j}]"
            if not isinstance(prow, dict):
                problems.add(rw, "musí být objekt")
                continue
            if not _is_text(prow.get("text")):
                problems.add(rw, "text chybí")
            if not _is_amount(prow.get("unitPrice")):
                problems.add(rw, "unitPrice musí být kladné číslo")
            if "qty" in prow and not (_is_int(prow["qty"]) and prow["qty"] >= 1):
                problems.add(rw, "qty musí být celé číslo >= 1")
            if "offerItem" in prow and prow["offerItem"] not in catalogs.offer:
                problems.add(rw, f"offerItem {prow['offerItem']!r} není kód nabídky účetních položek")

        activation = item.get("activation")
        if not isinstance(activation, dict) or not _is_int(activation.get("daysAfterDelivery")) or activation["daysAfterDelivery"] < 0:
            problems.add(iw, "activation musí být {daysAfterDelivery >= 0}")
        elif _is_int(month) and month in period.offsets and _is_int(day) and 1 <= day <= 31:
            activated = month_date(period, month, day) + timedelta(days=int(activation["daysAfterDelivery"]))
            if activated > period.anchor:
                problems.add(iw, f"zařazení {activated} je za kotvou {period.anchor}")
        costs = item.get("costs")
        if costs is not None:
            cost_supplier = suppliers.get(costs.get("supplier")) if isinstance(costs, dict) else None
            if cost_supplier is None or cost_supplier.invoices is None:
                problems.add(f"{iw}.costs", "supplier musí být dodavatel s pravidlem invoices")
    return raw


def _cash(raw_register, raw_expenses, problems: _Problems, items: dict[str, Item], catalogs: Catalogs) -> tuple[dict, tuple[dict, ...]]:
    register = raw_register if isinstance(raw_register, dict) else {}
    where = "story.cashRegister"
    if not isinstance(raw_register, dict):
        problems.add(where, "musí být objekt {days, items, qty}")
    else:
        days = register.get("days")
        if not isinstance(days, list) or not days or any(not _is_int(d) or not 1 <= d <= 31 for d in days) \
                or days != sorted(days) or len(set(days)) != len(days):
            problems.add(where, "days musí být rostoucí seznam dnů 1..31")
        codes = register.get("items")
        if not isinstance(codes, list) or not codes or any(c not in items for c in codes):
            problems.add(where, "items musí být neprázdný seznam kódů z items.jsonc")
        elif any(items[c].kind != "stock" for c in codes):
            problems.add(where, "prodejky jen nad zbožím (kind stock)")
        if not _is_range(register.get("qty")) or register["qty"][0] < 1:
            problems.add(where, "qty musí být rozsah [lo, hi] >= 1")
    expenses = raw_expenses if isinstance(raw_expenses, list) else []
    if not isinstance(raw_expenses, list):
        problems.add("story.cashExpenses", "musí být pole")
    for i, entry in enumerate(expenses):
        ew = f"story.cashExpenses[{i}]"
        if not isinstance(entry, dict):
            problems.add(ew, "musí být objekt")
            continue
        if not _is_int(entry.get("day")) or not 1 <= entry["day"] <= 31:
            problems.add(ew, "day musí být 1..31")
        if entry.get("item") not in catalogs.offer:
            problems.add(ew, f"item {entry.get('item')!r} není kód nabídky účetních položek")
        if not _is_text(entry.get("text")):
            problems.add(ew, "text chybí")
        if not _is_range(entry.get("amount"), kind=(int, Decimal)) or Decimal(entry["amount"][0]) <= 0:
            problems.add(ew, "amount musí být rozsah [lo, hi] kladných částek")
        if not _is_amount(entry.get("step")):
            problems.add(ew, "step musí být kladné číslo")
        vat_none = entry.get("vat") == "none"
        if vat_none == ("vatPct" in entry):
            problems.add(ew, "právě jedno z vat: none | vatPct")
        elif not vat_none and entry["vatPct"] not in VAT_PCTS:
            problems.add(ew, f"vatPct musí být z {VAT_PCTS}")
    return register, tuple(expenses)


def _payroll(raw, problems: _Problems, catalogs: Catalogs, period: Period) -> dict:
    where = "story.payroll"
    if not isinstance(raw, dict):
        problems.add(where, "musí být objekt")
        return {}
    if not _is_int(raw.get("day")) or not 1 <= raw["day"] <= 31:
        problems.add(where, "day musí být 1..31")
    if not _is_text(raw.get("text")):
        problems.add(where, "text chybí")
    figures = raw.get("figures")
    if not isinstance(figures, dict) or not figures or any(not _is_amount(v) for v in figures.values()):
        problems.add(where, "figures musí být objekt název → kladná částka")
        return raw
    rows = raw.get("rows")
    if not isinstance(rows, list) or not rows:
        problems.add(where, "rows musí být neprázdné pole")
        return raw
    for i, row in enumerate(rows):
        rw = f"{where}.rows[{i}]"
        if row.get("account") not in catalogs.chart_accounts:
            problems.add(rw, f"account {row.get('account')!r} není v osnově")
        if row.get("side") not in ("debit", "credit"):
            problems.add(rw, "side musí být debit | credit")
        if row.get("amount") not in figures:
            problems.add(rw, f"amount odkazuje na neznámou částku {row.get('amount')!r}")
        if not _is_text(row.get("text")):
            problems.add(rw, "text chybí")
    overrides = raw.get("months") or {}
    if not isinstance(overrides, dict):
        problems.add(where, "months musí být objekt měsíc → částky")
        overrides = {}
    for key, values in overrides.items():
        try:
            month = int(key)
        except (TypeError, ValueError):
            month = None
        if month is None or month not in period.offsets:
            problems.add(where, f"months.{key}: měsíc mimo období")
            continue
        if not isinstance(values, dict) or any(k not in figures or not _is_amount(v) for k, v in values.items()):
            problems.add(where, f"months.{key}: jen známé částky, kladné")
    if problems.items and any(p.startswith(where) for p in problems.items):
        return raw
    for month in period.offsets:
        amounts = {**figures, **(overrides.get(str(month)) or {})}
        debit = sum(Decimal(amounts[r["amount"]]) for r in rows if r["side"] == "debit")
        credit = sum(Decimal(amounts[r["amount"]]) for r in rows if r["side"] == "credit")
        if debit != credit:
            problems.add(where, f"měsíc {month}: MD {debit} ≠ D {credit}")
    return raw


def _own_bank_accounts(own_raw: dict, setup: dict[str, dict], problems: _Problems) -> dict[str, str]:
    """`story.own.bankAccounts`: měna → kód účtu ze setup/ (CZK povinná,
    měna účtu musí sedět)."""
    where = "story.own.bankAccounts"
    raw = own_raw.get("bankAccounts")
    if not isinstance(raw, dict) or "CZK" not in raw:
        problems.add(where, f"musí být objekt měna → kód účtu ze setup/bank_accounts.jsonc, CZK povinná (měny z {', '.join(CURRENCIES)})")
        return {}
    rows = {r.get("code"): r for r in (setup.get("bank_accounts") or {}).get("rows", [])}
    out: dict[str, str] = {}
    for currency, code in raw.items():
        if currency not in CURRENCIES or not _is_text(code):
            problems.add(where, f"{currency}: měna z {', '.join(CURRENCIES)} a kód účtu")
        elif code not in rows:
            problems.add(where, f"kód {code!r} není v setup/bank_accounts.jsonc")
        elif str(rows[code].get("currency") or "czk").upper() != currency:
            problems.add(where, f"účet {code} je v měně {str(rows[code].get('currency') or 'czk').upper()}, ne {currency}")
        else:
            out[currency] = code
    return out


def _bank(raw, problems: _Problems, setup: dict[str, dict], payroll: dict, catalogs: Catalogs,
          parties_raw: dict[str, dict], rates: dict[str, dict[int, Decimal]], period: Period) -> dict:
    """`story.bank` (fáze 2a): počáteční zůstatek a poplatek per účet, účty
    otevíracího dokladu a clearingu, platby mezd a odvodů (D22) — protiúčet
    nesmí patřit nikomu v sadě, jinak by platba dostala partnera; převody
    mezi vlastními účty (`transfers`, #131 D26) z účtu v cizí měně s kurzy
    na účet v domácí měně."""
    where = "story.bank"
    if not isinstance(raw, dict):
        problems.add(where, "musí být objekt {accounts, openingAccount, clearingAccount, settlementText, payroll}")
        return {}
    rows = {r.get("code"): r for r in (setup.get("bank_accounts") or {}).get("rows", [])}
    accounts = raw.get("accounts")
    if not isinstance(accounts, dict) or set(accounts) != set(rows):
        problems.add(where, f"accounts musí mít právě účty ze setup/bank_accounts.jsonc ({', '.join(str(c) for c in rows)})")
    else:
        for code, spec in accounts.items():
            aw = f"{where}.accounts.{code}"
            opening = spec.get("openingBalance") if isinstance(spec, dict) else None
            if not _is_amount(opening, positive=False) or Decimal(opening) < 0:
                problems.add(aw, "openingBalance musí být částka >= 0")
                continue
            if str(rows[code].get("currency") or "czk").lower() != "czk" and Decimal(opening) != 0:
                problems.add(aw, "účet v cizí měně začíná na nule (otevírací doklad jen v domácí měně)")
            if not _is_text(rows[code].get("accounting_account")):
                problems.add(aw, "účet potřebuje accounting_account (analytika 221) v setup/bank_accounts.jsonc")
            fee = spec.get("fee")
            if fee is not None and (not isinstance(fee, dict) or not _is_amount(fee.get("amount")) or not _is_text(fee.get("text"))):
                problems.add(aw, "fee musí být {amount kladné, text}")
    for key in ("openingAccount", "clearingAccount"):
        if raw.get(key) not in catalogs.chart_accounts:
            problems.add(where, f"{key} {raw.get(key)!r} není v osnově")
    if not _is_text(raw.get("settlementText")) or "{text}" not in raw["settlementText"]:
        problems.add(where, "settlementText musí být text s {text}")

    # Převody mezi vlastními účty (D26): k `day` každého měsíce se z `from`
    # (cizí měna) převede zůstatek nad `reserve` zaokrouhlený dolů na `step`,
    # když dělá aspoň `minAmount`; `to` je účet v domácí měně.
    transfers = raw.get("transfers")
    if transfers is not None:
        if not isinstance(transfers, list):
            problems.add(where, "transfers musí být pole pravidel {from, to, day, reserve, minAmount, step, text}")
            transfers = []
        for i, rule in enumerate(transfers):
            tw = f"{where}.transfers[{i}]"
            if not isinstance(rule, dict):
                problems.add(tw, "musí být objekt {from, to, day, reserve, minAmount, step, text}")
                continue
            src, dst = rule.get("from"), rule.get("to")
            if src not in rows or dst not in rows or src == dst:
                problems.add(tw, f"from a to musí být dva různé účty ze setup/bank_accounts.jsonc ({', '.join(str(c) for c in rows)})")
            else:
                src_currency = str(rows[src].get("currency") or "czk").upper()
                dst_currency = str(rows[dst].get("currency") or "czk").upper()
                if src_currency == "CZK" or dst_currency != "CZK":
                    problems.add(tw, "převod jde z účtu v cizí měně na účet v CZK")
                elif any(m not in rates.get(src_currency, {}) for m in period.offsets):
                    problems.add(tw, f"chybí kurz {src_currency} pro některý měsíc období")
            if not _is_int(rule.get("day")) or not 1 <= rule["day"] <= 31:
                problems.add(tw, "day musí být 1..31")
            if not _is_amount(rule.get("reserve"), positive=False) or Decimal(rule["reserve"]) < 0:
                problems.add(tw, "reserve musí být částka >= 0")
            if not _is_amount(rule.get("minAmount")):
                problems.add(tw, "minAmount musí být kladná částka")
            if not _is_amount(rule.get("step")):
                problems.add(tw, "step musí být kladná částka")
            if not _is_text(rule.get("text")):
                problems.add(tw, "text chybí")

    figures = payroll.get("figures") if isinstance(payroll.get("figures"), dict) else {}
    liabilities = {r.get("amount") for r in (payroll.get("rows") or []) if isinstance(r, dict) and r.get("side") == "credit"}
    taken: set[str] = set()
    for party in parties_raw.values():
        bank = party.get("bankAccount") or {}
        taken |= _account_forms(bank.get("accountNumber")) | _account_forms(bank.get("iban"))
    for row in rows.values():
        taken |= _account_forms(row.get("account_number")) | _account_forms(row.get("iban"))
    rules = raw.get("payroll")
    if not isinstance(rules, list) or not rules:
        problems.add(where, "payroll musí být neprázdné pole plateb {amount, day, text, counterparty}")
        return raw
    seen: set[str] = set()
    for i, rule in enumerate(rules):
        rw = f"{where}.payroll[{i}]"
        if not isinstance(rule, dict):
            problems.add(rw, "musí být objekt")
            continue
        amount = rule.get("amount")
        if amount not in figures or amount not in liabilities:
            problems.add(rw, f"amount {amount!r} musí být částka mezd s řádkem na straně D (závazek)")
        elif amount in seen:
            problems.add(rw, f"amount {amount!r} podruhé")
        seen.add(amount)
        if not _is_int(rule.get("day")) or not 1 <= rule["day"] <= 31:
            problems.add(rw, "day musí být 1..31")
        if not _is_text(rule.get("text")):
            problems.add(rw, "text chybí")
        counterparty = rule.get("counterparty")
        if not isinstance(counterparty, dict) or not _is_text(counterparty.get("account")) or not _is_text(counterparty.get("name")):
            problems.add(rw, "counterparty musí být {account, name}")
        elif _account_forms(counterparty["account"]) & taken:
            problems.add(rw, f"counterparty.account {counterparty['account']} patří osobě nebo vlastnímu účtu "
                             "— platba by dostala partnera (D22)")
    return raw


def _registry(raw, problems: _Problems, parties: dict[str, Party], binders: tuple[str, ...],
              catalogs: Catalogs, period: Period) -> tuple[dict, ...]:
    if raw is None:
        return ()
    if not isinstance(raw, list):
        problems.add("registry.jsonc", "musí být pole")
        return ()
    titles: set[str] = set()
    for i, doc in enumerate(raw):
        where = f"registry.jsonc[{i}]"
        if not isinstance(doc, dict):
            problems.add(where, "musí být objekt")
            continue
        kind = doc.get("docType")
        if kind not in catalogs.registry_kinds:
            problems.add(where, f"docType musí být z {', '.join(sorted(catalogs.registry_kinds))}")
        if doc.get("template") not in catalogs.registry_templates:
            problems.add(where, f"template musí být šablona z demo/datasets/templates ({', '.join(sorted(catalogs.registry_templates))})")
        if doc.get("binder") not in binders:
            problems.add(where, f"binder {doc.get('binder')!r} není v setup/binders.jsonc")
        if doc.get("partner") not in parties or doc.get("partner") == "own":
            problems.add(where, f"partner {doc.get('partner')!r} není v partners.jsonc")
        if not _is_text(doc.get("title")):
            problems.add(where, "title chybí")
        elif doc["title"] in titles:
            problems.add(where, f"title {doc['title']!r} není jedinečný")
        else:
            titles.add(doc["title"])
        if not _is_text(doc.get("refNumber")):
            problems.add(where, "refNumber chybí")
        if not _is_text(doc.get("summary")):
            problems.add(where, "summary chybí")
        _relative_date(doc.get("created"), f"{where}.created", period, problems)
        if isinstance(doc.get("created"), dict) and not re.fullmatch(r"[0-2]\d:[0-5]\d", str(doc["created"].get("time", ""))):
            problems.add(where, "created.time musí být HH:MM")
        _relative_date(doc.get("validFrom"), f"{where}.validFrom", period, problems)
        valid_to = doc.get("validTo")
        if not isinstance(valid_to, dict) or sum(k in valid_to for k in ("afterAnchorDays", "month", "years")) != 1:
            problems.add(where, "validTo musí být {afterAnchorDays} | {month, day} | {years}")
        elif "afterAnchorDays" in valid_to and not _is_int(valid_to["afterAnchorDays"]):
            problems.add(where, "validTo.afterAnchorDays musí být celé číslo")
        elif "years" in valid_to and not (_is_int(valid_to["years"]) and valid_to["years"] >= 1):
            problems.add(where, "validTo.years musí být celé číslo >= 1")
        elif "month" in valid_to:
            _relative_date(valid_to, f"{where}.validTo", period, problems)
        fields = doc.get("kindFields")
        if not isinstance(fields, dict):
            problems.add(where, "kindFields musí být objekt")
        elif kind in catalogs.registry_kinds:
            allowed = catalogs.registry_kinds[kind]
            unknown = [k for k in fields if k not in allowed]
            if unknown:
                problems.add(where, f"kindFields mimo druh {kind}: {', '.join(unknown)} (povolené: {', '.join(allowed)})")
            for promoted in ("contractNumber", "policyNumber", "validFrom", "validTo"):
                if promoted in fields:
                    problems.add(where, f"kindFields.{promoted} doplní generátor z obálky — nevyplňovat")
    return tuple(raw)


def _mail(raw, problems: _Problems, parties: dict[str, Party]) -> tuple[dict, ...]:
    if raw is None:
        return ()
    if not isinstance(raw, list):
        problems.add("mail.jsonc", "musí být pole")
        return ()
    ids: set[str] = set()
    for i, msg in enumerate(raw):
        where = f"mail.jsonc[{i}]"
        if not isinstance(msg, dict):
            problems.add(where, "musí být objekt")
            continue
        if msg.get("kind") != "other":
            problems.add(where, "fáze 1 umí jen kind: other (návrhy faktur vznikají z pravidel dodavatelů)")
        if not isinstance(msg.get("messageId"), str) or not MESSAGE_ID_PATTERN.fullmatch(msg["messageId"]):
            problems.add(where, "messageId musí být A-Z0-9- do 30 znaků")
        elif msg["messageId"] in ids:
            problems.add(where, f"messageId {msg['messageId']} není jedinečné")
        else:
            ids.add(msg["messageId"])
        if msg.get("party") not in parties or msg.get("party") == "own":
            problems.add(where, f"party {msg.get('party')!r} není v partners.jsonc")
        for key in ("subject", "body", "title"):
            if not _is_text(msg.get(key)):
                problems.add(where, f"{key} chybí")
        received = msg.get("received")
        if not isinstance(received, dict) or not _is_int(received.get("daysBeforeAnchor")) or received["daysBeforeAnchor"] < 0 \
                or not re.fullmatch(r"[0-2]\d:[0-5]\d", str(received.get("time", ""))):
            problems.add(where, "received musí být {daysBeforeAnchor >= 0, time HH:MM}")
        attention = msg.get("attention")
        if attention not in ATTENTION_KINDS:
            problems.add(where, f"attention musí být z {', '.join(ATTENTION_KINDS)}")
        if attention == "action":
            if not _is_text(msg.get("actionNote")):
                problems.add(where, "actionNote u action chybí")
            if "dueInDays" in msg and not (_is_int(msg["dueInDays"]) and msg["dueInDays"] >= 0):
                problems.add(where, "dueInDays musí být celé číslo >= 0")
        elif "actionNote" in msg or "dueInDays" in msg:
            problems.add(where, "actionNote a dueInDays jen u attention action")
    return tuple(raw)


def load_story(story_dir: Path, catalogs: Catalogs, *, anchor: date | None = None,
               reserved_demo: dict | None = None, reserved_corpus: dict | None = None) -> Story:
    """Načte a zvaliduje příběh; `anchor` přepíše kotvu ze `story.jsonc`
    (`build.py --anchor`). `reserved_demo` = `demo/invoices/suppliers.jsonc`,
    `reserved_corpus` = `tests/corpus/cz/identities.jsonc` — identity se
    nesmí překrývat. Všechny nálezy najednou jako `StoryError`."""
    problems = _Problems()
    story_raw = _load_file(story_dir, "story.jsonc", problems)
    partners_raw = _load_file(story_dir, "partners.jsonc", problems)
    items_raw = _load_file(story_dir, "items.jsonc", problems)
    registry_raw = _load_file(story_dir, "registry.jsonc", problems)
    mail_raw = _load_file(story_dir, "mail.jsonc", problems)
    if story_raw is None or partners_raw is None or items_raw is None:
        raise StoryError("; ".join(problems.items))

    # ── story.jsonc: hlavička a kotva ──
    name = story_raw.get("name")
    if not isinstance(name, str) or not NAME_PATTERN.fullmatch(name):
        problems.add("story.name", "musí odpovídat [a-z0-9][a-z0-9._-]* (max 64 znaků)")
    title = story_raw.get("title")
    if not _is_text(title):
        problems.add("story.title", "chybí")
    description = story_raw.get("description")
    if description is not None and not _is_text(description):
        problems.add("story.description", "musí být text nebo null")
    anchor_raw = story_raw.get("anchor")
    try:
        story_anchor = date.fromisoformat(anchor_raw) if isinstance(anchor_raw, str) else None
    except ValueError:
        story_anchor = None
    if story_anchor is None:
        problems.add("story.anchor", "musí být datum YYYY-MM-DD")
        story_anchor = date(2026, 10, 20)
    effective_anchor = anchor or story_anchor
    months_before = story_raw.get("monthsBefore")
    if not _is_int(months_before) or not 1 <= months_before <= 24:
        problems.add("story.monthsBefore", "musí být 1..24")
        months_before = 9
    period = period_for(effective_anchor, months_before)

    # ── vlastní firma a setup ──
    own_raw = story_raw.get("own")
    if not isinstance(own_raw, dict):
        problems.add("story.own", "musí být objekt identity")
        own_raw = {}
    if not _is_text(own_raw.get("cashDeskCode")):
        problems.add("story.own.cashDeskCode", "musí být kód z setup/")
    if own_raw.get("language") not in LANGUAGES:
        problems.add("story.own.language", f"musí být jeden z {', '.join(LANGUAGES)}")
    setup, binders = _load_setup(story_dir, period, own_raw, problems)
    for table in ("settings", "bank_accounts", "cash_desks", "vat_registrations", "binders"):
        if table not in setup:
            problems.add(f"setup/{table}.jsonc", "chybí")
    bank_accounts = _own_bank_accounts(own_raw, setup, problems)
    desk_codes = [r.get("code") for r in (setup.get("cash_desks") or {}).get("rows", [])]
    if own_raw.get("cashDeskCode") not in desk_codes:
        problems.add("story.own.cashDeskCode", f"kód {own_raw.get('cashDeskCode')!r} není v setup/cash_desks.jsonc")

    # ── kurzy ──
    rates: dict[str, dict[int, Decimal]] = {}
    rates_raw = story_raw.get("rates") or {}
    if not isinstance(rates_raw, dict):
        problems.add("story.rates", "musí být objekt měna → {měsíc: kurz}")
        rates_raw = {}
    for currency, by_month in rates_raw.items():
        if currency not in CURRENCIES or currency == "CZK" or not isinstance(by_month, dict):
            problems.add("story.rates", f"{currency}: jen cizí měny z {', '.join(CURRENCIES)} s objektem měsíc → kurz")
            continue
        table: dict[int, Decimal] = {}
        for key, rate in by_month.items():
            try:
                month = int(key)
            except (TypeError, ValueError):
                problems.add("story.rates", f"{currency}: klíč {key!r} není měsíc")
                continue
            if month not in period.offsets:
                problems.add("story.rates", f"{currency}: měsíc {month} mimo období")
            elif not _is_amount(rate):
                problems.add("story.rates", f"{currency}: kurz měsíce {month} musí být kladné číslo")
            else:
                table[month] = Decimal(rate)
        rates[currency] = table

    # ── partneři ──
    verified_at = partners_raw.get("verifiedAt")
    try:
        date.fromisoformat(verified_at)
    except (TypeError, ValueError):
        problems.add("partners.verifiedAt", "není ISO datum")
        verified_at = "1970-01-01"
    customers_raw = partners_raw.get("customers")
    suppliers_raw = partners_raw.get("suppliers")
    if not isinstance(customers_raw, dict) or not customers_raw:
        problems.add("partners.customers", "musí být neprázdný objekt")
        customers_raw = {}
    if not isinstance(suppliers_raw, dict) or not suppliers_raw:
        problems.add("partners.suppliers", "musí být neprázdný objekt")
        suppliers_raw = {}
    all_partners = {**customers_raw, **suppliers_raw}
    if len(all_partners) != len(customers_raw) + len(suppliers_raw):
        problems.add("partners", "klíč odběratele a dodavatele se nesmí shodovat")
    for key in all_partners:
        if not PARTNER_KEY_PATTERN.fullmatch(str(key)):
            problems.add(f"partners.{key}", "klíč musí být [a-z][a-z0-9]{1,11}")
    # Demo se nemění: spisové značky příběhu nesou popisek a demo šablony je
    # tisknou doslova — pravidlo D103 (#103) platí jen pro korpus.
    for problem in validate_identities(identities_for_checks(own_raw, all_partners, verified_at), reserved=reserved_demo,
                                       plain_court_registration=False):
        problems.add("identity", problem)
    _overlap_with_corpus({"own": own_raw, **all_partners}, reserved_corpus, problems)

    own = Party(key="own", kind="own", identity=_identity(own_raw), language=own_raw.get("language"))
    customers = {key: _party(key, "customer", raw, problems, catalogs, rates, period) for key, raw in customers_raw.items()}
    suppliers = {key: _party(key, "supplier", raw, problems, catalogs, rates, period) for key, raw in suppliers_raw.items()}
    names = [own.name] + [p.name for p in customers.values()] + [p.name for p in suppliers.values()]
    for problem in robust_order(names):
        problems.add("řazení osob", problem)
    for party in list(customers.values()) + list(suppliers.values()):
        if party.currency in CURRENCIES and party.currency not in bank_accounts:
            problems.add(f"{party.kind} {party.key}", f"měna {party.currency} nemá účet v story.own.bankAccounts")

    # ── položky, pravidla ──
    items = _items(items_raw, problems, catalogs)
    invoicing = story_raw.get("invoicing")
    if not isinstance(invoicing, dict) or not _is_int(invoicing.get("issueDay")) or not 1 <= invoicing["issueDay"] <= 28 \
            or not _is_int(invoicing.get("dueDays")) or invoicing["dueDays"] < 0:
        problems.add("story.invoicing", "musí být {issueDay 1..28, dueDays >= 0, workOrders}")
        invoicing = {}
    else:
        _work_orders_spec(invoicing.get("workOrders"), problems, setup, customers)
    billing = _billing(story_raw.get("billing"), problems, customers, items, period)
    adhoc = _adhoc(story_raw.get("adhoc"), problems, customers, items, period)
    projects = _projects(story_raw.get("projects"), problems, setup, customers, suppliers, items, adhoc, catalogs, period)
    assets = _assets(story_raw.get("assets"), problems, setup, suppliers, catalogs, period)
    cash_register, cash_expenses = _cash(story_raw.get("cashRegister"), story_raw.get("cashExpenses"), problems, items, catalogs)
    payroll = _payroll(story_raw.get("payroll"), problems, catalogs, period)
    bank = _bank(story_raw.get("bank"), problems, setup, payroll, catalogs, {"own": own_raw, **all_partners}, rates, period)
    parties = {"own": own, **customers, **suppliers}
    registry = _registry(registry_raw, problems, parties, binders, catalogs, period)
    mail = _mail(mail_raw, problems, parties)

    if problems.items:
        raise StoryError(f"{story_dir.name}: " + "; ".join(problems.items))

    return Story(
        dir=story_dir, name=name, title=title, description=description, anchor=effective_anchor, period=period,
        own=own, bank_account_code=bank_accounts["CZK"], bank_accounts=bank_accounts,
        cash_desk_code=own_raw["cashDeskCode"], bank=bank, rates=rates,
        invoicing=invoicing, billing=billing, adhoc=adhoc, projects=projects, assets=assets, cash_register=cash_register,
        cash_expenses=cash_expenses,
        payroll=payroll, customers=customers, suppliers=suppliers, items=items, registry=registry, mail=mail,
        setup=setup, binders=binders, verified_at=verified_at,
    )
