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
  Mozek:   LLM provider ◄───── core/ai backendy ─────────────┘
           (Anthropic)         (provider / model / klíč; sdílené)
```

Klíčový poznatek: **MCP server (= nástroje nad Shipardem) je společný základ,
vnitřní chat je jen jeden z jeho klientů.** Nástroje volá vnitřní chat
in-process (registr je hned vedle), externí klienti přes `/_mcp` po HTTP.
Analýza došlé pošty má vlastní cestu — runner `shpd-ds mail-analyze` v PHP
(fronta → claim → model → zápis, #85 D9); backend i LLM klienta ale sdílí.
Externí Python daemon `ai_analyzer` s pull protokolem byl zrušen
(#85 D20–D24, `tasks/ai-analyzer-removal.md`).

---

## 2. Komponenty a kde leží

| Vrstva | Umístění | Co |
|--------|----------|----|
| Sdílené backendy | `modules/core/ai/` | `core_ai_backends` + `AIBackendDocument`/`Lookup`/`Viewer`; provider, model, šifrovaný klíč |
| MCP server | `src/Api/Mcp/` + `src/Api/Controller/McpController.php`; routa `POST /api/v1/_mcp` | JSON-RPC 2.0 (`initialize`, `tools/list`, `tools/call`), registr nástrojů, mapování obálky |
| Nástroje | `modules/*/*/src/Mcp/` | doménové operace (viz §3) |
| Chat orchestrátor | `src/Api/Controller/ChatController.php` + `modules/core/chat/` | konverzace, SSE smyčka, in-process volání nástrojů |
| LLM klient | `src/Core/Ai/` (`LlmClient`, `AnthropicLlmClient`, `AiBackendResolver`, `LlmRetry`, `ModelCatalog` + `ModelSpec`, `RequestShaper`) | streamovaný Anthropic Messages API (+ tool-use, timeouty); resolver default backendu + dešifrování klíče; opakování přechodných chyb; katalog modelů (schopnosti, ceny, životní cyklus, §5.1) a tvarování požadavku podle něj |
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

| Cesta | Kdo volá LLM | Režim | Nástroje |
|-------|--------------|-------|----------|
| **Analýza pošty** | PHP `AnalysisRunner` (CLI `shpd-ds mail-analyze`: spawn po příjmu / předzpracování / reanalýze + minutový sweep) přes `AnthropicLlmClient` | strukturovaný výstup (JSON dle `output_schema` profilu), streamovaně, timeouty 180 / 840 s, opakování přechodných chyb | — |
| **Vnitřní chat** | PHP `AnthropicLlmClient` (in-process) | streamovaně (SSE), tool-use smyčka | čtecí MCP nástroje |
| **Dashboard shrnutí** | PHP `AnthropicLlmClient` přes `DashboardSummaryService` | streamovaně (SSE), **bez tools**, `maxTokens` 2000 (délku drží prompt) | — |
| **Klasifikace štítků** | PHP `ContentTagClassifier` (obsahová eskalace řádků při analýze) a `BookingHistoryClassifier` (CLI `booking-history`) | jedno volání na doklad / dávku textů, `maxTokens` 8000 / 16000 | — |

Všechny cesty čtou backend (provider/model/klíč) z `core_ai_backends`; default
backend na PHP straně resolvuje `AiBackendResolver`, analýza pošty bere
profil a backend z claimu (`AnalysisClaimService`). Souběh analýz hlídá limit
per server `ai.analysis.maxConcurrent` (sloty `flock`), ne AI gateway
hostingu (#85 D11, D15).

`max_tokens` je kaskáda **AI profil → backend → default runneru**
(`AnalysisRunner::DEFAULT_MAX_TOKENS` = 32768); `0` = nenastaveno, spadni níž. Jediné skutečné číslo žije
v kódu — limit tak nezkamení v datech každého DS. Chat backendový
`max_tokens` respektuje, při 0/NULL drží vlastní fallback 4096
(`ChatController`); dashboard shrnutí a klasifikátory mají vlastní konstanty
a backend limit nečtou. Na modelech s thinking se přemýšlení počítá do
`max_tokens`, proto jsou tyhle konstanty **strop, ne cena** — řádově vyšší
než délka odpovědi (2000 / 8000 / 16000; #85 F0-D5). Na modelu bez thinking
se spotřeba nemění.

**Ladicí parametry backendu** (`core_ai_backends`, #85 F0-D1, F0-D2, F0-D7):
`temperature` (NULL = neposílat), `thinking` a `effort` (`auto` = neposílat,
jinak `thinking: {type}` / `output_config: {effort}`). Na parametry je
převádí jediné místo, `AiBackendResolver::tuning()`; `thinking` a `effort`
z něj berou všichni volající, **teplotu jen runner analýzy pošty** — chat,
shrnutí i klasifikátory posílají `temperature: null` a posílat nezačnou
(existující řádky mají `0`, změnilo by se jim chování). **Hodnoty se před
odesláním tvarují podle katalogu modelů** (§5.1, #85 D32): kombinaci, kterou
model nepřijímá (řada 5 s nevýchozí teplotou nebo `thinking: disabled`,
`between_tools` s `xhigh` / `max`, `xhigh` na Sonnetu 4.6), `RequestShaper`
v `AnthropicLlmClient` vynechá nebo nahradí nejbližší platnou hodnotou
a úpravu zapíše do logu (`info`, model + seznam úprav). Model mimo katalog
nebo chybějící compiled config = beze změny, chybu 400 pak vrátí API
a volání selže čitelně v logu, u analýzy pošty jako `config_error` v tabu
Analýzy.

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

## 5. Backendy a konfigurace

`core_ai_backends` je **sdílený pool** providerů (provider, model, šifrovaný
klíč). Per DS může být víc backendů, právě jeden `is_default`. Detaily sloupců:
[`core_ai_backends.md`](../modules/core/ai/tables/core_ai_backends.md).

- Klíč je šifrovaný přes `DsSecretCipher` — viz [`operations/secrets.md`](operations/secrets.md).
- Nastavení klíče: `bin/shpd-ds ai-backend-set-key --backend default` (klíč skrytým vstupem nebo ze STDIN, `--api-key` jen pro provisioning agenta; aktivuje backend). Auto-provisioning vytvoří `default` backend při `ds-upgrade`.
- **Výchozí model a přemostění (#85 F0-D8, D34):** nový backend vzniká
  s výchozím modelem úrovně `balanced` z katalogu modelů (§5.1, dnes
  `claude-sonnet-4-6`) a `temperature` NULL. Sonnet 4.5 je od 30. 9. 2026
  deprecated a **30. 11. 2026 končí**; `ds-upgrade` proto u všech backendů
  jednorázově přepíše modely se statusem `deprecated` / `retired` na jejich
  `lifecycle.replacedBy` (i s datovou příponou) a vypíše `[MODEL]`. ID
  s prefixem platformy (`anthropic.…`) nechává být. Sonnet 4.6 je stejná
  cena i tokenizer, přijímá `temperature` a bez parametru nepřemýšlí —
  chování se nemění. **Aktivní je nejméně do 17. 2. 2027**; do té doby musí
  proběhnout fáze 4 (thinking bloky v tool smyčce chatu) a přechod na řadu 5.
- **Chat zůstává na výchozím backendu se Sonnetem 4.6 (#85 F0-D6):** tool
  smyčka na modelech s thinking vyžaduje vracet thinking bloky beze změny
  a historii jen přidávat — `AnthropicLlmClient::finalizeBlocks()` je dnes
  zahazuje. Totéž platí pro shrnutí dashboardu (sdílí výchozí backend).
  **Pojistka (#85 D29, D35):** má-li model backendu v katalogu
  `chatToolLoop: false` (bez parametru přemýšlí — celá řada 5),
  `ChatController` ho nevolá a vrátí 503 `AI_MODEL_NOT_SUPPORTED_FOR_CHAT`
  s modelem v `details`; UI asistenta ukáže „Asistent zatím neumí pracovat
  s modelem …, správce nastaví jiný“. `null` (nevíme) a `true` = beze změny.
  Pojistka zmizí s fází 4.
- **Jak zkoušet model řady 5 (`claude-sonnet-5-5`, `claude-haiku-5-5`)
  přes druhý backend:** založit backend v Nastavení → AI backendy
  (teplota prázdná, thinking / effort `auto` nebo konkrétní hodnota),
  klíč přes `ai-backend-set-key --backend <kód>`; pro analýzu pošty
  druhý AI profil s tímto backendem (reanalýza zprávy s profilem, nebo
  `profile_override`), pro štítky nastavení `exchange.contentTag.backend`
  = id backendu, pro historii účtování `booking-history --backend`. Co
  pro řadu 5 platí (odmítá teplotu, bez `thinking` přemýšlí adaptivně,
  `between_tools` jen s effortem do `high`, `refusal`, o ~30 % víc tokenů
  na stejný text, odmítá `tool_choice` any/tool a prefill):
  `tasks/ai-models-phase0.md`. Teplotu `0` nebo `thinking: disabled`
  z backendu katalog pro řadu 5 vynechá / nahradí (§5.1) — volání projde
  a úprava je v logu. Ceny bere `ModelCatalog::costUsd()` z katalogu.
- **AI přes hosting gateway (D5/D6):** DS hostovaný pod portálem může místo
  vlastního klíče používat AI gateway hostingu — backend má `base_url` =
  gateway (`…/api/v1/_hosting/ai-gw`) a `api_key` = gateway token
  (`shpd_gw_…`). Na straně DS se nemění žádný kód: `AnthropicLlmClient`
  si na `base_url` sám připojuje `/v1/messages` a autentizuje se
  `x-api-key`. Zápis: `ai-backend-set-key --backend
  default --api-key shpd_gw_… --base-url https://portal…/_hosting/ai-gw`
  (u nových DS to dělá provisioning agent automaticky). **Vlastní klíč
  zůstává rovnocennou cestou** (D6) — `--base-url ''` vrátí backend na
  přímé Anthropic API. Detaily gateway: [`hosting.md`](hosting.md) §5.5,
  runbook [`operations/ai-gateway.md`](operations/ai-gateway.md).
- **Lifecycle:** jediný ruční krok je jednorázový při prvním zřízení DS —
  `ai-backend-set-key` (klíč backendu). Všechno ostatní drží `ds-upgrade`
  automaticky a bezpodmínečně (i pod `skipProvisioning`): default backend,
  default profil + version sync profilu ze šablony, jednorázová deaktivace
  účtu `_ai_analyzer` zrušeného externího analyzeru (#85 D21). `ds-reset`
  backendy s klíči i profily zachovává (`keepOnReset`), takže reset ani
  upgrade žádnou ruční AI akci nevyžadují.
- **Provider scope:** v1 jen `anthropic`; rozhraní `LlmClient` drží dveře pro
  další providery (lokální, OpenAI) otevřené, aniž by se předčasně abstrahoval
  formát streamu.

### 5.1 Katalog modelů

Jedno místo v repu, kde Shipard ví, „jak s modelem mluvit“ (#85 fáze 1a,
`tasks/ai-model-catalog.md` D30–D35): cfgItem **`core.ai.models`**
(`modules/core/ai/config/models.jsonc`, modul `core.ai`), čtený přes
compiled config třídou `Shipard\Core\Ai\ModelCatalog` (položky =
`ModelSpec`). Klíč položky je ID modelu na přímém API bez datové přípony
(`claude-sonnet-4-6`); `find()` přijme i ID s příponou
(`claude-sonnet-4-5-20250929`) a ID z Bedrocku (`anthropic.claude-sonnet-5-5`)
— shoda je přesné ID nebo ID + `-YYYYMMDD`, vyhrává nejdelší kandidát,
prefix sám nestačí (`claude-opus-4` nechytí `claude-opus-4-8`).

| Pole | Význam |
|------|--------|
| `name`, `vendor`, `dialect`, `order` | popis; `order` řadí výpisy |
| `tier`, `tierDefault` | `fast` / `balanced` / `strong` / `null` (model nenabízíme, jen ceník); `tierDefault: true` = výchozí model úrovně — zatím jen `balanced` (D34) |
| `platformIds` | ID per platforma (`anthropic`, `bedrock`); chybějící platforma = model tam není |
| `capabilities.temperature` | přijímá nevýchozí teplotu (řada 4.7 a novější ne) |
| `capabilities.thinkingModes`, `thinkingDefault` | přijímané hodnoty `thinking.type`; chování bez parametru `off` / `adaptive` |
| `capabilities.effortLevels`, `thinkingOffMaxEffort` | přijímané úrovně (`[]` = parametr nezná); nejvyšší effort spolu s vypnutým thinkingem (`disabled` / `between_tools`) |
| `capabilities.maxOutputTokens` | horní mez `max_tokens` |
| `capabilities.chatToolLoop` | tool smyčka chatu funguje bez vracení thinking bloků = model bez parametru nepřemýšlí (pojistka D35, zmizí s fází 4) |
| `prices` | USD za milion tokenů `input` / `output`, volitelně `longPrompt {over, input, output}` (Haiku 5.5 nad 100 000 vstupních tokenů) |
| `lifecycle` | `status` `active` / `deprecated` / `retired`, `retiresAt`, `retiresNotBefore`, `replacedBy` (naše náhrada při `ds-upgrade`) |

**`null` (nebo chybějící pole) = nevíme → neomezovat**, hodnota se pošle
beze změny. Co nejde doložit z dokumentace, zůstává `null` — žádné odhady;
zdroje a datum poslední kontroly jsou v hlavičce souboru. Položka smí nést
jen ceny (modely, které nenabízíme). Bez compiled configu je katalog prázdný:
nic se netvaruje, ceny jsou 0 s varováním, `ds-upgrade` backend nezaloží
(`[WARN]` + `[SKIP]`).

**Tvarování požadavku** (`RequestShaper`, volá ho `AnthropicLlmClient`
před sestavením těla, platí pro všechny volající):

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
backend (Sonnet 4.6, teplota 0, `auto`) je tělo požadavku stejné jako před
katalogem (test). Co z katalogu čtou ostatní: `ModelCatalog::costUsd()`
(cena běhu analýzy, `cost_usd`), `MailAiProvisioner` (`defaultModel('balanced')`
a `retiredReplacement()` při `ds-upgrade`), `ChatController` (`chatToolLoop`).
Katalog se uplatní na straně zdroje dat; gateway hostingu model jen předává.

**Jak přidat nebo změnit model:** doplnit položku do `models.jsonc` s odkazem
na stránku modelu (schopnosti jen doložené, jinak `null`; ceny vždy;
`lifecycle` s datem), spustit `vendor/bin/phpunit --filter
'ModelCatalogFileTest'` (konzistence: ceny, jediný `tierDefault` na úroveň,
`replacedBy` na aktivní model, hodnoty z cfgItemů `core.ai.thinkingModes`
/ `effortLevels`), na zdrojích dat `ds-upgrade` (překompiluje config).
Vyřazení modelu = `status: deprecated` + `retiresAt` + `replacedBy`;
`ds-upgrade` pak backendy přepíše a vypíše `[MODEL]`.

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
  [`core_chat_conversations.md`](../modules/core/chat/tables/core_chat_conversations.md),
  [`core_chat_messages.md`](../modules/core/chat/tables/core_chat_messages.md)
- [`operations/secrets.md`](operations/secrets.md) — šifrování klíčů
- [`cli.md`](cli.md) — `ai-backend-set-key` a další příkazy
- [`mail/api-contract.md`](mail/api-contract.md) — analýza došlé pošty (sousední cesta)
