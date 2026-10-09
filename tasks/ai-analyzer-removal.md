# AI — zrušení pull protokolu, registru analyzerů a démona `ai-analyzer` (#85 D9, D11)

**Stav:** naplánováno — po ověření `mail-analysis-inprocess.md`; čeká na potvrzení rozhodnutí D20–D24 (#85)

## Status / cíl

Po `tasks/mail-analysis-inprocess.md` analyzuje došlou poštu `shpd` sám.
Všechno, co existovalo jen kvůli vzdálenému démonu, je od té chvíle mrtvý
kód a mrtvá provozní zátěž: pull protokol `/_mail/analysis/*`, systémový
uživatel `_ai_analyzer` s API klíčem, registr analyzerů na hostingu
a repozitář `shipard/ai-analyzer`.

Cíl: **odstranit je** a srovnat dokumentaci, aby v systému zůstala jediná
cesta analýzy. Stavový automat analýzy (claimy s lease, reaper) zůstává
(#85 D12).

GitHub Issue: shipard/shipard#85 (D9, D11).

## Návaznost

- `tasks/mail-analysis-inprocess.md` — **předpoklad**; tento task se
  spouští až po jeho ověření (sekce „Ověření na dev serveru“) a po
  rozhodnutí člověka, že démon končí i na testovacím serveru.
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
- Kód — inventura níže; **před mazáním ověř grepem**, seznam je momentka:

  ```bash
  grep -rl --include=*.php --include=*.jsonc --include=*.svelte --include=*.js -i -E \
    'ai_analyzers|ai-analyzer/lookup|analyzer_token|hosting-analyzer-key|AiAnalyzersViewer|_ai_analyzer|ai-analyzer-setup' \
    src modules frontend/src tests
  ```

## Rozhodnutí k designu (návrh, čeká na potvrzení)

- **D20 — Pull protokol se ruší naráz.** Routy `/_mail/analysis/queue`,
  `/{ndx}/claim`, `/{ndx}/payload`, `/{ndx}/attachments/{att}/content`,
  `/{ndx}/result`, `/{ndx}/failed` a jejich metody v `AnalysisController`
  mizí bez přechodného období — ostré zdroje dat zatím nejsou. Uživatelské
  akce nad zprávou (`reanalyze`, `apply`, `reject`, `unapply`, `preview`,
  `decisions`, archivace) zůstávají.
- **D21 — Uživatel `_ai_analyzer` a příkazy analyzeru.** Provisioning
  uživatele a jeho API klíče končí; `AIAnalyzerProvisioner` se zúží na
  výchozí backend a výchozí profil a přejmenuje (`MailAiProvisioner`).
  Existující uživatel `_ai_analyzer` se při `ds-upgrade` deaktivuje a jeho
  API klíče zneplatní — nemaže se (běhy analýz na něj odkazují
  v `created_by`). Příkazy `ai-analyzer-setup` a `ai-analyzer-bootstrap`
  zanikají. `ai-analyzer-set-key` se přejmenuje na **`ai-backend-set-key`**
  (nastavuje klíč backendu pro všechny AI cesty, ne pro analyzer); starý
  název zůstává jedno vydání jako alias s upozorněním. Nový příkaz umí
  klíč načíst skrytým vstupem (vzor `hosting-ai-gw-init --set-key`);
  `--api-key` zůstává pro provisioning agenta.
- **D22 — Registr analyzerů na hostingu se ruší.** Viewer a formulář
  „AI analyzery“, `AiAnalyzerDocument`, `HostingAiAnalyzerController`
  (`/_hosting/ai-analyzer/lookup`), příkaz `hosting-analyzer-key`, pole
  tokenu analyzeru u zdroje dat a krok provisioningu, který token
  analyzeru zakládá. Tabulka `hosting_core_ai_analyzers` a sloupec tokenu
  v `hosting_core_data_sources` zmizí z definic; `ds-upgrade` je z databáze
  nemaže (servisní výmaz je samostatná položka roadmapy, M5).
- **D23 — Upozornění místo SMTP alertů démona.** Nový alert check
  v `core.mail`: „Analýza pošty stojí“ — zdroj dat má použitelný backend
  a ve frontě je zpráva déle než 15 minut. Jednotlivá selhání už uživatel
  vidí na Dashboardu (sekce selhaných analýz); tohle hlídá, že runner
  vůbec běží (cron, sloty, práva).
- **D24 — Repozitář `shipard/ai-analyzer`.** Poslední commit: `README.md`
  s oznámením o ukončení a odkazem na #85. Archivaci repozitáře na GitHubu
  a vypnutí služby na serverech (`systemctl disable --now`, odstranění
  unit) dělá člověk podle runbooku z kroku 5.

## Scope

### V rozsahu

- Odstranění protokolu, provisioningu analyzeru a registru na hostingu
  (D20–D22) včetně testů a frontendu.
- Přejmenování `ai-analyzer-set-key` (D21) a úprava provisioning agenta,
  který ho volá.
- Alert check (D23).
- Dokumentace a runbook vypnutí démona (D24).

### Mimo rozsah

- AI gateway (`/_hosting/ai-gw`), její tokeny a měření spotřeby.
- Tabulka `core_mail_analysis_claims`, `AnalysisClaimReaper`,
  `mail-analysis-reap` (#85 D12).
- `mail-router` a `/_mail/incoming`.
- Fyzické smazání tabulek a sloupců z databází.
- Katalog modelů, AI úlohy (#85 fáze 1).

## Inventura (stav 2026-10-09)

| Oblast | Soubory |
|--------|---------|
| Protokol | `src/Api/Controller/AnalysisController.php` (`queue`, `claim`, `payload`, `attachmentContent`, `streamFile`, `result`, `failed`, `verifyAnalyzerAuth`, `validateClaimToken`), `src/Api/Router.php` (větev `/_mail/analysis`), `src/Api/ReadOnlyPolicy.php`, `src/Api/Middleware/AuthMiddleware.php` |
| Provisioning | `modules/core/mail/src/AIAnalyzerProvisioner.php`, `src/Command/DataSource/AiAnalyzerSetupCommand.php`, `AiAnalyzerBootstrapCommand.php`, `AiAnalyzerSetKeyCommand.php`, `ApiKeyCreateCommand.php`, `DsUpgradeCommand.php`, `HelpCommand.php`, `src/Cli/DsApplicationFactory.php` |
| Hosting | `modules/hosting/core/module.jsonc`, `src/AiAnalyzerDocument.php`, `src/AiAnalyzersViewer.php`, `src/DataSourcesForm.php`, `src/HostingDataSourceDocument.php`, `tables/hosting_core_ai_analyzers.jsonc` (+ `.md`), `tables/hosting_core_data_sources.jsonc`, `src/Api/Controller/HostingAiAnalyzerController.php`, `src/Api/Controller/HostingServerController.php`, `src/Api/HostingApiKeyAuthenticator.php`, `src/Command/DataSource/HostingAnalyzerKeyCommand.php`, `src/Core/Server/HostingSyncRunner.php` |
| Dokumentace | `docs/mail/api-contract.md`, `docs/ai.md`, `docs/hosting.md`, `docs/services.md`, `docs/cli.md`, `docs/ds-setup.md`, `docs/ds-state.md`, `docs/operations/ai-gateway.md`, `docs/operations/hosting-adopt-existing.md`, `docs/ai-workflow.md`, `docs/README.md`, `CLAUDE.md`, `tasks/README.md` |

## Kroky

### 1. Protokol (D20)

- **Nejdřív dokumentace** (`docs/services.md` §12): `docs/mail/api-contract.md`
  — §9.1–9.6 odstranit, §9.7 a dál (uživatelské akce) přečíslovat
  a přejmenovat §9 na „Akce nad analyzovanou zprávou“; doplnit řádek
  `**Verze:**` a sekci **Historie změn** se záznamem o zrušení protokolu.
  Popis stavového automatu (claim, lease, reaper) přesunout do
  `modules/core/mail/docs/ai-analysis.md`.
- `AnalysisController`: odstranit metody protokolu a soukromé pomocníky,
  které po nich osiří. `Router`: větev `/_mail/analysis`. `ReadOnlyPolicy`
  a `AuthMiddleware`: výjimky pro analyzer.
- Testy protokolu přepsat na služby z `Analysis\*` (pokrytí nesmí klesnout
  — testy `result` / `failed` přes HTTP se stávají testy
  `AnalysisResultWriter`).

### 2. Provisioning a příkazy (D21)

- `MailAiProvisioner` (přejmenování + zúžení); `ds-upgrade` jednorázově
  deaktivuje uživatele `_ai_analyzer` a zneplatní jeho klíče (idempotentně).
- Zrušit `ai-analyzer-setup`, `ai-analyzer-bootstrap`.
- `ai-backend-set-key` + alias `ai-analyzer-set-key`; skrytý vstup.
- Provisioning agent hostingu (krok, který zapisuje backend řádek s gateway
  tokenem) volá nový název; krok se zakládáním tokenu analyzeru mizí.
- Chybové texty, které radí „Run ai-analyzer-set-key“, opravit.

### 3. Hosting (D22)

- Odstranit registr podle inventury; `ds-upgrade` hosting DS musí projít
  s osiřelou tabulkou v databázi.
- Queue payload provisioningu: sekce `ai` (gateway) beze změny, část pro
  analyzer pryč. Ověřit zpětnou kompatibilitu agenta se starším payloadem
  a naopak (`docs/hosting.md`).

### 4. Alert check (D23)

- `alertChecks` v `modules/core/mail/module.jsonc`, třída checku, texty
  `cs` / `en`, `navSection`. Vzor: stávající checky v `docs/alerts.md`.

### 5. Dokumentace a runbook (D24)

- `docs/ai.md` — mapa bez démona, §4 a §5 (příkazy, lifecycle klíče).
- `docs/hosting.md`, `docs/operations/ai-gateway.md`,
  `docs/operations/hosting-adopt-existing.md` — bez analyzeru; nový název
  příkazu.
- `docs/services.md` — §1 a §14 bez `ai-analyzer`; příklady (`sources-sync`,
  `reload.path`) nahradit nebo označit jako historické.
- `docs/operations/` — krátký runbook „Vypnutí `ai-analyzer`“: pořadí
  (nasadit `shpd` → ověřit analýzu v procesu → zastavit a zakázat službu
  → odstranit unit, `/etc/shipard-ai-analyzer`, `/var/lib/…`, `/opt/…`
  → smazat řádky analyzerů na hostingu).
- `docs/cli.md`, `docs/ds-setup.md`, `docs/ds-state.md`, `docs/README.md`,
  `CLAUDE.md` (tabulka dokumentů, seznam CLI), `docs/ai-workflow.md` §7
  (tabulka `project_id`), `tasks/README.md` (mapa projektů, poznámka
  u sekce Došlá pošta).
- `help/` — ověřit, že žádná stránka nezmiňuje analyzer jako službu.
- `ai_analyzer:README.md` — oznámení o ukončení (D24).
- `**Stav:**` + `python3 scripts/tasks-index.py`.

## Pasti

- **Pořadí nasazení.** Nejdřív musí analýza v procesu běžet a být ověřená;
  po tomto tasku dostane běžící démon na `/queue` 404. Vypnutí služby je
  ruční krok člověka — task ho jen popisuje.
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

## Hotovo když

- [ ] Kroky 1–5 jako samostatné commity, `php -l`, cílené testy, celá sada,
      `npm run build` (mizí viewer z hostingu).
- [ ] Grep z „Před implementací přečti“ vrací jen historické zmínky
      v `tasks/` a alias příkazu.
- [ ] `ds-upgrade` projde na běžném i hosting zdroji dat s původními
      tabulkami v databázi.
- [ ] Nový zdroj dat z hostingu dostane gateway backend a analyzuje poštu
      bez dalšího kroku.
- [ ] Runbook vypnutí démona existuje; výsledek do #85.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
