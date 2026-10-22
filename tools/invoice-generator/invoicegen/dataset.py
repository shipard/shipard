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

Tvar souborů a zprávy (bez vazby na korpus) žije v `datasetio.py`
a `snapshot.py`; tady zůstává jen to korpusové — profily, matice buněk,
očekávání pro `dataset-verify`. Přesunutá jména se re-exportují.
"""

from __future__ import annotations

import shutil
from dataclasses import dataclass
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

from .checks import account_valid, iban_matches_account
from .corpus import PROFILES, TEMPLATE_LOCALE, Cell, SkippedCell, cells_for
from .datasetio import (  # noqa: F401 — re-export pro stávající importy
    CREATED_PATTERN, DATASET_FORMAT, MESSAGE_FORMAT, NAME_PATTERN, PERSON_FORMAT, PROVISION_KEYS,
    SETUP_FORMAT, SETUP_TABLES, DatasetBuildError, attachment_entry, clear_managed, dumps_dataset,
    manifest, php_json_numbers, prune, slug,
)
from .expected import dumps_expected
from .jsonc import load_jsonc
from .rates import HOME_CURRENCY, LIST_FORMAT, RATE_PLACES, Rate, accounting_date, select_lists
from .render import fmt_date
from .reporting import VatReporting, cost_record, exchange_rate_record, vat_record
from .snapshot import (  # noqa: F401 — re-export pro stávající importy
    ADDED_TAG_KEYS, ANALYSIS_PROFILE, MAILBOX, MESSAGE_ID_MAX, MODEL_NAME, PROMPT_VERSION, ai_title,
    analysis_json, analysis_record, dataset_canonical, message_record, strip_dataset_additions,
)

PROFILE_CODES = {"vat-payer": "VP", "non-payer": "NP", "association": "AS"}
MANAGED = ("manifest.jsonc", "setup", "exchangeRates", "persons", "mail", "expected")   # co build v cílové složce spravuje
LEDGER_SNAPSHOT = "general-ledger.json"   # profiles/<profil>/approved/ → expected/ (F3 D29)


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

    def home_currency(self) -> str:
        """Domácí měna zdroje dat (`economy.homeCurrency` v setup/settings.jsonc,
        malými jako v nastavení) velkými jako v dokladech; chybí = CZK."""
        for row in (self.setup.get("settings") or {}).get("rows", []):
            if row.get("key") == "economy.homeCurrency" and isinstance(row.get("value"), str) and row["value"]:
                return row["value"].upper()
        return HOME_CURRENCY


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


# ── Kurzovní lístky ──────────────────────────────────────────────────────────

def _rate_number(value) -> Decimal:
    """Kurz jako po PHP `round((float) rate, 6)` a `DatasetWriter`
    (`PRESERVE_ZERO_FRACTION`): nejvýš 6 míst, celá hodnota s `.0`."""
    rate = Decimal(str(value))
    if rate.as_tuple().exponent < -6:
        rate = rate.quantize(RATE_PLACES, rounding=ROUND_HALF_UP)
    if rate.as_tuple().exponent >= 0:
        rate = rate.quantize(Decimal("0.0"))
    return rate


def dataset_list(lst: dict, currencies: frozenset[str]) -> dict:
    """Lístek sekce `exchangeRates/` ve tvaru `ExchangeRatesExporteru`
    (#121 D10): klíče `format, listType, homeCurrency, validFrom, [validTo],
    [listNumber], rates` (null vynechané), kurzy seřazené podle měny a jen
    měny z `world.base.currencies` — ČNB vyhlašuje i XDR, které seed vyřadí
    s varováním; sada ho proto nenese."""
    rates = sorted((r for r in lst["rates"] if r["currency"] in currencies), key=lambda r: r["currency"])
    if not rates:
        raise DatasetBuildError(f"lístek {lst['listType']} {lst['validFrom']}: žádná známá měna")
    out: dict = {
        "format": LIST_FORMAT,
        "listType": lst["listType"],
        "homeCurrency": lst["homeCurrency"],
        "validFrom": lst["validFrom"],
    }
    if lst.get("validTo") is not None:
        out["validTo"] = lst["validTo"]
    if lst.get("listNumber") is not None:
        out["listNumber"] = str(lst["listNumber"])
    out["rates"] = [{"currency": r["currency"], "amount": int(r["amount"]), "rate": _rate_number(r["rate"])} for r in rates]
    return out


# ── Zpráva per buňka matice ──────────────────────────────────────────────────

def message_id(profile: str, stem: str) -> str:
    return f"MSG-CORPUS-{PROFILE_CODES[profile]}-{stem[:4]}"


ISDOC_MIME = "text/xml"   # po seedu ho dá finfo (MailSeeder MIME nepředává); IsdocImportService bere příponu i text/xml


def message_for_cell(cell: Cell, expected: dict, pdf: bytes, isdoc: bytes | None = None) -> dict:
    """Zpráva `shpd.mail.incomingMessage` 1.0 s PDF jako první přílohou,
    u podoby `pdfIsdoc` s ISDOC jako druhou (#103 D75; `isdoc` je pak
    povinný, u podoby `pdf` zakázaný), a jednou analýzou v pořadí klíčů
    MailExporteru (D20): otevřený návrh (`docState` 20 K řešení, bez
    `resolution`), přijato den po vystavení. Ideální analýza nese jen PDF (`att:1`)."""
    if (cell.form == "pdfIsdoc") != (isdoc is not None):
        raise DatasetBuildError(f"{cell.stem} [{cell.profile}]: podoba {cell.form} — ISDOC "
                                + ("chybí" if isdoc is None else "nepatří ke zprávě"))
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

    return message_record(
        message_id=msg_id,
        subject=subject,
        sender_email=sender_email,
        sender_name=sender_name,
        partner_name=supplier_name,
        received_at=received_at,
        body=body,
        primary_type="invoiceReceived",
        doc_state=20,
        ai_title=title,
        attachments=[attachment_entry(filename, pdf, 1)]
        + ([attachment_entry(f"{cell.stem}.isdoc", isdoc, 2, mime_type=ISDOC_MIME)] if isdoc is not None else []),
        analyses=[analysis_record(
            analyzed_at=analyzed_at,
            proposed_type="invoiceReceived",
            content_tag=expected["_resolve"]["contentTag"]["tag"],
            analysis_json=analysis_json(canonical, title),
            canonical_json=canonical,
        )],
    )


# ── Sestavení ────────────────────────────────────────────────────────────────

@dataclass(frozen=True)
class DatasetSummary:
    profile: str
    name: str
    messages: int
    skipped: int
    exchange_rate_lists: int
    out_dir: Path


def build_dataset(profile: Profile, identity: dict, docs: dict[str, dict], identities: dict,
                  corpus_dir: Path, out_dir: Path, *, reporting: VatReporting, projection_path: Path,
                  rate_lists: list[dict], currencies: frozenset[str]) -> DatasetSummary:
    """Jedna sada: setup/ ze základu, kurzovní lístky pro doklady v cizí
    měně, vlastní firma z identity, zpráva per buňka matice profilu
    a očekávání pro `dataset-verify` (F3: projekce, DPH, náklady, schválený
    snímek deníku). Deterministické — stejný korpus = stejné bajty."""
    messages: list[dict] = []
    files: dict[str, list[Path]] = {}   # přílohy zprávy v pořadí attachments[]
    vat_records: dict[str, dict] = {}
    cost_records: dict[str, dict] = {}
    rate_records: dict[str, dict] = {}      # kurz per doklad v cizí měně (D73)
    message_records: dict[str, dict] = {}   # zpráva → buňka korpusu (F4: metriky po variantách, `--only`)
    vat_payer = profile.vat_agenda()
    home = profile.home_currency()
    cells: list[tuple[Cell, Path]] = []
    skipped = 0
    for stem, doc in docs.items():
        for cell in cells_for(doc, stem, identities):
            if isinstance(cell, SkippedCell):
                skipped += cell.profile == profile.key
                continue
            if cell.profile != profile.key:
                continue
            expected_path = corpus_dir / "expected" / profile.key / f"{stem}.json"
            if not expected_path.is_file():
                raise DatasetBuildError(f"{stem} [{profile.key}]: chybí očekávaný výsledek {expected_path}")
            cells.append((cell, expected_path))

    # Kurzy k účetním datům dokladů v cizí měně (D72, D73): lístek použitý
    # resolverem + rezerva z předchozího pracovního dne jdou do sekce exchangeRates/.
    pairs = {(cell.data["currency"], accounting_date(cell.data)) for cell, _ in cells if cell.data["currency"] != home}
    selection = select_lists(rate_lists, pairs, home=home)
    if selection.missing:
        raise DatasetBuildError(
            f"{profile.key}: chybí kurzovní lístek pro "
            + ", ".join(f"{currency} k {day}" for currency, day in selection.missing)
            + " — spusť build.py --fetch-rates"
        )

    for cell, expected_path in cells:
        stem = cell.stem
        pdf_path = corpus_dir / "out" / profile.key / f"{stem}.pdf"
        if not pdf_path.is_file():
            raise DatasetBuildError(f"{stem} [{profile.key}]: chybí PDF {pdf_path}")
        # ISDOC právě u podoby pdfIsdoc (D75) — sirotek i chybějící soubor jsou chyba korpusu.
        isdoc_path = corpus_dir / "out" / profile.key / f"{stem}.isdoc"
        if isdoc_path.is_file() != (cell.form == "pdfIsdoc"):
            raise DatasetBuildError(f"{stem} [{profile.key}]: podoba {cell.form}, ale {isdoc_path} "
                                    + ("chybí" if cell.form == "pdfIsdoc" else "existuje"))
        expected = load_jsonc(expected_path)
        message = message_for_cell(cell, expected, pdf_path.read_bytes(),
                                   isdoc_path.read_bytes() if cell.form == "pdfIsdoc" else None)
        canonical = message["analyses"][0]["canonicalJson"]
        if strip_dataset_additions(canonical) != expected:
            raise DatasetBuildError(f"{stem} [{profile.key}]: canonicalJson po odebrání doplněných polí neodpovídá očekávání")
        messages.append(message)
        msg_id = message["messageId"]
        files[msg_id] = [pdf_path] + ([isdoc_path] if cell.form == "pdfIsdoc" else [])
        message_records[msg_id] = {"messageId": msg_id, "stem": stem, "variant": cell.data["variant"], "form": cell.form}
        currency = cell.data["currency"]
        rate = Rate.home() if currency == home else selection.rates[(currency, accounting_date(cell.data))]
        if vat_payer:
            vat_records[msg_id] = vat_record(msg_id, cell.data["variant"], expected, reporting, rate=rate.rate)
        cost_records[msg_id] = cost_record(msg_id, expected, vat_payer=vat_payer, rate=rate.rate)
        if currency != home:
            rate_records[msg_id] = exchange_rate_record(msg_id, expected, rate)

    messages.sort(key=lambda m: (m["receivedAt"], m["messageId"]))
    ids = [m["messageId"] for m in messages]
    if len(set(ids)) != len(ids):
        raise DatasetBuildError(f"{profile.key}: duplicitní messageId")

    clear_managed(out_dir, MANAGED)
    (out_dir / "setup").mkdir(parents=True, exist_ok=True)
    for table, file in profile.setup.items():
        (out_dir / "setup" / f"{table}.jsonc").write_text(dumps_dataset(file), encoding="utf-8")

    # Sekce exchangeRates/ jen u sady s dokladem v cizí měně (docs/exchange-rates.md § 7):
    # název NNNN-<slug(listType-validFrom)>.jsonc v pořadí (listType, validFrom) jako z dumpu.
    if selection.lists:
        (out_dir / "exchangeRates").mkdir()
        for ordinal, lst in enumerate(selection.lists, start=1):
            name = f"{ordinal:04d}-{slug(lst['listType'] + '-' + lst['validFrom'])}.jsonc"
            (out_dir / "exchangeRates" / name).write_text(dumps_dataset(dataset_list(lst, currencies)), encoding="utf-8")

    (out_dir / "persons").mkdir(exist_ok=True)
    person = own_person(identity)
    (out_dir / "persons" / f"0001-{slug(identity['name'])}.jsonc").write_text(dumps_dataset(person), encoding="utf-8")

    (out_dir / "mail").mkdir(exist_ok=True)
    for ordinal, message in enumerate(messages, start=1):
        base = f"{ordinal:04d}-{slug(message['messageId'])}"
        (out_dir / "mail" / f"{base}.jsonc").write_text(dumps_dataset(message), encoding="utf-8")
        sidecar = out_dir / "mail" / f"{base}.files"
        sidecar.mkdir()
        for source, attachment in zip(files[message["messageId"]], message["attachments"], strict=True):
            shutil.copyfile(source, sidecar / attachment["file"])

    # Očekávání pro dataset-verify (tasks/testing-phase3.md): záznamy v pořadí
    # zpráv; neplátce a spolek mají vat.json prázdné (D28), sada bez cizí
    # měny exchange-rates.json prázdné (D73). Zapisovač očekávání (2 mezery,
    # Decimal) — nejsou to soubory sady pro seed.
    # messages.json = zpráva → buňka korpusu pro evaluaci AI (F4, tasks/testing-phase4.md).
    expected_dir = out_dir / "expected"
    expected_dir.mkdir()
    shutil.copyfile(projection_path, expected_dir / "projection.jsonc")
    (expected_dir / "messages.json").write_text(dumps_expected([message_records[m] for m in ids]), encoding="utf-8")
    (expected_dir / "vat.json").write_text(dumps_expected([vat_records[m] for m in ids if m in vat_records]), encoding="utf-8")
    (expected_dir / "costs.json").write_text(dumps_expected([cost_records[m] for m in ids]), encoding="utf-8")
    (expected_dir / "exchange-rates.json").write_text(dumps_expected([rate_records[m] for m in ids if m in rate_records]), encoding="utf-8")
    if profile.ledger_snapshot is not None:
        shutil.copyfile(profile.ledger_snapshot, expected_dir / LEDGER_SNAPSHOT)

    counts = {"setup": len(profile.setup)}
    if selection.lists:
        counts["exchangeRates"] = len(selection.lists)
    counts.update({"persons": 1, "mail": len(messages)})
    (out_dir / "manifest.jsonc").write_text(dumps_dataset(manifest(
        name=profile.name,
        title=profile.title,
        description=profile.description,
        created=profile.created,
        country=identity["country"],
        profile=profile.key,
        provision=profile.provision,
        counts=counts,
    )), encoding="utf-8")

    return DatasetSummary(profile=profile.key, name=profile.name, messages=len(messages), skipped=skipped,
                          exchange_rate_lists=len(selection.lists), out_dir=out_dir)


def build_datasets(corpus_dir: Path, out_root: Path, identities: dict, docs: dict[str, dict], *,
                   reporting: VatReporting, projection_path: Path, rate_lists: list[dict],
                   currencies: frozenset[str]) -> list[DatasetSummary]:
    """Všechny profily: `corpus_dir/profiles/<profil>/` → `out_root/<profil>/`."""
    summaries = []
    for key in PROFILES:
        profile = load_profile(corpus_dir / "profiles" / key, key)
        summaries.append(build_dataset(profile, identities["profiles"][key], docs, identities, corpus_dir, out_root / key,
                                       reporting=reporting, projection_path=projection_path,
                                       rate_lists=rate_lists, currencies=currencies))
    return summaries
