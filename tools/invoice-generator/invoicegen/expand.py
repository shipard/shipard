"""Rozvinutí příběhu na záznamy sady (tasks/demo-dataset-phase1.md D6):
pravidla + kotva → osoby, položky, zakázky (#131 fáze 2b), karty majetku
(fáze 2c), doklady (vydané faktury z předpisu zakázek, zálohová, přijaté
faktury se zprávami vč. samostatné faktury s pořízením majetku, prodejky,
výdajové pokladní doklady, mzdy), zprávy k řešení a dokumenty spisovny.
Deterministicky — rozmanitost jen ze `timeline.spread` nad (měsíc, pořadí
partnera, pořadí pravidla).

Model plateb (D8) je oddělený: `payment_events()` vrací události
úhrad podle chování odběratelů a dodavatelů; hotovost je na dokladu
(pokladna), zbytek promítá `bank.py` do bankovních výpisů (fáze 2a).
"""

from __future__ import annotations

from dataclasses import dataclass, replace
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP

from .assets import acc_monthly_plan
from .bank import build_statements, expand_bank_docs
from .catalogs import Catalogs
from .collation import czech_key
from .compute import compute
from .corpusprint import template_values
from .demoprint import flat_party, invoice_values, registry_values
from .doccalc import calc_document
from .errors import ExpandError
from .model import AssetRec, DocRec, MailRec, PeriodRec, PersonRec, Records, RegistryRec, RowRec, WorkOrderRec, WorkOrderRowRec
from .render import render_html
from .snapshot import ai_title
from .story import Party, Story, template_locale
from .timeline import (
    clamp_day, days_before, fiscal_year, in_period, month_date, month_end, period_label, quarter_label,
    quarter_offsets, spread, spread_decimal, ts,
)
from .workorders import (
    PERIODIC_RULES, date_start_for, doc_text, document_language, invoice_from, period_bounds, period_label as work_order_period_label,
    rows_valid_at,
)

__all__ = ["DOC_ID_CODES", "ExpandError", "ExtraInvoice", "PaymentEvent", "PurchaseRow", "asset_cost_links", "asset_purchases",
           "assign_numbers", "expand", "expand_assets", "expand_projects", "expand_work_orders", "foreign_unit_price",
           "format_number", "payment_events", "project_purchases"]

# `source.kind` faktur z periodické fakturace (InvoiceBuilder::SOURCE_KIND).
SOURCE_WORK_ORDER = "workOrder"
# Pohyb řádku pořízení dlouhodobého majetku (rowAccount direct, rowAsset optional — assets D61).
PURCHASE_ASSET = "purchase.asset"

# Číslování (modules/docs/core/config/docTypes.jsonc): %D kód typu, %y dva
# znaky fiskálního roku, %C kód řady (pokladna), %4 / %5 pořadí.
DOC_ID_CODES = {"invno": "1", "invpo": "12", "invni": "2", "invpi": "22", "cmnbkp": "60", "cash": "31", "cashreg": "14"}
BOUND_TYPES = frozenset({"cash", "cashreg"})   # series_binding cash_desk → %D%C%y%5

VAT_OUTPUT = {21: "cz-120", 12: "cz-121", 0: "cz-122"}
VAT_INPUT = {21: "cz-110", 12: "cz-111", 0: "cz-112"}
VAT_EU_SERVICES = "cz-202"
VAT_EU_SERVICES_INPUT = "cz-217"   # přijetí služby z EU, samovyměření (pár cz-207 skládá doccalc), #131 D28

MAIL_TEXTS = {
    "cs": {"subject": "Faktura {number}", "body": "Dobrý den,\n\nv příloze zasíláme fakturu {number}.\n\n{supplier}",
           "note": "Děkujeme za včasnou úhradu.",
           "period_note": "Vyúčtování za období {from_} – {to}."},
    "en": {"subject": "Invoice {number}", "body": "Hello,\n\nplease find attached invoice {number}.\n\n{supplier}",
           "note": "Thank you for your prompt payment.",
           "period_note": "Billing period {from_} – {to}."},
}

MODEL_NAME = "demo-ideal"
PROMPT_VERSION = "demo"


def format_number(doc_type: str, fiscal_label: str, sequence: int, series_code: str | None) -> str:
    code = series_code or ""
    yy = fiscal_label[-2:]
    if doc_type in BOUND_TYPES:
        return f"{DOC_ID_CODES[doc_type]}{code}{yy}{sequence:05d}"
    return f"{DOC_ID_CODES[doc_type]}{yy}{code}{sequence:04d}"


def assign_numbers(docs: list[DocRec]) -> None:
    """Čísla v pořadí vystavení per (typ, řada, fiskální rok); koncepty
    bez čísla. VS vydaných dokladů = číslo (#125); u pokladny a mezd
    `DocDocument` doplní VS = pořadí, takže ho sada nese stejně."""
    counters: dict[tuple[str, str | None, int], int] = {}
    for doc in sorted(docs, key=lambda d: (d.doc_type, d.series_code or "", d.issue, d.ordinal)):
        if doc.state == 10:
            continue
        year = fiscal_year(doc.issue)
        key = (doc.doc_type, doc.series_code, year)
        counters[key] = counters.get(key, 0) + 1
        doc.sequence = counters[key]
        doc.number = format_number(doc.doc_type, str(year), doc.sequence, doc.series_code)
        if doc.payment_reference is None:
            doc.payment_reference = doc.number if doc.doc_type in ("invno", "invpo") else str(doc.sequence)


# ── Osoby a položky ──────────────────────────────────────────────────────────

def expand_persons(story: Story) -> list[PersonRec]:
    parties = sorted(story.parties().values(), key=lambda p: (czech_key(p.name), p.company_id or "", p.key))
    return [PersonRec(party=p, person_id=f"F{i:05d}", is_own=p.kind == "own") for i, p in enumerate(parties, start=1)]


def expand_items(story: Story):
    return sorted(story.items.values(), key=lambda i: (i.code.lower(), i.name.lower()))


# ── Vydané doklady ───────────────────────────────────────────────────────────

def _customer_vat(customer: Party, pct: int) -> tuple[str, str]:
    """(kód DPH, místo plnění) pro řádek vydaného dokladu odběrateli."""
    if customer.vat_treatment == "reverseCharge":
        return VAT_EU_SERVICES, "intracom"
    return VAT_OUTPUT[pct], "domestic"


def _rate(story: Story, currency: str, offset: int) -> Decimal:
    """Kurz měsíce vystavení (D13); doklad v cizí měně bez kurzu je chyba —
    žádný tichý fallback na 1 (D27)."""
    if currency == "CZK":
        return Decimal("1")
    rate = (story.rates.get(currency) or {}).get(offset)
    if rate is None:
        raise ExpandError(f"doklad v {currency} v měsíci {offset} nemá kurz v story.rates")
    return rate


def foreign_unit_price(price_czk: Decimal, rate: Decimal) -> Decimal:
    """Cena katalogové položky na dokladu v cizí měně = cena v CZK ÷ kurz
    měsíce vystavení, na celé centy (D27)."""
    return (price_czk / rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def _issued_doc(story: Story, *, key: str, ordinal: int, doc_type: str, customer: Party, offset: int,
                issue: date, due_days: int, rows: list[RowRec], state: int, cash: bool) -> DocRec:
    place = "intracom" if customer.vat_treatment == "reverseCharge" else "domestic"
    tax_document = doc_type != "invpo"
    return DocRec(
        key=key, doc_type=doc_type, ordinal=ordinal, issue=issue, accounting=issue,
        due=issue + timedelta(days=due_days),
        tax_point=issue if tax_document else None, vat_obligation=issue if tax_document else None,
        currency=customer.currency or "CZK", rate=_rate(story, customer.currency or "CZK", offset),
        vat_mode="fromBase", vat_place=place, payment_method="cash" if cash else "bankTransfer",
        partner_key=customer.key, self_party="supplier", rows=rows, state=state,
        cash_desk=story.cash_desk_code if cash else None,
    )


def _item_row(story: Story, customer: Party, code: str, qty: Decimal, text: str | None,
              price: Decimal | None = None, catalogs: Catalogs | None = None, *, rate: Decimal) -> RowRec:
    """Řádek vydaného dokladu z položky. Vlastní `price` pravidla je cena
    v měně faktury; cena z katalogu (CZK) se na doklad v cizí měně
    přepočte kurzem měsíce vystavení (D27)."""
    item = story.items[code]
    vat_code, _ = _customer_vat(customer, item.vat_pct)
    if price is None:
        price = item.price if (customer.currency or "CZK") == "CZK" else foreign_unit_price(item.price, rate)
    # Řádek bez textu dostane po seedu popis = název položky (DocDocument).
    return RowRec(
        operation="sale.services" if item.kind == "service" else "sale.goods",
        item_code=item.code, item_name=item.name, description=text if text is not None else item.name, unit=item.unit,
        unit_print=catalogs.unit_shortcuts.get(item.unit, item.unit) if catalogs else item.unit,
        quantity=qty, unit_price=price,
        vat_pct=0 if vat_code == VAT_EU_SERVICES else item.vat_pct, vat_code=vat_code,
    )


# ── Zakázky ──────────────────────────────────────────────────────────────────

def expand_work_orders(story: Story) -> list[WorkOrderRec]:
    """Periodické zakázky z pravidel billing `month` / `quarter` (D32–D34):
    jedna zakázka V pořádku per pravidlo v pořadí pravidel (čísla řady
    `S`), zahájení před oknem (`since`), „fakturovat od“ = první období
    v okně, předpis = položka, popis v jazyce dokladu, množství (čtvrtletí
    3), cena pravidla a kód DPH; změna ceny (`priceFrom`) = dva řádky
    s platností. Zahraniční odběratel: měna EUR a přepis účtu (BU2).
    Zálohová faktura (`once`) zůstává mimo zakázky."""
    spec = story.invoicing["workOrders"]
    kind_row = next(r for r in story.setup["work_order_kinds"]["rows"] if r.get("name") == spec["kind"])
    default_account = kind_row.get("inv_bank_account")
    out: list[WorkOrderRec] = []
    for rule_index, rule in enumerate(story.billing):
        every = rule["every"]
        if every not in PERIODIC_RULES:
            continue
        customer = story.customers[rule["customer"]]
        language = document_language(customer)
        item = story.items[rule["item"]]
        vat_code, _ = _customer_vat(customer, item.vat_pct)
        currency = customer.currency or "CZK"
        sequence = len(out) + 1
        rows = [WorkOrderRowRec(
            item_code=item.code, description=spec["rowText"][language], quantity=Decimal(rule.get("qty", 1)),
            unit=item.unit, unit_price=Decimal(rule["price"]), vat_code=vat_code,
        )]
        if "priceFrom" in rule:
            change = story.period.month_start(rule["priceFrom"]["month"])
            rows[0].valid_to = change - timedelta(days=1)
            rows.append(replace(rows[0], unit_price=Decimal(rule["priceFrom"]["price"]), valid_from=change, valid_to=None))
        account = story.bank_account_for(currency)
        out.append(WorkOrderRec(
            key=f"billing:{rule_index}", ordinal=rule_index, series_code=spec["series"], sequence=sequence,
            number=f"{spec['series']}{sequence:04d}", title=spec["title"].format(customer=customer.name),
            state="confirmed", date_start=date_start_for(story.period, int(rule["since"]), every),
            customer_key=customer.key, currency=currency, periodicity=every,
            invoice_from=invoice_from(story.period, every), doc_text=spec["docText"][language],
            bank_account=account if account != default_account else None, rows=rows,
        ))
    return out + expand_projects(story)


def expand_projects(story: Story) -> list[WorkOrderRec]:
    """Projekty (D36): externí jednorázové zakázky Ukončeno, řada `P`
    v pořadí příběhu; zahájení = první ad hoc faktura projektu minus
    `startDaysBefore`, ukončení = datum poslední (dodávka). Bez předpisu
    a období; výnosy a náklady nesou doklady dimenzí zakázky."""
    spec = story.projects
    out: list[WorkOrderRec] = []
    for index, project in enumerate(spec.get("items") or []):
        customer = story.customers[project["customer"]]
        dates = sorted(
            month_date(story.period, entry["month"], entry["day"])
            for entry in story.adhoc if entry.get("project") == project["key"]
        )
        dates = [d for d in dates if in_period(story.period, d)] or dates
        sequence = index + 1
        out.append(WorkOrderRec(
            key=f"project:{project['key']}", ordinal=len(story.billing) + index, series_code=spec["series"],
            sequence=sequence, number=f"{spec['series']}{sequence:04d}", title=project["title"], state="finished",
            date_start=dates[0] - timedelta(days=int(project["startDaysBefore"])), date_end=dates[-1],
            customer_key=customer.key, currency=customer.currency or "CZK",
        ))
    return out


@dataclass(frozen=True)
class PurchaseRow:
    """Řádek doplněný na fakturu dodavatele: nákup projektu (D36 — kód
    nabídky, text, množství = prodané kusy, cena, číslo zakázky) nebo
    pořízení majetku (D41 — pohyb purchase.asset s účtem pořízení přímo
    a kartou na řádku, bez položky)."""
    offer_code: str | None
    text: str
    quantity: Decimal
    unit_price: Decimal
    work_order: str | None = None
    operation: str = "acc.entry"
    account: str | None = None
    asset: str | None = None


@dataclass(frozen=True)
class ExtraInvoice:
    """Samostatná faktura dodavatele mimo jeho pravidlo (nákup majetku, D41):
    den vystavení a řádky."""
    day: int
    rows: tuple[PurchaseRow, ...]


def project_purchases(story: Story, work_orders: list[WorkOrderRec], issued: list[DocRec]) -> dict[tuple[str, int], list[PurchaseRow]]:
    """(dodavatel, měsíc) → nákupní řádky projektů; množství = součet kusů
    položky na fakturách projektu (dimenze zakázky), `offerItem` výchozí
    z prvního řádku pravidla dodavatele."""
    numbers = {wo.key: wo.number for wo in work_orders}
    out: dict[tuple[str, int], list[PurchaseRow]] = {}
    for project in story.projects.get("items") or []:
        number = numbers[f"project:{project['key']}"]
        sold: dict[str, Decimal] = {}
        for doc in issued:
            if doc.work_order != number:
                continue
            for row in doc.rows:
                if row.item_code is not None and row.quantity is not None:
                    sold[row.item_code] = sold.get(row.item_code, Decimal("0")) + row.quantity
        purchases = project["purchases"]
        supplier = story.suppliers[purchases["supplier"]]
        default_offer = str((supplier.invoices or {})["rows"][0]["item"])
        rows = out.setdefault((purchases["supplier"], int(purchases["month"])), [])
        for prow in purchases["rows"]:
            rows.append(PurchaseRow(
                offer_code=str(prow.get("offerItem", default_offer)), text=prow["text"], quantity=sold[prow["item"]],
                unit_price=Decimal(prow["unitPrice"]), work_order=number,
            ))
    return out


# ── Majetek ──────────────────────────────────────────────────────────────────

def expand_assets(story: Story, catalogs: Catalogs) -> list[AssetRec]:
    """Karty majetku (D41): typ a účetní skupina ze setup/, zařazení
    `daysAfterDelivery` po dni nákupu ve výši základu řádku pořízení
    (qty × unitPrice), účet pořízení z účetní skupiny; plán měsíčních
    účetních odpisů v okně příběhu jen pro souhrn ekonomiky."""
    spec = story.assets
    out: list[AssetRec] = []
    if not spec:
        return out
    type_row = next(r for r in story.setup["asset_types"]["rows"] if r.get("name") == spec["type"])
    group_code = str(type_row["default_accounting_group"])
    group = next(r for r in story.setup["asset_accounting_groups"]["rows"] if r.get("code") == group_code)
    for item in spec["items"]:
        purchase = item["purchase"]
        delivered = month_date(story.period, int(purchase["month"]), int(purchase["day"]))
        activation = delivered + timedelta(days=int(item["activation"]["daysAfterDelivery"]))
        amount = Decimal(purchase.get("qty", 1)) * Decimal(purchase["unitPrice"])
        plan = acc_monthly_plan(catalogs.asset_rules, item["taxRule"], amount, activation, story.period.months)
        out.append(AssetRec(
            key=f"asset:{item['key']}", number=item["number"], name=item["name"], type_name=spec["type"],
            accounting_group=group_code, acquisition_account=str(group["account_acquisition"]),
            tax_method=item["taxMethod"], tax_rule=item["taxRule"], acc_method=item["accMethod"],
            activation=activation, amount=amount, purchase_doc_key=f"received:{purchase['supplier']}:{int(purchase['month'])}",
            acc_plan=tuple(sorted(plan.items())),
        ))
    return out


def asset_purchases(story: Story, assets: list[AssetRec]) -> dict[tuple[str, int], ExtraInvoice]:
    """(dodavatel, měsíc) → samostatná faktura s pořízením majetku (D41):
    řádek serveru `purchase.asset` (účet pořízení skupiny, karta na řádku,
    bez položky) a běžné řádky nákupu (`offerItem` výchozí z prvního řádku
    pravidla dodavatele)."""
    out: dict[tuple[str, int], ExtraInvoice] = {}
    for asset, item in zip(assets, story.assets.get("items") or []):
        purchase = item["purchase"]
        supplier = story.suppliers[purchase["supplier"]]
        default_offer = str((supplier.invoices or {})["rows"][0]["item"])
        rows = [PurchaseRow(
            offer_code=None, text=str(purchase.get("text", asset.name)), quantity=Decimal(purchase.get("qty", 1)),
            unit_price=Decimal(purchase["unitPrice"]), operation=PURCHASE_ASSET, account=asset.acquisition_account,
            asset=asset.number,
        )]
        for prow in purchase.get("rows") or []:
            rows.append(PurchaseRow(
                offer_code=str(prow.get("offerItem", default_offer)), text=prow["text"], quantity=Decimal(prow.get("qty", 1)),
                unit_price=Decimal(prow["unitPrice"]),
            ))
        key = (purchase["supplier"], int(purchase["month"]))
        day = int(purchase["day"])
        if key in out:
            if out[key].day != day:
                raise ExpandError(f"dva nákupy majetku u {key[0]} v měsíci {key[1]} v různé dny")
            rows = list(out[key].rows) + rows
        out[key] = ExtraInvoice(day=day, rows=tuple(rows))
    return out


def asset_cost_links(story: Story, assets: list[AssetRec]) -> dict[str, list[AssetRec]]:
    """Dodavatel → karty, jejichž náklady nese (`costs.supplier`, D41):
    faktury dodavatele od první vystavené po zařazení dostanou kartu na
    řádcích (tab Náklady a výnosy)."""
    links: dict[str, list[AssetRec]] = {}
    for asset, item in zip(assets, story.assets.get("items") or []):
        costs = item.get("costs")
        if costs:
            links.setdefault(str(costs["supplier"]), []).append(asset)
    return links


# ── Vydané doklady ───────────────────────────────────────────────────────────

def expand_issued(story: Story, catalogs: Catalogs, ordinal: int,
                  work_orders: list[WorkOrderRec] | None = None) -> tuple[list[DocRec], int]:
    docs: list[DocRec] = []
    period = story.period
    inv = story.invoicing
    by_key = {wo.key: wo for wo in (work_orders if work_orders is not None else expand_work_orders(story))}
    for rule_index, rule in enumerate(story.billing):
        customer = story.customers[rule["customer"]]
        every = rule["every"]
        work_order = by_key.get(f"billing:{rule_index}")
        if every == "month":
            offsets = list(period.offsets)
        elif every == "quarter":
            offsets = quarter_offsets(period)
        else:
            offsets = [rule["month"]]
        for offset in offsets:
            day = rule.get("day", inv["issueDay"]) if every == "once" else inv["issueDay"]
            issue = month_date(period, offset, day)
            if not in_period(period, issue):
                continue
            state = 10 if (offset == 0 and rule.get("conceptInAnchorMonth")) else 40
            doc_type = "invpo" if rule.get("docType") == "proformaIssued" else "invno"
            ordinal += 1
            rate = _rate(story, customer.currency or "CZK", offset)
            if work_order is None:
                # Jednorázová (zálohová) faktura mimo zakázky.
                rows = [_item_row(story, customer, rule["item"], Decimal(rule.get("qty", 1)), story.items[rule["item"]].name,
                                  Decimal(rule["price"]), catalogs, rate=rate)]
            else:
                # Faktura jako z InvoiceBuilder: řádky předpisu platné k datu vystavení.
                valid = rows_valid_at(work_order.rows, issue)
                if not valid:
                    raise ExpandError(f"zakázka {work_order.number}: k {issue} nemá platný řádek předpisu")
                rows = [_item_row(story, customer, r.item_code, r.quantity, r.description, r.unit_price, catalogs, rate=rate)
                        for r in valid]
            doc = _issued_doc(
                story, key=f"billing:{rule_index}:{offset}", ordinal=ordinal, doc_type=doc_type, customer=customer,
                offset=offset, issue=issue, due_days=int(rule.get("dueDays", inv["dueDays"])),
                rows=rows, state=state, cash=False,
            )
            if work_order is not None:
                # Vystavení = DUZP = účetní datum = začátek období, text dokladu
                # z šablony zakázky s {období} v jazyce dokladu, období, zdroj
                # a dimenze zakázky na hlavičce (deník ji dědí) — D32.
                start, end = period_bounds(period.month_start(offset), every)
                label = work_order_period_label(start, every, document_language(customer), catalogs.period_texts)
                doc.doc_text = doc_text(work_order.doc_text, work_order.title, label)
                doc.period_from, doc.period_to = start, end
                doc.source_kind = SOURCE_WORK_ORDER
                doc.work_order = work_order.number
                work_order.periods.append(PeriodRec(start, end, doc.key))
            docs.append(doc)
    customer_order = {key: i for i, key in enumerate(story.customers)}
    project_numbers = {wo.key.removeprefix("project:"): wo.number for wo in by_key.values() if wo.key.startswith("project:")}
    for entry_index, entry in enumerate(story.adhoc):
        customer = story.customers[entry["customer"]]
        offset = entry["month"]
        issue = month_date(period, offset, entry["day"])
        if not in_period(period, issue):
            continue
        rows: list[RowRec] = []
        rate = _rate(story, customer.currency or "CZK", offset)
        for row_index, row in enumerate(entry["rows"]):
            if "hours" in row:
                lo, hi = row["hours"]
                qty = Decimal(spread(lo, hi, offset, customer_order[customer.key], entry_index, row_index))
                text = row.get("text", f"Servisní práce technika — {period_label(period.month_start(offset))}")
            else:
                qty = Decimal(row["qty"])
                text = row.get("text")
            rows.append(_item_row(story, customer, row["item"], qty, text, catalogs=catalogs, rate=rate))
        ordinal += 1
        doc = _issued_doc(
            story, key=f"adhoc:{entry_index}", ordinal=ordinal, doc_type="invno", customer=customer, offset=offset,
            issue=issue, due_days=inv["dueDays"], rows=rows, state=10 if entry.get("state") == "concept" else 40,
            cash=entry.get("payment") == "cash",
        )
        if entry.get("project") is not None:
            # Faktura projektu (D36): dimenze zakázky na hlavičce, deník ji dědí.
            doc.work_order = project_numbers[entry["project"]]
        docs.append(doc)
    return docs, ordinal


# ── Přijaté faktury a zprávy ─────────────────────────────────────────────────

def _supplier_number(supplier: Party, counter: int) -> str:
    spec = supplier.doc_number or {}
    return spec["pattern"].format(seq=int(spec["seqStart"]) + counter)


def _digits(text: str, limit: int = 10) -> str:
    digits = "".join(ch for ch in text if ch.isdigit())
    return digits[-limit:] if digits else "0"


def _received_rows(story: Story, supplier: Party, rule: dict, offset: int, supplier_order: int,
                   catalogs: Catalogs, labels: dict[str, str], purchases: list[PurchaseRow] = (), *,
                   regular: bool = True) -> list[RowRec]:
    """Řádky přijaté faktury z pravidla dodavatele (`regular`; samostatná
    faktura s pořízením majetku je bez nich). Dodavatel se samovyměřením
    (služby z EU, D28) dostane kód cz-217 se sazbou samovyměření — papír
    tiskne 0 % (`_invoice_mail`). Nákupní řádky projektů (D36) nahradí
    běžný řádek se stejným textem (na jeho místě, `row_index` ostatních
    zůstává), jinak se přidají; nesou dimenzi zakázky. Řádek pořízení
    majetku (D41) má pohyb purchase.asset, účet přímo a kartu na řádku."""
    rows: list[RowRec] = []
    previous_qty: Decimal | None = None
    reverse_charge = supplier.vat_treatment == "reverseCharge"
    for row_index, row in enumerate(rule["rows"] if regular else []):
        offer = catalogs.offer[row["item"]]
        pct = int(row["vatPct"])
        text = row["text"].format(**labels)
        if "amount" in row:
            qty, unit, price = Decimal("1"), "pcs", Decimal(row["amount"])
        else:
            raw_qty = row["qty"]
            if raw_qty == "sameAsPrevious":
                qty = previous_qty if previous_qty is not None else Decimal("1")
            elif isinstance(raw_qty, list):
                lo, hi = Decimal(raw_qty[0]), Decimal(raw_qty[1])
                step = Decimal("0.001") if lo != lo.to_integral_value() or hi != hi.to_integral_value() else Decimal("1")
                qty = spread_decimal(lo, hi, step, offset, supplier_order, row_index)
            else:
                qty = Decimal(raw_qty)
            unit, price = row["unit"], Decimal(row["unitPrice"])
        previous_qty = qty
        rows.append(RowRec(
            operation="acc.entry", item_code=offer.code, item_name=offer.name_cs, description=text, unit=unit,
            # Anglický papír tiskne kód jednotky (pcs), český zkratku (ks).
            unit_print=unit if supplier.language == "en" else catalogs.unit_shortcuts.get(unit, unit),
            quantity=qty, unit_price=price, vat_pct=pct,
            vat_code=VAT_EU_SERVICES_INPUT if reverse_charge else VAT_INPUT[pct],
            content_tag=offer.content_tags[0] if offer.content_tags else None,
            supply_kind=row.get("supplyKind"),
        ))
    for purchase in purchases:
        offer = catalogs.offer[purchase.offer_code] if purchase.offer_code is not None else None
        project_row = RowRec(
            operation=purchase.operation, item_code=offer.code if offer else None, item_name=offer.name_cs if offer else None,
            description=purchase.text, unit="pcs",
            unit_print=catalogs.unit_shortcuts.get("pcs", "pcs"), quantity=purchase.quantity, unit_price=purchase.unit_price,
            vat_pct=21, vat_code=VAT_INPUT[21], content_tag=offer.content_tags[0] if offer and offer.content_tags else None,
            account=purchase.account, work_order=purchase.work_order, asset=purchase.asset,
        )
        position = next((i for i, r in enumerate(rows) if r.description == purchase.text), None)
        if position is None:
            rows.append(project_row)
        else:
            rows[position] = project_row
    return rows


def _pattern_label(doc_number_pattern: str, issue: date) -> dict[str, str]:
    return {"yyyy": str(issue.year), "yy": f"{issue.year % 100:02d}"}


def expand_received(story: Story, catalogs: Catalogs, templates: dict[str, str], ordinal: int,
                    purchases: dict[tuple[str, int], list[PurchaseRow]] | None = None,
                    extras: dict[tuple[str, int], ExtraInvoice] | None = None,
                    cost_links: dict[str, list[AssetRec]] | None = None,
                    ) -> tuple[list[DocRec], list[MailRec], int]:
    """Přijaté faktury se zprávami: měsíce podle pravidla dodavatele plus
    samostatné faktury (`extras`: nákup majetku, D41 — měsíc mimo pravidlo,
    vlastní den, jen jejich řádky; číslo dodavatele jde z jednoho čítače
    v pořadí měsíců). Faktury dodavatele z `cost_links` od první vystavené
    po zařazení karty nesou kartu na řádcích (náklady na majetek)."""
    docs: list[DocRec] = []
    mails: list[MailRec] = []
    period = story.period
    own_flat = flat_party(story.own.identity)
    purchases = purchases or {}
    extras = extras or {}
    cost_links = cost_links or {}
    for supplier_order, (key, supplier) in enumerate(story.suppliers.items()):
        rule = supplier.invoices
        if rule is None:
            continue
        if "months" in rule:
            offsets = [m for m in rule["months"]]
        elif rule["every"] == "quarter":
            offsets = quarter_offsets(period)
        else:
            offsets = list(period.offsets)
        regular_offsets = set(offsets)
        extra_offsets = {o for (k, o) in extras if k == key}
        counter = 0
        for offset in sorted(regular_offsets | extra_offsets):
            regular = offset in regular_offsets
            extra = extras.get((key, offset))
            issue = month_date(period, offset, int(rule["day"]) if regular else extra.day)
            if not in_period(period, issue):
                continue
            mode = rule["anchorMonth"] if offset == 0 else "processed"
            if mode == "skip":
                continue
            month_start = period.month_start(offset)
            if rule.get("period") == "previousMonth":
                previous = period.month_start(offset - 1) if offset - 1 in period.offsets else None
                if previous is None:
                    continue
                period_from, period_to = previous, month_end(previous)
                tax_point = period_to
                labels = {"period": period_label(previous), "quarter": quarter_label(previous)}
            else:
                period_from = period_to = None
                tax_point = issue
                labels = {"period": period_label(month_start), "quarter": quarter_label(month_start)}
            spec = supplier.doc_number or {}
            pattern = spec["pattern"]
            number = pattern.format(seq=int(spec["seqStart"]) + counter, **_pattern_label(pattern, issue))
            counter += 1
            due = issue + timedelta(days=int(rule["dueDays"]))
            added = list(purchases.get((key, offset), ())) + list(extra.rows if extra is not None else ())
            rows = _received_rows(story, supplier, rule, offset, supplier_order, catalogs, labels, added, regular=regular)
            # Náklady na majetek (D41): od první faktury vystavené po zařazení karty.
            for asset in cost_links.get(key, ()):
                if issue >= asset.activation:
                    for row in rows:
                        if row.asset is None:
                            row.asset = asset.number
            rounding = rule.get("rounding", "none")
            doc_key = f"received:{key}:{offset}"
            currency = supplier.currency or "CZK"
            ordinal += 1
            doc = DocRec(
                key=doc_key, doc_type="invni", ordinal=ordinal, issue=issue, accounting=issue, due=due,
                tax_point=tax_point, vat_obligation=tax_point, period_from=period_from, period_to=period_to,
                currency=currency, rate=_rate(story, currency, offset), vat_mode="fromBase",
                vat_place="intracom" if supplier.vat_treatment == "reverseCharge" else "domestic",
                payment_method="bankTransfer", payment_reference=_digits(number), partner_key=key,
                self_party="customer", rows=rows, state=40, rounding=rounding, supplier_doc_number=number,
            )
            mail = _invoice_mail(story, supplier, supplier_order, doc, offset, templates, own_flat, open_proposal=mode == "open")
            if mode == "processed":
                doc.mail = mail
                mail.target_doc_key = doc.key
                docs.append(doc)
            mails.append(mail)
    return docs, mails, ordinal


def _invoice_mail(story: Story, supplier: Party, supplier_order: int, doc: DocRec, offset: int,
                  templates: dict[str, str], own_flat: dict, *, open_proposal: bool) -> MailRec:
    number = doc.supplier_doc_number or ""
    received_day = doc.issue + timedelta(days=1)
    minute = spread(2, 50, offset, supplier_order, 1)
    received_at = ts(received_day, 8, minute)
    analyzed_at = ts(received_day, 8, minute + 1)
    period_tag = f"{doc.issue.year % 100:02d}{doc.issue.month:02d}"
    message_id = f"MSG-DEMO-{supplier.key.upper()[:6]}-{period_tag}"
    language = supplier.language or "cs"
    texts = MAIL_TEXTS[language]
    treatment = supplier.vat_treatment or "standard"
    reverse_charge = treatment == "reverseCharge"
    # Papír dodavatele se samovyměřením tiskne 0 % a druh plnění (D28);
    # doklad nese cz-217 / 21 % — obě hodnoty plynou z téhož pravidla.
    data = {
        "docNumber": number,
        "paymentReference": doc.payment_reference,
        "dates": {
            "issue": doc.issue.isoformat(), "tax": doc.tax_point.isoformat() if doc.tax_point else None,
            "due": doc.due.isoformat() if doc.due else None,
            "periodFrom": doc.period_from.isoformat() if doc.period_from else None,
            "periodTo": doc.period_to.isoformat() if doc.period_to else None,
        },
        "currency": doc.currency,
        "vatTreatment": treatment,
        "payment": {"method": "bankTransfer", "specificSymbol": None, "constantSymbol": None},
        "contentTag": next((r.content_tag for r in doc.rows if r.content_tag), None),
        "rows": [{"text": r.description, "qty": r.quantity, "unit": r.unit_print, "unitPrice": r.unit_price,
                  "vatPct": 0 if reverse_charge else r.vat_pct,
                  **({"supplyKind": r.supply_kind} if reverse_charge else {})}
                 for r in doc.rows],
        "rounding": doc.rounding,
        "notes": {"onDocument": None},
    }
    if doc.rounding == "czk":
        # Zaokrouhlení na koruny jen tam, kde ho Shipard umí odvodit z částky
        # (|rozdíl| v pásmu 0,01–1,00); jinak doklad bez zaokrouhlení.
        plain = compute({**data, "rounding": "none"}, vat_treatment=treatment)
        difference = abs(plain["totalAmount"].quantize(Decimal("1"), rounding=ROUND_HALF_UP) - plain["totalAmount"])
        if not Decimal("0.01") < difference < Decimal("1"):
            data["rounding"] = "none"
            doc.rounding = "none"
    computed = compute(data, vat_treatment=treatment)
    note = _invoice_note(doc, language)
    template = supplier.template or "a"
    if template_locale(template) == "en":
        # Anglická šablona korpusu: hodnoty z kanonických identit (měna,
        # země v adrese, klauzule reverse charge).
        values = template_values({**data, "notes": {"onDocument": note}}, supplier.identity, story.own.identity,
                                 computed, locale="en")
    else:
        invoice = {
            "docNumber": number, "variableSymbol": doc.payment_reference or "0",
            "dates": {"issue": data["dates"]["issue"], "tax": data["dates"]["tax"], "due": data["dates"]["due"]},
            "notes": {"onDocument": note},
        }
        values = invoice_values(invoice, flat_party(supplier.identity, supplier.accent), own_flat, computed)
    html = render_html(templates[template], values)
    expected_like = {"totals": {"totalAmount": computed["totalAmount"]}, "currency": doc.currency, "docNumber": number}
    return MailRec(
        key=f"mail:{message_id}", message_id=message_id, sender=supplier,
        subject=texts["subject"].format(number=number),
        body=texts["body"].format(number=number, supplier=supplier.name),
        received_at=received_at, analyzed_at=analyzed_at, primary_type="invoiceReceived",
        doc_state=20 if open_proposal else 40, ai_title=ai_title(expected_like, supplier.name, False),
        pdf_name=f"{number}.pdf".replace("/", "-"), html=html, canonical_data=data, computed=computed,
        resolution=None if open_proposal else 40, resolved_at=None if open_proposal else ts(received_day, 9, minute),
        content_tag=data["contentTag"],
    )


def _invoice_note(doc: DocRec, language: str = "cs") -> str:
    texts = MAIL_TEXTS[language]
    if doc.period_from and doc.period_to:
        return texts["period_note"].format(
            from_=f"{doc.period_from.day}. {doc.period_from.month}. {doc.period_from.year}",
            to=f"{doc.period_to.day}. {doc.period_to.month}. {doc.period_to.year}",
        )
    return texts["note"]


def expand_other_mail(story: Story) -> list[MailRec]:
    mails: list[MailRec] = []
    for msg in story.mail:
        party = story.party(msg["party"])
        received_day = days_before(story.anchor, int(msg["received"]["daysBeforeAnchor"]))
        hour, minute = (int(x) for x in str(msg["received"]["time"]).split(":"))
        due = story.anchor + timedelta(days=int(msg["dueInDays"])) if "dueInDays" in msg else None
        due_text = f"{due.day}. {due.month}. {due.year}" if due else ""
        mails.append(MailRec(
            key=f"mail:{msg['messageId']}", message_id=msg["messageId"], sender=party, subject=msg["subject"],
            body=msg["body"], received_at=ts(received_day, hour, minute), analyzed_at=ts(received_day, hour, minute + 1),
            primary_type="other", doc_state=10, ai_title=msg["title"].format(dueDate=due_text),
            attention=msg["attention"], action_note=msg.get("actionNote"), action_due=due,
        ))
    return mails


# ── Pokladna a mzdy ──────────────────────────────────────────────────────────

def expand_cash(story: Story, catalogs: Catalogs, ordinal: int) -> tuple[list[DocRec], int]:
    docs: list[DocRec] = []
    period = story.period
    register = story.cash_register
    codes = register["items"]
    lo_qty, hi_qty = register["qty"]
    for month_index, offset in enumerate(period.offsets):
        for day_index, day in enumerate(register["days"]):
            issue = month_date(period, offset, day)
            if not in_period(period, issue):
                continue
            item = story.items[codes[(month_index + day_index) % len(codes)]]
            qty = Decimal(spread(lo_qty, hi_qty, offset, day_index, 2))
            ordinal += 1
            docs.append(DocRec(
                key=f"cashreg:{offset}:{day}", doc_type="cashreg", ordinal=ordinal, issue=issue, accounting=issue,
                due=issue, tax_point=issue, vat_obligation=issue, currency="CZK", rate=Decimal("1"),
                vat_mode="fromBase", vat_place="domestic", payment_method="cash", partner_key=None,
                self_party="supplier", state=40, rounding="czk", cash_desk=story.cash_desk_code,
                series_code=story.cash_desk_code,
                rows=[RowRec(operation="sale.goods", item_code=item.code, item_name=item.name, description=item.name,
                             unit=item.unit, unit_print=catalogs.unit_shortcuts.get(item.unit, item.unit), quantity=qty,
                             unit_price=item.price, vat_pct=item.vat_pct, vat_code=VAT_OUTPUT[item.vat_pct])],
            ))
        for rule_index, rule in enumerate(story.cash_expenses):
            issue = month_date(period, offset, rule["day"])
            if not in_period(period, issue):
                continue
            offer = catalogs.offer[rule["item"]]
            lo, hi = Decimal(rule["amount"][0]), Decimal(rule["amount"][1])
            amount = spread_decimal(lo, hi, Decimal(rule["step"]), offset, rule_index, 3)
            vat_none = rule.get("vat") == "none"
            pct = None if vat_none else int(rule["vatPct"])
            ordinal += 1
            docs.append(DocRec(
                key=f"cash:{offset}:{rule_index}", doc_type="cash", ordinal=ordinal, issue=issue, accounting=issue,
                due=issue, tax_point=issue, vat_obligation=issue, currency="CZK", rate=Decimal("1"),
                vat_mode="none" if vat_none else "fromBase", vat_place="domestic", payment_method="cash",
                partner_key=None, self_party="customer", state=40, cash_desk=story.cash_desk_code, cash_direction=2,
                series_code=story.cash_desk_code,
                rows=[RowRec(operation="acc.entry", item_code=offer.code, item_name=offer.name_cs,
                             description=rule["text"], unit="pcs", unit_print=catalogs.unit_shortcuts.get("pcs", "pcs"),
                             quantity=Decimal("1"), unit_price=amount, vat_pct=pct,
                             vat_code=None if vat_none else VAT_INPUT[pct])],
            ))
    return docs, ordinal


def expand_payroll(story: Story, ordinal: int) -> tuple[list[DocRec], int]:
    docs: list[DocRec] = []
    period = story.period
    payroll = story.payroll
    for offset in period.offsets:
        issue = clamp_day(period.month_start(offset), int(payroll["day"]))
        if not in_period(period, issue):
            continue
        figures = {**payroll["figures"], **((payroll.get("months") or {}).get(str(offset)) or {})}
        rows = [RowRec(operation="acc.record", description=row["text"], account=row["account"], acc_side=row["side"],
                       total_price=Decimal(figures[row["amount"]]))
                for row in payroll["rows"]]
        ordinal += 1
        docs.append(DocRec(
            key=f"payroll:{offset}", doc_type="cmnbkp", ordinal=ordinal, issue=issue, accounting=issue,
            due=issue + timedelta(days=14), tax_point=issue, vat_obligation=issue, currency="CZK", rate=Decimal("1"),
            vat_mode="none", vat_place="domestic", payment_method="bankTransfer", partner_key=None, self_party=None,
            state=40, rows=rows, doc_text=payroll["text"].format(period=period_label(period.month_start(offset))),
            vat_registration=False,
        ))
    return docs, ordinal


# ── Spisovna ─────────────────────────────────────────────────────────────────

def _registry_date(spec: dict, story: Story, *, valid_from: date | None = None) -> date:
    period = story.period
    if "afterAnchorDays" in spec:
        return story.anchor + timedelta(days=int(spec["afterAnchorDays"]))
    if "years" in spec:
        assert valid_from is not None
        try:
            return valid_from.replace(year=valid_from.year + int(spec["years"])) - timedelta(days=1)
        except ValueError:   # 29. 2.
            return valid_from.replace(year=valid_from.year + int(spec["years"]), day=28) - timedelta(days=1)
    return month_date(period, int(spec["month"]), int(spec["day"]))


PROMOTED_REF = {"contract": "contractNumber", "insurance": "policyNumber"}


def expand_registry(story: Story, catalogs: Catalogs, templates: dict[str, str]) -> list[RegistryRec]:
    out: list[RegistryRec] = []
    own_flat = flat_party(story.own.identity)
    for index, entry in enumerate(story.registry):
        partner = story.party(entry["partner"])
        created = _registry_date(entry["created"], story)
        hour, minute = (int(x) for x in str(entry["created"]["time"]).split(":"))
        valid_from = _registry_date(entry["validFrom"], story)
        valid_to = _registry_date(entry["validTo"], story, valid_from=valid_from)
        fields: dict = {}
        signed: date | None = None
        for key, value in entry["kindFields"].items():
            if key == "signedDate" and isinstance(value, dict):
                signed = _registry_date(value, story)
                fields[key] = signed.isoformat()
            else:
                fields[key] = value
        ref_key = PROMOTED_REF.get(entry["docType"])
        if ref_key:
            fields[ref_key] = entry["refNumber"]
        fields["validFrom"] = valid_from.isoformat()
        fields["validTo"] = valid_to.isoformat()
        rec = RegistryRec(
            key=f"registry:{index}", doc_type=entry["docType"], title=entry["title"], summary=entry["summary"],
            partner_key=partner.key, binder=entry["binder"], ref_number=entry["refNumber"], valid_from=valid_from,
            valid_to=valid_to, created_at=ts(created, hour, minute), signed=signed, kind_fields=fields,
            source_kind=entry.get("sourceKind", "manual"), template=entry["template"],
            pdf_name=f"{entry['refNumber']}.pdf",
        )
        rec.html = render_html(templates[rec.template], registry_values(rec, partner.identity, own_flat))
        out.append(rec)
    return out


# ── Platby (D8) ──────────────────────────────────────────────────────────────

@dataclass(frozen=True)
class PaymentEvent:
    doc_key: str
    kind: str              # cash | onTime | late | partial | remainder | never
    paid_on: date | None
    amount: Decimal | None
    direction: int = 1     # 1 příjem (vydaný doklad), 2 výdaj (přijatá faktura)
    reference: str | None = None   # VS platby; None = bez VS
    fault: str | None = None       # missing | typo = záměrně nespárovaná platba (D18)


def _doc_offset(story: Story, doc: DocRec) -> int | None:
    """Měsíc (posun od kotvy), ke kterému vydaný doklad patří."""
    kind, _, rest = doc.key.partition(":")
    if kind == "billing":
        return int(rest.split(":")[1])
    if kind == "adhoc":
        return int(story.adhoc[int(rest)]["month"])
    return None


def typo_reference(reference: str, taken: frozenset[str] = frozenset()) -> str:
    """Deterministický překlep VS: první prohození dvou různých sousedních
    číslic od konce, které nedá číslo jiného dokladu (`taken`); bez takové
    dvojice nula navíc."""
    chars = list(reference)
    for i in range(len(chars) - 2, -1, -1):
        if chars[i] != chars[i + 1]:
            candidate = chars[:]
            candidate[i], candidate[i + 1] = candidate[i + 1], candidate[i]
            text = "".join(candidate)
            if text not in taken:
                return text
    return reference + "0"


def payment_events(story: Story, docs: list[DocRec]) -> list[PaymentEvent]:
    """Události úhrad podle chování odběratelů (vydané doklady, příjem)
    a dodavatelů (přijaté faktury, výdaj): `cash` je už na dokladu,
    zbytek promítne `bank.build_statements`. Odběratel může platbu
    konkrétního měsíce poslat bez VS nebo s překlepem (`payment.unmatched`,
    D18) a částečnou úhradu doplatit (`remainderDays`)."""
    events: list[PaymentEvent] = []
    taken = frozenset({d.number for d in docs if d.number} | {d.payment_reference for d in docs if d.payment_reference})
    for doc in docs:
        if doc.partner_key is None or doc.due is None:
            continue
        if doc.doc_type in ("invno", "invpo"):
            if doc.payment_method == "cash":
                events.append(PaymentEvent(doc.key, "cash", doc.issue, doc.total_amount, reference=doc.payment_reference))
                continue
            party, direction, on_time_shift = story.customers[doc.partner_key], 1, -2
        elif doc.doc_type == "invni":
            party, direction, on_time_shift = story.suppliers[doc.partner_key], 2, -1
        else:
            continue
        behaviour = party.payment
        kind = behaviour.get("behaviour", "onTime")
        reference = doc.payment_reference
        fault = None
        if direction == 1:
            offset = _doc_offset(story, doc)
            fault = next((u["reference"] for u in behaviour.get("unmatched") or [] if u["month"] == offset), None)
            if fault == "missing":
                reference = None
            elif fault == "typo" and reference is not None:
                reference = typo_reference(reference, taken)
        if kind == "onTime":
            events.append(PaymentEvent(doc.key, kind, doc.due + timedelta(days=on_time_shift), doc.total_amount,
                                       direction, reference, fault))
        elif kind == "late":
            events.append(PaymentEvent(doc.key, kind, doc.due + timedelta(days=int(behaviour["days"])), doc.total_amount,
                                       direction, reference, fault))
        elif kind == "partial":
            pct = Decimal(behaviour["pct"]) / Decimal("100")
            first = (doc.total_amount * pct).quantize(Decimal("0.01"))
            events.append(PaymentEvent(doc.key, kind, doc.due, first, direction, reference, fault))
            if "remainderDays" in behaviour:
                events.append(PaymentEvent(doc.key, "remainder", doc.due + timedelta(days=int(behaviour["remainderDays"])),
                                           doc.total_amount - first, direction, reference, fault))
        else:
            events.append(PaymentEvent(doc.key, kind, None, None, direction, reference, fault))
    return events


# ── Celek ────────────────────────────────────────────────────────────────────

def _check_assets(assets: list[AssetRec], received: list[DocRec]) -> None:
    """Každá karta má fakturu s řádkem pořízení ve stavu V pořádku a zařazení
    není před dodáním."""
    by_key = {d.key: d for d in received}
    for asset in assets:
        doc = by_key.get(asset.purchase_doc_key)
        if doc is None or doc.state != 40:
            raise ExpandError(f"majetek {asset.number}: faktura {asset.purchase_doc_key} s pořízením v sadě není (anchorMonth open / skip?)")
        if not any(r.operation == PURCHASE_ASSET and r.asset == asset.number for r in doc.rows):
            raise ExpandError(f"majetek {asset.number}: faktura {doc.key} nemá řádek pořízení s kartou")
        if asset.activation < doc.issue:
            raise ExpandError(f"majetek {asset.number}: zařazení {asset.activation} před dodáním {doc.issue}")


def expand(story: Story, catalogs: Catalogs, *, invoice_templates: dict[str, str],
           registry_templates: dict[str, str]) -> Records:
    ordinal = 0
    work_orders = expand_work_orders(story)
    assets = expand_assets(story, catalogs)
    issued, ordinal = expand_issued(story, catalogs, ordinal, work_orders)
    received, received_mails, ordinal = expand_received(
        story, catalogs, invoice_templates, ordinal, project_purchases(story, work_orders, issued),
        asset_purchases(story, assets), asset_cost_links(story, assets),
    )
    _check_assets(assets, received)
    cash, ordinal = expand_cash(story, catalogs, ordinal)
    payroll, ordinal = expand_payroll(story, ordinal)
    # Otevírací a vyrovnávací doklady (fáze 2a) před číslováním — čísla
    # `cmnbkp` se prolnou s mzdami podle data.
    bank_docs, ordinal = expand_bank_docs(story, payroll, ordinal)
    docs = issued + received + cash + payroll + bank_docs
    assign_numbers(docs)
    for doc in docs:
        calc_document(doc)
    statements = build_statements(story, docs, payment_events(story, docs))
    mails = received_mails + expand_other_mail(story)
    ids = [m.message_id for m in mails]
    if len(set(ids)) != len(ids):
        raise ExpandError("duplicitní messageId: " + ", ".join(sorted({i for i in ids if ids.count(i) > 1})))
    return Records(
        persons=expand_persons(story),
        items=expand_items(story),
        docs=docs,
        mails=mails,
        registry=expand_registry(story, catalogs, registry_templates),
        setup=story.setup,
        bank=statements,
        work_orders=work_orders,
        assets=assets,
    )
