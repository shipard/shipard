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
| `docs/modules.md` | Modulový systém — struktura modulů, závislosti, JSONC formát, vícejazyčnost (i18n), kompilace konfigurace, CLI příkaz `ds-upgrade` |
| `docs/table-definitions.md` | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |
| `docs/document-system.md` | Dokumentový systém — Document třídy, hooky, validace, TableGateway, child tabulky, DocumentRegistry, **zámek záznamu** (`documentLockProviders`, §16), kdo plní `created_by` a **autor dokladu** (`DocAuthorResolver`, §13) |
| `docs/alerts.md` | Systém upozornění — JSONC `alertChecks`, PHP `AlertCheck`, `AlertReconciler`, snooze/dismiss, CLI `alerts-run`; `AlertCheck` dostává volitelný 4. parametr `?DataSourceConfig` (země zdroje dat pro `world.<země>.*`, #121 task B) |
| `docs/frontend.md` | Frontend architektura — Svelte 5, komponenty, ikony (Font Awesome), API komunikace |
| `docs/edit-forms.md` | Editační formuláře — FormDefinition, taby, sekce, sloupce, `TableForm`, JSONC formy, recalculate, doc states, **Sub-tabulky** (sekce 15: endpoint `/subtable`, `renderSubtable()`, read-only, ConfirmDialog), **HeaderInfo + živý pruh `live_summary`** (sekce 21), **Lookup pole** (sekce 22) |
| `docs/edit-forms-cookbook.md` | Editační formuláře — cookbook s izolovanými vzory pro psaní forem (JSONC i PHP `TableBuilder`); rychlý úvod, sekce/sloupce/inline/separator recepty, časté chyby. Pro hluboký referenční materiál viz `edit-forms.md`. |
| `docs/operations/secrets.md` | Per-DS šifrování `encrypted_text` sloupců — `DsSecretCipher`, klíčový soubor, rotace, health check, threat model; **tajemství konfigurace zdroje dat** v `secrets/` (§5, #126 B1): `db.password`, `mail-relay.password`, `oidc-<id>.client-secret` — `DataSourceSecrets` + sloty, migrace krokem 0 `ds-upgrade` (`DataSourceSecretsMigrator`), `shpd-ds ds-secret`, sekce `doctor`, přechodná přednost `main.json` do tasku B2 |
| `docs/migration-guide.md` | Backup a přenos DS na jiný server — tarball, DB dump, perms, ověření |
| `docs/dashboard.md` | Dashboard — home feed akčních karet (fáze 2), kartový kontrakt, zdroje, akce + undo, tasks widget, API kontrakt |
| `docs/app-settings.md` | Settings pages + branding — `SettingsStore`, `settingsPages` v module.jsonc, klíče `app.*`, branding sloty, `/_app` endpointy, jak přidat další stránku, typy polí (`text`, `select`, `color`, …) |
| `docs/accounting.md` | Účtování dokladů — rowOperations (vlajky vč. `rowAsset` / `system`), účtovací předpis (vč. **přepisů podle varianty osnovy** `chartOverrides` — efektivní předpis z `AccountingRules::resolve()`, NNO 549 / 649, strážce masek proti seedům, #122), `AccountingEngine`, deník, **standardní dimenze jádra** (#110 D20: středisko `cost_center` a majetek `asset` jako sloupce přímo v `docs_core_heads` / `docs_core_rows` / `economy_accounting_journal`, deklarace `journalDimensions` v `economy.accounting` vč. `headColumn`, `rowFlag`, polí na formulářích `forms` a `exchangeKey` pro výměnný formát; viditelnost polí řídí nastavení *Dimenze na dokladech* `economy.accounting.dimension.<id>`, §6), lifecycle (stav 40), zápis přes `NestedTransaction` (§7.3), endpoint reaccount, tab Zaúčtování + `JournalViewer` (Fáze 1–3 hotové), DPH analytiky per vatCode + reverse charge + konvence OSS |
| `docs/ai.md` | **AI subsystém** — MCP nástroje, chat, analýza pošty; **AI připojení** (`core_ai_backends` = platforma + adresa + šifrovaný klíč, bez modelu), **katalog modelů** `core.ai.models` + mapa úrovní `core.ai.tiers` (`RequestShaper`), **AI funkce** (`aiFunctions` v `module.jsonc` → cfgItem `core.ai.functions`, tabulka `core_ai_function_settings` s přepsáním připojení / modelu / ladění, profil pošty přepisuje `mail.extraction`), **jeden resolver** `AiFunctionResolver` → `ResolvedAiCall::chatParams()` (pořadí přepsání → řádek funkce → deklarace / mapa úrovní; chyby `AiFunctionException`), vlastní ID modelu s `model_like`, upozornění `core.ai.model_retiring`; #85 fáze 1b (`tasks/ai-functions.md`); **fáze 1c** (`tasks/ai-settings-section.md`, D49–D52): sekce **AI** v Nastavení (Připojení / Funkce / Modely — `ModelCatalogViewer` je viewer bez tabulky), `AiFunctionResolver::plan()` / `preview()` (`AiCallPlan` bez klíče, `AiCallPreview`, `RequestChange`), **jeden zdroj textů** `core.ai.texts` (`AiTexts`) pro detail funkce, `ai-test` i chat, CLI `ai-test` (§5.6–5.7); **fáze 3a** (`tasks/ai-bedrock-eu.md`, D57–D61): platforma **Amazon Bedrock (EU)** — `core.ai.platforms` čte jen `AiPlatforms` / `AiPlatform` (dialekt, výchozí adresa, `modelIds` + `modelIdPrefix` → `eu.anthropic.…`, `priceMultiplier`, nápovědy formuláře), klient odmítá podle dialektu (`LlmChatParams::$dialect`), `platformModels` v mapě úrovní, cena jen přes `ResolvedAiCall::costUsd()`, texty `api.<druh>.<platforma>`, formulář `AIBackendForm` (§5.8) |
| `docs/auth.md` | Autentizace — auth politika per DS (`auth` v main.json), OIDC relying party (start/callback/exchange, PKCE, handoff), `SessionService`, mapování identit + JIT, break-glass CLI, test s dockerovým Keycloakem, samoobsluha účtů Fáze 0b (pozvánky, forgot/reset/change hesla, sessions), uživatel ↔ Osoba a importovaní neaktivní uživatelé (#93) |
| `docs/render.md` | PDF rendering — Gotenberg služba per stroj + `RenderClient` (`src/Core/Render/`), profily untrusted/report, post-processing (embedIsdoc/appendPdfs), degradace `errorKind`; provoz v `docs/operations/render-service.md` |
| `docs/prints.md` | Tisky — PDF nad jedním záznamem (#90): deklarace `prints` v module.jsonc, `PrintRunner` → `PrintBuilder` → `PrintData` (JSON kontrakt, bloky tisku dokladů v `docs.core`) → Twig v sandboxu → `RenderClient`; katalogy překladů, jazyk tisku podle partnera (`PrintPartyProvider` + `DocumentLanguageResolver`, #94), jazyky tisku `cs` / `en` / `sk` / `de` (kompilace konfigurace pro jazyky dokumentů, formáty přes `ext-intl`, tiskové zkratky jednotek, přepínač v náhledu, jak přidat jazyk), QR platba, vodoznak storna (`watermarks` → `meta.watermark`), nepovinné strany, tisky faktury / zálohové faktury / pokladního dokladu / prodejky a interní Kontace, REST `/_prints`, CLI `print-run` (`--format=html`, `--data` pro vývoj šablon), generická akce Tisk v detailu vieweru, jak přidat tisk; **odesílání e-mailem** (§9, #90 D34–D45): `sendPurpose` + `recipientPerson` v deklaraci, účely kontaktů (`sendPurposes` v module.jsonc → cfgItem `base.persons.sendPurposes`), `RecipientResolver`, `RecordSendService` (`prepare` / `send`), šablony `email-*.txt.twig`, REST `send-draft` / `send`, CLI `print-send`, akce Odeslat a sekce Odeslaná pošta v detailu; **vzhled a texty na tiscích** (§12, #90 D46–D52): modul `core.prints` — stránka nastavení Tisky (akcentová barva hlavičky, umístění loga → `branding`, jen záhlaví), tabulka `core_prints_texts` a agenda Texty na tiscích, sloty (`PrintTextSlot`, `textSlots` v deklaraci), výběr ke dni tisku (`PrintTextResolver` přes `PrintTextProvider`), zpracování Twig sandbox `userTexts()` → `MarkdownEscaper` → Markdown → `texts` (`|raw` jen pro `texts.*`), přepis předmětu a těla e-mailu, proměnné (`textVariables`, `GET /_prints/text-variables`) |
| `docs/ds-state.md` | Stavy zdroje dat — lifecycle + maintenance overlay, `config/state.json` (fail-closed), 503 `DS_UNAVAILABLE`, cron gating `JOB_ALLOWED_STATES`, `shpd-ds ds-state`; fáze 2: `ReadOnlyPolicy` (403 `DS_READ_ONLY`, fail-closed per routa), MCP read-tier, SPA `StatusScreen` + banner, D8 `ds-state-check`; fáze 3 (hosting) dle #56; **přepínač periodických úloh** per DS (#115 fáze 1, §8): boolean `cron` v `state.json` jako třetí nezávislá osa (chybí = zapnuto, jiný typ = fail-closed), dispatcher DS s `cron: false` přeskočí celý vč. úklidu (čítač `cronDisabledDataSources`), `cron --slot --ds=<id>` = jeden tik bez zámku a heartbeatu, `ds-state cron --on\|--off`, `ds-create --no-cron`, `shpd-server ds-cron` (přehled + hromadně), viditelnost: doctor (produkce varování), dev dashboard, `CronOffBanner` přes `/_app/info` `cronEnabled`; **dispatcher respektuje aktivní moduly** (#115 D15): mapa `CronCommand::JOB_MODULES` (každá úloha ze `SLOT_JOBS` má záznam, test), `Core\Server\DataSourceModules`, pořadí kontrol přepínač → stav → moduly, vyřazená úloha se nepočítá, neurčitelné moduly = pustit vše |
| `docs/assets.md` | **Majetek** — design modulu `economy.assets` (D1–D46): rozbor starého `e10pro.property`, ledger hodnotových událostí, engine odpisů s pravidly per země, automatické zaúčtování, import. Fáze 1 (karta, typy, účetní skupiny, inventární čísla, `AssetDocument` + `AssetNumberAllocator`, seed skupin), 2a (pravidla daňových odpisů `world.assets` + čistý engine `DepreciationPlanner`, §5.1–5.2), 2b (události `AssetEventDocument` + lock provider, `AssetPlanService`, plán a akce na kartě, `DepreciationRunService` „Odpisy za období“, §5.3) a 3 (zaúčtování: `AssetPostingService` — jeden `cmnbkp` za období rovnou V pořádku, operace `asset.*`, dimenze deníku `asset`, zámky události a dokladu, řada z nastavení, §5.4) a 4 (vazba na doklady: karta na řádku pořízení `purchase.asset` — `rowAsset: "optional"`, založení karty z řádku, `AssetAcquisitionService`, pole Majetek na hlavičce a řádcích dokladů podle nastavení *Dimenze na dokladech* (`economy.accounting.dimension.asset`, #110 D21 — nahradilo `economy.assets.trackExpenses`), tab Náklady a výnosy, dva alerty, §5.5) a 5 (přehledy: pět reportů `economy.assets.*` nad evidencí — `AssetReportSupport`, `AssetPlanService::plansFor`; kontrola evidence × deník `AssetJournalCheck` jako report, dva alerty a varování na kartě; drill-down, §5.6) a 6 (import, nový Shipard: formát `shpd.assets.asset.v1` + `AssetImportApplier` s importním módem dokumentů (`_import`), doplnění karty na doklady `AssetDocLinkService` s pojistkou obratů, `assets-import-verify`, importované události zaúčtované mimo modul — D76, účet pořízení v kontrole jen varováním — D73, §5.7; runner ve starém Shipardu navazuje dle §6) hotové; **datová sada** (#131 fáze 2c: sekce `assets/` obálka `shpd.dataset.asset.v1`, seed přes dokumenty bez importního módu, dump bez zaúčtování — `modules/economy/assets/src/Dataset/`, `docs/datasets.md` §1) hotová; oblasti 7–8 dle §7 |
| `docs/exchange-rates.md` | **Kurzy cizích měn** (#121 task A, D1–D7, D10, U1–U7): typy lístků `world.cz.exchangeRateLists` (`maxAgeDays`), formát `shpd.exchangeRates.list.v1`, modul `economy.exchangeRates` — tabulky `economy_exchange_rates_lists` / `_values`, `ExchangeRateListImporter` (jediný zápis: created / skipped / replaced / conflict), `ExchangeRateResolver` (poslední lístek typu ≤ datum bez ohledu na měnu, `maxAgeDays` / `validTo`, další typ místo staršího lístku, domácí měna 1, memoizace; datum = účetní datum — u přijaté faktury DUZP, `AccountingDateRule` v docs.core, #103 D78), kurz ve formuláři dokladu (`DocsHeadsFormBase::applyExchangeRate`, hint `_exchange_rate_hint`, doklad v domácí měně má kurz 1), v applieru (`info` / `warning`, import mód nedohledává), v bance (`resolveExchangeRates` před transakcí, chybějící lístek zastaví výpis, pole Kurz ve formuláři transakce), CLI `exchange-rates-import` / `exchange-rate`, datové sady (sekce `exchangeRates/`, dump jen použitých lístků), viewer Kurzovní lístky v Nastavení; **task B** (`tasks/exchange-rates-download.md`, D2, D3, D9, U1–U7): neznámá měna při importu vyřazena (`skippedCurrencies`, otisk po vyřazení), `download` u typů (`Download\ListDownloadConfig`), `exchangeRates.serviceUrl` v `server.json` (`ServerConfig::getExchangeRatesServiceUrl`, prázdné = vypnuto), `ExchangeRateServiceClient` nad vyměnitelným transportem, `ExchangeRateDownloader` (okno od předchozího fiskálního roku, dotazy po měsících od nejnovějšího, limit 6, pauza 1 h a hodina po chybě ve stavu `economy.exchangeRates.downloadState`, dnešní lístek každý běh, přítomné datum se ignoruje bez porovnání, nic nechybí = žádná síť ani log), CLI `exchange-rates-download` + úloha `five-minutes` před `alerts-run`, upozornění `economy.exchangerates.list_missing` (`Checks\ListMissingCheck`, jen u typu s lístkem), §9 |
| `docs/einvoice.md` | **Strukturovaná e-faktura ISDOC** (#119 fáze 1, D1–D10): generátor `Isdoc\IsdocWriter` jako čistá funkce canonical → ISDOC 6.0.1 (rozhraní `EInvoice\EInvoiceWriter`, registr `EInvoiceFormats`, volby `EInvoiceOptions` + `CountryNames`, `EInvoiceInputException` se všemi cestami, `EInvoiceUuid` UUIDv5), vstupní kontrakt (papírová projekce + `rows[].computed`, jen Kč, typy 1 / 4, obě strany s IČO; doklad bez DPH bez DUZP), XSD 6.0.1 / 6.0.2 v `modules/core/exchange/schemas/isdoc/` + `IsdocSchemaValidator`, čtečka `IsdocReader` čte zpět přenesení, režim DPH, slevu, `Note` → `notes.onDocument` i `docText`, způsob úhrady; CLI `shpd-server einvoice-render`; ISDOC v korpusu (18 souborů, D10) a round-trip `IsdocCorpusRoundTripTest` s vyjmenovanými výjimkami; ISDOC jako druhá příloha zpráv v datových sadách a evaluace zpráv s ISDOC produkční cestou — stav `notCarried`, `IsdocFieldCoverage` jako jediný zdroj polí, která ISDOC nenese, rozpad `byPath` (#103 D75–D76, D82, `tasks/testing-rates-isdoc.md`); fáze 2 (vložení do PDF, příloha e-mailu, výběr formátu) v § 9 |
| `docs/work-orders.md` | **Zakázky a periodická fakturace** — design modulu `economy.workOrders` (#110, D1–D24): čtyři typy zakázek na druhu, číselné řady se společným enginem čísel, nadřazená zakázka, evidence vyfakturovaných období, přispěvatelé obsahu faktury; středisko, zakázka a majetek jako **standardní dimenze jádra** (sloupce v tabulkách jádra, deklarace v `economy.accounting`, nastavení „Dimenze na dokladech“). Task 1 (`tasks/dimensions-core.md`: majetek a středisko jako dimenze jádra, nastavení *Dimenze na dokladech*, objekt `dimensions` ve výměnném formátu a střediska v datových sadách), task 2 (engine čísel) a **fáze 1 zakázek** (`tasks/work-orders-phase1.md`: modul `economy.workOrders` v `install.base` — druhy s typem přes `WorkOrderTypes`, řady nad `SequenceCounter`, `WorkOrderDocument` s vlastní sadou stavů Ukončeno / Zrušeno, nadřazená zakázka, zakázka jako třetí standardní dimenze `workOrder`, detail s Deníkem; §5.3 „Hotovo ve fázi 1“) a **fáze 2 — periodická fakturace** (`tasks/work-orders-phase2.md`: předpis `inv_*` druh → zakázka → řádky, `InvoicingSettingsResolver`, evidence období `economy_work_orders_periods`, `PeriodCalendar` / `PeriodLabel` / `InvoiceBuilder` → kanonický doklad přes `DocumentApplier`, `InvoicingRunService` + CLI `work-orders-invoice-run` + cron `daily`, tab Fakturace s akcemi Vystavit dlužná období / Přegenerovat (`replaceConcept`) / Obnovit, přispěvatelé `workOrderInvoiceContributors`, upozornění `economy.workorders.*`; §5.5 „Hotovo ve fázi 2“) hotové 2026-10-08, oprava řádků předpisu podle stavu zakázky (`tasks/work-orders-rows-readonly.md`: bez `independentRows`, `WorkOrderRowLockProvider` nad `economy_work_orders_rows` — V pořádku jen ke čtení, úprava přes V opravě; generické CRUD create lock providery nehlídá) 2026-10-09; **import zakázek** (`tasks/work-orders-import.md`: formát `shpd.workOrders.workOrder.v1` — `WorkOrderImportVerifier` (kontrola payloadu před zápisem, cesty nálezů) + `WorkOrderImportApplier` v `modules/economy/workOrders/src/Import/`, `POST /_exchange/workOrders/workOrder/{validate|apply}` admin / API klíč; hlavička v Konceptu → řádky → cílový stav přes V pořádku, převzaté číslo + `_importSequence` srovná čítač řady, existující číslo = `skipped`, `validate` = průběh s rollbackem; kontrakt `docs/exchange-format.md` § Zakázky) hotový 2026-10-09; fáze 3 (úrovně V pořádku, automatické odeslání) v §7 |
| `docs/mail/outbound.md` | Odchozí pošta — fronta (`core_mail_outbox` + log), per-sender SMTP transporty, relay konfigurace `mail.relay`, `MailOutboxService`/`TransportResolver`, víc příjemců a kopie (`AddressList`), odesílatel záznamu (`SenderResolver`, `recordSenderProviders`, povolené adresy), posluchač výsledku transportu (`OutboxSourceListener`), **pojistka odchozí pošty** (`mail.safety` v server.json — `MailSafetyConfig`, `MailSafetyGuard`: redirect / allowlist / drop / off, fail-closed výchozí stavy, stopa `safety_action` + `[TEST]`, `doctor`, `/_app/info`, `MailSafetyNotice`; #95), cron worker, password endpoint, runbook |
| `docs/overview.md` | **Přehled firmy** — obrazovka „jak na tom firma je" (#120 D1–D19): registrace `overviewBlocks` v `module.jsonc` → cfgItem `core.overview.blocks`, sekce `core.overview.sections` (`core.system`), `src/Core/Overview/` (`OverviewBlock`, `OverviewContext`, `OverviewItem` / `OverviewMessage` / `OverviewBlockResult`, `OverviewBlockRegistry`, `OverviewCollector` s izolací per blok), `GET /_ui/overview` jen pro adminy (401 / 403 v `OverviewController`, read-only Allow), bloky banka (`BankBalancesBlock`, práh 35 dní) / pokladna (`CashBalancesBlock`, `JournalAccountBalance`) / pohledávky a závazky (`ReceivablesBlock`, `PayablesBlock` nad `CaseTotals` — jeden výpočet s patičkou vieweru případů, filtry `currency` / `dueWithin`) / DPH (`VatBlock`, `VatPeriodSelector`, `VatPositionReader`, termín `FilingDeadlines` → `CzFilingDeadlineRule` + `world.cz` `CzechHolidays`) / výsledek (`ProfitLossBlock`, klíče řádků `result` / `costs` / `revenues`), pojistka počátečních stavů `OpeningSequenceStatus`, texty `*.overviewTexts` (`FeedTexts`), panel `companyOverview` + `CompanyOverview.svelte`, `formatMoney`, jak přidat blok, odchylky od zadání |
| `docs/mail/sent.md` | **Odeslaná pošta** — evidence odeslaných zpráv nad libovolnou tabulkou (`core_mail_sent_messages`, #90 D40–D45): pevný obsah (read-only stavy, `systemManaged`, `SentMessageDocument`, strážce příloh), stavy 40 / 70 / 90, `SentMessageTransport`, propsání výsledku z fronty vč. štítku pojistky (`safety_action`, #95), Odeslat znovu, agenda a formulář zprávy, bezpečnost testování; **import** (`POST /_mail/sent/import`, #104 — `SentMessageImportService`, `import_ref`, `send_trigger = import`, stav `unknown`, 409 `IMPORTED`) |

## Architektura — rychlý přehled

```
src/
├── Command/                    # CLI příkazy (Symfony Console)
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
- Server config: `/etc/shipard/server.json` (práva 0600)
- Data sources: `/opt/shipard/data-sources/{id}/config/main.json` (práva 0600)
  — **bez tajemství** (#126 D1–D4): heslo k databázi, heslo relay pošty
  a `clientSecret` OIDC providerů žijí v `secrets/` jako `db.password`,
  `mail-relay.password`, `oidc-<id>.client-secret` (líné čtení
  `DataSourceSecrets`, zápis jen `SecretsFileWriter`, ruční zadání
  `shpd-ds ds-secret`; krok 0 `ds-upgrade` je z `main.json` přesune — do
  tasku B2 má hodnota v `main.json` přednost a `doctor` ji hlásí). Nové
  tajemství = nový soubor v `secrets/`, nikdy klíč v `main.json`.
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
- Moduly v `modules/{skupina}/{modul}/`, ID v tečkové notaci (`economy.docs`)
- Definiční soubory: `.jsonc` (ručně psané), `.json` (generované)
- Vícejazyčnost: suffix `:lang` (`"name:cs": "Název"`), holé pole povinné jako fallback
- Fallback: požadovaný jazyk → `en` → holé pole
- Závislosti: jednoduché (bez verzí), tranzitivní, cyklické = chyba
- Extensions: jen přidávání sloupců/indexů do cizích tabulek
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

### Kódové konvence
- Datové třídy (*Definition): readonly, factory `fromArray()`, validace v konstruktoru
- Příkazy: Symfony Console, testovatelné přes subclassing
- Žádná business logika v datových třídách

### Dokumentový systém
- Data vždy jako PHP `array` — žádné property per sloupec (kvůli extensions)
- Hooky: `validate()` → `beforeSave()` → DB → `afterSave()`, `beforeDelete()` → DB → `afterDelete()`, `onLoad()`
- `validate()` vrací `ValidationResult` s chybami (column + message + code), pro UI focus
- Chyby bez vazby na pole (form-level): `column = ValidationError::FIELD_FORM` (`'_form'`) — frontend je vykreslí v banneru formuláře, ne vedle inputu. Kontrakt `field` viz `docs/edit-forms.md` sekce 8
- Chyby v řádcích: tečková notace `rows.0.unit_price`
- Hlavička + řádky: vždy v jedné DB transakci
- **Generované doklady** (periodická fakturace, později faktura ze zakázky / ze zálohy) vznikají i se přegenerovávají **výhradně přes výměnný formát a `DocumentApplier`** (`applyOptions.replaceConcept` = přegenerování konceptu v místě, `numberSeriesId` = řada podle id; applier ukládá v `NestedTransaction`, takže volající smí apply zabalit do své transakce) — žádné skládání hlavičky a řádků mimo applier. Známé výjimky (#112): `VatReturnAccountingService`, `AssetPostingDocuments`. Viz `docs/document-system.md` §12b
- Child tabulky sync: bez `id` = INSERT, s `id` = UPDATE, chybějící = DELETE
- **Zámek záznamu** (`documentLockProviders: [{table, class}]` v `module.jsonc`, #55 D24): providery jedou na `DocumentRegistry` (plní `DocumentLoader`), vynucuje `TableGateway` po `validate()` (chyba `_form` kód `locked`), `deleteDocument` (`DOCUMENT_LOCKED`), generické CRUD, `DocStateTransitionFilter` (žádné přechody) i `reaccount`. Import mód (`Document::isLockExempt`, `_importNumber`) providery nevolá; `_forceUnlock` jen CLI + log. UI dostává `lock {locked, reasons[]}` v `doc_states` / `detail`. Zamykatelná entita = `locked` + `locked_at` + `locked_by` (`LockStamp`, `CurrentUser`). Viz `docs/document-system.md` §16
- **`created_by` a autor dokladu** (#93): auditní `created_by` plní `TableGateway::saveDocument` z `CurrentUser` — jen insert, jen tabulka se sloupcem (gateway k tomu potřebuje `tableDef`), a **přítomný klíč (i `null`) se nepřepisuje**; strojový kontext nechává NULL. Doklady `created_by` nemají — nesou editovatelný `author` („Vystavil“ na tisku), jehož výchozí hodnotu při vzniku určuje `DocAuthorResolver` (docs.core): explicitní klíč → přihlášený ne-systémový uživatel → `auto_author` číselné řady → nastavení `docs.autoAuthor` → NULL. Exchange: `applyOptions.author`. Viz `docs/document-system.md` → Kdo plní `created_by` / Autor dokladu
- Document třídy: registrace v `module.jsonc` (`documentClasses`), polymorfismus přes `typeColumn`
- **`documentEventHandlers` / `stateChanged`**: handler dostane událost změny stavu, **jen když Document třída tabulky přechod sama naplní** (`$this->stateTransition = [old, new]` v `beforeSave` — sdílený helper `Document::trackStateChange`, volat jako první; gateway nic nedopočítává) — jinak se nikdy nezavolá a nic to nehlásí (učení pravidel odesílatelů tak od července neběželo). U nové registrace ověřit integračním testem přes `TableGateway`; unit test, který handler volá napřímo, to nepokryje. **Insert rovnou mimo Koncept** (import, „Vystavit a uzavřít“) dává přechod `0 → X` — handler, který reaguje na vstup do stavu, musí počítat i se starým stavem 0, jinak tuhle cestu tiše mine (učení pravidel štítků z „Vystavit a uzavřít“ tak od srpna neběželo). Viz `docs/document-system.md` §8b
- PHP třídy modulů: `modules/{skupina}/{modul}/src/`, namespace `Shipard\Module\{Skupina}\{Modul}\`

### Editační formuláře — `select` vs `lookup`
- **`select`**: enumy (`enumInt`/`enumString`) a malé cfgItem-based číselníky. Options se předají v `FormDefinition`.
- **`lookup`**: FK na velké tabulky (Osoby, Adresy, Položky…) s typeahead vyhledáváním. Klient volá `GET /_ui/lookup/{table}/search`, server pre-resolvuje vybrané hodnoty do `dataResolved`.
- Registrace lookup tříd v `module.jsonc` → `lookups: [{table, class}]`; konkrétní implementace dědí z `Shipard\Core\Form\Lookup\TableLookup`.
- Cascade filtery (např. partner → adresy partnera) jdou přes existující `recalculate` flow; žádný extra mechanismus.
- Lookup pole podporují inline **edit** (tužka u vybrané hodnoty) a **create** („+ Vytvořit nový“ v dropdownu) přes vnořený `FormDialog`. Opt-in přes `editForm`/`createForm` flagy v lookup definici. Přidaný `editTriggers` flag (default false) ovládá, zda edit detailů triggerne recalculate v rodiči — zapíná se tam, kde edit propisuje data zpět do rodiče (položka → řádek). U Partnera vypnutý, jinak by edit detailů vynuloval adresu/banku přes destruktivní cascade.
- Flag `createDefaults` (opt-in): „+ Vytvořit nový“ se předvyplní podle formuláře, ze kterého se zakládá — `TableLookup::createDefaults($parentRow, $parentHead)` + `POST /_ui/lookup/{table}/create-defaults`; data rodičů bere `LookupInput` ze Svelte contextu `form/formContext.js` (vzor `AssetsLookup` — karta majetku z řádku faktury).
- `lookup` element **nelze** umístit do `inline` skupiny.
- Detailně viz `docs/edit-forms.md` kapitola 22.

### Editační formuláře — sub-tabulky
- Tab typu `subtable` (`TableForm::subtableTab()`) zobrazuje child tabulku; **sloupce
  i buňky definuje server** přes `GET /_ui/form/{parentTable}/subtable/{tabId}/{parentId}`
  → `TableForm::renderSubtable(FormTab $tab, array $rows, array $parentData)` na
  **rodičovském** formu. Default z `TableDefinition` dětské tabulky (`setTables()`),
  overridy: `DocsHeadsFormBase` (řádky dokladu), `AccountingDocsForm` (kontace),
  `PersonsForm` (kontakty / adresy / účty).
- Formát čísel, částek a dat v buňkách **jen** přes `SubtableCellFormatter` — nepřidávat
  další privátní `formatMoney()`. Boolean labely z cfgItem `core.system.formDefaults`.
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
  `DocHeadVatContext::vat_rate_date` (sazba k datu vystavení),
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
- `shpd-server`: `version`, `help`, `ds-create --name [--module] [--ds-id] [--no-cron] [--real-copy] [--purpose=<text>]` (třída dat v `main.json`: ve vývoji `free`, s `--real-copy` a v produkci `realCopy`; `--no-server-ai` je zastaralý alias `--real-copy`, #126 D7), `ds-delete <id> [--dry-run] [--yes]` (tvrdé smazání **volného** zdroje dat mimo produkci: granty ostatních účtů z `mysql.db` → databáze → DB uživatel → adresář; potvrzení opsáním ID, `--yes` pro úlohu dashboardu; reálná kopie bez `ds-class set free`, režim `production` i běžící úloha dashboardu = odmítnuto, žádný `--force`; opakovatelné po částečném selhání; #103 D68–D69, `tasks/ds-delete.md`), `ds-list [--json]` (přehled zdrojů dat: třída, účel, původ, země, moduly, cron, stav, ve vývoji serverové AI — jediný zdroj přehledu i pro Clauda, bez tajemství, #126 D10), `dev-ai-key --set-key|--status` (serverový AI klíč `/etc/shipard/secrets/dev-ai-<platforma>.key` pro vývojové DS s třídou `free`; jen režim `development`), `dev-update-check [--json]` (zastaralost checkoutu vůči `stable` na GitHubu: anonymní HTTPS fetch z pevné URL do `refs/remotes/origin/stable` / `refs/shipard/upstream/stable` podle `origin`, cache `/opt/shipard/run/dev-update/state.json` pod `flock`, pojistky počítané při každém volání `notStable` / `dirty` / `notFastForward` / `jobRunning` / `missingTool` / `notChecked`; jen `development`, #129 D16–D20), `dev-update [--yes] [--job=<id>]` (kontrola + pojistky → `git -c core.hooksPath=/dev/null merge --ff-only` → `scripts/dev-update.sh` → `ds-upgrade-all` → `cron-install --check` jen varuje → nový `dev-update-check`; po merge žádná nová třída, kroky jako podprocesy s pevným `PATH`; `--job` = vlastní úloha dashboardu; jen `development`, #129 D21–D22), `server-init`, `cron-install [--dry-run | --check] [--keep-ds-cron]` (generátor `/etc/cron.d/shipard`; první instalace mimo produkci vypne cron všem existujícím zdrojům dat a vypíše je, #115 D10; `--check` = exit 0/1 bez výstupu a bez roota pro skripty), `next-table-id`, `einvoice-render --format=isdoc --input=<canonical.json> --output=<soubor> --uuid-name=<text> [--issuing-system=<text>]` (ISDOC z canonicalu bez zdroje dat; ověření proti XSD 6.0.1 i 6.0.2, atomický zápis; exit 1 vstup / 2 schéma, nic se nezapíše — `docs/einvoice.md`, #119 D9), `hosting-sync [--dry-run] [--stats]`, `ds-state-check` (daily D8), `ds-cron [--on|--off] [--all | <id>…]` (přehled ID / Name / Cron / State a hromadný přepínač periodických úloh, #115), `doctor`, `cron --slot [--ds=<id>]` (`--ds` = jeden tik pro jeden DS: přepínač ignoruje, stav respektuje, bez zámku a heartbeatu, exit podle úloh)
- `shpd-ds` (z adresáře DS): `version`, `help`, `ds-upgrade`, `ds-setting list|get <key>|set <key> <value> [--unset]`, `ds-state [show|set <state> [--delete-after] [-y]|maintenance --on [--reason]|--off|cron --on|--off]` (`docs/ds-state.md`; `cron` = přepínač periodických úloh per DS, #115), `ds-class [show|set free|realCopy [--purpose=<text>]]` (třída dat v `main.json`; `set free` jen v interaktivním terminálu — z Claude Code ani MCP mostu neprojde, #126 D8), `ds-secret [list|set mail-relay.password|set oidc-<id>.client-secret]` (tajemství zdroje dat v `secrets/`; hodnota jen ze skrytého dotazu nebo stdin, nikdy z argumentu; `db.password` nastavit nejde, #126 U4), `ds-secrets-health`, `ds-secrets-rotate [--dry-run]`, `alerts-run [--check=id|--all]`, `alerts-prune [--days=N] [--dry-run]`, `report-run <reportId> --fiscal-year --month-from --month-to | --period <id instance> [--detail] [--param id=hodnota]… [--pretty] [--format=json|xlsx|csv] [--output=<soubor>]` (JSON na stdout; xlsx/csv = export, xlsx vyžaduje `--output`), `report-diff <fileA> <fileB> [--strict] [--json]` (exit 0/1/2), `print-run <printId> [<recordId>] [--format=json|pdf|html] [--language=cs|en|sk|de] [--output=<cíl>] [--data=<PrintData.json>]` (tisk nad záznamem; JSON = `PrintData` na stdout, pdf vyžaduje `--output`, html `--output=<adresář>`; `--data` = render hotových dat bez databáze a builderu), `print-send <printId> <recordId> [--to=<adresa>]… [--cc=…]… [--from=…] [--language=…] [--dry-run]` (odeslání záznamu e-mailem: zpráva v Odeslané poště + řádek fronty; `--dry-run` = návrh jako JSON; bez `--to` jdou příjemci z kontaktů — povinné jen na neprodukčním serveru s vypnutou pojistkou `mail.safety`), `vat-periods-ensure [--date=YYYY-MM-DD]` (denní cron, instance tvrzení), `vat-filing-compose --period <id instance> [--kind=regular] [--date-found=YYYY-MM-DD] | --recompute <id podání>` (podání DPH), `vat-filing-import --period=<id> --type=return|cs|rs [--kind=…] [--xml=<soubor>] [--attach=<soubor>]… [--dry-run]` (import starého podání z původního XML; `--dry-run` = rollback), `doc-reaccount <docId> [--force]` (přegenerování deníku; `--force` obejde zámek období a zaloguje), `assets-import-verify [--asset=<číslo>] [--json]` (ověření importu majetku: zlatý test daňových odpisů, účetní okruh × deník od prvního roku s vazbou, kontrola evidence × deník po letech do dnešního roku, uplynulá doba účetního odpisování jen jako upozornění; exit 1 s rozdíly), `work-orders-invoice-run [--date=YYYY-MM-DD] [--work-order=<číslo>] [--dry-run]` (běh periodické fakturace — koncepty za splatná období; denní cron; `--work-order` bez pojistky dohánění, `--dry-run` nic nezapíše), `exchange-rates-import <soubor|složka> [--replace]` (nahrání kurzovních lístků `shpd.exchangeRates.list.v1`; shodný lístek beze změny, jiný obsah = konflikt, `--replace` přepíše; `docs/exchange-rates.md` §6), `exchange-rate <měna> [<datum>]` (kurz k datu podle resolveru, nebo důvod per typ lístku, proč nenalezen), `exchange-rates-download [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--type=<id>] [--dry-run]` (stažení chybějících kurzovních lístků ze služby — cron `five-minutes`: okno od předchozího fiskálního roku, nejvýš 6 dotazů, pauza 1 h na měsíc, nic nechybí = žádná síť; `--from` / `--to` ruční okno bez limitu a pauzy; prázdné `exchangeRates.serviceUrl` v `server.json` = vypnuto, exit 0; `docs/exchange-rates.md` §9), `accbal-match --all|--partner=<id>|--fiscal-year=<id> [--dry-run]` (dávkové přeúčtování clearingu), `accbal-regenerate --all|--doc=<id>|--fiscal-year=<id> [--dry-run]` (hromadná re-derivace saldo pohybů z deníku), `mail-outbox-run [--limit=N]`, `mail-outbox-retry --id N`, `mail-send-test --to x@y… [--cc x@y]… [--from ...]`, `mail-target-backfill [--dry-run] [--limit=N] [--batch=N]` (partner + titulek navázaných zpráv z dokladu), `mail-analyze --message <id> | --sweep` (AI analýza došlé zprávy v procesu, #85 D9: runner `modules/core/mail/src/Analysis/` — spawn po příjmu / nahrání / předzpracování / reanalýze, minutový sweep pro frontu bez claimu; runner po zprávě **dobírá frontu** — každou nejvýš jednou za proces, rozpočet 10 min, pak nástupce (D25); pád po claimu i třetí vypršení claimu za hodinu končí stavem 70 (D26); limit souběhu `ai.analysis.maxConcurrent` v `server.json`, výchozí 2, `0` = vypnuto; selhaná analýza není chyba příkazu), `ai-backend-set-key [--backend <kód>] [--api-key <klíč>] [--base-url <url>]` (klíč AI připojení skrytým vstupem nebo ze STDIN, `--api-key` jen pro provisioning agenta; `ai-analyzer-set-key` je alias na jedno vydání, #85 D21; model a ladění per AI funkce v Nastavení, `docs/ai.md` §5.3), `ai-test [--function <id>] [--json]` (test AI funkcí: rozhodnutí resolveru + minimální volání per funkce — připojení, model, čas, tokeny, cena, nebo věta chyby jako v detailu funkce; exit 1 při selhání; stojí zlomky centu, jen ručně; `docs/ai.md` §5.7), `ai-eval <sada> --variant <soubor> [--only] [--limit] [--repeat] [--save-raw] [--out] [--yes] [--json] [--estimate] [--max-cost <USD>] [--progress-file <soubor>]` (evaluace AI analýzy nad datovou sadou; `--estimate` = jen preflight a odhad bez běhu, `--max-cost` = strop útraty → běh uložený jako neúplný `stoppedBy: maxCost`, `--progress-file` = JSON řádky průběhu pro úlohu dashboardu, SIGTERM = zrušení bez dalšího volání, exit 130, #103 F6b D61, #103 F4: analýza nanečisto přes `AnalysisComputation`, porovnání s očekáváním po polích, soubor výsledků `tests/eval/<sada>/`; jen `development`, čistě naseedovaný zdroj dat; stojí peníze; `docs/test-corpus.md` § 18), `ai-eval-compare <A.json> <B.json> [--json]` (dva běhy vedle sebe: rozdíly metrik, šum opakování, pole zlepšená / zhoršená — součty a odchylky per uzel přepočtené na jedno opakování, změna pole od 0,5 volání; různé sady odmítne), `booking-history --input <soubor> [--report] [--apply-seed] [--tag-items[=offer|usage|auto]] [--backend <id|název>] [--model <ID>] [--model-like <ID>] [--no-llm] …` (historie účtování: report, seed pravidel, otagování položek; `--backend` / `--model` přepisují AI funkci `exchange.bookingHistory`), `registry-extract-texts [--all] [--limit=N]`, `dataset-dump <dir> [--zip] [--force]`, `dataset-seed <dir|zip> [--no-reset] [-y]` (reset + import sady, `docs/datasets.md`), `user-create [--password|--password-stdin] [--if-not-exists] [--identity-issuer/-subject/-provider]` (bez hesla → poslat pozvánku; `--password-stdin` = heslo z prvního řádku stdin, nikdy v argv — používá formulář Nový zdroj dat, #103 D65), `user-set-admin`, `auth-emergency-login`, `hosting-oidc-init`, `hosting-oidc-client`, `hosting-server-key`, `hosting-router-key`, `hosting-ai-gw-init`, `hosting-ai-token`, `hosting-stats [--json]`

### Frontend — ikony
- Font Awesome SVG/JS, tree-shaking přes Vite
- Centrální registr: `frontend/src/icons.js` — pojmenování podle významu (`iconAdd`, `iconUser`, `iconListCheck`), ne vzhledu
- Komponenta: `Icon.svelte` (inline SVG), rozšířený `Button.svelte` (prop `icon`)
- Navigace: server posílá `"icon": "klíč"` v JSON, frontend překládá přes `resolveIcon()` s fallbackem `iconTable`
- Nová ikona: import v `icons.js` + export + záznam v `iconMap`
- Viewery dědí default ikonu pro řádky z `module.jsonc` viewers[].icon
  (stejná jako v sidebaru). Per-row override v `renderRow()`
  (např. PersonsViewer podle person_type).

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
  nestaví sám (`content` = přílohy kreslí viewer v obsahu: Došlá
  a Odeslaná pošta, Spisovna). Viz `docs/frontend.md` → Formát detail
  panelu.

### Frontend — Navigace (sidebar)

- Sekce hlavního sidebaru pocházejí z cfgItem **`global.navSections`**
  (`modules/install/base/config/navSections.jsonc`), NE z prefixu module ID.
  Analogie k `global.settingsSections`. `NavigationController` (`GET
  /_ui/navigation`) seskupuje viewery/tabulky podle jejich `navSection`,
  řadí sekce dle `navSections.order` a položky dle `navOrder`.
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
  (`src/dev/i18n/`, cs + en); sdílí jen `styles/`, `icons.js`, `Button`, `Icon`
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
  `var(--x)` bez fallbacku zastaví pre-commit
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
  hodnoty jen z výpisů. **Přehled zdrojů dat** (#103 D70–D71): bez sloupce
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
  Viz `docs/frontend.md` → Vývojářský dashboard, `docs/dev/dev-daily.md` § 1,
  `docs/test-corpus.md` § 18.

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
- Mapování chybových kódů: `frontend/src/i18n/errors.js` `translateError(error)` přeloží `error.code` přes klíče `error.<CODE>`, fallback na server `error.message`

### Backend — Vícejazyčnost server-driven labels

Lokalizace UI textů, které generuje backend (toolbar tlačítka, taby formulářů, taby detailu vieweru), jde **přes cfgItems v jsonc, ne přes hardcoded stringy v PHP**:

- **Toolbar default** (`Add`/`Open`): `core.system.viewerDefaults.toolbarActions` — `TableViewer::getToolbarActions()` z cfgItem; module overrides v `core.mail.viewerDefaults` apod.
- **AutoFormBuilder General tab**: `core.system.formDefaults.generalTabLabel`
- **Detail taby vieweru**: `*.viewerDetailLabels.tabs.*` per-modul (`core.system` má sdílený `overview`, ostatní moduly mají specifické klíče). Helper `TableViewer::detailTabLabel()` / `defaultOverviewLabel()`
- **JSONC form titulky/taby**: `JsoncFormLoader::load($lang)` aplikuje `ConfigLocalizer::localize()` rekurzivně na `:cs`/`:en` varianty v `title`, `titleNew`, `tabs[].label`, `elements[].label` (separátory)
- **Texty karet feedu** (#101 D11): katalog `*.feedTexts` per modul, který kartu vyrábí (`core.mail` / `core.alerts` / `core.exchange`, `config/feedTexts.jsonc`, položka `{text, text:cs, text:en}`), čte `Shipard\Core\Feed\FeedTexts::t(klíč, anglický fallback, params)`. Vzor = ICU MessageFormat jako ve frontendu (plurály `{n, plural, one {# …} few {…} other {…}}`) — uvnitř plurálu **vždy `#`** (PHP intl neumí `{n}` v plurálové větvi), apostrof `''`. Fallback v PHP musí být znak po znaku holé pole katalogu (`FeedTextsCatalogTest` to hlídá). Bez katalogu (DS před `ds-upgrade`) tiše anglicky; warning jen pro chybějící klíč v existujícím katalogu. Viz `docs/dashboard.md` §5
- **Fallback při chybějícím compiled configu**: anglický řetězec přímo v PHP — lokalizace funguje degradovaně, ne crash

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
3. `cd frontend && npm run build` — pokud se měnil frontend (staví aplikaci do `public/app/` i vývojářský dashboard do `public/_dev/`; `npm test` pro čisté funkce; `python3 scripts/check-css-tokens.py` — nedefinované CSS tokeny; `python3 scripts/check-css-units.py` — px v mezerách, zaoblení a písmu; obě hlídá i pre-commit)
4. **Aktualizovat `**Stav:**` v task filu** + `python3 scripts/tasks-index.py`

## Otevřené úkoly

Směr a priority řeší **[`docs/roadmap.md`](docs/roadmap.md)** — tady už se seznam nedrží,
rozcházel se se skutečností. Stav jednotlivých zadání je v `tasks/README.md`
(generovaný souhrn) a v hlavičce `**Stav:**` každého task filu.
