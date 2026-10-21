"""Číselníky Shipardu, proti kterým se validuje příběh demo sady: účtová
osnova, nabídka účetních položek, jednotky, druhy dokumentů spisovny,
účely odeslání kontaktů a dostupné šablony PDF. Čte se přímo z repozitáře
(konfigurace modulů), žádná databáze."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from .jsonc import load_jsonc

CHART_FILE = "modules/economy/accounting/config/accountChartDefault.jsonc"
OFFER_FILE = "modules/economy/items/config/accountingItemsDefault.jsonc"
UNITS_FILE = "modules/core/units/config/unitsSeed.jsonc"
ITEM_KINDS_FILE = "modules/economy/items/config/itemKindsSeed.jsonc"
TAXONOMY_FILE = "modules/core/exchange/config/contentTags.jsonc"
REGISTRY_KINDS_FILE = "modules/base/registry/config/docKinds.jsonc"
PERSONS_MODULE_FILE = "modules/base/persons/module.jsonc"
PERIOD_TEXTS_FILE = "modules/economy/workOrders/config/periodTexts.jsonc"
INVOICE_TEMPLATES_DIR = "demo/invoices/templates"
REGISTRY_TEMPLATES_DIR = "demo/datasets/templates"


@dataclass(frozen=True)
class OfferItem:
    code: str
    name_cs: str
    account: str
    content_tags: tuple[str, ...]


@dataclass(frozen=True)
class ItemKind:
    code: str          # system_code druhu (service, stock, accounting, other)
    name_cs: str
    item_type: int


@dataclass(frozen=True)
class Catalogs:
    chart_accounts: frozenset[str]
    offer: dict[str, OfferItem]
    units: frozenset[str]
    unit_shortcuts: dict[str, str]               # system_code → česká zkratka (ks, hod, měs)
    item_kinds: dict[str, ItemKind]
    taxonomy: frozenset[str]                     # štítky obsahu (core.exchange.contentTags)
    registry_kinds: dict[str, tuple[str, ...]]   # druh → povolené klíče kindFields
    send_purposes: frozenset[str]
    invoice_templates: frozenset[str]
    registry_templates: frozenset[str]
    period_texts: dict[str, dict[str, str]]      # periodicita → jazyk → text {období} (cfgItem periodTexts, #131 fáze 2b)


def load_catalogs(repo_root: Path) -> Catalogs:
    chart = load_jsonc(repo_root / CHART_FILE)
    accounts = frozenset(str(entry["number"]) for entry in chart)

    offer_raw = load_jsonc(repo_root / OFFER_FILE)
    offer: dict[str, OfferItem] = {}
    for entry in offer_raw["items"]:
        code = str(entry["code"])
        offer[code] = OfferItem(
            code=code,
            name_cs=str(entry.get("name:cs") or entry.get("name") or code),
            account=str(entry["account"]),
            content_tags=tuple(entry.get("contentTags") or ()),
        )

    units_raw = load_jsonc(repo_root / UNITS_FILE)
    units = frozenset(str(entry["system_code"]) for entry in units_raw)
    unit_shortcuts = {str(entry["system_code"]): str(entry["shortcut"]) for entry in units_raw}
    item_kinds = {
        str(entry["system_code"]): ItemKind(code=str(entry["system_code"]), name_cs=str(entry["name:cs"]),
                                            item_type=int(entry["item_type"]))
        for entry in load_jsonc(repo_root / ITEM_KINDS_FILE)
    }
    taxonomy = frozenset(str(tag) for tag in load_jsonc(repo_root / TAXONOMY_FILE))

    kinds_raw = load_jsonc(repo_root / REGISTRY_KINDS_FILE)
    registry_kinds = {kind: tuple(spec.get("fields") or ()) for kind, spec in kinds_raw.items()}

    persons_module = load_jsonc(repo_root / PERSONS_MODULE_FILE)
    send_purposes = frozenset(str(p["id"]) for p in persons_module.get("sendPurposes", []))

    period_texts: dict[str, dict[str, str]] = {}
    for periodicity, spec in load_jsonc(repo_root / PERIOD_TEXTS_FILE).items():
        texts = {key.split(":", 1)[1]: str(value) for key, value in spec.items() if key.startswith("text:")}
        if "text" in spec:
            texts.setdefault("en", str(spec["text"]))
        period_texts[str(periodicity)] = texts

    return Catalogs(
        chart_accounts=accounts,
        offer=offer,
        units=units,
        unit_shortcuts=unit_shortcuts,
        item_kinds=item_kinds,
        taxonomy=taxonomy,
        registry_kinds=registry_kinds,
        send_purposes=send_purposes,
        invoice_templates=frozenset(p.stem for p in (repo_root / INVOICE_TEMPLATES_DIR).glob("*.html")),
        registry_templates=frozenset(p.stem for p in (repo_root / REGISTRY_TEMPLATES_DIR).glob("*.html")),
        period_texts=period_texts,
    )
