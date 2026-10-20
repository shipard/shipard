"""Rozvinutí příběhu na záznamy sady (tasks/demo-dataset-phase1.md D6):
pravidla + kotva → osoby, položky, doklady (vydané faktury, zálohová,
přijaté faktury se zprávami, prodejky, výdajové pokladní doklady, mzdy),
zprávy k řešení a dokumenty spisovny. Deterministicky — rozmanitost jen
ze `timeline.spread` nad (měsíc, pořadí partnera, pořadí pravidla).

Model plateb (D8) je oddělený: `payment_events()` vrací události
úhrad podle chování odběratelů a dodavatelů; hotovost je na dokladu
(pokladna), zbytek promítá `bank.py` do bankovních výpisů (fáze 2a).
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP

from .bank import build_statements, expand_bank_docs
from .catalogs import Catalogs
from .collation import czech_key
from .compute import compute
from .demoprint import flat_party, invoice_values, registry_values
from .doccalc import calc_document
from .errors import ExpandError
from .model import DocRec, MailRec, PersonRec, Records, RegistryRec, RowRec
from .render import render_html
from .snapshot import ai_title
from .story import Party, Story
from .timeline import (
    clamp_day, days_before, fiscal_year, in_period, month_date, month_end, period_label, quarter_label,
    quarter_offsets, spread, spread_decimal, ts,
)

__all__ = ["DOC_ID_CODES", "ExpandError", "PaymentEvent", "assign_numbers", "expand", "format_number", "payment_events"]

# Číslování (modules/docs/core/config/docTypes.jsonc): %D kód typu, %y dva
# znaky fiskálního roku, %C kód řady (pokladna), %4 / %5 pořadí.
DOC_ID_CODES = {"invno": "1", "invpo": "12", "invni": "2", "invpi": "22", "cmnbkp": "60", "cash": "31", "cashreg": "14"}
BOUND_TYPES = frozenset({"cash", "cashreg"})   # series_binding cash_desk → %D%C%y%5

VAT_OUTPUT = {21: "cz-120", 12: "cz-121", 0: "cz-122"}
VAT_INPUT = {21: "cz-110", 12: "cz-111", 0: "cz-112"}
VAT_EU_SERVICES = "cz-202"

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
    return Decimal("1") if currency == "CZK" else story.rates[currency][offset]


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
              price: Decimal | None = None, catalogs: Catalogs | None = None) -> RowRec:
    item = story.items[code]
    vat_code, _ = _customer_vat(customer, item.vat_pct)
    # Řádek bez textu dostane po seedu popis = název položky (DocDocument).
    return RowRec(
        operation="sale.services" if item.kind == "service" else "sale.goods",
        item_code=item.code, item_name=item.name, description=text if text is not None else item.name, unit=item.unit,
        unit_print=catalogs.unit_shortcuts.get(item.unit, item.unit) if catalogs else item.unit,
        quantity=qty, unit_price=price if price is not None else item.price,
        vat_pct=0 if vat_code == VAT_EU_SERVICES else item.vat_pct, vat_code=vat_code,
    )


def expand_issued(story: Story, catalogs: Catalogs, ordinal: int) -> tuple[list[DocRec], int]:
    docs: list[DocRec] = []
    period = story.period
    inv = story.invoicing
    for rule_index, rule in enumerate(story.billing):
        customer = story.customers[rule["customer"]]
        every = rule["every"]
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
            price = Decimal(rule["price"])
            if "priceFrom" in rule and offset >= rule["priceFrom"]["month"]:
                price = Decimal(rule["priceFrom"]["price"])
            month_start = period.month_start(offset)
            if every == "quarter":
                text = inv["quarterlyText"].format(period=quarter_label(month_start))
            elif every == "month":
                text = inv["flatFeeText"].format(period=period_label(month_start))
            else:
                text = story.items[rule["item"]].name
            qty = Decimal(rule.get("qty", 1))
            state = 10 if (offset == 0 and rule.get("conceptInAnchorMonth")) else 40
            doc_type = "invpo" if rule.get("docType") == "proformaIssued" else "invno"
            ordinal += 1
            docs.append(_issued_doc(
                story, key=f"billing:{rule_index}:{offset}", ordinal=ordinal, doc_type=doc_type, customer=customer,
                offset=offset, issue=issue, due_days=int(rule.get("dueDays", inv["dueDays"])),
                rows=[_item_row(story, customer, rule["item"], qty, text, price, catalogs)], state=state, cash=False,
            ))
    customer_order = {key: i for i, key in enumerate(story.customers)}
    for entry_index, entry in enumerate(story.adhoc):
        customer = story.customers[entry["customer"]]
        offset = entry["month"]
        issue = month_date(period, offset, entry["day"])
        if not in_period(period, issue):
            continue
        rows: list[RowRec] = []
        for row_index, row in enumerate(entry["rows"]):
            if "hours" in row:
                lo, hi = row["hours"]
                qty = Decimal(spread(lo, hi, offset, customer_order[customer.key], entry_index, row_index))
                text = f"Servisní práce technika — {period_label(period.month_start(offset))}"
            else:
                qty = Decimal(row["qty"])
                text = None
            rows.append(_item_row(story, customer, row["item"], qty, text, catalogs=catalogs))
        ordinal += 1
        docs.append(_issued_doc(
            story, key=f"adhoc:{entry_index}", ordinal=ordinal, doc_type="invno", customer=customer, offset=offset,
            issue=issue, due_days=inv["dueDays"], rows=rows, state=10 if entry.get("state") == "concept" else 40,
            cash=entry.get("payment") == "cash",
        ))
    return docs, ordinal


# ── Přijaté faktury a zprávy ─────────────────────────────────────────────────

def _supplier_number(supplier: Party, counter: int) -> str:
    spec = supplier.doc_number or {}
    return spec["pattern"].format(seq=int(spec["seqStart"]) + counter)


def _digits(text: str, limit: int = 10) -> str:
    digits = "".join(ch for ch in text if ch.isdigit())
    return digits[-limit:] if digits else "0"


def _received_rows(story: Story, supplier: Party, rule: dict, offset: int, supplier_order: int,
                   catalogs: Catalogs, labels: dict[str, str]) -> list[RowRec]:
    rows: list[RowRec] = []
    previous_qty: Decimal | None = None
    for row_index, row in enumerate(rule["rows"]):
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
            unit_print=catalogs.unit_shortcuts.get(unit, unit), quantity=qty, unit_price=price, vat_pct=pct,
            vat_code=VAT_INPUT[pct], content_tag=offer.content_tags[0] if offer.content_tags else None,
        ))
    return rows


def _pattern_label(doc_number_pattern: str, issue: date) -> dict[str, str]:
    return {"yyyy": str(issue.year), "yy": f"{issue.year % 100:02d}"}


def expand_received(story: Story, catalogs: Catalogs, templates: dict[str, str], ordinal: int
                    ) -> tuple[list[DocRec], list[MailRec], int]:
    docs: list[DocRec] = []
    mails: list[MailRec] = []
    period = story.period
    own_flat = flat_party(story.own.identity)
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
        counter = 0
        for offset in sorted(offsets):
            issue = month_date(period, offset, rule["day"])
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
            rows = _received_rows(story, supplier, rule, offset, supplier_order, catalogs, labels)
            rounding = rule.get("rounding", "none")
            doc_key = f"received:{key}:{offset}"
            ordinal += 1
            doc = DocRec(
                key=doc_key, doc_type="invni", ordinal=ordinal, issue=issue, accounting=issue, due=due,
                tax_point=tax_point, vat_obligation=tax_point, period_from=period_from, period_to=period_to,
                currency="CZK", rate=Decimal("1"), vat_mode="fromBase", vat_place="domestic",
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
    data = {
        "docNumber": number,
        "paymentReference": doc.payment_reference,
        "dates": {
            "issue": doc.issue.isoformat(), "tax": doc.tax_point.isoformat() if doc.tax_point else None,
            "due": doc.due.isoformat() if doc.due else None,
            "periodFrom": doc.period_from.isoformat() if doc.period_from else None,
            "periodTo": doc.period_to.isoformat() if doc.period_to else None,
        },
        "currency": "CZK",
        "vatTreatment": "standard",
        "payment": {"method": "bankTransfer", "specificSymbol": None, "constantSymbol": None},
        "contentTag": next((r.content_tag for r in doc.rows if r.content_tag), None),
        "rows": [{"text": r.description, "qty": r.quantity, "unit": r.unit_print, "unitPrice": r.unit_price, "vatPct": r.vat_pct}
                 for r in doc.rows],
        "rounding": doc.rounding,
        "notes": {"onDocument": None},
    }
    if doc.rounding == "czk":
        # Zaokrouhlení na koruny jen tam, kde ho Shipard umí odvodit z částky
        # (|rozdíl| v pásmu 0,01–1,00); jinak doklad bez zaokrouhlení.
        plain = compute({**data, "rounding": "none"})
        difference = abs(plain["totalAmount"].quantize(Decimal("1"), rounding=ROUND_HALF_UP) - plain["totalAmount"])
        if not Decimal("0.01") < difference < Decimal("1"):
            data["rounding"] = "none"
            doc.rounding = "none"
    computed = compute(data)
    invoice = {
        "docNumber": number, "variableSymbol": doc.payment_reference or "0",
        "dates": {"issue": data["dates"]["issue"], "tax": data["dates"]["tax"], "due": data["dates"]["due"]},
        "notes": {"onDocument": _invoice_note(doc)},
    }
    html = render_html(templates[supplier.template or "a"], invoice_values(invoice, flat_party(supplier.identity, supplier.accent), own_flat, computed))
    expected_like = {"totals": {"totalAmount": computed["totalAmount"]}, "currency": "CZK", "docNumber": number}
    return MailRec(
        key=f"mail:{message_id}", message_id=message_id, sender=supplier, subject=f"Faktura {number}",
        body=f"Dobrý den,\n\nv příloze zasíláme fakturu {number}.\n\n{supplier.name}",
        received_at=received_at, analyzed_at=analyzed_at, primary_type="invoiceReceived",
        doc_state=20 if open_proposal else 40, ai_title=ai_title(expected_like, supplier.name, False),
        pdf_name=f"{number}.pdf".replace("/", "-"), html=html, canonical_data=data, computed=computed,
        resolution=None if open_proposal else 40, resolved_at=None if open_proposal else ts(received_day, 9, minute),
        content_tag=data["contentTag"],
    )


def _invoice_note(doc: DocRec) -> str:
    if doc.period_from and doc.period_to:
        return (f"Vyúčtování za období {doc.period_from.day}. {doc.period_from.month}. {doc.period_from.year} – "
                f"{doc.period_to.day}. {doc.period_to.month}. {doc.period_to.year}.")
    return "Děkujeme za včasnou úhradu."


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

def expand(story: Story, catalogs: Catalogs, *, invoice_templates: dict[str, str],
           registry_templates: dict[str, str]) -> Records:
    ordinal = 0
    issued, ordinal = expand_issued(story, catalogs, ordinal)
    received, received_mails, ordinal = expand_received(story, catalogs, invoice_templates, ordinal)
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
    )
