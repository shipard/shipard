# Došlá pošta — AI analýza v `shpd` místo démona `ai-analyzer` (#85 D9)

**Stav:** hotovo — 2026-10-09 (kroky 1–6 v samostatných commitech, ověření 1–11 na dev serveru viz „Ověřeno“); zbývá push, komentář do #85, nasazení na alfě a živý souběh s démonem při `maxConcurrent = 0`

## Status / cíl

Analýzu došlé pošty dnes dělá samostatný Python démon `ai-analyzer`: táhne
zprávy přes `/_mail/analysis/*`, volá model a výsledek posílá zpět. Ostatní
AI cesty (asistent, shrnutí dashboardu, obsahové štítky, historie účtování)
volají model přímo z PHP přes `AnthropicLlmClient`.

Cíl: **analýzu zprávy provede `shpd` sám** — CLI `shpd-ds mail-analyze`,
spouštěný odpojeně po příjmu zprávy a záchranně z minutového cronu, stejným
vzorem jako `mail-preprocess`. Na čerstvé instalaci pak k vytěžení dokladu
stačí klíč AI backendu; odpadá druhý runtime, tokeny analyzeru a kontrakt
mezi dvěma repozitáři.

Tento task přidává novou cestu a **starou neruší** — pull protokol zůstává
funkční, aby šly obě cesty porovnat a nasazení nebylo jednorázové. Zrušení
protokolu, registru analyzerů na hostingu a repozitáře démona řeší
navazující `tasks/ai-analyzer-removal.md`.

GitHub Issue: shipard/shipard#85 (D9, D10, D11).

## Návaznost

- `tasks/mail-phase3a.md` — pull protokol, claimy, reaper (§3).
- `tasks/mail-message-centric.md` — tvar výsledku analýzy (kontrakt v4).
- `tasks/mail-preprocess.md` — runner, spawner, sweep, gate AI fronty
  (D8, D9, D12). **Vzor pro tento task.**
- `tasks/mail-isdoc-content-tags.md` — odložený ISDOC import a závod
  s analýzou (#81 D1, D4).
- `tasks/mail-analysis-error-messages.md` — typy chyb a hlášky selhané analýzy.
- `tasks/ai-models-phase0.md` — ladicí parametry backendu; na tomto tasku
  nezávisí (viz Pasti).
- `ai_analyzer:ai_analyzer/` — zdroj přenášené logiky: `worker.py`,
  `preprocessing.py`, `prompt.py`, `schema.py`, `providers/`.

## Před implementací přečti

- `docs/ai.md` — §1 mapa, §4 cesty k modelu, §5 backendy.
- `modules/core/mail/docs/ai-analysis.md` — sekce „Pull-based protokol“,
  „Stavy zprávy“, „Reanalýza“, „Chybové hlášky pro uživatele“,
  „Deterministický ISDOC import“.
- `docs/mail/api-contract.md` §9.1–9.6 a §9.10 — dnešní protokol; jeho
  sémantika se zachovává (D12).
- `modules/core/mail/docs/preprocess.md` — stavy, tok intake → runner, sweep.
- `docs/hosting.md` §5.5 — AI gateway (limit: spojení drží PHP-FPM worker).
- `docs/operations/secrets.md` — zacházení s klíčem backendu.
- Kód:
  - `src/Api/Controller/AnalysisController.php` — `queue()` (predikát
    fronty), `claim()` (výběr profilu a backendu, dešifrování klíče),
    `payload()` (které přílohy analýza vidí), `result()`, `failed()`,
    `reanalyze()`.
  - `modules/core/mail/src/AnalysisClaimReaper.php`.
  - `modules/core/mail/src/Preprocess/PreprocessRunner.php`,
    `PreprocessRunnerFactory.php`, `PreprocessSpawner.php`;
    `src/Command/DataSource/MailPreprocessCommand.php`;
    `src/Core/Process/DetachedProcess.php`.
  - `src/Api/Controller/MailController.php` — `receiveIncoming()`,
    `uploadMessages()`, `deferIsdocImport()`, `spawnPreprocess()`.
  - `src/Core/Ai/` — `LlmClient`, `LlmChatParams`, `LlmChatResult`,
    `AnthropicLlmClient`, `AiBackendResolver`.
  - `src/Core/Prints/Twig/PrintTwigFactory.php`, `PrintSecurityPolicy.php`
    — vzor Twig sandboxu.
  - `modules/core/exchange/src/Schema/SchemaValidator.php` — použití
    `opis/json-schema`.
  - `src/Command/Server/CronCommand.php` — `SLOT_JOBS`, `JOB_ALLOWED_STATES`.
  - `modules/core/mail/src/AnalysisErrorPresenter.php` — typy chyb, které
    UI zná.

## Rozhodnutí k designu (potvrzená)

### Směr (#85, 2026-10-09)

- ✓ **D9** — Analýza došlé pošty běží v `shpd` (CLI job, spawn + sweep);
  `ai-analyzer` končí. `mail-router` se nemění.
- ✓ **D10** — `LlmClient` je šev per API dialekt; Anthropic dialekt zůstává
  na vlastním `AnthropicLlmClient`. Žádná knihovna třetí strany.
- ✓ **D11** — AI gateway hostingu beze změny; souběh analýz hlídá limit
  per server.

### Prováděcí (#85, potvrzeno 2026-10-09)

- ✓ **D12 — Stejný stavový automat, jiný transport.** Runner dělá tytéž
  kroky jako dnešní protokol: claim s lease v `core_mail_analysis_claims`
  → výsledek nebo selhání → uvolnění claimu; expirované claimy dál vrací
  `mail-analysis-reap`. Liší se jen tím, že kroky volá jako služby
  v procesu, ne přes HTTP. `analysis_state`, tab Analýzy,
  `AnalysisErrorPresenter`, reanalýza, read-only zámek zprávy při
  `analysis_state = 20` i závod s ISDOC importem zůstávají beze změny.
  `analyzer_id` claimu = `internal:<hostname>:<pid>`.
- ✓ **D13 — Logika z controlleru do služeb.** Nový jmenný prostor
  `Shipard\Module\Core\Mail\Analysis\`:
  - `AnalysisQueue` — predikát fronty (jediné místo; dnes je v `queue()`
    dvakrát), `eligible(int $limit)`, `isEligible(int $messageId)`,
  - `AnalysisClaimService` — `claim()`, `extend()`, `findActive()`,
  - `AnalysisResultWriter` — `storeResult()`, `storeFailure()`.

  HTTP endpointy v tomto tasku zůstávají jako tenké obálky nad službami,
  beze změny chování a kontraktu.
- ✓ **D14 — Spouštění: odpojený spawn + sweep, žádný démon.**
  `shpd-ds mail-analyze --message <id>` se spouští přes `DetachedProcess`
  (a) po commitu příjmu nebo nahrání, když zpráva nejde do předzpracování,
  (b) na konci běhu `mail-preprocess`, (c) po `reanalyze`.
  `shpd-ds mail-analyze --sweep` běží v minutovém slotu cronu za
  `mail-analysis-reap` a pro zprávy ve frontě bez aktivního claimu spouští
  runner — nejvýš tolik, kolik je volných slotů (D15).
- ✓ **D15 — Limit souběhu per server.** `ai.analysis.maxConcurrent`
  v `server.json`, výchozí **2**. Slot = neblokující `flock` na souboru
  `ai-analysis-<n>.lock` v `CronProvisioner::RUN_DIR`. Runner bez volného
  slotu skončí **bez claimu** a zpráva zůstane ve frontě pro sweep.
  Hodnota `0` = analýza v procesu vypnutá (spawn i sweep nic nedělají) —
  server, kde má dál pracovat démon.
- ✓ **D16 — Příprava příloh: ZIP ano, `.eml` ne.** Přenáší se
  `preprocessing.py`: rozbalení ZIP (jedna úroveň, vnořený ZIP se
  přeskočí), třídění na PDF / obrázek (`jpeg`, `png`, `gif`, `webp`)
  / text (`text/*`), u `application/octet-stream` odhad podle přípony,
  dekódování textu (`utf-8`, `utf-16`, `windows-1250`, `latin-1`). Limity
  jako dnes: 20 příloh na zprávu, 30 MB celkem (priorita PDF > obrázek
  > text, při stejné prioritě větší dřív; zachované přílohy v původním
  pořadí), nad 10 MB na přílohu jen varování v logu. Rozbalení přílohy
  `.eml` / `message/rfc822` se **nepřenáší** — okrajový případ; příloha se
  přeskočí jako nepodporovaný typ. Limity jsou konstanty, ne nastavení.
- ✓ **D17 — Prompt přes Twig sandbox.** `prompt_template` profilu se
  vykresluje Twigem v sandboxu, `strict_variables`, bez autoescape.
  Kontext jako dnes: `message` (`subject`, `sender_email`, `sender_name`,
  `received_at`, `body_plain`, `body_html`), `attachments[]` (`ndx`,
  `filename`, `mime_type`, `kind`, `size_human`), `output_schema`.
  Politika povoluje jen tagy `if`, `for`, `set` a filtry `length`,
  `default`, `join`, `trim`, `lower`, `upper`; žádné funkce, metody ani
  vlastnosti objektů.
- ✓ **D18 — Volání modelu, výstup, chyby.**
  - Požadavek jako dnes: jedna zpráva `user` — text promptu, pak za každou
    přílohu blok `document` (PDF, base64) / `image` / `text`
    (`--- Attachment <název> (#<ndx>) ---` + obsah). `max_tokens` kaskádou
    profil → backend → `32768`; `temperature` z backendu; streamovaně.
  - Výstup: první textový blok odpovědi → JSON (přímo, jinak z markdown
    bloku ```` ```json ````) → validace proti `output_schema` profilu přes
    `opis/json-schema` → objekt na nejvyšší úrovni.
  - `stop_reason = max_tokens` je selhání bez opakování.
  - Opakování v rámci běhu: přechodné chyby (transport, 408, 429, 5xx,
    `overloaded_error`) — další dva pokusy po 10 a 60 s; před každým
    pokusem se prodlouží lease claimu.
  - Vyčerpaný měsíční strop útraty u poskytovatele (429
    s `error.details.error_code = enforced_spend_limit_reached`, bez
    hlavičky `retry-after`) se **neopakuje** a zpráva se nevrací do
    fronty: `config_error`, stav 70. *(Doplněno 2026-10-09.)*
  - Typy chyb beze změny: `ai_error`, `schema_error`, `config_error`.
  - Přechodná chyba po vyčerpání pokusů vrací zprávu do fronty, **nejvýš
    třikrát za hodinu** (počítají se selhané běhy zprávy); potom stav 70
    „Analýza selhala“ a rozhoduje uživatel (Znovu analyzovat).
  - Běh v procesu zapisuje `created_by = NULL` (strojový kontext).
- ✓ **D19 — Cena volání.** `cost_usd` počítá PHP z tabulky cen podle
  nejdelšího shodného prefixu ID modelu (`anthropic_pricing.py`
  → `Shipard\Core\Ai\AnthropicPricing`); neznámý model = 0 a varování
  v logu. Dočasné — ceny převezme katalog modelů (#85 fáze 1).

## Scope

### V rozsahu

- Služby analýzy vytažené z `AnalysisController` (D13).
- Příprava příloh, prompt, zpracování výstupu, cena (D16–D19).
- Timeouty a opakování v LLM vrstvě (D18).
- Runner, CLI `mail-analyze`, sloty souběhu (D14, D15).
- Spouštění z příjmu, nahrání, předzpracování, reanalýzy a cronu (D14).
- Dokumentace: `docs/ai.md`, `modules/core/mail/docs/ai-analysis.md`,
  `docs/cli.md`, provozní zmínka o `ai.analysis.maxConcurrent`, krátká
  sekce „AI na lokální instalaci“ v `docs/dev/local-dev.md`.

### Mimo rozsah

- Zrušení pull protokolu, uživatele `_ai_analyzer`, registru analyzerů na
  hostingu a přejmenování `ai-analyzer-set-key`
  — `tasks/ai-analyzer-removal.md`.
- Ladicí parametry modelů (thinking, effort, volitelná teplota)
  — `tasks/ai-models-phase0.md`.
- Katalog modelů, AI úlohy, připojení vs. model (#85 fáze 1).
- Evaluace a běh „nanečisto“ (#85 fáze 2), dávkové API.
- Thinking bloky v tool smyčce asistenta (#85 fáze 4).
- Změna promptu, schématu nebo profilu.
- Jakákoli změna `mail-router`.

## Nový tok

```
příjem / nahrání zprávy (commit)
   ├─ plán předzpracování nebo ISDOC → spawn mail-preprocess
   │      └─ konec běhu (stav 30/40), analysis_state = 10 → spawn mail-analyze
   └─ jinak, analysis_state = 10 ───────────────────────→ spawn mail-analyze

reanalyze (analysis_state → 10) ────────────────────────→ spawn mail-analyze
cron minute: mail-analysis-reap → mail-analyze --sweep ─→ spawn mail-analyze (≤ volné sloty)

mail-analyze --message N
   1. slot (flock, neblokující)      bez slotu → konec, zpráva čeká ve frontě
   2. AnalysisQueue::isEligible(N)   ne → konec
   3. AnalysisClaimService::claim    10 → 20, lease; profil + backend + klíč
   4. přílohy → AttachmentPreparer → PromptRenderer
   5. LLM (stream, opakování, prodlužování lease)
   6. OutputParser (JSON + schéma)
   7. AnalysisResultWriter::storeResult / storeFailure   20 → 30 / 10 / 70
```

## Kroky

Každý krok je samostatný commit. Kroky 1–4 nemění chování běžícího
systému; novou cestu zapíná až krok 5.

### 1. Služby z `AnalysisController` (bez změny chování)

- `Analysis\AnalysisQueue`: predikát fronty z `queue()` jako jediný
  dotaz/fragment (`analysis_state = 10`, mimo Archiv a Koš,
  `preprocess_state` mimo `PREPROCESS_BLOCKING_STATES`, příznaky
  `ai_analysis_enabled` zprávy a `ai_analysis_disabled` schránky, bez
  aktivního claimu). `eligible($limit)`, `countEligible()`,
  `isEligible($messageId)`.
- `Analysis\AnalysisClaimService::claim(int $messageId, string $analyzerId,
  int $leaseSeconds, ?int $profileId)` — tělo dnešního `claim()` včetně
  `FOR UPDATE`, výběru profilu a backendu a dešifrování klíče. Vrací
  hodnotový objekt (claim, profil, backend, klíč), nebo typovanou chybu
  s dnešními kódy (`NOT_FOUND`, `INVALID_STATE`, `ALREADY_CLAIMED`,
  `NO_PROFILE`, `NO_BACKEND`, `SECRETS_UNAVAILABLE`,
  `BACKEND_KEY_CORRUPTED`, `BACKEND_KEY_MISSING`).
  `extend(int $claimId, int $leaseSeconds)` — posune `expires_at` jen
  u neuvolněného claimu; `false` = claim už neplatí.
- `Analysis\AnalysisResultWriter::storeResult(int $messageId, int $claimId,
  array $result, ?int $userId)` a `storeFailure(…)` — těla `result()`
  a `failed()` od validace vstupu po commit, včetně soukromých pomocníků,
  které potřebují (`validateAndStoreCanonical`, `extractContentTag`,
  `applyMessageClassification`, `applyMessageTitle`, partner, dispozice
  po analýze). Tvar `$result` = dnešní tělo `/result` (kontrakt v4).
- `AnalysisController`: `queue()`, `claim()`, `result()`, `failed()`
  delegují na služby a jen mapují na `Response`. Konstanty
  `ANALYSIS_*` a `PREPROCESS_BLOCKING_STATES` zůstávají dostupné pod
  dnešními názvy (ostatní třídy na ně odkazují).
- Wiring služeb na jednom místě (`Analysis\AnalysisServices::create(…)`),
  aby ho sdílel controller i runner.

Akceptace: `AnalysisControllerTest`, `AnalysisControllerExchangeTest`,
`AnalysisControllerResolveBodyTest` a
`tests/Integration/Mail/AnalysisResultEndpointTest` projdou **beze změny
testů**.

### 2. Vstup a výstup analýzy

- `Analysis\AttachmentPreparer` (D16) — vstup: přílohy zprávy podle
  `payload()` (bez `raw_source_attachment`, bez smazaných), obsah přes
  `AttachmentService::getFilePath()`. Výstup: seznam připravených příloh
  (`ndx`, `kind`, `filename`, `mime_type`, base64 nebo text). Rozbalený
  soubor ze ZIPu dědí `ndx` rodiče a název `<zip>/<vnitřní název>`.
- `Analysis\PromptRenderer` (D17).
- `Analysis\OutputParser` (D18) — `parse(string $rawText, array $schema):
  array`; chyba parsování i validace = `SchemaValidationException`
  s čitelnou zprávou (cesta v dokumentu + důvod).
- `Shipard\Core\Ai\AnthropicPricing` (D19).

### 3. LLM vrstva — timeouty a opakování

- `LlmChatParams`: `?int $stallTimeoutSeconds = null`,
  `?int $timeoutSeconds = null` (`null` = dnešní chování).
  `AnthropicLlmClient::sendStreamingRequest()` je promítne do curl
  (`CURLOPT_LOW_SPEED_LIMIT` / `CURLOPT_LOW_SPEED_TIME`,
  `CURLOPT_TIMEOUT`); vypršení = `LlmApiException` se stavem 0.
- `LlmApiException`: nové pole `?string $errorCode` — hodnota
  `error.details.error_code` z těla chybové odpovědi (dnes se z něj čte
  jen `type` a `message`).
- `LlmApiException::isTransient()` — stav 0, 408, 429, ≥ 500 nebo inline
  chyba streamu typu `overloaded_error` / `api_error` / `rate_limit_error`.
  **Výjimka:** `errorCode = enforced_spend_limit_reached` (vyčerpaný
  strop útraty, taky 429) přechodná není — `false`.
- `Shipard\Core\Ai\LlmRetry::run(callable $call, array $delays,
  ?callable $beforeAttempt)` — opakuje jen `isTransient()`; `sleep`
  injektovatelný kvůli testům.
- Ostatní volající (`ChatController`, klasifikátory, shrnutí) se nemění.

### 4. Runner, CLI, sloty

- `Analysis\AnalysisSlots` (D15) — `tryAcquire(): ?resource`,
  `freeCount(): int`; limit ze `ServerConfig` (nový getter pro
  `ai.analysis.maxConcurrent`, výchozí 2, chybějící sekce = výchozí).
- `Analysis\AnalysisRunner`:
  - `run(int $messageId): array{status: string, note?: string}` — kroky
    1–7 z „Nový tok“. Lease 900 s; runner volá model s
    `stallTimeoutSeconds = 180`, `timeoutSeconds = 840`.
  - Mapování chyb: chyba claimu `NO_PROFILE` / `NO_BACKEND`
    / `BACKEND_KEY_*` / `SECRETS_UNAVAILABLE` → konec bez zápisu, zpráva
    zůstává ve frontě (dnešní chování), jedno varování do logu;
    `SchemaValidationException` → `storeFailure('schema_error', retryable:
    false)`; `LlmApiException` s `errorCode =
    enforced_spend_limit_reached` → `storeFailure('config_error',
    retryable: false)` se zprávou poskytovatele (říká, kdy se přístup
    obnoví); jiná `LlmApiException` → `ai_error` s `retryable` podle
    `isTransient()` a stropu z D18; `stop_reason = max_tokens` → `ai_error`,
    `retryable: false`; neplatný claim při zápisu (mezitím expiroval)
    → jen varování do logu včetně ceny volání.
  - `sweep(): array{spawned: list<int>, skipped: string|null}` — bez
    použitelného backendu (aktivní výchozí profil → aktivní backend
    s klíčem) nedělá nic; jinak `eligible(freeCount())` → spawn.
- `Analysis\AnalysisSpawner` — po vzoru `PreprocessSpawner`
  (`shpd-ds mail-analyze --message <id>`, log `analysis.log` vedle
  serverového logu); při `maxConcurrent = 0` nespouští nic.
- `Analysis\AnalysisRunnerFactory` — produkční wiring pro CLI.
- `src/Command/DataSource/MailAnalyzeCommand.php` — po vzoru
  `MailPreprocessCommand`: právě jedna z voleb `--message <id>`
  / `--sweep`. Selhaná analýza **není** chyba příkazu (zpráva doteče do
  stavu 10 / 70); `FAILURE` jen pro špatné volání a chyby infrastruktury.
  Registrace v `src/Cli/DsApplicationFactory.php` a v `HelpCommand`.

### 5. Spouštění

- `MailController::receiveIncoming()` a `uploadMessages()`: po commitu,
  **až po** rozhodnutí o předzpracování a `deferIsdocImport()` — zpráva
  s `analysis_state = 10`, která nešla do předzpracování, dostane spawn
  analýzy. Selhání spawnu jen zalogovat.
- `PreprocessRunner::run()`: po zápisu stavu 30 / 40, když má zpráva
  `analysis_state = 10`, spawn analýzy (spawner injektovaný, test seam
  jako u stávajícího spawneru).
- `AnalysisController::reanalyze()`: po commitu spawn analýzy.
- `CronCommand`: do slotu `minute` za `mail-preprocess --sweep` přidat
  `mail-analyze --sweep`; v `JOB_ALLOWED_STATES` jen `ACTIVE`.

### 6. Dokumentace a uzavření

- `docs/ai.md`: §1 mapa (analýza pošty jako další volající PHP klienta),
  §2 tabulka komponent, §4 cesty k modelu (analýza: PHP runner; `max_tokens`
  kaskáda teď končí v runneru), poznámka, že pull protokol dočasně zůstává.
- `modules/core/mail/docs/ai-analysis.md`: nová sekce „Analýza v procesu“
  (tok, spouštění, sloty, opakování a strop, příprava příloh včetně toho,
  že `.eml` příloha se nerozbaluje); sekce „Pull-based protokol“ dostane
  úvodní poznámku o stavu.
- `docs/cli.md`: `mail-analyze`.
- `docs/operations/production.md` (nebo kde je popsaný `server.json`):
  `ai.analysis.maxConcurrent` — význam, výchozí hodnota, `0`, vztah
  k PHP-FPM workerům gateway.
- `docs/dev/local-dev.md`: krátká sekce — k analýze dokladu a asistentovi
  stačí nastavit klíč backendu (`shpd-ds ai-analyzer-set-key --backend
  default --api-key …`), pak nahrát doklad z Dashboardu.
- `CLAUDE.md`: řádek `mail-analyze` v seznamu CLI příkazů.
- `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Testy

- `AnalysisQueueTest` — každá podmínka predikátu zvlášť (stav, Archiv/Koš,
  gate předzpracování, příznaky zprávy a schránky, aktivní claim).
- `AnalysisClaimServiceTest` — kódy chyb, `extend()` na uvolněném claimu.
- `AttachmentPreparerTest` — ZIP (i vadný a vnořený), odhad typu podle
  přípony, `.eml` přeskočená, limity počtu a velikosti s prioritou,
  zachování pořadí, dekódování `windows-1250`.
- `PromptRendererTest` — stávající šablona profilu
  (`modules/core/mail/profiles/czech_general.jsonc`) se vykreslí bez
  chyby; neznámá proměnná = výjimka; zakázaný tag / filtr = výjimka.
- `OutputParserTest` — čistý JSON, JSON v markdown bloku, nevalidní JSON,
  porušení schématu, pole místo objektu.
- `AnthropicPricingTest` — nejdelší prefix, neznámý model.
- `LlmRetryTest`, `AnthropicLlmClientTest` (timeouty v curl volbách přes
  stávající test seam; `isTransient()` včetně 429 se stropem útraty;
  `errorCode` z těla chyby).
- `AnalysisRunnerTest` — s falešným `LlmClient`: úspěch (výsledek
  zapsaný, claim uvolněný, stav 30), `schema_error` (stav 70), přechodná
  chyba (stav 10) a strop třikrát za hodinu (stav 70), `max_tokens`
  (stav 70), strop útraty (jediné volání, `config_error`, stav 70),
  bez slotu (žádný claim), bez klíče (žádný zápis), claim
  expirovaný během volání (výsledek se nezapíše).
- `MailAnalyzeCommandTest` — validace voleb, návratové kódy.
- Integrační: zpráva s PDF přílohou → `AnalysisRunner::run()` s falešným
  LLM vracejícím uložený výstup → stejné řádky v
  `core_mail_message_analyses` a stejný stav zprávy jako přes `/result`.

## Ověření na dev serveru (zdroj dat v režimu `volný`)

1. Démon na serveru neběží nebo je `ai.analysis.maxConcurrent` nastavené
   pro novou cestu; `ds-upgrade` není potřeba (beze změny schématu).
2. **Schéma:** všechny uložené úspěšné běhy (`analysis_json` v
   `core_mail_message_analyses`) projdou `OutputParser` validací proti
   aktuálnímu `output_schema` svého profilu — rozdíl mezi `opis`
   a Pythonem se projeví tady, ne v provozu.
3. Nahrát z Dashboardu fakturu v PDF → do minuty stav „Analyzováno“,
   návrh v tabu Návrh, běh v tabu Analýzy s tokeny, cenou a trváním.
4. Nahrát ZIP se dvěma PDF, obrázek účtenky, zprávu bez příloh.
5. Reanalýza téže zprávy → nový běh; porovnat `canonical_json` s během
   přes démona (stejný model a prompt — rozdíly jen v mezích běžné
   variability modelu, ne ve struktuře).
6. ISDOC faktura → žádný běh AI (import v předzpracování), viz Pasti.
7. Backend bez klíče → zpráva zůstane „Ve frontě“, v logu jedno varování
   za sweep, žádné řádky v Analýzách.
8. Špatný klíč → „Analýza selhala“ s hláškou z `AnalysisErrorPresenter`.
9. `maxConcurrent = 1`, nahrát pět dokladů naráz → zpracují se postupně,
   nikdy dva claimy současně.
10. Zabít běžící `mail-analyze` během volání → po expiraci lease vrátí
    reaper zprávu do fronty a sweep ji dokončí.
11. DS s gateway hostingu (backend s `base_url` gateway) → analýza projde
    a spotřeba se objeví v přehledu gateway.

### Ověřeno 2026-10-09 (dev server, DS `4l3j`, `060z`, `vlm9`)

1. Cron na dev stroji neběží, démon také ne; `ds-upgrade` nebyl potřeba.
2. **Schéma:** 17 uložených úspěšných běhů (4l3j 1, 060z 15, vlm9 1) prošlo
   `OutputParser` validací až na jeden běh s promptem v4.2.0, kde
   `vat.place = "foreign"` není v dnešním výčtu — vývoj schématu profilu,
   ne rozdíl mezi `opis` a Pythonem.
3. PDF faktura (zpráva založená in-process, `mail-analyze --message`): do
   20 s stav Analyzováno, návrh dokladu (číslo, dodavatel, řádek, částka),
   titulek a partner zprávy, Nová → K řešení, claim uvolněný `result`,
   `created_by` NULL, cena z tabulky; slot `ai-analysis-1.lock` založen.
   Spawn z HTTP příjmu/nahrání kryjí integrační testy (seam closure), živý
   odpojený spawn ověřen přes reanalýzu a sweep (bod 5, 9, 10).
4. ZIP se dvěma PDF → primární dokument faktura, VOP jako sekundární nález,
   `attachments[]` jen faktura; zpráva bez příloh → `other` / `promo`,
   docState zůstává Nová. Obrázek účtenky jen jednotkovým testem.
5. Reanalýza přes `AnalysisController::reanalyze()` s reálným
   `AnalysisSpawner` → nový běh, canonical shodný s prvním během
   (číslo, částka, IČO, řádky, dokonce stejné tokeny). Porovnání s během
   přes démona nebylo možné — démon tu neběží.
6. ISDOC → pořadí spawnu kryjí integrační testy (`IngestPreprocessTest`,
   `MailUploadEndpointTest`), živě neověřeno.
7. DS bez klíče (060z): `--message` končí „Not configured: NO_BACKEND“,
   zpráva zůstává Ve frontě, žádný řádek v Analýzách; `--sweep` „Skipped:
   no usable AI backend“ + jedno varování v logu.
8. Špatný klíč (060z, dočasně): `[ai_error] anthropic permanent: HTTP 401
   authentication_error: invalid x-api-key`, stav 70, presenter dává
   kategorii `aiError` (stejně jako u démona — hláška radí k příloze, ne
   ke klíči; kandidát na `configError` pro 401/403 v navazujícím tasku).
   Backend vrácen do původního stavu (bez klíče, neaktivní).
9. Tři runnery naráz při dvou slotech: dva „Done“, třetí „No free slot“;
   `--sweep` ho spustil odpojeně (`analysis.log`), všechny tři Analyzováno,
   nikdy víc než dva claimy současně.
10. `kill -9` běžícího runneru po claimu → zpráva Analyzuje se; po
    vypršení lease (simulováno) reaper vrátil 10, sweep spustil nový běh →
    Analyzováno, claimy `expired` + `result`.
11. DS přes AI gateway hostingu (vlm9 → gn5c): analýza prošla, model a
    tokeny v běhu; přehled gateway neověřen ručně.

## Pasti

- **Pořadí vůči ISDOC.** Spawn analýzy až po `deferIsdocImport()`.
  Dřívější spawn si zprávu claimne dřív, než detekce ISDOC doběhne, a AI
  zbytečně (a za peníze) analyzuje fakturu, kterou umí deterministický
  import. Podmíněný `UPDATE` v `deferIsdocImport()` zůstává jako pojistka.
- **Transakce.** Claim se commituje před voláním modelu; během volání
  nesmí být otevřená transakce ani držený zámek řádku. Zápis výsledku má
  vlastní transakci (jako dnes).
- **Klíč backendu.** Dešifrovaný klíč žije jen v paměti runneru — nikdy
  do logu, do zprávy výjimky ani do argumentů procesu (spawn předává jen
  id zprávy).
- **Dvě různé 429.** Běžný rate limit (tempo za minutu, s `retry-after`)
  je přechodný. Vyčerpaný měsíční strop útraty organizace se vrací taky
  jako 429 `rate_limit_error`, ale bez `retry-after` a s
  `error_code = enforced_spend_limit_reached` — API stojí do začátku
  dalšího měsíce a opakování nepomůže. Gateway hostingu propouští stav
  i tělo odpovědi beze změny, takže runner oba případy rozliší i přes ni;
  u hostovaných zdrojů dat jde o strop **společné** organizace, ne
  o nastavení zákazníka — hláška pro uživatele nemá radit „zkontrolujte
  klíč“. Vlastní limit útraty nastavený v konzoli poskytovatele vrací
  400 `invalid_request_error`; ten končí jako `ai_error` bez opakování.
- **Timeout curl.** `AnthropicLlmClient` dnes žádný timeout nenastavuje;
  bez kroku 3 může runner viset déle než lease a zprávu zpracují dva
  procesy.
- **Lease a opakování.** Tři pokusy s limitem 840 s se do lease 900 s
  nevejdou — proto `extend()` před každým pokusem. Když `extend()` vrátí
  `false`, runner končí bez zápisu.
- **Práva `RUN_DIR`.** Do `/opt/shipard/run` dnes zapisuje jen cron.
  Runner spouští i PHP-FPM (spawn z requestu) — ověřit v `PermissionSpec`,
  že adresář je pro uživatele FPM zapisovatelný; pokud ne, rozšířit
  kontrakt práv a kontrolu v `doctor`. Nezapisovatelný adresář slotů
  nesmí analýzu tiše vypnout — chyba do logu při každém běhu.
- **Souběh s démonem.** Dokud protokol existuje, může démon běžet vedle
  nové cesty. Claim je atomický, takže se zpráva nezpracuje dvakrát, ale
  není určené, kdo vyhraje. Na serveru, kde má zůstat démon, nastavit
  `maxConcurrent = 0`.
- **Twig není Jinja.** Twig neodstraňuje mezery před blokovým tagem na
  začátku řádku (`lstrip_blocks`). Výstup nemusí být bajtově shodný —
  zkontrolovat vykreslený prompt stávajícího profilu očima.
- **`opis/json-schema`.** Schéma profilu používá `oneOf`; ověřit chování
  při chybějícím `$schema` (výchozí draft) a že chybová zpráva nese cestu
  v dokumentu — z ní `AnalysisErrorPresenter` skládá hlášku pro uživatele.
- **Paměť.** 30 MB příloh je v base64 40 MB a ještě jednou v těle
  požadavku. CLI obvykle nemá `memory_limit`; nespoléhat na to — při
  startu runneru ho zvednout, pokud je nastavený níž než 512 MB.
- **Fáze 0.** `tasks/ai-models-phase0.md` zavádí
  `AiBackendResolver::tuning()`. Kdo přijde druhý, přizpůsobí se: runner
  má číst ladicí parametry backendu na jednom místě, ne vlastními
  `(float) $backend['temperature']`.
- **`created_by`.** Běh přes HTTP zapisuje uživatele `_ai_analyzer`, běh
  v procesu `NULL`. Ověřit, že nic nefiltruje běhy podle tohoto uživatele.

## Hotovo když

- [x] Kroky 1–6 jako samostatné commity, `php -l` na změněných souborech.
- [x] Po kroku 1 projdou stávající testy analýzy beze změny.
- [x] Cílené testy: `vendor/bin/phpunit --filter 'AnalysisQueueTest|AnalysisClaimServiceTest|AttachmentPreparerTest|PromptRendererTest|OutputParserTest|AnthropicPricingTest|LlmRetryTest|AnthropicLlmClientTest|AnalysisRunnerTest|MailAnalyzeCommandTest|AnalysisControllerTest|PreprocessRunnerTest'`, pak celá sada.
- [x] Nahraný doklad se na serveru bez démona vytěží do minuty.
- [ ] Pull protokol dál funguje (démon proti stejnému serveru zprávu
      zpracuje, když je `maxConcurrent = 0`) — endpointy kryjí nezměněné
      testy a integrační porovnání s runnerem; živý démon neověřen.
- [x] Ověření 1–11 provedeno (viz „Ověřeno 2026-10-09“); komentář do #85
      připraven, zapíše se s pushem.
- [x] Dokumentace podle kroku 6.
- [x] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
