# Majetek Fáze 6 — import: applier, karta na dokladech, ověření

**Stav:** naplánováno — D73–D82 potvrzena 2026-10-06; runner ve starém Shipardu navazuje

> PRD pro jednu Claude Code session (6 commitů). Design: `docs/assets.md`
> §1 (invarianty), §4 D8–D17 a D73–D82, §5, §6 (kontrakt importu);
> `docs/exchange-format.md`; issue #83.

## Kontext

Migrované zdroje mají v novém Shipardu doklady i deník, ale ne majetek:
karty chybí a majetkové řádky dokladů (pořízení na fakturách, zařazení,
odpisy a vyřazení na účetních dokladech) nesou účty bez karty. Účetní
odpisy přitom v deníku už jsou — import majetkových účetních dokladů je
rozpadl na dvojice `acc.record` s účty ze starého deníku a 551 sedí se
starým systémem na korunu (D73–D82, rozbor v `docs/assets.md`).

Fáze 6 v novém Shipardu připraví všechno, co runner ve starém Shipardu
potřebuje: výměnný formát karty s historií, doplnění karty na už
importované doklady a ověření výsledku. Runner (čtení starých dat,
mapování, účetní okruh podle deníku — D77) je samostatný task ve starém
Shipardu a pouští se až po této fázi.

## Cíl

1. Rozdíl na účtu pořízení v Kontrole evidence × deník jen varováním (D73).
2. Importované události jsou zaúčtované mimo modul (D76).
3. Výměnný formát `shpd.assets.asset.v1` + applier (D74, D75, D79).
4. Doplnění karty na importované doklady (D80).
5. CLI `assets-import-verify` (D82).
6. Dokumentace.

## Před implementací přečti

- `docs/assets.md` §1, §4 (D3, D8–D17, D49, D52–D58, D73–D82), §5.3–5.6, §6
- `docs/exchange-format.md` §11 (REST), §14 (Uživatelé — nejbližší vzor:
  malý formát, jen `validate` + `apply`, admin nebo API klíč)
- `modules/core/exchange/src/User/UserApplier.php`,
  `modules/core/exchange/schemas/shpd.system.user.v1.jsonc`,
  `src/Api/Controller/ExchangeController.php`, `dispatchExchange`
  v `public/index.php`
- `modules/economy/assets/src/AssetDocument.php` (číslo karty,
  validace D57), `AssetEventDocument.php` (původ `import`, D10, D58),
  `Depreciation/AssetEvent.php`, `Depreciation/CircuitWalker.php`
- `modules/economy/assets/src/Posting/AssetPostingService.php`
  (`loadUnpostedEvents`, `earlierPeriodUnposted`), `AssetPlanService.php`
  (`postingOf`), `AssetJournalCheck.php`, `Reports/JournalCheckBuilder.php`,
  `Checks/JournalMismatchCheck.php`
- `src/Command/DataSource/DocReaccountCommand.php` (přeúčtování dokladu,
  obejití zámků `--force` se zalogováním), `docs/accounting.md` §6
  (dimenze deníku), §7.6

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** runner ve starém Shipardu (čtení starých tabulek, mapa ID,
účetní okruh z deníku, číselníky přes CRUD, přílohy přes obecné API —
navazující task); pohyby, příslušenství, vlastnosti, štítky (D74);
událost počátečního stavu z importu (D17); reimport reálných zdrojů.

## 1. Účet pořízení jen varováním (D73)

`JournalCheckBuilder`: u účtu s rolí pořízení se rozdíl evidence × deník
nehlásí jako `assets.journalCheck.accountMismatch` (chyba), ale jako
varování `assets.journalCheck.acquisitionAccountDifference` s textem,
že jde o pořízení bez karty nebo zařazení bez navázaného pořízení.
Řádek reportu i sloupce zůstávají; ostatní role beze změny. Test:
rozdíl jen na 04x → stav reportu `warnings`.

## 2. Importované události jsou zaúčtované mimo modul (D76)

Jedno místo pravdy „událost je zaúčtovaná“: živý `doc_head` **nebo**
původ `import`. Použít všude, kde se dnes ptá jen na `doc_head`:

- `AssetPostingService` — kandidáti náhledu a zaúčtování, důvod
  `earlierPeriodUnposted`: importovaná událost kandidát není a nic
  neblokuje;
- `AssetJournalCheck` — evidence kontroly po účtech importované události
  **započítá**; per karta (a) zaúčtování ≠ deník a (c) nezaúčtovaná
  událost je **neposuzují** (jejich doklady mají jiné zápisy než D49);
  (b) pořízení × zařazení beze změny;
- `AssetPlanService::postingOf` a UI — plán karty ukazuje u importovaných
  „Zaúčtováno ve starém systému“ (bez odkazu na doklad), seznam karet
  nedává badge „Nezaúčtováno“;
- `JournalMismatchCheck`, `AcquisitionMismatchCheck` — přes
  `AssetJournalCheck`, ověřit testem.

`doc_head` importované události zůstává prázdný; zrušení zaúčtování se jí
tím netýká. Testy: karta s importovanou historií do roku N a odpisem roku
N+1 — náhled zaúčtování za N+1 nabídne jen N+1; kontrola po účtech
s importovanými událostmi a deníkem s dimenzí karty bez rozdílu.

## 3. Formát `shpd.assets.asset.v1` + applier (D74, D75, D79)

Schema `modules/core/exchange/schemas/shpd.assets.asset.v1.jsonc` (+ `.json`
jako ostatní), validator a applier v `modules/core/exchange/src/Asset/`
(nebo v modulu majetku — rozhodni podle toho, kde se líp drží závislosti,
a zapiš do docs). REST `POST /api/v1/_exchange/assets/asset/{validate,apply}`,
oprávnění jako import uživatelů. Jeden request = jedna karta.

Payload (ID číselníků a osob jsou **nová** ID — mapu drží runner):

```jsonc
{
  "format": "shpd.assets.asset.v1",
  "asset": {
    "assetNumber": "…",            // klíč, převezme se beze změny
    "name": "…", "shortName": "…", "note": "…",
    "type": 12,                    // economy_assets_types.id | null
    "category": "tangible",        // small | tangible | intangible | nondepreciable
    "tracking": "single",          // single | set | quantity
    "accountingGroup": 3,          // id | null
    "foreign": false, "owner": null,
    "acquiredDate": "2019-05-01", "disposedDate": null,
    "price": null,                 // jen drobný majetek (D13)
    "taxMethod": "straight", "taxRule": "…", "accMethod": "time", "accMonths": 60,
    "state": "confirmed"           // confirmed | archived
  },
  "events": [
    {
      "kind": "depreciation",      // activation | improvement | reduction |
                                   // depreciation | interruption | disposal
      "scope": "tax",              // both | tax | acc
      "date": "2019-12-31", "periodBegin": "2019-01-01", "periodEnd": "2019-12-31",
      "amount": 12345.00,
      "claimUnrecorded": false, "halfYear": false, "priceIncreased": false,
      "note": "…",
      "sourceRef": "deps:4711"     // jen pro výpis chyb, neukládá se
    }
  ]
}
```

Pravidla:

- **Párování** podle `assetNumber`. Nová karta → založit; existující →
  přepsat hlavičku a nahradit všechny její události původu `import`.
  Má-li karta ruční nebo systémové události, applier ji **přeskočí**
  (`skipped`, varování `asset_has_local_events`) — nic nemění.
- Karta se ukládá přes `AssetDocument` s daným číslem (bez přidělení);
  události přes `AssetEventDocument` s původem `import`, stav potvrzeno,
  chronologicky. Celá karta v jedné transakci.
- Cílový stav `confirmed` → V pořádku, `archived` → V archívu. Neprojde-li
  karta validací úplné účetní skupiny (D57) nebo nemá skupinu → uloží se
  jako **koncept** s varováním `accounting_group_incomplete` (D79).
- Validace událostí, které historická data splnit nemohou, platí pro
  původ `import` volněji (celé koruny a existence účetního roku už
  dnes). Pokud narazíš na další (pořadí, přerušení u účetního okruhu,
  částka nad zůstatkem…), uvolni je jen pro `import` a vyjmenuj
  v `docs/assets.md` §5.7.
- Chyba plánu po uložení (`assets.planError`) importu nebrání — vrátí se
  jako varování s textem zprávy plánu; karta ji ukazuje jako dnes.
- Odpověď: `{status: created | updated | skipped, assetId, warnings[]}`;
  validační chyby (`validate` i `apply`) s cestou do payloadu a `sourceRef`.
- Opakovaný `apply` téhož payloadu nic nezmění (kromě `updated`).

Testy: založení, opakování, karta s místní událostí přeskočena, koncept
bez skupiny, necelé koruny a události před prvním účetním rokem projdou,
vyřazená karta → V archívu.

## 4. Karta na dokladech (D80)

REST `POST /api/v1/_exchange/assets/doc-links/apply`, jeden request =
jeden doklad (nové ID — mapu drží runner):

```jsonc
{
  "docId": 4711,
  "headAsset": null,               // id karty pro hlavičku | null
  "rows": [
    { "account": "551022", "side": "dr", "amount": 13074.00,
      "asset": 15, "orderHint": 3, "sourceRef": "row:812" }
  ]
}
```

- **Párování řádků:** kandidáti = řádky dokladu se stejným číslem účtu,
  `vat_base_dom` = `amount` (na haléř) a stranou, je-li uvedená
  (účetní doklady; u faktur a pokladních dokladů strana chybí). Jeden
  kandidát → shoda; víc → rozhodne `orderHint` (`order_pos`), jinak
  `ambiguous`. Každý řádek dokladu se spáruje nejvýš jednou.
- Řádek, který už nese **jinou** kartu → `conflict`; stejnou → beze změny.
- Doklad se mění **celý, nebo vůbec** (transakce): nastaví `asset` na
  řádcích a hlavičce, u dokladu ve stavu 40 přegeneruje deník
  (`AccountingEngine::accountDocument`) přes zámky měsíce a DPH stejně
  jako `doc-reaccount --force`, se záznamem `warn` do logu (důvod
  „assets backfill“). **Pojistka:** součty MD / DAL po účtech dokladu před
  a po přegenerování se musí shodovat, jinak rollback a `turnover_changed`.
- Doklad mimo stav 40 → jen sloupce, bez přegenerování.
- Funguje bez ohledu na nastavení Sledovat náklady na majetek.
- Odpověď: `{status: linked | unchanged | ambiguous | notFound | conflict |
  turnover_changed, rows: [{sourceRef, rowId | null, status}]}`.

Testy: účetní doklad se dvojicemi 551/08x, dva řádky se stejnou částkou
(rozhodne `orderHint`), faktura s hlavičkou, zamčený měsíc (projde,
zaloguje), nenalezený řádek → doklad beze změny, deník po doplnění nese
dimenzi a obraty se nezměnily.

## 5. CLI `assets-import-verify` (D82)

`shpd-ds assets-import-verify [--asset=<číslo>] [--json]` — čte, nic
nemění:

1. **Zlatý test daňového okruhu (D6):** pro každý importovaný daňový
   odpis roku N spočítá engine odpis z historie před rokem N a porovná
   (přerušené roky a `claimUnrecorded` podle pravidel enginu). Výstup:
   karta, rok, importováno, engine, rozdíl.
2. **Účetní okruh × deník:** per karta a účetní rok součet účetních
   odpisů z událostí × MD účtu odpisů s dimenzí karty v deníku.
3. **Kontrola evidence × deník** (`AssetJournalCheck`) za každý účetní rok
   od prvního s dimenzí `asset` v deníku: stav a počty zpráv po kódech.
4. Souhrn; exit 0 bez rozdílů, 1 s rozdíly. `--json` pro log runneru.

Test na fake datech (rozdíl v roce, čistý stav).

## 6. Dokumentace

- `docs/assets.md` §5.7 Import — formáty, pravidla, uvolněné validace,
  odchylky; §6 doplnit endpointy; stav v hlavičce a §7
- `docs/exchange-format.md` §11 (dispatcher) a §14 — `shpd.assets.asset.v1`
  a doc-links
- `docs/cli.md` — `assets-import-verify`
- tento task: Stav

## Hotovo když

- [ ] Kontrola evidence × deník hlásí rozdíl na 04x jako varování (D73).
- [ ] Importované události nejsou kandidáty zaúčtování, neblokují další
      období a kontrola po účtech je počítá (D76).
- [ ] `validate` / `apply` karty funguje podle pravidel §3, opakování je
      idempotentní, karta s místní událostí se přeskočí.
- [ ] Doplnění karty na doklad podle §4 včetně pojistky obratů a zámků.
- [ ] `assets-import-verify` na ukázkovém DS (`4l3j-z0bz-kz39-echj`)
      po ručním `apply` dvou karet (dlouhodobá s historií od 2015
      a vyřazená) a doplnění karty na jeden doklad.
- [ ] PHPUnit (úzké filtry) zelené, dokumentace aktualizovaná.

## Doporučené pořadí commitů

1. D73 — varování na účtu pořízení.
2. D76 — zaúčtováno mimo modul (posting, kontrola, UI).
3. Schema + validator + applier karty, REST.
4. Doplnění karty na doklady, REST.
5. `assets-import-verify`.
6. Dokumentace, stav tasku.
