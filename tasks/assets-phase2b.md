# Majetek Fáze 2b — události, odpisové nastavení karty, plán a odpisy za období

**Stav:** naplánováno — D27–D46 potvrzena 2026-09-30; prerekvizita `tasks/assets-phase2a.md`

> PRD pro jednu Claude Code session (8 commitů). Design: `docs/assets.md`
> §4 (D3, D7, D11, D12, D13, D16, D27–D46), issue #83.

## Kontext

Fáze 2a dodala čistý výpočet (`world.assets` + `DepreciationPlanner`).
Tahle fáze ho napojí na data: karta dostane odpisové nastavení, vznikne
tabulka **událostí** (hodnotová historie, D3), uživatel na kartě vidí plán
daňových a účetních odpisů, zadává události (zařazení, TZ, snížení,
přerušení, vyřazení, počáteční stav) a akcí **„Odpisy za období“** hromadně
potvrzuje odpisy. **Nic se neúčtuje** (D27) — zaúčtování přidá fáze 3.

## Cíl

0. Oprava enginu z fáze 2a: krátké zdaňovací období (D46).
1. Tabulka `economy_assets_events` (454) + cfgItem druhů, okruhů a původů.
2. Odpisové nastavení karty (sloupce + validace + tab Odpisy).
3. `AssetEventDocument` s pravidly per druh, `AssetEventLockProvider`
   (append-from-end + zámek měsíce, D29), efekty na kartu (D38).
4. Vyřazení s posledními odpisy v jedné transakci (D35).
5. Detail karty: taby Daňové a Účetní odpisy (plán z enginu), akce
   detailu pro zadání událostí.
6. „Odpisy za období“: endpoint náhled + provedení, dialog ve vieweru.
7. Nastavení → Majetek: četnost účetních odpisů (D12) — přidat field typ
   `select` do settings pages.
8. Popisky viewerů majetku z cfgItem (dluh z fáze 1).
9. Uživatelská dokumentace.

## Návaznost

- **Prerekvizita:** `tasks/assets-phase2a.md` hotový.
- **Odemyká:** fázi 3 (zaúčtování — přidá stav „zaúčtováno“ a vazbu
  událost → doklad) a fázi 6 (import událostí ze starého Shipardu).
- Fáze 2 a 3 jdou do ostrého provozu společně (D27).

## Před implementací přečti

- `docs/assets.md` §4 (D3, D11, D16, D27–D45), §5
- `tasks/assets-phase2a.md` (API enginu a pravidel)
- `modules/economy/assets/` celé (fáze 1: `AssetDocument`, `AssetsForm`,
  `AssetsViewer`, `AssetCategories`, `module.jsonc`)
- `docs/document-system.md` §16 (lock providery), vzor
  `modules/economy/codebooks/src/FiscalMonthLockProvider.php`
- `docs/edit-forms.md` kap. 15 (sub-tabulky), kap. 22 (lookup)
- `docs/frontend.md` — Akce detailu (`detail.actions`, `kind: open_form`
  s `target.preset`), Spodní taby, vzor vlastní akce vieweru
  `import_statement` v `Viewer.svelte`
- `src/Api/Router.php` + `src/Api/ReadOnlyPolicy.php` (registrace nové
  routy, fail-closed tabulka), vzor `/_bank/reaccount`
- `docs/app-settings.md` §„Jak přidat stránku“ (field typy — `select`
  zatím neexistuje)

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** zaúčtování a stav „zaúčtováno“ (fáze 3); vazba na pořizovací
doklad (fáze 4); přehledy a tisk (fáze 5); import událostí (fáze 6);
odložená daň, AV/AM, skupina X (fáze 8).

## Oprava enginu — krátké zdaňovací období (D46)

Fáze 2a roční daňový odpis v účetním roce kratším než 12 měsíců nekrátí
(`docs/assets.md` §5.2 — „krátký účetní rok se nekrátí“). Správně
(§26 odst. 7 písm. a) bod 3 ZDP): zdaňovací období **kratší než
12 měsíců** → **polovina ročního odpisu** (i za jediný měsíc), delší →
plný roční odpis.

- `assets-cz.jsonc`: `"shortPeriodHalfYear": ["straight", "accelerated"]`
  s komentářem a odkazem na ustanovení.
- `TaxDepreciationRules`: `allowsShortPeriodHalfYear(string $method): bool`
  (`AccountingOnlyTaxRules` → false); `TaxYearInput` dostane příznak
  `shortPeriod`, `CzTaxDepreciationRules::annualAmount()` ho zpracuje
  jako polovinu (vzorec `"(…) / 2"`, jako vyřazení). Krátké období
  a vyřazení současně = jedna polovina, ne čtvrtina.
- `DepreciationPlanner` / `PeriodCalendar`: délka období v měsících (i
  u extrapolovaných); krátké období = méně než 12 celých měsíců.
- Krátké období se počítá jako uplatněný rok (n + 1) — ověřit v testu
  a okomentovat.
- Testy: rovnoměrný sk. 2 s přechodným obdobím 1–9/2024 → polovina;
  období 15 měsíců → plný odpis; vyřazení v krátkém období → polovina;
  zrychlený v krátkém období.
- `docs/assets.md` §5.2 opravit.

## Datový model

### `economy_assets_events` (454)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `id` | int PK AI | |
| `asset` | int → `economy_assets_assets`, not null | |
| `event_kind` | enumString(16), cfgItem `economy.assets.eventKinds` | `opening`, `activation`, `improvement`, `reduction`, `depreciation`, `interruption`, `disposal` |
| `scope` | enumString(4), cfgItem `economy.assets.eventScopes` | `both`, `tax`, `acc` |
| `event_date` | date, not null | účetní datum |
| `period_begin`, `period_end` | date, null | u odpisu a přerušení |
| `amount` | numeric(15,2), not null, default 0 | změna VC / odpis; u počátečního stavu VC |
| `accumulated` | numeric(15,2), null | jen `opening` — oprávky |
| `units_done` | smallint, null | jen `opening` — roky (roční metody) nebo měsíce |
| `price_increased` | boolean, default 0 | jen `opening` |
| `original_date` | date, null | jen `opening` — datum původního zařazení |
| `half_year` | boolean, default 0 | `disposal`: uplatnit polovinu; `depreciation`: odpis roku vyřazení |
| `claim_unrecorded` | boolean, default 0 | D11 — jen import |
| `origin` | enumString(8), cfgItem `economy.assets.eventOrigins` | `manual`, `system`, `import` |
| `note` | varchar(250), null | |
| `docState`, `docStateMain` | | cfgItem `economy.assets.eventStates` (10, 40, 80, 90 — bez archivu) |

Indexy: `idx_asset_scope_date` (`asset`, `scope`, `event_date`, `id`),
`idx_doc_state` (`docStateMain`). `hideFromNavigation: true` — události
se spravují jen z karty.

### Karta `economy_assets_assets` — nové sloupce (skupina `depreciation`)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `tax_method` | enumString(16), null | kódy metod z pravidel země |
| `tax_rule` | varchar(24), null | kód skupiny / časového / mimořádného pravidla (`cz-2`, `cz-30a-2`, `cz-nim-software`) |
| `acc_method` | enumString(16), null, cfgItem `economy.assets.accMethods` | `as_tax`, `time` |
| `acc_months` | smallint, null | délka účetního odpisování (`time`) |

`economy.assets.categories`: přidat příznak `intangible` (true u
`intangible`) — pravidla země podle něj omezí metody (NIM od 2021).

## Pravidla

### Karta (`AssetDocument` — rozšíření)

- Druh s `depreciable` → `tax_method`, `acc_method` povinné; metoda musí
  být v `TaxDepreciationRules::availableMethods(datum zařazení, intangible)`
  (bez zařazení: dnešní datum); `tax_rule` povinné u `straight`,
  `accelerated`, `time`, `extraordinary` a musí být v `rules()`;
  `acc_method = time` → `acc_months` > 0. Kombinace, které engine
  neumí spočítat (hlásí `settingsInvalid`): `acc_method = as_tax`
  s daňovou metodou `accounting` nebo `none`; daňová `accounting` bez
  `acc_method = time`.
- Druh bez `depreciable` → všechna čtyři pole se vyprázdní v `beforeSave()`.
- Po prvním potvrzeném daňovém odpisu nelze změnit `tax_method` ani
  `tax_rule` (chyba na poli, kód `taxMethodLocked`). Účetní metodu změnit
  lze — plán se přepočítá od posledního potvrzeného odpisu.
- U dlouhodobého druhu jsou `acquired_date` a `disposed_date` jen ke
  čtení (D38): plní je události, ruční hodnota z payloadu se ignoruje.

### Události (`AssetEventDocument`)

Společné: karta musí být dlouhodobá; potvrdit (40) lze jen událost karty
ve stavu V pořádku; žádná událost po potvrzeném vyřazení.

| Druh | Okruh | Pravidla |
|---|---|---|
| `activation` | `both` | jediné zařazení na kartě, žádný `opening`; `amount` > 0 |
| `opening` (D16) | `tax` nebo `acc` | max. jeden per okruh, bez `activation`; `event_date` = první den účetního roku; `amount` > 0, 0 ≤ `accumulated` ≤ `amount`, `units_done` ≥ 0, `original_date` povinné |
| `improvement`, `reduction` | `both` | po zařazení / počátečním stavu, `amount` > 0; TZ na kartě s mimořádnou metodou zakázané (D41, kód `improvementOnSchedule`); snížení nejvýš do zůstatkové ceny obou okruhů (engine to nehlídá) |
| `depreciation` | `tax` nebo `acc` | jen dlouhodobý odepisovaný druh; `period_begin`/`period_end` povinné, `event_date` ve stejném účetním roce jako `period_end`; `amount` ≥ 0, celé koruny kromě `origin = import` (D10); ne nad zůstatek (engine) |
| `interruption` | `tax` | účetní rok; metoda karty přerušitelná (D34); ne v roce, kde je potvrzený daňový odpis |
| `disposal` | `both` | datum ≥ zařazení; `half_year` jen pokud pravidla dovolují a majetek byl v evidenci k začátku roku |

Neodepisovaný dlouhodobý druh smí mít `activation`, `improvement`,
`reduction`, `disposal` (vývoj hodnoty), ne odpisy a přerušení.

### Zámek (`AssetEventLockProvider`, D29)

Potvrzená událost je zamčená, když (a) po ní existuje potvrzený
`depreciation` téže karty ve stejném okruhu (`both` = oba okruhy), nebo
(b) její `event_date` spadá do zamčeného účetního měsíce. Důvody jako
`lock.reasons`, vzor `FiscalMonthLockProvider`. Registrace
`documentLockProviders`.

### Efekty na kartu (D38)

V `afterPersist()` události (uvnitř transakce):

- potvrzené `activation` → karta `acquired_date` = `event_date`;
  potvrzený `opening` → `acquired_date` = `original_date`;
- potvrzený `disposal` → nejdřív poslední odpisy (níže), pak karta
  `disposed_date` = `event_date`, `docState` 70 / `docStateMain` 4
  (přímý UPDATE v téže transakci, systémový přechod — okomentovat);
- smazání (90) potvrzeného `disposal` → karta 80, `disposed_date` null;
  smazání zařazení → `acquired_date` null.

### Poslední odpisy při vyřazení (D35)

Při potvrzení `disposal` zavolat planner s vyřazením a založit
chybějící odpisy (`origin = system`, stav 40): daňový za rok vyřazení
(polovina dle `half_year`, jinak žádný) a účetní do měsíce vyřazení.
Existující potvrzené odpisy nepřepisovat.

## Plán na kartě

`AssetsViewer::renderDetail()` — taby **Přehled** (stávající), **Daňové
odpisy**, **Účetní odpisy** (jen odepisovaný druh). Služba
`AssetPlanService` načte kartu, potvrzené události, účetní roky a měsíce
(`economy_codebooks_fiscal_years` / `_months`), pravidla země
(`TaxRulesRegistry::forCountry($dsConfig->getCountry())`) a zavolá planner.

Tab = tabulka (`type: table`): Období, Druh, Stav (Potvrzeno / Plán),
Výpočet (vzorec), Odpis, Oprávky, Zůstatek; plánované řádky tlumeně.
Nad tabulkou souhrn (metoda, pravidlo, vstupní cena, oprávky, zůstatek,
letošní odpis); pod ní panel hlášení (`PlanMessage`), chyby červeně.
Karta bez zařazení: text „Majetek není zařazen“ + akce Zařadit.

Akce detailu (`detail.actions`, `kind: open_form`, `target.table =
economy_assets_events`, `preset` = `asset`, `event_kind`, `scope`):
**Zařadit**, **Počáteční stav** (jen bez zařazení; dvě akce — daňový /
účetní), **Technické zhodnocení**, **Snížení hodnoty**, **Přerušit
odpisy** (přerušitelná metoda), **Vyřadit**, **Odepsat** (vlastní akce
`depreciation_run` s `assetId`, viz níže). Nabídka jen povolených akcí
podle stavu karty a událostí.

Formulář karty: nový tab **Odpisy** — metody, pravidlo (select z
`rules()`), účetní délka (zadání v měsících, hint „roky × 12“), pod tím
sub-tabulka **Události** (read-only řádky, dialog události přes
`FormDialog`). Formulář události (`AssetEventsForm`): pole podle druhu
(recalculate), popisky česky.

## Odpisy za období (D33)

Backend — `AssetsDepreciationController`, routy v `Router.php`,
`ReadOnlyPolicy`: `preview` Allow, `run` Deny403:

- `GET /_assets/depreciation-run/preview?scope=tax|acc&period=<id>[&asset=<id>]`
  — `period` = id účetního roku (`tax`, `acc` při roční četnosti) nebo
  účetního měsíce (`acc` měsíčně). Vrací karty s plánovaným odpisem za
  období (inv. č., název, částka, vzorec, hlášení), součet, vyloučené
  karty s důvodem (chyba plánu, již odepsáno, vyřazeno, nezařazeno).
- `POST /_assets/depreciation-run` `{scope, period, asset?}` — v jedné
  transakci založí odpisy (`origin = system`, stav 40) pro karty z náhledu
  bez chyby; idempotentní (karta s potvrzeným odpisem za období se
  přeskočí). Vrací počet a součet.

Logika ve službě `DepreciationRunService` (testovatelná bez HTTP).

Frontend — toolbar akce `depreciation_run` ve vieweru Majetek + detailová
akce na kartě → nová komponenta `AssetsDepreciationRunDialog.svelte`
(výběr okruhu a období, tabulka náhledu, Potvrdit), po úspěchu refresh
vieweru. API wrapper `frontend/src/api/assets.js`. Texty přes i18n
(`cs.js`/`en.js`, `npm run check:i18n`).

## Nastavení (D12)

- Settings pages: nový field typ `select` (`options: [{value, label:cs,
  label:en}]`) — whitelist v `ModuleDefinition::fromArray()`,
  `SettingsController`, frontendová komponenta pole; zapsat do
  `docs/app-settings.md`.
- Stránka `assetsDepreciation` („Odpisy“, sekce Majetek): klíč
  `economy.assets.accPeriodicity` = `year` (výchozí) / `month`.
  `AssetPlanService` a `DepreciationRunService` ho čtou přes
  `SettingsStore` s fallbackem `year`.

## Popisky

Napevno zapsané `cs() ? … : …` ve viewerech majetku přesunout do cfgItem
`economy.assets.viewerLabels` (vzor `detailTabLabel()`); nové texty
této fáze rovnou tam.

## Uživatelská dokumentace

- nová `help/majetek/odpisy-majetku.md` — nastavení odpisů na kartě,
  zařazení, počáteční stav při přechodu z jiného systému (jen k začátku
  účetního roku), TZ, snížení, přerušení, vyřazení s polovinou,
  mimořádné odpisy, plán na kartě, Odpisy za období, četnost účetních
  odpisů;
- `help/majetek/evidence-majetku.md` — data pořízení/vyřazení u
  dlouhodobého majetku z událostí;
- `help/co-dnes-nejde.md` — odpisy se počítají a potvrzují, ale
  **neúčtují**; import majetku zatím ne;
- `python3 scripts/help-index.py`.

## Testy

- `AssetEventDocumentTest` — pravidla tabulky „Události“ per druh.
- `AssetEventLockProviderTest` — append-from-end, `both` vs okruh, zámek
  měsíce.
- `AssetDocumentTest` — nová pole, `taxMethodLocked`, datum jen ke čtení.
- `DepreciationRunServiceTest` — náhled, vyloučené karty, idempotence,
  měsíční četnost.
- vyřazení — poslední odpisy + přechod karty (mock DB / subclassing).
- `ReadOnlyPolicyTest` — nové routy.
- `vendor/bin/phpunit --filter 'Assets'`, pak celá sada;
  `cd frontend && npm run build && npm run check:i18n`.

## Task breakdown

0. **Oprava enginu (D46)** — pravidlo země, `TaxYearInput`, planner,
   testy, `docs/assets.md` §5.2.
1. **Schéma** — tabulka událostí, cfgItem (`eventKinds`, `eventScopes`,
   `eventOrigins`, `eventStates`, `accMethods`), sloupce karty, příznak
   `intangible`. *Hotovo když:* `ds-upgrade` na ukázkovém DS, opakovaný
   běh beze změn.
2. **Document třídy** — `AssetEventDocument`, lock provider, rozšíření
   `AssetDocument`, efekty na kartu, vyřazení s posledními odpisy + testy.
3. **Plán na kartě** — `AssetPlanService`, taby detailu, akce detailu,
   tab Odpisy + sub-tabulka + `AssetEventsForm`.
4. **Odpisy za období** — služba, controller, routy, `ReadOnlyPolicy`
   + testy.
5. **Frontend** — `AssetsDepreciationRunDialog.svelte`, toolbar a detail
   akce, `api/assets.js`, i18n.
6. **Nastavení a popisky** — field typ `select`, stránka Odpisy, cfgItem
   popisků viewerů.
7. **Dokumentace** — help, `docs/assets.md` (oblast 2 → hotovo,
   odchylky), `docs/app-settings.md`, `docs/frontend.md` (akce
   `depreciation_run`), Stav tasku + `python3 scripts/tasks-index.py`.

*Hotovo celé když* na ukázkovém DS: karta DHM rovnoměrná sk. 2 → zařadit
→ plán odpovídá sazbám → „Odpisy za období“ za rok vytvoří daňový
i účetní odpis → přerušení další rok → vyřazení s polovinou vytvoří
poslední odpisy a kartu přesune do archivu; počáteční stav na nové kartě
pokračuje správnou sazbou; mimořádná metoda sk. 2 dá měsíční rozpis.

## Rozhodnutí k designu (potvrzená)

- ✓ D27 Nic se neúčtuje; 2 a 3 do provozu společně.
- ✓ D28 Tabulka událostí 454, počáteční stav per okruh.
- ✓ D29 Stavy událostí, historie se rozebírá od konce, zámek měsíce.
- ✓ D30 Odpisové nastavení karty; daňová metoda po prvním odpisu
  neměnná.
- ✓ D33 Odpisy za období: náhled → potvrzení, idempotentní.
- ✓ D35 Vyřazení s polovinou, poslední odpisy v jedné transakci.
- ✓ D38 Data karty z událostí, vyřazení přesouvá kartu do archivu.
- ✓ D39 UI dle výše; popisky z cfgItem.
- ✓ D12 Četnost účetních odpisů jako nastavení DS.
- ✓ D46 Zdaňovací období kratší než 12 měsíců → polovina ročního odpisu.
