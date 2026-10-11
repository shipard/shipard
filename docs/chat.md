# Shipard — Chat (vnitřní AI asistent)

Vestavěný asistent, se kterým uživatel diskutuje nad svými daty. Orchestrátor
běží **v PHP, in-process**; jeden tah konverzace = jeden streamovaný (SSE)
požadavek, uvnitř kterého běží smyčka LLM ↔ nástroje. Konzumuje čtecí MCP
nástroje. Přehled subsystému: [`ai.md`](ai.md).

- **Backend:** `src/Api/Controller/ChatController.php` + modul `core/chat`
- **LLM klient:** `src/Core/Ai/` (`LlmClient`, `AnthropicLlmClient`)
- **Frontend:** `frontend/src/components/chat/` + `frontend/src/api/chat.js`

---

## 1. Endpointy

| Metoda | Cesta | Účel |
|--------|-------|------|
| GET | `/_chat/conversations` | Seznam konverzací uživatele (vč. `section`) |
| POST | `/_chat/conversations` | Založí prázdnou konverzaci (volitelně `section`) |
| GET | `/_chat/conversations/{id}` | Detail (vč. `section`) + zprávy (`seq` ASC) |
| PATCH | `/_chat/conversations/{id}` | Přejmenování (`title`) |
| DELETE | `/_chat/conversations/{id}` | Soft-delete (`docState=90`) |
| POST | `/_chat/conversations/{id}/messages` | **Streamovaný tah** (SSE) |

Vše vyžaduje auth a je **user-scoped** (cizí konverzace → 404). Zprávy nejsou
zapisovatelné přes CRUD — vznikají jen ve smyčce (integrita bloků).

`section` (UI shells Fáze 5) scopuje konverzaci na sekci hlavní navigace:
volí se při založení a dál se nemění. Hodnota se validuje proti id
z `global.navSections` (sentinel `_top` neprojde) → jinak
`INVALID_VALUE` (422); bez kompilované konfigurace validace degraduje na
statický seznam id.

---

## 2. Orchestrační smyčka (`runAgenticLoop`)

`POST …/messages` uloží zprávu uživatele a vrátí `Response::stream(...)`, uvnitř
kterého běží smyčka (≤ `MAX_TOOL_ITERATIONS` = 8):

1. **Zpráva uživatele se persistuje hned** (před voláním modelu) — odolnost vůči
   selhání.
2. Sestav `messages` z historie (bez thinking bloků a prázdných textů,
   tah bez bloků se vynechá) + nabídni modelu **jen čtecí nástroje**
   (`isReadOnly()`), zavolej `LlmClient::streamChat()`; textové delty průběžně
   posílej jako SSE `text-delta`, začátek thinking bloku jako SSE `thinking`.
3. Vrátí-li model `tool_use`:
   - persistuj asistentův tah **bez thinking bloků**
     (`LlmChatResult::contentWithoutThinking()`, bloky `text` + `tool_use`);
     do dalšího kroku téže smyčky jde tah **celý** (thinking bloky beze
     změny, na stejné pozici — [`ai.md`](ai.md) §5.4, #85 D53),
   - pro každý nástroj: SSE `tool-call`, spusť **in-process**
     (`registry->get(name)->call(args, ctx)`), výsledek zabal do `tool_result`,
   - persistuj syntetickou zprávu `role=user`/`kind=tool_results`, vrať modelu,
     opakuj.
4. Bez `tool_use` → finální odpověď: persistuj, agreguj telemetrii, SSE
   `message-complete`.
5. Strop iterací → graceful konec se zprávou, ne chyba.

**Bezpečnostní invariant:** smyčka spustí jen nástroj, který je v registru a
`isReadOnly()` — i kdyby si model vyžádal jiný či zápisový (vrátí se
`tool_result` s `is_error`, nespustí se). Vykonávací chyba nástroje → rovněž
`tool_result is_error`, model reaguje; ne tvrdý pád.

---

## 3. SSE kontrakt

```
event: text-delta
data: {"text":"částečný text"}

event: thinking
data: []

event: tool-call
data: {"name":"persons_search","arguments":{...}}

event: message-complete
data: {"message_id":123,"usage":{"input_tokens":…,"output_tokens":…},"model":"…"}

event: error
data: {"code":"LLM_ERROR","message":"…"}
```

Výsledek nástroje se samostatně nestreamuje — navazující text modelu doteče jako
`text-delta`. `thinking` = model začal thinking blok (#85 D56; bez textu, jednou
na blok) — UI ukáže „Přemýšlím…“ do dalšího `text-delta`, `tool-call` nebo konce
odpovědi. `message-complete` může nést `note: "iteration_limit"` při dosažení
stropu.

---

## 4. LLM klient

`LlmClient` je rozhraní; `AnthropicLlmClient` jediná v1 implementace (Anthropic
Messages API, streamovaně). `streamChat(LlmChatParams, $onTextDelta,
$onThinking = null)`:

- tělo `{model, max_tokens, system, messages, stream:true}` + `tools` (když jsou);
  před odesláním normalizuje `tool_use.input` — prázdný vstup (`[]` po PHP
  round-tripu `json_decode` assoc → persistence → `json_encode`) jde jako
  JSON objekt `{}`, jinak API vrací 400 „input: Input should be an object"
  (model volající nástroj bez argumentů, např. `mail_list_pending`);
- parsuje provider SSE: `text_delta` → `$onTextDelta`; `tool_use` bloky
  (`content_block_start` + `input_json_delta`) → poskládá `input`; `thinking`
  bloky (`thinking_delta` + `signature_delta`, prázdný text zůstává)
  a `redacted_thinking` přesně tak, jak přišly, `$onThinking` jednou při
  začátku bloku; neznámý typ bloku zaloguje varování jednou za proces;
- vrací `LlmChatResult` s `text`, `contentBlocks` (celé bloky tahu v pořadí
  včetně thinking — pro feed zpět v rámci smyčky), `contentWithoutThinking()`
  (tvar k uložení), `toolUses`, usage, `stopReason`.

`temperature` se v1 vynechává (novější modely ho odmítají). Provider scope:
jen `anthropic`; rozhraní drží dveře pro další.

---

## 5. AI funkce, klíč a systémový prompt

- **Volání modelu** (`resolveCall`): AI funkce `chat.default`
  (`AiFunctionResolver`, [`ai.md`](ai.md) §5.3–5.4) — připojení, klíč,
  model, strop 16 000 z deklarace (#85 D55, thinking se počítá) a ladění
  přijdou hotové; chat nic neskládá. `conversation.backend` (přišpendlené
  připojení) je přepsání; když chybí nebo je neaktivní, platí výchozí
  připojení jako dřív. Chyby resolveru → `NO_BACKEND` /
  `BACKEND_KEY_ERROR` / `AI_NOT_CONFIGURED`.
- **Systémový prompt** (`systemPrompt`): základ z cfgItem `core.chat.settings`
  (`module.jsonc`), s built-in fallbackem. K němu se **při každém požadavku
  přilepí aktuální datum** + instrukce „neodhaduj podle tréninkových dat, ověř
  nástrojem; pokud nemáš nástroj, řekni to". Bez data model pokládá současný rok
  za budoucnost. Základ (cfgItem i fallback) dále instruuje model vracet
  tabulková data jako **GFM pipe tabulku** a členit delší text markdown
  nadpisy — frontend obojí renderuje nativně (§7).
- **Sekční blok** (UI shells Fáze 5): konverzace se `section` dostane mezi
  základ a datumový dovětek blok
  `„Kontext: uživatel konverzuje v oddělení «{label}». {prompt}"` — label
  z `global.navSections` (kompilovaná cfg je pre-lokalizovaná), prompt
  z cfgItem **`core.chat.sectionContexts`**
  (`modules/core/chat/config/sectionContexts.jsonc`; klíč = id sekce,
  hodnota `{prompt}` s `:cs`/`:en` variantami). Sekce bez záznamu
  v cfgItem → jen věta s labelem (degradace bez konfigurace). Prompty
  drží fokus oddělení (čím začít, co ověřovat), obecná pravidla zůstávají
  v základu. Nástroje se per sekce **nefiltrují** (D6) — fokus dělá
  prompt, bezpečnost read-only tier.

---

## 6. Datový model

Konverzace a zprávy — per uživatel + DS, soft-delete přes `docState`.

- [`core_chat_conversations`](../modules/core/chat/tables/core_chat_conversations.md)
  — vlastník, název, backend, agregát telemetrie, `section` (scope na
  sekci navigace, nullable).
- [`core_chat_messages`](../modules/core/chat/tables/core_chat_messages.md)
  — `content` jako **JSON pole bloků Anthropic** (`text`/`tool_use`/`tool_result`),
  `role` (`user`/`assistant`) + `kind` (`user_text`/`assistant`/`tool_results`).

`tool_result` bloky žijí ve zprávě s `role=user` (formát Anthropic) — proto
`kind`, aby UI odlišilo skutečnou zprávu uživatele od syntetické s výsledky
nástrojů.

---

## 7. Frontend

- **`api/chat.js`** — CRUD přes `client.js`; streamovaný tah ale **nelze přes
  `EventSource`** (neumí POST ani Bearer hlavičku), takže `sendMessageStream`
  jede `fetch()` + `response.body.getReader()` a ručně parsuje SSE rámce.
  Parser je čistá funkce `parseSseFrames` (testovatelná, řeší neúplné rámce
  přes hranici chunku).
- **Chat store** (runes) — sdílený stav seznam ↔ vlákno; optimistická zpráva
  uživatele, akumulace `text-delta`, čipy nástrojů.
- **Komponenty** (`components/chat/`) — `ChatView`/`ConversationList`/
  `ChatThread`/`ChatInput`/`MessageBubble`/`ToolCallChip`/`Markdown`. `tool-call`
  se zobrazuje jako čip s lidským popiskem (`toolLabels.js`).
- **`Markdown`** renderuje **bezpečnou podmnožinu** markdownu do Svelte elementů
  — **žádné `{@html}` ze syrového výstupu modelu** (text se escapuje, emitují se
  jen kontrolované tagy). Nula XSS plochy, nula nové závislosti. Podporované
  konstrukce: odstavce, bold/italic/inline code, fenced code bloky, seznamy,
  **nadpisy** `#`–`######` (vizuálně zastropované na `h3`–`h5`, ať nerozbíjí
  bubliny) a **GFM pipe tabulky** (hlavička + delimiter řádek; alignment
  z delimiteru, kratší řádky se doplní, delší oříznou).
- **`TableBlock`** vykresluje tabulkový token jako nativní `<table>` se zebra
  řádky v wrapperu s `overflow-x: auto` (úzký boční panel). V rohu **copy
  tlačítko** (hover/focus-within, na dotykových zařízeních trvale):
  `clipboardTable.js` zapíše dual-format `ClipboardItem` — `text/html`
  (skutečná `<table>` pro paste do Excelu/Sheets/Wordu) + `text/plain` (TSV);
  bez `ClipboardItem` fallback na TSV `writeText`. Feedback „Zkopírováno"
  ~2 s. HTML pro clipboard se staví přes `document.createElement` +
  `textContent`, nikdy string konkatenací textu modelu.
- **Streaming a tabulky:** parser běží nad celým akumulovaným textem při každé
  deltě — rozepsaná tabulka se krátce ukáže jako odstavec a „přeskočí" do
  `<table>` po doručení delimiter řádku (přijaté v1 chování, žádný speciální
  kód).
- **Backend:** v1 výchozí (žádný výběr modelu v UI). **401 uprostřed streamu**
  (vypršení tokenu) → srozumitelná chyba; plný refresh-retry je odložený.
- **Dashboardový launcher + boční panel** — dashboard má dole plovoucí
  textový input (`ChatLauncher.svelte`); odeslání založí novou konverzaci,
  otevře boční `ChatPanel.svelte` (mountovaný v AppShellu, non-modální
  overlay zprava, z-index 80 — přežije navigaci) a pošle zprávu. Panel
  sdílí tentýž `chatStore` singleton jako sekce Chat — perzistence,
  streaming i error handling jsou reuse; „Otevřít v Chatu" (⧉) naviguje
  do sekce Chat se stejným vláknem. Otevřenost drží mini store
  `chatPanel.svelte.js`. Viz `docs/dashboard.md` §8.
- **Scope na sekci** (UI shells Fáze 5): nová konverzace zdědí scope
  z `navigationStore.activeSection` v okamžiku `newConversation()` —
  volají to launcher, „+" v panelu, **tlačítko chatu v chrome** (hlavička
  Sidebaru + sbalený pás, classic `TopMenuBar`; gate = Chat leaf v nav
  tree, týž výraz jako capability `chat`) a „Nová konverzace" v AI
  záložce wild shellu. Draft drží `pendingSection`
  v chat store; `section` se odešle s lazy create při první zprávě.
  U inputu chip s labelem sekce — v draftu s ✕ (`clearPendingSection`),
  po založení statický. Plná sekce Chat scope jen **zobrazuje** (chip +
  štítek v seznamu konverzací), výběr nenabízí (D5).
- **Karty sekce** (`SectionCards.svelte`): prázdná scoped konverzace
  zobrazí nad inputem skutečné dashboard karty sekce
  (`GET /_ui/dashboard?section=`, reuse `FeedCard`) — UI, žádná AI
  generace (D4). Obsluhují se jen navigační akce (`open_viewer`,
  `open_panel`, `open_form`, `open_detail`); ostatní druhy se z karet
  odfiltrují. Chyba fetche → blok se tiše vynechá; po první zprávě
  zmizí. Model si feed čte nástrojem `feed_cards`
  ([`mcp-server.md`](mcp-server.md) §5).
- **AI asistent sekce** (`SectionAssistant.svelte`, wild shell Fáze 6):
  plná plocha AI záložky sekce — hlavička (název sekce, Nová konverzace,
  Otevřít v Chatu) + `ChatThread` s `showScopeChip={false}` (scope dává
  záložka, chip by byl redundantní a ✕ by vlákno od záložky
  desynchronizoval). Vstup na záložku naváže na **nejnovější** scoped
  konverzaci sekce (seznam nese `section`, řazený modified DESC), bez
  existující založí prázdný draft (SectionCards + input). Sdílí tentýž
  `chatStore` singleton — souběh s otevřeným ChatPanelem zobrazuje
  totéž vlákno (přijatelné v1, stejná sémantika jako „Otevřít v Chatu").
  Pozn.: `chatStore.remove()` po smazání aktivního vlákna zakládá draft
  bez scope — mazání v asistentu není, po smazání v sekci Chat se
  záložka při návratu čistě re-aktivuje.

---

## 8. Bezpečnost a hranice v1

- Chat nabízí modelu **jen čtecí tier** + bezpečnostní invariant (§2).
- Zápisové nástroje (`mail_draft_document`) chat zatím **nenabízí** — přijdou
  jako vlastní fáze se zastavením a potvrzením u zápisu.
- Žádné `{@html}` z modelu (§7). Platí i pro tabulky: buňky jsou textově
  bindované spany, `text-align` jen z whitelistovaných hodnot parseru a HTML
  pro clipboard se staví přes DOM API (`textContent`), ne string konkatenací.

---

## 9. Související dokumenty

- [`ai.md`](ai.md) — přehled subsystému
- [`mcp-server.md`](mcp-server.md) — nástroje, které chat konzumuje
- [`frontend.md`](frontend.md) — frontend architektura (Svelte, API komunikace)
- [`operations/secrets.md`](operations/secrets.md) — šifrování klíče backendu
