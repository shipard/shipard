# AI — připojení, AI funkce a jeden resolver volání (#85 fáze 1b)

**Stav:** naplánováno — rozhodnutí D36–D43 potvrzena 2026-10-10 (#85), připraveno k implementaci

## Status / cíl

Řádek `core_ai_backends` dnes míchá tři věci: **přístup** (adresa, klíč),
**model** a **ladění** (teplota, thinking, effort, strop tokenů). Všech pět
volajících bere „výchozí backend“, takže celý zdroj dat jede na jednom
modelu s jedním laděním; výjimky jsou ad hoc (nedeklarované nastavení
`exchange.contentTag.backend`, `--backend` u historie účtování, backend
u profilu pošty).

Cíl:

- **Připojení** nese jen přístup.
- **AI funkce** (`mail.extraction`, `exchange.contentTags`, …) deklaruje
  modul; funkce má úroveň modelu a výchozí ladění.
- **Model pro funkci** určuje mapa úrovní v repu; zdroj dat může u funkce
  přepsat připojení, model i ladění.
- **Jeden resolver** vrací hotové parametry volání; všech pět volajících
  přechází na něj.

Nasazením se **nemá změnit chování**: všechny úrovně zatím ukazují na
`claude-sonnet-4-6`, výchozí ladění funkcí odpovídá dnešku. Výchozí modely
pro řadu 5 doplní evaluace (fáze 2).

GitHub Issue: shipard/shipard#85 (D1, D3, D4, D27, D28, D36–D43; fáze 1b).

## Návaznost

- `tasks/ai-model-catalog.md` — katalog, `RequestShaper`, pojistka chatu.
  **Předpoklad**, hotovo.
- `tasks/ai-models-phase0.md` — `tuning()`, sloupce ladění backendu
  (tímto taskem zanikají).
- `tasks/mail-analysis-inprocess.md` — `AnalysisClaimService`,
  `AnalysisRunner`.
- `tasks/hosting-05-ai-gateway.md` — provisioning zapisuje řádek připojení
  (`ai-backend-set-key --base-url`); nemění se.
- Navazuje fáze 2 (evaluace) a 1c (sekce AI v Nastavení).

## Před implementací přečti

- `docs/ai.md` celé; `modules/core/ai/tables/core_ai_backends.md`;
  `modules/core/mail/docs/ai-analysis.md` (AI profily).
- `docs/modules.md` — deklarace v `module.jsonc` a jak se čtou
  (`ModuleDefinition`; vzor `alertChecks`, `sendPurposes`).
- `docs/table-definitions.md` — §10 (`ds-upgrade` sloupce nemaže,
  uvolnění `NOT NULL` provádí), konvence konfiguračních tabulek.
- `docs/alerts.md` — alert check (vzor `core.mail.analysis_stalled`).
- Kód:
  - `src/Core/Ai/` — `AiBackendResolver`, `ModelCatalog`, `ModelSpec`,
    `RequestShaper`, `LlmChatParams`, `AnthropicLlmClient`.
  - Volající: `src/Api/Controller/ChatController.php` (řádky kolem
    270–330, pojistka D35), `src/Core/Dashboard/DashboardSummaryService.php`,
    `modules/core/exchange/src/Enrich/ContentTagClassifier.php`,
    `RowEnrichmentPipeline.php`,
    `modules/core/exchange/src/BookingHistory/BookingHistoryClassifier.php`,
    `src/Command/DataSource/BookingHistoryCommand.php`,
    `modules/core/mail/src/Analysis/AnalysisClaimService.php`
    (`resolveBackend`), `AnalysisRunner.php` (`chatParams()`).
  - `modules/core/mail/src/MailAiProvisioner.php`,
    `src/Command/DataSource/DsUpgradeCommand.php`,
    `AiBackendSetKeyCommand.php`.
  - `modules/core/ai/` — `module.jsonc`, tabulka, formulář, viewer,
    lookup, dokument backendu.

## Rozhodnutí k designu (potvrzená)

### Směr (#85, zamčeno 2026-10-10)

- ✓ **D1** připojení vs. model · ✓ **D3** AI funkce · ✓ **D4** profil
  pošty s volitelným přepsáním · ✓ **D27** výchozí model z repu ·
  ✓ **D28** vlastní ID modelu.

### Prováděcí (#85, potvrzeno 2026-10-10)

- ✓ **D36 — Deklarace funkcí.** Nový klíč `aiFunctions` v `module.jsonc`:

  ```jsonc
  "aiFunctions": [
      {
          "id": "mail.extraction",
          "name": "Incoming mail extraction",
          "name:cs": "Vytěžení došlé pošty",
          "name:en": "Incoming mail extraction",
          "description:cs": "…",
          "tier": "balanced",                 // fast | balanced | strong
          "defaults": { "maxTokens": 32768, "temperature": 0 },
          "requires": ["pdf"]                 // "tools", "pdf"
      }
  ]
  ```

  | Funkce | Modul | Úroveň | Výchozí ladění | Požadavky |
  |--------|-------|--------|----------------|-----------|
  | `mail.extraction` | `core.mail` | balanced | `maxTokens` 32768, `temperature` 0 | `pdf` |
  | `exchange.contentTags` | `core.exchange` | fast | `maxTokens` 8000 | — |
  | `exchange.bookingHistory` | `core.exchange` | balanced | `maxTokens` 16000 | — |
  | `dashboard.summary` | `core.ai` | fast | `maxTokens` 2000 | — |
  | `chat.default` | `core.ai` | balanced | `maxTokens` 4096 | `tools` |

  `thinking` a `effort` nemá ve výchozím ladění žádná funkce (= neposílat,
  jako dnes). `temperature` je jen v deklaraci, není to nastavení pro
  správce; na modelu, který ji nepřijímá, ji vynechá `RequestShaper`.
- ✓ **D37 — Mapa úrovní.** cfgItem `core.ai.tiers`: `fast`, `balanced`,
  `strong` → `name` (cs/en), `order`, `model`. Všechny tři zatím
  `claude-sonnet-4-6`. `ModelCatalog::defaultModel($tier)` čte mapu;
  příznak `tierDefault` u modelů zaniká.
- ✓ **D38 — Nastavení funkcí v tabulce** `core_ai_function_settings`:
  jeden řádek na deklarovanou funkci, zakládá ho `ds-upgrade`
  (idempotentně); uživatel řádky nezakládá ani nemaže.

  | Sloupec | Typ | Význam |
  |---------|-----|--------|
  | `function_id` | string, unikátní | ID z deklarace |
  | `connection` | reference `core_ai_backends`, nullable | `NULL` = výchozí připojení |
  | `model` | string, nullable | ID z katalogu nebo vlastní; `NULL` = podle úrovně |
  | `model_like` | string, nullable | u vlastního ID: model z katalogu, podle kterého se požadavek tvaruje (D43) |
  | `thinking` | enumString, default `default` | `default` = podle funkce, `auto` = neposílat, jinak hodnota z `core.ai.thinkingModes` |
  | `effort` | enumString, default `default` | obdobně z `core.ai.effortLevels` |
  | `max_tokens` | int, nullable | `NULL` = podle funkce |

  Bez stavů dokladu; v `keepOnReset`.
- ✓ **D39 — Připojení.** Tabulka `core_ai_backends` zůstává, v UI
  „AI připojení“ / „AI connections“. Sloupec `provider` se stává
  platformou: popisek „Platforma“, hodnoty z nového cfgItemu
  `core.ai.platforms` (zatím jen `anthropic`: název, dialekt
  `anthropic-messages`, výchozí adresa). Z definice mizí `max_tokens`,
  `temperature`, `thinking`, `effort`. **`model` v definici zůstává jako
  nullable, mimo formulář i viewer** — v databázích je `NOT NULL` bez
  výchozí hodnoty a `ds-upgrade` sloupce nemaže, takže po vyjmutí
  z definice by selhal `INSERT` nového připojení. Popis sloupce:
  „nepoužívá se od fáze 1b; odstraní servisní výmaz“.
- ✓ **D40 — Jeden resolver.** `Shipard\Core\Ai\AiFunctionResolver`:

  ```php
  resolve(string $functionId, ?AiCallOverride $override = null): ResolvedAiCall
  ```

  Pořadí zdrojů pro každou hodnotu: `$override` (profil pošty, volba
  příkazu) → řádek funkce → deklarace funkce / mapa úrovní.
  `ResolvedAiCall` nese platformu, adresu, dešifrovaný klíč, ID modelu pro
  platformu, `shapeAs` (ID z katalogu, podle kterého se tvaruje), ladění,
  `connectionId` a původ každé hodnoty (kvůli výpisu v Nastavení).
  Chyby jako `AiFunctionException` s kódem: `NO_CONNECTION`,
  `KEY_MISSING`, `SECRETS_UNAVAILABLE`, `KEY_CORRUPTED`,
  `MODEL_NOT_ON_PLATFORM`, `MODEL_NOT_SUPPORTED_FOR_FUNCTION` (funkce
  vyžaduje `tools` a model má `chatToolLoop: false` — zobecněná pojistka
  D35), `UNKNOWN_MODEL` (vlastní ID bez `model_like`).
  `LlmChatParams` dostane `?string $shapeAs`; `AnthropicLlmClient` hledá
  v katalogu podle `shapeAs ?? model`.
- ✓ **D41 — Převod dnešních dat** při `ds-upgrade`, idempotentně, před
  tím, než volající přestanou číst staré sloupce (čte se syrovým SQL):
  1. Backend navázaný na profil pošty: model jiný než model úrovně →
     `profile.model`; `thinking` / `effort` jiné než `auto` →
     `profile.thinking` / `profile.effort`. Je-li to výchozí připojení →
     `profile.backend = NULL`.
  2. Nastavení `exchange.contentTag.backend` → `connection` (a případně
     `model`) řádku `exchange.contentTags`; klíč nastavení smazat.
  3. Výchozí backend s `thinking` / `effort` jiným než `auto` → stejná
     hodnota do řádků všech funkcí (dnes platí pro všechny volající).
  4. `max_tokens` a `temperature` backendu se **nepřevádějí** — funkce
     mají vlastní výchozí strop a teplotu (viz Pasti).
  Výpis každé provedené změny; druhý běh nic nedělá.
- ✓ **D42 — Upozornění `core.ai.model_retiring`** — „AI model brzy
  končí“: funkce nebo profil pošty má **výslovně nastavený** model, který
  podle katalogu do 30 dnů končí (`retiresAt`) nebo už skončil. Modely
  z mapy úrovní hlídá repo, ne zdroj dat — pro ně jen varování v logu
  resolveru.
- ✓ **D43 — Vlastní ID modelu.** Pole `model` ve formuláři funkce
  i profilu: výběr z katalogu (modely s úrovní) nebo vlastní text.
  Vlastní ID vyžaduje `model_like`; požadavek se tvaruje podle něj, na
  platformu odchází ID tak, jak je napsané, cena je 0 s varováním
  (neznámý ceník se neodhaduje).

**Profil pošty (D4):** `core_mail_ai_profiles.backend` je nově nullable
(`NULL` = výchozí připojení); nové sloupce `model` (nullable), `model_like`,
`thinking`, `effort` (enum s `default`) — přepsání pro `mail.extraction`.
`max_tokens` profilu zůstává (0 = podle funkce).

## Scope

### V rozsahu

- Deklarace funkcí, mapa úrovní, tabulka nastavení, resolver (D36–D40).
- Převod pěti volajících; zánik `AiBackendResolver::tuning()`
  a `defaultBackend()` v jejich kódu, nastavení
  `exchange.contentTag.backend`.
- Převod dat a úprava tabulek backendů a profilů (D39, D41).
- Minimální UI: viewer a formulář „AI funkce“ vedle připojení
  (`settingsItems`, stejné místo jako dnes backendy), formulář připojení
  bez modelu a ladění, formulář profilu s přepsáním.
- Upozornění (D42), vlastní ID modelu (D43).
- Dokumentace.

### Mimo rozsah

- Sekce AI v Nastavení, přehled efektivních hodnot a úprav požadavku,
  nápověda (D8) — fáze 1c.
- Výchozí modely úrovní pro řadu 5 a výchozí `effort` funkcí — po
  evaluaci (fáze 2).
- Platforma `bedrock`, modely per platforma v mapě úrovní (fáze 3).
- `chat.deep`, thinking bloky v tool smyčce (fáze 4).
- Přejmenování tabulky `core_ai_backends` a sloupce `provider`.

## Kroky

Každý krok je samostatný commit. Kroky 1–3 chování nemění.

### 1. Deklarace a mapa úrovní

- `ModuleDefinition`: čtení `aiFunctions` (validace: `id` unikátní napříč
  moduly, `tier` z mapy, `requires` ze známých hodnot).
  `Shipard\Core\Ai\AiFunctionRegistry` (`fromConfig`, `get($id)`, `all()`)
  + hodnotový objekt `AiFunction`.
- Deklarace pěti funkcí v `core.mail`, `core.exchange`, `core.ai`.
- cfgItemy `core.ai.tiers`, `core.ai.platforms`; `ModelCatalog::defaultModel()`
  čte mapu; `tierDefault` z `models.jsonc` pryč (upravit
  `ModelCatalogFileTest`: každá úroveň z mapy ukazuje na aktivní model
  katalogu se shodnou nebo vyšší úrovní schopností — stačí „existuje
  a je `active`“).

### 2. Schéma

- `core_ai_function_settings` (definice, `.md`, dokument s validací,
  viewer, formulář, lookup modelů z katalogu); `module.jsonc`
  (`tables`, `keepOnReset`, `viewers`, `forms`, `settingsItems`).
- `core_mail_ai_profiles`: `backend` nullable, nové sloupce přepsání;
  formulář profilu.
- `core_ai_backends`: `provider` s popiskem Platforma a cfgItemem;
  **sloupce ladění zatím v definici zůstávají** (čte je převod v kroku 5).
- `ds-upgrade`: založení řádků funkcí (v `MailAiProvisioner` nebo novém
  `AiFunctionProvisioner` v `core.ai` — podle toho, kde to dává smysl
  vzhledem k závislostem modulů; řádky zakládat pro všechny deklarované
  funkce aktivních modulů).

### 3. Resolver

- `AiFunctionResolver`, `ResolvedAiCall`, `AiCallOverride`,
  `AiFunctionException`; `LlmChatParams::$shapeAs` + úprava klienta.
- `ResolvedAiCall::chatParams(…)` — pomocník, který z rozhodnutí a obsahu
  volání (system, messages, tools, timeouty) složí `LlmChatParams`.

### 4. Převod volajících

- `ChatController` → `chat.default`. Kódy chyb pro frontend zůstávají
  (`NO_BACKEND`, `LLM_NOT_CONFIGURED`, `AI_MODEL_NOT_SUPPORTED_FOR_CHAT`),
  mapují se z `AiFunctionException`.
- `DashboardSummaryService` → `dashboard.summary`.
- `ContentTagClassifier`, `RowEnrichmentPipeline` →
  `exchange.contentTags`; čtení nastavení `exchange.contentTag.backend`
  pryč.
- `BookingHistoryClassifier`, `BookingHistoryCommand` →
  `exchange.bookingHistory`; volba `--backend` zůstává jako přepsání
  připojení, nová `--model`.
- `AnalysisClaimService`, `AnalysisRunner` → `mail.extraction`
  s přepsáním z profilu; `AnalysisClaim` nese `ResolvedAiCall`. Kódy
  `AnalysisClaimException` (`NO_BACKEND`, `BACKEND_KEY_MISSING`, …)
  zůstávají.
- Strop tokenů analýzy: `profile.max_tokens` (> 0) → řádek funkce →
  deklarace (32768).
- `AiBackendResolver`: zůstane jen to, co resolver potřebuje (načtení
  připojení, dešifrování klíče), nebo se do resolveru vstřebá.

### 5. Převod dat a úklid tabulek

- Převod podle D41 v `ds-upgrade` (po založení řádků funkcí, před
  kompilací nových definic nezáleží — sloupce v databázi zůstávají).
- `core_ai_backends`: `max_tokens`, `temperature`, `thinking`, `effort`
  z definice; `model` nullable a skrytý (D39). Formulář, viewer, dokument,
  `core_ai_backends.md`. Provisioner zakládá připojení bez modelu;
  přepis vyřazených modelů (`retiredReplacement`) se přesouvá na
  `model` řádků funkcí a profilů.
- cfgItemy `core.ai.thinkingModes` / `effortLevels`: doplnit hodnotu
  `default` jen tam, kde ji používá přepsání (nebo samostatné seznamy
  pro přepsání — zvolit to, co nerozbije stávající význam `auto`).

### 6. Upozornění a vlastní model

- `core.ai.model_retiring` (D42) + test.
- Vlastní ID modelu ve formulářích funkce a profilu (D43): validace
  v dokumentu (`model` mimo katalog ⇒ `model_like` povinné a z katalogu).

### 7. Dokumentace a uzavření

- `docs/ai.md`: nová stavba — Připojení, Katalog modelů, AI funkce
  (deklarace, mapa úrovní, přepsání, pořadí zdrojů), Resolver, chyby;
  postup „jak vyzkoušet jiný model“ přes přepsání funkce nebo profil.
- `core_ai_backends.md`, `core_ai_function_settings.md`,
  `core_mail_ai_profiles.md`, `modules/core/mail/docs/ai-analysis.md`.
- `docs/modules.md` — klíč `aiFunctions`.
- `docs/operations/ai-gateway.md`, `docs/hosting.md` — „backend“ →
  „připojení“ tam, kde jde o řádek s gateway tokenem.
- Texty v UI a chybách: „AI backend“ → „AI připojení“; hláška pojistky
  chatu odkazuje na nastavení AI funkcí.
- `CLAUDE.md` (pokud se mění seznam CLI), `**Stav:**`,
  `python3 scripts/tasks-index.py`.

## Testy

- `AiFunctionRegistryTest` — duplicitní ID, neznámá úroveň, neznámý
  požadavek.
- `AiFunctionResolverTest` — pořadí zdrojů pro každou hodnotu (přepsání
  → řádek → deklarace), výchozí připojení, neaktivní připojení, chybějící
  klíč, vlastní model s `model_like` a bez něj, model mimo platformu,
  funkce s `tools` na modelu bez tool smyčky, `default` vs `auto`.
- Volající: stávající testy s falešným klientem ověří, že **tělo
  požadavku je stejné jako před změnou** (analýza: Sonnet 4.6, teplota 0,
  32768; chat 4096; štítky 8000; historie 16000; shrnutí 2000).
- `AnalysisClaimServiceTest` — profil bez připojení (výchozí), profil
  s přepsáním modelu a ladění.
- Převod dat (`DsUpgradeCommandTest` / test provisioneru): každé pravidlo
  D41, idempotence, zdroj dat bez čehokoli k převodu.
- `ModelRetiringAlertCheckTest` — výslovný model končící do 30 dnů,
  skončený, model z mapy úrovní (mlčí).
- `ModuleDefinitionTest` — klíč `aiFunctions`.

## Ověření (dev server, zdroje dat v režimu `volný`)

1. `ds-upgrade` na ukázkovém zdroji dat: pět řádků funkcí; profily
   zkušebních backendů (Sonnet 5.5, Haiku 5.5) mají model přepsaný
   v profilu; výpis převodu; druhý běh nic nevypíše.
2. Nahrát doklad bez ISDOC → analýza na Sonnetu 4.6 jako dřív (stejné
   tokeny řádově, v logu žádná úprava požadavku).
3. Reanalýza s profilem pro Sonnet 5.5 a pro Haiku 5.5 → běhy na těchto
   modelech.
4. U funkce `exchange.contentTags` nastavit model `claude-haiku-5-5` →
   další analýza klasifikuje štítek na Haiku (log), extrakce dál na 4.6.
5. Asistent a shrnutí dashboardu fungují; u `chat.default` nastavit
   `claude-sonnet-5-5` → srozumitelná chyba; vrátit.
6. Vlastní ID modelu: neexistující ID s `model_like` → požadavek odejde
   tvarovaný, API vrátí 404, v Analýzách „AI není správně nastavená“.
7. Upozornění: funkci dočasně nastavit `claude-sonnet-4-5` → „AI model
   brzy končí“ po `alerts-run`; vrátit.
8. Hosting zdroj dat: `ds-upgrade` projde, připojení s gateway adresou
   beze změny, analýza přes gateway funguje.

## Pasti

- **`model` je v databázích `NOT NULL` bez výchozí hodnoty.** Nevyjímat
  z definice (D39).
- **`max_tokens` backendu.** Některé zdroje dat mají na výchozím backendu
  4096; analýza ho dnes bere jako strop výstupu (profil 0 → backend) —
  dlouhé doklady na nich končí na `max_tokens`. Po převodu platí strop
  funkce 32768. Je to zamýšlená změna, ne regrese; uvést ve výpisu
  `ds-upgrade` („strop tokenů analýzy 4096 → 32768“), když k ní dochází.
- **Teplota.** Backend s `temperature = NULL` (někdo ji vymazal kvůli
  řadě 5) dnes teplotu neposílá; po převodu ji funkce `mail.extraction`
  posílá jako 0 všude, kde ji model přijímá. Na řadě 5 se nic nemění.
- **`default` není `auto`.** `default` = převzít z funkce; `auto` =
  parametr neposílat. Neplést ve formuláři ani v resolveru.
- **Pořadí kroků.** Převod dat čte staré sloupce; ty musí být v databázi
  (jsou — `ds-upgrade` nemaže) a kód je nesmí číst po kroku 4.
- **Závislosti modulů.** `core.ai` nesmí záviset na `core.mail` ani
  `core.exchange`; funkce si deklaruje každý modul sám a registr je čte
  z compiled configu.
- **Gateway hostingu** zapisuje jen připojení; model do řádku nepíše
  a po tomto tasku ani nemá kam.
- **Cache shrnutí dashboardu** — ověřit, že změna modelu funkce
  neservíruje staré shrnutí donekonečna (klíč cache).
- **Klasifikátory a `fast`.** Úroveň `fast` zatím znamená Sonnet 4.6;
  nic se nezrychlí ani nezlevní, dokud evaluace nezmění mapu.

## Hotovo když

- [ ] Kroky 1–7 jako samostatné commity, `php -l`, cílené testy, celá
      sada, `npm run build` při změně frontendu.
- [ ] Těla požadavků všech pěti volajících pro výchozí nastavení jsou
      stejná jako před změnou (testy).
- [ ] Ověření 1–8 provedeno, výsledek do #85.
- [ ] Dokumentace podle kroku 7.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
