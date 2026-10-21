"""Mezimodel rozvinutého příběhu (mezi `expand.py` a `dumpshape.py`):
osoby, položky, doklady s řádky, zprávy, dokumenty spisovny. Čísla jen
`Decimal` / `int`, data jako `date`; žádné tvary dumpu — ty skládá až
`dumpshape.py`."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal

from .story import Item, Party


@dataclass
class RowRec:
    operation: str                         # sale.services | sale.goods | acc.entry | acc.record
    item_code: str | None = None           # ourCode položky (vlastní nebo kód nabídky)
    item_name: str | None = None           # název položky z číselníku (dump: item.name)
    description: str | None = None         # text řádku
    unit: str | None = None                # system_code jednotky
    unit_print: str | None = None          # zkratka na papír / do analýzy
    quantity: Decimal | None = None
    unit_price: Decimal | None = None
    total_price: Decimal | None = None     # cena řádku (qty × unitPrice zaokrouhleno, nebo částka kontace)
    vat_pct: int | None = None
    vat_code: str | None = None
    account: str | None = None             # kontační řádek
    acc_side: str | None = None            # debit | credit
    content_tag: str | None = None
    supply_kind: str | None = None         # druh plnění na papíru zahraničního dodavatele (services), jen pro zprávu
    work_order: str | None = None          # dimenze zakázky na řádku (číslo zakázky, #131 fáze 2b)
    asset: str | None = None               # dimenze majetku na řádku (inventární číslo karty, #131 fáze 2c)
    # výsledek výpočtu (doccalc):
    vat_base: Decimal | None = None
    vat_amount: Decimal | None = None
    vat_total: Decimal | None = None


@dataclass(frozen=True)
class RecapLine:
    vat_code: str
    vat_pct: int
    base: Decimal
    tax: Decimal
    total: Decimal
    is_reverse_pair: bool = False          # párový řádek samovyměření (kód odběratele, mimo součty hlavičky)


@dataclass
class MailRec:
    key: str                               # klíč pro cache PDF
    message_id: str
    sender: Party
    subject: str
    body: str
    received_at: str                       # YYYY-MM-DDTHH:MM:SS
    analyzed_at: str
    primary_type: str                      # invoiceReceived | other
    doc_state: int                         # 10 Nová | 20 K řešení | 40 Hotovo
    ai_title: str
    pdf_name: str | None = None            # název přílohy (jedna PDF), None = bez přílohy
    html: str | None = None                # vyrenderovaná šablona pro PDF
    canonical_data: dict | None = None     # vstup build_expected (tvar korpusu)
    computed: dict | None = None           # compute.compute(...) pro PDF a canonical
    resolution: int | None = None          # 40 = použito (navázaný doklad)
    resolved_at: str | None = None
    target_doc_key: str | None = None      # klíč dokladu, na který zpráva vede
    content_tag: str | None = None
    attention: str | None = None
    action_note: str | None = None
    action_due: date | None = None


@dataclass
class DocRec:
    key: str
    doc_type: str                          # invno | invpo | invni | cash | cashreg | cmnbkp
    ordinal: int                           # pořadí vzniku v příběhu (poslední klíč řazení)
    issue: date
    accounting: date
    due: date | None
    tax_point: date | None
    vat_obligation: date | None
    currency: str
    rate: Decimal
    vat_mode: str                          # fromBase | fromTotal | none
    vat_place: str                         # domestic | intracom
    payment_method: str                    # bankTransfer | cash
    partner_key: str | None
    self_party: str | None                 # supplier | customer | None (cmnbkp)
    rows: list[RowRec]
    state: int                             # 10 koncept | 40 V pořádku
    rounding: str = "none"                 # none | czk
    period_from: date | None = None
    period_to: date | None = None
    payment_reference: str | None = None   # explicitní VS (vydané faktury = číslo dokladu)
    cash_desk: str | None = None
    cash_direction: int | None = None
    supplier_doc_number: str | None = None # číslo dodavatele (docNumber přijaté faktury)
    doc_text: str | None = None
    note_on_document: str | None = None
    vat_registration: bool = True          # doklad nese registraci DPH (registrationCountry)
    fiscal_period_type: str | None = None  # "opening" = otevírací doklad (exchange fiscalPeriodType, #69 D20)
    source_kind: str | None = None         # exchange source.kind ("workOrder" = z periodické fakturace, #131 fáze 2b)
    work_order: str | None = None          # dimenze zakázky na hlavičce (číslo zakázky)
    number: str | None = None              # přidělí assign_numbers
    sequence: int | None = None
    series_code: str | None = None
    recap: list[RecapLine] = field(default_factory=list)
    total_base: Decimal = Decimal("0")
    total_vat: Decimal = Decimal("0")
    total_amount: Decimal = Decimal("0")
    total_rounding: Decimal = Decimal("0")
    mail: MailRec | None = None            # zpracovaná zpráva s PDF, ze které doklad vznikl


@dataclass
class WorkOrderRowRec:
    """Řádek fakturačního předpisu periodické zakázky (platnost od/do)."""
    item_code: str
    description: str
    quantity: Decimal
    unit: str                              # system_code jednotky
    unit_price: Decimal
    vat_code: str
    valid_from: date | None = None
    valid_to: date | None = None


@dataclass(frozen=True)
class PeriodRec:
    """Vystavené období periodické zakázky — doklad klíčem (číslo až po `assign_numbers`)."""
    start: date
    end: date
    doc_key: str


@dataclass
class WorkOrderRec:
    key: str                               # billing:<index pravidla> | project:<klíč>
    ordinal: int                           # pořadí vzniku v příběhu (poslední klíč řazení)
    series_code: str                       # kód řady zakázek (setup/work_order_series)
    sequence: int
    number: str
    title: str
    state: str                             # confirmed | finished
    date_start: date
    customer_key: str
    currency: str
    date_end: date | None = None
    periodicity: str | None = None         # month | quarter — jen periodická
    invoice_from: date | None = None
    doc_text: str | None = None            # šablona textu dokladu s {období}, v jazyce dokladu
    bank_account: str | None = None        # přepis účtu z druhu (EUR → BU2), None = z druhu
    rows: list[WorkOrderRowRec] = field(default_factory=list)
    periods: list[PeriodRec] = field(default_factory=list)


@dataclass
class AssetRec:
    """Karta dlouhodobého majetku (#131 fáze 2c, D41): pořízená řádkem
    `purchase.asset` na faktuře dodavatele, zařazená ručně ve výši základu
    řádku; `acc_plan` = měsíční účetní odpisy v okně příběhu (invoicegen/assets.py)
    jen pro souhrn ekonomiky — sada odpisy nenese (D38)."""
    key: str                               # asset:<klíč>
    number: str                            # inventární číslo (převezme se beze změny)
    name: str
    type_name: str                         # název typu (setup/asset_types)
    accounting_group: str                  # kód účetní skupiny (setup/asset_accounting_groups)
    acquisition_account: str               # účet pořízení skupiny (04x) na řádku purchase.asset
    tax_method: str
    tax_rule: str
    acc_method: str
    activation: date                       # datum zařazení
    amount: Decimal                        # vstupní cena = základ řádku pořízení
    purchase_doc_key: str                  # klíč faktury dodavatele s řádkem pořízení
    acc_plan: tuple[tuple[date, Decimal], ...] = ()   # (první den měsíce, účetní odpis) v okně příběhu
    category: str = "tangible"
    tracking: str = "single"


@dataclass
class RegistryRec:
    key: str
    doc_type: str
    title: str
    summary: str
    partner_key: str
    binder: str
    ref_number: str
    valid_from: date
    valid_to: date
    created_at: str                        # YYYY-MM-DDTHH:MM:SS
    signed: date | None
    kind_fields: dict
    source_kind: str
    template: str
    pdf_name: str
    html: str | None = None


@dataclass(frozen=True)
class PersonRec:
    party: Party
    person_id: str
    is_own: bool


@dataclass
class TxRec:
    """Bankovní transakce — jen to, co dodá banka (#131 D17); partnera
    a účtování dopočítá aplikace po seedu."""
    key: str
    account_code: str                      # kód našeho účtu (setup/bank_accounts)
    date: date
    amount: Decimal                        # se znaménkem: + příjem, − výdaj
    ordinal: int                           # pořadí vzniku (řazení v rámci dne)
    counterparty_account: str | None = None
    counterparty_name: str | None = None
    payment_reference: str | None = None   # VS; None = bez VS
    message: str | None = None
    operation: str | None = None           # None = výchozí podle směru; "fee.out"
    exchange_rate: Decimal | None = None   # jen účet v cizí měně (kurz hrazené faktury, U1)
    doc_key: str | None = None             # hrazený doklad / vyrovnávací doklad mezd
    fault: str | None = None               # missing | typo = záměrně nespárovaná platba (D18)
    external_id: str | None = None         # doplní build_statements


@dataclass
class StatementRec:
    """Výpis jednoho účtu za kalendářní měsíc (poslední končí kotvou)."""
    account_code: str
    currency: str                          # velkými (jako canonical)
    period_start: date
    period_end: date
    number: str                            # "M/RRRR"
    external_id: str                       # "<kód>-RRRR-MM"
    opening: Decimal
    closing: Decimal
    transactions: list[TxRec]              # seřazené (datum, pořadí vzniku)


@dataclass
class Records:
    persons: list[PersonRec]
    items: list[Item]
    docs: list[DocRec]
    mails: list[MailRec]                    # všechny zprávy (zpracované i k řešení)
    registry: list[RegistryRec]
    setup: dict[str, dict]
    bank: list[StatementRec] = field(default_factory=list)
    work_orders: list[WorkOrderRec] = field(default_factory=list)   # zakázky (#131 fáze 2b)
    assets: list[AssetRec] = field(default_factory=list)            # karty majetku (#131 fáze 2c)
