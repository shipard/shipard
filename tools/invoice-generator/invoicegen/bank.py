"""Bankovní výpisy demo sady (tasks/demo-dataset-phase2a-bank.md, #131 D17,
D18, D22, U1): události úhrad (`expand.payment_events`) → transakce na
našich účtech → měsíční výpisy per účet; k tomu otevírací doklad
(počáteční zůstatek 221 / 701) a vyrovnávací interní doklady mezd a odvodů
(MD závazek / DAL clearing — D22).

Transakce nese jen to, co dodá banka: partnera i párování s fakturou
dopočítá aplikace po seedu (D17). Určitost: pořadí vzniku transakcí je
pořadí dokladů, pak mzdy, pak poplatky; v rámci dne rozhoduje toto pořadí.
"""

from __future__ import annotations

import re
from datetime import date, timedelta
from decimal import Decimal

from .errors import ExpandError
from .model import DocRec, RowRec, StatementRec, TxRec
from .story import Story
from .timeline import in_period, month_date, month_end, period_label

OPENING_DOC_TEXT = "Počáteční stav bankovních účtů"
OPENING_ROW_TEXT = "Počáteční účet rozvažný"
CLEARING_ROW_TEXT = "Přeúčtování z nespárovaných plateb"


def normalize_account(text: str) -> tuple[str, str]:
    """Tvary, které porovnává `PartnerResolver`: celé číslo bez oddělovačů
    a část před lomítkem (bez kódu banky)."""
    full = re.sub(r"[\s\-/]", "", text).upper()
    prefix = re.sub(r"[\s\-]", "", text.split("/", 1)[0]).upper()
    return full, prefix


def payroll_payment_date(story: Story, offset: int, rule: dict) -> date | None:
    """Platba za měsíc `offset` jde v měsíci následujícím (D22); None =
    mimo období (budoucnost za kotvou)."""
    following = offset + 1
    if following not in story.period.offsets:
        return None
    paid_on = month_date(story.period, following, int(rule["day"]))
    return paid_on if in_period(story.period, paid_on) else None


def _bank_account_row(story: Story, code: str) -> dict:
    for row in story.setup["bank_accounts"]["rows"]:
        if row.get("code") == code:
            return row
    raise ExpandError(f"účet {code} není v setup/bank_accounts.jsonc")


def account_currency(story: Story, code: str) -> str:
    return str(_bank_account_row(story, code).get("currency") or "czk").upper()


def _internal_doc(*, key: str, ordinal: int, day: date, rows: list[RowRec], text: str,
                  fiscal_period_type: str | None = None) -> DocRec:
    """Interní doklad jako mzdy (`expand_payroll`): bez partnera a DPH."""
    return DocRec(
        key=key, doc_type="cmnbkp", ordinal=ordinal, issue=day, accounting=day, due=day + timedelta(days=14),
        tax_point=day, vat_obligation=day, currency="CZK", rate=Decimal("1"), vat_mode="none", vat_place="domestic",
        payment_method="bankTransfer", partner_key=None, self_party=None, state=40, rows=rows, doc_text=text,
        vat_registration=False, fiscal_period_type=fiscal_period_type,
    )


def _payroll_figures(story: Story, offset: int) -> dict:
    payroll = story.payroll
    return {**payroll["figures"], **((payroll.get("months") or {}).get(str(offset)) or {})}


def _payroll_offset(doc: DocRec) -> int:
    return int(doc.key.split(":", 1)[1])


def expand_bank_docs(story: Story, payroll_docs: list[DocRec], ordinal: int) -> tuple[list[DocRec], int]:
    """Otevírací doklad (počáteční zůstatky MD 221xxx / DAL openingAccount
    k prvnímu dni dat, `fiscalPeriodType: opening`) a vyrovnávací doklad ke
    každé platbě mezd a odvodů téhož dne (MD účet závazku z řádku mezd /
    DAL clearingAccount, D22)."""
    docs: list[DocRec] = []
    period = story.period
    bank = story.bank

    rows: list[RowRec] = []
    for code, spec in bank["accounts"].items():
        opening = Decimal(spec["openingBalance"])
        if opening == 0:
            continue
        account = _bank_account_row(story, code).get("accounting_account")
        rows.append(RowRec(operation="acc.record", description=f"Počáteční stav účtu {code}", account=account,
                           acc_side="debit", total_price=opening))
        rows.append(RowRec(operation="acc.record", description=OPENING_ROW_TEXT, account=bank["openingAccount"],
                           acc_side="credit", total_price=opening))
    if rows:
        ordinal += 1
        docs.append(_internal_doc(key="opening", ordinal=ordinal, day=period.start, rows=rows, text=OPENING_DOC_TEXT,
                                  fiscal_period_type="opening"))

    credit_rows = {row["amount"]: row for row in story.payroll["rows"] if row["side"] == "credit"}
    for doc in payroll_docs:
        offset = _payroll_offset(doc)
        figures = _payroll_figures(story, offset)
        for rule in bank["payroll"]:
            paid_on = payroll_payment_date(story, offset, rule)
            if paid_on is None:
                continue
            liability = credit_rows[rule["amount"]]
            amount = Decimal(figures[rule["amount"]])
            text = rule["text"].format(period=period_label(period.month_start(offset)))
            ordinal += 1
            docs.append(_internal_doc(
                key=f"settlement:{offset}:{rule['amount']}", ordinal=ordinal, day=paid_on,
                rows=[
                    RowRec(operation="acc.record", description=liability["text"], account=liability["account"],
                           acc_side="debit", total_price=amount),
                    RowRec(operation="acc.record", description=CLEARING_ROW_TEXT, account=bank["clearingAccount"],
                           acc_side="credit", total_price=amount),
                ],
                text=bank["settlementText"].format(text=text),
            ))
    return docs, ordinal


def build_statements(story: Story, docs: list[DocRec], events) -> list[StatementRec]:
    """Transakce z událostí úhrad (jen doklady ve 40 s číslem, platby do
    kotvy), mezd a odvodů a poplatků → výpisy per účet a kalendářní měsíc
    (prázdný měsíc bez výpisu, poslední končí kotvou). Zůstatek navazuje,
    `externalId` deterministické."""
    period = story.period
    by_key = {doc.key: doc for doc in docs}
    txs: list[TxRec] = []
    ordinal = 0

    for event in events:
        doc = by_key[event.doc_key]
        if event.paid_on is None or event.amount is None or event.kind in ("cash", "never"):
            continue
        if doc.state != 40 or doc.number is None or not in_period(period, event.paid_on):
            continue
        partner = story.party(doc.partner_key)
        bank_account = partner.identity.get("bankAccount") or {}
        counterparty = bank_account.get("accountNumber") or bank_account.get("iban")
        ordinal += 1
        txs.append(TxRec(
            key=f"pay:{doc.key}:{event.kind}", account_code=story.bank_account_for(doc.currency), date=event.paid_on,
            amount=event.amount if event.direction == 1 else -event.amount, ordinal=ordinal,
            counterparty_account=counterparty, counterparty_name=partner.name, payment_reference=event.reference,
            exchange_rate=doc.rate if doc.currency != "CZK" else None, doc_key=doc.key, fault=event.fault,
        ))

    for doc in docs:
        if not doc.key.startswith("payroll:"):
            continue
        offset = _payroll_offset(doc)
        figures = _payroll_figures(story, offset)
        for rule in story.bank["payroll"]:
            paid_on = payroll_payment_date(story, offset, rule)
            if paid_on is None:
                continue
            ordinal += 1
            txs.append(TxRec(
                key=f"payroll:{offset}:{rule['amount']}", account_code=story.bank_account_code, date=paid_on,
                amount=-Decimal(figures[rule["amount"]]), ordinal=ordinal,
                counterparty_account=rule["counterparty"]["account"], counterparty_name=rule["counterparty"]["name"],
                message=rule["text"].format(period=period_label(period.month_start(offset))),
                doc_key=f"settlement:{offset}:{rule['amount']}",
            ))

    for code, spec in story.bank["accounts"].items():
        fee = spec.get("fee")
        if not fee:
            continue
        for offset in period.offsets:
            end = month_end(period.month_start(offset))
            if not in_period(period, end):
                continue   # nedokončený měsíc kotvy — poplatek přijde až s koncem měsíce
            ordinal += 1
            txs.append(TxRec(key=f"fee:{code}:{offset}", account_code=code, date=end, amount=-Decimal(fee["amount"]),
                             ordinal=ordinal, message=fee["text"], operation="fee.out"))

    statements: list[StatementRec] = []
    for code, spec in story.bank["accounts"].items():
        currency = account_currency(story, code)
        balance = Decimal(spec["openingBalance"])
        for offset in period.offsets:
            start = period.month_start(offset)
            month_txs = sorted(
                (t for t in txs if t.account_code == code and (t.date.year, t.date.month) == (start.year, start.month)),
                key=lambda t: (t.date, t.ordinal),
            )
            if not month_txs:
                continue
            opening = balance
            for position, tx in enumerate(month_txs, start=1):
                tx.external_id = f"{code}-{start:%Y%m}-{position:03d}"
                balance += tx.amount
                if balance < 0:
                    raise ExpandError(
                        f"zůstatek účtu {code} klesl {tx.date.isoformat()} na {balance} ({tx.key}) — "
                        f"zvyš bank.accounts.{code}.openingBalance aspoň o {-balance}"
                    )
            statements.append(StatementRec(
                account_code=code, currency=currency, period_start=start,
                period_end=min(month_end(start), period.anchor), number=f"{start.month}/{start.year}",
                external_id=f"{code}-{start:%Y-%m}", opening=opening, closing=balance, transactions=month_txs,
            ))

    _validate(story, statements, docs)
    return statements


def _validate(story: Story, statements: list[StatementRec], docs: list[DocRec]) -> None:
    """Pasti ze zadání: nespárovaná platba má protiúčet osoby, každá platba
    mezd má vyrovnávací doklad téhož dne a částky (a naopak), překlep VS
    nekoliduje s číslem dokladu, kurz jen a právě na účtu v cizí měně."""
    problems: list[str] = []
    by_key = {doc.key: doc for doc in docs}
    numbers = {doc.number for doc in docs if doc.number} | {doc.payment_reference for doc in docs if doc.payment_reference}
    settlements = {doc.key for doc in docs if doc.key.startswith("settlement:")}
    seen_settlements: set[str] = set()
    for statement in statements:
        foreign = statement.currency != "CZK"
        for tx in statement.transactions:
            if not in_period(story.period, tx.date):
                problems.add(f"{tx.key}: datum {tx.date} mimo období sady")
            if tx.fault is not None and not tx.counterparty_account:
                problems.append(f"{tx.key}: záměrně nespárovaná platba musí mít protiúčet osoby (D22)")
            if tx.fault == "typo" and tx.payment_reference in numbers:
                problems.append(f"{tx.key}: překlep VS {tx.payment_reference} koliduje s číslem dokladu — uprav měsíc v payment.unmatched")
            if foreign and tx.exchange_rate is None:
                problems.append(f"{tx.key}: transakce na účtu {statement.account_code} v cizí měně bez kurzu")
            if not foreign and tx.exchange_rate is not None:
                problems.append(f"{tx.key}: kurz na účtu v domácí měně")
            if tx.key.startswith("payroll:"):
                settlement = by_key.get(tx.doc_key or "")
                if settlement is None:
                    problems.append(f"{tx.key}: chybí vyrovnávací doklad {tx.doc_key}")
                elif settlement.issue != tx.date or settlement.total_amount != -tx.amount:
                    problems.append(f"{tx.key}: vyrovnávací doklad {tx.doc_key} má jiný den nebo částku")
                else:
                    seen_settlements.add(settlement.key)
    for key in sorted(settlements - seen_settlements):
        problems.append(f"{key}: vyrovnávací doklad bez platby mezd")
    if problems:
        raise ExpandError("; ".join(problems))
