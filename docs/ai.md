# Shipard — AI subsystém

Vestavěný AI asistent („chat nad svými daty") a sdílená infrastruktura pro
volání jazykových modelů. Subsystém stojí na jednom společném základu — **sadě
nástrojů (MCP serveru)** nad daty Shipardu — který konzumuje několik klientů.
Jazykový model je vyměnitelný díl; nástroje jsou napsané jednou.

> Tento dokument je **přehled**. Detaily viz [`mcp-server.md`](mcp-server.md)
> (jak přidat nástroj) a [`chat.md`](chat.md) (orchestrátor a frontend).

---

## 1. Vrstvená mapa

```
  Konzumenti:   vnitřní chat        externí MCP         analýza pošty
                (core/chat,         klienti             (mail-analyze,
                 in-process)        (/_mcp, HTTP)        PHP runner)
                     │                   │                    │
                     └──── nástroje ─────┘                    │ vlastní cesta
                              ▼                               │ (claim/result
              ┌─────────────────────────────────┐            │  jako služby,
              │ MCP nástroje (src/Api/Mcp)       │            │  ne přes MCP)
              │ doménové operace nad daty        │            │
              └───────────────┬─────────────────┘            │
                              ▼                               │
              ┌─────────────────────────────────┐            │
              │ Shipard data (moduly, PHP)       │            │
              └─────────────────────────────────┘            │
                                                              │
  Mozek:   LLM platforma ◄── AI funkce + připojení (core/ai) ──┘
           (Anthropic)       (co se volá: funkce → model, ladění;
                              čím: připojení = platforma + klíč)
```

Klíčový poznatek: **MCP server (= nástroje nad Shipardem) je společný základ,
vnitřní chat je jen jeden z jeho klientů.** Nástroje volá vnitřní chat
in-process (registr je hned vedle), externí klienti přes `/_mcp` po HTTP.
Analýza došlé pošty má vlastní cestu — runner `shpd-ds mail-analyze` v PHP
(fronta → claim → model → zápis, #85 D9); resolver AI funkcí i LLM klienta
ale sdílí.
Externí Python daemon `ai_analyzer` s pull protokolem byl zrušen
(#85 D20–D24, `tasks/ai-analyzer-removal.md`).

---

## 2. Komponenty a kde leží

| Vrstva | Umístění | Co |
|--------|----------|----|
| AI připojení | `modules/core/ai/` | `core_ai_backends` + `AIBackendDocument`/`Lookup`/`Viewer`; platforma, adresa, šifrovaný klíč (§5) |
| AI funkce | `modules/*/module.jsonc` (`aiFunctions`) + `modules/core/ai/` | deklarace funkcí (úroveň, výchozí ladění, požadavky), `core_ai_function_settings` + `AiFunctionSettingsDocument`/`Form`/`AiFunctionsViewer`, mapa úrovní `core.ai.tiers`, `AiFunctionProvisioner` (§5.2) |
| MCP server | `src/Api/Mcp/` + `src/Api/Controller/McpController.php`; routa `POST /api/v1/_mcp` | JSON-RPC 2.0 (`initialize`, `tools/list`, `tools/call`), registr nástrojů, mapování obálky |
| Nástroje | `modules/*/*/src/Mcp/` | doménové operace (viz §3) |
| Chat orchestrátor | `src/Api/Controller/ChatController.php` + `modules/core/chat/` | konverzace, SSE smyčka, in-process volání nástrojů |
| LLM klient a resolver | `src/Core/Ai/` (`LlmClient`, `AnthropicLlmClient`, `LlmRetry`, `ModelCatalog` + `ModelSpec`, `RequestShaper`, `AiFunctionRegistry` + `AiFunction`, `AiFunctionResolver` + `ResolvedAiCall` + `AiCallOverride`, `AiBackendResolver`) | streamovaný Anthropic Messages API (+ tool-use, timeouty); opakování přechodných chyb; katalog modelů (schopnosti, ceny, životní cyklus, §5.1) a tvarování požadavku podle něj; **jeden resolver volání** — z funkce, jejího řádku, mapy úrovní, připojení a přepsání volajícího složí hotové parametry (§5.3) |
| Analýza pošty | `modules/core/mail/src/Analysis/` + `src/Command/DataSource/MailAnalyzeCommand.php` | služby fronty / claimu / zápisu výsledku (sdílené s pull endpointy), příprava příloh, prompt v Twig sandboxu, parser výstupu, runner a sloty souběhu — viz [`../modules/core/mail/docs/ai-analysis.md`](../modules/core/mail/docs/ai-analysis.md) § Analýza v procesu |
| Dashboard shrnutí | `src/Core/Dashboard/DashboardSummaryService.php` + `modules/core/ai/tables/core_ai_dashboard_summary.jsonc` | generované shrnutí feedu (SSE, cache dle hashe digestu) — viz [`dashboard.md`](dashboard.md) §11 |
| Frontend | `frontend/src/components/chat/` + `api/chat.js` | pohled „Chat", SSE konzumace přes `fetch` + reader |

---

## 3. Katalog nástrojů a tiery podle rizika

Nástroje se neřadí podle modulů, ale podle **rizika** — to určuje, kolik
samostatnosti dostane AI. Vnitřní chat v1 nabízí modelu **jen čtecí tier**
(`McpTool::isReadOnly()`).

| Tier | Brzda | Nástroje |
|------|-------|----------|
| **Čtení** | žádná (bezpečné) | `persons_search`, `persons_get`, `documents_search`, `documents_aggregate`, `mail_list_pending`, `registry_search`, `help_search`, `help_get_page` |
| **Koncepty** | zápis do `docState` Konceptu (10) + lidská revize; `autoCreateMode='safe'` (nezakládá master data) | `mail_draft_document` |
| **Akce** | (potvrzení / zatím nezavedeno) | — |

`documents_search` vrací **konkrétní doklady**, `documents_aggregate` **součty
a počty** seskupené podle dimenze (partner / typ dokladu / fiskální měsíc /
období DPH) — žebříčky a časové řady. Agregace patří do SQL, ne do sčítání
odstránkovaných výsledků modelem.

Katalog roste podle schopností systému: nástroj smí tvrdit jen to, co data
umí pravdivě zodpovědět — a jen to, co vrací **on sám** (např. „po splatnosti"
ano, „neuhrazeno" ne: stav úhrady žádný z dokladových nástrojů nevrací).

---

## 4. Cesty k jazykovému modelu

| Cesta | AI funkce | Kdo volá LLM | Režim | Nástroje |
|-------|-----------|--------------|-------|----------|
| **Analýza pošty** | `mail.extraction` (balanced, 32768, teplota 0, `pdf`) | PHP `AnalysisRunner` (CLI `shpd-ds mail-analyze`: spawn po příjmu / předzpracování / reanalýze + minutový sweep) přes `AnthropicLlmClient` | strukturovaný výstup (JSON dle `output_schema` profilu), streamovaně, timeouty 180 / 840 s, opakování přechodných chyb | — |
| **Vnitřní chat** | `chat.default` (balanced, 4096, `tools`) | PHP `AnthropicLlmClient` (in-process) | streamovaně (SSE), tool-use smyčka | čtecí MCP nástroje |
| **Dashboard shrnutí** | `dashboard.summary` (fast, 2000) | PHP `AnthropicLlmClient` přes `DashboardSummaryService` | streamovaně (SSE), **bez tools** (délku drží prompt) | — |
| **Klasifikace štítků** | `exchange.contentTags` (fast, 8000) | PHP `ContentTagClassifier` (obsahová eskalace řádků při analýze) | jedno volání na doklad | — |
| **Historie účtování** | `exchange.bookingHistory` (balanced, 16000) | PHP `BookingHistoryClassifier` (CLI `booking-history`) | jedno volání na dávku textů | — |

Každá cesta volá jednu **AI funkci** (§5.2) a parametry volání dostává
hotové z `AiFunctionResolver::resolve()` (§5.3) — připojení, klíč, model,
strop tokenů, teplotu a ladění; sama už žádný parametr modelu neskládá.
Analýza pošty resolver volá v claimu (`AnalysisClaimService`) s přepsáním
z AI profilu, `booking-history` s přepsáním z voleb příkazu. Souběh analýz
hlídá limit per server `ai.analysis.maxConcurrent` (sloty `flock`), ne AI
gateway hostingu (#85 D11, D15).

**Strop tokenů** (`maxTokens`) deklaruje každá funkce (sloupec v tabulce
výše); zdroj dat ho smí přepsat na řádku funkce nebo v profilu pošty
(`max_tokens` > 0). Jediné skutečné číslo žije v deklaraci modulu — limit
nezkamení v datech každého DS. Na modelech s thinking se přemýšlení počítá
do `max_tokens`, proto jsou stropy **strop, ne cena** — řádově vyšší než
délka odpovědi (#85 F0-D5). Na modelu bez thinking se spotřeba nemění.

**Teplota** je jen v deklaraci funkce (dnes ji má jen `mail.extraction`,
`0`); není to nastavení pro správce. **Thinking a effort** deklarace nemají
(= neposílat, jako dosud); zdroj dat je nastaví u funkce nebo profilu —
`auto` = výslovně neposílat, `default` = podle funkce (§5.2). **Hodnoty se
před odesláním tvarují podle katalogu modelů** (§5.1, #85 D32): kombinaci,
kterou model nepřijímá (řada 5 s nevýchozí teplotou nebo `thinking:
disabled`, `between_tools` s `xhigh` / `max`, `xhigh` na Sonnetu 4.6),
`RequestShaper` v `AnthropicLlmClient` vynechá nebo nahradí nejbližší
platnou hodnotou a úpravu zapíše do logu (`info`, model + seznam úprav).
Vlastní ID modelu mimo katalog se tvaruje podle `model_like`
(`LlmChatParams::$shapeAs`, D43). Chybějící compiled config = beze změny,
chybu 400 pak vrátí API a volání selže čitelně v logu, u analýzy pošty
jako `config_error` v tabu Analýzy.

**Kontrola `stop_reason`** (#85 F0-D4): `LlmChatResult::isComplete()` je
`true` jen pro `end_turn`, `tool_use`, `stop_sequence` a chybějící stop
reason; `max_tokens`, `refusal` (bezpečnostní klasifikátor řady 5, může
zasáhnout i neškodný obsah), `model_context_window_exceeded` a nové hodnoty
jsou neúplný výsledek. Runner analýzy ho ukládá jako `ai_error` bez
opakování (`anthropic: stop_reason <hodnota>`, u `max_tokens` dnešní
hláška), klasifikátory a shrnutí vrací `null` a **shrnutí se necachuje**,
chat jen loguje.

Výsledek extrakce navíc prochází obohacením řádků (`RowEnrichmentPipeline`):
deterministická vrstva z historie dokladů partnera (`RowHistoryEnricher`,
bez LLM volání) + obsahová eskalace pro nepokryté řádky (klasifikace do
taxonomie štítků — pravidlem IČO, jinak levným LLM voláním) — viz
`modules/core/mail/docs/ai-analysis.md`, sekce „Obohacení řádků z historie"
a „Obsahová eskalace (content tags)".

**Soukromí digestu shrnutí**: prompt shrnutí obsahuje titulky karet
(partneři/částky z hlaviček dokladů) — stejná data, jaká analýza pošty LLM už
posílá při extrakci; žádná nová datová hranice. Plný `canonical_json` se do
promptu nikdy nedává.

---

## 5. Připojení, katalog, AI funkce a resolver

Tři vrstvy nastavení (tasks/ai-functions.md, #85 D1, D3, D4, D36–D48):

| Vrstva | Co nese | Kde | Kdo mění |
|--------|---------|-----|----------|
| **AI připojení** | přístup: platforma, adresa, šifrovaný klíč | `core_ai_backends` (Nastavení → AI → Připojení), `ai-backend-set-key` | správce DS, provisioning hostingu |
| **Katalog modelů + mapa úrovní** | jak s modelem mluvit (schopnosti, ceny, životní cyklus) a který model dnes znamená úroveň `fast` / `balanced` / `strong` | `core.ai.models`, `core.ai.tiers` (repo) | vývoj (evaluace) |
| **AI funkce** | co se volá: úroveň modelu, výchozí strop a teplota, požadavky; per DS přepsání připojení, modelu a ladění | deklarace `aiFunctions` v `module.jsonc` → cfgItem `core.ai.functions`; řádky `core_ai_function_settings` (Nastavení → AI → Funkce); profil pošty pro `mail.extraction` | modul (deklarace), správce DS (přepsání) |

Nasazením fáze 1b se chování nezměnilo: všechny úrovně ukazují na
`claude-sonnet-4-6`, výchozí ladění funkcí odpovídá dřívějšku (teplota 0
jen u vytěžení, stropy 32768 / 4096 / 8000 / 16000 / 2000). Výchozí
modely pro řadu 5 doplní evaluace (fáze 2).

### 5.1 Připojení

`core_ai_backends` je pool **připojení** k platformě: `provider`
(popisek „Platforma“, hodnoty z cfgItemu `core.ai.platforms` — dialekt
API a výchozí adresa; zatím jen `anthropic`), `base_url` (AI gateway
hostingu), šifrovaný `api_key`, `is_default`, `is_active`. Model ani ladění
připojení **nenese** (D39); sloupec `model` zůstává v definici jako
nullable mimo UI — v databázích byl NOT NULL a `ds-upgrade` sloupce
nemaže. Detaily: [`core_ai_backends.md`](../modules/core/ai/tables/core_ai_backends.md).

- Klíč je šifrovaný přes `DsSecretCipher` — viz [`operations/secrets.md`](operations/secrets.md).
- Nastavení klíče: `bin/shpd-ds ai-backend-set-key --backend default`
  (klíč skrytým vstupem nebo ze STDIN, `--api-key` jen pro provisioning
  agenta; aktivuje připojení). Auto-provisioning vytvoří připojení
  `default` při `ds-upgrade` (bez modelu, nepotřebuje katalog).
- **AI přes hosting gateway (D5/D6):** DS hostovaný pod portálem může místo
  vlastního klíče používat AI gateway hostingu — připojení má `base_url`
  = gateway (`…/api/v1/_hosting/ai-gw`) a `api_key` = gateway token
  (`shpd_gw_…`). Na straně DS se nemění žádný kód: `AnthropicLlmClient`
  si na `base_url` sám připojuje `/v1/messages` a autentizuje se
  `x-api-key`; gateway model jen předává. Zápis: `ai-backend-set-key
  --backend default --api-key shpd_gw_… --base-url https://portal…/_hosting/ai-gw`
  (u nových DS to dělá provisioning agent automaticky). **Vlastní klíč
  zůstává rovnocennou cestou** (D6) — `--base-url ''` vrátí připojení na
  přímé Anthropic API. Detaily gateway: [`hosting.md`](hosting.md) §5.5,
  runbook [`operations/ai-gateway.md`](operations/ai-gateway.md).
- **Lifecycle:** jediný ruční krok je jednorázový při prvním zřízení DS —
  `ai-backend-set-key`. Všechno ostatní drží `ds-upgrade` automaticky
  a bezpodmínečně (i pod `skipProvisioning`): připojení `default`, řádky
  AI funkcí, default profil + version sync profilu ze šablony, jednorázový
  převod dřívějšího ladění (§5.2), jednorázová deaktivace účtu
  `_ai_analyzer` zrušeného externího analyzeru (#85 D21). `ds-reset`
  připojení s klíči, řádky funkcí i profily zachovává (`keepOnReset`),
  takže reset ani upgrade žádnou ruční AI akci nevyžadují.
- **Platform scope:** v1 jen `anthropic`; `bedrock` (modely per platforma
  v mapě úrovní) přijde ve fázi 3. Rozhraní `LlmClient` drží dveře pro
  další dialekty otevřené.

### 5.2 Katalog modelů a mapa úrovní

Jedno místo v repu, kde Shipard ví, „jak s modelem mluvit“ (#85 fáze 1a,
`tasks/ai-model-catalog.md` D30–D35): cfgItem **`core.ai.models`**
(`modules/core/ai/config/models.jsonc`, modul `core.ai`), čtený přes
compiled config třídou `Shipard\Core\Ai\ModelCatalog` (položky =
`ModelSpec`). Klíč položky je ID modelu na přímém API bez datové přípony
(`claude-sonnet-4-6`); `find()` přijme i ID s příponou
(`claude-sonnet-4-5-20250929`) a ID z Bedrocku (`anthropic.claude-sonnet-5-5`)
— shoda je přesné ID nebo ID + `-YYYYMMDD`, vyhrává nejdelší kandidát,
prefix sám nestačí (`claude-opus-4` nechytí `claude-opus-4-8`);
`platformOf()` řekne, které platformě zadané ID patří.

| Pole | Význam |
|------|--------|
| `name`, `vendor`, `dialect`, `order` | popis; `order` řadí výpisy |
| `tier` | `fast` / `balanced` / `strong` / `null` (model nenabízíme, jen ceník); modely s úrovní jsou v nabídce formulářů funkce a profilu |
| `platformIds` | ID per platforma (`anthropic`, `bedrock`); chybějící platforma = model tam není (`MODEL_NOT_ON_PLATFORM`) |
| `capabilities.temperature` | přijímá nevýchozí teplotu (řada 4.7 a novější ne) |
| `capabilities.thinkingModes`, `thinkingDefault` | přijímané hodnoty `thinking.type`; chování bez parametru `off` / `adaptive` |
| `capabilities.effortLevels`, `thinkingOffMaxEffort` | přijímané úrovně (`[]` = parametr nezná); nejvyšší effort spolu s vypnutým thinkingem (`disabled` / `between_tools`) |
| `capabilities.maxOutputTokens` | horní mez `max_tokens` |
| `capabilities.chatToolLoop` | tool smyčka funguje bez vracení thinking bloků = model bez parametru nepřemýšlí; funkce s požadavkem `tools` model s `false` odmítne (`MODEL_NOT_SUPPORTED_FOR_FUNCTION`, zobecněná pojistka D35, zmizí s fází 4) |
| `prices` | USD za milion tokenů `input` / `output`, volitelně `longPrompt {over, input, output}` (Haiku 5.5 nad 100 000 vstupních tokenů) |
| `lifecycle` | `status` `active` / `deprecated` / `retired`, `retiresAt`, `retiresNotBefore`, `replacedBy` (naše náhrada při `ds-upgrade`) |

**Mapa úrovní** — cfgItem **`core.ai.tiers`** (`config/tiers.jsonc`, D37):
`fast`, `balanced`, `strong` → `name`, `order`, `model`. Všechny tři
zatím `claude-sonnet-4-6`. `ModelCatalog::defaultModel(úroveň)` ji čte;
`ModelCatalogFileTest` hlídá, že každá úroveň ukazuje na aktivní model
katalogu s úrovní. Model z mapy, který končí, hlídá repo — resolver ho jen
jednou za proces zaloguje (D42).

**`null` (nebo chybějící pole) = nevíme → neomezovat**, hodnota se pošle
beze změny. Co nejde doložit z dokumentace, zůstává `null` — žádné odhady;
zdroje a datum poslední kontroly jsou v hlavičce souboru. Položka smí nést
jen ceny (modely, které nenabízíme). Bez compiled configu je katalog
prázdný: nic se netvaruje, ceny jsou 0 s varováním, resolver model
neověřuje a bez mapy úrovní volání bez výslovného modelu nesestaví
(`NO_MODEL`).

**Tvarování požadavku** (`RequestShaper`, volá ho `AnthropicLlmClient`
před sestavením těla, platí pro všechny volající; položku katalogu hledá
podle `shapeAs ?? model`):

1. teplota, kterou model nepřijímá → vynechat;
2. `thinking` mimo `thinkingModes` → náhrada `disabled` ↔ `between_tools`
   (tu z dvojice, kterou model má), jinak vynechat;
3. `effort` mimo `effortLevels` → nejbližší nižší přijímaná (model effort
   nezná → vynechat); vypnutý thinking s `thinkingOffMaxEffort` → effort
   nejvýš tato mez (Sonnet 5.5 `between_tools`, Opus 5 a Haiku 5.5
   `disabled`: `high`);
4. `max_tokens` nad `maxOutputTokens` → snížit.

Úpravy jdou do logu jednou za volání (`info`, model + seznam úprav); model
mimo katalog se netvaruje a varování padne jednou za proces. Pro výchozí
nastavení (Sonnet 4.6, bez ladění) je tělo požadavku stejné jako před
katalogem (test). Co z katalogu čtou ostatní: `ModelCatalog::costUsd()`
(cena běhu analýzy, `cost_usd`; vlastní ID modelu = 0 s varováním),
`AiFunctionProvisioner` / `MailAiProvisioner` (`retiredReplacement()`
u řádků funkcí a profilů při `ds-upgrade`), `ModelOptions` (nabídka
modelů ve formulářích), `ModelRetirementJudge` (upozornění). Katalog se
uplatní na straně zdroje dat; gateway hostingu model jen předává.

**Jak přidat nebo změnit model:** doplnit položku do `models.jsonc` s odkazem
na stránku modelu (schopnosti jen doložené, jinak `null`; ceny vždy;
`lifecycle` s datem), případně přesměrovat úroveň v `tiers.jsonc`, spustit
`vendor/bin/phpunit --filter 'ModelCatalogFileTest'` (konzistence: ceny,
úrovně mapy na aktivní modely, `replacedBy` na aktivní model, hodnoty
z cfgItemů `core.ai.thinkingModes` / `effortLevels`), na zdrojích dat
`ds-upgrade` (překompiluje config). Vyřazení modelu = `status: deprecated`
+ `retiresAt` + `replacedBy`; `ds-upgrade` pak výslovné modely u funkcí
a profilů přepíše a vypíše `[MODEL]`, upozornění `core.ai.model_retiring`
/ `core.mail.profile_model_retiring` hlásí konec do 30 dnů.

### 5.3 AI funkce

**Deklarace** (`aiFunctions` v `module.jsonc`, D36; validace
`ModuleDefinition`, kompilace do cfgItemu `core.ai.functions`,
čtení `AiFunctionRegistry` / `AiFunction` — `modules.md` → Pole `aiFunctions`):

```jsonc
"aiFunctions": [
    {
        "id": "mail.extraction",
        "name": "Incoming mail extraction", "name:cs": "Vytěžení došlé pošty",
        "description:cs": "…",
        "tier": "balanced",                 // fast | balanced | strong
        "defaults": { "maxTokens": 32768, "temperature": 0 },
        "requires": ["pdf"]                 // "tools", "pdf"
    }
]
```

Pět funkcí dnes: `mail.extraction` (core.mail), `exchange.contentTags`
a `exchange.bookingHistory` (core.exchange), `dashboard.summary`
a `chat.default` (core.ai). `core.ai` nezávisí na modulech, které funkce
deklarují — registr je čte z compiled configu.

**Nastavení per zdroj dat** — tabulka `core_ai_function_settings`
([`core_ai_function_settings.md`](../modules/core/ai/tables/core_ai_function_settings.md),
D38): jeden řádek na deklarovanou funkci, zakládá `ds-upgrade`
(`AiFunctionProvisioner`, idempotentně; uživatel řádky nezakládá ani
nemaže — viewer AI funkce nenabízí Přidat, dokument mazání odmítne).
Sloupce `connection` (NULL = výchozí připojení), `model` (NULL = podle
úrovně; z katalogu, nebo vlastní ID — pak povinné `model_like`, D43),
`thinking` / `effort` (`default` = podle funkce, `auto` = neposílat,
jinak hodnota), `max_tokens` (NULL = podle funkce). Formulář (Nastavení →
AI → Funkce) nabízí model jako `select` s `allowCustom`
(`ComboInput`, datalist — volby z katalogu, vlastní ID jde napsat; D44).
**`default` není `auto`** — nepléct ve formuláři ani v kódu.

**Profil pošty** (D4) přepisuje totéž o úroveň výš jen pro `mail.extraction`:
`core_mail_ai_profiles.backend` (NULL = podle funkce), `model`,
`model_like`, `thinking`, `effort`, `max_tokens` (0 = podle funkce).
Reanalýza zprávy s jiným profilem = jiný model jen pro tu zprávu.

**Převod dřívějšího nastavení** (D41, D46) udělal `ds-upgrade`
jednorázově při nasazení fáze 1b: model a ladění připojení (`thinking` /
`effort` jiné než `auto`) do profilů navázaných na připojení (`[CONVERT]
profile …`; vazba na výchozí připojení se vynulovala), nastavení
`exchange.contentTag.backend` do řádku `exchange.contentTags` (klíč
smazán), ladění výchozího připojení do řádků všech funkcí; `max_tokens`
a `temperature` připojení se nepřevedly — jen `[NOTE]`, že strop
vytěžení je teď 32768 podle funkce. Převedené připojení dostalo `model =
NULL` (značka hotovo); druhý běh nic nevypíše.

**Upozornění `core.ai.model_retiring` / `core.mail.profile_model_retiring`**
(D42, D47): funkce nebo aktivní profil s výslovně nastaveným modelem,
který podle katalogu do 30 dnů končí (warning) nebo už skončil (error);
společný úsudek `ModelRetirementJudge` (core.ai). Modely z mapy úrovní
hlídá repo.

### 5.4 Resolver volání a chyby

`Shipard\Core\Ai\AiFunctionResolver::resolve(string $functionId,
?AiCallOverride $override = null): ResolvedAiCall` (D40) — jediné místo,
které skládá parametry volání. Pořadí zdrojů **pro každou hodnotu
zvlášť**: přepsání volajícího (`AiCallOverride`: profil pošty přes
`fromRow()`, volby `booking-history --backend/--model/--model-like`) →
řádek funkce → deklarace funkce / mapa úrovní. Připojení: přepsání →
řádek → výchozí aktivní připojení. Model: zadané ID jde na platformu
beze změny, pokud na ni patří (i s datovou příponou); ID jiné platformy
se přeloží přes `platformIds`; vlastní ID mimo katalog odchází tak, jak
je napsané, a tvaruje se podle `model_like`.

`ResolvedAiCall` nese platformu, adresu (připojení, jinak výchozí adresa
platformy), dešifrovaný klíč (jen v paměti; `toArray()` ho nevrací), ID
modelu pro platformu, `shapeAs`, `catalogModel`, strop, teplotu, thinking,
effort, `connectionId` / `connectionCode` a **původ každé hodnoty**
(`origins`: `override` / `function` / `declaration` / `tier` / `default`
— pro přehled v Nastavení, fáze 1c). `chatParams(system, messages,
tools, stallTimeout, timeout)` z něj složí `LlmChatParams`.

Chyby = `AiFunctionException` s kódem; všechno jsou chyby nastavení, ne
běhu:

| Kód | Kdy | Chat (`ChatController`) | Claim pošty (`AnalysisClaimException`) |
|-----|-----|-------------------------|----------------------------------------|
| `NO_CONNECTION` | žádné výchozí aktivní připojení, nebo zvolené chybí / je neaktivní | `NO_BACKEND` 503 | `NO_BACKEND` 409 |
| `KEY_MISSING` | připojení bez klíče | `NO_BACKEND` 503 | `BACKEND_KEY_MISSING` 409 |
| `SECRETS_UNAVAILABLE`, `KEY_CORRUPTED` | klíč nejde dešifrovat | `BACKEND_KEY_ERROR` 500 | `SECRETS_UNAVAILABLE` / `BACKEND_KEY_CORRUPTED` 500 |
| `MODEL_NOT_SUPPORTED_FOR_FUNCTION` | funkce vyžaduje `tools`, model má `chatToolLoop: false` (D35) | `AI_MODEL_NOT_SUPPORTED_FOR_CHAT` 503 | `BACKEND_CONFIG_INVALID` 409 |
| `UNKNOWN_MODEL`, `MODEL_NOT_ON_PLATFORM`, `NO_MODEL`, `UNKNOWN_PLATFORM`, `UNKNOWN_FUNCTION` | vlastní ID bez `model_like`, model mimo platformu, chybí mapa úrovní / deklarace (compiled config před `ds-upgrade`) | `AI_NOT_CONFIGURED` 503 | `BACKEND_CONFIG_INVALID` 409 (D48) |

Shrnutí dashboardu a klasifikátory na jakoukoli chybu resolveru tiše
degradují (bez shrnutí / bez klasifikace, `info` v logu); konverzace
chatu přišpendlená na neaktivní připojení padá na výchozí jako dřív.
Klíč cache shrnutí dashboardu zahrnuje model (D48) — změna modelu funkce
shrnutí přegeneruje.

**Chat zůstává na Sonnetu 4.6 (#85 F0-D6):** tool smyčka na modelech
s thinking vyžaduje vracet thinking bloky beze změny a historii jen
přidávat — `AnthropicLlmClient::finalizeBlocks()` je dnes zahazuje.
Pojistku dělá resolver (`requires: ["tools"]` × `chatToolLoop`); UI
asistenta ukáže „Asistent zatím neumí pracovat s modelem …, správce
nastaví jiný“. Pojistka zmizí s fází 4.

### 5.5 Jak vyzkoušet jiný model

- **Jedna funkce na jiném modelu** (např. štítky na Haiku 5.5): Nastavení
  → AI → Funkce → funkce → Model (z katalogu, nebo vlastní ID
  + „Tvarovat jako model“); případně jiné připojení a ladění. Platí pro
  všechny volající té funkce; ostatní funkce beze změny.
- **Vytěžení pošty na jiném modelu jen pro některé zprávy:** druhý AI profil
  s přepsaným modelem (Nastavení → Pošta → AI profily), reanalýza zprávy
  s tímto profilem nebo `profile_override`. Druhé připojení jen tehdy,
  když jde o jiný klíč / účet (`ai-backend-set-key --backend <kód>`).
- **Historie účtování:** `booking-history --model claude-haiku-5-5`
  (`--model-like` u vlastního ID, `--backend` pro jiné připojení).
- Co pro řadu 5 platí (odmítá teplotu, bez `thinking` přemýšlí adaptivně,
  `between_tools` jen s effortem do `high`, `refusal`, o ~30 % víc tokenů
  na stejný text, odmítá `tool_choice` any/tool a prefill):
  `tasks/ai-models-phase0.md`. Katalog nepoužitelné hodnoty ladění vynechá
  / nahradí (§5.2) — volání projde a úprava je v logu. Ceny bere
  `ModelCatalog::costUsd()` z katalogu.

---

## 6. Bezpečnostní zásady

- **Auth + DS scoping.** Každý nástroj běží v rámci přihlášeného uživatele a
  jeho zdroje dat (DS je resolvnutý z hostu/cesty před dispatchem). MCP server
  nesmí být cesta, jak obejít oprávnění.
- **Read-only invariant chatu.** Smyčka nabízí modelu a spouští **jen** nástroje
  s `isReadOnly()===true` — i kdyby si model vyžádal jiný či zápisový nástroj
  (vrátí se `tool_result` s `is_error`, nespustí se).
- **Brzda u konceptů.** `mail_draft_document` zakládá jen **Koncept**
  (`targetDocState=10`) a jede `autoCreateMode='safe'` — nikdy nezakládá novou
  master data (dodavatele/položky) ani nefinalizuje doklad; to dělá člověk přes
  stavový automat dokladu.
- **Bez MCP OAuth v1.** Cizí klienti se autentizují stávajícím Bearer tokenem /
  API klíčem (first-party); MCP OAuth flow je odložený.

---

## 7. Datum a kontext v chatu

Jazykový model nemá vlastní smysl pro „dnešek". `ChatController::systemPrompt()`
proto k systémovému promptu při každém požadavku **přilepí aktuální datum** a
instrukci „neodhaduj podle tréninkových dat — ověř nástrojem". Bez toho model
spadne na své tréninkové datum a může pokládat současný rok za budoucnost.

---

## 8. Související dokumenty

- [`mcp-server.md`](mcp-server.md) — MCP server a jak přidat nástroj (dev guide)
- [`chat.md`](chat.md) — orchestrátor, SSE kontrakt, datový model, frontend
- [`core_ai_backends.md`](../modules/core/ai/tables/core_ai_backends.md),
  [`core_ai_function_settings.md`](../modules/core/ai/tables/core_ai_function_settings.md),
  [`core_mail_ai_profiles.md`](../modules/core/mail/tables/core_mail_ai_profiles.md),
  [`core_chat_conversations.md`](../modules/core/chat/tables/core_chat_conversations.md),
  [`core_chat_messages.md`](../modules/core/chat/tables/core_chat_messages.md)
- [`operations/secrets.md`](operations/secrets.md) — šifrování klíčů
- [`modules.md`](modules.md) — deklarace `aiFunctions` v `module.jsonc`
- [`cli.md`](cli.md) — `ai-backend-set-key`, `booking-history` a další příkazy
- [`mail/api-contract.md`](mail/api-contract.md) — analýza došlé pošty (sousední cesta)
