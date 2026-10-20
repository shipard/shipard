"""Snímek ideální analýzy a obálka došlé zprávy `shpd.mail.incomingMessage`
ve tvaru PHP exportéru (`MailExporter`) — sdílené korpusem (`dataset.py`)
i demo sadami (`demo.py`).

Zpráva v sadě nese i to, co by jinak doplnil seed nebo dump (`created`,
`sourceType`, velikost a SHA-256 příloh, `created` analýzy, nulové tokeny
a cena), aby dump naseedované sady vyšel bajtově stejně. Bloby
`analysisJson` / `canonicalJson` procházejí `php_json_numbers` (seed je
ukládá `json_encode` bez `PRESERVE_ZERO_FRACTION`), hodnoty sloupců
zůstávají `Decimal` (`1.0`).
"""

from __future__ import annotations

import copy
from decimal import Decimal

from .datasetio import DatasetBuildError, MESSAGE_FORMAT, php_json_numbers
from .render import NNBSP, fmt_money

ANALYSIS_PROFILE = "czech_general"   # profil analýzy pošty čerstvého zdroje dat (MailAiProvisioner)
MODEL_NAME = "corpus-ideal"
PROMPT_VERSION = "corpus"
MESSAGE_ID_MAX = 30
MAILBOX = "default"

ADDED_TAG_KEYS = ("tagSource", "tagConfidence")


# ── Ideální analýza ──────────────────────────────────────────────────────────

def dataset_canonical(expected: dict, filename: str, analyzed_at: str, *,
                      prompt_version: str = PROMPT_VERSION) -> dict:
    """Očekávaný výsledek + pole, která nese skutečná analýza a projekce je
    nemá: `source` za `formatVersion`, `attachments` před `_resolve`,
    `tagSource` / `tagConfidence` za `tag`. Nic jiného se nemění (D20)."""
    out: dict = {}
    for key, value in expected.items():
        if key == "_resolve":
            out["attachments"] = [{"filename": filename, "mimeType": "application/pdf", "kind": "original", "ref": "att:1"}]
            resolve = copy.deepcopy(value)
            tag_block = resolve.get("contentTag") or {}
            new_block: dict = {}
            for tk, tv in tag_block.items():
                new_block[tk] = tv
                if tk == "tag":
                    new_block["tagSource"] = "llm"
                    new_block["tagConfidence"] = Decimal("1.0")
            resolve["contentTag"] = new_block
            out["_resolve"] = resolve
            continue
        out[key] = copy.deepcopy(value)
        if key == "formatVersion":
            out["source"] = {
                "kind": "aiExtraction",
                "extractedAt": analyzed_at + "+00:00",
                "confidence": Decimal("1.0"),
                "promptVersion": prompt_version,
            }
    return out


def strip_dataset_additions(canonical: dict) -> dict:
    """Inverze `dataset_canonical` — pro kontrolu shody s očekáváním."""
    out = {k: copy.deepcopy(v) for k, v in canonical.items() if k not in ("source", "attachments")}
    resolve = out.get("_resolve")
    if isinstance(resolve, dict) and isinstance(resolve.get("contentTag"), dict):
        resolve["contentTag"] = {k: v for k, v in resolve["contentTag"].items() if k not in ADDED_TAG_KEYS}
    return out


def analysis_json(canonical: dict, title: str, *, primary_type: str = "invoiceReceived") -> dict:
    """Syntetický výstup AI ve tvaru promptu (`czech_general`) pro zprávu
    s dokladem."""
    return {
        "overall_confidence": Decimal("1.0"),
        "message_classification": {
            "primary_type": primary_type,
            "confidence": Decimal("1.0"),
            "title": title,
        },
        "document": {
            "doc_type": canonical["docType"],
            "confidence": Decimal("1.0"),
            "extracted_json": {k: copy.deepcopy(v) for k, v in canonical.items() if k != "_resolve"},
        },
    }


def classification_json(title: str, *, primary_type: str = "other", attention: str | None = None,
                        action_note: str | None = None, due_date: str | None = None,
                        party: dict | None = None) -> dict:
    """Syntetický výstup AI pro zprávu bez dokladu (`czech_general`,
    `message_classification` s pozorností — tasks/mail-other-attention.md):
    `document` je null, `attention` action / info / promo, `action_note`
    a `due_date` jen u action."""
    classification: dict = {
        "primary_type": primary_type,
        "confidence": Decimal("1.0"),
        "title": title,
    }
    if attention is not None:
        classification["attention"] = attention
    if action_note is not None:
        classification["action_note"] = action_note
    if due_date is not None:
        classification["due_date"] = due_date
    if party is not None:
        classification["party"] = dict(party)
    return {
        "overall_confidence": Decimal("1.0"),
        "message_classification": classification,
        "document": None,
    }


def ai_title(expected: dict, supplier_name: str, receipt: bool) -> str:
    amount = fmt_money(expected["totals"]["totalAmount"], "cs").replace(NNBSP, " ")
    currency = expected["currency"]
    money = f"{amount} Kč" if currency == "CZK" else f"{amount} {currency}"
    kind = "Účtenka" if receipt else "Faktura"
    return f"{kind} {expected['docNumber']} — {supplier_name}, {money}"


# ── Obálka zprávy ────────────────────────────────────────────────────────────

def _without_none(record: dict) -> dict:
    """Jen první úroveň — bloby analýzy drží `null` i `{}` (exportér je
    nepruhuje)."""
    return {k: v for k, v in record.items() if v is not None}


def analysis_record(*, analyzed_at: str, proposed_type: str, analysis_json: dict,
                    canonical_json: dict | None = None, status: int = 2,
                    model_name: str = MODEL_NAME, prompt_version: str = PROMPT_VERSION,
                    profile: str = ANALYSIS_PROFILE, confidence: Decimal = Decimal("1.0"),
                    content_tag: str | None = None, resolution: int | None = None,
                    resolved_at: str | None = None, tokens_input: int = 0, tokens_output: int = 0,
                    duration_ms: int = 0, cost_usd: Decimal = Decimal("0.0"),
                    created: str | None = None) -> dict:
    """Jeden běh analýzy v pořadí klíčů `MailExporter` (metadata, pak bloby
    na konci). `resolution` 40 = použito (zpracovaná zpráva s navázaným
    dokladem), 50 = odmítnuto, None = otevřený návrh. `created` default =
    `analyzedAt` (seed ho tak doplní)."""
    record = {
        "analyzedAt": analyzed_at,
        "status": status,
        "modelName": model_name,
        "promptVersion": prompt_version,
        "profile": profile,
        "confidence": confidence,
        "proposedType": proposed_type,
        "contentTag": content_tag,
        "resolution": resolution,
        "resolvedAt": resolved_at,
        "tokensInput": tokens_input,
        "tokensOutput": tokens_output,
        "durationMs": duration_ms,
        "costUsd": cost_usd,
        "created": created if created is not None else analyzed_at,
        "analysisJson": php_json_numbers(analysis_json),
        "canonicalJson": php_json_numbers(canonical_json) if canonical_json is not None else None,
    }
    return _without_none(record)


def message_record(*, message_id: str, subject: str, sender_email: str, received_at: str,
                   primary_type: str, doc_state: int, sender_name: str | None = None,
                   sender_person: dict | None = None, partner_person: dict | None = None,
                   partner_name: str | None = None, body: str | None = None,
                   primary_type_source: str = "ai", source_type: int = 2, analysis_state: int = 30,
                   ai_title: str | None = None, created: str | None = None,
                   attention: str | None = None, action_note: str | None = None,
                   action_due: str | None = None, target: dict | None = None,
                   mailbox: str = MAILBOX, attachments: list[dict] | None = None,
                   analyses: list[dict] | None = None) -> dict:
    """Zpráva `shpd.mail.incomingMessage` 1.0 v pořadí klíčů `MailExporter`:
    hlavička (odesílatel a partner jako odkaz na osobu `{name, companyId,
    taxId, vatId}` — seed je dohledá podle identifikátorů), tělo, stavy,
    titulek, pozornost (#105), `created` (default = `receivedAt`), vazba
    `target` na doklad / dokument spisovny, přílohy a analýzy. `None`
    vynecháno jen na první úrovni."""
    if len(message_id) > MESSAGE_ID_MAX:
        raise DatasetBuildError(f"messageId {message_id} delší než {MESSAGE_ID_MAX}")
    record = {
        "format": MESSAGE_FORMAT,
        "formatVersion": "1.0",
        "messageId": message_id,
        "mailbox": mailbox,
        "primaryType": primary_type,
        "primaryTypeSource": primary_type_source,
        "subject": subject,
        "senderEmail": sender_email,
        "senderName": sender_name,
        "senderPerson": dict(sender_person) if sender_person else None,
        "partnerPerson": dict(partner_person) if partner_person else None,
        "partnerName": partner_name,
        "receivedAt": received_at,
        "bodyPlain": body,
        "sourceType": source_type,
        "docState": doc_state,
        "analysisState": analysis_state,
        "aiTitle": ai_title,
        "attention": attention,
        "actionNote": action_note,
        "actionDue": action_due,
        "created": created if created is not None else received_at,
        "target": dict(target) if target else None,
        "attachments": list(attachments) if attachments else None,
        "analyses": list(analyses) if analyses else None,
    }
    return _without_none(record)
