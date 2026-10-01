# Majetek Fáze 4 — vazba na doklady

**Stav:** naplánováno — D57–D64 potvrzena 2026-10-01; implementace nezačala

> PRD pro jednu Claude Code session (7 commitů). Design: `docs/assets.md`
> §4 (D14, D15, D47, D57–D64), §5.4; issue #83. Navazuje na
> `tasks/assets-phase3.md`.

## Kontext

Fáze 3 dala deníku dimenzi `asset`, ale plní ji jen systémové doklady
majetku. Tahle fáze ji otevře běžným dokladům: majetek na hlavičce
a řádcích přijatých a vydaných faktur, pokladních a účetních dokladů
(náklady a výnosy na majetek), karta na řádku pořízení `purchase.asset`
s možností kartu z řádku rovnou založit, zařazení předvyplněné
z pořízení a dva alerty. K tomu dvě validace z kontroly fáze 3.

## Cíl

1. Validace: úplná účetní skupina (D57), ruční událost v založeném
   účetním roce (D58).
2. Nastavení „Sledovat náklady na majetek“ (D59) a obecné zobrazení
   polí dimenzí na formulářích dokladů.
3. Výchozí dimenze z hlavičky (D60).
4. Karta na řádku pořízení (D61) a založení karty z řádku (D62) —
   obecné rozšíření lookupu o výchozí hodnoty z rodiče.
5. Sekce Pořízení a předvyplněné zařazení (D63).
6. Tab Náklady a výnosy na kartě (D64).
7. Alerty „Pořízení majetku bez karty“ a „Majetek čeká na zařazení“.

## Před implementací přečti

- `docs/assets.md` §4 D14, D15, D47, D57–D64, §5.3–5.4
- `docs/accounting.md` § Dimenze deníku, §2 (vlajky operací)
- `modules/economy/assets/module.jsonc` (`journalDimensions`,
  `settingsPages`), `src/Core/Accounting/JournalDimensionSet.php`
- `modules/docs/core/src/DocRowsForm.php` (pole `asset` u `rowAsset`),
  `DocsHeadsFormBase.php`, `modules/docs/accountingDocs/src/AccountingDocsForm.php`
- `src/Core/Form/Lookup/TableLookup.php`, `docs/edit-forms.md` kap. 22
  (lookup, `createForm`), frontend `LookupInput.svelte`, `FormDialog.svelte`
- `docs/alerts.md`, vzor `modules/docs/core/src/Checks/StaleInRepairCheck.php`
- `modules/economy/assets/src/AssetDocument.php`, `AccountingGroupDocument.php`,
  `AssetEventDocument.php`, `AssetsViewer.php`, `AssetEventsForm.php`

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** přehledy majetku a kontrola invariantu (fáze 5); import,
převzetí `usePropertyExpenses`, backfill vazeb a `asset` ve výměnném
formátu (fáze 6); prodej majetku s nabídkou vyřazení (fáze 7); AI
návrh karty při analýze faktury.

## 1. Validace (D57, D58)

- `AssetDocument`: odepisovaný druh (`depreciable`) → účetní skupina musí
  mít `account_depreciation` i `account_accumulated`, jinak chyba na poli
  `accounting_group` (kód `accountingGroupIncomplete`) při potvrzení (40).
  Koncept se uložit smí.
- `AccountingGroupDocument`: uložení bez účtu odpisů / oprávek není chyba
  (pozemky je nemají), ale `HeaderInfo` / hint formuláře upozorní, že
  skupina nejde použít pro odepisovaný majetek.
- `AssetEventDocument`: ruční událost (`origin = manual`) kromě `opening`
  musí mít `event_date` v založeném účetním roce
  (`economy_codebooks_fiscal_years`), jinak chyba na poli `event_date`
  (kód `outsideFiscalYear`): „Datum není v žádném účetním roce. Majetek
  zařazený před prvním účetním obdobím zadej jako počáteční stav.“
- Testy obou pravidel.

## 2. Nastavení a pole dimenzí (D59)

- Stránka `assetsDepreciation` (nebo nová „Majetek — obecné“, rozhodne
  implementace): `economy.assets.trackExpenses` (`select` ano / ne,
  výchozí ne).
- `journalDimensions` rozšířit o deklaraci formulářů (obecně, bez znalosti
  majetku v `docs.core`):

  ```jsonc
  "forms": {
      "docTypes": ["invni", "invno", "cash", "cmnbkp"],
      "head": true, "rows": true,
      "enabledBySetting": "economy.assets.trackExpenses"
  }
  ```

  `JournalDimensionSet` vrací dimenze pro formulář podle typu dokladu
  a nastavení (`SettingsStore`). `DocsHeadsFormBase` a `DocRowsForm` (resp.
  `AccountingDocsForm`) vykreslí lookup dimenze (`table` z deklarace,
  label z deklarace) na hlavičce a na řádku. Pole u operací s `rowAsset`
  (systémové) a u `purchase.asset` (D61) zůstává vždy bez ohledu na
  nastavení.
- Extension `economy.assets` → `docs_core_heads.asset` (int, null,
  reference `economy_assets_assets`, index).
- Uložená hodnota při vypnutém nastavení se nemaže (jen se nezobrazuje
  pole); dimenze se v deníku propisuje dál.
- `docs/accounting.md` § Dimenze deníku doplnit o `forms`.

## 3. Výchozí hodnota z hlavičky (D60)

- `journalDimensions.asset.headColumn = "asset"`.
- Engine už fallback umí — ověřit testem: řádek bez karty → deník nese
  kartu hlavičky; řádek s kartou → vlastní; hlavičkové kroky (321, 343)
  → karta hlavičky.
- Formulář řádku: placeholder pole Majetek = „Z hlavičky: {inv. č.}“,
  je-li na hlavičce vyplněno.

## 4. Pořízení a založení karty (D61, D62)

- `rowOperations.jsonc`: `purchase.asset` + vlajka `rowAsset: "optional"`
  (nepovinná karta; `rowAsset: 1` z fáze 3 zůstává povinné).
  `DocRowOperationRules` a `DocRowsForm` vlajku rozliší;
  `docs/accounting.md` tabulka vlajek.
- Lookup — obecné rozšíření **výchozí hodnoty nového záznamu z rodiče**:
  - flag `createDefaults: true` v definici lookupu (PHP builder i JSONC);
  - `TableLookup::createDefaults(array $parentRow, array $parentHead):
    array` (default `[]`);
  - endpoint `POST /_ui/lookup/{table}/create-defaults` (tělo: data
    rodičovského formuláře — řádek a hlavička), `ReadOnlyPolicy` Allow;
  - `LookupInput` před otevřením `FormDialog` pro „+ Vytvořit nový“
    zavolá endpoint a výsledek předá jako `defaultData`;
  - `docs/edit-forms.md` kap. 22.
- `AssetsLookup::createDefaults()`: `name` = text řádku (`description`),
  `acquired_date` = účetní datum hlavičky, `category` podle účtu řádku
  (prefix `04` → `tangible`, `5` → `small`, jinak nic), u `small`
  `price` = `vat_base_dom` řádku. Účet se čte přes `account` →
  `economy_accounting_accounts.number`.
- Lookup karty na řádku `purchase.asset` s `createForm`, `editForm`,
  `createDefaults`.

## 5. Pořízení na kartě a zařazení (D63)

- `AssetPlanService` (nebo nová `AssetAcquisitionService`): navázané
  řádky = `docs_core_rows.asset = karta` s operací `purchase.asset`
  na potvrzených dokladech (hlavička ve stavu 40); součet `vat_base_dom`,
  poslední účetní datum, rozpad podle účtu (04x / ostatní).
- Detail karty: sekce **Pořízení** v Přehledu — tabulka (doklad s
  odkazem `open_detail`, datum, text, účet, částka) + součet.
- Akce detailu **Zařadit** (`open_form`, preset): u nezařazené dlouhodobé
  karty s pořízením na 04x doplnit do presetu `amount` = součet řádků na
  04x a `event_date` = poslední účetní datum. Uživatel může upravit.
- Testy služby (součty, stavy dokladů, jen `purchase.asset`).

## 6. Náklady a výnosy (D64)

- Tab detailu **Náklady a výnosy** (zobrazit jen, když existuje aspoň
  jeden řádek deníku s dimenzí karty mimo operace `asset.*`):
  souhrn po účetních letech (náklady 5xx, výnosy 6xx, ostatní účty
  zvlášť) a tabulka řádků (datum, doklad s odkazem, účet, text, MD,
  DAL). Řazení od nejnovějších, stránkování nebo limit (např. 200 řádků
  + odkaz do deníku s filtrem dimenze).
- Odkaz „Otevřít v deníku“ → `open_viewer` deníku s filtrem dimenze
  (filtr existuje z fáze 3).

## 7. Alerty

`alertChecks` v `economy.assets` (vzor `StaleInRepairCheck`):

- `economy.assets.purchase_without_asset` — potvrzené doklady s řádkem
  `purchase.asset` bez karty; severity `warning`, `navSection: assets`;
  akce otevře doklad.
- `economy.assets.awaiting_activation` — dlouhodobá karta s potvrzeným
  pořízením na 04x bez potvrzeného zařazení / počátečního stavu;
  severity `info`; akce otevře kartu.

Testy obou checků.

## Uživatelská dokumentace

- `help/majetek/naklady-na-majetek.md` (nová) — nastavení, majetek na
  hlavičce a řádku, dědění z hlavičky, tab Náklady a výnosy.
- `help/majetek/evidence-majetku.md` / `odpisy-majetku.md` — založení
  karty z řádku faktury, sekce Pořízení, zařazení z pořízení, alerty.
- `help/co-dnes-nejde.md` — vazba na faktury hotová; zůstává import
  a prodej majetku.
- `python3 scripts/help-index.py`.

## Testy

- `AssetDocumentTest`, `AccountingGroupDocumentTest`,
  `AssetEventDocumentTest` — D57, D58.
- `JournalDimensionSetTest` — `forms`, `enabledBySetting`, `headColumn`.
- `AccountingEngineTest` — fallback z hlavičky (D60).
- `DocRowOperationRulesTest` — `rowAsset: "optional"`.
- `AssetsLookupTest` — `createDefaults` (04x, 5xx, bez účtu).
- lookup endpoint `create-defaults` + `ReadOnlyPolicyTest`.
- `AssetAcquisitionServiceTest` (nebo v `AssetPlanServiceTest`).
- alert checky.
- `vendor/bin/phpunit --filter 'Assets|JournalDimension|AccountingEngine|DocRowOperation|Lookup|ReadOnlyPolicy'`,
  pak celá sada; `cd frontend && npm run build && npm run check:i18n`.

## Task breakdown

1. **Validace D57, D58** + testy.
2. **Nastavení a pole dimenzí** — `trackExpenses`, `forms` v
   `journalDimensions`, extension `docs_core_heads.asset`, formuláře
   hlavičky a řádků + testy + `docs/accounting.md`.
3. **Výchozí z hlavičky** — `headColumn`, placeholder, test enginu.
4. **Pořízení a založení karty** — `rowAsset: "optional"`, lookup
   `createDefaults` (backend, endpoint, frontend), `AssetsLookup` + testy
   + `docs/edit-forms.md`.
5. **Pořízení na kartě a zařazení** — služba, sekce Pořízení, preset
   akce Zařadit + testy.
6. **Náklady a výnosy + alerty** — tab, odkaz do deníku, dva checky
   + testy.
7. **Dokumentace** — help, `docs/assets.md` (oblast 4 → hotovo,
   odchylky), Stav tasku + `python3 scripts/tasks-index.py`.

*Hotovo celé když* na ukázkovém DS (`4l3j-z0bz-kz39-echj`): se zapnutým
nastavením má přijatá faktura pole Majetek na hlavičce i řádcích, deník
nese kartu (řádky bez karty z hlavičky); na řádku `purchase.asset`
(042) jde založit kartu s předvyplněným názvem, datem a druhem; karta
ukazuje Pořízení a Zařadit předvyplní součet; po zaúčtování období je
zařazení v deníku; faktura s nákladem na kartu se ukáže v tabu Náklady
a výnosy; oba alerty se objeví a po nápravě zmizí; karta se smoke
skupinou bez účtu oprávek nejde potvrdit; ruční zařazení s datem před
prvním účetním rokem je odmítnuté.

## Rozhodnutí k designu (potvrzená)

- ✓ D57 Odepisovaný druh jen s úplnou účetní skupinou.
- ✓ D58 Ruční události v založeném účetním roce; jinak počáteční stav.
- ✓ D59 Pole Majetek na dokladech jen se zapnutým nastavením.
- ✓ D60 Výchozí karta z hlavičky.
- ✓ D61 Karta na `purchase.asset` nepovinná + alert.
- ✓ D62 Založení karty z řádku s předvyplněním (obecné rozšíření lookupu).
- ✓ D63 Sekce Pořízení, zařazení předvyplněné součtem základů.
- ✓ D64 Tab Náklady a výnosy; prodej majetku až fáze 7.
