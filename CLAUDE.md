# Shipard — CLAUDE.md

## Projekt

Modulární multi-tenant SaaS účetní systém — backend a CLI utility, webové API a Svelte 5 frontend.

- **Namespace:** `Shipard\` → `src/`, testy `Shipard\Tests\` → `tests/`
- **PHP 8.5+**, strict_types povinně, PSR-4 autoloading
- **Závislosti:** `dibi/dibi` (DB vrstva), `symfony/console` (CLI), `phpunit/phpunit` (dev)

## Dokumentace

Podrobné specifikace jsou v adresáři `docs/`. Přečti příslušný dokument PŘED implementací.

Kam patří **nový** dokument:

- `help/` — uživatel a vestavěný asistent; „jak to udělám“
- `docs/` — Claude a vývojář; „jak je to udělané“ — specifikace
- `docs/dev/` — vývojář, hlavně začínající; návod krok za krokem
- `docs/operations/` — správce systému; provozní postup
- `modules/<skupina>/<modul>/docs/` — specifikace doplňku (a specifikace,
  která se týká jen jednoho modulu); do `docs/` jen obecná pravidla

Návod krok za krokem pro člověka patří do `docs/dev/`, provozní postup pro
správce do `docs/operations/`; specifikace zůstávají v kořeni `docs/`.

| Dokument | Obsah |
|----------|-------|
| `docs/roadmap.md` | **Roadmapa** — milníky, pravidlo prioritizace. Než začneš nový task: ověř, do kterého milníku patří |
| `tasks/README.md` | **Konvence pro tasky** — hlavička `**Stav:**`, struktura zadání, anonymizace citlivých údajů, pojmenování souborů |
| `docs/ai-workflow.md` | **Práce s Claudem** — role chat / Claude Code / člověk, postup issue → D-rozhodnutí → PRD → implementace → ověření, pravidla pro testovací server, návyky (`gh`, PHPUnit filtry, grep, ověření patchů), mapa zdrojů, osobní `CLAUDE.local.md` (šablona `CLAUDE.local.example.md`, režimy zdrojů dat na dev serveru), text instrukcí Projektu v claude.ai. Týmové přístupy jsou v soukromém repu `shipard/dev-env` |
| `docs/help-authoring.md` | **Uživatelská dokumentace** v `help/` — žánrová hranice proti `docs/`, front matter stránky, šablona, generovaný rozcestník. Přečti před psaním čehokoli do `help/` |
| `docs/services.md` | **Standard samostatných komponent** — pravidla pro repozitáře mimo `shpd` (`mail-router`, generátor videa, vendorovaná infra; `ai-analyzer` zrušen #85 D24). Přečti před prací v kterémkoli z nich a před zavedením nové komponenty |
| `docs/architecture.md` | Mapa tříd, vrstvy, závislosti, tok dat — přečti pokud potřebuješ pochopit jak komponenty spolupracují |
| `docs/modules.md` | Modulový systém — druhy modulů (jádro / doplněk / privátní), struktura modulů, závislosti, JSONC formát, vícejazyčnost (i18n), kompilace konfigurace, sekce `server.json` z modulů (`serverConfig`, kap. 5), doplňky a `ds-module` (§9), CLI příkaz `ds-upgrade`; přehled modulů po druzích je v generovaném `modules/README.md` |
| `docs/table-definitions.md` | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny; **přirozený klíč záznamu** `exchangeKey` + `exchangeLookupColumns` (§ 4, #166 D8) |
| `docs/document-system.md` | Dokumentový systém — Document třídy, hooky, validace, TableGateway, child tabulky, DocumentRegistry, **zámek záznamu** (`documentLockProviders`, §16), kdo plní `created_by` a **autor dokladu** (`DocAuthorResolver`, §13) |
| `docs/alerts.md` | Systém upozornění — JSONC `alertChecks`, PHP `AlertCheck`, `AlertReconciler`, snooze/dismiss, CLI `alerts-run`; `AlertCheck` dostává volitelný 4. parametr `?DataSourceConfig` (země zdroje dat pro `world.<země>.*`, #121 task B) |
| `docs/frontend.md` | Frontend architektura — Svelte 5, komponenty, ikony (Font Awesome), API komunikace |
| `docs/edit-forms.md` | Editační formuláře — FormDefinition, taby, sekce, sloupce, `TableForm`, JSONC formy, recalculate, doc states, **Sub-tabulky** (sekce 15: endpoint `/subtable`, `renderSubtable()`, read-only, ConfirmDialog), **HeaderInfo + živý pruh `live_summary`** (sekce 21), **Lookup pole** (sekce 22) |
| `docs/edit-forms-cookbook.md` | Editační formuláře — cookbook s izolovanými vzory pro psaní forem (JSONC i PHP `TableBuilder`); rychlý úvod, sekce/sloupce/inline/separator recepty, časté chyby. Pro hluboký referenční materiál viz `edit-forms.md`. |
| `docs/operations/secrets.md` | Per-DS šifrování `encrypted_text` sloupců — `DsSecretCipher`, klíčový soubor, rotace, health check, threat model; **tajemství konfigurace zdroje dat** v `secrets/` (§5, #126 B1): `db.password`, `mail-relay.password`, `oidc-<id>.client-secret` — `DataSourceSecrets` + sloty, migrace krokem 0 `ds-upgrade` (`DataSourceSecretsMigrator`, odebírá z `main.json` podle `id` a hodnoty), `shpd-ds ds-secret`, sekce `doctor`; hodnota, která zůstala v `main.json`, se ignoruje a `doctor` ji hlásí chybou (B2) |
| `docs/migration-guide.md` | Backup a přenos DS na jiný server — tarball, DB dump, perms, ověření |
| `docs/dashboard.md` | Dashboard — home feed akčních karet (fáze 2), kartový kontrakt, zdroje, akce + undo, tasks widget, API kontrakt |
| `docs/app-settings.md` | Settings pages + branding — `SettingsStore`, `settingsPages` v module.jsonc, klíče `app.*`, branding sloty, `/_app` endpointy, jak přidat další stránku, typy polí (`text`, `select`, `color`, `checkbox`, …) a mezinadpis bez hodnoty `section` |
| `docs/accounting.md` | Účtování dokladů — rowOperations (vlajky vč. `rowAsset` / `system`), účtovací předpis (vč. **přepisů podle varianty osnovy** `chartOverrides` — efektivní předpis z `AccountingRules::resolve()`, NNO 549 / 649, strážce masek proti seedům, #122), `AccountingEngine`, deník, **standardní dimenze jádra** (#110 D20: středisko `cost_center` a majetek `asset` jako sloupce přímo v `docs_core_heads` / `docs_core_rows` / `economy_accounting_journal`, deklarace `journalDimensions` v `economy.accounting` vč. `headColumn`, `rowFlag` a polí na formulářích `forms` (klíč pro výměnný formát říká `exchangeKey` cílové tabulky, #166 D8); viditelnost polí řídí nastavení *Dimenze na dokladech* `economy.accounting.dimension.<id>`, §6), lifecycle (stav 40), zápis přes `NestedTransaction` (§7.3), endpoint reaccount, tab Zaúčtování + `JournalViewer` (Fáze 1–3 hotové), DPH analytiky per vatCode + reverse charge + konvence OSS |
| `docs/ai.md` | **AI subsystém** — MCP nástroje, chat, analýza pošty; **AI připojení** (`core_ai_backends` = platforma + adresa + šifrovaný klíč, bez modelu), **katalog modelů** `core.ai.models` + mapa úrovní `core.ai.tiers` (`RequestShaper`), **AI funkce** (`aiFunctions` v `module.jsonc` → cfgItem `core.ai.functions`, tabulka `core_ai_function_settings` s přepsáním připojení / modelu / ladění, profil pošty přepisuje `mail.extraction`), **jeden resolver** `AiFunctionResolver` → `ResolvedAiCall::chatParams()` (pořadí přepsání → řádek funkce → deklarace / mapa úrovní; chyby `AiFunctionException`), vlastní ID modelu s `model_like`, upozornění `core.ai.model_retiring`; #85 fáze 1b (`tasks/ai-functions.md`); **fáze 1c** (`tasks/ai-settings-section.md`, D49–D52): sekce **AI** v Nastavení (Připojení / Funkce / Modely — `ModelCatalogViewer` je viewer bez tabulky), `AiFunctionResolver::plan()` / `preview()` (`AiCallPlan` bez klíče, `AiCallPreview`, `RequestChange`), **jeden zdroj textů** `core.ai.texts` (`AiTexts`) pro detail funkce, `ai-test` i chat, CLI `ai-test` (§5.6–5.7); **fáze 3a** (`tasks/ai-bedrock-eu.md`, D57–D61): platforma **Amazon Bedrock (EU)** — `core.ai.platforms` čte jen `AiPlatforms` / `AiPlatform` (dialekt, výchozí adresa, `modelIds` + `modelIdPrefix` → `eu.anthropic.…`, `priceMultiplier`, nápovědy formuláře), klient odmítá podle dialektu (`LlmChatParams::$dialect`), `platformModels` v mapě úrovní, cena jen přes `ResolvedAiCall::costUsd()`, texty `api.<druh>.<platforma>`, formulář `AIBackendForm` (§5.8) |
| `docs/auth.md` | Autentizace — auth politika per DS (`auth` v main.json), OIDC relying party (start/callback/exchange, PKCE, handoff), `SessionService`, mapování identit + JIT, break-glass CLI, test s dockerovým Keycloakem, samoobsluha účtů Fáze 0b (pozvánky, forgot/reset/change hesla, sessions), uživatel ↔ Osoba a importovaní neaktivní uživatelé (#93) |
| `docs/render.md` | PDF rendering — Gotenberg služba per stroj + `RenderClient` (`src/Core/Render/`), profily untrusted/report, post-processing (embedIsdoc/appendPdfs), degradace `errorKind`; provoz v `docs/operations/render-service.md` |
| `docs/prints.md` | Tisky — PDF nad jedním záznamem (#90): deklarace `prints` v module.jsonc, `PrintRunner` → `PrintBuilder` → `PrintData` (JSON kontrakt, bloky tisku dokladů v `docs.core`) → Twig v sandboxu → `RenderClient`; katalogy překladů, jazyk tisku podle partnera (`PrintPartyProvider` + `DocumentLanguageResolver`, #94), jazyky tisku `cs` / `en` / `sk` / `de` (kompilace konfigurace pro jazyky dokumentů, formáty přes `ext-intl`, tiskové zkratky jednotek, přepínač v náhledu, jak přidat jazyk), QR platba, vodoznak storna (`watermarks` → `meta.watermark`), opravný doklad (#92 D8: titulek `correctiveVatPayer` / `correctiveNonVatPayer` podle příznaku, ne znaménka — `DocPrintContext::corrective()`, `document.correction {originalNumber, reason}`, `_partials/correction.html.twig`), nepovinné strany, tisky faktury / zálohové faktury / pokladního dokladu / prodejky a interní Kontace, REST `/_prints`, CLI `print-run` (`--format=html`, `--data` pro vývoj šablon), generická akce Tisk v detailu vieweru, jak přidat tisk; **odesílání e-mailem** (§9, #90 D34–D45): `sendPurpose` + `recipientPerson` v deklaraci, účely kontaktů (`sendPurposes` v module.jsonc → cfgItem `base.persons.sendPurposes`), `RecipientResolver`, `RecordSendService` (`prepare` / `send`), šablony `email-*.txt.twig`, REST `send-draft` / `send`, CLI `print-send`, akce Odeslat a sekce Odeslaná pošta v detailu; **vzhled a texty na tiscích** (§12, #90 D46–D52): modul `core.prints` — stránka nastavení Tisky (akcentová barva hlavičky, umístění loga → `branding`, jen záhlaví; čtyři přepínače kontaktů vlastní firmy v zápatí → `branding.footerContacts`, #162 D130–D132; vzhled podle typografických rolí tisku je v `docs/design-system.md` §13), tabulka `core_prints_texts` a agenda Texty na tiscích, sloty (`PrintTextSlot`, `textSlots` v deklaraci), výběr ke dni tisku (`PrintTextResolver` přes `PrintTextProvider`), zpracování Twig sandbox `userTexts()` → `MarkdownEscaper` → Markdown → `texts` (`|raw` jen pro `texts.*`), přepis předmětu a těla e-mailu, proměnné (`textVariables`, `GET /_prints/text-variables`) |
| `docs/ds-state.md` | Stavy zdroje dat — lifecycle + maintenance overlay, `config/state.json` (fail-closed), 503 `DS_UNAVAILABLE`, cron gating podle `cronJobs[].states` úlohy (#172 D6), `shpd-ds ds-state`; fáze 2: `ReadOnlyPolicy` (403 `DS_READ_ONLY`, fail-closed per routa), MCP read-tier, SPA `StatusScreen` + banner, D8 `ds-state-check`; fáze 3 (hosting) dle #56; **přepínač periodických úloh** per DS (#115 fáze 1, §8): boolean `cron` v `state.json` jako třetí nezávislá osa (chybí = zapnuto, jiný typ = fail-closed), dispatcher DS s `cron: false` přeskočí celý vč. úklidu (čítač `cronDisabledDataSources`), `cron --slot --ds=<id>` = jeden tik bez zámku a heartbeatu, `ds-state cron --on\|--off`, `ds-create --no-cron`, `shpd-server ds-cron` (přehled + hromadně), viditelnost: doctor (produkce varování), dev dashboard, `CronOffBanner` přes `/_app/info` `cronEnabled`; **dispatcher respektuje aktivní moduly** (#115 D15): úlohu nese modul, který ji deklaruje v `cronJobs` (`Core\Server\CronJobRegistry`, #172 D6), `Core\Server\DataSourceModules`, pořadí kontrol přepínač → stav → moduly, vyřazená úloha se nepočítá, neurčitelné moduly = pustit vše |
| `docs/assets.md` | **Majetek** — design modulu `economy.assets` (D1–D46): rozbor starého `e10pro.property`, ledger hodnotových událostí, engine odpisů s pravidly per země, automatické zaúčtování, import. Fáze 1 (karta, typy, účetní skupiny, inventární čísla, `AssetDocument` + `AssetNumberAllocator`, seed skupin), 2a (pravidla daňových odpisů `world.assets` + čistý engine `DepreciationPlanner`, §5.1–5.2), 2b (události `AssetEventDocument` + lock provider, `AssetPlanService`, plán a akce na kartě, `DepreciationRunService` „Odpisy za období“, §5.3) a 3 (zaúčtování: `AssetPostingService` — jeden `cmnbkp` za období rovnou V pořádku, operace `asset.*`, dimenze deníku `asset`, zámky události a dokladu, řada z nastavení, §5.4) a 4 (vazba na doklady: karta na řádku pořízení `purchase.asset` — `rowAsset: "optional"`, založení karty z řádku, `AssetAcquisitionService`, pole Majetek na hlavičce a řádcích dokladů podle nastavení *Dimenze na dokladech* (`economy.accounting.dimension.asset`, #110 D21 — nahradilo `economy.assets.trackExpenses`), tab Náklady a výnosy, dva alerty, §5.5) a 5 (přehledy: pět reportů `economy.assets.*` nad evidencí — `AssetReportSupport`, `AssetPlanService::plansFor`; kontrola evidence × deník `AssetJournalCheck` jako report, dva alerty a varování na kartě; drill-down, §5.6) a 6 (import, nový Shipard: formát `shpd.assets.asset.v1` + `AssetImportApplier` s importním módem dokumentů (`_import`), doplnění karty na doklady `AssetDocLinkService` s pojistkou obratů, `assets-import-verify`, importované události zaúčtované mimo modul — D76, účet pořízení v kontrole jen varováním — D73, §5.7; runner ve starém Shipardu navazuje dle §6) hotové; **datová sada** (#131 fáze 2c: sekce `assets/` obálka `shpd.dataset.asset.v1`, seed přes dokumenty bez importního módu, dump bez zaúčtování — `modules/economy/assets/src/Dataset/`, `docs/datasets.md` §1) hotová; oblasti 7–8 dle §7 |
| `docs/exchange-rates.md` | **Kurzy cizích měn** (#121 task A, D1–D7, D10, U1–U7): typy lístků `world.cz.exchangeRateLists` (`maxAgeDays`), formát `shpd.exchangeRates.list.v1`, modul `economy.exchangeRates` — tabulky `economy_exchange_rates_lists` / `_values`, `ExchangeRateListImporter` (jediný zápis: created / skipped / replaced / conflict), `ExchangeRateResolver` (poslední lístek typu ≤ datum bez ohledu na měnu, `maxAgeDays` / `validTo`, další typ místo staršího lístku, domácí měna 1, memoizace; datum = účetní datum — u přijaté faktury DUZP, `AccountingDateRule` v docs.core, #103 D78), kurz ve formuláři dokladu (`DocsHeadsFormBase::applyExchangeRate`, hint `_exchange_rate_hint`, doklad v domácí měně má kurz 1), v applieru (`info` / `warning`, import mód nedohledává), v bance (`resolveExchangeRates` před transakcí, chybějící lístek zastaví výpis, pole Kurz ve formuláři transakce), CLI `exchange-rates-import` / `exchange-rate`, datové sady (sekce `exchangeRates/`, dump jen použitých lístků), viewer Kurzovní lístky v Nastavení; **task B** (`tasks/exchange-rates-download.md`, D2, D3, D9, U1–U7): neznámá měna při importu vyřazena (`skippedCurrencies`, otisk po vyřazení), `download` u typů (`Download\ListDownloadConfig`), `exchangeRates.serviceUrl` v `server.json` (sekce modulu — `ExchangeRatesServerConfig`, prázdné = vypnuto), `ExchangeRateServiceClient` nad vyměnitelným transportem, `ExchangeRateDownloader` (okno od předchozího fiskálního roku, dotazy po měsících od nejnovějšího, limit 6, pauza 1 h a hodina po chybě ve stavu `economy.exchangeRates.downloadState`, dnešní lístek každý běh, přítomné datum se ignoruje bez porovnání, nic nechybí = žádná síť ani log), CLI `exchange-rates-download` + úloha `five-minutes` před `alerts-run`, upozornění `economy.exchangerates.list_missing` (`Checks\ListMissingCheck`, jen u typu s lístkem), §9 |
| `docs/einvoice.md` | **Strukturovaná e-faktura ISDOC** (#119 fáze 1, D1–D10): generátor `Isdoc\IsdocWriter` jako čistá funkce canonical → ISDOC 6.0.1 (rozhraní `EInvoice\EInvoiceWriter`, registr `EInvoiceFormats`, volby `EInvoiceOptions` + `CountryNames`, `EInvoiceInputException` se všemi cestami, `EInvoiceUuid` UUIDv5), vstupní kontrakt (papírová projekce + `rows[].computed`, jen Kč, typy 1 / 4, obě strany s IČO; doklad bez DPH bez DUZP), opravný doklad (#92 D9: `invoiceIssued` s blokem `correction` → typ 2 při záporné částce, jinak 3, `OriginalDocumentReferences` jen s číslem původního dokladu, důvod opravy na začátku `Note`, částky se znaménkem; čtečka vrubopis ani odkaz nečte), XSD 6.0.1 / 6.0.2 v `modules/core/exchange/schemas/isdoc/` + `IsdocSchemaValidator`, čtečka `IsdocReader` čte zpět přenesení, režim DPH, slevu, `Note` → `notes.onDocument` i `docText`, způsob úhrady; CLI `shpd-server einvoice-render`; ISDOC v korpusu (18 souborů, D10) a round-trip `IsdocCorpusRoundTripTest` s vyjmenovanými výjimkami; ISDOC jako druhá příloha zpráv v datových sadách a evaluace zpráv s ISDOC produkční cestou — stav `notCarried`, `IsdocFieldCoverage` jako jediný zdroj polí, která ISDOC nenese, rozpad `byPath` (#103 D75–D76, D82, `tasks/testing-rates-isdoc.md`); fáze 2 (vložení do PDF, příloha e-mailu, výběr formátu) v § 9 |
| `docs/work-orders.md` | **Zakázky a periodická fakturace** — design modulu `economy.workOrders` (#110, D1–D24): čtyři typy zakázek na druhu, číselné řady se společným enginem čísel, nadřazená zakázka, evidence vyfakturovaných období, přispěvatelé obsahu faktury; středisko, zakázka a majetek jako **standardní dimenze jádra** (sloupce v tabulkách jádra, deklarace v `economy.accounting`, nastavení „Dimenze na dokladech“). Task 1 (`tasks/dimensions-core.md`: majetek a středisko jako dimenze jádra, nastavení *Dimenze na dokladech*, objekt `dimensions` ve výměnném formátu a střediska v datových sadách), task 2 (engine čísel) a **fáze 1 zakázek** (`tasks/work-orders-phase1.md`: modul `economy.workOrders` v `install.base` — druhy s typem přes `WorkOrderTypes`, řady nad `SequenceCounter`, `WorkOrderDocument` s vlastní sadou stavů Ukončeno / Zrušeno, nadřazená zakázka, zakázka jako třetí standardní dimenze `workOrder`, detail s Deníkem; §5.3 „Hotovo ve fázi 1“) a **fáze 2 — periodická fakturace** (`tasks/work-orders-phase2.md`: předpis `inv_*` druh → zakázka → řádky, `InvoicingSettingsResolver`, evidence období `economy_work_orders_periods`, `PeriodCalendar` / `PeriodLabel` / `InvoiceBuilder` → kanonický doklad přes `DocumentApplier`, `InvoicingRunService` + cron `daily`, tab Fakturace s akcemi Vystavit dlužná období / Přegenerovat (`replaceConcept`) / Obnovit, přispěvatelé, upozornění; §5.5 „Hotovo ve fázi 2“) hotové 2026-10-08 — **engine je od #175 v modulu `economy.periodicInvoicing`** (`tasks/periodic-invoicing-sources.md`, `modules/economy/periodicInvoicing/README.md`, hotovo 2026-11-26): zakázky jsou jeho první zdroj fakturace (`WorkOrderInvoicingSource`, registrace `periodicInvoicingSources` → cfgItem `economy.periodicInvoicing.sources`, rozhraní `Source\PeriodicInvoicingSource`), evidence období per zdroj (`PeriodRepository` dostává tabulku a sloupec záznamu — id období platí jen ve zdroji), jeden běh `periodic-invoicing-run [--source] [--record]` (alias `work-orders-invoice-run` na jedno vydání), společná záložka `InvoicingDetailTab`, akce záložky jako `recordActions` se třídami enginu, přispěvatelé `periodicInvoicingContributors`, upozornění `economy.periodicinvoicing.*` jednou pro všechny zdroje (nález `zdroj:id`, sekce badge v `context.navSection`), stránka nastavení v sekci Účetnictví; nový zdroj = registrace + `recordActions` + zapojení záložky, engine zdroj nejmenuje, oprava řádků předpisu podle stavu zakázky (`tasks/work-orders-rows-readonly.md`: bez `independentRows`, `WorkOrderRowLockProvider` nad `economy_work_orders_rows` — V pořádku jen ke čtení, úprava přes V opravě; generické CRUD create lock providery nehlídá) 2026-10-09; **import zakázek** (`tasks/work-orders-import.md`: formát `shpd.workOrders.workOrder.v1` — `WorkOrderImportVerifier` (kontrola payloadu před zápisem, cesty nálezů) + `WorkOrderImportApplier` v `modules/economy/workOrders/src/Import/`, `POST /_exchange/workOrders/workOrder/{validate|apply}` admin / API klíč; hlavička v Konceptu → řádky → cílový stav přes V pořádku, převzaté číslo + `_importSequence` srovná čítač řady, existující číslo = `skipped`, `validate` = průběh s rollbackem; kontrakt `docs/exchange-format.md` § Zakázky) hotový 2026-10-09; fáze 3 (úrovně V pořádku, automatické odeslání) v §7 |
| `docs/places.md` | **Místa** — obecný číselník míst s hierarchií `base.places` (#169 D1–D18, task A `tasks/places-model.md`): typ × druh (cfgItem `base.places.types` s úrovní, ikonou a `hasAddress`, jediné čtení `PlaceTypes` vč. `canBeParent()`; cfgItemy se mezi moduly neslučují — jak modul přidá typ), tabulky `base_places_kinds` / `base_places_places` (bez `exchangeKey`, kód bez unikátního indexu, cesta se neukládá), pravidla `PlaceDocument` (úrovně oběma směry, cyklus vždy, kód mezi sourozenci, koš a archiv s podřízenými, adresa jen u typu s adresou přes `PersonValues`, pořadí na konec sourozenců) a `PlaceKindDocument` (zámek typu po potvrzení i u použitého konceptu, použitý druh nejde do koše), přechody stavů přes dokument (`stateTransitionsRunDocumentHooks`), `PlaceTree` (cesta, podstrom, pořadí dětí jedním dotazem, `compareNodes()`), `PlacesLookup` s filtry `childType` / `exclude` z přepočtu formuláře, sekce Místa v Nastavení, seed druhů `PlaceKindsProvisioner`; **task B** (`tasks/viewer-tree-layout.md`, D8–D12, D20–D25): prohlížeč míst jako strom se seznamem přes přepínač, akce Přidat podřízené na řádku i v detailu (`PlacesViewer::addChildAction()` / `childPreset()` — druh posledního podřízeného, jinak `defaultChildType` typu → seedový druh → první živý druh typu; chybí u typu bez `canHaveChildren()` a u místa mimo živý stav) |
| `docs/viewer-tree.md` | **Stromový layout vieweru** (`tree`, #169 D8–D12, D20–D25) — třetí layout vedle seznamu a gridu pro tabulky se sloupcem rodiče: kontrakt `TableViewer` (`getTreeOptions()` s `parentColumn` a sloupci, `selectTreeRows()` bez stránkování a řazení s `LIMIT maxNodes + 1`, `selectTreeRowsByIds()` bez filtrů, `compareTreeRows()`, `renderTreeRow()` s `rowAction`), čistý `TreeRowsBuilder` (pořadí průchodu do hloubky, `depth`, `childCount`, předci dotažení po vrstvách jako kontext `match: false`, cyklus v datech nespadne), `ViewerController` (meta `layouts` / `tree`, `rows?layout=tree`, strop `tooMany` — strom se neořezává), frontend `ViewerTree.svelte` + čisté funkce `utils/viewerTree.js` / `viewerLayoutPick.js`, paměť rozbalení per zdroj dat a viewer (`utils/viewerTreeState.js`, dočasný pohled při hledání / filtru / jiné záložce stavů se neukládá), akce řádku přes `open_form` s `preset` a rozbalení řádku podle `meta.tree.parentColumn`, obecný přepínač layoutů (cyklus přes `meta.layouts`), telefon strom nedegraduje; jak dát vieweru strom (§ 6); pilot `PlacesViewer` |
| `docs/mail/outbound.md` | Odchozí pošta — fronta (`core_mail_outbox` + log), per-sender SMTP transporty, relay konfigurace `mail.relay`, `MailOutboxService`/`TransportResolver`, víc příjemců a kopie (`AddressList`), odesílatel záznamu (`SenderResolver`, `recordSenderProviders`, povolené adresy), posluchač výsledku transportu (`OutboxSourceListener`), **pojistka odchozí pošty** (`mail.safety` v server.json — `MailSafetyConfig`, `MailSafetyGuard`: redirect / allowlist / drop / off, fail-closed výchozí stavy, stopa `safety_action` + `[TEST]`, `doctor`, `/_app/info`, `MailSafetyNotice`; #95), cron worker, password endpoint, runbook |
| `docs/overview.md` | **Přehled firmy** — obrazovka „jak na tom firma je" (#120 D1–D19): registrace `overviewBlocks` v `module.jsonc` → cfgItem `core.overview.blocks`, sekce `core.overview.sections` (`core.system`), `src/Core/Overview/` (`OverviewBlock`, `OverviewContext`, `OverviewItem` / `OverviewMessage` / `OverviewBlockResult`, `OverviewBlockRegistry`, `OverviewCollector` s izolací per blok), `GET /_ui/overview` jen pro adminy (401 / 403 v `OverviewController`, read-only Allow), bloky banka (`BankBalancesBlock`, práh 35 dní) / pokladna (`CashBalancesBlock`, `JournalAccountBalance`) / pohledávky a závazky (`ReceivablesBlock`, `PayablesBlock` nad `CaseTotals` — jeden výpočet s patičkou vieweru případů, filtry `currency` / `dueWithin`) / DPH (`VatBlock`, `VatPeriodSelector`, `VatPositionReader`, termín `FilingDeadlines` → `CzFilingDeadlineRule` + `world.cz` `CzechHolidays`) / výsledek (`ProfitLossBlock`, klíče řádků `result` / `costs` / `revenues`), pojistka počátečních stavů `OpeningSequenceStatus`, texty `*.overviewTexts` (`FeedTexts`), panel `companyOverview` + `CompanyOverview.svelte`, `formatMoney`, jak přidat blok, odchylky od zadání |
| `modules/economy/salesRegistration/docs/sales-registration.md` | **Evidence tržeb** — doplněk `economy.salesRegistration` (#171, EET 2.0; fáze 1 `tasks/sales-registration-phase1.md`): typ evidence = data země `world.cz.salesRegistrationTypes` (`cz.eet2`) × třída engine z `economy.salesRegistration.engines`, jediné čtení `SalesRegistrationTypes` (země = první část id typu); tabulky `economy_sales_registration_registrations` / `_units` (typ `varchar` hlídaný dokumentem, `taxpayer_id` a `cert_*` systémové, bez `exchangeKey`, unikátní (typ, poplatník) jen mezi živými registracemi); certifikát — akce `uploadCertificate` (`Pkcs12Importer`, záložní `openssl pkcs12 -legacy` s heslem jen na stdin, klíč `encrypted_text`), dočasné soubory klíče `KeyFiles` v privátním `tmp/` zdroje dat; **pojistka serveru** `salesRegistration.safety.mode` v `server.json` (off / playground / drop, fail-closed) a jediné rozhodnutí o trase `MessageRouteResolver` (pojistka × režim registrace → prostředí, identita, ověření); sdílená testovací identita `resources/playground/` (test platnosti padá 30 dní před koncem); kontrakt `SalesRegistrationEngine` + `Eet2Engine` nad pinovanou knihovnou `ondrejnov/eet`, `SalesRegistrationSender`, akce `verifyConnection`; upozornění `economy.salesregistration.cert_*`; texty mimo viewer a formulář přes `Texts` (katalog `economy.salesRegistration.texts`, fallback hlídá `SalesRegistrationTextsTest`); živý test `SHIPARD_EET_PLAYGROUND=1`; chybějící body rozšíření v § 8; **fáze 2** (`tasks/sales-registration-phase2.md`, D4–D8, D13, D18–D25, U5–U12, § 10–16): tabulky tržeb a pokusů `economy_sales_registration_sales` / `_attempts` (`systemManaged`, data tržby se po vzniku nemění), evidenční jednotka na číselné řadě (extension `sales_registration_unit` + první skutečné `formTabs` `Series\SeriesUnitFormTab`, kód řady = `id_pokl` hlídá `SeriesUnitHandler`), pravidla `rules` / `timeZone` / maska `amount` v datech typu (`SaleRule`), čisté vyhodnocení dokladu `Evaluation\DocSaleEvaluator` (spadá / nespadá / nejde založit; registrace podle země registrace DPH a platnosti k času tržby, D8d, import podle sloupce hlavičky `imported`, doklad s tržbou zůstává u její registrace), rozdílové zasílání `Sales\SaleSync` pod `GET_LOCK` dokladu + unikátní (doklad, pořadí), handler `DocsHeadsSalesHandler` (stav 40 / 30 → tržba, zákaz koše v `beforeSave`), fronta `Queue\SaleQueue` nad `SaleStore` (zámek odesílatele, pokus před odesláním, stavy podle `SaleOutcome`, backoff 1 min → 1 h bez konce, jistič 60 s, obnova `sending`; handler posílá hned jen mimo transakci — z applieru to nechá cronu), záchranná síť `ReconcileScanner`, agenda `SalesViewer` v sekci Prodej, akce `resend`, upozornění `economy.salesregistration.sale_*` a `doc_unregistrable`; **fáze 3** (`tasks/sales-registration-phase3.md`, D8b–D8d, D13, U13–U25, § 17–18): **Kontaktní platba** — sloupce hlavičky dokladu `contact_payment` / `contact_payment_at` (čas v zóně místa tržby, převod jen `ContactPayment\PaymentTime`) / systémový `contact_payment_changed_at` (extension `docs_core_heads`), kde se příznak nabízí = data typu `contactPayment` čtená jen přes `ContactPaymentPolicy`, vyhodnocení k času platby (`DocEvaluation::$source`), kontroly při uložení `ContactPaymentSaveHandler`, záložka formuláře `ContactPaymentFormTab`, akce záznamu `contactPayment` / `cancelContactPayment` nad `ContactPaymentService` (podmíněný zápis bez přechodu stavu, `Sales\ImmediateSend` sdílený s handlerem), varování D8c `CashPaymentLookup`, čas tržby na jednom místě `SaleSync::saleTime()` a okno záchranné sítě i podle změny příznaku; **evidence na dokladu** — `Docs\DocSalesDetailExtension` (záložka s vyhodnocením a tržbami, štítek = nejhorší stav tržeb `SaleState::worst()`), `Docs\DocSalesRowDecorator` (štítek „EET“, jeden dotaz na stránku), důvody slovy `Evaluation\ReasonTexts`; zdroj dat bez doplňku se nemění (`DocumentsWithoutAddonTest`) |
| `docs/mail/sent.md` | **Odeslaná pošta** — evidence odeslaných zpráv nad libovolnou tabulkou (`core_mail_sent_messages`, #90 D40–D45): pevný obsah (read-only stavy, `systemManaged`, `SentMessageDocument`, strážce příloh), stavy 40 / 70 / 90, `SentMessageTransport`, propsání výsledku z fronty vč. štítku pojistky (`safety_action`, #95), Odeslat znovu, agenda a formulář zprávy, bezpečnost testování; **import** (`POST /_mail/sent/import`, #104 — `SentMessageImportService`, `import_ref`, `send_trigger = import`, stav `unknown`, 409 `IMPORTED`) |

## Architektura — rychlý přehled

```
src/
├── Command/                    # CLI příkazy jádra (Symfony Console); příkazy, které patří
│   │                           # jen modulu, žijí v modules/…/src/Command/ a registrují
│   │                           # se přes `commands` / `serverCommands` v module.jsonc (#172)
│   ├── Server/                 # shpd-server: ds-create, ds-delete, server-init, next-table-id
│   └── DataSource/             # shpd-ds: ds-upgrade
├── Core/
│   ├── Config/                 # ServerConfig, DataSourceConfig, DataClass (free / realCopy), ConfigCompiler, ConfigRuntime
│   ├── Database/               # TableDefinition, ColumnDefinition, IndexDefinition,
│   │                           # ExtensionDefinition, TableMerger, SchemaComparator,
│   │                           # SchemaValidator, SqlGenerator, DatabaseManager
│   ├── Document/               # Document, DefaultDocument, TableGateway, DocumentRegistry,
│   │                           # ValidationResult, ValidationError, DocumentResult
│   ├── I18n/                   # LocalizedFieldResolver, ConfigLocalizer
│   ├── Module/                 # ModuleDefinition, ModuleLoader, ModuleResolver
│   ├── Numbering/              # NumberPattern, NumberContext, SequenceStorage, SequenceCounter
│   │                           # (společný engine čísel řad — doklady, zakázky; #110 D17)
│   └── Utils/                  # JsoncParser, IdGenerator
modules/{skupina}/{modul}/src/  # Document třídy modulů (PersonDocument, IssuedInvoiceDocument...)
                                # src/Command/ = CLI příkazy modulu
                                # Skupiny: core, base, economy, docs, tasks, world, install
```

Závislosti tečou shora dolů: Command → Document → Module/Config/Database → I18n/Utils.

## Klíčové konvence

### Tasky a jejich stav

Zadání žijí v `tasks/`, každé má hned za nadpisem H1 řádek `**Stav:**`
(`hotovo` / `částečně` / `naplánováno` / `zrušeno`). Tento řádek je **zdroj pravdy**
o stavu — generuje se z něj souhrn nedokončených tasků v `tasks/README.md`.

**Poslední krok každé implementace je aktualizace `**Stav:**` v příslušném
tasku — ve stejném commitu jako kód.** Poté:

```bash
python3 scripts/tasks-index.py
```

Pre-commit hook ověří, že souhrn odpovídá hlavičkám. Rozpor hlavičky **s kódem** ale
neuhlídá žádný skript — jen tento návyk. Audit v srpnu 2026 našel osm tasků,
které tvrdily „k implementaci“ u věcí dávno v kódu.

Než začneš nový task: ověř v `docs/roadmap.md`, do kterého milníku patří. Otevřená
položka z M0 (věcná správnost) má přednost před vším ostatním.

Podrobnosti: `tasks/README.md` → Hlavička se stavem.

### Odeslání práce (push, pull request, merge)

O odeslání práce **vždy rozhoduje člověk**. `git push`, `gh pr create`
a `gh pr merge` spouštěj **jen na výslovný pokyn v dané konverzaci** — nikdy
z vlastní iniciativy ani „na závěr tasku“; pokyn platí pro ten jeden případ.
Bez pokynu připrav commity a popis PR do souboru. V Claude Code si tyto
příkazy vyžádají potvrzení v každém režimu (`.claude/settings.json`,
`permissions.ask`) — piš je v běžném tvaru, dotaz neobcházej. Viz
`docs/ai-workflow.md` §3 a `docs/dev/claude-code-intro.md` kapitola 8.

### Uživatelská dokumentace (`help/`)

`help/` odpovídá na otázku **„jak to udělám“**, `docs/` na **„jak je to
udělané“**. Do `help/` nepatří názvy tříd, tabulek, endpointů ani hodnoty
`docState` — uživatel pracuje se stavem **V pořádku**, ne s `docState=40`.

Čte to člověk na GitHubu **i** vnitřní AI asistent, proto:

- Názvy sekcí, tlačítek a stavů **ověř ve zdroji** popisků (`module.jsonc`
  `name:cs`, `frontend/src/i18n/cs.js`), neodhaduj je.
- Co nefunguje, patří do `help/co-dnes-nejde.md`. Bez toho si asistent
  chybějící funkci vymyslí.
- Uživatelská stránka se aktualizuje **ve stejném commitu** jako změna
  funkce, kterou popisuje. Poté `python3 scripts/help-index.py`
  (pre-commit hook ověřuje rozcestník i metadata stránek).

Formát hlavičky stránky a šablona: `docs/help-authoring.md`.

### Konfigurace na serveru
- Server config: `/etc/shipard/server.json` (práva 0600). **Sekce patří
  tam, kde žije kód, který ji čte** (#172 D7, D10,
  `tasks/module-server-config.md`): co čte jádro, má getter v `ServerConfig`
  (klíče `ServerConfig::CORE_KEYS`); co čte jen modul, modul deklaruje
  polem `serverConfig` v `module.jsonc` (`{key, class}`, klíč = cesta
  s tečkami) a načte třída modulu s rozhraním `ServerConfigSection` přes
  `ServerConfig::getSection()` — výchozí hodnoty a chyby jsou věc třídy,
  výjimka padá až při čtení sekce. Nový getter pro sekci modulu do
  `ServerConfig` nepatří. Kolize cest a klíče jádra hlídá
  `ModuleServerConfigRules` nad stromem. Dnes: `exchangeRates`
  (`economy.exchangeRates`), `registry.persons` (`base.persons`),
  `ai.analysis` (`core.mail`), `salesRegistration` (doplněk
  `economy.salesRegistration` — pojistka evidence tržeb). Viz
  `docs/modules.md` kap. 5
- Data sources: `/opt/shipard/data-sources/{id}/config/main.json` (práva 0600)
  — **bez tajemství** (#126 D1–D5): heslo k databázi, heslo relay pošty
  a `clientSecret` OIDC providerů žijí v `secrets/` jako `db.password`,
  `mail-relay.password`, `oidc-<id>.client-secret` (líné čtení
  `DataSourceSecrets`, zápis jen `SecretsFileWriter`, ruční zadání
  `shpd-ds ds-secret`; krok 0 `ds-upgrade` je z `main.json` přesune).
  Hodnota, která v `main.json` zůstala (obnova starší zálohy), se **ignoruje**
  a `doctor` ji hlásí chybou (B2). Nové tajemství = nový soubor v `secrets/`,
  nikdy klíč v `main.json`. **`main.json` smí číst i Claude**; `secrets/`
  nikdy — ani skriptem, který by si soubor otevřel sám.
  Viz `docs/operations/secrets.md` §5

### Soubory zapisované z CLI do adresáře zdroje dat
- CLI příkazy běží i pod rootem (`sudo shpd-ds …`, `shpd-server …`,
  `cron-install`). Soubor, který příkaz zapíše do adresáře zdroje dat, musí
  dostat **vlastníka zdroje dat**: `FileOwnership::matchTo()` na dočasný
  soubor před `rename`, vzor `FileOwnership::dataSourceReference()`. Jinak
  ho PHP-FPM ani cron nepřečtou — `state.json` zavře zdroj dat fail-closed,
  `secrets.key` nic nerozšifruje.
- Nepovede-li se vlastníka nastavit, zápis končí chybou a původní soubor
  zůstává — nikdy tichý úspěch.
- Nový soubor patří i do `PermissionSpec::getDataSourceEntries()`, aby ho
  hlídal `doctor` a opravil `fix-permissions`.
- Viz `docs/operations/permissions.md` → Soubory zapisované pod rootem.

### ID zdroje dat
- Formát: `xxxx-xxxx-xxxx-xxxx` (a-z0-9, 4 skupiny po 4)
- DB name: pomlčky → podtržítka (`abcd_efgh_ijkl_mnop`)
- DB user: `shpd_` + první 2 skupiny bez pomlček (`shpd_abcdefgh`)

### Zdroje dat ve veřejných textech (issues, komentáře, commity, docs, tasks)
Tento repozitář je veřejný. Reálné zdroje dat (zákazníci, migrované firmy) se
v GitHub issues, komentářích, commit messages, `docs/` a `tasks/` **nikdy
nepojmenovávají** — žádné názvy firem, jména osob, domény ani pokladny
„S. XY“. Identifikace výhradně **prefixem ID zdroje**:
- nový Shipard: prvních 6 znaků DS ID (`btpg-p`), nebo celé ID;
- starý Shipard: prvních 6 číslic (`689089`).
Čísla a částky se uvádějí jen agregovaně (počty, součty per účet/období), ne
per doklad s partnerem. Ukázkové/testovací DS (`4l3j-z0bz-kz39-echj`) omezení
nemá. Platí i pro Claude při zakládání issues a komentářů (`gh`). Projektové
soubory mimo repozitář (`alpha.md`, `fix-source/` v soukromém `old_shipard`)
smí názvy nést — tam jsou potřeba k práci.

### Databáze
- MariaDB přes Dibi (`driver: mysqli`)
- `CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci`
- **Volné textové hledání** (viewer, lookup `q`, MCP, CRUD `like`) **vždy přes
  `Shipard\Core\Database\SearchCondition`** — `LIKE %~like~ COLLATE
  utf8mb4_uca1400_ai_ci` na parametru, surový text bez `%`. Strukturální prefixy
  (čísla účtů, symboly) zůstávají v collation sloupce. Viz `docs/table-definitions.md`
  → Vyhledávání
- Žádné FOREIGN KEY — referenční integrita na aplikační úrovni
- Admin účet jen pro CREATE DATABASE / CREATE USER, runtime přes DS uživatele

### Modulový systém
- Moduly v `modules/{skupina}/{modul}/`, ID v tečkové notaci (`economy.docs`);
  skupina `[a-z][a-z0-9]*`, modul `[a-z][a-zA-Z0-9]*` — adresář s `module.jsonc`
  a neplatným jménem je chyba při načtení modulů, ne tiché přeskočení
- **Druh modulu** (#170): jádro (bez pole), **doplněk** (`"kind": "addon"`
  v `module.jsonc` — mimo `install.base`, zapíná se ve zdroji dat přes
  `shpd-ds ds-module enable <id>`), instalační (skupina `install`). **Jádro
  o doplňku neví**: modul jádra ani `install.base` na doplňku nezávisí
  (`ModuleKindRules`, test nad stromem) a doplněk se napojuje jen přes body
  rozšíření — chybějící bod je změna jádra s vlastní issue, ne úprava na
  míru doplňku. Po změně názvu, popisu nebo druhu modulu
  `php scripts/modules-index.php` (rozcestník `modules/README.md`, hlídá
  pre-commit i `ModulesIndexTest`). Viz `docs/modules.md` §9
- Definiční soubory: `.jsonc` (ručně psané), `.json` (generované)
- Vícejazyčnost: suffix `:lang` (`"name:cs": "Název"`), holé pole povinné jako fallback
- Fallback: požadovaný jazyk → `en` → holé pole
- Závislosti: jednoduché (bez verzí), tranzitivní, cyklické = chyba
- Extensions: jen přidávání sloupců/indexů do cizích tabulek
- **Rozšíření UI cizí tabulky** (#172 D16–D19, `tasks/module-ui-extensions.md`):
  modul přispívá do detailu, formuláře a seznamu tabulky, kterou nevlastní,
  třemi poli `module.jsonc` s položkou `{table, class, order?}` —
  `detailExtensions` (`Core\Viewer\DetailExtension`: taby se sdílenými typy
  obsahu a štítky za taby a štítky vieweru), `formTabs`
  (`Core\Form\ModuleFormTab`: celá záložka na konci formuláře, jen sloupce
  tabulky, ukládá běžné uložení, bez přepočtu), `rowDecorators`
  (`Core\Viewer\RowDecorator`: spany na konec `t2`, jedno volání na stránku).
  Jen aktivní moduly zdroje dat (`ModuleUiExtensions` z týchž resolvovaných
  modulů jako viewery a formuláře), třída bez argumentů konstruktoru,
  kontext `ModuleExtensionContext`. Výjimka i výsledek mimo kontrakt = log
  a přeskočení poskytovatele (`ModuleUiExtensions::call()` — jediné místo
  izolace), viewer ani formulář jádra se kvůli modulu nemění. Pravidla hlídá
  `ModuleUiExtensionRules` nad stromem. Viz `docs/modules.md` kap. 5,
  `docs/frontend.md` → Formát detail panelu / Formát řádku,
  `docs/edit-forms.md` kap. 27
- **Akce záznamu s obsluhou v modulu** (#172 D12–D15, D19,
  `tasks/module-record-actions.md`): **nová akce detailu se nepřidává do
  pevného seznamu id ve `Viewer.svelte`** (ten je uzavřený) — modul ji
  deklaruje polem `recordActions` (`{id, table, class, admin?, order?}`,
  globální id `<id modulu>.<id>`) a třídou s rozhraním
  `Core\RecordAction\RecordAction` (`available` / `describe` / `execute`).
  Jádro má jeden endpoint `POST /_ui/record-action/{table}/{id}/{akce}`
  (JSON nebo multipart; chyby polí 422 `RECORD_ACTION_INVALID`
  s `details[]`), jednu větev `kind: "record_action"` ve `Viewer.svelte`
  a jeden dialog `RecordActionDialog.svelte`. Pole dialogu jen továrnami
  `RecordActionField` (text, textarea, password, number, date, datetime,
  checkbox, select, file, notice); heslo a soubor jdou jen do `execute()`,
  nelogují se a nevracejí. Akce dostává **holý řádek tabulky** (v detailu
  i při provedení) a kontext `RecordActionContext` (uživatel,
  `ServerConfig`). Akce jsou čtvrtý druh `ModuleUiExtensions` — aktivní
  moduly, izolace `call()`, pravidla `ModuleUiExtensionRules`. Controller
  **není** v `ReadOnlyPolicy` (read_only = 403) a zámky záznamu endpoint
  neuplatňuje — rozhoduje akce. Viz `docs/modules.md` kap. 5 → Pole
  `recordActions`, `docs/rest-api.md`, `docs/frontend.md` → Akce detailu. **Třída
  rozšíření UI smí ležet i v modulu, na kterém deklarující modul
  (tranzitivně) závisí** (#175) — společné akce tak modul deklaruje nad
  svou tabulkou (akce periodické fakturace: třídy v
  `economy.periodicInvoicing`, deklarace u zdroje). Kontext akce nese
  nepovinně i `documents` / `tables` / `dispatcher` pro akce, které
  zakládají doklady (jen při provedení, ne při vykreslení detailu; #175 U3)
- Nastavení v `core_system_settings`, které drží id řádku tabulky zahazované
  resetem (např. id řady dokladů), se deklaruje v `clearSettingsOnReset`
  v `module.jsonc` — `ds-reset` ho smaže spolu s tabulkou a provisioner ho
  nastaví znovu (viz `docs/modules.md`, #131 D42)

### Databázové tabulky
- Pojmenování: `{skupina}_{modul}_{tabulka}` (snake_case)
- Každá tabulka má `tableId` (unikátní SMALLINT, globálně)
- PK: vždy `id INT AUTO_INCREMENT`
- `enumInt` → SMALLINT, `enumString` → CHAR(len) CHARACTER SET ascii
- `numeric(precision, scale)` pro finance
- `ds-upgrade`: CREATE/ADD/bezpečný MODIFY, nikdy nesmaže
- **Přirozený klíč záznamu** (#166 D8–D10, `tasks/exchange-key-references.md`):
  který sloupec záznam určuje mimo zdroj dat, říká **jen definice tabulky**
  (`exchangeKey`, pro jmenované hledání `exchangeLookupColumns`) — žádná
  mapa klíčů v PHP ani `exchangeKey` u dimenze. Hledá jediný
  `ReferenceResolver` (modules/core/exchange/src/Resolve/): přesně jeden
  živý záznam, koš se nepočítá, nenalezeno i nejednoznačné je chyba
  (`ReferenceException`), nikdy první nalezený; hodnotu neořezává. Gramatika
  hodnoty (`42` | `"@klíč"` | `"@sloupec:hodnota"`) je `ReferenceValue` —
  formát dokladu ji používá pro každý odkaz na záznam (viz Reference ve
  formátu dokladu níže). Datové sady (`SetupReferences`, přenosná je jen
  tabulka sekce setup) a dimenze dokladů (`DimensionResolver`) jsou fasády
  nad ním; čtenář dimenzí potřebuje definice tabulek. Tabulka s klíčem bez unikátního indexu patří do seznamu
  v `ExchangeKeyDefinitionsTest`. **Číselná řada dokladů a registrace DPH
  mají vlastní kód** `code` (#166 D16, D17,
  `tasks/number-series-vat-registration-codes.md`) — unikátní, nezávislý na
  `doc_number_code` (`%C`); tvar říká jen `Core\Database\RecordCode` (bez
  mezer, `@` a dvojtečky), hodnotu generátor tabulky (`invno`, `invno-2`,
  `cash-<kód pokladny>`, `cz`, `cz-2`), řádkům vloženým mimo `Document`
  ho doplní `RecordCodeBackfill` (provisionery řad hned po vložení,
  `ds-upgrade` vždy, i pod `skipProvisioning`). Viz
  `docs/table-definitions.md` § 4, `docs/exchange-format.md` § 8

### Kódové konvence
- Datové třídy (*Definition): readonly, factory `fromArray()`, validace v konstruktoru
- Příkazy: Symfony Console, testovatelné přes subclassing
- Žádná business logika v datových třídách
- Tvar a platnost IČO, DIČ, čísel účtů, IBAN, BIC, PSČ a telefonu řeší
  `world.validations`, ne vlastní regex ani kontrolní součet v modulu;
  ukládá se kanonický tvar (zápis do adresáře přes `PersonValues`), čitelný
  tvar jen pro zobrazení (`ValueRules::format()`, Twig filtry) a účty se
  párují přes `BankAccountKey` (`docs/exchange-format.md` → Normalizace
  a validace hodnot, #118)

### Dokumentový systém

Ukládání dokladů — kam se podívat podle vrstvy (#166 D56; každé pravidlo je
na jednom místě, ostatní na něj odkazují):

- **doklad** — pole, výchozí hodnoty, validace, výpočet, číslování: `docs/document-system.md` (§ 12c výchozí hodnoty), `docs/vat-calculation.md`
- **formát a ukládací API (pokyn)** — pole formátu, reference, chybějící × `null`, importní mód (úplný seznam § 10), idempotence, REST, „Doklad skriptem“, kódy nálezů pokynu: `docs/exchange-format.md`
- **interpretace (návrh z pošty a ISDOC)** — hledání podle údajů, pravidla DPH přijatých dokladů, volby uživatele `_resolve`, zakládání, učení, heuristiky, kódy nálezů návrhu, `/interpret`: `docs/document-interpretation.md`

- Data vždy jako PHP `array` — žádné property per sloupec (kvůli extensions)
- Hooky: `validate()` → `beforeSave()` → DB → `afterSave()`, `beforeDelete()` → DB → `afterDelete()`, `onLoad()`
- `validate()` vrací `ValidationResult` s chybami (column + message + code), pro UI focus
- Chyby bez vazby na pole (form-level): `column = ValidationError::FIELD_FORM` (`'_form'`) — frontend je vykreslí v banneru formuláře, ne vedle inputu. Kontrakt `field` viz `docs/edit-forms.md` sekce 8
- Chyby v řádcích: tečková notace `rows.0.unit_price`
- Hlavička + řádky: vždy v jedné DB transakci
- **Generované doklady** (periodická fakturace, později faktura ze zakázky / ze zálohy) vznikají i se přegenerovávají **výhradně přes výměnný formát a `DocumentApplier`** (`applyOptions.replaceConcept` = přegenerování konceptu v místě; záznamy, které generátor zná — partner, položky, řada, vlastní účet, dimenze — posílá referencí id, `_resolve` neposílá; applier ukládá v `NestedTransaction`, takže volající smí apply zabalit do své transakce) — žádné skládání hlavičky a řádků mimo applier. Známé výjimky (#112): `VatReturnAccountingService`, `AssetPostingDocuments`. Viz `docs/document-system.md` §12b
- **Pokyn × návrh** (#166 D18–D24, D53, `tasks/doc-apply-instruction-mode.md`, `tasks/doc-interpretation-switch.md`): dvě vstupní třídy, jedno uložení. **`DocumentApplier` ukládá jen pokyn** — `validate()` / `preview()` / `apply()` nemají režim (`ApplyMode` zanikl) ani větve odvozování a na jmenném prostoru interpretace nezávisí. Pokyn (REST `/_exchange/docs/document/*`, sekce `docs/` datových sad, generátory dokladů, import) uloží doklad, jak ho payload říká — reference, výchozí hodnoty jako ve formuláři (níže), validace a výpočet `DocDocument`, **žádné odvozování ze signálů** (místo plnění z DIČ, kód a režim DPH, doklad neplátce, převzetí rekapitulace, odhad zaokrouhlení, vynechání řádků, heuristiky validátoru, učení kódů); hodnoty DPH jsou záměr, `vat.pct` se nepoužije, blok `_resolve` pokyn nečte vůbec (info `resolve_ignored`, #166 D32). **Zaokrouhlení a `totals`** (#166 D25–D28, `tasks/doc-totals-rounding-explicit.md`): mód zaokrouhlení je ve formátu výslovný (`totals.totalRoundingMode`, `vat.roundingMode`; názvy ↔ kódy jen `RoundingModeNames`) — pokyn ho bere z payloadu (chybí = na haléře; při zapnuté automatice platí automatika a jiný uvedený mód dá varování `total_rounding_mode_ignored`), návrh ho odhaduje jen bez uvedené hodnoty. Číselná pole `totals` jsou v pokynu **kontrolní součet**: rozdíl proti hlavičce uloženého dokladu nad 0,01 = 422 `totals_check_failed`, nic se neuloží, náhled hlásí totéž; jediné porovnání je `TotalsCheck`, nikdy vlastní přepočet. Generátor, který částky nepočítá, `totals` neposílá. **Návrh** (pošta — `AnalysisController::previewMessage`, `MessageProposalApplier`, `dataset-verify`; REST jen s `applyOptions.interpret: true`) jde vstupem návrhu `Interpret\DocumentProposal` — interpretace → založení chybějících záznamů → pokyn → učení (další odrážka). Nové odvození patří jen do tříd interpretace, ne do applieru. Chyba `DocDocument::validate()` při uložení je 422 `validation_failed` s nálezy v `_resolve.issues` (cesta v canonicalu přes `DocumentIssuePaths`), ne `internal_error`; náhled pokynu ji ukáže předem. Importní mód (`applyOptions.importNumber`) je vždy pokyn a nese jen historii — **žádné výjimky z validace**; `importNumber` v návrhu = `import_requires_instruction`. Invariant „co uloží formulář, uloží pokyn“ hlídají `DocumentRoundTripMatrixTest` (tvar exportu) a `DocumentInstructionPayloadTest` (tvary, které export nevyrobí). Viz `docs/exchange-format.md` § 3, § 10
- **Interpretace dokladu a vstup návrhu** (#166 D51–D55, D59–D62, `tasks/doc-interpretation-extract.md`, `tasks/doc-interpretation-switch.md`): co návrh odvozuje z papírové podoby, žije v `modules/core/exchange/src/Document/Interpret/` — `VatInterpretation` (kontext DPH návrhu, kódy, doklad neplátce a mimo české DPH, volby DPH), `RecapInterpretation`, `RoundingInterpretation`, `RecordMatching` (hledání podle údajů, volby uživatele, `autoCreateMode`), `PendingRecords` a `SupplierCodeLearning` (zapisují), `ProposalDefaults`, `ProposalReport`, `PaperChecks` (kontroly předlohy; `validate()` je prokládá kontrolami payloadu z `DocumentValidator`). `DocumentInterpreter::interpret()` čte, nezapisuje a vrací `InterpretedDocument`: **hotový doklad** = úplný pokyn (reference číslem, hodnoty DPH a zaokrouhlení uvedené, výchozí hodnoty výslovně včetně `null`, `totals` bez částek, bez signálů a `_resolve` — skládá `FinishedDocument`), zprávu (`report`), `pending`, `rowMap`, `learn`. **Vstup návrhu `DocumentProposal`** (pošta, ISDOC, `dataset-verify`, REST s `interpret: true`): `preview()` = interpretace + náhled pokynu nad hotovým dokladem (`computed`); `apply()` = v jedné transakci založení záznamů z `pending` → `DocumentApplier::apply()` → učení dodavatelských kódů → vazba zprávy na doklad (chyba vrátí i založené osoby; idempotenci podle zprávy pošty a zámek `source.ref` přes commit drží vstup návrhu); `interpret()` = hotový doklad a zpráva bez uložení (REST `POST /_exchange/docs/document/interpret`). Odpověď návrhu = papírová podoba + `_resolve` + `savedDocId`, cesty do původního payloadu (nálezy pokynu přes `rowMap`). **Applier pravidla interpretace nevolá** (D62) — co čtou obě strany, je ve sdílené vrstvě `Document\PayloadContext` (typ, registrace DPH, datum sazby, oprava, kurz, kontext DPH pokynu), `PayloadResolve` (reference, ověření kódu DPH z payloadu, rekapitulace, plán uložení `basePlan()` / `plan()`) a `SourceRefLookup`. **Meze formátu zavřené** (D60, D61): `rows[].unit` text = systémový kód, číslo = id jednotky; `supplier.bankAccount.ref` = id účtu partnera (`null` = bez vazby, bez klíče přesná shoda čísla; účet jiné osoby `bank_account_person_mismatch`; návrh z pošty referenci zahazuje jako `source.ref`). Testy návrhu staví interpretaci nad týmiž mocky v `TestableDocumentApplier` (`InterpreterFixture::proposalOf()`). Viz `docs/document-interpretation.md` (§ 3 hotový doklad, § 4 kroky, § 12 REST), `docs/exchange-format.md` § 3
- **Výchozí hodnoty dokladu na jednom místě** (#166 D40–D50, `tasks/doc-defaults-shared.md`): co formulář předvyplní novému dokladu a řádku, doplní i pokyn, když to payload neuvádí — **jedním kódem** v `docs.core` (`Defaults\DocHeadDefaults`: měna, způsob úhrady, režim DPH, datum vystavení, registrace DPH platná k datu, vlastní účet, automatické zaokrouhlení; `Defaults\DocRowDefaults`: pohyb, text / cena / jednotka z karty položky, množství 1, první kód DPH nabídky). Volá je hook formuláře (`applyNewRecordDefaults`) i `DocumentApplier` přes adaptér `InstructionDefaults` (pracovní kopie payloadu hned po normalizaci — výchozí hodnoty tak vidí kontext DPH i validace cílového stavu). **Nová výchozí hodnota patří do společné třídy**, ne do hooku ani do applieru; rozdíly mezi typy řídí atributy `docTypes` (`vat`, `own_bank_account`, `series_binding`), ne podtřída formuláře. Kontrakt tříd: doplňuje se jen **chybějící klíč**, přítomný vyhrává i s `null`. Ve formátu **chybějící klíč = výchozí hodnota, `null` = záměrně prázdné** (`"vat": {"code": null}`); `DocumentExporter`, generátor demo sady a `CorrectiveDocBuilder` proto píšou `null` výslovně u prázdného pole s výchozí hodnotou. Importní mód nedoplňuje datum vystavení ani obsah řádku (kód DPH, cena, text, jednotka, množství), pohyb ano. Odpověď pokynu nese `_resolve.defaults` (`[{path, value}]`, náhled i uložení; payload vrací, jak přišel). Povinnost polí v pokynu hlídá `DocDocument::validate()` pro cílový stav — validátor jen pokladnu vázaného typu a směr pokladního dokladu. Pohyb: řádek s položkou podle `applyRowOperations` a typu položky, bez položky první pohyb nabídky (výchozí pohyb mapy si drží jen návrh). Invariant „minimální pokyn = formulář bez zásahu uživatele“ hlídá `DocumentFormInstructionInvariantTest` (cesta formuláře přes skutečný `FormController`). Viz `docs/document-system.md` § 12c, `docs/exchange-format.md` § 3 „Výchozí hodnoty pokynu“, § 11
- **Reference ve formátu dokladu** (#166 D30–D39, `tasks/doc-format-references.md`): každý odkaz na záznam v `shpd.docs.document.v1` je **jedno pole s referencí** (`42` | `"@klíč"` | `"@sloupec:hodnota"`) — strany a položky (reference, nebo objekt s `ref`; osoba i přes `"@company_id:…"` / `"@vat_id:…"`), `numberSeries`, `payment.ownBankAccount`, `cashDesk`, `dimensions.<id>`, `rows[].account`, `vat.registration`, `correction.originalDoc`, `partner` (hlavička účetního dokladu a typů vázaných na pokladnu) a `rows[].partner` (`tasks/doc-format-partner-fields.md`: jen přes `ref` v obou režimech; `partner` na faktuře = `partner_not_supported`, u pokladního dokladu má přednost před stranou a jiná osoba než strana je v pokynu `partner_conflict`). Hledá je jen `ReferenceResolver`; tvar referencí formátu (rozbalení strany a položky na objekt s `ref`, identifikující údaje, čtení položky řádku z obou tvarů) drží `Document\CanonicalReferences` — žádné vlastní `$row['item']['ourCode']` ani hledání podle jména mimo resolvery návrhu. **Pokyn** hledá jen přes reference a **nic nezakládá**: nenalezená / nejednoznačná / špatně zapsaná reference je nález s kódem resolveru a 422 `validation_failed`, strana nebo položka jen údaji `reference_missing`, jednotka jen přesný `system_code` (`unit_not_found`), `_resolve` se nečte vůbec — payload s blokem projde s jedním info `resolve_ignored`. **Návrh** hledá podle údajů jako dřív, `ref` má přednost, volby `_resolve.partner` / `_resolve.rows[].partner` platí jen bez pole; obohacení z historie píše `item.ref` a účet `"@číslo"` (`enrichment.suggested` nese holé hodnoty). **Dočasné signály návrhu** (D39): `rows[].item.ourCode`, účet holým číslem a `vat.registrationCountry` zůstávají ve schématu jen pro uložené návrhy z pošty — pokyn je odmítá (`signal_not_allowed`). `DocumentExporter` a generátor sad píšou reference přirozeným klíčem (strana `{ref: "@person_id", …data}` — data jsou dobový snapshot importního módu; partner hlavičky účetního dokladu stejně polem `partner`, partner řádku `"@person_id"`), generátory dokladů id; záznam bez hodnoty klíče = varování dumpu a pole se vynechá. Schéma má dvě kopie (`.jsonc`, `.json`); profil pošty nese jeho papírovou podobu (další odrážka). Viz `docs/exchange-format.md` § 5 „Reference na záznamy“, § 11 „Doklad skriptem“
- **Schéma pro model = papírová podoba dokladu** (#166 D63–D66, `tasks/mail-profile-paper-schema.md`): výstup modelu při analýze pošty se validuje proti **projekci** schématu dokladu bez toho, co papír nenese — reference na záznamy, pole určená skriptům a generátorům, volby uložení. Co to je, říká značka u vlastnosti **v jediném schématu** (`.jsonc` i `.json`): `"x-paper": false` = v papírové podobě není, `"x-paper": { … }` = má tam uvedené schéma (`rows[].unit` jen text, `rows[].account` holé číslo); projekci mechanicky odvodí `Schema\PaperSchema` (core.exchange; strana a položka jen objektem, osiřelé `$defs` pryč), validace formátu značku ignoruje. Signály D39 v projekci zůstávají. Projekci nese `output_schema` profilu pošty (`czech_general.jsonc`, větev dokladu) — **nepíše se ručně**, přepíše ji `php scripts/profile-paper-schema.php`; pole mimo ni ve výstupu modelu je `schema_error` celé analýzy (`OutputParser`), zahazování `source.ref` a reference účtu partnera zůstává jako pojistka. Projekcí se validuje **jen výstup modelu** — uložené návrhy, náhled a hotové doklady nesou pole mimo ni a jdou plným schématem. Model schéma nedostává (řídí se ukázkou v promptu). **Změna schématu, proti kterému se validuje výstup modelu (`output_schema` profilu, tedy i nové pole schématu dokladu bez značky) = nová verze promptu**: `ds-upgrade` synchronizuje profil podle `prompt_version` a profil přežije `ds-reset`, takže bez nové verze se schéma na existující zdroje dat nedostane — `ProfileSchemaDriftTest` má u verze zapsaný otisk celého `output_schema` (`Schema\SchemaFingerprint`) a změnu bez nové verze shodí. Tentýž otisk nese záznam běhu evaluace (`effective.schemaHash`, souhrn běhu, `sameSchema` v porovnání). Viz `docs/exchange-format.md` § 5 „Papírová podoba“, `docs/document-interpretation.md` § 2, `modules/core/mail/docs/ai-prompts.md` → Output schema
- **Idempotence pokynu — `source.ref`** (#166 D7, D46, `tasks/doc-apply-idempotence.md`): doklad nese identifikátor ze zdrojového systému (`source.ref` vedle `source.kind` → sloupce `source_kind` + `source_ref` hlavičky, index `idx_source_ref`) a **opakovaný pokyn se stejnou dvojicí vrátí existující živý doklad beze změny** — 200, `savedDocId`, `_resolve.summary.status = "alreadyApplied"`, info `already_applied` (`docId`, `docNumber`); obsah se neporovnává ani neposuzuje (dohledání běží před validací), doklad v koši neblokuje, víc živých = nejnovější. Klíč čte jen `Document\SourceRef` (obě hodnoty neprázdné řetězce, shoda **přesná** včetně velikosti písmen — collation sloupce dává jen kandidáty); `ref` bez `kind` = `source_kind_missing`, jiný typ dokladu = 422 `source_ref_conflict`. Souběh hlídá pojmenovaný zámek MariaDB podle klíče (`GET_LOCK`, držený přes commit; po 10 s 409 `source_ref_busy`) a druhé dohledání jako první dotaz transakce — ne `FOR UPDATE` (zámek mezery indexu by blokoval souběžné importy sousedních identifikátorů); uvnitř cizí transakce si souběh hlídá volající. `/preview` existující doklad hlásí nálezem, `replaceConcept` cílový koncept z dohledání vylučuje a `source_ref` bere z payloadu, importní mód beze změny. **Návrh z pošty klíč nenese** — papírová podoba schématu pole nemá a `MessageProposalApplier` i náhled zprávy `source.ref` zahazují (pojistka). Druh zdroje pro skripty přes REST je `api`. `DocumentExporter` `source.ref` píše; případy matice round-tripu ho nést nesmí. Viz `docs/exchange-format.md` § 5 „Idempotence pokynu“, § 10 „Idempotence a souběh“, § 11, § 12
- Child tabulky sync: bez `id` = INSERT, s `id` = UPDATE, chybějící = DELETE
- **Zámek záznamu** (`documentLockProviders: [{table, class}]` v `module.jsonc`, #55 D24): providery jedou na `DocumentRegistry` (plní `DocumentLoader`), vynucuje `TableGateway` po `validate()` (chyba `_form` kód `locked`), `deleteDocument` (`DOCUMENT_LOCKED`), generické CRUD, `DocStateTransitionFilter` (žádné přechody) i `reaccount`. Import mód (`Document::isLockExempt`, `_importNumber`) providery nevolá; `_forceUnlock` jen CLI + log. UI dostává `lock {locked, reasons[]}` v `doc_states` / `detail`. Zamykatelná entita = `locked` + `locked_at` + `locked_by` (`LockStamp`, `CurrentUser`). Viz `docs/document-system.md` §16
- **`created_by` a autor dokladu** (#93): auditní `created_by` plní `TableGateway::saveDocument` z `CurrentUser` — jen insert, jen tabulka se sloupcem (gateway k tomu potřebuje `tableDef`), a **přítomný klíč (i `null`) se nepřepisuje**; strojový kontext nechává NULL. Doklady `created_by` nemají — nesou editovatelný `author` („Vystavil“ na tisku), jehož výchozí hodnotu při vzniku určuje `DocAuthorResolver` (docs.core): explicitní klíč → přihlášený ne-systémový uživatel → `auto_author` číselné řady → nastavení `docs.autoAuthor` → NULL. Exchange: `applyOptions.author`. Viz `docs/document-system.md` → Kdo plní `created_by` / Autor dokladu
- Document třídy: registrace v `module.jsonc` (`documentClasses`), polymorfismus přes `typeColumn`
- **Kód DPH řádku** (#164, `tasks/vat-codebook-revision.md`): položkový řádek dokladu s DPH má tři stavy — kód **v rekapitulaci** (i osvobozený s nulovou daní), kód **„Mimo DPH“** `cz-000` (kategorie `outOfScope`: řádek není plněním, je mimo rekapitulaci a v částce dokladu vždy, i u převzaté rekapitulace) a **bez kódu = nevyplněno** (`vat_code_missing`: varování v rozpracovaném dokladu a při uložení řádku, chyba při potvrzení — i v importním módu, #166 D22; doklad Bez DPH kódy nenese). Jediné místo pravidla je `VatLineScope` (docs.core) — `empty($row['vat_code'])` znamená jen „řádek nemá kód“, otázka „patří do rekapitulace“ jde vždy přes `VatLineScope::inRecap()`; jediný znak „mimo rekapitulaci“ je kategorie kódu (`VatRateResolver::isOutOfScopeCode()`). Kódy „Bez daně“ zanikly: přijatý doklad od neplátce v tuzemsku vzniká v návrhu celý Bez DPH s řádky bez kódu (interpretace `VatInterpretation`, druh `supplierNonPayer`), 0 % v tuzemsku se v návrhu odvodí jako `cz-114` „Osvobozeno“, `cz-000` se neodvozuje; pokyn kód neodvozuje vůbec (#166). Viz `docs/vat-calculation.md` § 1.1, `docs/document-interpretation.md` § 6
- **Datum sazby DPH** (#159 task A, `tasks/vat-rate-date-core.md`): procento DPH řádku dokladu a řádku rekapitulace se **nezadává** — vždy se odvodí z kódu DPH a data sazby (`vat_pct` je jen uložená hodnota; žádné pole procenta ve formuláři). Datum sazby dává přepínač hlavičky `vat_rate_source` (`docs.core.vatRateSources`): 0 automaticky (opravný doklad k datu původního plnění, jinak DPPD → DUZP → datum vystavení), 1 vlastní datum dokladu (`vat_rate_date` hlavičky), 2 vlastní datum na řádcích (`vat_rate_date` řádku dokladu i rekapitulace); data mimo svůj režim se při uložení mažou, nedaňový doklad a doklad bez DPH má vždy 0. Jediné místo pravidel je `VatRateDates` (docs.core) — nikdy vlastní `vat_dppd ?? vat_duzp`. Procento se odvozuje v `DocDocument::computeAmounts()`, `takeOverVatRecapitulation()` a při uložení řádku ze sub-formuláře; `persistRowComputedColumns()` ho propíše i při uložení hlavičky bez řádků. Kód bez sazby k datu (`vat_rate_missing`) je chyba při potvrzení, v importním módu a při uložení řádku, v rozpracovaném dokladu varování. Nabídka kódů (`DocHeadVatContext::vatCodeOptions()`) je k datu sazby řádku s procentem v popisku; kontext hlavičky dává `effective_rate_date`. DPPD dokladu směru prodej následuje změnu DUZP (`VatRateDates::followObligationDate()`). Období DPH, KH a zámky datum sazby nečtou. **Výměnný formát** (task B, `tasks/vat-rate-date-exchange.md`): `vat.rateSource` (`auto` | `document` | `rows`), `dates.vatRateDate`, `rows[].vat.rateDate`, `vatRecap[].rateDate`; `DocumentApplier` skládá hlavičku pro `VatRateDates` (`vatRateHead()`) a kód DPH, sazbu i předmět plnění hledá k datu sazby **řádku** (`lineRateDate()`), místo plnění, registrace k datu a období zůstávají u DUZP (`taxPointDate`); datum mimo režim = info `vat_rate_date_ignored`; exporter vypisuje přepínač jen mimo automatiku a data jen vyplněná. Viz `docs/vat-calculation.md` § 5.2, `docs/exchange-format.md` § 5 „Datum sazby DPH“
- **Doklad převzatý importem** (#171 U11): trvalý sloupec hlavičky `imported` nastaví `DocDocument` při vzniku dokladu v importním módu (`_importNumber`); pozdější úprava ho nemění a hodnota z payloadu se nebere. Handlery a kontroly, které na převzatou historii reagovat nemají, čtou tenhle sloupec — marker `_importNumber` handler nevidí a `source_kind` import spolehlivě neříká. Viz `docs/document-system.md` § 8b
- **`documentEventHandlers` / `stateChanged`**: handler dostane událost změny stavu, **jen když Document třída tabulky přechod sama naplní** (`$this->stateTransition = [old, new]` v `beforeSave` — sdílený helper `Document::trackStateChange`, volat jako první; gateway nic nedopočítává) — jinak se nikdy nezavolá a nic to nehlásí (učení pravidel odesílatelů tak od července neběželo). U nové registrace ověřit integračním testem přes `TableGateway`; unit test, který handler volá napřímo, to nepokryje. **Insert rovnou mimo Koncept** (import, „Vystavit a uzavřít“) dává přechod `0 → X` — handler, který reaguje na vstup do stavu, musí počítat i se starým stavem 0, jinak tuhle cestu tiše mine (učení pravidel štítků z „Vystavit a uzavřít“ tak od srpna neběželo). Viz `docs/document-system.md` §8b
- PHP třídy modulů: `modules/{skupina}/{modul}/src/`, namespace `Shipard\Module\{Skupina}\{Modul}\`

- **Opravný daňový doklad** (#92 task A, `tasks/corrective-doc-core.md`): faktura vydaná s příznakem `corrective` na hlavičce, ne nový typ — typ s příznakem říká `docTypes[].corrective`, čte jen `DocTypes::isCorrective()`. Skupina sloupců `correction` (odkaz na původní doklad nepovinný, jeho číslo, datum plnění, důvod opravy); `DocDocument::applyCorrectiveColumns` denormalizuje číslo a datum z původního dokladu a na typu bez příznaku / při vypnutém příznaku sloupce nuluje, `validateCorrective` hlídá odkaz (vždy) a při potvrzení stav i partnera původního dokladu a důvod opravy — i v importním módu (#166 D22). **Sazba DPH se hledá k datu plnění původního dokladu** — automatické datum sazby `VatRateDates::automaticDate()` (řádek, rekapitulace, kontrola převzaté rekapitulace); období DPH, KH a zámky dál čtou `vat_duzp`. Kurz z lístku se u opravného dokladu nepřepisuje, formulář ho převezme z původního dokladu. Lookup `DocsHeadsLookup` (jen V pořádku), sekce Oprava v tabu Nastavení FVB, detail s odkazem z obou stran. Saldokonto, účtování ani DPH výstupy se nemění. Viz `modules/docs/core/README.md` → Opravný daňový doklad, `docs/vat-calculation.md` § 5.2. **Task B** (`tasks/corrective-doc-exchange.md`, D6, D10): canonical nese blok `correction` + pin `_resolve.correction`; `DocumentApplier::correctionDecision()` (jedno dohledání původního dokladu per canonical — pin, jinak číslo mezi doklady téhož typu V pořádku) krmí `transform()`, datum sazby (`vatContext()` → `rateDate`), kurz (`exchangeRateDecision()` lístek nečte: kurz původního dokladu nebo prázdný) i náhled; blok na typu bez příznaku = `correction_not_supported`. Exporter blok vyváží a opravné doklady řadí až za běžné doklady typu (seed obnoví odkaz podle čísla). Akce **Vystavit opravný doklad** (`detailActions` jen V pořádku + typ s příznakem) → `POST /_docs/corrective` → `CorrectiveDocService` (`modules/docs/core/src/Corrective/`): `CorrectiveDocBuilder` složí canonical z DB (ne přes exporter; záporná množství, bez `totals` / `vatRecap`, zdroj `correctiveDoc`) a uloží ho applier; frontend otevře koncept ve formuláři, tlačítko je po dobu volání zablokované. **Task C** (`tasks/corrective-doc-outputs.md`, D8, D9): tisk s titulkem opravného dokladu, číslem původního dokladu a důvodem opravy (`docs/prints.md` § 4.1) a ISDOC typ 2 / 3 (`docs/einvoice.md` § 4)

### Editační formuláře — `select` vs `lookup`
- **`select`**: enumy (`enumInt`/`enumString`) a malé cfgItem-based číselníky. Options se předají v `FormDefinition`.
- **`lookup`**: FK na velké tabulky (Osoby, Adresy, Položky…) s typeahead vyhledáváním. Klient volá `GET /_ui/lookup/{table}/search`, server pre-resolvuje vybrané hodnoty do `dataResolved`.
- Registrace lookup tříd v `module.jsonc` → `lookups: [{table, class}]`; konkrétní implementace dědí z `Shipard\Core\Form\Lookup\TableLookup`.
- Cascade filtery (např. partner → adresy partnera) jdou přes existující `recalculate` flow; žádný extra mechanismus.
- Lookup pole podporují inline **edit** (tužka u vybrané hodnoty) a **create** („+ Vytvořit nový“ v dropdownu) přes vnořený `FormDialog`. Opt-in přes `editForm`/`createForm` flagy v lookup definici. Přidaný `editTriggers` flag (default false) ovládá, zda edit detailů triggerne recalculate v rodiči — zapíná se tam, kde edit propisuje data zpět do rodiče (položka → řádek). U Partnera vypnutý, jinak by edit detailů vynuloval adresu/banku přes destruktivní cascade.
- Flag `createDefaults` (opt-in): „+ Vytvořit nový“ se předvyplní podle formuláře, ze kterého se zakládá — `TableLookup::createDefaults($parentRow, $parentHead)` + `POST /_ui/lookup/{table}/create-defaults`; data rodičů bere `LookupInput` ze Svelte contextu `form/formContext.js` (vzor `AssetsLookup` — karta majetku z řádku faktury).
- `lookup` element **nelze** umístit do `inline` skupiny.
- Detailně viz `docs/edit-forms.md` kapitola 22.

### Editační formuláře — obsah jen ke čtení (`content`, ne HTML)
- Formulář **HTML neposílá** (#150 D82): prvek `html` a `TabBuilder::html()`
  zanikly, frontend ve formuláři nemá `{@html}`; hlídá `FormContractTest`.
- Jednoduchý obsah (nápověda, text, hláška, vlastnosti, prázdný stav) =
  `TabBuilder::content([bloky])` — bloky kontraktu detailu
  (`DetailContent::SHARED_TYPES`, vč. `hint` = šedá nápověda bez tučného),
  kreslí je sdílená `components/content/ContentBlock.svelte` jako v detailu.
  Složitější obsah = pojmenovaná komponenta (`formComponents.js`; formulář
  dokladu `docVatRecap` nad `VatRecapTable`, `docParties` nad `PartyCard`).
- Texty těchto prvků jdou z katalogu `<modul>.formTexts` přes
  `TableForm::formText(katalog, klíč, anglický fallback, params)` — literální
  argumenty, fallback = holé `name` katalogu (`FormTextsCatalogTest`); názvy
  tabů a sekcí PHP formulářů zůstávají do #154.
- Viz `docs/edit-forms.md` §4.5–4.6 a kap. 11, `docs/design-system.md` §12.

### Editační formuláře — sub-tabulky
- Tab typu `subtable` (`TableForm::subtableTab()`) zobrazuje child tabulku; **sloupce
  i buňky definuje server** přes `GET /_ui/form/{parentTable}/subtable/{tabId}/{parentId}`
  → `TableForm::renderSubtable(FormTab $tab, array $rows, array $parentData)` na
  **rodičovském** formu. Default z `TableDefinition` dětské tabulky (`setTables()`),
  overridy: `DocsHeadsFormBase` (řádky dokladu), `AccountingDocsForm` (kontace),
  `PersonsForm` (kontakty / adresy / účty).
- Formát čísel, částek a dat v buňkách **jen** přes `SubtableCellFormatter` — nepřidávat
  další privátní `formatMoney()`; datum bere jazyk formuláře (`$this->language`, #133 D73). Boolean labely z cfgItem `core.system.formDefaults`.
- Read-only rodič → `FormDialog readOnly` (jen Zobrazit); mazání přes `ui/ConfirmDialog`
  → `POST …/subtable/{tabId}/{parentId}/delete` = `TableGateway::deleteDocument` dětské
  tabulky (zámky, `afterDelete` = přepočet hlavičky), **ne** generické `DELETE /{table}/{id}`.
  Detaily `docs/edit-forms.md` kapitola 15.

### Editační formuláře — polymorfismus per typ
- Formuláře nad polymorfní tabulkou (např. `docs_core_heads` s `doc_type`)
  se registrují přes `typeColumn` + `classes` + `defaultClass` v
  `module.jsonc` → `forms[]`. `FormLoader::mergeForms()` slévá registrace
  z více modulů per-table (paralela k `DocumentLoader::mergeDocumentClasses()`).
- Vzor: `docs.core` registruje `DocsHeadsForm` jako defaultClass,
  `docs.invoicesOut` přidává `invno → IssuedInvoiceForm`, `docs.invoicesIn`
  přidává `invni → ReceivedInvoiceForm`, `docs.proformasOut` přidává
  `invpo → ProformaOutForm` (#79 D1), `docs.proformasIn` přidává
  `invpi → ProformaInForm` (#106 D1). Hierarchie tříd:
  `TableForm → DocsHeadsFormBase → {DocsHeadsForm,
  ReceivedInvoiceFormBase → {ReceivedInvoiceForm, ProformaInForm},
  IssuedInvoiceFormBase → {IssuedInvoiceForm, ProformaOutForm}, CashDeskFormBase → …}`;
  `IssuedInvoiceFormBase` / `ReceivedInvoiceFormBase` (docs.core) drží
  sdílený layout hlavičky a tab Nastavení vydaných / přijatých dokladů —
  nekopírovat `buildHeaderTab()` per modul. Document třídy přijatých
  dokladů dědí `ReceivedInvoiceDocumentBase` (docs.core, doporučení
  bankovního spojení dodavatele při Potvrzení).
- **Nedaňový typ dokladu**: `docTypes[].tax_document: false` (`invpo`
  a `invpi`; chybí = daňový). Jediné čtení `DocTypes::isTaxDocument()` /
  `nonTaxDocTypes()` (docs.core) — nikdy `=== 'invpo'` v kódu. Řídí:
  `DocDocument::applyDateDefaults` (DUZP/DPPD null i z payloadu),
  `DocsHeadsFormBase::isTaxDocument()` (skrytá pole, bez defaultu DUZP),
  `VatRateDates` (sazba k datu vystavení, přepínač data sazby vždy 0),
  `DocsHeadsVatPeriodHandler` + `VatPeriodLockProvider` (období DPH null
  i ruční), `VatDocumentSelection` (NOT IN), `DocumentApplier` (ignoruje
  taxPointDate/vatObligationDate).
- Per-typ subclassy jsou tenké — overridují jen, co se má lišit (titulky
  přes `getFormTitle()` / `getNewFormTitle()`, do budoucna jednotlivé
  `buildXxxTab()` metody). Společná logika žije v base.
- Doklady vázané na pokladnu (`docs.cashDocs` → `cash`, `docs.cashRegister`
  → `cashreg`, #59) sdílejí v `docs.core` abstraktní `CashDeskDocumentBase`
  / `CashDeskFormBase` (úhrada jen hotově/kartou, měna pokladny, splatnost =
  vystavení, pokladna z řady — nikdy tichý výběr první řady). Směr `cash`
  per doklad (`cash_dir`), pohyby filtruje `rowOperations[].docTypes.cash.cashDir`.
- `FormRegistry::createForm($table, $data, $db, $config)` dispatchuje podle
  `$data[$typeColumn]`. Existující `{table, class}` registrace fungují beze
  změny (PersonsForm, ItemsForm, …).
- `DocsHeadsFormBase` má hook `buildExtraTabs(array $data, bool $isNew): array`
  pro přidání per-typ tabů na konec formuláře za Přílohy. Default = tab
  „Nastavení“ jen s polem **Vystavil** (`author`, #93); vlastní tab
  Nastavení pole přidává přes `addAuthorElement()` — select z
  `ActiveUsersOptions`, ne lookup (`core_system_users` hlídá `TableAccessGuard`).
  První uživatelé:
  - `ReceivedInvoiceForm` (FPB) — sekce DPH (vat_registration,
    vat_rounding_mode), Bankovní spojení (bank_account), Měna
    (home_currency readOnly), Ostatní (constant_symbol).
  - `IssuedInvoiceForm` (FVB) — sekce Měna (home_currency readOnly),
    Ostatní (bank_account, vat_registration, vat_calc_source,
    total_rounding_mode, vat_rounding_mode, constant_symbol). Hlavička FVB
    nemá partner_bank ani vat_dppd (DPPD odvozuje DocDocument z DUZP).
- Detailně viz `docs/edit-forms.md` kapitola 23.

### Citlivá data (encrypted_text)
- Pro nové citlivé sloupce (API klíče, hesla, tokeny) **vždy** typ `encrypted_text` v JSONC schema. Nikdy plain `text`/`varchar`.
- Encrypt/decrypt se **nedělá automaticky v TableGateway** — Document class odpovídá za:
  - `beforeSave()`: pokud je pole dirty a non-null/non-empty, `DsSecretCipher::forConfig($cfg)->encrypt(...)`
  - Controller: `$cipher->decrypt($row['col'])` až těsně před použitím
- Vzor: `tests/Fixtures/Module/Test/Secrets/TestSecretDocument.php`
- **Anti-patterny:** necachuj plaintext (session/cookie/log), neposílej ho do view/template, nepřenášej v URL/query
- Form pro editaci citlivého pole: prázdné pole + placeholder `●●●●●● (zadat pro změnu)`, prázdný submit nemění hodnotu; zápis přes form save povoluje **opt-in whitelist** `TableForm::getEditableSensitiveColumns()` (jinak 400 SENSITIVE_COLUMN) — viz `docs/edit-forms.md` kap. 24
- Backup, migrace, rotace, troubleshooting: `docs/operations/secrets.md`
- **Commitované texty** (tasky, docs, commit messages): nikdy citlivé údaje z reálných dat (jména firem/osob, čísla faktur, reálné částky, názvy DS, id záznamů) — diagnostické příklady anonymizovat se zachováním poměrů; viz `tasks/README.md` → Konvence

### CLI příkazy
- **Příkazy a úlohy cronu modulů** (#172 D1–D6, `tasks/module-commands-cron.md`):
  co patří jen modulu, žije v modulu — třída v `modules/…/src/Command/`
  (namespace `Shipard\Module\<Skupina>\<Modul>\Command\`), deklarace
  `commands` (`shpd-ds`) / `serverCommands` (`shpd-server`) v `module.jsonc`;
  factory (`src/Cli/`) a ručně psaná nápověda znají jen jádro. `shpd-ds`
  nabízí příkazy modulů aktivních ve zdroji dat, příkaz neaktivního modulu
  končí chybou s id modulu (`ShipardApplication`), mimo zdroj dat je jen
  jádro; nápověda vypisuje příkazy modulů po modulech z `setDescription()`.
  Úlohu cronu deklaruje modul polem `cronJobs` (příkaz téhož modulu, slot,
  `order`, `states`) — dispatcher žádnou per-DS úlohu nezná
  (`CronJobRegistry`). Pravidla hlídá `ModuleCommandRules` nad stromem,
  tabulku úloh `CronJobRegistryTest`. Viz `docs/modules.md` kap. 5,
  `docs/cli.md` → Příkazy jádra a příkazy modulů
- `shpd-server`: `version`, `help`, `ds-create --name [--module] [--ds-id] [--no-cron] [--real-copy] [--purpose=<text>]` (třída dat v `main.json`: ve vývoji `free`, s `--real-copy` a v produkci `realCopy`; `--no-server-ai` je zastaralý alias `--real-copy`, #126 D7), `ds-delete <id> [--dry-run] [--yes]` (tvrdé smazání **volného** zdroje dat mimo produkci: granty ostatních účtů z `mysql.db` → databáze → DB uživatel → adresář; potvrzení opsáním ID, `--yes` pro úlohu dashboardu; reálná kopie bez `ds-class set free`, režim `production` i běžící úloha dashboardu = odmítnuto, žádný `--force`; opakovatelné po částečném selhání; #103 D68–D69, `tasks/ds-delete.md`), `ds-list [--json]` (přehled zdrojů dat: třída, účel, původ, země, moduly, cron, stav, ve vývoji serverové AI — jediný zdroj přehledu i pro Clauda, bez tajemství, #126 D10), `dev-ai-key --set-key|--status` (serverový AI klíč `/etc/shipard/secrets/dev-ai-<platforma>.key` pro vývojové DS s třídou `free`; jen režim `development`), `dev-update-check [--json]` (zastaralost checkoutu vůči `stable` na GitHubu: anonymní HTTPS fetch z pevné URL do `refs/remotes/origin/stable` / `refs/shipard/upstream/stable` podle `origin`, cache `/opt/shipard/run/dev-update/state.json` pod `flock`, pojistky počítané při každém volání `notStable` / `dirty` / `notFastForward` / `jobRunning` / `missingTool` / `notChecked`; jen `development`, #129 D16–D20), `dev-update [--yes] [--job=<id>]` (kontrola + pojistky → `git -c core.hooksPath=/dev/null merge --ff-only` → `scripts/dev-update.sh` → `ds-upgrade-all` → `cron-install --check` jen varuje → nový `dev-update-check`; po merge žádná nová třída, kroky jako podprocesy s pevným `PATH`; `--job` = vlastní úloha dashboardu; jen `development`, #129 D21–D22), `test-round [--only=code,datasets,videos] [--dataset=<n|adresář>]… [--scenario=<id>]… [--eval=<varianta> --repeat=<n> --max-cost=<USD>] [--base-url=<origin>] [--detach | --follow=<id>] [--json]` (testovací kolo jedním příkazem jako úloha dashboardu `round`: kód → veřejné sady → videa nad vlastními zdroji dat `testovací kolo — <sada>`, evaluace jen se stropem po odhadu; preflight, spuštění a sledování úlohy, exit 0 / 1 / 2 / 130; `test-round-run` = skrytý orchestrátor; `docs/test-corpus.md` § 21, #103 D130–D135), `server-init`, `cron-install [--dry-run | --check] [--keep-ds-cron]` (generátor `/etc/cron.d/shipard`; první instalace mimo produkci vypne cron všem existujícím zdrojům dat a vypíše je, #115 D10; `--check` = exit 0/1 bez výstupu a bez roota pro skripty), `next-table-id`, `einvoice-render --format=isdoc --input=<canonical.json> --output=<soubor> --uuid-name=<text> [--issuing-system=<text>]` (ISDOC z canonicalu bez zdroje dat; ověření proti XSD 6.0.1 i 6.0.2, atomický zápis; exit 1 vstup / 2 schéma, nic se nezapíše — `docs/einvoice.md`, #119 D9), `hosting-sync [--dry-run] [--stats]`, `ds-state-check` (daily D8), `ds-cron [--on|--off] [--all | <id>…]` (přehled ID / Name / Cron / State a hromadný přepínač periodických úloh, #115), `doctor`, `cron --slot [--ds=<id>]` (`--ds` = jeden tik pro jeden DS: přepínač ignoruje, stav respektuje, bez zámku a heartbeatu, exit podle úloh)
- `shpd-ds` (z adresáře DS): `version`, `help`, `ds-upgrade`, `ds-setting list|get <key>|set <key> <value> [--unset]`, `ds-state [show|set <state> [--delete-after] [-y]|maintenance --on [--reason]|--off|cron --on|--off]` (`docs/ds-state.md`; `cron` = přepínač periodických úloh per DS, #115), `ds-class [show|set free|realCopy [--purpose=<text>]]` (třída dat v `main.json`; `set free` jen v interaktivním terminálu — z Claude Code ani MCP mostu neprojde, #126 D8), `ds-module [list [--json] | enable <id> | disable <id>] [--no-upgrade]` (moduly zdroje dat: `enable` jen doplněk nebo modul z dalšího kořene, `disable` jen přímo zapnutý modul mimo `install.*`; zapíše `modules` v `main.json` a spustí `ds-upgrade` ve stejném procesu, tabulky vypnutého modulu zůstávají, #170 D5), `ds-secret [list|set mail-relay.password|set oidc-<id>.client-secret]` (tajemství zdroje dat v `secrets/`; hodnota jen ze skrytého dotazu nebo stdin, nikdy z argumentu; `db.password` nastavit nejde, #126 U4), `ds-secrets-health`, `ds-secrets-rotate [--dry-run]`, `values-normalize [--dry-run] [--json]` (jednorázová normalizace existujících údajů adresáře týmiž pravidly jako uložení — osoby, adresy, kontakty, účty osob a vlastní účty; jen změněné sloupce v jedné transakci, výpis změn a nevalidních hodnot bez osobních údajů; na kopiích reálných dat ostrý běh jen člověk; #118 D22, `docs/cli.md`), `alerts-run [--check=id|--all]`, `alerts-prune [--days=N] [--dry-run]`, `report-run <reportId> --fiscal-year --month-from --month-to | --period <id instance> [--detail] [--param id=hodnota]… [--pretty] [--format=json|xlsx|csv] [--output=<soubor>]` (JSON na stdout; xlsx/csv = export, xlsx vyžaduje `--output`), `report-diff <fileA> <fileB> [--strict] [--json]` (exit 0/1/2), `print-run <printId> [<recordId>] [--format=json|pdf|html] [--language=cs|en|sk|de] [--output=<cíl>] [--data=<PrintData.json>]` (tisk nad záznamem; JSON = `PrintData` na stdout, pdf vyžaduje `--output`, html `--output=<adresář>`; `--data` = render hotových dat bez databáze a builderu), `print-send <printId> <recordId> [--to=<adresa>]… [--cc=…]… [--from=…] [--language=…] [--dry-run]` (odeslání záznamu e-mailem: zpráva v Odeslané poště + řádek fronty; `--dry-run` = návrh jako JSON; bez `--to` jdou příjemci z kontaktů — povinné jen na neprodukčním serveru s vypnutou pojistkou `mail.safety`), `vat-periods-ensure [--date=YYYY-MM-DD]` (denní cron, instance tvrzení), `vat-filing-compose --period <id instance> [--kind=regular] [--date-found=YYYY-MM-DD] | --recompute <id podání>` (podání DPH), `vat-filing-import --period=<id> --type=return|cs|rs [--kind=…] [--xml=<soubor>] [--attach=<soubor>]… [--dry-run]` (import starého podání z původního XML; `--dry-run` = rollback), `doc-reaccount <docId> [--force]` (přegenerování deníku; `--force` obejde zámek období a zaloguje), `assets-import-verify [--asset=<číslo>] [--json]` (ověření importu majetku: zlatý test daňových odpisů, účetní okruh × deník od prvního roku s vazbou, kontrola evidence × deník po letech do dnešního roku, uplynulá doba účetního odpisování jen jako upozornění; exit 1 s rozdíly), `periodic-invoicing-run [--date=YYYY-MM-DD] [--source=<id>] [--record=<číslo>] [--dry-run]` (běh periodické fakturace všech zdrojů — koncepty za splatná období; denní cron; `--source` jen jeden zdroj (zakázky `workOrders`), `--record` jen jeden záznam zdroje bez pojistky dohánění a jen se `--source`, `--dry-run` nic nezapíše; `work-orders-invoice-run [--work-order=<číslo>]` je alias `--source=workOrders` na jedno vydání, #175), `exchange-rates-import <soubor|složka> [--replace]` (nahrání kurzovních lístků `shpd.exchangeRates.list.v1`; shodný lístek beze změny, jiný obsah = konflikt, `--replace` přepíše; `docs/exchange-rates.md` §6), `exchange-rate <měna> [<datum>]` (kurz k datu podle resolveru, nebo důvod per typ lístku, proč nenalezen), `sales-registration-run [--limit=N]` (fronta evidence tržeb — doplněk `economy.salesRegistration`, cron `minute`: obnova zaseknutých + splatné tržby, výchozí limit 20; neodeslaná tržba není chyba příkazu), `sales-registration-reconcile [--days=N] [--dry-run]` (záchranná síť evidence tržeb — cron `five-minutes` před `alerts-run`: dorovná chybějící tržby dokladů V pořádku a Storno z posledních 7 dní — okno podle změny stavu i změny příznaku Kontaktní platba — s časem přechodu dokladu, u kontaktní platby s časem platby; `--dry-run` jen vypíše), `exchange-rates-download [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--type=<id>] [--dry-run]` (stažení chybějících kurzovních lístků ze služby — cron `five-minutes`: okno od předchozího fiskálního roku, nejvýš 6 dotazů, pauza 1 h na měsíc, nic nechybí = žádná síť; `--from` / `--to` ruční okno bez limitu a pauzy; prázdné `exchangeRates.serviceUrl` v `server.json` = vypnuto, exit 0; `docs/exchange-rates.md` §9), `accbal-match --all|--partner=<id>|--fiscal-year=<id> [--dry-run]` (dávkové přeúčtování clearingu), `accbal-regenerate --all|--doc=<id>|--fiscal-year=<id> [--dry-run]` (hromadná re-derivace saldo pohybů z deníku), `mail-outbox-run [--limit=N]`, `mail-outbox-retry --id N`, `mail-send-test --to x@y… [--cc x@y]… [--from ...]`, `mail-target-backfill [--dry-run] [--limit=N] [--batch=N]` (partner + titulek navázaných zpráv z dokladu), `mail-analyze --message <id> | --sweep` (AI analýza došlé zprávy v procesu, #85 D9: runner `modules/core/mail/src/Analysis/` — spawn po příjmu / nahrání / předzpracování / reanalýze, minutový sweep pro frontu bez claimu; runner po zprávě **dobírá frontu** — každou nejvýš jednou za proces, rozpočet 10 min, pak nástupce (D25); pád po claimu i třetí vypršení claimu za hodinu končí stavem 70 (D26); limit souběhu `ai.analysis.maxConcurrent` v `server.json`, výchozí 2, `0` = vypnuto; selhaná analýza není chyba příkazu), `ai-backend-set-key [--backend <kód>] [--api-key <klíč>] [--base-url <url>]` (klíč AI připojení skrytým vstupem nebo ze STDIN, `--api-key` jen pro provisioning agenta; `ai-analyzer-set-key` je alias na jedno vydání, #85 D21; model a ladění per AI funkce v Nastavení, `docs/ai.md` §5.3), `ai-test [--function <id>] [--json]` (test AI funkcí: rozhodnutí resolveru + minimální volání per funkce — připojení, model, čas, tokeny, cena, nebo věta chyby jako v detailu funkce; exit 1 při selhání; stojí zlomky centu, jen ručně; `docs/ai.md` §5.7), `ai-eval <sada> --variant <soubor> [--only] [--limit] [--repeat] [--save-raw] [--out] [--yes] [--json] [--estimate] [--max-cost <USD>] [--progress-file <soubor>]` (evaluace AI analýzy nad datovou sadou; `--estimate` = jen preflight a odhad bez běhu, `--max-cost` = strop útraty → běh uložený jako neúplný `stoppedBy: maxCost`, `--progress-file` = JSON řádky průběhu pro úlohu dashboardu, SIGTERM = zrušení bez dalšího volání, exit 130, #103 F6b D61, #103 F4: analýza nanečisto přes `AnalysisComputation`, porovnání s očekáváním po polích, soubor výsledků `tests/eval/<sada>/`; jen `development`, čistě naseedovaný zdroj dat; stojí peníze; `docs/test-corpus.md` § 18), `ai-eval-compare <A.json> <B.json> [--json]` (dva běhy vedle sebe: rozdíly metrik, šum opakování, pole zlepšená / zhoršená — součty a odchylky per uzel přepočtené na jedno opakování, změna pole od 0,5 volání; různé sady odmítne), `booking-history --input <soubor> [--report] [--apply-seed] [--tag-items[=offer|usage|auto]] [--backend <id|název>] [--model <ID>] [--model-like <ID>] [--no-llm] …` (historie účtování: report, seed pravidel, otagování položek; `--backend` / `--model` přepisují AI funkci `exchange.bookingHistory`), `registry-extract-texts [--all] [--limit=N]`, `dataset-dump <dir> [--zip] [--force]`, `dataset-seed <dir|zip> [--no-reset] [-y]` (reset + import sady, `docs/datasets.md`), `dataset-e2e <dir|zip> [--no-reseed] [--base-url <url>] [--output-dir <adresář>] [--json] [--progress-file <soubor>]` (proklikání sady v prohlížeči, #103 F7a: přeseedování → session vyhrazeného uživatele `e2e` jen ve `storageState` → Playwright Test `tests/e2e/` jako podproces → zneplatnění session → `dataset-verify --no-apply` → report; preflight jen `development` + `free` + Node a Chromium pod uživatelem příkazu; exit 0 / 1 nálezy / 2 preflight / 130 zrušeno; z dashboardu akce Proklikat = úloha `e2e`; `docs/test-corpus.md` § 19), `video-run <scénář> [--verb check|build] [--anchor YYYY-MM-DD] [--base-url <url>] [--json] [--progress-file <soubor>]` (video ze scénáře nad demo sadou, #103 F7b D119–D129: sada scénáře sestavená s kotvou = dnešek do mezipaměti `/opt/shipard/run/video-datasets` (per sada a kotva, otisk zdrojů, zámek) → `dataset-seed` vyhrazeného zdroje dat (účel `videa — <sada>`, třída `free`) → session uživatele `video` jen ve `storageState` → video-runner `check`, nebo `record` + `compose` jako podprocesy s `VIDEO_FIXED_TIME` (kotva + `clockTime` scénáře) a `VIDEO_HOST_RESOLVER_RULES` → video a `meta.json` v `/opt/shipard/run/videos/<id>/`, `check.json`; preflight i Gotenberg, ffmpeg, python3 a Chromium runneru; exit 0 / 1 / 2 / 130; z dashboardu oddíl Videa = úloha `video`; `docs/test-corpus.md` § 20), `user-create [--password|--password-stdin] [--if-not-exists] [--identity-issuer/-subject/-provider]` (bez hesla → poslat pozvánku; `--password-stdin` = heslo z prvního řádku stdin, nikdy v argv — používá formulář Nový zdroj dat, #103 D65), `user-set-admin`, `auth-emergency-login`, `hosting-oidc-init`, `hosting-oidc-client`, `hosting-server-key`, `hosting-router-key`, `hosting-ai-gw-init`, `hosting-ai-token`, `hosting-stats [--json]`

### Frontend — ikony
- Font Awesome SVG/JS, tree-shaking přes Vite
- Centrální registr: `frontend/src/icons.js` — pojmenování podle významu (`iconAdd`, `iconUser`, `iconListCheck`), ne vzhledu
- Komponenta: `Icon.svelte` (inline SVG), rozšířený `Button.svelte` (prop `icon`)
- Navigace: server posílá `"icon": "klíč"` v JSON, frontend překládá přes `resolveIcon()` s fallbackem `iconTable`
- Nová ikona: import v `icons.js` + export + záznam v `iconMap`
- Viewery dědí default ikonu pro řádky z `module.jsonc` viewers[].icon
  (stejná jako v sidebaru). Per-row override v `renderRow()`
  (např. PersonsViewer podle person_type).

### Viewery — stav v řádku seznamu

- Stav v řádku seznamu vypisuje jen `TableViewer::rowStateTag($docState)`
  (#153 D98): `null` pro Koncept (`stateStyle` `concept`) a pro stav
  s `detailBadge: false` („V pořádku“), jinak span se třídou z jediné mapy
  (`rowStateClass()`, přepsat smí viewer jen třídu jednoho stylu). Viewer
  nemá vlastní `STATE_SPAN_CLASS` ani podmínku `!== 10` / `!== 40` — hlídá
  `TableViewerRowStateTagTest`. Viz `docs/design-system.md` §4 Stav v řádku
  seznamu.

### Viewery bez tabulky

- Deklarace `viewers[]` bez `table` (`ViewerDefinition::$table === null`;
  vzor `core.ai.modelCatalog` / `ModelCatalogViewer`, #85 D50): řádky
  i detail z konfigurace. Viewer přepisuje
  `TableViewer::loadRecord(int $id): ?array` (syntetický záznam, kladné
  int id, null = 404) a `getToolbarActions()` → `[]`. `ViewerController`
  pro něj přeskočí `TableAccessGuard`, zámky, tisky i Odeslanou poštu.
  Do hlavní navigace nejde — patří do `settingsItems`. Tabulkové viewery
  `loadRecord()` nepřepisují. Viz `docs/frontend.md` → Registrace vieweru.

### Viewery — detail přes builder

- Detail záznamu skládá `TableViewer::renderDetail()` (builder, #136 D18);
  viewer dodává háčky `loadDetailRecord()`, `detailHeader()` (výchozí
  z deklarace `detailHeader` ve `viewers[]` nebo z `displayPattern` tabulky
  přes `RecordPattern`, D19), `detailBadges()`, `detailTabs()`
  (`propertiesTab()` = Přehled nad vlastnostmi), `detailActions()`,
  `detailAttachmentGroups()`. Štítek stavu přidává builder sám — stav
  s `detailBadge: false` („V pořádku“) ho nemá (D20). Přílohy záznamu
  načítá builder (`RecordAttachments`) jako `detail.attachments`: konec
  Přehledu (výchozí), tab Přílohy (`detailAttachments: "tab"`) nebo nic
  (`content`, D21); frontend je kreslí sdílenou `DetailAttachments.svelte`,
  Odeslaná pošta jen pod prvním tabem (D22). **`renderDetail()` je
  `final`** (task B #136) — viewer ji nepřepisuje, `DetailContractTest` to
  hlídá u každé deklarace; háček `detailHeader()` bez ikony dostane ikonu
  z deklarace (vlastní jen Osoby: firma / osoba), podtitul shodný
  s titulkem builder vynechá. Přílohy a štítek stavu si žádný viewer
  nestaví sám (`content` = přílohy kreslí viewer v obsahu: jen Došlá
  a Odeslaná pošta; Spisovna má přílohy na konci tabu Obsah, #140 D27).
  **Přehled neopakuje hlavičku** (#140 D26): titulek, části podtitulku
  ani štítky se v prvním tabu nevypisují, prázdná skupina mizí
  (`propertiesTab()`); náhradní titulek (pohyb saldokonta bez partnera →
  text) a zkrácený název (jen když se liší) jsou podmíněné položky, volbu
  sdílí hlavička i Přehled jednou privátní metodou (`LedgerViewer::titleOf`).
  Hlídá `ViewerDetailContractTest::testOverviewDoesNotRepeatHeader` nad
  pěti nejnovějšími záznamy každého vieweru; výjimky
  `OVERVIEW_REPEAT_ALLOWED` jen pro jiný údaj se stejnou hodnotou. Viz
  `docs/frontend.md` → Formát detail panelu, `docs/design-system.md` §12.

### Frontend — Navigace (sidebar)

- Sekce hlavního sidebaru pocházejí z cfgItem **`global.navSections`**
  (`modules/install/base/config/navSections.jsonc`), NE z prefixu module ID.
  Analogie k `global.settingsSections`. `NavigationController` (`GET
  /_ui/navigation`) seskupuje viewery/tabulky podle jejich `navSection`,
  řadí sekce dle `navSections.order` a položky dle `navOrder`.
  **Vlastní sekci** modul (typicky doplněk) deklaruje polem `navSections`
  ve svém `module.jsonc`, sekci Nastavení polem `settingsSections` —
  `ConfigCompiler` je připojí k sekcím instalačního modulu, shodné `id` je
  chyba kompilace (#170 D13, `docs/modules.md` → Vlastní sekce modulu).
- `navSection` + `navOrder` se deklarují na vieweru v `module.jsonc`
  `viewers[]` (tabulky bez vieweru v `tables/*.jsonc`). Sentinel
  `navSection: "_top"` = root-level leaf nad sekcemi (Došlá pošta, Úkoly);
  Dashboard a Chat jsou syntetické root leaves (`_order` 20/25) řazené
  společně s `_top` položkami. Panel z `panels[]` s `navSection` vstupuje
  do hlavní navigace jako `{type:'panel'}` (portál hostingu, `_top`/10).
  Bez `navSection` → fallback do sekce `system`.
- Ne-adminovi `NavigationController` strom ořezává (odvozené `adminOnly`:
  prefix `core_system_`, `adminOnly` na TableDefinition — tentýž zdroj
  pravdy jako `TableAccessGuard` — + explicitní `adminOnly: true` na
  deklaraci vieweru/panelu); `$auth === null` filtruje fail-closed.
  Viewer-level `adminOnly` = úklid navigace, ne serverová bariéra
  (vzor `core.alerts.alerts` — ne-admin alerty obsluhuje přes dashboard
  feed). Chat leaf jen s aktivním `core.chat` a ne pro ne-admina na DS
  s aktivním `hosting.core` — výraz identický s capability `chat`
  v `GET /_ui/dashboard`. Landing po přihlášení = první root-level leaf
  stromu (`navigationStore.ensureDefaultActiveItem(navTree)`).
- `hideFromNavigation: true` funguje i na **vieweru** (nejen na tabulce) —
  skryje jen ten viewer; sdílenou tabulku dál zobrazují ostatní viewery
  (souhrnný `docs.core.heads` skrytý, Faktury přijaté/vydané nad sdílenou
  `docs_core_heads` viditelné).
- API tvar odpovědi je shodný se starým prefix-groupingem → `Sidebar.svelte`
  beze změny. Detaily: `docs/frontend.md`, `docs/modules.md`.

### Frontend — Dashboard (feed, fáze 2)

- Home obrazovka, výchozí po loginu (root-level leaf, `type: 'dashboard'`).
  **Prioritizovaný feed akčních karet** + tasks widget pod ním (fáze 1 widget
  mřížka nahrazena).
- `GET /_ui/dashboard` → `{summary{aiText,counts}, sections[], cards[],
  readySummary?, capabilities}`.
  Sběr karet dělá `Core\Feed\FeedCollector` (sdílený dashboardem, AI
  shrnutím a `GET /_ui/section-badges`): napevno registrované `FeedSource`
  zdroje (`MailSuggestionsSource`, `AlertsSource` — `src/Core/Feed/`
  + moduly); zdroj se registruje jen při přítomnosti jeho klíčové tabulky
  na DS a výjimka jednoho zdroje feed neshodí (per-source izolace).
  `capabilities {mailUpload, chat}` řídí skrytí tlačítka Nahrát /
  drag&drop / ChatLauncheru na frontendu. **Sekce, řazení a strop dělá
  server** (#101): každá karta nese `feedSection` (newItems / ready /
  review / attention / failed / alerts / other — výchozí z `kind`, zdroj
  smí přepsat; `kind` se nemění), `FeedCollector::sortAndCap` řadí sekce →
  `KIND_ORDER` → interní `sortKey` ASC (K vyřízení = lhůta, #105) →
  `timestamp` DESC a stropuje `MAX_CARDS_PER_SECTION = 30`
  **per sekce**; `sections[] {id,total,shown,archivable?}` nese pravdivé
  počty (`archivable` = řádky Ostatní pro **Archivovat vše**, #105 D5), FE
  kreslí „a N dalších“ (karta „a další…“ zanikla). Zdroje dostávají jen
  pojistný `FeedContext::$sourceLimit` (500), ne strop. County, badge,
  AI digest a `readySummary` (vč. `shown`) jdou z `FeedResult::$allCards`.
- **Badge stavů sekcí** (UI shells Fáze 3, #45): karty nesou volitelný
  `navSection` (mail → `_top`, content-tag → `basic`, alerty per check
  z `alertChecks[].navSection`; setup checky bez pole). `GET
  /_ui/section-badges` → `{sections: {"<id>": {count, severity}}}` — jen
  urgent (danger) / review (warning), jen neprázdné sekce. FE: store
  `sectionBadges.svelte.js` (polling 60 s + focus, startuje AppShell),
  badge na root sekcích rozbaleného `NavTree` (jen app mód; collapsed
  a `_top` bez badge). Viz `docs/dashboard.md`, `docs/ui-shells.md` §8.
- Kartový kontrakt `{id, source, kind, feedSection, icon, stateStyle, title, subtitle,
  timestamp, context, actions[]}`. Chování akcí odvozuje frontend z `action.kind`
  (`apply_extracted`/`review_extracted`/`reject_extracted`/`reanalyze`/
  `trash_message`/`archive_message`/`open_viewer`/`open_form`/`open_detail`).
  `open_detail` = read-only detail záznamu v modalu (`ViewerDetailModal` nad
  `GET /_ui/viewer/{viewerId}/detail/{id}`, target `{viewerId, recordId,
  tabId?}`) — mail „Otevřít e-mail" a chip „+N" příloh. Mail akce bez
  `label` (FE lokalizuje dle `action.id`); alert akce nesou vlastní label
  (passthrough).
- **Jednoklik apply** posílá `safe` (`applyExtractedDocument(ndx)` bez
  userActions); `unresolved_required` (422) → **fall-through** do
  `DocumentExchangePreviewModal`. **Undo** = `POST …/unapply` (doklad do koše
  90, extracted → 20, zpráva 40→20). Toast s „Otevřít"/„Vrátit" (app nemá toast
  infra → lokální v `Dashboard.svelte`).
- `RejectReasonPrompt` je sdílený (feed i `ViewerDetail`).
- **AI shrnutí (fáze 2b)**: `GET /_ui/dashboard/summary` — SSE stream
  (`text`/`done`/`error`) generovaného shrnutí feedu. `DashboardSummaryService`
  (`src/Core/Dashboard/`) staví digest (county + top 6 karet + datum + jazyk),
  cache dle `sha256(digest)` v `core_ai_dashboard_summary` per jazyk (datum
  v hashi = regenerace aspoň denně); LLM přes `AiBackendResolver`
  (`src/Core/Ai/`) + `streamChat` (`temperature=null`, `tools=null`,
  `maxTokens ~300`). Prázdný feed / chybějící backend či klíč / chyba →
  `done{text:null}` = tichá degradace na statické county. FE:
  `streamDashboardSummary()` v `api/dashboard.js` + stream v
  `AiSummaryCard.svelte` (re-open při každém loadu dashboardu).
- Doc-state `.docState_*` třídy globální (`styles/base.css`) — sdílené
  `ViewerRow`/`WidgetRow`/`FeedCard`. Feed ikony (`check/question/warning/info`)
  v `icons.js`.
- Detaily: `docs/dashboard.md`

### Vývojářský dashboard (`/_dev/`)

- Samostatná Svelte SPA (`frontend/dev.html`, `frontend/vite.dev.config.js`,
  `frontend/src/dev/`), sestavená do `public/_dev/`; index servíruje
  `DevDashboardController` jen v módu `development`, JSON API a streamy
  `/_dev/api/*` beze změny kontraktu, prohlížeč Testování čte jen soubory
  (`DevTestingController`, `Eval/Browse/`). Texty ze slovníku dashboardu
  (`src/dev/i18n/`, cs + en); sdílí jen `styles/`, `icons.js`, `Button`, `Icon`,
  čisté jádro formátu data `utils/formatDateCore.js`
  (dialogy dev-lokální `DevDialog` / `DevConfirm`, ne `Modal`).
  Nová stránka = routa v `frontend/src/dev/routes.js` **a** segment
  v `DevDashboardController::SPA_PAGES`. **Vzhled** (#129 fáze 1,
  `tasks/dev-dashboard-look.md`): jen světlý — `src/dev/theme.css`
  přepisuje hodnoty tokenů `--shpd-*` jen v bundlu dashboardu (`data-theme`
  nikdy) a definuje tokeny `--dev-*`; IBM Plex z `@fontsource` jen v
  `src/dev/main.js`; sdílený `Button` má u `secondary` tokeny
  `--shpd-btn-secondary-text` / `-border` s fallbackem (aplikace beze
  změny); **texty česky a anglicky** (#129 D12) z vlastního slovníku
  `src/dev/i18n/` přes `t()` / `label()`, `npm run check:i18n` hlídá
  paritu, použité klíče i ICU; tlačítka `primary` = jediná hlavní akce, `secondary` = ostatní
  vč. akcí v řádcích a tlačítek, která jen otevřou potvrzení, `danger` =
  provede nevratnou akci; pruh + lišta = sticky `.dev-top` (z-index 100,
  lepicí prvky pod ní přes `--dev-top-height`); nedefinovaný token
  ve `var(--x…)`, i s fallbackem, a hex barva mimo `dev/theme.css`
  (i v `dev.css` a komponentách dashboardu, #133 D71) zastaví pre-commit
  (`scripts/check-css-tokens.py`). **Úlohy na pozadí** (#103 F6b
  D58): `src/Core/DevJobs/` (`JobRecord` = `job.json` od API, `JobState`
  = `state.json` jen od obalu, živost přes `flock` na `wrapper.lock`,
  stav odvozený při čtení, `JobLauncher` = jedna aktivní úloha na zdroj
  dat + `setsid -f` obalu `shpd-server dev-job-run` (skrytý), zrušení
  `posix_kill(-pid, 15)` celé skupině, `force` = 9), adresář
  `/opt/shipard/run/dev-jobs/<id>/`, API `DevJobsController`
  (`/_dev/api/testing/jobs…`) a spouštěcí akce `DevEvalController`
  (`/_dev/api/testing/eval/…`: zdroje dat sady, reseed / create / verify
  / estimate / run, editor variant) s pojistkou `DevActionGuard` (jen
  `development` + třída `free` + sada ze seznamu, D60) — argv bez shellu,
  hodnoty jen z výpisů. **Testovací kolo** (#103 F8a D130–D135,
  `tasks/testing-phase8a.md`, `docs/test-corpus.md` § 21): typ úlohy
  `round` = druhá úloha celého serveru, **nevýlučná**
  (`JobRecord::EXCLUSIVE_TYPES = [update]`; `conflictingJob` podle typu —
  kolo blokuje jen druhé kolo), jeden krok `shpd-server test-round-run
  <adresář>` s orchestrací uvnitř (`modules/core/exchange/src/Dataset/Round/`:
  `RoundSelection` v `params`, `RoundPlanner` → `RoundPlan`, `RoundRunner`,
  `RoundResult` = `result.json` psaný průběžně přes `JobStore::writeResult`,
  `CodeSteps` + `CodeStepProcess` + `CodeStepParser` pro vrstvu kódu),
  dílčí úlohy přes recepty `Dataset/Jobs/DatasetJobRecipes` (tytéž argv
  jako `DevEvalController` / `DevVideosController`) s `parent` = id kola,
  `JobLauncher::cancel` kaskáduje na `JobStore::activeChildren`, zámky
  `wrapper.lock` / `.lock` otevřené s `fopen` mód `ce` (close-on-exec);
  vlastní zdroje dat `testovací kolo — <sada>` (`RoundDataSources` nad
  `PurposeDataSources`, O1); CLI `test-round` = preflight + spuštění
  + sledování, API `DevRoundController` (`/_dev/api/testing/round`);
  `ai-eval --estimate` kontroluje jen `EvalPreflight::environmentProblems`.
  **Přehled zdrojů dat** (#103 D70–D71): bez sloupce
  Databáze, akce Otevřít + kebab menu (`dev/components/DevKebabMenu.svelte`
  nad sdíleným `Popover`: Logy, Upgrade, Nahrát datovou sadu, Kopírovat ID,
  Smazat… — neaktivní položky se neukazují); **Smazat…** jen u třídy `free`
  = `DeleteDataSourceDialog` s opsáním názvu → `POST
  /_dev/api/data-sources/{id}/delete` (`DevActionGuard`, 409 při běžící
  úloze) → úloha `delete` = `shpd-server ds-delete <id> --yes` s cwd v kořeni
  repozitáře. **Zastaralost a Aktualizovat** (#129 fáze 3,
  `tasks/dev-dashboard-update.md`): `src/Core/DevUpdate/` (`Upstream` = URL
  a větev napevno + cílová reference podle `origin`, `Git` nad
  `CapturedProcess` s `env`, `UpdateEnvironment` = pevný PATH + git bez
  dotazů, `UpdateStateStore` = cache + `check.lock` + značka
  `check.requested`, `UpdateChecker`, `UpdateGuards` počítané při každém
  dotazu, fasáda `UpdateStatus::snapshot()`, `UpdateStepRunner`), API
  `DevUpdatesController` (`GET /_dev/api/updates` — neplatná cache bez
  běžící kontroly spustí odpojený `dev-update-check`; `POST …/check` 202;
  `POST …/run` 409 `UPDATE_BLOCKED {blockers}`, jinak úloha `update`),
  úloha **bez zdroje dat** (`JobRecord::SERVER_TYPES`, `dataSource: null`,
  v `JobLauncher` vylučuje jakoukoli jinou úlohu a naopak přes
  `JobStore::activeJobs()`, zrušení 409 `JOB_NOT_CANCELLABLE`), UI
  `dev/components/VersionMenu.svelte` (#129 D23–D24: verze v pruhu je
  **vždy tlačítko s bublinou** — uvnitř štítek „N pozadu“ / „nelze ověřit“,
  bublina s verzí, hashem jako odkazem, větví, datem commitu, řádkem stavu
  a vždy **Zkontrolovat teď**; „aktualizuji…“ a **Obnovit stránku** jsou
  samostatné prvky vedle) + store `dev/updates.svelte.js` (polling 5 s při
  kontrole / úloze, focus nejvýš 1× za minutu, viditelná stránka sama každých
  10 minut `IDLE_POLL_MS` — D25, chyba dotazu nechává stav; po doběhnutí
  **Obnovit stránku**), čisté funkce `dev/updates.js` (`statusKind`,
  `chipKind`, `idlePollDue`, `versionAriaLabel`), klíče `updates.*`.
  **Oddíl Videa** (#103 F7b D122, D126, D127, `tasks/testing-phase7b.md`):
  stránka `videos` (`src/dev/pages/videos/`, `src/dev/videos/`, klíče
  `videos.*` / `jobs.videoStep.*`) — scénáře z `demo/scenarios/` se stavem
  posledního ověření a natočení, zdroj dat sady podle účelu `videa —
  <sada>` (založení = úloha `create`: `ds-create` s cronem + `ds-upgrade`,
  bez seedu), akce Ověřit / Natočit = úloha `video` (`shpd-ds video-run
  <scénář> --verb … --json --progress-file --base-url <origin>`, grace
  180 s), neaktivní s nápovědou bez nástrojů nebo zdroje dat, přehrávač
  a stažení přes `GET /_dev/api/videos/{id}/file` (`DevVideosController`,
  `Response::file` + `ByteRange` = HTTP `Range` 206 / 416, jen soubor
  z `meta.json`, jen `development`).
  Viz `docs/frontend.md` → Vývojářský dashboard, `docs/dev/dev-daily.md` § 1,
  `docs/test-corpus.md` § 18 a § 20.

### Frontend — Settings mód

- Aplikace má tři navigační módy: `app` (běžná práce), `settings` (Nastavení
  aplikace, DS-scoped) a `account` (Nastavení účtu, per-user)
- Mode drží `navigation.svelte.js`, každý mód má vlastní `activeItem`;
  `enterSettings`/`enterAccount`/`exitToApp`
- Sidebar mode-aware načítá `/_ui/navigation` (app), `/_ui/settings/navigation`
  (settings) nebo `/_ui/account/navigation` (account)
- Číselníky určené do Nastavení mají `settingsItems[]` v `module.jsonc`,
  sekce v `modules/install/base/config/settingsSections.jsonc`
- Položky uvedené v `settingsItems[]` se automaticky skrývají z hlavního
  navigačního stromu
- Třetí typ položky: **settings page** (`settingsItems` s `"page"`,
  definice v `settingsPages[]`) — server-driven stránka vlastností,
  hodnoty v `core_system_settings` přes `SettingsStore`. První stránka:
  Aplikace (název, ikona, logo — branding sloty). Viz `docs/app-settings.md`
- Settings page má `scope` (`ds` | `user`, default `ds`). User-scope čte/píše
  přes `UserSettingsStore` (`core_system_user_settings`, scoped na `user_id`,
  klíče `account.*`); `SettingsStore`/`UserSettingsStore` sdílí rozhraní
  `KeyValueStore`. Field typy `theme`/`language` jsou řízené widgety vázané
  na klientské stores
- **Account mód** (`account`): Nastavení účtu — vlastní strom
  (`global.accountSections` + `accountItems[]`), endpoint
  `/_ui/account/navigation`, page `accountBasic` (vzhled + jazyk, scope user).
  Detaily `docs/app-settings.md` sekce 8
- Sub-tabulky spravované výhradně přes parent záznam (např. `economy_codebooks_fiscal_months`)
  mají v JSONC definici `"hideFromNavigation": true` — nezobrazují se ani v hlavním
  sidebaru, ani v Nastavení

### Frontend — Vzhledy (themes)

- Tři režimy: Shipard (`light`) / Tmavý (`dark`) / Vlastní (`custom`);
  `auto` zanikl (migrace na follow)
- Custom barví **jen sidebar** přes runtime inline tokeny na `<html>`
  (`deriveSidebarTokens()` v `utils/themeColor.js`, OKLab/OKLCH) —
  solid barva nebo vertikální gradient + opacity mix k bázi; tělo
  stránky se nebarví nikdy — chrání doc-state systém
- **Dvouúrovňový (Fáze 4):** efektivní vzhled = `follow ? (DS default ??
  Shipard) : user override`. **DS default** = `app.theme` (scope ds,
  Nastavení aplikace → Aplikace, edituje `DsThemeField` přes Uložit), na
  klienta přes `appInfo` → `themeStore.setDsDefault()`. **User
  `account.theme`** (scope user) nese `follow`: `{follow:true}` = sleduj
  DS default, `{follow:false, mode, custom}` = override; legacy bez follow
  = override; nový uživatel/absence = follow. Přepínač „Vlastní vzhled"
  v `ThemeField`. **Dropdown vzhledu v patce sidebaru zanikl** — vzhled je
  nastavení, panel otevírá `ThemeField`
- Persistence: **server je zdroj pravdy** (per-user `account.theme`,
  DS-wide `app.theme`), localStorage = **anti-flash cache** (per-DS klíč
  v dev): override cache `shpd_theme(_custom/_tokens)`, DS default cache
  `shpd_ds_theme(_tokens)`. Po loginu `accountPrefs.load()` + `appInfo.load()`
  sesynchronizují server → store + cache; změny z panelu/stránky Základní
  píší zpět na server (`themeStore.setMode/setCustom/setFollow` přes
  `api/account.js`). **Čtyři synchronizovaná místa** localStorage:
  `theme.svelte.js`, `index.html` bootstrap, `api/config.js`, DS cache
  klíče `shpd_ds_theme*`
- Sdílené komponenty: `ThemeModeSegments` (segmented control),
  `ThemeSwatches` (controlled editor — používá ThemePanel i DsThemeField)
- Detaily: `docs/design-system.md` (sekce 9), `docs/frontend.md` (sekce 11),
  `docs/app-settings.md` (sekce 8)

### Frontend — Vícejazyčnost

- Language store: `frontend/src/stores/language.svelte.js` (mode `cs` / `en` / `auto`)
- Slovníky: `frontend/src/i18n/{cs,en}.js` — ploché objekty, tečková notace klíčů
- Helper `t(key, params?)` přes ICU MessageFormat (`intl-messageformat`), import z `i18n/index.js`
- Volba per-zařízení (localStorage `shpd_language`), `setMode()` reloadne stránku
- Anti-flash bootstrap v `frontend/index.html` nastavuje `<html lang>` před prvním renderem
- Backend dostává volbu přes `Accept-Language` header v `api/client.js`
- Lint: `cd frontend && npm run check:i18n` — kontroluje paritu klíčů cs ↔ en (aplikace i dashboard), u dashboardu navíc existenci staticky použitých klíčů, nepoužité klíče a ICU syntaxi zpráv
- **Vývojářský dashboard** má vlastní slovník a `t()` v `frontend/src/dev/i18n/` (klíč `shpd_dev_language`, jazyk podle prohlížeče, přepínač CS · EN v liště, reload po přepnutí); nikdy neimportuje `i18n/index.js` ani `stores/language.svelte.js` aplikace a naopak. Kódy ze serveru přes `label(prefix, code)` (bez klíče kód, ne klíč). Viz `docs/frontend.md` → Vývojářský dashboard
- **Datum a čas na obrazovce** jen přes `frontend/src/utils/formatDate.js` — `formatDate()` / `formatDateTime()` v jazyce rozhraní (česky `01.01.2026`, anglicky `2026-01-01`, čas `HH:mm`; čisté jádro `formatDateCore.js`, #133 D73). Žádné `toLocaleDateString()` ani `Intl.DateTimeFormat` v komponentách — hlídá `dateFormatUsage.test.mjs` v `npm test`. Viz `docs/design-system.md` §11 → Datum a čas
- Mapování chybových kódů: `frontend/src/i18n/errors.js` `translateError(error)` přeloží `error.code` přes klíče `error.<CODE>`, fallback na server `error.message`

### Backend — Vícejazyčnost server-driven labels

Lokalizace UI textů, které generuje backend (toolbar tlačítka, taby formulářů, taby detailu vieweru), jde **přes cfgItems v jsonc, ne přes hardcoded stringy v PHP**:

- **Toolbar default** (`Add`/`Open`): `core.system.viewerDefaults.toolbarActions` — `TableViewer::getToolbarActions()` z cfgItem; module overrides v `core.mail.viewerDefaults` apod.
- **AutoFormBuilder General tab**: `core.system.formDefaults.generalTabLabel`
- **Detail taby vieweru**: `*.viewerDetailLabels.tabs.*` per-modul (`core.system` má sdílený `overview`, ostatní moduly mají specifické klíče). Helper `TableViewer::detailTabLabel()` / `defaultOverviewLabel()`; texty detailu ze sekce `texts` přes `detailText()` (jiná sekce `detailSectionText()` — popisky polí Spisovny `fields`). Plochý katalog `*.viewerLabels` (`{<klíč>: {name}}`, seznamy a detaily Majetku, Zakázek a AI) čte `TableViewer::labelText()` — báze modulu mají jen tenkou `text()`, náhrada `{param}` a anglický fallback jsou v `TableViewer` jednou
- **Texty viewerů nejsou literály v PHP** (#133 D65–D67): popisek vlastnosti shodný s popiskem sloupce jde z lokalizované definice tabulky přes `TableViewer::columnLabel(sloupec, anglický fallback, ?tabulka)` (`formLabel ?? name`, jako formulář); ostatní texty detailu **i seznamu** (štítky, zástupné texty, prefixy jako celý vzor s parametrem — `"IČO: {value}"`) z katalogu modulu — plochý `*.viewerLabels`, kde ho modul má, jinak `*.viewerDetailLabels` sekce `texts`. Text, který se opakuje ve třech a více modulech (Ano / Ne, „od {date}“, „bez konce“, Identifikace, stavová slova, relativní čas), je jednou v `core.system.viewerDetailLabels` → `texts` a čte ho `TableViewer::sharedText()` / `yesNoText()`. Holé `name` v katalogu = anglická varianta = fallback v PHP znak po znaku. Hlídá `ViewerSourceTextsTest`: žádný literál s českou diakritikou a žádný literál jako popisek (`addItem()`, klíče `'title'` / `'label'` / `'text'`) ve zdrojích viewerů, a žádný dvojjazyčný zápis přímo v PHP — text ve větvi ternáru s podmínkou jazyka (`$cs ? 'česky' : 'anglicky'`) ani pod klíčem `'cs'` / `'en'`; podmínka jazyka smí vybírat jen to, co text není (oddělovač čísla). Texty s počtem se píší bez skloňování („Řádků: {count}“), katalogy nemají ICU
- **Texty prvků formuláře** (#150 D87): nápovědy, prázdné stavy, hlášky a popisky bloků prvku `content` z katalogu modulu `*.formTexts` (sekce `texts`, tvar jako `*.viewerDetailLabels`) přes `TableForm::formText()`; čtení katalogu sdílí s viewery `Core\I18n\CatalogText`. Názvy tabů, sekcí a oddělovačů PHP formulářů zatím česky natvrdo (#154)
- **JSONC form titulky/taby**: `JsoncFormLoader::load($lang)` aplikuje `ConfigLocalizer::localize()` rekurzivně na `:cs`/`:en` varianty v `title`, `titleNew`, `tabs[].label`, `elements[].label` (separátory)
- **Texty karet feedu** (#101 D11): katalog `*.feedTexts` per modul, který kartu vyrábí (`core.mail` / `core.alerts` / `core.exchange`, `config/feedTexts.jsonc`, položka `{text, text:cs, text:en}`), čte `Shipard\Core\Feed\FeedTexts::t(klíč, anglický fallback, params)`. Vzor = ICU MessageFormat jako ve frontendu (plurály `{n, plural, one {# …} few {…} other {…}}`) — uvnitř plurálu **vždy `#`** (PHP intl neumí `{n}` v plurálové větvi), apostrof `''`. Fallback v PHP musí být znak po znaku holé pole katalogu (`FeedTextsCatalogTest` to hlídá). Bez katalogu (DS před `ds-upgrade`) tiše anglicky; warning jen pro chybějící klíč v existujícím katalogu. Viz `docs/dashboard.md` §5
- **Fallback při chybějícím compiled configu**: anglický řetězec přímo v PHP — lokalizace funguje degradovaně, ne crash
- **Datum a čas pro obrazovku** tvoří jen `Shipard\Core\I18n\DisplayDate` (#133 D73): česky `01.01.2026`, anglicky a jinak ISO `2026-01-01`, s časem na minuty. Viewer volá `TableViewer::formatDate()` / `formatDateTime()`, formulář `TableForm::formatDate()` / `formatDateTime()` (jazyk dává `FormRegistry::setLanguage()`, lookupu `LookupRegistry::setLanguage()`), buňka sub-tabulky `SubtableCellFormatter::date()` s jazykem. Česká věta bez jazyka požadavku (validační hláška, zámek) bere jazyk `cs`. Vlastní `format('j. n. Y')` / `'d.m.Y'` smí jen tisk, export a text uložený v datech — `DisplayDateUsageTest` hlídá zdroje `src/` a modulů, výjimky jsou vyjmenované s důvodem. Viz `docs/design-system.md` §11 → Datum a čas

`DataSourceConfig::getDefaultLanguage()` čte volitelné pole `defaultLanguage` z `config/main.json` (default `'en'`); `resolveLanguage()` v `public/index.php` ho použije jako fallback když chybí `Accept-Language`.

Po přidání nové cfgItem registrace v `module.jsonc` je nutný **`vendor/bin/shpd-ds ds-upgrade`** v dev DS, aby se cfgItem dostala do `compiled.<jazyk>.json` (jazyky rozhraní `cs`, `en` a jazyky dokumentů `sk`, `de` — tisk čte konfiguraci v jazyce dokladu).

## Příkazy pro vývoj

```bash
composer install
vendor/bin/phpunit              # všechny testy musí projít
php bin/shpd-server version     # → Shipard v0.1.0
php bin/shpd-server help
php bin/shpd-ds version         # vyžaduje CWD s config/main.json
```

`composer.lock` je verzovaný — změna `composer.json` vždy s přepočtem locku
(`composer require` / `composer update <balík>` / `composer update --lock`)
ve stejném commitu.

## Testování

- Testy v `tests/Unit/`, zrcadlí strukturu `src/`
- DB testy: mockování přes reflexi nebo subclassing (viz `TestableDsCreateCommand`)
- void metody v mocku: jen `->method('foo')` bez `willReturn`
- JSONC parser: testovat komentáře v řetězcích, trailing čárky
- I18n: testovat fallback chain (cs → en → holé pole)

**Sekvence po dokončení tasku:**

1. `php -l` na změněných PHP souborech
2. `vendor/bin/phpunit --filter 'ClassName'` cíleně, pak celá sada
3. `cd frontend && npm run build` — pokud se měnil frontend (staví aplikaci do `public/app/` i vývojářský dashboard do `public/_dev/`; `npm test` pro čisté funkce; `python3 scripts/check-css-tokens.py` — nedefinované CSS tokeny, tokeny hlášek `--shpd-color-alert-*` mimo `Notice` / `PageBanner` a hex barvy mimo `styles/variables.css` / `dev/theme.css` (#133 D71; tón jako text a ikona bere `--shpd-color-{tón}-text`, značka bez textu — tečka, donut, proužek karty — `--shpd-color-{tón}-mark` (D74), plocha `--shpd-color-{tón}` jen pod `--shpd-color-text-on-fill`, D69); `python3 scripts/check-css-units.py` — px v mezerách, zaoblení, písmu a rozměrech (i prop `width` komponenty a layout tokeny) a breakpointy mimo D42 / D43, práh úzkého vieweru `VIEWER_SPLIT_MIN` D58; `python3 scripts/check-contrast.py` — kontrast hlídaných dvojic barevných tokenů v obou vzhledech (#153); všechny tři hlídá i pre-commit)
4. **Aktualizovat `**Stav:**` v task filu** + `python3 scripts/tasks-index.py`

## Otevřené úkoly

Směr a priority řeší **[`docs/roadmap.md`](docs/roadmap.md)** — tady už se seznam nedrží,
rozcházel se se skutečností. Stav jednotlivých zadání je v `tasks/README.md`
(generovaný souhrn) a v hlavičce `**Stav:**` každého task filu.
