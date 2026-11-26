# Periodická fakturace jako modul se zdroji fakturace (#175 D1–D9)

**Stav:** hotovo — 2026-11-26 ve čtyřech commitech (#175 D1–D9, U1–U6); ověřeno nad demo sadou v headless prohlížeči, ruční proklik naostro zbývá

> Task pro Claude Code (4 commity). Design: issue #175. Je to **refaktoring**:
> chování periodické fakturace zakázek se nemění (D9). Druhým zdrojem budou
> Smlouvy doplňku Budovy (#174, fáze 2) — ty tento task neimplementuje, jen
> pro ně připraví bod rozšíření. Fáze 0 doplňku Budov (#174 D11).

## Kontext

- Engine periodické fakturace žije v `modules/economy/workOrders/src/Invoicing/`
  (#110 fáze 2, `docs/work-orders.md` §5.5) a je svázaný se zakázkami:
  `InvoicingRunService::candidates()` / `workOrder()` / `kind()` / `rowsOf()`
  čtou tabulky zakázek, `InvoiceBuilder::build(array $workOrder, …)` přidává
  dimenzi zakázka, `PeriodRepository::TABLE` je napevno
  `economy_work_orders_periods` se sloupcem `work_order`.
- Upozornění (`src/Checks/*`) joinují tabulku zakázek, `PeriodCheckBase`
  má napevno `SUBJECT_TABLE_ID = 460` a viewer zakázek.
- Záložku Fakturace staví `WorkOrdersViewer::invoicingTab()`; akce
  `workOrderIssueDue` / `workOrderRegenerate` / `workOrderRestore` jsou
  napevno ve `frontend/src/components/viewer/Viewer.svelte`, volají
  `frontend/src/api/workOrders.js` → routy `/_work-orders/…`
  (`src/Api/Router.php`) → `WorkOrdersInvoicingController`
  (`public/index.php`).
- Přispěvatelé: pole `workOrderInvoiceContributors` v `ModuleDefinition`,
  skládá `ConfigCompiler` do cfgItem `economy.workOrders.invoiceContributors`;
  v produkci žádný registrovaný.
- Data: v reálných kopiích ani na testovacím serveru nejsou žádné zakázky
  ani období (ověřeno 2026-11-26). Změny se dotknou jen demo a seedovaných
  sad.
- `ds-upgrade` jen přidává tabulky, sloupce a indexy — proto tabulka období
  per zdroj (D1), ne jedna obecná.

## Rozhodnutí (#175, schválena 2026-11-26)

- **D1** — Evidence období per zdroj: každý zdroj má vlastní tabulku stejného
  tvaru, zakázky dnešní `economy_work_orders_periods`. Společný
  `PeriodRepository` dostane jméno tabulky a sloupce záznamu.
- **D2** — Engine ve vlastním modulu jádra `economy.periodicInvoicing`
  v `install.base`. Přesunou se třídy `Invoicing/*` a cfgItemy periodicit,
  okamžiků fakturace, stavů a textů období; tabulky zůstávají u zdrojů.
- **D3** — Registrace zdroje polem `periodicInvoicingSources` v `module.jsonc`
  (`{id, class}`), skládá `ConfigCompiler`.
- **D4** — Nastavení fakturace ve sloupcích `inv_*` stejných jmen na záznamu
  i druhu (`InvoicingSettingsResolver`, `InvoicingFormOptions` beze změny);
  řádek předpisu má dokumentovaný tvar, zdroj řádky normalizuje.
- **D5** — Přispěvatelé společní pro všechny zdroje; klíč
  `periodicInvoicingContributors`, kontext nese zdroj a záznam.
- **D6** — Jeden běh `periodic-invoicing-run [--source] [--record]`, jedna
  úloha `daily`, `work-orders-invoice-run` na jedno vydání jako alias;
  upozornění jednou pro všechny zdroje, nález s klíčem `zdroj:id`, akce
  otevře viewer zdroje.
- **D7** — Jeden zdroj dokladu „Periodická fakturace“, klíč `workOrder`
  v `docs.core.sourceKinds` zůstává.
- **D8** — Záložku Fakturace staví společná třída; akce jsou `recordActions`
  (#172) se společnými třídami, každý zdroj je deklaruje nad svou tabulkou.
  Ze `Viewer.svelte` zmizí tři napevno zapsaná id, `api/workOrders.js`,
  routy `/_work-orders/…` a `WorkOrdersInvoicingController`. Převod
  stávajících akcí je výslovná výjimka z #172 D19 („stávající akce se
  nepřevádějí“).
- **D9** — Chování Zakázek se nemění; testy se přesunou, přibudou testy
  druhého zdroje.

### Upřesnění v tasku

- **U1 — Normalizovaný záznam.** Zdroj vrací záznam jako pole s klíči, které
  engine čte dnes: `id`, `number`, `title`, `customer`, `currency`,
  `payment_reference`, `date_end`, `inv_periodicity`, `inv_from`,
  `inv_doc_text` a ostatní `inv_*`. Zakázky ho mají 1 : 1 jako řádek
  tabulky. Dimenze dodá zdroj zvlášť (`dimensions()`), ne builder.
- **U2 — Tvar řádku předpisu** = sloupce `economy_work_orders_rows` bez
  `work_order`: `id`, `order_pos`, `item`, `description`, `quantity`, `unit`
  (id jednotky), `unit_price`, `vat_code`, `operation`, `valid_from`,
  `valid_to`, `contributor`. Popsat v README modulu jako kontrakt.
- **U3 — Kontext akce záznamu** dostane nepovinné `?DocumentRegistry`,
  `array $tables` a `?DocumentEventDispatcher` (předá `dispatchRecordAction`
  v `public/index.php` stejně jako dnes `dispatchWorkOrders()`). Akce, která
  zakládá doklady, je bez nich neumí složit; obecné rozšíření #172, ne na
  míru zdroji.
- **U4 — Badge sekce u upozornění.** `AlertFinding` sekci nenese, bere se
  z deklarace checku. Společné upozornění dá do `context.navSection` sekci
  vieweru zdroje (z deklarace `viewers[]`, `navSection`) a `AlertsSource`
  ji upřednostní před sekcí checku. Check sám `navSection` nemá.
- **U5 — Stránka nastavení** *Periodická fakturace* přejde do nového modulu,
  sekce Nastavení **Účetnictví** (`accounting`) — fakturace už není jen
  věc zakázek. Klíč `economy.workOrders.contributorWaitDays` →
  `economy.periodicInvoicing.contributorWaitDays` bez převodu hodnoty
  (žádný přispěvatel neexistuje).
- **U6 — Id zdroje zakázek** `workOrders` (CLI `--source=workOrders`).
  `--record=<číslo>` hledá záznam podle čísla přes zdroj.

## Před implementací přečti

- `docs/work-orders.md` §5.5 (fakturace, D5, D10, D24)
- `docs/modules.md` kap. 5 — pole `workOrderInvoiceContributors`,
  `commands` / `cronJobs`, `recordActions`, `alertChecks`; kap. 9 (jádro
  o doplňku neví)
- `docs/alerts.md` (`AlertCheck`, `AlertFinding`, reconciler)
- `tasks/module-record-actions.md` (kontrakt akce, dialog, výsledek)
- `tasks/work-orders-phase2.md` (pasti fakturace — transakce, zámek období,
  pojistka dohánění, `replaceConcept`, `ContentHash`)

## Kroky

### Commit 1 — modul `economy.periodicInvoicing` a rozhraní zdroje

- Nový modul `modules/economy/periodicInvoicing/` (jádro, závislost
  `install.base`; `economy.workOrders` na něm závisí). Závislosti podle
  použitých tříd (`docs.core`, `core.exchange`, `base.persons`, …) —
  `ModuleKindRules` a `ModulesIndexTest` musí projít, `php
  scripts/modules-index.php`.
- Přesun `src/Invoicing/**` ze Zakázek do
  `Shipard\Module\Economy\PeriodicInvoicing\…` (vč. `Contributor/`). Třídy,
  které znají zakázky, zůstávají v Zakázkách (`WorkOrderTypes`, dataset
  seedery a exporter období).
- cfgItemy `periodicities`, `invoiceTimings`, `periodStates`, `periodTexts`
  pod `economy.periodicInvoicing.*`; reference v tabulkách zakázek
  (`inv_periodicity`, `inv_timing`, `state` období) a v kódu přepsat. Texty
  záložky Fakturace a akcí do katalogu nového modulu.
- Rozhraní `PeriodicInvoicingSource` (+ `AbstractPeriodicInvoicingSource`
  se settery `db` / `config` / `dsConfig` jako u přispěvatelů):
  - `id()`, `table()`, `periodsTable()`, `periodsRecordColumn()`,
    `viewerId()`;
  - `candidates(?int $recordId): list<array>` — záznamy k fakturaci (U1);
  - `record(int $id): ?array`, `recordIdByNumber(string $number): ?int`;
  - `kind(array $record): ?array` — řádek druhu se sloupci `inv_*`;
  - `rows(array $record): list<array>` — řádky předpisu (U2), `order_pos` ASC;
  - `dimensions(array $record): array<string, int>`;
  - `recordLabel(array $record): string` — číslo a název pro běh
    a upozornění.
- `ModuleDefinition` + `ConfigCompiler`: `periodicInvoicingSources` →
  cfgItem `economy.periodicInvoicing.sources` (duplicitní id = chyba
  kompilace, rezervovaný cfgItem jako u přispěvatelů);
  `workOrderInvoiceContributors` → `periodicInvoicingContributors` →
  `economy.periodicInvoicing.contributors`. Registr zdrojů
  (`InvoicingSources::fromConfig`, také `forTable(string $table)` pro akce)
  po vzoru `InvoiceContributorRegistry`.
- `PeriodRepository(Connection, string $table, string $recordColumn)`;
  metody beze změny tvaru, `listFor($recordId)`.
- `InvoiceBuilder::build(PeriodicInvoicingSource $source, array $record,
  ?array $kind, Period, array $rows)` — dimenze ze zdroje, `source.kind`
  dál `workOrder` (D7).
- `InvoicingRunService` dostane registr zdrojů a pro každý zdroj
  `PeriodRepository` jeho tabulky; `RunOptions` místo `workOrderId` nese
  `sourceId` + `recordId`; `RunLine` nese `sourceId`, `recordId`,
  `recordLabel`. `regenerate()` / `restore()` dostanou zdroj (id období je
  jednoznačné jen v tabulce zdroje).
- `ContributionContext`: `source` (id) a `record` místo `workOrder`.
- V Zakázkách `WorkOrderInvoicingSource` (registrace `{id: "workOrders",
  class}`): kandidáti jako dnešní `candidates()` (periodické typy přes
  `WorkOrderTypes`, V pořádku, `inv_from` a `inv_periodicity` vyplněné),
  dimenze `workOrder` + `costCenter`.
- Testy: přesun `tests/Unit/Module/Economy/WorkOrders/Invoicing/*` do
  `…/PeriodicInvoicing/`; **druhý zdroj** jako fake implementace rozhraní
  nad poli (bez tabulky) v testu běhu — dvě evidence období se nemíchají,
  dimenze a `recordLabel` jdou ze zdroje; kompilace registrace (duplicita,
  rezervovaný cfgItem).

### Commit 2 — běh, cron, upozornění, nastavení

- `PeriodicInvoicingRunCommand` (`periodic-invoicing-run [--date]
  [--source=<id>] [--record=<číslo>] [--dry-run]`, `--record` bez
  `--source` = chyba) v novém modulu, `cronJobs` `daily` tam;
  `WorkOrdersInvoiceRunCommand` zůstane jako tenký alias (`--work-order` →
  `--source=workOrders --record`) s poznámkou „zanikne v dalším vydání“,
  bez vlastní úlohy cronu. Výpis běhu se zdrojem.
- Upozornění do nového modulu, id `economy.periodicinvoicing.*` (stejný
  tvar jako dnešní `economy.workorders.*`); `PeriodCheckBase` prochází
  registr zdrojů, nález má klíč `zdroj:id`, `subjectTableId` z definice
  tabulky zdroje, akci `open_viewer` na `viewerId()` zdroje a
  `context.navSection` (U4). `InvoicesToReviewCheck` beze změny logiky.
- `AlertsSource`: `navSection` karty = `context.navSection` nálezu, jinak
  z checku (U4) — test.
- Stránka nastavení podle U5.

### Commit 3 — záložka Fakturace a akce záznamu

- `InvoicingDetailTab` v novém modulu: z dnešního
  `WorkOrdersViewer::invoicingTab()` — vlastnosti předpisu, příští splatné
  období, tabulka období s odkazem na doklad. Bez akcí (ty jsou
  `recordActions`). `WorkOrdersViewer` ji jen zapojí; memo v `detailData()`
  zůstává pro Deník.
- `RecordActionContext` podle U3 (+ `RecordActionController`,
  `TableViewer` při vykreslení detailu může nechat `null`).
- Tři akce v novém modulu, zdroj najdou přes `InvoicingSources::forTable()`:
  - **Vystavit dlužná období** — dostupná u záznamu, který je mezi
    kandidáty zdroje; běh jen pro záznam s `force`; výsledek „Vystaveno
    období: N, selhalo: M“ (`warning`, když M > 0).
  - **Přegenerovat** — dostupná, když má záznam období s konceptem;
    dialog: `select` období s konceptem + `notice` „Ruční úpravy konceptu
    se ztratí.“
  - **Obnovit** — dostupná se zastaveným obdobím; dialog: `select`
    zastavených období; výsledek mimo `issued` = `warning` se zprávou běhu.
  - `\DomainException` z běhu → `RecordActionValidationException` na pole
    období (409 / 422 dnes), ne 500.
- Zakázky deklarují `recordActions` nad `economy_work_orders_heads` (id
  `issueDue`, `regenerate`, `restore`, třídy z nového modulu).
- Smazat: `WorkOrdersInvoicingController`, routy `/_work-orders/…`
  v `Router.php`, `dispatchWorkOrders()` v `public/index.php`, klíč
  `workOrders` v `src/Api/ReadOnlyPolicy.php`, `frontend/src/api/workOrders.js`, tři větve a import ve
  `Viewer.svelte`, klíče `viewer.detail.workOrder*` v `i18n/{cs,en}.js`.
- Testy: akce (dostupnost, dialog, výsledek, chyba období), kontrakt
  detailu zakázky (`ViewerDetailContractTest`, `WorkOrdersViewerTest`),
  `npm run build`, `npm run check:i18n`.

### Commit 4 — dokumentace

- `modules/economy/periodicInvoicing/README.md`: co modul dělá, rozhraní
  zdroje, normalizovaný záznam (U1), tvar řádku (U2), registrace, evidence
  období per zdroj, akce, CLI, upozornění, jak přidat zdroj.
- `docs/modules.md` kap. 5: `periodicInvoicingSources`, přejmenované
  `periodicInvoicingContributors`; `docs/work-orders.md` §5.5 odkaz na
  modul (engine už není v Zakázkách); `docs/cli.md`; `CLAUDE.md` (seznam
  CLI, řádek `docs/work-orders.md`, akce záznamu); `docs/alerts.md`
  (`context.navSection`).
- Nápověda: `help/zakazky/periodicka-fakturace.md` (Přegenerovat a Obnovit
  jako dialog s výběrem období, stránka nastavení v sekci Účetnictví),
  `python3 scripts/help-index.py`.
- `tasks/README.md` — řádek tasku v oddílu Zakázky, `**Stav:**`,
  `python3 scripts/tasks-index.py`; komentář do #175.

## Hotovo když

- [x] `economy.periodicInvoicing` je v `install.base`, Zakázky na něm
      závisí, `modules/README.md` přegenerovaný
- [x] v `modules/economy/workOrders/src/` nezůstala žádná třída enginu ani
      reference na `economy.workOrders.periodicities` / `invoiceTimings` /
      `periodStates` / `periodTexts` / `invoiceContributors`
- [x] běh, CLI (vč. aliasu), Přegenerovat a Obnovit dávají nad zakázkami
      stejné výsledky jako před refaktoringem (přesunuté testy beze změny
      asercí)
- [x] test s fake druhým zdrojem: oddělená evidence období, dimenze ze
      zdroje, akce a upozornění najdou zdroj podle tabulky
- [x] `Viewer.svelte` nezná žádné `workOrder*` id; akce záložky Fakturace
      jdou přes `POST /_ui/record-action/…`
- [x] `ds-upgrade` volného zdroje dat s demo sadou projde, `dataset-seed`
      sady se zakázkami projde a dump se od sady liší jen manifestem
      (`dataset-verify` demo sadu odmítá — nemá očekávání pro poštu;
      ověřeno seed → dump → diff)
- [ ] ručně: zakázka v demo sadě — záložka Fakturace, Vystavit dlužná
      období, Přegenerovat, Obnovit, upozornění s badge v sekci Zakázky
      (proklikáno v headless prohlížeči nad demo sadou it-servis
      2026-11-26; ruční proklik naostro zbývá)
- [x] dokumentace a nápověda podle commitu 4, `**Stav:**` aktualizovaný

## Pasti

- **Transakce běhu se nemění**: zámek řádku období + apply + zápis dokladu
  v jedné `NestedTransaction` (`tasks/work-orders-phase2.md`). Refaktoring
  nesmí apply vytáhnout mimo transakci.
- **Id období je jednoznačné jen v tabulce zdroje** — akce i `regenerate()`
  / `restore()` vždy se zdrojem, nikdy holé id.
- **`ds-upgrade` cfgItem nepřejmenuje v datech**: stavy a periodicity jsou
  uložené hodnoty (`planned`, `month`, …), mění se jen id cfgItem — hodnoty
  zůstávají stejné.
- **Přejmenované id upozornění**: staré otevřené alerty `economy.workorders.*`
  po změně nikdo neuzavře — ověř, co s osiřelými alerty udělá reconciler
  (`docs/alerts.md`), a když zůstanou viset, uklidit je v kroku
  `ds-upgrade` (jen alerty s tímto prefixem).
- **Alias příkazu**: dva příkazy nesmí mít stejné `setName`; alias je
  samostatná třída, ne `setAliases` (jiná volba `--work-order`).
- **Jádro o doplňku neví**: nový modul nesmí jmenovat Zakázky ani Budovy —
  zakázky se napojují jen registrací zdroje, deklarací `recordActions`
  a zapojením `InvoicingDetailTab` ve vlastním vieweru.
- **Seedery a exporter datových sad** období zůstávají v Zakázkách, jen
  používají `PeriodRepository` s tabulkou zakázek.

## Poznámky k implementaci (2026-11-26)

Rozhodnutí nad rámec zadání, potvrzená 2026-11-26 před implementací:

- **R1 — pravidlo namespace rozšíření UI uvolněno.** `ModuleUiExtensionRules`
  dovolilo třídu rozšíření UI jen v namespace deklarujícího modulu, takže
  Zakázky nemohly deklarovat `recordActions` se třídami enginu (D8). Nově smí
  třída ležet i v modulu, na kterém deklarující modul (tranzitivně) závisí —
  stejný dosah jako u tabulek. Obecná změna #172, `docs/modules.md` kap. 5.
- **R2 — jméno záznamu dává zdroj.** Rozhraní zdroje má navíc `recordNoun()`
  („Zakázka“) a `openActionLabel()` („Otevřít zakázku“); titulky upozornění
  a popisek akce zůstaly u zakázek znak po znaku. Zprávy enginu ukládané do
  období jsou přepsané neutrálně („Předpis nemá typ dokladu (záznam ani jeho
  druh).“, „K datu … není žádný platný řádek předpisu.“) — engine zakázky
  nejmenuje.
- **R3 — sekci badge dává zdroj** metodou `navSection()`, ne deklarace
  `viewers[]` (U4): check nemá deklarace viewerů k dispozici. Test Zakázek
  hlídá shodu s deklarací vieweru.

Další odchylky a upřesnění:

- **`tableId()` na rozhraní zdroje** — `subjectTableId` nálezu nejde vzít
  „z definice tabulky zdroje“, check definice tabulek nemá; id dává zdroj
  a test hlídá shodu s definicí tabulky.
- **Tvar `RunLine`**: `sourceId`, `recordId`, `recordLabel` — výpis běhu má
  hlavičku `<číslo a název> [<zdroj>]` místo `<číslo> — <název>`.
- **Alias `work-orders-invoice-run`** běží vždy jen nad zdrojem `workOrders`
  (i bez `--work-order`) a při spuštění vypíše, že zanikne. Pojistka „modul
  zakázek není aktivní“ z něj zmizela — příkaz neaktivního modulu CLI
  nenabídne (#172).
- **Souhrnný nález konceptů ke kontrole** má `context.navSection`, jen když
  se na sekci shodnou všechny registrované zdroje (doklady všech zdrojů mají
  týž druh zdroje, D7); s jediným zdrojem je badge jako dřív.
- **Skupinová karta feedu** (víc než 3 alerty checku) bere sekci z nálezů,
  jen když ji nesou všechny aktivní alerty checku shodně — agregát
  `JSON_VALUE(context, '$.navSection')` v dotazu fáze 1 `AlertsSource`.
- **Osiřelé alerty** `economy.workorders.*` reconciler opravdu neuzavře
  (uzavírá jen alerty běžícího checku) — `ds-upgrade` je převádí do
  Vyřešeno (`LegacyInvoicingAlertsCleanup`), nic nemaže; `docs/alerts.md` § 6.
- **Pořadí denního cronu** drží `order: 600` — běh fakturace zůstává za
  `vat-periods-ensure`, i když ho nese modul dřív v abecedě.
- **Akce v liště detailu** jsou až za akcí vieweru „Otevřít v deníku“ —
  builder detailu řadí akce modulů za akce vieweru (dřív byly akce fakturace
  první). Přegenerovat a Obnovit nově hlásí úspěch zprávou.
- **Texty záložky a akcí** jsou v katalogu enginu
  `economy.periodicInvoicing.viewerLabels` (17 klíčů přesunutých z katalogu
  Zakázek + texty dialogů a výsledků); frontendové klíče
  `viewer.detail.workOrder*` zanikly.
- Konstanty `InvoicingSettings::SOURCE_WORK_ORDER` / `WORK_ORDER_COLUMNS`
  zůstaly (D4 „beze změny“) — přejmenování až s druhým zdrojem.

Ověření: unit sada celá, integrační `ViewerDetailContractTest`
a `DatasetRoundTripTest`; volný zdroj dat s demo sadou it-servis — seed,
dump shodný se sadou, `periodic-invoicing-run --dry-run` ke kotvě nic
nevystaví (alias stejně), v prohlížeči záložka Fakturace a tři akce přes
`POST /_ui/record-action/…`, po vyvolání pojistky dohánění badge v sekci
Zakázky.
