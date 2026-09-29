# Majetek Fáze 1 — karta, typy, účetní skupiny

**Stav:** naplánováno — rozhodnutí D18–D26 potvrzena 2026-09-29, implementace nezačala

> PRD pro jednu Claude Code session (5 commitů). Design: `docs/assets.md`
> (§4 D1–D26, §5, §7 oblast 1), issue #83.

## Kontext

Nový modul `economy.assets` nahrazuje `e10pro.property` starého Shipardu
(`docs/assets.md` §2–§3). Tahle fáze zakládá **evidenci**: kartu majetku,
typy, skupiny typů a účetní skupiny majetku. Nic se v ní neodepisuje ani
neúčtuje — hodnotová historie, engine a zaúčtování jsou fáze 2 a 3. Po této
fázi jde plnohodnotně vést drobný (evidovaný) majetek a zakládat karty
dlouhodobého majetku bez odpisů.

## Cíl

1. Modul `economy.assets` se čtyřmi tabulkami (D18).
2. Karta majetku s druhem (D19), cizím majetkem (D20), způsobem sledování
   (D21), inventárním číslem přidělovaným při potvrzení (D22), stavy
   s vyřazením přes archiv (D23), přílohami.
3. Číselníky typů, skupin typů a účetních skupin (D24, D25) v Nastavení.
4. Seed účetních skupin provisionerem (jen DS bez `skipProvisioning`).
5. Nová sekce navigace **Majetek** a sekce Nastavení **Majetek** (D26).
6. Uživatelská dokumentace v `help/`.

## Návaznost

- **Prerekvizity:** `tasks/viewer-bottom-tabs.md` (obecné spodní taby
  vieweru) musí být hotový; `economy.accounting` (osnova, `AccountsLookup`),
  `base.persons` (`PersonsLookup`), `core.attachments`.
- **Odemyká:** Fázi 2 (ledger událostí + engine) — ta přidá daňové a účetní
  nastavení karty a převezme data pořízení/vyřazení dlouhodobého majetku.
- Mimo tuto fázi: vše z `docs/assets.md` §7 oblastí 2–8.

## Před implementací přečti

- `docs/assets.md` celý (hlavně §4 D5, D13, D18–D26 a §5)
- Vzor modulu: `modules/economy/codebooks/module.jsonc` (`viewers`, `forms`,
  `settingsItems`, `documentClasses`, `lookups`), `modules/economy/accbal/module.jsonc`
- Vzor tabulky: `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc`
  (docStates `core.system.docStatesArchive`, columnGroups, systémové
  docState sloupce, indexy, `stateTransitionsRunDocumentHooks`)
- Vzor formuláře: `modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc`
  (lookup na účet s filtrem `number_prefix`), tab příloh:
  `modules/economy/bank/forms/economy_bank_statements.jsonc`
- Vzor vieweru: `modules/economy/codebooks/src/CashDesksViewer.php`;
  filtry pravého panelu: `TableViewer::getFilters()`; spodní taby:
  `TableViewer::getBottomTabs()` (vzor `RegistryDocumentsViewer`)
- Vzor provisioneru: `modules/economy/accounting/src/AccountChartProvisioner.php`,
  registrace: `src/Command/DataSource/DsUpgradeCommand.php` (větev bez
  `skipProvisioning`, vzor `BalancesProvisioner`)
- Settings page: `docs/app-settings.md`, vzor `settingsPages` v
  `modules/core/system/module.jsonc`
- Sekce navigace: `modules/install/base/config/navSections.jsonc`,
  `settingsSections.jsonc`; aktivace modulu: `modules/install/base/module.jsonc`
  (`dependencies`)
- Uživatelská dokumentace: `docs/help-authoring.md`
- Starý modul pro srovnání: `old_shipard:modules/e10pro/property/tables/property.json`,
  `types.json`, `groups.json`

## Scope

**Uvnitř:** modul, 4 tabulky, cfgItem enumy, Document třídy s validací
a přidělením inv. čísla, 4 formuláře, 4 viewery, lookupy (typy, účetní
skupiny, karty), settings page prefixů, provisioner účetních skupin, sekce
navigace a Nastavení, help stránka.

**Mimo:** daňové a účetní nastavení karty, události, odpisy (Fáze 2);
zaúčtování a řádkové operace `asset.*` (Fáze 3); vazba na doklady a sloupec
`asset` na řádcích/deníku (Fáze 4); přehledy a tisk karty (Fáze 5); import
(Fáze 6); předání do užívání, příslušenství, vlastnosti, místa, štítky
(Fáze 7); chování souborů a množstevních karet (Fáze 8).

## Datový model

`tableId` 450–453 (ověř `php bin/shpd-server next-table-id` — k 2026-09-29
vrací 450). Všechny tabulky: docStates `core.system.docStatesArchive`,
systémové `docState` / `docStateMain` jako u pokladen.

### `economy_assets_assets` (450) — karta

| Sloupec | Typ | Poznámka |
|---|---|---|
| `id` | int PK AI | |
| `asset_number` | varchar(20), null | inv. číslo; unikátní; prázdné u konceptu, přidělí se při potvrzení (D22) |
| `name` | varchar(150), not null | |
| `short_name` | varchar(60), null | |
| `asset_type` | int → `economy_assets_types`, null | nepovinný (D25) |
| `category` | enumString(16), cfgItem `economy.assets.categories`, not null, default `small` | D19 |
| `tracking` | enumString(16), cfgItem `economy.assets.trackingKinds`, not null, default `single` | D21 — bez chování |
| `accounting_group` | int → `economy_assets_accounting_groups`, null | povinná u dlouhodobého (D24) |
| `is_foreign` | boolean, default 0 | D20 |
| `owner` | int → `base_persons_persons`, null | povinný, je-li `is_foreign` |
| `acquired_date` | date, null | datum pořízení |
| `disposed_date` | date, null | datum vyřazení (D23) |
| `price` | numeric(15,2), null | jen drobný majetek (D13) |
| `note` | text, null | |

Indexy: `unq_asset_number` (unique, `asset_number`), `idx_category`
(`category`, `docStateMain`), `idx_type` (`asset_type`), `idx_doc_state`
(`docStateMain`, `asset_number`). `displayPattern`: `{asset_number} — {name}`.
`stateTransitionsRunDocumentHooks: true` (přidělení čísla při přechodu do 40).

### cfgItem `economy.assets.categories` (D19)

| Klíč | cs | Dlouhodobý | Odepisuje se | Prefix default |
|---|---|---|---|---|
| `small` | Drobný majetek | ne | ne | `MA` |
| `tangible` | Dlouhodobý hmotný | ano | ano | `MA` |
| `intangible` | Dlouhodobý nehmotný | ano | ano | `MA` |
| `nondepreciable` | Neodepisovaný dlouhodobý | ano | ne | `MA` |

Příznaky `longTerm` a `depreciable` nesou validace (a fáze 2); v kódu se
na klíče druhů neptat přímo, jen na příznaky.

### cfgItem `economy.assets.trackingKinds` (D21)

`single` Jednotlivá věc · `set` Soubor · `quantity` Množstevní karta.
Ve formuláři všechny tři; chování přijde ve Fázi 8.

### `economy_assets_types` (451)

`id`, `name` varchar(100) not null, `short_name` varchar(40), `type_group` →
`economy_assets_type_groups` (null), `default_category` (enumString,
null), `default_accounting_group` → účetní skupiny (null), `note` text,
`sort_order` smallint, docState sloupce. Index `idx_sort_order`.

### `economy_assets_type_groups` (452)

`id`, `name` varchar(100) not null, `sort_order`, `note`, docState sloupce.

### `economy_assets_accounting_groups` (453) — D5, D24

| Sloupec | Typ | Poznámka |
|---|---|---|
| `code` | varchar(10), not null, unique | přirozený klíč seedu |
| `name` | varchar(100), not null | |
| `account_asset` | int → `economy_accounting_accounts` | 01x/02x/03x |
| `account_acquisition` | int → účty, null | 04x |
| `account_accumulated` | int → účty, null | oprávky 07x/08x |
| `account_depreciation` | int → účty, null | 551 |
| `account_disposal` | int → účty, null | 541 |
| `note`, `sort_order`, docState | | |

Účty jsou vazby na analytické účty (lookup `economy_accounting_accounts`
s filtrem `account_level` jako u pokladny a `number_prefix` per pole).
Úplnost účtů pro zaúčtování se kontroluje až ve Fázi 3.

## Pravidla (Document třídy)

`AssetDocument::validate()`:

- `name` povinné.
- Druh s `longTerm` → `accounting_group` povinná; `price` musí být prázdná
  (chyba na poli `price`: cena dlouhodobého majetku vzniká z pohybů, D13).
- Druh bez `longTerm` → `price` volitelná, `accounting_group` volitelná.
- `is_foreign` → `owner` povinný; bez `is_foreign` se `owner` vyprázdní
  v `beforeSave()`.
- `disposed_date` ≥ `acquired_date`, jsou-li obě vyplněná.
- Přechod do 70 (V archívu = vyřazeno) vyžaduje `disposed_date`
  (form-level chyba `_form`, kód `disposedDateRequired`).
- `asset_number` unikátní mezi nesmazanými kartami (chyba na poli).

Přidělení inv. čísla (D22) — `AssetNumberAllocator`, volaný
z `beforeSave()` při přechodu do 40, když je `asset_number` prázdné:

- prefix = settings klíč `economy.assets.numberPrefix.<category>`,
  fallback prefix z cfgItem druhu;
- číslo = max číselné části mezi kartami se stejným prefixem
  (`^<prefix>[0-9]+$`) + 1, doplněné nulami na 4 místa (`MA0001`),
  při delším existujícím čísle beze ztráty cifer;
- výpočet i zápis v transakci uložení (zámek řádků `SELECT … FOR UPDATE`
  nad kartami s prefixem), ať dva souběžně potvrzované koncepty nedostanou
  stejné číslo — unikátní index je poslední pojistka;
- ručně zadané číslo se nepřepisuje; importovaná čísla (fáze 6) také ne.

`AssetTypeDocument`, `AssetTypeGroupDocument`, `AccountingGroupDocument`:
povinné názvy; u účetní skupiny povinné `code`, `name`, `account_asset`.

## UI

### Navigace a Nastavení (D26)

- `navSections.jsonc`: nová sekce `assets` („Majetek“, ikona `box`,
  `order` 35 — mezi Prodejem a Účtárnou).
- Viewer `economy.assets.assets` („Majetek“, `navSection: assets`).
- `settingsSections.jsonc`: nová sekce `assets` („Majetek“); do ní
  `settingsItems` typů, skupin typů, účetních skupin a settings page
  prefixů inv. čísel (`economy.assets.numbering`, 4 pole — prefix per druh).
- `install.base` → `dependencies` + `economy.assets`.

### Viewer karet

- Řádek: inv. číslo, název, typ, druh (badge), cizí (příznak + vlastník),
  datum pořízení, u drobného cena.
- ViewGroups ze stavů (aktivní / archiv = vyřazené / koš).
- **Spodní taby** (`getBottomTabs()`, `tasks/viewer-bottom-tabs.md`):
  Vše (`all`, výchozí), Drobný, Dlouhodobý hmotný, Dlouhodobý nehmotný,
  Neodepisovaný (id = klíč druhu, `newRecordDefaults: {category}`), Cizí
  (`foreign`, `newRecordDefaults: {is_foreign: 1}`). Popisky druhů
  z cfgItem, ne napevno.
- Filtry pravého panelu (`getFilters()`): **Typ**, **Účetní skupina**.
- Fulltext přes `SearchCondition` (inv. číslo, název, zkrácený název).
- Detail (`renderDetail`): přehled karty; sekce hodnot připraví Fáze 2.

### Formulář karty

Tab **Karta**: inv. číslo, název, zkrácený název, typ (lookup), druh
(select), způsob sledování (select), účetní skupina (lookup), cizí +
vlastník (lookup osob), datum pořízení, datum vyřazení, cena, poznámka.
Výběr typu předvyplní druh a účetní skupinu (`recalculate`), jen pokud
jsou prázdné. Cena se u dlouhodobých druhů skrývá.
Tab **Přílohy**: `type: attachments`, `tableId` 450.

### Číselníky

Formuláře a viewery typů, skupin typů a účetních skupin podle vzoru
pokladen. Lookupy: `AssetTypesLookup`, `AccountingGroupsLookup`,
`AssetsLookup` (pro Fázi 4 a 7; hledá v inv. čísle a názvu).

## Seed účetních skupin

`AccountingGroupsProvisioner` (vzor `AccountChartProvisioner`), jen ve
větvi bez `skipProvisioning` — migrované DS dostanou skupiny importem
(Fáze 6). Idempotentní dle `code`; existující skupinu nemění. Skupinu
založí jen tehdy, když v osnově existují **všechny** její vyplněné účty;
jinak ji přeskočí a vypíše do výstupu `ds-upgrade -v`.

Seed v `config/accountingGroups.jsonc` (docState 40):

| `code` | Název | Majetek | Pořízení | Oprávky | Odpisy | Vyřazení |
|---|---|---|---|---|---|---|
| `021` | Stavby | 021100 | 042100 | 081100 | 551100 | 541100 |
| `022` | Samostatné movité věci | 022100 | 042100 | 082100 | 551100 | 541100 |
| `013` | Software | 013100 | 041100 | 073100 | 551100 | 541100 |
| `014` | Ocenitelná práva | 014100 | 041100 | 074100 | 551100 | 541100 |
| `019` | Ostatní dlouhodobý nehmotný majetek | 019100 | 041100 | 079100 | 551100 | 541100 |
| `031` | Pozemky | 031100 | 042100 | — | — | 541100 |
| `032` | Umělecká díla a sbírky | 032100 | 042100 | — | — | 541100 |

Účty ověř proti `modules/economy/accounting/config/accountChartDefault.jsonc`
(k 2026-09-29 všechny existují).

## Uživatelská dokumentace

- `help/majetek/evidence-majetku.md` — založení karty, druhy, cizí majetek,
  inventární číslo, vyřazení drobného majetku; šablona `docs/help-authoring.md`
  §4, klíčová slova vč. hovorových („inventář“, „inventární číslo“,
  „drobný majetek“, „DHM“).
- `help/majetek/nastaveni-majetku.md` — typy, skupiny typů, účetní skupiny,
  prefixy inv. čísel.
- `help/co-dnes-nejde.md`: odpisy, zaúčtování, předání do užívání, přehledy
  majetku a import majetku ze starého Shipardu zatím nejdou.
- Popisky tlačítek a stavů ověřit v `module.jsonc` a `frontend/src/i18n/cs.js`;
  pak `python3 scripts/help-index.py`.

## Task breakdown

1. **Modul a schéma** — `module.jsonc`, 4 tabulky, 2 cfgItem enumy,
   sekce navigace a Nastavení, závislost v `install.base`.
   *Hotovo když:* `ds-upgrade` na `4l3j-z0bz-kz39-echj` založí tabulky
   a druhé spuštění nic nemění.
2. **Document třídy** — validace, `AssetNumberAllocator`, registrace
   `documentClasses`. Unit testy: validace per druh, cizí majetek,
   vyřazení bez data, přidělení čísla (prázdná tabulka, existující
   `MA0041`, delší číslo `MA12345`, jiný prefix se nepočítá, ručně
   zadané se nepřepisuje).
   *Hotovo když:* `vendor/bin/phpunit --filter 'Assets'` zelené.
3. **UI** — formuláře, viewery, lookupy, settings page prefixů, tab příloh.
   *Hotovo když:* na ukázkovém DS jde založit, potvrdit (číslo se přidělí),
   vyřadit a přiložit soubor; `npm run build` projde, pokud se sahalo na
   frontend.
4. **Seed** — `AccountingGroupsProvisioner` + registrace v `DsUpgradeCommand`
   + test idempotence a přeskočení skupiny s chybějícím účtem.
   *Hotovo když:* ukázkový DS má 7 skupin, opakovaný `ds-upgrade` nic nezdvojí.
5. **Dokumentace** — help stránky, `co-dnes-nejde.md`, `docs/assets.md`
   (oblast 1 → hotovo, odkaz na tento task), `tasks/README.md` (nová
   sekce Majetek), **Stav** tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu (potvrzená)

- ✓ D18 Tabulky 450–453 (karta, typy, skupiny typů, účetní skupiny).
- ✓ D19 Druhy: drobný, dlouhodobý hmotný, dlouhodobý nehmotný,
  neodepisovaný dlouhodobý; leasing jako druh neexistuje.
- ✓ D20 Cizí majetek = příznak + vlastník (osoba).
- ✓ D21 Způsob sledování jako sloupec hned, chování ve Fázi 8.
- ✓ D22 Inv. číslo: text ≤ 20, unikátní, při potvrzení prefix dle druhu
  + 4 místa; importovaná čísla beze změny.
- ✓ D23 V archívu = vyřazeno; karta nese datum pořízení a vyřazení, cenu
  jen drobný majetek.
- ✓ D24 Účetní skupina: 5 účtů, povinná u dlouhodobého, seed 7 skupin.
- ✓ D25 Typ nese skupinu, výchozí druh a účetní skupinu; na kartě
  nepovinný; bez seedu.
- ✓ D26 Sekce navigace a Nastavení Majetek, přílohy, help stránky;
  druh a cizí majetek jako spodní taby vieweru (po zobecnění tabů,
  `tasks/viewer-bottom-tabs.md`), typ a účetní skupina ve filtrech.
