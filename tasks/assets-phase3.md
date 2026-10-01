# Majetek Fáze 3 — zaúčtování a dimenze deníku

**Stav:** hotovo — 2026-10-01 (8 commitů, ověřeno na `4l3j-z0bz-kz39-echj`); odchylky od zadání níže a v `docs/assets.md` §5.4; zbývá proklik UI v prohlížeči a nasazení na alfu

> PRD pro jednu Claude Code session (7 commitů). Design: `docs/assets.md`
> §4 (D4, D15, D47–D56), §5.3; issue #83. Navazuje na `tasks/assets-phase2b.md`.

## Kontext

Po fázi 2 má majetek události (zařazení, TZ, snížení, odpisy, vyřazení)
a akci „Odpisy za období“, ale **nic se neúčtuje**. Tahle fáze doplní
zaúčtování: systém z potvrzených událostí účetního okruhu sestaví jeden
účetní doklad (`cmnbkp`) za období, řádky ponesou kartu majetku a ta se
propíše do deníku jako **dimenze**. Tím začne platit invariant
„evidence = deník“ (`docs/assets.md` §1).

Vzor: zaúčtování přiznání DPH — `modules/economy/vat/src/Accounting/VatReturnAccountingService.php`
(doklad přes `TransactionlessTableGateway`, řada z nastavení, vazba zpět
v jedné transakci). Rozdíl: doklad majetku vzniká rovnou **V pořádku**
(D51).

## Cíl

1. Oprava z fáze 2b: zrušené vyřazení smaže systémové odpisy (D56).
2. Obecné **dimenze deníku** (D47) — `journalDimensions` v `module.jsonc`,
   engine kopíruje hodnotu řádku do deníku a seskupuje podle ní; první
   dimenze `asset`.
3. Řádkové operace `asset.*` s vlajkami `rowAsset` a `system` (D48),
   kroky předpisu.
4. Sestavení řádků dokladu z událostí (D49) — čistá třída.
5. Běh „Odpisy a zaúčtování za období“ + „Zrušit zaúčtování období“
   (D50–D53, D55), řada dokladů (D54).
6. Zámky: zaúčtovaná událost, doklad spravovaný majetkem.
7. UI a dokumentace.

## Před implementací přečti

- `docs/assets.md` §1 (invarianty), §4 D4, D15, D27, D29, D47–D56, §5.3
- `docs/accounting.md` §2 (operace a vlajky), §4 (předpis), §6 (deník),
  §7.1–7.3 (handlery, contributoři, algoritmus enginu)
- `modules/economy/accounting/src/AccountingEngine.php` — `buildRowLines`,
  `resolveRowIdentity`, `makeLine`, `groupLines`, `writeResult`
- `modules/economy/vat/src/Accounting/VatReturnAccountingService.php`
  (vzor: řada, gateway, transakce, idempotence)
- `modules/docs/core/src/DocRowOperationRules.php`,
  `modules/docs/core/config/rowOperations.jsonc` (`acc.record`)
- `modules/docs/accountingDocs/src/AccountingDocsForm.php` (nabídka operací)
- `modules/economy/assets/src/DepreciationRunService.php`,
  `SystemDepreciationWriter.php`, `AssetEventDocument.php`,
  `AssetEventLockProvider.php`, `AssetPlanService.php`
- `docs/document-system.md` §16 (lock providery, `isLockExempt`)
- `docs/app-settings.md` (field typ `select`)

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** výchozí dimenze z hlavičky na fakturách a vazba `purchase.asset`
→ karta (fáze 4); kontrola invariantu jako report / alert, přehledy
účtování (fáze 5); import a backfill vazeb (fáze 6); nastavení
„zaúčtovat zařazení hned“ (D50 — až na požádání).

## 1. Zrušené vyřazení (D56)

`AssetEventDocument`: když potvrzené `disposal` opouští stav 40, smazat
(90) systémové odpisy (`origin = system`) téže karty s `event_date` =
datum vyřazení, které vyřazení založilo. Je-li některý zaúčtovaný,
přechod odmítnout (`_form`, kód `disposalPosted`: „Nejdřív zruš
zaúčtování období“). Aktualizovat `help/majetek/odpisy-majetku.md`
(odstavec o zrušeném vyřazení).

## 2. Dimenze deníku (D47)

Obecné, v `economy.accounting` / `docs.core`, bez znalosti majetku:

- Klíč `journalDimensions` v `module.jsonc`:
  `[{"id": "asset", "rowColumn": "asset", "headColumn": null,
  "journalColumn": "asset", "table": "economy_assets_assets",
  "name:cs": "Majetek", "name:en": "Asset"}]`. Parser v
  `ModuleDefinition`, loader ve vzoru `JournalContributorLoader`
  (`JournalDimensionSet`).
- Sloupce zakládá modul dimenze přes **extensions**: `economy.assets`
  → `docs_core_rows.asset` a `economy_accounting_journal.asset` (int,
  null, reference `economy_assets_assets`, index na deníku).
- `AccountingEngine`: `loadRows` načte sloupce dimenzí; `makeLine` je
  zkopíruje z řádku (prázdné → z `headColumn` hlavičky, je-li); klíč
  `groupLines` je obsahuje; `writeResult` je zapíše. Contributoři
  dimenze nenastavují (`null`). Bankovní engine beze změny (dimenze
  `null`).
- `JournalViewer` a tab Zaúčtování dokladu: sloupec per aktivní dimenze
  (label z definice, hodnota přes `displayPattern` cílové tabulky);
  filtr deníku podle dimenze.
- Testy: engine s dimenzí (kopie, seskupení dvou karet zvlášť, fallback
  z hlavičky), engine bez dimenzí beze změny výsledku.
- `docs/accounting.md` nová podkapitola „Dimenze deníku“.

## 3. Operace `asset.*` (D48)

`rowOperations.jsonc`: `asset.activation` (Zařazení majetku),
`asset.improvement` (Technické zhodnocení), `asset.reduction` (Snížení
hodnoty majetku), `asset.depreciation` (Odpis majetku),
`asset.disposal` (Vyřazení majetku) — `docTypes.cmnbkp`, `rowSide: 1`,
`rowAccount: "direct"`, nové vlajky `rowAsset: 1` a `system: 1`.

- `DocRowOperationRules`: `rowAsset` → `asset` povinné (kód
  `asset_required`); `system` → řádek s takovou operací smí uložit jen
  služba majetku (marker `_systemOperations` v datech, který služba
  nastaví; jinak kód `system_operation`).
- `AccountingDocsForm` (a nabídka operací obecně): operace se `system`
  nenabízí; existující řádky se zobrazí read-only.
- Předpis CZ `cmnbkp`: kroky `{"src": "rows", "accountSrc": "row",
  "sideSrc": "row", "operation": "asset.*"}` per operace (vzor
  `acc.record`).
- `docs/accounting.md` §2 tabulka vlajek doplnit o `rowAsset`, `system`.

## 4. Sestavení řádků (D49)

`AssetPostingBuilder` (čistá třída, bez DB): vstup = karta (účetní
skupina s čísly účtů), potvrzené nezaúčtované události účetního okruhu
v období (`both` a `acc`), plán účetního okruhu k datu vyřazení; výstup
= řádky dokladu `{operation, asset, account, acc_side, amount,
description}`.

| Událost | Řádky |
|---|---|
| `activation`, `improvement` | MD `account_asset` / DAL `account_acquisition`, částka události |
| `reduction` | MD `account_acquisition` / DAL `account_asset` |
| `depreciation` (okruh `acc`) | MD `account_depreciation` / DAL `account_accumulated` |
| `disposal`, odepisovaný | MD `account_accumulated` / DAL `account_asset` ve výši oprávek k vyřazení; MD `account_disposal` / DAL `account_asset` ve výši zůstatkové ceny (nulové řádky vynechat) |
| `disposal`, neodepisovaný | MD `account_disposal` / DAL `account_asset` vstupní cenou |
| `opening`, okruh `tax`, `interruption` | žádné řádky |

Oprávky a zůstatková cena k vyřazení z plánu účetního okruhu **po**
posledních odpisech (ty jsou v témže dokladu před řádky vyřazení —
pořadí řádků: zařazení, TZ, snížení, odpisy, vyřazení; v rámci druhu
podle inv. čísla). Popis řádku: `"{druh} {inv. č.} {název}"`, odpis
s obdobím. Chybějící účet skupiny = chyba karty (ne řádek s dírou).
Testy: všechny řádky tabulky, vyřazení po TZ, vyřazení s počátečním
stavem (oprávky z `opening` + odpisy), nulová zůstatková cena.

## 5. Běh a zrušení (D50–D55)

### Řada (D54)

- Nastavení `economy.assets.accountingSeries` (id řady `cmnbkp`) na
  stránce Odpisy. Select potřebuje dynamickou nabídku → rozšířit field
  typ `select` o `optionsProvider` (FQCN třídy s `options(): list<{value,
  label}>`, whitelist v `ModuleDefinition`); `docs/app-settings.md`.
- Provisioner (vzor `NumberSeriesProvisioner`, jen bez
  `skipProvisioning`): řada `cmnbkp` „Majetek“, pokud neexistuje,
  a nastavení na ni, pokud je prázdné.
- Bez platné řady → běh odmítne (`series_missing`), žádný tichý výběr.

### `AssetPostingService`

- `preview(periodId)` — účetní okruh: kandidáti odpisů z
  `DepreciationRunService` **a** potvrzené nezaúčtované události účetního
  okruhu s `event_date` v období; řádky dokladu z builderu, součty MD/DAL
  per účet, vyloučené karty (chybějící účet `accounting_account_missing`,
  chyba plánu, nezaúčtované dřívější období `earlierPeriodUnposted`,
  zamčený měsíc).
- `post(periodId)` — v **jedné transakci**: (1) odpisy jako dnešní běh
  (`SystemDepreciationWriter`); (2) builder; (3) `cmnbkp` přes
  `TransactionlessTableGateway` + dispatcher (handler účtování vygeneruje
  deník): `doc_type = cmnbkp`, řada z D54, `accounting_date` = konec
  období, `docState` 40, `_systemOperations`; (4) `doc_head` u všech
  zaúčtovaných událostí. Chyba kdekoli = rollback. Idempotence: událost
  s `doc_head` se znovu neúčtuje; období bez kandidátů = žádný doklad.
  Účtování dokladu skončí chybou (`accounting_state = 2`) → rollback
  s hlášením.
- `unpost(periodId)` — jen poslední zaúčtované období a nezamčený
  měsíc: storno dokladu (30) přes gateway s výjimkou zámku (níže),
  `doc_head = NULL` u jeho událostí, systémové odpisy toho běhu zůstávají
  potvrzené (lze je smazat od konce). Uvnitř transakce.
- Daňový běh (`DepreciationRunService`, `scope = tax`) beze změny.

### Události

- `economy_assets_events.doc_head` (int, null, reference
  `docs_core_heads`, index).
- `AssetEventLockProvider`: zaúčtovaná událost (navázaný doklad mimo
  30 / 90) je zamčená — důvod „Zaúčtováno dokladem {číslo}“.
- `AssetPlanService` / `PlanRow`: příznak zaúčtování a číslo dokladu
  pro řádky událostí.

### Doklad (D53)

`AssetPostingDocLockProvider` na `docs_core_heads`: doklad s řádkem
`asset.*` je zamčený pro ruční úpravy i přechody (důvod „Doklad spravuje
Majetek — zaúčtování se ruší z majetku“). Služba ho obchází přes
`Document::isLockExempt()` s markerem `_assetsService` (vzor importu
`_importNumber`) — jen pro `post` / `unpost`.

### HTTP

`AssetsDepreciationController` rozšířit (routy v `Router.php`,
`ReadOnlyPolicy`): `GET /_assets/posting/preview?period=` (Allow),
`POST /_assets/posting` (403), `POST /_assets/posting/cancel` (403).
Účetní volba v dialogu volá posting místo dnešního `run` se `scope=acc`
(ten zůstává pro daňový okruh).

## 6. UI

- `AssetsDepreciationRunDialog.svelte`: účetní okruh = „Odpisy
  a zaúčtování za období“ — náhled ve dvou částech (nové odpisy, události
  k zaúčtování) + souhrn účtů MD/DAL; tlačítko Zaúčtovat; po úspěchu
  číslo dokladu s odkazem (`open_detail` na doklad). Akce „Zrušit
  zaúčtování období“ (potvrzovací dialog) u posledního zaúčtovaného
  období.
- Karta: v plánu účetního okruhu u potvrzených řádků „Zaúčtováno —
  doklad …“ nebo „Čeká na zaúčtování“; viewer: badge „Nezaúčtováno“
  u karet s potvrzenými nezaúčtovanými událostmi.
- Účetní doklad majetku: sloupec Majetek v řádcích a v tabu Zaúčtování,
  zámek se zobrazí standardně (`lock.reasons`).
- i18n (`cs.js` / `en.js`, `npm run check:i18n`).

## 7. Dokumentace

- `help/majetek/odpisy-majetku.md` — zaúčtování za období, co se kam
  účtuje (tabulka D49 lidsky), zrušení zaúčtování, řada dokladů;
  `help/co-dnes-nejde.md` — odstranit „odpisy se neúčtují“, ponechat
  import a vazbu na faktury; `python3 scripts/help-index.py`.
- `docs/assets.md` §5.4 (zaúčtování), §7 oblast 3 → hotovo, odchylky.
- `docs/accounting.md` (dimenze, vlajky, kroky předpisu).

## Testy

- `AssetEventDocumentTest` — D56.
- `AccountingEngine` s dimenzí (§2).
- `DocRowOperationRulesTest` — `rowAsset`, `system`.
- `AssetPostingBuilderTest` — tabulka §4.
- `AssetPostingServiceTest` — náhled a vyloučení, zaúčtování (doklad,
  `doc_head`, idempotence), díra v obdobích, chybějící řada, rollback
  při chybě účtování, zrušení (jen poslední období).
- lock providery (událost, doklad, výjimka služby).
- `ReadOnlyPolicyTest`, `ModuleDefinition` (`journalDimensions`,
  `optionsProvider`).
- `vendor/bin/phpunit --filter 'Assets|AccountingEngine|DocRowOperation|ReadOnlyPolicy|ModuleDefinition'`,
  pak celá sada; `cd frontend && npm run build && npm run check:i18n`.

## Task breakdown

1. **D56** — zrušené vyřazení + test + help.
2. **Dimenze deníku** — `journalDimensions`, loader, engine, extensions
   `asset`, viewer deníku a tab Zaúčtování + testy + `docs/accounting.md`.
3. **Operace `asset.*`** — rowOperations, vlajky, pravidla, form, předpis
   + testy.
4. **Builder** — `AssetPostingBuilder` + testy.
5. **Služba a HTTP** — řada (nastavení, `optionsProvider`, provisioner),
   `doc_head`, `AssetPostingService`, lock providery, controller, routy
   + testy.
6. **Frontend** — dialog, stav zaúčtování na kartě a ve vieweru, i18n.
7. **Dokumentace** — help, `docs/assets.md`, Stav tasku
   + `python3 scripts/tasks-index.py`.

*Hotovo celé když* na ukázkovém DS (`4l3j-z0bz-kz39-echj`): karta DHM →
zařazení (MD 022 / DAL 042) a účetní odpis se zaúčtují jedním dokladem
za období; deník nese dimenzi `asset`; druhé zaúčtování téhož období nic
nezaloží; ruční oprava dokladu je zamčená; zrušení zaúčtování doklad
stornuje a události odpojí; vyřazení zaúčtuje oprávky a zůstatkovou cenu
čistým zápisem; součet MD odpisů karty v deníku = součet potvrzených
účetních odpisů.

## Odchylky při implementaci

Potvrzené před implementací (podrobně `docs/assets.md` §5.4):

- **Commit navíc (0/8): vnořené transakce.** `AccountingEngine`,
  `BankTransactionAccountingEngine` a `LedgerGenerator` zapisují přes
  `NestedTransaction` — bez toho by transakci `post()` engine tiše commitnul.
- **Zámek dokladu:** výjimka pro službu je v `AssetPostingDocLockProvider`
  (marker `_systemOperations`), ne v `isLockExempt()`; marker `_assetsService`
  nevznikl. Zámek fiskálního měsíce tak platí i pro `post` / `unpost`.
- **Dimenze deníku:** místo loaderu kompilace do cfgItem
  `core.accounting.journalDimensions`.
- **`doc_head`** vznikl už v commitu 1 (D56 ho potřebuje).

Nálezy z implementace:

- **Řada „Majetek“ × přiznání DPH:** `ds-upgrade` při založení řady
  zafixuje dosavadní jedinou řadu `cmnbkp` do
  `economy.vat.filingAccountingSeries` — jinak by zaúčtování přiznání DPH
  začalo vyžadovat ruční volbu řady.
- **Účetní odpis jedné karty** (detail → Odepsat) se dál jen potvrzuje;
  dávka zaúčtování je vždy nad všemi kartami.
- **Uživatelská stránka** `help/majetek/zauctovani-majetku.md` je
  samostatná (stránka odpisů by přerostla jednu úlohu).
- `AccountingDocument` vyžaduje účet u každé operace s `rowAccount: direct`
  (dřív jen `acc.record`); uložený systémový řádek nejde přepsat na jinou
  operaci.

## Rozhodnutí k designu (potvrzená)

- ✓ D47 Obecné dimenze deníku, první `asset`.
- ✓ D48 Operace `asset.*` s vlajkami `rowAsset` a `system`.
- ✓ D49 Účty ze skupiny; snížení = obrácené zařazení; vyřazení čistým
  zápisem.
- ✓ D50 Dávka za období; okamžité zařazení až jako budoucí nastavení.
- ✓ D51 Doklad rovnou V pořádku.
- ✓ D52 `doc_head` na události, zaúčtovaná událost zamčená.
- ✓ D53 Doklad spravuje majetek; zrušení zaúčtování jen od posledního
  období.
- ✓ D54 Řada z nastavení, provisioner „Majetek“, bez tichého výběru.
- ✓ D55 Kontroly v náhledu, zaúčtování bez děr.
- ✓ D56 Zrušené vyřazení maže svoje systémové odpisy.
