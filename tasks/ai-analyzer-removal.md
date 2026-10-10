# AI — zrušení pull protokolu, registru analyzerů a démona `ai-analyzer` (#85 D9, D11)

**Stav:** naplánováno — rozhodnutí D20–D24 potvrzena 2026-10-10 (#85), připraveno k implementaci

## Status / cíl

Došlou poštu analyzuje `shpd` sám (`tasks/mail-analysis-inprocess.md`,
`tasks/mail-analysis-queue-drain.md` — ověřeno 2026-10-10 včetně nahrání
z Dashboardu pod PHP-FPM). Všechno, co existovalo jen kvůli vzdálenému
démonu, je mrtvý kód a mrtvá provozní zátěž: pull protokol
`/_mail/analysis/*`, systémový uživatel `_ai_analyzer` s API klíčem,
registr analyzerů na hostingu a repozitář `shipard/ai-analyzer`.

Cíl: **odstranit je** a srovnat dokumentaci, aby v systému zůstala jediná
cesta analýzy. Stavový automat analýzy (claimy s lease, reaper) zůstává
(#85 D12).

Souběh nové cesty s démonem se **neověřuje** (rozhodnuto 2026-10-10) —
byl to přechodný režim; na serveru, kde démon ještě běží, se po nasazení
tohoto tasku služba zastaví (runbook v kroku 5).

GitHub Issue: shipard/shipard#85 (D9, D11, D20–D24).

## Návaznost

- `tasks/mail-analysis-inprocess.md`, `tasks/mail-analysis-queue-drain.md`
  — **předpoklad**, hotovo.
- `tasks/ai-models-phase0.md` — provisioner po něm nese i přepis
  vyřazených modelů (`RETIRED_MODELS`); při přejmenování zachovat.
- `tasks/mail-phase3a.md` — původní protokol a provisioning analyzeru.
- `tasks/hosting-10-ai-analyzer.md` — registr analyzerů na hostingu,
  lookup endpoint, `sources-sync`.
- `tasks/hosting-05-ai-gateway.md` — AI gateway; **nemění se**.
- `tasks/ai-provisioning-unconditional.md` — co `ds-upgrade` zakládá.

## Před implementací přečti

- `docs/mail/api-contract.md` celé (§9 se dělí na rušené a zůstávající).
- `docs/hosting.md` — sekce o analyzeru a §5.5 gateway.
- `docs/services.md` — §1, §12, §14.
- `docs/ai.md`, `modules/core/mail/docs/ai-analysis.md`.
- `docs/ds-state.md` — `ReadOnlyPolicy` a routy analyzeru.
- `docs/alerts.md` — jak se píše alert check (vzor
  `core.mail.outbox_health`).
- Kód — inventura níže; **před mazáním ověř grepem**, seznam je momentka:

  ```bash
  grep -rl --include=*.php --include=*.jsonc --include=*.svelte --include=*.js -i -E \
    'ai_analyzers|ai-analyzer/lookup|analyzer_token|hosting-analyzer-key|AiAnalyzersViewer|_ai_analyzer|ai-analyzer-setup|ai-analyzer-bootstrap|ai-analyzer-set-key|AIAnalyzerProvisioner|verifyAnalyzerAuth|validateClaimToken' \
    src modules frontend/src tests
  ```

## Rozhodnutí k designu (potvrzená)

- ✓ **D20 — Pull protokol se ruší naráz.** Routy `/_mail/analysis/queue`,
  `/{ndx}/claim`, `/{ndx}/payload`, `/{ndx}/attachments/{att}/content`,
  `/{ndx}/result`, `/{ndx}/failed` a jejich metody v `AnalysisController`
  mizí bez přechodného období — ostré zdroje dat zatím nejsou. Uživatelské
  akce nad zprávou (`/_mail/messages/{ndx}/reanalyze`, `apply`, `unapply`,
  `reject`, `preview`, `decisions`) zůstávají.
- ✓ **D21 — Uživatel `_ai_analyzer` a příkazy analyzeru.**
  - Provisioning uživatele a jeho API klíče končí; `AIAnalyzerProvisioner`
    se zúží na výchozí backend (včetně přepisu vyřazených modelů)
    a výchozí profil a přejmenuje na `MailAiProvisioner`.
  - Existující uživatel `_ai_analyzer` se při `ds-upgrade` deaktivuje
    a jeho API klíče zneplatní — idempotentně, s výpisem. **Nemaže se**
    (běhy analýz na něj odkazují v `created_by`).
  - Příkazy `ai-analyzer-setup` a `ai-analyzer-bootstrap` zanikají.
  - `ai-analyzer-set-key` se přejmenuje na **`ai-backend-set-key`**
    (nastavuje klíč backendu pro všechny AI cesty); starý název zůstává
    jedno vydání jako alias s upozorněním na stderr. Nový příkaz umí klíč
    načíst skrytým vstupem (vzor `hosting-ai-gw-init --set-key`);
    `--api-key` zůstává pro provisioning agenta.
- ✓ **D22 — Registr analyzerů na hostingu se ruší.** Viewer a formulář
  „AI analyzery“, `AiAnalyzerDocument`, `HostingAiAnalyzerController`
  (`/_hosting/ai-analyzer/lookup`), příkaz `hosting-analyzer-key`, pole
  tokenu analyzeru u zdroje dat a krok provisioningu, který token
  analyzeru zakládá. Tabulka `hosting_core_ai_analyzers` a sloupec tokenu
  v `hosting_core_data_sources` zmizí z definic; `ds-upgrade` je z databáze
  nemaže (servisní výmaz je samostatná položka roadmapy, M5).
- ✓ **D23 — Upozornění místo SMTP alertů démona.** Nový alert check
  `core.mail.analysis_stalled` — „Analýza pošty stojí“: zdroj dat má
  použitelný backend (aktivní výchozí profil → aktivní backend s klíčem)
  a ve frontě analýzy je zpráva déle než 15 minut. Jednotlivá selhání už
  uživatel vidí na Dashboardu; tohle hlídá, že runner vůbec běží (sloty,
  práva, cron).
- ✓ **D24 — Repozitář `shipard/ai-analyzer`.** Poslední commit:
  `README.md` s oznámením o ukončení a odkazem na #85. Archivaci
  repozitáře na GitHubu a vypnutí služby na serverech dělá člověk podle
  runbooku z kroku 5.

## Scope

### V rozsahu

- Odstranění protokolu, provisioningu analyzeru a registru na hostingu
  (D20–D22) včetně testů.
- Přejmenování `ai-analyzer-set-key` (D21) a úprava provisioning agenta.
- Alert check (D23).
- Dokumentace a runbook vypnutí démona (D24).

### Mimo rozsah

- AI gateway (`/_hosting/ai-gw`), její tokeny a měření spotřeby.
- Tabulka `core_mail_analysis_claims`, `AnalysisClaimReaper`,
  `mail-analysis-reap`, služby `Analysis\*` (#85 D12).
- `ai.analysis.maxConcurrent` — zůstává; hodnota `0` dál znamená „analýza
  na tomto serveru vypnutá“, jen už bez zmínky o démonu.
- `mail-router` a `/_mail/incoming`.
- Fyzické smazání tabulek a sloupců z databází.
- Katalog modelů, AI úlohy (#85 fáze 1); cron na vývojářských strojích (#115).

## Inventura (stav 2026-10-10)

| Oblast | Soubory |
|--------|---------|
| Protokol | `src/Api/Controller/AnalysisController.php` — `queue`, `claim`, `payload`, `attachmentContent`, `streamFile`, `result`, `failed`, `verifyAnalyzerAuth`, `validateClaimToken` a pomocníci, kteří po nich osiří (`withNoStoreHeaders`, `normalizeDateTime`, `decodeJsonField`, `validateAndStoreCanonical`, `applyMessageClassification`, `applyMessageTitle`, `knownPrimaryTypes` — ověřit volající); `src/Api/Router.php` (větev `/_mail/analysis`, řádky kolem 708–765; větev `/_mail/messages/{ndx}/…` zůstává); `src/Api/ReadOnlyPolicy.php` (položka `analysis` — jen callbacky analyzeru) |
| Provisioning a příkazy | `modules/core/mail/src/AIAnalyzerProvisioner.php`, `src/Command/DataSource/AiAnalyzerSetupCommand.php`, `AiAnalyzerBootstrapCommand.php`, `AiAnalyzerSetKeyCommand.php`, `DsUpgradeCommand.php`, `HelpCommand.php`, `src/Cli/DsApplicationFactory.php`; zmínky v `AiProfileReloadCommand.php`, `ApiKeyCreateCommand.php`, `HostingAiGwInitCommand.php`, `HostingAiTokenCommand.php`, `src/Api/Controller/MailController.php`, `modules/core/mail/src/Analysis/AnalysisClaimService.php` |
| Texty v definicích | `modules/core/ai/forms/core_ai_backends.jsonc`, `modules/core/ai/module.jsonc`, `modules/core/mail/profiles/czech_general.jsonc` |
| Hosting | `modules/hosting/core/module.jsonc`, `src/AiAnalyzerDocument.php`, `src/AiAnalyzersViewer.php`, `src/DataSourcesForm.php`, `src/HostingDataSourceDocument.php`, `tables/hosting_core_ai_analyzers.jsonc` (+ `.md`), `tables/hosting_core_data_sources.jsonc` (+ `.md`); `src/Api/Controller/HostingAiAnalyzerController.php`, `HostingServerController.php`, `src/Api/HostingApiKeyAuthenticator.php`, `src/Api/Middleware/AuthMiddleware.php` (výjimka pro `hostingAiAnalyzer`), `src/Command/DataSource/HostingAnalyzerKeyCommand.php`, `src/Core/Server/HostingSyncRunner.php` |
| Komentáře po démonu | `modules/core/mail/src/Analysis/` (`AnalysisRunner`, `AnalysisSpawner`, `PromptRenderer`, `OutputParser`, `SchemaValidationException`), `src/Core/Config/ServerConfig.php` — přepsat tak, aby dávaly smysl bez znalosti démona |
| Testy | `tests/Unit/Api/Controller/AnalysisControllerTest.php`, `HostingAiAnalyzerControllerTest.php`, `HostingServerControllerTest.php`, `tests/Unit/Api/RouterTest.php`, `tests/Integration/Mail/AnalysisResultEndpointTest.php`, `tests/Unit/Command/DataSource/AiAnalyzerBootstrapCommandTest.php`, `AiAnalyzerSetKeyCommandTest.php`, `HostingAnalyzerKeyCommandTest.php`, `DsUpgradeCommandTest.php`, `tests/Unit/Core/Server/HostingSyncRunnerTest.php`, `tests/Unit/Module/Core/Mail/AIAnalyzerProvisionerTest.php`, `tests/Unit/Module/Hosting/Core/HostingModuleDefinitionTest.php`, `tests/Fixtures/Module/Hosting/InMemoryHostingAiAnalyzerDb.php`; další testy zmiňují uživatele `_ai_analyzer` jen ve fixturách (importy, bankovní výpisy) — upravit podle grepu |
| Dokumentace | `docs/mail/api-contract.md`, `docs/ai.md`, `docs/hosting.md`, `docs/services.md`, `docs/cli.md`, `docs/ds-setup.md`, `docs/ds-state.md`, `docs/dashboard.md`, `docs/frontend.md`, `docs/exchange-format.md`, `docs/registry-mvp.md`, `docs/dev/local-dev.md`, `docs/operations/production.md`, `docs/operations/ai-gateway.md`, `docs/operations/hosting-adopt-existing.md`, `docs/ai-workflow.md`, `docs/README.md`, `CLAUDE.md`, `tasks/README.md`; `modules/core/mail/docs/` (`ai-analysis.md`, `ai-prompts.md`, `documentation.md`, `preprocess.md`); tabulkové `.md` v `modules/core/mail/tables/`, `modules/core/ai/tables/`, `modules/hosting/core/tables/` |

## Kroky

Každý krok je samostatný commit.

### 1. Protokol (D20)

- **Nejdřív dokumentace** (`docs/services.md` §12):
  `docs/mail/api-contract.md` — §9.1–9.6 odstranit, zbytek §9 (uživatelské
  akce) přečíslovat a přejmenovat na „Akce nad analyzovanou zprávou“;
  doplnit řádek `**Verze:**` a sekci **Historie změn** se záznamem
  o zrušení protokolu. Popis stavového automatu (claim, lease, reaper,
  stropy) žije v `modules/core/mail/docs/ai-analysis.md` — sekci
  „Pull-based protokol“ nahradit odkazem na „Analýza v procesu“.
- `AnalysisController`: odstranit metody protokolu a osiřelé pomocníky;
  konstruktor zbavit závislostí, které potřeboval jen protokol.
  `Router`: větev `/_mail/analysis`. `ReadOnlyPolicy`: callbacky
  analyzeru.
- Testy protokolu přepsat na služby `Analysis\*` — pokrytí nesmí klesnout:
  co dnes testuje `result` / `failed` / `claim` / `queue` přes HTTP
  (`AnalysisControllerTest`, `AnalysisResultEndpointTest`), musí zůstat
  pokryté v `AnalysisResultWriterTest` (nový — dnes writer testuje jen
  HTTP vrstva), `AnalysisClaimServiceTest`, `AnalysisQueueTest`, případně
  v integračním testu runneru. Před
  smazáním testu ověřit, že jeho případ má protějšek.
- `RouterTest`: zrušené routy vrací 404.

### 2. Provisioning a příkazy (D21)

- `MailAiProvisioner` (přejmenování + zúžení; `RETIRED_MODELS` a přepis
  modelů zůstávají). `ds-upgrade`: jednorázová, idempotentní deaktivace
  uživatele `_ai_analyzer` (`core_system_users.is_active = 0`)
  a zneplatnění jeho klíčů (`core_system_api_keys.is_active = 0`), výpis
  jen při změně.
- Zrušit `ai-analyzer-setup`, `ai-analyzer-bootstrap` (příkazy, registrace,
  nápověda, testy).
- `ai-backend-set-key`: nová třída / přejmenování, alias
  `ai-analyzer-set-key` s upozorněním; bez `--api-key` načte klíč skrytým
  vstupem (neinteraktivní běh bez klíče = chyba, ne tiché nic).
- Texty, které radí „Run ai-analyzer-set-key“ nebo zmiňují analyzer
  (chybové zprávy, popisky formuláře backendu, nápověda příkazů, komentář
  v šabloně profilu), opravit na nový název a novou skutečnost.

### 3. Hosting (D22)

- Odstranit registr podle inventury (definice tabulky, dokument, viewer,
  formulář, položka navigace, controller, routa, výjimka v
  `AuthMiddleware`, větev v `HostingApiKeyAuthenticator`, příkaz, testy,
  fixtura).
- `hosting_core_data_sources`: sloupec tokenu analyzeru pryč z definice,
  z formuláře i z dokumentu.
- `HostingSyncRunner`: krok h. (volání `ai-analyzer-setup --json`)
  zrušit; potvrzení provisioningu už `analyzer_token` neposílá. Krok g.
  (backend řádek s gateway tokenem) volá `ai-backend-set-key`.
- `HostingServerController`: potvrzení bez `analyzer_token` je v pořádku;
  pole od staršího agenta se **ignoruje** (žádná chyba, žádný zápis).
- `ds-upgrade` hosting zdroje dat musí projít s osiřelou tabulkou
  a sloupcem v databázi.

### 4. Alert check (D23)

- `alertChecks` v `modules/core/mail/module.jsonc`:
  `core.mail.analysis_stalled`, texty `cs` / `en`, `navSection` jako
  u `core.mail.outbox_health`. Třída checku: počet zpráv ve frontě
  (`AnalysisQueue` — stejný predikát) starších než 15 minut podle
  `modified`; bez použitelného backendu check mlčí (fronta bez klíče není
  porucha).
- Test checku: prázdná fronta, čerstvá zpráva, stará zpráva, stará zpráva
  bez použitelného backendu.

### 5. Dokumentace a runbook (D24)

- `docs/ai.md` — mapa a tabulka komponent bez démona, §4 a §5 (příkazy,
  lifecycle klíče, `ai-backend-set-key`).
- `docs/hosting.md`, `docs/operations/ai-gateway.md`,
  `docs/operations/hosting-adopt-existing.md` — bez analyzeru; nový název
  příkazu.
- `docs/services.md` — §1 a §14 bez `ai-analyzer`; příklady, které se na
  něj odkazují (`sources-sync`, `reload.path`), nahradit příklady
  z `mail-router` nebo označit jako historické.
- `docs/operations/production.md` — `ai.analysis.maxConcurrent` bez zmínky
  o démonu; nový oddíl **„Vypnutí `ai-analyzer`“**: (1) nasadit `shpd`
  a pustit `ds-upgrade` na všech zdrojích dat, (2) ověřit analýzu nahráním
  dokladu, (3) `systemctl disable --now` služby a path / timer unit
  démona, (4) odstranit unit soubory, `/etc/shipard-ai-analyzer`, stavový
  adresář a instalaci v `/opt`, (5) na hostingu už není co mazat — registr
  zmizel z UI, data zůstávají osiřelá.
- `docs/dev/local-dev.md`, `docs/cli.md`, `docs/ds-setup.md`,
  `docs/ds-state.md`, `docs/README.md` a ostatní dokumenty z inventury —
  nový název příkazu, žádný démon.
- `CLAUDE.md` (tabulka dokumentů, seznam CLI), `docs/ai-workflow.md` §7
  (tabulka `project_id` — řádek `ai_analyzer` pryč), `tasks/README.md`
  (mapa projektů, věta o démonech u sekce Došlá pošta).
- `help/` — ověřit, že žádná stránka nezmiňuje analyzer jako službu.
- Tabulkové `.md`: `core_mail_analysis_claims.md` (`analyzer_id` =
  `internal:<hostname>:<pid>`; starší řádky nesou ID démona),
  `core_mail_message_analyses.md` (`created_by`), ostatní podle grepu.
- **Repo `ai_analyzer`** (`/home/sebik/sw/ai-analyzer`, samostatný
  commit tam): `README.md` — nahoře oznámení o ukončení, důvod jednou
  větou a odkaz na shipard/shipard#85; necommitovaný
  `tasks/sampling-thinking-params.md` smazat.
- `**Stav:**` + `python3 scripts/tasks-index.py`.

## Ověření (dev server, zdroje dat v režimu `volný`)

1. `ds-upgrade` na běžném i hosting zdroji dat projde; uživatel
   `_ai_analyzer` je neaktivní a jeho klíče zneplatněné; druhý běh nic
   nevypíše.
2. Nahrát doklad bez ISDOC z Dashboardu → analýza doběhne jako dřív;
   reanalýza, použití a zamítnutí návrhu fungují.
3. `GET /_mail/analysis/queue` → 404.
4. `ai-backend-set-key --backend default` se skrytým vstupem nastaví
   klíč; `ai-analyzer-set-key` funguje a vypíše upozornění.
5. Hosting zdroj dat: v navigaci není „AI analyzery“, formulář zdroje dat
   nemá token analyzeru; přehled spotřeby gateway beze změny.
6. Upozornění: při `ai.analysis.maxConcurrent = 0` nechat zprávu ve
   frontě déle než 15 minut, pustit `alerts-run` → „Analýza pošty stojí“
   ve feedu; po vrácení limitu a doběhnutí analýzy upozornění zmizí.
7. Grep z „Před implementací přečti“ vrací jen historické zmínky
   v `tasks/`, alias příkazu a deaktivaci uživatele.

## Pasti

- **Pořadí nasazení.** Po tomto tasku dostane běžící démon na `/queue`
  404. Vypnutí služby je ruční krok člověka — task ho jen popisuje.
- **`created_by` u starých běhů.** Uživatele `_ai_analyzer` nemazat.
- **Gateway není analyzer.** `hosting-ai-token`, `hosting-ai-gw-init`,
  `HostingAiGatewayController`, `GwUsageExtractor`,
  `hosting_core_ai_tokens` a `hosting_core_ai_usage` zůstávají — při
  mazání podle grepu `ai` nezaměnit.
- **`HostingApiKeyAuthenticator`** obsluhuje i jiné klíče hostingu
  (router, server) — odstranit jen větev analyzeru.
- **Provisioning agent** (`HostingSyncRunner`, krok h.) dnes volá
  `ai-analyzer-setup --json` a token posílá v potvrzení jako
  `analyzer_token`. Hosting musí potvrzení bez tohoto pole přijmout
  a starší agent, který ho ještě pošle, nesmí dostat chybu.
- **Uživatelské akce sdílejí controller.** `reanalyze`, `applyMessage`,
  `rejectMessage`, `saveDecisions`, `unapplyMessage`, `previewMessage`
  zůstávají v `AnalysisController` a v `ReadOnlyPolicy`; odstraňují se
  jen callbacky stroje.
- **Pokrytí.** Testy přes HTTP dnes fungují jako regresní síť služeb —
  nemazat je bez náhrady na úrovni služby.
- **`maxConcurrent = 0`.** Kód ani dokumentace nesmí dál tvrdit, že je to
  režim „server s démonem“; je to vypínač.

## Hotovo když

- [ ] Kroky 1–5 jako samostatné commity (krok 5 navíc commit v repu
      `ai_analyzer`), `php -l`, cílené testy, celá sada.
- [ ] Ověření 1–7 provedeno, výsledek do #85.
- [ ] Runbook vypnutí démona je v `docs/operations/production.md`.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
