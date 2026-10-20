"""Záznamy sady ve tvaru, který vypíše `dataset-dump` (D10) — jediné místo
s konstantami odvozenými z PHP exportérů (`PersonExporter`, `ItemExporter`,
`DocumentExporter`, `RegistryExporter`, `MailExporter`): pořadí klíčů,
které hodnoty jsou float (`1.0`) a které int, co prune vynechá, řazení
souborů podle `ORDER BY` exportérů a slugy. Když se sada liší od dumpu,
opravuje se tady, ne v `expand.py`.
"""

from __future__ import annotations

from decimal import Decimal

from .catalogs import Catalogs
from .collation import czech_key
from .datasetio import PERSON_FORMAT, attachment_entry, prune
from .expected import apply_projection, build_expected
from .model import DocRec, MailRec, PersonRec, RegistryRec, RowRec
from .snapshot import analysis_json, analysis_record, classification_json, dataset_canonical, message_record
from .story import Item, Story

DOC_TYPE_NAMES = {
    "invni": "invoiceReceived", "invno": "invoiceIssued", "invpo": "proformaIssued", "invpi": "proformaReceived",
    "cmnbkp": "accountingDocument", "cash": "cashDocument", "cashreg": "cashRegisterDocument",
}
# Pořadí typů v dumpu = řazení MariaDB podle kódu typu (ORDER BY h.doc_type).
DOC_TYPE_ORDER = sorted(DOC_TYPE_NAMES)

APPLY_PERSON = {"mergeStrategy": "createOnly", "matchStrategy": "identifiersOnly", "targetDocState": 40}

MODEL_NAME = "demo-ideal"
PROMPT_VERSION = "demo"


def php_float(value: Decimal | int) -> Decimal:
    """Hodnota sloupce, kterou PHP vypíše jako float (`V::float` +
    `PRESERVE_ZERO_FRACTION`): celé číslo dostane `.0`, desetinné zůstává."""
    d = Decimal(value)
    return d if d.as_tuple().exponent < 0 else d.quantize(Decimal("0.1"))


def _iso(d) -> str | None:
    return d.isoformat() if d is not None else None


# ── Osoby ────────────────────────────────────────────────────────────────────

def person_record(rec: PersonRec) -> dict:
    party = rec.party
    identity = party.identity
    address = identity.get("address") or {}
    contact = identity.get("contact") or {}
    bank = identity.get("bankAccount") or {}
    currency = (party.currency or "CZK").upper()
    contacts = []
    if party.kind == "customer" and contact.get("name"):
        contacts.append({
            "name": contact.get("name"),
            "email": contact.get("email"),
            "phone": contact.get("phone"),
            "orderPos": 0,
            "sendPurposes": list(party.send_purposes),
        })
    record = {
        "format": PERSON_FORMAT,
        "formatVersion": "1.2",
        "personType": "company",
        "country": identity["country"],
        "personId": rec.person_id,
        "companyId": identity.get("companyId"),
        "taxId": identity.get("taxId"),
        "vatId": identity.get("vatId"),
        "courtRegistration": identity.get("courtRegistration"),
        "name": {"fullName": identity["name"]},
        "contact": {"email": contact.get("email"), "phone": contact.get("phone"), "web": contact.get("web")},
        "status": {"isOwn": True if rec.is_own else None, "docState": 40},
        "documents": {"language": party.language, "sendAttachmentsMerged": False},
        "addresses": [{
            "addressType": 1,
            "street": address.get("street"),
            "houseNumber": address.get("houseNumber"),
            "city": address.get("city"),
            "zip": address.get("zip"),
            "country": address.get("country"),
            "orderPos": 0,
        }],
        "bankAccounts": [{
            "accountNumber": bank.get("accountNumber"),
            "iban": bank.get("iban"),
            "bic": bank.get("bic"),
            "currency": currency,
            "source": 0,
            "orderPos": 0,
        }] if bank.get("iban") or bank.get("accountNumber") else [],
        "contacts": contacts,
        "applyOptions": dict(APPLY_PERSON),
    }
    return prune(record)


def person_sort_key(rec: PersonRec) -> tuple:
    return (czech_key(rec.party.name), rec.party.company_id or "", rec.person_id)


def person_slug(rec: PersonRec) -> str:
    return rec.party.name


# ── Položky ──────────────────────────────────────────────────────────────────

def item_record(item: Item, catalogs: Catalogs) -> dict:
    kind = catalogs.item_kinds[item.kind]
    record = {
        "format": "shpd.items.item",
        "formatVersion": "1.0",
        "code": item.code,
        "name": item.name,
        "kind": {"code": kind.code, "name": kind.name_cs, "itemType": kind.item_type},
        "salesPriceNoVat": php_float(item.price),
        "unit": item.unit,
        "accountingAccount": item.account,
        "status": {"docState": 40},
        "applyOptions": dict(APPLY_PERSON),
    }
    return prune(record)


def item_sort_key(item: Item) -> tuple:
    return (item.code.lower(), item.name.lower())


def item_slug(item: Item) -> str:
    return f"{item.code} {item.name}"


# ── Doklady ──────────────────────────────────────────────────────────────────

def party_block(identity: dict, *, with_bank: bool) -> dict:
    """`CanonicalParty::fromPerson` — strana dokladu z osoby v DS."""
    address = identity.get("address") or {}
    contact = identity.get("contact") or {}
    bank = identity.get("bankAccount") or {}
    block = {
        "name": identity["name"],
        "country": identity["country"],
        "companyId": identity.get("companyId"),
        "taxId": identity.get("taxId"),
        "vatId": identity.get("vatId"),
        "courtRegistration": identity.get("courtRegistration"),
        "address": {
            "street": address.get("street"),
            "houseNumber": address.get("houseNumber"),
            "city": address.get("city"),
            "zip": address.get("zip"),
            "country": address.get("country"),
        },
        "contact": {"email": contact.get("email"), "phone": contact.get("phone"), "web": contact.get("web")},
    }
    if with_bank:
        block["bankAccount"] = {"accountNumber": bank.get("accountNumber"), "iban": bank.get("iban"), "bic": bank.get("bic")}
    return block


def _row_block(row: RowRec, position: int) -> dict:
    block: dict = {
        "rowKind": "item",
        "operation": row.operation,
        "orderPos": position,
        "item": {"ourCode": row.item_code, "name": row.item_name} if row.item_code else None,
        "unit": row.unit,
        "quantity": php_float(row.quantity) if row.quantity is not None else None,
        # Kontační řádek (částka bez množství): applier uloží fromTotal a
        # `DocRowCalculator::computePrice` dá jednotkovou cenu 0.
        "unitPrice": php_float(row.unit_price) if row.unit_price is not None else php_float(0),
        "totalPrice": php_float(row.total_price) if row.total_price is not None else None,
        "priceCalcMode": "fromUnitPrice" if row.quantity is not None else "fromTotal",
        "vat": {"code": row.vat_code, "pct": php_float(row.vat_pct if row.vat_pct is not None else 0)} if row.vat_code else None,
        "computed": {
            "vatBase": php_float(row.vat_base) if row.vat_base is not None else None,
            "vatAmount": php_float(row.vat_amount) if row.vat_amount is not None else None,
            "vatTotal": php_float(row.vat_total) if row.vat_total is not None else None,
        },
        "description": row.description,
        "account": row.account,
        "accSide": row.acc_side,
    }
    return block


def partner_side(doc: DocRec) -> str | None:
    """Na které straně dokladu je partner (druhá než `selfParty`)."""
    if doc.self_party == "supplier":
        return "customer"
    if doc.self_party == "customer":
        return "supplier"
    return None


def doc_record(doc: DocRec, story: Story) -> dict:
    side = partner_side(doc)
    party = None
    if doc.partner_key is not None and side is not None:
        party = party_block(story.party(doc.partner_key).identity, with_bank=side == "supplier")
    apply_options: dict = {"targetDocState": doc.state}
    if doc.number is not None:
        apply_options["importNumber"] = {"docNumber": doc.number, "sequenceNumber": doc.sequence}
    if doc.series_code is not None:
        apply_options["numberSeriesCode"] = doc.series_code
    if doc.doc_type in ("invno", "invpo"):
        apply_options["importOwnBankAccount"] = story.bank_account_code
    canonical = {
        "format": "shpd.docs.document",
        "formatVersion": "1.0",
        "docType": DOC_TYPE_NAMES[doc.doc_type],
        "docNumber": doc.supplier_doc_number,
        "docText": doc.doc_text,
        "selfParty": doc.self_party,
        "cashDesk": doc.cash_desk,
        "cashDirection": doc.cash_direction,
        "supplier": party if side == "supplier" else None,
        "customer": party if side == "customer" else None,
        "dates": {
            "issueDate": _iso(doc.issue),
            "dueDate": _iso(doc.due),
            "accountingDate": _iso(doc.accounting),
            "taxPointDate": _iso(doc.tax_point),
            "vatObligationDate": _iso(doc.vat_obligation),
            "periodFrom": _iso(doc.period_from),
            "periodTo": _iso(doc.period_to),
        },
        "currency": doc.currency,
        "exchangeRate": php_float(doc.rate),
        "vat": {
            "mode": doc.vat_mode,
            "place": doc.vat_place,
            "registrationCountry": "cz" if doc.vat_registration else None,
            "recapSource": "computed",
            "calcSource": "header",
            "controlStatementMode": "auto",
        },
        "payment": {"method": doc.payment_method, "paymentReference": doc.payment_reference},
        "notes": {"onDocument": doc.note_on_document},
        "rows": [_row_block(row, i) for i, row in enumerate(doc.rows, start=1)],
        "vatRecap": [{
            "vatCode": line.vat_code,
            "vatPct": php_float(line.vat_pct),
            "base": php_float(line.base),
            "tax": php_float(line.tax),
            "total": php_float(line.total),
        } for line in doc.recap],
        "totals": {
            "totalBase": php_float(doc.total_base),
            "totalVat": php_float(doc.total_vat),
            "totalAmount": php_float(doc.total_amount),
            "totalRounding": php_float(doc.total_rounding),
        },
        "applyOptions": apply_options,
    }
    pruned = prune(canonical)
    if "importNumber" in pruned.get("applyOptions", {}) and "sequenceNumber" not in pruned["applyOptions"]["importNumber"]:
        pruned["applyOptions"]["importNumber"]["sequenceNumber"] = None
    return pruned


def doc_sort_key(doc: DocRec) -> tuple:
    """`ORDER BY h.doc_type, ns.doc_number_code, h.sequence_number, h.doc_number, h.issue_date, h.id`
    — NULL řadí první, koncepty tedy před číslované doklady téhož typu."""
    return (
        doc.doc_type,
        (doc.series_code is not None, doc.series_code or ""),
        (doc.sequence is not None, doc.sequence or 0),
        (doc.number is not None, doc.number or ""),
        doc.issue,
        doc.ordinal,
    )


def doc_slug(doc: DocRec) -> str:
    return doc.number if doc.number is not None else f"koncept {doc.doc_type}"


# ── Zprávy ───────────────────────────────────────────────────────────────────

def _person_ref(identity: dict) -> dict:
    return prune({
        "name": identity["name"],
        "companyId": identity.get("companyId"),
        "taxId": identity.get("taxId"),
        "vatId": identity.get("vatId"),
    })


def mail_record(mail: MailRec, pdf: bytes | None, story: Story, *, projection, taxonomy: set[str],
                target_number: str | None) -> dict:
    sender = mail.sender.identity
    if mail.primary_type == "invoiceReceived":
        assert mail.canonical_data is not None and mail.computed is not None and pdf is not None and mail.pdf_name
        expected = apply_projection(
            build_expected(mail.canonical_data, sender, story.own.identity, mail.computed, receipt=False),
            projection, taxonomy=taxonomy,
        )
        canonical = dataset_canonical(expected, mail.pdf_name, mail.analyzed_at, prompt_version=PROMPT_VERSION)
        analysis = analysis_record(
            analyzed_at=mail.analyzed_at, proposed_type="invoiceReceived", content_tag=mail.content_tag,
            analysis_json=analysis_json(canonical, mail.ai_title), canonical_json=canonical,
            model_name=MODEL_NAME, prompt_version=PROMPT_VERSION,
            resolution=mail.resolution, resolved_at=mail.resolved_at,
        )
        attachments = [attachment_entry(mail.pdf_name, pdf, 1)]
        target = {"table": "docs_core_heads", "docNumber": target_number} if target_number else None
    else:
        analysis = analysis_record(
            analyzed_at=mail.analyzed_at, proposed_type=mail.primary_type,
            analysis_json=classification_json(
                mail.ai_title, primary_type=mail.primary_type, attention=mail.attention, action_note=mail.action_note,
                due_date=_iso(mail.action_due),
                party={"name": sender["name"], "companyId": sender.get("companyId"), "email": (sender.get("contact") or {}).get("email")},
            ),
            model_name=MODEL_NAME, prompt_version=PROMPT_VERSION,
        )
        attachments = None
        target = None
    return message_record(
        message_id=mail.message_id, subject=mail.subject, sender_email=(mail.sender.email or "").lower(),
        sender_name=mail.sender.name, sender_person=_person_ref(sender), partner_person=_person_ref(sender),
        partner_name=mail.sender.name, received_at=mail.received_at, body=mail.body,
        primary_type=mail.primary_type, doc_state=mail.doc_state, ai_title=mail.ai_title,
        attention=mail.attention, action_note=mail.action_note, action_due=_iso(mail.action_due),
        target=target, attachments=attachments, analyses=[analysis],
    )


def mail_sort_key(mail: MailRec) -> tuple:
    return (mail.received_at, mail.message_id)


def mail_slug(mail: MailRec) -> str:
    return mail.message_id


# ── Spisovna ─────────────────────────────────────────────────────────────────

def registry_record(rec: RegistryRec, pdf: bytes, story: Story) -> dict:
    partner = story.party(rec.partner_key).identity
    record = {
        "format": "shpd.dataset.registryDocument.v1",
        "document": {
            "schema": "shpd.registry.document.v1",
            "docType": rec.doc_type,
            "title": rec.title,
            "summary": rec.summary,
            "party": {
                "name": partner["name"],
                "companyId": partner.get("companyId"),
                "email": (partner.get("contact") or {}).get("email"),
            },
            "kindFields": dict(rec.kind_fields),
        },
        "docState": 40,
        "sourceKind": rec.source_kind,
        "binder": rec.binder,
        "refNumber": rec.ref_number,
        "validFrom": _iso(rec.valid_from),
        "validTo": _iso(rec.valid_to),
        "created": rec.created_at,
        "attachments": [attachment_entry(rec.pdf_name, pdf)],
    }
    return prune(record)


def registry_sort_key(rec: RegistryRec) -> tuple:
    return (rec.created_at, czech_key(rec.title))


def registry_slug(rec: RegistryRec) -> str:
    return rec.title
