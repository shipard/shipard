"""Souhrn ekonomiky příběhu (tasks/demo-dataset-economy.md, #131 D25):
výnosy, náklady, výsledek a zisk v % za období, měsíce ve ztrátě, podíl
zahraničních tržeb, nákup a prodej zboží, nejnižší a konečný zůstatek účtů,
výnosy, náklady a marže projektů (fáze 2b, D36 — doklady s dimenzí
zakázky) — ze stejných záznamů, ze kterých vznikají soubory sady (ne
čtením výstupu).

Výpočet zrcadlí Výsledovku „Od počátku roku k měsíci kotvy“: jen doklady
ve stavu V pořádku (koncepty se neúčtují), částky v domácí měně kurzem
dokladu (`total_base × rate` ≈ `total_base_dom`), měsíc = datum vystavení
(= účetní datum všech dokladů sady). Mzdy = řádky na straně MD mzdového
dokladu (521 + 524), poplatky z výpisů (`fee.out` → 568). Zálohová faktura
výnos není, převody mezi vlastními účty se výsledku netýkají.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal, ROUND_HALF_UP

from .model import DocRec, Records, StatementRec
from .story import Story

__all__ = ["AccountBalance", "EconomySummary", "MonthFigures", "ProjectFigures", "format_summary", "summarize"]

REVENUE_TYPES = frozenset({"invno", "cashreg"})      # vydané faktury, prodejky (602 / 604)
SUPPLIER_TYPES = frozenset({"invni"})                 # přijaté faktury (5xx)
CASH_EXPENSE_TYPES = frozenset({"cash"})              # výdajové pokladní doklady
GOODS_PURCHASE_PREFIX = "501"                         # nákup zboží (metoda B: rovnou do nákladů)
FEE_OPERATION = "fee.out"
ZERO = Decimal("0.00")


def _r2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


@dataclass
class MonthFigures:
    month: date                    # první den měsíce
    revenue: Decimal = ZERO
    costs: Decimal = ZERO

    @property
    def result(self) -> Decimal:
        return self.revenue - self.costs


@dataclass(frozen=True)
class AccountBalance:
    code: str
    currency: str
    opening: Decimal
    lowest: Decimal
    closing: Decimal


@dataclass
class ProjectFigures:
    """Projekt (zakázka typu project): výnosy = faktury s dimenzí zakázky
    na hlavičce, náklady = řádky přijatých faktur s dimenzí zakázky."""
    key: str
    number: str
    title: str
    revenue: Decimal = ZERO
    costs: Decimal = ZERO

    @property
    def result(self) -> Decimal:
        return self.revenue - self.costs

    @property
    def margin(self) -> Decimal:
        return self.result / self.revenue if self.revenue else Decimal("0")


@dataclass
class EconomySummary:
    revenue: Decimal = ZERO
    foreign_revenue: Decimal = ZERO        # tržby odběratelům mimo zemi vlastní firmy
    wages: Decimal = ZERO                  # mzdové náklady (521 + 524)
    suppliers: Decimal = ZERO              # přijaté faktury
    cash_expenses: Decimal = ZERO          # výdajové pokladní doklady
    fees: Decimal = ZERO                   # bankovní poplatky
    goods_sold: Decimal = ZERO             # prodej zboží (řádky sale.goods)
    goods_bought: Decimal = ZERO           # nákup zboží (položky 501xxx na přijatých fakturách)
    months: list[MonthFigures] = field(default_factory=list)
    balances: list[AccountBalance] = field(default_factory=list)
    projects: list[ProjectFigures] = field(default_factory=list)

    @property
    def costs(self) -> Decimal:
        return self.wages + self.suppliers + self.cash_expenses + self.fees

    @property
    def result(self) -> Decimal:
        return self.revenue - self.costs

    @property
    def margin(self) -> Decimal:
        """Zisk jako podíl výnosů (0,10 = 10 %); bez výnosů 0."""
        return self.result / self.revenue if self.revenue else Decimal("0")

    @property
    def foreign_share(self) -> Decimal:
        return self.foreign_revenue / self.revenue if self.revenue else Decimal("0")

    @property
    def loss_months(self) -> list[MonthFigures]:
        return [m for m in self.months if m.result < 0]

    def balance(self, code: str) -> AccountBalance:
        return next(b for b in self.balances if b.code == code)


def _dom(doc: DocRec, value: Decimal | None) -> Decimal:
    return _r2((value or Decimal("0")) * doc.rate)


def _month_of(doc: DocRec) -> date:
    return doc.accounting.replace(day=1)


def _balances(statements: list[StatementRec]) -> list[AccountBalance]:
    out: list[AccountBalance] = []
    by_account: dict[str, list[StatementRec]] = {}
    for statement in statements:
        by_account.setdefault(statement.account_code, []).append(statement)
    for code, items in by_account.items():
        items = sorted(items, key=lambda s: s.period_start)
        running = items[0].opening
        lowest = running
        for statement in items:
            for tx in statement.transactions:
                running += tx.amount
                lowest = min(lowest, running)
        out.append(AccountBalance(code=code, currency=items[0].currency, opening=items[0].opening, lowest=lowest,
                                  closing=items[-1].closing))
    return out


def summarize(story: Story, records: Records) -> EconomySummary:
    summary = EconomySummary()
    months: dict[date, MonthFigures] = {offset_start: MonthFigures(offset_start) for offset_start in story.period.months}

    def month(doc: DocRec) -> MonthFigures:
        return months.setdefault(_month_of(doc), MonthFigures(_month_of(doc)))

    for doc in records.docs:
        if doc.state != 40:
            continue
        if doc.doc_type in REVENUE_TYPES:
            base = _dom(doc, doc.total_base)
            summary.revenue += base
            month(doc).revenue += base
            if doc.partner_key is not None and story.party(doc.partner_key).country != story.own.country:
                summary.foreign_revenue += base
            for row in doc.rows:
                if row.operation == "sale.goods":
                    summary.goods_sold += _dom(doc, row.vat_base if row.vat_base is not None else row.total_price)
        elif doc.doc_type in SUPPLIER_TYPES:
            base = _dom(doc, doc.total_base)
            summary.suppliers += base
            month(doc).costs += base
            for row in doc.rows:
                if (row.item_code or "").startswith(GOODS_PURCHASE_PREFIX):
                    summary.goods_bought += _dom(doc, row.vat_base if row.vat_base is not None else row.total_price)
        elif doc.doc_type in CASH_EXPENSE_TYPES:
            base = _dom(doc, doc.total_base)
            summary.cash_expenses += base
            month(doc).costs += base
        elif doc.doc_type == "cmnbkp" and doc.key.startswith("payroll:"):
            wages = sum((row.total_price or Decimal("0")) for row in doc.rows if row.acc_side == "debit")
            summary.wages += wages
            month(doc).costs += wages

    for statement in records.bank:
        for tx in statement.transactions:
            if tx.operation == FEE_OPERATION:
                fee = _r2(-tx.amount * (tx.exchange_rate or Decimal("1")))
                summary.fees += fee
                key = tx.date.replace(day=1)
                months.setdefault(key, MonthFigures(key)).costs += fee

    summary.months = [months[key] for key in sorted(months)]
    summary.balances = _balances(records.bank)
    summary.projects = _projects(records)
    return summary


def _projects(records: Records) -> list[ProjectFigures]:
    out: list[ProjectFigures] = []
    for work_order in records.work_orders:
        if not work_order.key.startswith("project:"):
            continue
        figures = ProjectFigures(key=work_order.key.removeprefix("project:"), number=work_order.number, title=work_order.title)
        for doc in records.docs:
            if doc.state != 40:
                continue
            if doc.doc_type in REVENUE_TYPES and doc.work_order == work_order.number:
                figures.revenue += _dom(doc, doc.total_base)
            elif doc.doc_type in SUPPLIER_TYPES:
                for row in doc.rows:
                    if row.work_order == work_order.number:
                        figures.costs += _dom(doc, row.vat_base if row.vat_base is not None else row.total_price)
        out.append(figures)
    return out


# ── Výpis pro build.py ───────────────────────────────────────────────────────

def _amount(value: Decimal, currency: str = "CZK") -> str:
    whole = value.quantize(Decimal("1"), rounding=ROUND_HALF_UP)
    text = f"{whole:,}".replace(",", " ").replace("-", "−")
    return f"{text} {'Kč' if currency == 'CZK' else currency}"


def _pct(value: Decimal) -> str:
    return f"{(value * 100).quantize(Decimal('0.1'), rounding=ROUND_HALF_UP)}".replace(".", ",") + " %"


def format_summary(summary: EconomySummary) -> list[str]:
    """Řádky souhrnu pro výstup `build.py` (bez odsazení)."""
    sign = "+" if summary.result >= 0 else ""
    lines = [
        f"výnosy {_amount(summary.revenue)}, náklady {_amount(summary.costs)} "
        f"(mzdy {_amount(summary.wages)}, dodavatelé {_amount(summary.suppliers)}, "
        f"pokladna {_amount(summary.cash_expenses)}, poplatky {_amount(summary.fees)}), "
        f"výsledek {sign}{_amount(summary.result)} = {_pct(summary.margin)} výnosů",
    ]
    losses = summary.loss_months
    if losses:
        detail = ", ".join(f"{m.month.month:02d}/{m.month.year} ({_amount(m.result)})" for m in losses)
        lines.append(f"měsíce ve ztrátě: {len(losses)} z {len(summary.months)} — {detail}")
    else:
        lines.append(f"měsíce ve ztrátě: žádný z {len(summary.months)}")
    ratio = (summary.goods_sold / summary.goods_bought).quantize(Decimal("0.01")) if summary.goods_bought else None
    lines.append(
        f"zahraniční odběratelé {_pct(summary.foreign_share)} tržeb; zboží: prodej {_amount(summary.goods_sold)}, "
        f"nákup {_amount(summary.goods_bought)}" + (f" ({str(ratio).replace('.', ',')}×)" if ratio is not None else "")
    )
    for balance in summary.balances:
        lines.append(
            f"účet {balance.code}: počátek {_amount(balance.opening, balance.currency)}, "
            f"minimum {_amount(balance.lowest, balance.currency)}, konec {_amount(balance.closing, balance.currency)}"
        )
    for project in summary.projects:
        sign = "+" if project.result >= 0 else ""
        lines.append(
            f"projekt {project.number} {project.title}: výnosy {_amount(project.revenue)}, náklady {_amount(project.costs)}, "
            f"marže {sign}{_amount(project.result)} = {_pct(project.margin)}"
        )
    return lines
