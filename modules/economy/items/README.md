# Modul: Položky (economy.items)

Modul spravuje katalog položek používaných v dokladovém a skladovém
systému — zboží, služby, účetní položky a ostatní. Položky jsou
klasifikovány **druhem** (uživatelsky definovatelný číselník) a
**typem** (systémový enum: služba / zásoba / účetní / ostatní).

Modul je úmyslně držen úzký pro Fázi 1: nezahrnuje skladovou evidenci,
ceníkový mechanismus, sazby DPH ani vícenásobné měny — to vše dorazí
později spolu s VAT modulem a `economy.docs`.

## Závislosti

- `core.system`
- `core.units` — položka odkazuje na měrnou jednotku

## Tabulky

| Tabulka | Popis |
|---|---|
| [economy_items_kinds](tables/economy_items_kinds.md) | Druhy položek (Konzultace IT, Materiál, …) |
| [economy_items](tables/economy_items.md) | Hlavní katalog položek |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [ItemKindDocument.php](src/ItemKindDocument.php) | Validace druhu — chrání před změnou `item_type` u použitého druhu |
| [ItemKindsForm.php](src/ItemKindsForm.php) | Formulář pro druh položky |
| [ItemKindsViewer.php](src/ItemKindsViewer.php) | Viewer druhů včetně tabu se seznamem položek |
| [ItemKindsProvisioner.php](src/ItemKindsProvisioner.php) | Idempotentní seed 4 systémových druhů |
| [ItemDocument.php](src/ItemDocument.php) | Validace položky + auto-gen kódu + denormalizace `item_type` |
| [ItemsForm.php](src/ItemsForm.php) | Formulář pro položku, recalculate na změnu druhu |
| [ItemsViewer.php](src/ItemsViewer.php) | Viewer položek s JOIN na druh a jednotku |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.items.itemTypes` | [config/itemTypes.jsonc](config/itemTypes.jsonc) | Typ položky — služba / zásoba / účetní / ostatní |
| `economy.items.sourceKinds` | [config/sourceKinds.jsonc](config/sourceKinds.jsonc) | Klíče pro sloupec `source_kind` v `economy_items` — `manual`, `aiExtraction`, `import.oldShipard`, `import.csv`, `import.supplierCatalog`, `setup.accountingItems` |

## Účetní položky (item_type = 2)

Řádek dokladu s operací `acc.entry` (na interním dokladu `acc.item`) se
účtuje **přímo na účet uvedený na položce** — účtovací předpis má
`accountSrc: "item"` a `AccountingEngine::resolveItemAccount()` vyžaduje
položku typu Účetní položka s vyplněným účtem, jinak vznikne chybový řádek
deníku (`item_account_missing`). Slouží pro bankovní poplatky, úroky,
kurzové rozdíly nebo zaokrouhlení.

Sloupec `accounting_account` **není** přímý sloupec `economy_items` — je to
extension z `modules/economy/accounting/extensions/economy_items.jsonc`
(účetnictví zná položky, položky o účetnictví nevědí).

Startovní sadu sedmi účetních položek lze **jednorázově vygenerovat
z panelu Nastavení zdroje dat** (sekce „Volitelné", ds-setup Task 10,
D18/D19 — není to provisioner, smazané položky se nevracejí). Seed sady
jsou **dvě**, jedna per varianta osnovy
([config/accountingItemsDefault.jsonc](config/accountingItemsDefault.jsonc),
[config/accountingItemsNpo.jsonc](config/accountingItemsNpo.jsonc)) —
obě osnovy používají stejná čísla pro jiné účty, takže filtr podle
existence čísla by byl chyba. Vygenerované položky nesou
`source_kind = 'setup.accountingItems'` a `source_ref` s kódem ze seedu.

## Obsahové štítky a místo plnění

Sloupec `content_tags` (JSON list klíčů taxonomie
`core.exchange.contentTags`) neslouží jen párování řádků návrhu na položku
(obsahová eskalace, `tasks/content-tag-enrichment.md`). U přijatého
dokladu od dodavatele mimo tuzemsko podle něj `core.exchange` rozhoduje
i o **zvláštním místě plnění** řádku pokrytého položkou (#89 D19–D21,
`docs/exchange-format.md` § 8.4 → *Zvláštní místo plnění ze štítků
položky*). Počítají se jen štítky, které mají v taxonomii
`crossBorderSupply`:

- položka jen se štítky `special` (ubytování + jízdné; mýto + správní
  poplatky — štítek bez určení se nepočítá) → řádek má zvláštní místo
  plnění, doklad bez daně dodavatele vznikne Bez DPH;
- **sběrná položka s různorodými štítky** (parkování a ostraha `special`,
  přeprava `services`) → výsledek je nejednoznačný: návrh počítá se
  samovyměřením, řádek dostane varování a karta jde do kontroly.

Položka založená pro štítek nese právě jeden; víc štítků mají nabídkové
a ručně upravené položky. Kdo chce jednoznačný výsledek, sběrnou položku
rozdělí — štítky na položce jsou tu zdroj pravdy, štítek se na řádek
dokladu neukládá.

## Seedovaná data

Provisioner při `ds-upgrade` naplní **4 systémové druhy**, jeden per
`item_type`, podle [config/itemKindsSeed.jsonc](config/itemKindsSeed.jsonc):
`service`, `stock`, `accounting`, `other`. Tytéž pravidla idempotence
jako u [`core.units`](../../core/units/README.md): respektuje, pokud
uživatel záznam zarchivoval.

## Vazby

- `economy_items.item_kind` → `economy_items_kinds.id` (povinné)
- `economy_items.item_type` — denormalizace z `item_kind` (server-side
  v `beforeSave` + recalculate při změně druhu ve formuláři)
- `economy_items.unit` → `core_units.id` (povinné, default `pcs` u nové
  položky)

## Cena bez DPH (Fáze 1)

Položka má pouze sloupec `sales_price_no_vat`. Cena s DPH a sazba DPH
přijdou s VAT modulem; do té doby se v UI zobrazuje cena natvrdo
v Kč jako domácí měně DS. Měna položky není sloupec — bude řešena
ceníkovým mechanismem v budoucnu.
