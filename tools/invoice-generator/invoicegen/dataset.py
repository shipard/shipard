"""Sestavení datových sad z korpusu (tasks/testing-phase2.md, #103 F2):
pro každý profil vlastní firmy jedna sada `shpd.dataset.v1`, kde každá
buňka matice je došlá zpráva s PDF a ideální analýzou (snapshot mode —
očekávaný výsledek jako `canonicalJson`, bez volání AI).

Sada je po seedu a `dataset-dump` bajtově shodná v sekci `mail/`, proto
tvar souborů kopíruje PHP exportér (`MailExporter`, `DatasetWriter`):
pořadí klíčů, 4 mezery, `null` vynechané v obálce zprávy, názvy souborů
`NNNN-<slug(messageId)>.jsonc` v pořadí (`receivedAt`, `messageId`)
a čísla tak, jak projdou PHP: hodnoty sloupců přes `DatasetWriter`
(`PRESERVE_ZERO_FRACTION`: `1.0` zůstává), bloby `analysisJson`
a `canonicalJson` přes `json_decode` → `json_encode` v seedu (celé
hodnoty jako int: `2340.00` → `2340`, `1.0` → `1`; ostatní nejkratší
float: `486.50` → `486.5`).
"""

from __future__ import annotations

import copy
import hashlib
import json
import re
import shutil
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from decimal import Decimal
from pathlib import Path

from .checks import account_valid, iban_matches_account
from .corpus import PROFILES, TEMPLATE_LOCALE, Cell, SkippedCell, cells_for
from .expected import dumps_expected
from .jsonc import load_jsonc
from .render import NNBSP, fmt_date, fmt_money
from .reporting import VatReporting, cost_record, vat_record

DATASET_FORMAT = "shpd.dataset.v1"
SETUP_FORMAT = "shpd.dataset.setup.v1"
MESSAGE_FORMAT = "shpd.mail.incomingMessage"
PERSON_FORMAT = "shpd.persons.person"

PROFILE_CODES = {"vat-payer": "VP", "non-payer": "NP", "association": "AS"}
ANALYSIS_PROFILE = "czech_general"   # profil analýzy pošty čerstvého zdroje dat (MailAiProvisioner)
MODEL_NAME = "corpus-ideal"
PROMPT_VERSION = "corpus"
MESSAGE_ID_MAX = 30
MAILBOX = "default"

NAME_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
CREATED_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
SETUP_TABLES = ("settings", "bank_accounts", "cash_desks", "cost_centers", "vat_registrations", "binders", "mailboxes")
PROVISION_KEYS = ("accountingItems", "serverAi")   # serverAi: bool — serverové AI připojení pro vývoj (F4 D40)
MANAGED = ("manifest.jsonc", "setup", "persons", "mail", "expected")   # co build v cílové složce spravuje
LEDGER_SNAPSHOT = "general-ledger.json"   # profiles/<profil>/approved/ → expected/ (F3 D29)

# Port Shipard\Core\Utils\Slug::make — stejná tabulka, stejný regex.
_TRANSLIT = {
    "á": "a", "č": "c", "ď": "d", "é": "e", "ě": "e", "í": "i", "ň": "n",
    "ó": "o", "ř": "r", "š": "s", "ť": "t", "ú": "u", "ů": "u", "ý": "y",
    "ž": "z", "ä": "a", "ľ": "l", "ĺ": "l", "ô": "o", "ŕ": "r", "ö": "o",
    "ü": "u", "ß": "ss", "ł": "l", "ą": "a", "ę": "e", "ś": "s", "ź": "z",
    "ż": "z", "ć": "c", "ń": "n", "à": "a", "è": "e", "ì": "i", "ò": "o",
    "ù": "u", "â": "a", "ê": "e", "î": "i", "û": "u", "ç": "c",
    "ñ": "n", "ø": "o", "å": "a", "æ": "ae", "œ": "oe",
}


class DatasetBuildError(ValueError):
    pass


def slug(text: str, max_length: int = 60, fallback: str = "record") -> str:
    ascii_ = "".join(_TRANSLIT.get(ch, ch) for ch in text.lower())
    ascii_ = re.sub(r"[^a-z0-9]+", "-", ascii_).strip("-")
    if len(ascii_) > max_length:
        ascii_ = ascii_[:max_length].rstrip("-")
    return ascii_ or fallback


# ── Zápis JSON jako DatasetWriter (PHP) ──────────────────────────────────────

def _dump_decimal(value: Decimal) -> str:
    """`DatasetWriter` (`PRESERVE_ZERO_FRACTION`): číslo s desetinnou
    tečkou je float (`486.50` → `486.5`, `1.0` → `1.0`), bez ní int."""
    if value == 0:
        value = abs(value)
    if value.as_tuple().exponent >= 0:
        return str(int(value))
    return repr(float(value))


def php_json_numbers(value):
    """Čísla jako po `json_decode` → `json_encode` bez
    `PRESERVE_ZERO_FRACTION` (MailSeeder ukládá bloby analýzy takto):
    celá hodnota je int (`2340.00` → `2340`, `1.0` → `1`), ostatní float.
    Vrací kopii; na bloby `analysisJson` / `canonicalJson`."""
    if isinstance(value, Decimal):
        return int(value) if value == value.to_integral_value() else value
    if isinstance(value, dict):
        return {k: php_json_numbers(v) for k, v in value.items()}
    if isinstance(value, list):
        return [php_json_numbers(v) for v in value]
    return value


def _dump_str(value: str) -> str:
    out = json.dumps(value, ensure_ascii=False)
    # PHP escapuje U+2028/2029 i s JSON_UNESCAPED_UNICODE.
    return out.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")


def _dump(value, level: int) -> str:
    pad = "    " * level
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, Decimal):
        return _dump_decimal(value)
    if isinstance(value, float):
        raise TypeError("float v datové sadě — čísla jen Decimal / int")
    if isinstance(value, str):
        return _dump_str(value)
    if isinstance(value, dict):
        if not value:
            return "{}"
        inner = ",\n".join(f"{pad}    {_dump_str(str(k))}: {_dump(v, level + 1)}" for k, v in value.items())
        return "{\n" + inner + "\n" + pad + "}"
    if isinstance(value, (list, tuple)):
        if not value:
            return "[]"
        inner = ",\n".join(f"{pad}    {_dump(v, level + 1)}" for v in value)
        return "[\n" + inner + "\n" + pad + "]"
    raise TypeError(f"nepodporovaný typ {type(value).__name__}")


def dumps_dataset(value) -> str:
    """JSON jako `DatasetWriter::encode()`: 4 mezery, UTF-8 bez escapování,
    `/` bez escapování, `\\n` na konci."""
    return _dump(value, 0) + "\n"


# ── Základ profilu ───────────────────────────────────────────────────────────

@dataclass(frozen=True)
class Profile:
    key: str
    name: str
    title: str
    description: str | None
    created: str
    provision: dict
    setup: dict[str, dict]   # klíč tabulky → obsah setup/<klíč>.jsonc
    ledger_snapshot: Path | None = None   # schválený snímek deníku (approved/general-ledger.json)

    def vat_agenda(self) -> bool:
        """Profil vede agendu DPH (`economy.vatAgenda` v setup/settings.jsonc)
        — jen takový dostane očekávání přiznání a KH (F3 D28)."""
        for row in (self.setup.get("settings") or {}).get("rows", []):
            if row.get("key") == "economy.vatAgenda":
                return bool(row.get("value"))
        return False


def load_profile(profile_dir: Path, key: str) -> Profile:
    meta_path = profile_dir / "profile.jsonc"
    if not meta_path.is_file():
        raise DatasetBuildError(f"{key}: chybí {meta_path}")
    meta = load_jsonc(meta_path)
    problems: list[str] = []

    name = meta.get("name")
    if not isinstance(name, str) or not NAME_PATTERN.fullmatch(name):
        problems.append("name musí odpovídat [a-z0-9][a-z0-9._-]* (max 64 znaků)")
    title = meta.get("title")
    if not isinstance(title, str) or not title.strip():
        problems.append("title je povinný")
    description = meta.get("description")
    if description is not None and not isinstance(description, str):
        problems.append("description musí být řetězec nebo null")
    created = meta.get("created")
    if not isinstance(created, str) or not CREATED_PATTERN.fullmatch(created):
        problems.append("created musí být pevné UTC razítko YYYY-MM-DDTHH:MM:SSZ")
    provision = meta.get("provision")
    if provision is None:
        provision = {}
    elif not isinstance(provision, dict):
        problems.append("provision musí být objekt")
        provision = {}
    for pkey, pvalue in provision.items():
        if pkey not in PROVISION_KEYS:
            problems.append(f"provision.{pkey}: neznámá akce (známé: {', '.join(PROVISION_KEYS)})")
        elif pkey == "serverAi":
            if not isinstance(pvalue, bool):
                problems.append("provision.serverAi: true nebo false")
        elif pvalue != "all" and not (isinstance(pvalue, list) and pvalue
                                      and all(isinstance(c, str) and c.strip() for c in pvalue)):
            problems.append(f"provision.{pkey}: \"all\" nebo neprázdný seznam kódů nabídky")
    unknown = set(meta) - {"name", "title", "description", "created", "provision"}
    if unknown:
        problems.append("neznámé klíče: " + ", ".join(sorted(unknown)))

    setup: dict[str, dict] = {}
    for path in sorted((profile_dir / "setup").glob("*.jsonc")) if (profile_dir / "setup").is_dir() else []:
        file = load_jsonc(path)
        table = file.get("table")
        if file.get("format") != SETUP_FORMAT:
            problems.append(f"setup/{path.name}: format musí být {SETUP_FORMAT}")
        if table not in SETUP_TABLES or table != path.stem:
            problems.append(f"setup/{path.name}: table musí být název souboru z {', '.join(SETUP_TABLES)}")
            continue
        rows = file.get("rows")
        if not isinstance(rows, list) or not rows or not all(isinstance(r, dict) for r in rows):
            problems.append(f"setup/{path.name}: rows musí být neprázdné pole objektů")
            continue
        if table == "bank_accounts":
            for i, row in enumerate(rows):
                account = row.get("account_number")
                iban = row.get("iban")
                if not isinstance(account, str) or not account_valid(account):
                    problems.append(f"setup/{path.name}: rows[{i}].account_number nemá mod 11")
                elif not isinstance(iban, str) or not iban_matches_account(iban, account):
                    problems.append(f"setup/{path.name}: rows[{i}].iban neodpovídá účtu")
        setup[table] = {"format": SETUP_FORMAT, "table": table, "rows": rows}
    if problems:
        raise DatasetBuildError(f"{key}/profile: " + "; ".join(problems))
    snapshot = profile_dir / "approved" / LEDGER_SNAPSHOT
    return Profile(key=key, name=name, title=title, description=description, created=created,
                   provision=provision, setup=setup, ledger_snapshot=snapshot if snapshot.is_file() else None)


# ── Vlastní firma ────────────────────────────────────────────────────────────

def own_person(identity: dict) -> dict:
    """`shpd.persons.person` 1.2 v pořadí PersonExporteru; vlastní firma
    (isOwn) ve V pořádku, sídlo z identity profilu. Banku nese jen
    číselník v setup/ (D21 — v sadě jen to nutné)."""
    address = identity["address"]
    person = {
        "format": PERSON_FORMAT,
        "formatVersion": "1.2",
        "personType": "company",
        "country": identity["country"],
        "companyId": identity["companyId"],
        "taxId": identity.get("taxId"),
        "vatId": identity.get("vatId"),
        "name": {"fullName": identity["name"]},
        "status": {"isOwn": True, "docState": 40},
        "addresses": [{
            "addressType": 1,
            "street": address.get("street"),
            "houseNumber": address.get("houseNumber"),
            "city": address.get("city"),
            "zip": address.get("zip"),
            "country": address.get("country"),
        }],
        "applyOptions": {"mergeStrategy": "createOnly", "matchStrategy": "identifiersOnly", "targetDocState": 40},
    }
    return prune(person)


def prune(value):
    """`ValueNormalizer::prune()`: null a prázdná pole pryč (rekurzivně).
    Na snapshot analýzy (`analysisJson`, `canonicalJson`) se nepoužívá."""
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            v = prune(v)
            if v is None or v == [] or v == {}:
                continue
            out[k] = v
        return out
    if isinstance(value, list):
        return [prune(v) for v in value if v is not None]
    return value


# ── Ideální analýza ──────────────────────────────────────────────────────────

ADDED_TAG_KEYS = ("tagSource", "tagConfidence")


def dataset_canonical(expected: dict, filename: str, analyzed_at: str) -> dict:
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
                "promptVersion": PROMPT_VERSION,
            }
    return out


def strip_dataset_additions(canonical: dict) -> dict:
    """Inverze `dataset_canonical` — pro kontrolu shody s očekáváním."""
    out = {k: copy.deepcopy(v) for k, v in canonical.items() if k not in ("source", "attachments")}
    resolve = out.get("_resolve")
    if isinstance(resolve, dict) and isinstance(resolve.get("contentTag"), dict):
        resolve["contentTag"] = {k: v for k, v in resolve["contentTag"].items() if k not in ADDED_TAG_KEYS}
    return out


def analysis_json(canonical: dict, title: str) -> dict:
    """Syntetický výstup AI ve tvaru promptu (`czech_general`)."""
    return {
        "overall_confidence": Decimal("1.0"),
        "message_classification": {
            "primary_type": "invoiceReceived",
            "confidence": Decimal("1.0"),
            "title": title,
        },
        "document": {
            "doc_type": canonical["docType"],
            "confidence": Decimal("1.0"),
            "extracted_json": {k: copy.deepcopy(v) for k, v in canonical.items() if k != "_resolve"},
        },
    }


def message_id(profile: str, stem: str) -> str:
    return f"MSG-CORPUS-{PROFILE_CODES[profile]}-{stem[:4]}"


def ai_title(expected: dict, supplier_name: str, receipt: bool) -> str:
    amount = fmt_money(expected["totals"]["totalAmount"], "cs").replace(NNBSP, " ")
    currency = expected["currency"]
    money = f"{amount} Kč" if currency == "CZK" else f"{amount} {currency}"
    kind = "Účtenka" if receipt else "Faktura"
    return f"{kind} {expected['docNumber']} — {supplier_name}, {money}"


def message_for_cell(cell: Cell, expected: dict, pdf: bytes) -> dict:
    """Zpráva `shpd.mail.incomingMessage` 1.0 s jednou přílohou a jednou
    analýzou v pořadí klíčů MailExporteru (D20)."""
    locale = TEMPLATE_LOCALE[cell.template]
    doc_number = expected["docNumber"]
    issue = expected["dates"]["issueDate"]
    received_day = (date.fromisoformat(issue) + timedelta(days=1)).isoformat()
    received_at = f"{received_day}T08:00:00"
    analyzed_at = f"{received_day}T08:01:00"
    supplier_name = cell.supplier["name"]
    sender_email = (cell.supplier.get("contact") or {}).get("email")
    sender_name = supplier_name
    if not sender_email:
        # Doklad bez e-mailu dodavatele (účtenka) = sken poslaný sám sobě.
        sender_email = f"doklady@{cell.profile}.example"
        sender_name = cell.profile_identity["name"]

    if cell.receipt:
        subject = f"Účtenka {fmt_date(issue, 'cs')}"
        body = f"Sken účtenky z {fmt_date(issue, 'cs')}."
    elif locale == "en":
        subject = f"Invoice {doc_number}"
        body = f"Hello,\n\nplease find attached invoice {doc_number}.\n\n{supplier_name}"
    else:
        subject = f"Faktura {doc_number}"
        body = f"Dobrý den,\n\nv příloze zasíláme fakturu {doc_number}.\n\n{supplier_name}"

    filename = f"{cell.stem}.pdf"
    title = ai_title(expected, supplier_name, cell.receipt)
    canonical = dataset_canonical(expected, filename, analyzed_at)
    msg_id = message_id(cell.profile, cell.stem)
    if len(msg_id) > MESSAGE_ID_MAX:
        raise DatasetBuildError(f"{cell.stem} [{cell.profile}]: messageId {msg_id} delší než {MESSAGE_ID_MAX}")

    return {
        "format": MESSAGE_FORMAT,
        "formatVersion": "1.0",
        "messageId": msg_id,
        "mailbox": MAILBOX,
        "primaryType": "invoiceReceived",
        "primaryTypeSource": "ai",
        "subject": subject,
        "senderEmail": sender_email,
        "senderName": sender_name,
        "partnerName": supplier_name,
        "receivedAt": received_at,
        "bodyPlain": body,
        "sourceType": 2,
        "docState": 20,
        "analysisState": 30,
        "aiTitle": title,
        "created": received_at,
        "attachments": [{
            "file": filename,
            "name": filename,
            "mimeType": "application/pdf",
            "size": len(pdf),
            "sha256": hashlib.sha256(pdf).hexdigest(),
            "order": 1,
        }],
        "analyses": [{
            "analyzedAt": analyzed_at,
            "status": 2,
            "modelName": MODEL_NAME,
            "promptVersion": PROMPT_VERSION,
            "profile": ANALYSIS_PROFILE,
            "confidence": Decimal("1.0"),
            "proposedType": "invoiceReceived",
            "contentTag": expected["_resolve"]["contentTag"]["tag"],
            "tokensInput": 0,
            "tokensOutput": 0,
            "durationMs": 0,
            "costUsd": Decimal("0.0"),
            "created": analyzed_at,
            "analysisJson": php_json_numbers(analysis_json(canonical, title)),
            "canonicalJson": php_json_numbers(canonical),
        }],
    }


# ── Sestavení ────────────────────────────────────────────────────────────────

@dataclass(frozen=True)
class DatasetSummary:
    profile: str
    name: str
    messages: int
    skipped: int
    out_dir: Path


def _clear_managed(out_dir: Path) -> None:
    for name in MANAGED:
        target = out_dir / name
        if target.is_dir():
            shutil.rmtree(target)
        elif target.exists():
            target.unlink()


def build_dataset(profile: Profile, identity: dict, docs: dict[str, dict], identities: dict,
                  corpus_dir: Path, out_dir: Path, *, reporting: VatReporting, projection_path: Path) -> DatasetSummary:
    """Jedna sada: setup/ ze základu, vlastní firma z identity, zpráva per
    buňka matice profilu a očekávání pro `dataset-verify` (F3: projekce,
    DPH, náklady, schválený snímek deníku). Deterministické — stejný korpus
    = stejné bajty."""
    messages: list[dict] = []
    files: dict[str, Path] = {}
    vat_records: dict[str, dict] = {}
    cost_records: dict[str, dict] = {}
    message_records: dict[str, dict] = {}   # zpráva → buňka korpusu (F4: metriky po variantách, `--only`)
    vat_payer = profile.vat_agenda()
    skipped = 0
    for stem, doc in docs.items():
        for cell in cells_for(doc, stem, identities):
            if isinstance(cell, SkippedCell):
                skipped += cell.profile == profile.key
                continue
            if cell.profile != profile.key:
                continue
            expected_path = corpus_dir / "expected" / profile.key / f"{stem}.json"
            pdf_path = corpus_dir / "out" / profile.key / f"{stem}.pdf"
            if not expected_path.is_file():
                raise DatasetBuildError(f"{stem} [{profile.key}]: chybí očekávaný výsledek {expected_path}")
            if not pdf_path.is_file():
                raise DatasetBuildError(f"{stem} [{profile.key}]: chybí PDF {pdf_path}")
            expected = load_jsonc(expected_path)
            message = message_for_cell(cell, expected, pdf_path.read_bytes())
            canonical = message["analyses"][0]["canonicalJson"]
            if strip_dataset_additions(canonical) != expected:
                raise DatasetBuildError(f"{stem} [{profile.key}]: canonicalJson po odebrání doplněných polí neodpovídá očekávání")
            messages.append(message)
            msg_id = message["messageId"]
            files[msg_id] = pdf_path
            message_records[msg_id] = {"messageId": msg_id, "stem": stem, "variant": cell.data["variant"], "form": cell.form}
            if vat_payer:
                vat_records[msg_id] = vat_record(msg_id, cell.data["variant"], expected, reporting)
            cost_records[msg_id] = cost_record(msg_id, expected, vat_payer=vat_payer)

    messages.sort(key=lambda m: (m["receivedAt"], m["messageId"]))
    ids = [m["messageId"] for m in messages]
    if len(set(ids)) != len(ids):
        raise DatasetBuildError(f"{profile.key}: duplicitní messageId")

    _clear_managed(out_dir)
    (out_dir / "setup").mkdir(parents=True, exist_ok=True)
    for table, file in profile.setup.items():
        (out_dir / "setup" / f"{table}.jsonc").write_text(dumps_dataset(file), encoding="utf-8")

    (out_dir / "persons").mkdir(exist_ok=True)
    person = own_person(identity)
    (out_dir / "persons" / f"0001-{slug(identity['name'])}.jsonc").write_text(dumps_dataset(person), encoding="utf-8")

    (out_dir / "mail").mkdir(exist_ok=True)
    for ordinal, message in enumerate(messages, start=1):
        base = f"{ordinal:04d}-{slug(message['messageId'])}"
        (out_dir / "mail" / f"{base}.jsonc").write_text(dumps_dataset(message), encoding="utf-8")
        sidecar = out_dir / "mail" / f"{base}.files"
        sidecar.mkdir()
        shutil.copyfile(files[message["messageId"]], sidecar / message["attachments"][0]["file"])

    # Očekávání pro dataset-verify (tasks/testing-phase3.md): záznamy v pořadí
    # zpráv; neplátce a spolek mají vat.json prázdné (D28). Zapisovač
    # očekávání (2 mezery, Decimal) — nejsou to soubory sady pro seed.
    # messages.json = zpráva → buňka korpusu pro evaluaci AI (F4, tasks/testing-phase4.md).
    expected_dir = out_dir / "expected"
    expected_dir.mkdir()
    shutil.copyfile(projection_path, expected_dir / "projection.jsonc")
    (expected_dir / "messages.json").write_text(dumps_expected([message_records[m] for m in ids]), encoding="utf-8")
    (expected_dir / "vat.json").write_text(dumps_expected([vat_records[m] for m in ids if m in vat_records]), encoding="utf-8")
    (expected_dir / "costs.json").write_text(dumps_expected([cost_records[m] for m in ids]), encoding="utf-8")
    if profile.ledger_snapshot is not None:
        shutil.copyfile(profile.ledger_snapshot, expected_dir / LEDGER_SNAPSHOT)

    manifest: dict = {
        "format": DATASET_FORMAT,
        "name": profile.name,
        "title": profile.title,
    }
    if profile.description is not None:
        manifest["description"] = profile.description
    manifest["dateMode"] = "fixed"
    manifest["created"] = profile.created
    manifest["country"] = identity["country"]
    manifest["profile"] = profile.key
    if profile.provision:
        manifest["provision"] = profile.provision
    manifest["counts"] = {"setup": len(profile.setup), "persons": 1, "mail": len(messages)}
    (out_dir / "manifest.jsonc").write_text(dumps_dataset(manifest), encoding="utf-8")

    return DatasetSummary(profile=profile.key, name=profile.name, messages=len(messages), skipped=skipped, out_dir=out_dir)


def build_datasets(corpus_dir: Path, out_root: Path, identities: dict, docs: dict[str, dict], *,
                   reporting: VatReporting, projection_path: Path) -> list[DatasetSummary]:
    """Všechny profily: `corpus_dir/profiles/<profil>/` → `out_root/<profil>/`."""
    summaries = []
    for key in PROFILES:
        profile = load_profile(corpus_dir / "profiles" / key, key)
        summaries.append(build_dataset(profile, identities["profiles"][key], docs, identities, corpus_dir, out_root / key,
                                       reporting=reporting, projection_path=projection_path))
    return summaries
