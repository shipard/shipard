"""Sestavení demo sady z příběhu (tasks/demo-dataset-phase1.md D5):
`load_story` → `expand` → řazení a názvy souborů jako exportéry dumpu →
PDF přes cache → záznamy ve tvaru dumpu (`dumpshape`) → zápis jen
změněných bajtů (`datasetio.sync_tree`). Manifest nese `country`,
`provision.accountingItems: "all"` a `counts` všech sekcí v pořadí dumpu
(včetně nul), `created` = kotva o půlnoci UTC (D7)."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from pathlib import Path

from .catalogs import Catalogs, load_catalogs
from .datasetio import (
    SECTIONS, SETUP_TABLES, SyncResult, dumps_dataset, file_name, manifest, sync_tree,
)
from .dumpshape import (
    asset_record, asset_slug, asset_sort_key, bank_record, bank_slug, bank_sort_key, doc_record, doc_slug, doc_sort_key,
    item_record, item_slug, item_sort_key, mail_record, mail_slug, mail_sort_key, person_record, person_slug,
    person_sort_key, registry_record, registry_slug, registry_sort_key, work_order_record, work_order_slug,
    work_order_sort_key,
)
from .economy import EconomySummary, summarize
from .expand import expand
from .expected import load_projection
from .jsonc import load_jsonc
from .model import Records
from .pdfcache import PdfCache
from .story import Story, load_story
from .timeline import manifest_created

MANAGED = ("manifest.jsonc", "setup", "persons", "items", "workOrders", "assets", "docs", "bank", "registry", "mail")
DEMO_IDENTITIES = "demo/invoices/suppliers.jsonc"
CORPUS_IDENTITIES = "tests/corpus/cz/identities.jsonc"
PROJECTION = "tests/corpus/projection.jsonc"
INVOICE_TEMPLATES = "demo/invoices/templates"
REGISTRY_TEMPLATES = "demo/datasets/templates"


@dataclass(frozen=True)
class DemoSummary:
    story: Story
    counts: dict[str, int]
    rendered: int
    reused: int
    sync: SyncResult
    out_dir: Path
    economy: EconomySummary    # souhrn ekonomiky příběhu (D25) ze stejných záznamů jako soubory


def _templates(directory: Path) -> dict[str, str]:
    return {p.stem: p.read_text(encoding="utf-8") for p in sorted(directory.glob("*.html"))}


def _encode(value) -> bytes:
    return dumps_dataset(value).encode("utf-8")


def build_files(story: Story, records: Records, catalogs: Catalogs, *, projection, cache: PdfCache,
                out_dir: Path) -> tuple[dict[str, bytes], dict[str, int]]:
    """Relativní cesta → bajty pro celou sadu + počty sekcí (v pořadí dumpu)."""
    files: dict[str, bytes] = {}
    counts: dict[str, int] = {}

    setup_count = 0
    for table in SETUP_TABLES:
        if table in records.setup:
            files[f"setup/{table}.jsonc"] = _encode(records.setup[table])
            setup_count += 1
    counts["setup"] = setup_count
    counts["exchangeRates"] = 0   # kurzy nese doklad (D13), lístky sada nemá

    persons = sorted(records.persons, key=person_sort_key)
    for i, rec in enumerate(persons, start=1):
        files[f"persons/{file_name(i, person_slug(rec))}"] = _encode(person_record(rec))
    counts["persons"] = len(persons)

    items = sorted(records.items, key=item_sort_key)
    for i, item in enumerate(items, start=1):
        files[f"items/{file_name(i, item_slug(item))}"] = _encode(item_record(item, catalogs))
    counts["items"] = len(items)

    docs = sorted(records.docs, key=doc_sort_key)
    # Koncept dostane v Shipardu zástupné číslo `!` + id doplněné nulami
    # (`DocDocument`); id = pořadí vložení = pořadí souboru v čerstvém DS.
    for position, doc in enumerate(docs, start=1):
        if doc.state == 10 and doc.number is None:
            doc.number = f"!{position:010d}"
    number_by_key = {doc.key: doc.number for doc in docs}
    for i, doc in enumerate(docs, start=1):
        files[f"docs/{file_name(i, doc_slug(doc))}"] = _encode(doc_record(doc, story))
    counts["docs"] = len(docs)

    # Zakázky (#131 fáze 2b) — evidence období odkazuje čísla dokladů
    # včetně zástupných čísel konceptů, proto až po přidělení výše.
    work_orders = sorted(records.work_orders, key=work_order_sort_key)
    for i, rec in enumerate(work_orders, start=1):
        files[f"workOrders/{file_name(i, work_order_slug(rec))}"] = _encode(work_order_record(rec, story, number_by_key))
    counts["workOrders"] = len(work_orders)

    # Karty majetku (#131 fáze 2c) — před doklady v pořadí seedu; soubor = inventární číslo.
    assets = sorted(records.assets, key=asset_sort_key)
    for i, rec in enumerate(assets, start=1):
        files[f"assets/{file_name(i, asset_slug(rec))}"] = _encode(asset_record(rec))
    counts["assets"] = len(assets)

    statements = sorted(records.bank, key=bank_sort_key)
    for i, statement in enumerate(statements, start=1):
        files[f"bank/{file_name(i, bank_slug(statement))}"] = _encode(bank_record(statement))
    counts["bank"] = len(statements)

    registry = sorted(records.registry, key=registry_sort_key)
    for i, rec in enumerate(registry, start=1):
        base = f"registry/{file_name(i, registry_slug(rec), '')}"
        assert rec.html is not None
        pdf = cache.resolve(rec.key, rec.html, out_dir, f"{base}.files/{rec.pdf_name}")
        files[f"{base}.files/{rec.pdf_name}"] = pdf
        files[f"{base}.jsonc"] = _encode(registry_record(rec, pdf, story))
    counts["registry"] = len(registry)

    mails = sorted(records.mails, key=mail_sort_key)
    for i, mail in enumerate(mails, start=1):
        base = f"mail/{file_name(i, mail_slug(mail), '')}"
        pdf = None
        if mail.html is not None and mail.pdf_name:
            pdf = cache.resolve(mail.key, mail.html, out_dir, f"{base}.files/{mail.pdf_name}")
            files[f"{base}.files/{mail.pdf_name}"] = pdf
        target_number = number_by_key.get(mail.target_doc_key) if mail.target_doc_key else None
        files[f"{base}.jsonc"] = _encode(mail_record(
            mail, pdf, story, projection=projection, taxonomy=set(catalogs.taxonomy), target_number=target_number,
        ))
    counts["mail"] = len(mails)

    ordered_counts = {section: counts[section] for section in SECTIONS if section in counts}
    files["manifest.jsonc"] = _encode(manifest(
        name=story.name, title=story.title, description=story.description,
        created=manifest_created(story.anchor), country=story.own.country,
        provision={"accountingItems": "all"}, counts=ordered_counts,
    ))
    return files, ordered_counts


def build_demo_dataset(story_dir: Path, out_dir: Path, *, repo_root: Path, anchor: date | None = None,
                       state_path: Path | None = None, force: bool = False, render=None) -> DemoSummary:
    catalogs = load_catalogs(repo_root)
    story = load_story(
        story_dir, catalogs, anchor=anchor,
        reserved_demo=load_jsonc(repo_root / DEMO_IDENTITIES),
        reserved_corpus=load_jsonc(repo_root / CORPUS_IDENTITIES),
    )
    records = expand(
        story, catalogs,
        invoice_templates=_templates(repo_root / INVOICE_TEMPLATES),
        registry_templates=_templates(repo_root / REGISTRY_TEMPLATES),
    )
    projection = load_projection(repo_root / PROJECTION)
    cache_args = {"state_path": state_path or (story_dir / "build-state.json"), "force": force}
    if render is not None:
        cache_args["render"] = render
    cache = PdfCache(**cache_args)
    files, counts = build_files(story, records, catalogs, projection=projection, cache=cache, out_dir=out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    sync = sync_tree(out_dir, files, managed=MANAGED)
    cache.save()
    return DemoSummary(story=story, counts=counts, rendered=len(cache.rendered), reused=len(cache.reused),
                       sync=sync, out_dir=out_dir, economy=summarize(story, records))
