# AI — zpracování v EU: platforma Amazon Bedrock (EU) (#85 fáze 3a)

**Stav:** částečně — kroky 1–5 hotové 2026-10-11 (5 commitů, celá sada 9 027 testů; `ds-upgrade` jen `4l3j`); ověření 1–7 s reálným klíčem odloženo: přihlášení klíčem i tvar chyb ověřeny, ale testovací účet AWS nemá přístup k žádnému modelu Anthropicu (403 „not available for this account“ i přímo v konzoli AWS) — řeší se na straně AWS; po zpřístupnění stačí `shpd-ds ai-test` a body 2–5, 7, výsledek do #85

## Status / cíl

Někteří zákazníci trvají na tom, aby jejich doklady zpracovával model
v datacentrech v EU. Přímé API Anthropicu takovou volbu nemá (jen `us`
a `global`). Claude je ale dostupný přes Amazon Bedrock stejným
Messages API a s geografickým profilem `eu.` běží požadavek jen
v regionech EU.

Cíl: **připojení typu „Amazon Bedrock (EU)“** — správce založí
připojení s klíčem Bedrocku a funkce, které přes něj běží, se zpracují
v EU. Po fázi 1 a 4 je to hlavně konfigurace: stejný dialekt, jiná
adresa, jiné ID modelu, jiné modely úrovní.

Rozsah je **strana zdroje dat s vlastním klíčem**. Směrování hostovaných
zdrojů dat přes AI gateway je navazující část 3b.

GitHub Issue: shipard/shipard#85 (D6, D57–D61; fáze 3a).

## Návaznost

- `tasks/ai-functions.md` — připojení, platformy (`core.ai.platforms`),
  `AiFunctionResolver::placeModel()`, mapa úrovní.
- `tasks/ai-model-catalog.md` — `platformIds.bedrock` v katalogu, ceny.
- `tasks/chat-thinking-blocks.md` — asistent na řadě 5 (**předpoklad**:
  na Bedrocku jiný model než řady 5 není).
- `tasks/ai-settings-section.md` — `ai-test`, detail funkce, nápověda.
- Navazuje 3b (gateway hostingu) a případně krátkodobé klíče (D59).

## Před implementací přečti

- `docs/ai.md` — Připojení, Katalog modelů, AI funkce, Resolver.
- Kód: `src/Core/Ai/AiFunctionResolver.php` (`resolve()` kolem řádku 137,
  `placeModel()`), `ModelCatalog` (`find()`, `platformOf()`,
  `defaultModel()`, `costUsd()`), `ResolvedAiCall`,
  `AnthropicLlmClient` (`exceptionFromErrorResponse()`),
  `modules/core/ai/config/` (`platforms.jsonc`, `tiers.jsonc`,
  `models.jsonc`), dokument a formulář připojení, `AiTestCommand`,
  `AnalysisRunner` (výpočet ceny).
- Dokumentace (zdroj pravdy):
  - <https://docs.aws.amazon.com/bedrock/latest/userguide/model-card-anthropic-claude-sonnet-5-5.html>
  - <https://docs.aws.amazon.com/bedrock/latest/userguide/api-keys.html>
  - <https://platform.claude.com/docs/en/build-with-claude/claude-in-amazon-bedrock>
  - <https://platform.claude.com/docs/en/about-claude/pricing> (oddíl
    Cloud platform pricing)

### Co platí pro Bedrock (ověřeno 2026-10-11)

- **Endpoint:** Messages API je na `bedrock-runtime`:
  `https://bedrock-runtime.{region}.amazonaws.com/anthropic/v1/messages`
  — stejný tvar požadavku i streamu (SSE) jako přímé API. Endpoint
  `bedrock-mantle` je pro Sonnet 5.5 dostupný jen v regionu GovCloud.
- **ID modelu:** základní `anthropic.claude-sonnet-5-5`; pro EU
  **geografický profil** `eu.anthropic.claude-sonnet-5-5` — požadavek ze
  zdrojového regionu v EU se zpracuje jen v regionech EU (Frankfurt,
  Stockholm, Milán, Španělsko, Irsko, Paříž). Zpracování v jediném
  regionu AWS pro tento model na `bedrock-runtime` nenabízí.
- **Přihlášení:** Bedrock API klíč jako bearer token; klient Anthropicu
  ho posílá stejně jako vlastní klíč (`x-api-key`) — **ověřeno `curl`
  2026-10-11**: hlavička `x-api-key` projde autentizací na
  `bedrock-runtime` (`Authorization: Bearer` také), tělo chyby má tvar
  Anthropicu (`{"type":"error","error":{…}}`). **Dlouhodobý klíč**
  má nastavitelnou platnost, patří IAM uživateli a **platí jen
  v regionu, kde vznikl** (jinak „Credential should be scoped to a valid
  region“); AWS ho doporučuje jen na zkoušení. **Krátkodobý klíč** platí
  nejvýš 12 hodin a generuje se z přihlašovacích údajů AWS.
- **Modely:** přes Messages API jen řada 4.7 a novější a Haiku 4.5;
  `claude-sonnet-4-6` ne.
- **Nepodporováno** (nepoužíváme): structured outputs, počítání tokenů,
  dávky, Files API, výpis modelů.
- **Cena:** regionální a geografické endpointy mají příplatek 10 %
  proti globálním.
- Kontrola prefixu thinking bloků platí i na Bedrocku — řeší D53.
- **SCP organizace AWS** může zakázat `bedrock:CallWithBearerToken` —
  pak každé volání s API klíčem končí 403 „explicit deny in a service
  control policy“ ve všech regionech (zjištěno 2026-10-11 na testovacím
  účtu; v `us-east-1` prošla autentizace i validace modelu, na akci padá
  totéž). Řeší správce organizace AWS, ne Shipard.
- **Účet bez přístupu k modelům Anthropicu** (zjištěno 2026-10-12 na
  novém samostatném účtu): po ověření účtu vrací Bedrock 403
  „<model> is not available for this account … contact AWS Sales“ pro
  každý model Anthropicu, i z konzole AWS. Není to region ani klíč;
  přístup k modelům třetích stran uděluje AWS účtu (platební metoda pro
  AWS Marketplace, stáří a tarif účtu, případně žádost na podporu).

## Rozhodnutí k designu (potvrzená)

- ✓ **D57 — Platforma `bedrock-eu` („Amazon Bedrock (EU)“).** Nová
  položka `core.ai.platforms`:
  - `dialect`: `anthropic-messages`,
  - `baseUrl`: `https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic`
    (výchozí; správce může v připojení zadat jiný region EU),
  - `modelIds`: `bedrock` — který klíč `platformIds` v katalogu použít,
  - `modelIdPrefix`: `eu.` — předřadí se ID z katalogu
    (`eu.anthropic.claude-sonnet-5-5`),
  - `priceMultiplier`: `1.1` (D60).

  Stávající `anthropic` dostane `modelIds: "anthropic"`, bez prefixu,
  násobitel 1. Klient se nemění (stejné hlavičky, stejná cesta
  `/v1/messages` za `baseUrl`).
- ✓ **D58 — Modely úrovní per platforma.** `core.ai.tiers.<úroveň>`
  dostane volitelné `platformModels`; pro `bedrock-eu` ukazují všechny
  tři úrovně na `claude-sonnet-5-5`. `ModelCatalog::defaultModel()`
  bere platformu připojení. Bez položky pro platformu platí `model`
  úrovně — a když ho platforma nenabízí, resolver vrátí
  `MODEL_NOT_ON_PLATFORM` jako dnes.
- ✓ **D59 — Přihlášení dlouhodobým klíčem.** Klíč Bedrocku se ukládá
  stejně jako klíč Anthropicu (`ai-backend-set-key`). Generování
  krátkodobých klíčů z přihlašovacích údajů AWS je navazující task, až
  to bude potřeba pro ostrý provoz.
- ✓ **D60 — Cena s násobitelem platformy.** Cena volání = ceník katalogu
  × `priceMultiplier` platformy připojení, počítaná na jednom místě.
- ✓ **D61 — Gateway hostingu zvlášť (3b)** — po ověření 3a.

## Scope

### V rozsahu

- Platforma `bedrock-eu`, ID modelu s prefixem, modely úrovní per
  platforma (D57, D58).
- Cena s násobitelem (D60).
- Čitelné chyby Bedrocku (klíč z jiného regionu, model bez povoleného
  přístupu).
- Formulář připojení (výběr platformy, výchozí adresa), přehled modelů
  (ID na platformách), detail funkce.
- Dokumentace a nápověda: jak založit připojení do EU.

### Mimo rozsah

- AI gateway hostingu a hostované zdroje dat (3b).
- Krátkodobé klíče, podpis SigV4, role IAM.
- Jiné geografické profily (`us.`, `global.`) a jiné cloudové platformy.
- Evaluace Sonnetu 5.5 na vytěžení (#103).
- Životní cyklus modelů podle Bedrocku (má vlastní termíny) — katalog
  nese termíny přímého API.

## Kroky

Každý krok je samostatný commit.

### 1. Platforma a ID modelu

Hotovo 2026-10-11 (commit `feat(ai): platforma Amazon Bedrock (EU)…`).
Odchylky od zadání: mapu platforem čte jediné místo — nový readonly
objekt `AiPlatform` + `AiPlatforms` (`src/Core/Ai/`), ne pět ad hoc
čtení cfgItemu. **Klient se přece měnit musel:** `AnthropicLlmClient`
odmítal `provider !== 'anthropic'`, takže by na `bedrock-eu` padlo každé
volání — nově `LlmChatParams::$dialect`, kontrola podle dialektu;
hlavičky a cesta beze změny. `platformOf()` vrací rodinu ID (klíč
`platformIds`), nové `geoPrefixOf()`; zadané ID s jiným geografickým
prefixem (`us.` na EU) se překládá na ID platformy jako každé jiné ID
jiné rodiny (ne chyba — pravidlo „ID jiné platformy se přeloží“).

- `platforms.jsonc`: položka `bedrock-eu`, nová pole `modelIds`,
  `modelIdPrefix`, `priceMultiplier` (i u `anthropic`).
- `AiFunctionResolver::placeModel()`: ID pro platformu =
  `modelIdPrefix` + `platformIds[modelIds]`. ID zadané správcem, které
  už na platformu patří (včetně prefixu `eu.`), jde ven beze změny.
- `ModelCatalog::find()` / `platformOf()`: rozpoznat i ID
  s geografickým prefixem (`eu.`, `us.`, `au.`, `global.` před
  `anthropic.`) — kvůli ceně a tvarování podle odpovědi i podle ručně
  zadaného ID.
- Přepis vyřazených modelů při `ds-upgrade` dál nechává ID platforem
  být.
- Testy: `AiFunctionResolverTest` (model úrovně na `bedrock-eu` →
  `eu.anthropic.claude-sonnet-5-5`; ručně zadané
  `eu.anthropic.claude-haiku-5-5` beze změny; `claude-sonnet-4-6` na
  `bedrock-eu` → `MODEL_NOT_ON_PLATFORM`), `ModelCatalogTest` (hledání
  s geografickým prefixem), test konzistence platforem (`modelIds`
  ukazuje na klíč, který katalog zná).

### 2. Modely úrovní per platforma

Hotovo 2026-10-11. Navíc: `ModelOptions::catalogModels()` nechá
v nabídce uloženou hodnotu, kterou katalog zná, ale není klíčem nabídky
(ID platformy, datová přípona) — jinak by select hodnotu nezobrazil;
formulář funkce se po změně připojení přestaví (`triggers: reload`).

- `tiers.jsonc`: `platformModels` → `{"bedrock-eu": "claude-sonnet-5-5"}`
  u všech tří úrovní; `ModelCatalog::defaultModel($tier, $platform)`.
- Přehled modelů a detail funkce: u modelu / funkce ukázat model úrovně
  pro platformu efektivního připojení.
- Test konzistence: model v `platformModels` existuje, je aktivní a má
  ID pro danou platformu.

### 3. Cena

Hotovo 2026-10-11. `AnalysisRunner` počítal cenu z ID modelu
v odpovědi (`$result->model`) — nově z rozhodnutí resolveru
(`ResolvedAiCall::costUsd()`), ID z odpovědi zůstává jen v auditu.

- `ResolvedAiCall` nese `priceMultiplier`; jedno místo pro výpočet ceny
  (pomocník nad `ModelCatalog::costUsd()`), použité v `AnalysisRunner`
  a `AiTestCommand`.
- Testy: cena běhu přes `bedrock-eu` je 1,1× ceník; přes `anthropic`
  beze změny.

### 4. Chyby a formulář

Hotovo 2026-10-11. Formulář připojení je PHP třída `AIBackendForm`
(JSONC `forms/core_ai_backends.jsonc` zanikl); nápovědy ke klíči
a adrese nese položka platformy (`keyHint` / `baseUrlHint`
v `platforms.jsonc`, lokalizované), ne PHP. Varianty textů chyb API
`api.<druh>.<platforma>` (401 / 403 / 404 pro `bedrock-eu`).

- `AnthropicLlmClient::exceptionFromErrorResponse()`: tělo chyby bez
  objektu `error` (styl AWS: `{"message": "…"}` / `{"Message": "…"}`)
  → zpráva z těla místo holého „HTTP 403“.
- Chyby přihlášení a přístupu k modelu z Bedrocku (401, 403, 404) končí
  jako chyba nastavení (už dnes podle stavu); do katalogu textů
  (`core.ai.texts`) doplnit radu pro platformu Bedrock: klíč musí být
  z téhož regionu jako adresa připojení; model musí mít v účtu AWS
  povolený přístup.
- Formulář připojení: po výběru platformy nabídnout její výchozí adresu
  (prázdné pole = výchozí platformy, jako dnes); popisek klíče podle
  platformy.

### 5. Dokumentace a uzavření

Hotovo 2026-10-11 (`docs/ai.md` §5.1, §5.2, §5.4, §5.7 a nový §5.8;
`help/nastaveni-ai.md` → Zpracování v EU; `help/co-dnes-nejde.md`,
`help/slovnicek.md`, `core_ai_backends.md`, `CLAUDE.md`).

- `docs/ai.md`: platformy (pole položky), Bedrock EU (endpoint,
  geografický profil, klíče, modely, cena), co znamená „zpracování
  v EU“ a co ne (poskytovatelem je americká společnost; data zůstávají
  v regionech EU).
- `help/nastaveni-ai.md`: postup „Zpracování v EU“ — klíč v AWS
  (region!), připojení, přepnutí výchozího připojení nebo jen
  vybraných funkcí, ověření příkazem `ai-test`.
- `core_ai_backends.md`, `docs/operations/` podle potřeby.
- `**Stav:**`, `python3 scripts/tasks-index.py`, `scripts/help-index.py`.

## Ověření (zdroj dat v režimu `volný`, klíč zadá člověk)

Klíč Bedrocku nastaví uživatel sám (`ai-backend-set-key` se skrytým
vstupem); do zadání, logů ani výpisů se nedostane.

Stav 2026-10-11: na `4l3j` je připojení `bedrock-eu` („Bedrock EU
(test)“, aktivní, ne výchozí) s uloženým klíčem; krok 1 hotový, krok 6
(klíč zamítnutý politikou účtu → 403 s radou v `ai-test` i v detailu
funkce) ověřen, funkce `exchange.contentTags` vrácena na výchozí
připojení. Body 2–5 a 7 proběhnou po úpravě SCP: stačí funkci přepnout
na toto připojení a spustit `ai-test`.

1. Založit připojení „Bedrock EU“ (platforma `bedrock-eu`, výchozí
   adresa), nastavit klíč.
2. Funkci `exchange.contentTags` přepnout na toto připojení →
   `shpd-ds ai-test --function exchange.contentTags`: OK, model
   `eu.anthropic.claude-sonnet-5-5`, cena s násobitelem.
3. Profil pošty s tímto připojením → reanalýza dokladu: běh na Bedrocku,
   návrh věcně shodný s během na přímém API; cena 1,1×.
4. `chat.default` na toto připojení → dotaz se dvěma nástroji doběhne
   („Přemýšlím…“, žádné 400); druhá otázka v téže konverzaci také.
5. Výchozí připojení přepnout na Bedrock EU → `ai-test` pro všech pět
   funkcí OK; shrnutí Dashboardu se vygeneruje. Vrátit.
6. Negativní testy: klíč z jiného regionu / adresa jiného regionu →
   srozumitelná chyba s radou; funkce s modelem `claude-sonnet-4-6` na
   tomto připojení → „model není na platformě“ už v detailu funkce.
7. Výsledek (časy, tokeny, ceny proti přímému API) do #85.

## Pasti

- **Region klíče.** Dlouhodobý klíč funguje jen v regionu, kde vznikl;
  adresa připojení musí mířit do téhož regionu.
- **Přístup k modelu** se v AWS povoluje zvlášť pro každý model; bez něj
  API vrací chybu, která vypadá jako špatný klíč.
- **Tvar chyb.** Chyby Bedrocku nemusí mít tvar Anthropicu — bez kroku 4
  uvidí správce jen „HTTP 403“.
- **`model` v odpovědi.** Bedrock může v `message_start` vrátit jiné
  ID, než bylo v požadavku (základní místo profilu) — cena a tvarování
  musí fungovat pro obě podoby.
- **Sonnet 5.5 na všech úrovních** znamená thinking i u klasifikátorů:
  pomalejší a dražší volání než na 4.6 a jiné chování štítků (viz
  zkušenost z fáze 1a). Ladění per funkce jde přepsat v Nastavení;
  výchozí hodnoty se nemění (čeká na evaluaci, #103).
- **Kvóty** jsou na účet AWS a region, ne na připojení.
- **Nic se nepřepíná samo.** Existence platformy nemění chování žádného
  zdroje dat, dokud správce nezaloží připojení.

## Hotovo když

- [x] Kroky 1–5 jako samostatné commity, `php -l`, cílené testy
      (`--filter 'AiFunctionResolverTest|ModelCatalog|AnthropicLlmClientTest|AnalysisRunnerTest|AiTestCommandTest'`),
      celá sada (9 027 testů, 2026-10-11).
- [x] Bez připojení `bedrock-eu` se žádný požadavek nezměnil (testy:
      tělo požadavku výchozího připojení shodné, `AiFunctionResolverTest`
      pro `anthropic` beze změny).
- [ ] Ověření 1–7 provedeno s reálným klíčem, výsledek do #85 — **čeká
      na úpravu SCP organizace AWS** (viz Pasti); kroky 1 (založení
      připojení, klíč) a 6 (negativní test: 403 s radou) proběhly
      na `4l3j`.
- [x] `**Stav:**` aktualizovaný, indexy tasků a nápovědy spuštěné.
