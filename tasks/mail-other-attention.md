# Ostatní pošta k vyřízení — pozornost u zpráv bez dokladu, sekce K vyřízení, hromadný archiv

**Stav:** částečně — implementováno 2026-10-07 (#105 D1–D10), čeká na opravu schématu v4.7.1 (sekce „Oprava po ověření“); odchylky od zadání v sekci „Poznámky k implementaci“

## Cíl

Dashboard dělí došlou poštu na Přijaté faktury a Spisovnu; všechno, z čeho AI
nenavrhla doklad ani dokument (`primary_type='other'`), padá do sekce
**Ostatní** jako jeden řádek per zpráva s akcemi Koš / Archiv
(`MailSuggestionsSource::fetchNotInvoiceRows()`). Jenže i v téhle poště jsou
věci, které **vyžadují akci nebo rozhodnutí** — přehled expirujících domén,
výzva k platbě, upomínka, žádost — a ty se topí mezi potvrzeními platby,
změnami stavu objednávky a notifikacemi.

Na dev DS `lh6x-l` poslal jeden registrátor domén 14 faktur a 52 zpráv
`other`: 13 přehledů expirujících domén (rozhodnout: prodloužit, nebo nechat
propadnout), 13 výzev k platbě (zaplatit), 22 potvrzení (jen vědět). Na
testovacím serveru je obraz stejný: na jednom zdroji je polovina čekajících
řádků Ostatní expirace a výzvy k úhradě, na jiném skeny obálek a drobné
notifikace; newsletterů jsou jednotky za čtvrtletí.

AI dnes u `other` vrací jen `primary_type` + `title`. Titulek (`ai_title`)
už tyhle skupiny spolehlivě rozlišuje — jen o to nežádáme strukturovaně.

Po tomto tasku:

- prompt u zprávy bez dokladu vrátí **pozornost** (`action` / `info` /
  `promo`), u `action` i větu „co udělat“ a lhůtu, a protistranu zprávy;
- dashboard má novou sekci **K vyřízení** (akční zprávy s lhůtou a akcí
  Vyřízeno) a sekce **Ostatní** nese jen informativní poštu s akcí
  **Archivovat vše**;
- pravidlo odesílatele `archiveIfOther` nespolkne akční zprávy.

Vazba na pravidla odesílatelů (`tasks/mail-sender-rules-after-analysis.md`):
„Ostatní“ není homogenní — u smíšeného odesílatele (registrátor: faktury
i expirace) by `archiveIfOther` odklidilo i to, co člověk musí přečíst. Navíc
přes 70 % pošty chodí přeposíláním přes skupinu, takže odesílatel u zpráv bez
dokladu je ten, kdo přeposlal; rozhodovat podle obsahu je robustnější než
podle adresy.

## Před implementací přečti

- `modules/core/mail/docs/ai-analysis.md` → *Klasifikace typu zprávy
  (message_classification)*, *Partner zprávy*, *Titulek zprávy (ai_title)*
- `modules/core/mail/docs/ai-prompts.md` — pole profilu, *Output schema*,
  *Iterativní ladění promptu* (bump verze, sync přes `ds-upgrade`),
  *Changelog promptu* (formát záznamu)
- `docs/dashboard.md` — §3 sekce feedu (#101), kartový kontrakt (§4),
  *Karty ostatní pošty*, tabulka zdroj → `kind` → `feedSection`, akce + undo
- `tasks/mail-sender-rules-after-analysis.md` — `PostAnalysisDisposer`
  (D1, D2), `SenderRuleConfirmedHandler` (D8), digest + „Vrátit vše“ (D7)
- `tasks/mail-message-title-partner.md` D4, D5, D8 + `MessagePartnerWriter`
  — partner zprávy jen ze shody identifikátorem, nikdy jménem
- `tasks/dashboard-feed-workflow-sections.md` — jak vznikla sekce
  a `feedSection` (server + `Feed.svelte` zrcadlí `SECTION_ORDER`)
- `CLAUDE.md` → *Backend — Vícejazyčnost* (katalog `feedTexts`, ICU),
  *Databázové tabulky*, *Frontend — Vícejazyčnost*
- `docs/table-definitions.md` — přidání sloupců, `ds-upgrade`
- `docs/help-authoring.md` — úprava stránky v `help/`

## Rozhodnutí k designu (potvrzená, #105)

- ✓ **D1 — Pozornost u zprávy bez dokladu.** Prompt **v4.7.0**: u
  `primary_type=other` vrací `message_classification.attention` z výčtu
  `action` (vyžaduje akci nebo rozhodnutí: expirace, výzva k platbě,
  upomínka, žádost o cokoli), `info` (potvrzení platby, stav objednávky,
  notifikace, doručenka, sken obálky), `promo` (newsletter, leták, nabídka,
  pozvánka). U dokladů a dokumentů Spisovny se pole nevrací. `promo` UI
  zatím nerozlišuje od `info` — sbírá se, aby se auto-koš newsletterů dal
  později rozhodnout na datech.
- ✓ **D2 — Co a dokdy.** K `action` navíc `action_note` (jedna česká věta,
  ≤ 200 znaků, „co má člověk udělat“ — např. „Prodloužit 3 domény, jinak
  15. 10. expirují“) a volitelné `due_date` (ISO datum lhůty, **jen je-li ve
  zprávě**, neodhadovat). U `info` / `promo` se nevrací.
- ✓ **D3 — Uložení.** Tři sloupce na `core_mail_incoming_messages`:
  `attention` (enumString 10, nullable, cfgItem
  `core.mail.attentionKinds`; NULL = neurčeno), `action_note` (varchar 200,
  nullable), `action_due` (date, nullable). Zapisuje `/result`
  v transakci vedle `primary_type`, **jen u typu `other`**; u dokladu
  a dokumentu Spisovny se nastaví NULL. Neznámá hodnota `attention` =
  warning + ignore (stejně jako neznámý `primary_type`). Uživatel pole
  needituje — reaguje archivací.
- ✓ **D4 — Nová sekce feedu „K vyřízení“** (`feedSection: attention`):
  řádky `primary_type='other'`, `attention='action'`, `docState=10`, bez
  otevřeného návrhu (stejná podmínka jako Ostatní). Pořadí sekcí: Položky
  k založení → Připraveno → Ke kontrole → **K vyřízení** → Nepodařilo se
  zpracovat → Upozornění → Ostatní. Karta `kind=review`, `category=other`,
  titulek `ai_title`, podtitulek `action_note`, badge lhůty („do 15. 10.“;
  po lhůtě nebo do 3 dnů `danger`), akce **Vyřízeno** (= `archive_message`,
  primární), Otevřít e-mail, Koš. Řazení uvnitř sekce `action_due` ASC,
  bez lhůty na konec, pak `received_at` DESC.
- ✓ **D5 — Ostatní = jen informativní.** V Ostatních zůstanou řádky
  `attention IN ('info','promo')` a `attention IS NULL` (starší analýzy).
  Sekce dostane akci **Archivovat vše (N)** — endpoint archivuje jen
  `info` / `promo` řádky bez otevřeného návrhu, vrátí ID; toast **Vrátit**
  je vrátí do Nové. NULL řádky zůstávají per řádek jako dnes (lze je
  reanalyzovat). Žádný automatický archiv po N dnech — až jako opt-in
  navazující krok, pokud se Archivovat vše ukáže jako otravné.
- ✓ **D6 — Pravidla odesílatele.** `archiveIfOther` v `PostAnalysisDisposer`
  (`afterResult` i `applyToWaiting`) nově **vynechá zprávy
  s `attention='action'`** — zůstanou v K vyřízení; `info` / `promo` / NULL
  archivuje jako dnes. Učící handler `SenderRuleSuggestionHandler` se
  v tomto tasku **neopravuje** (nehlásí se mu změny stavu zprávy — známý
  bug z 2026-10-06; čeká na rozhodnutí, jestli pravidla per odesílatel
  ještě chceme navrhovat automaticky). Popisky dispozice a karty pravidla
  zpřesnit: „…když neobsahuje doklad ani nic k vyřízení“.
- ✓ **D7 — Protistrana u zprávy bez dokladu.** Prompt u `other` vrací
  `message_classification.party {name, companyId, email}` toho, **od koho
  zpráva skutečně je** (odesílatel služby, ne kdo ji přeposlal). Server plní
  `partner_name` a `partner_person` přes **`MessagePartnerWriter`** se
  stejnými pravidly jako u dokladů: jméno jen dokud `target_row IS NULL`,
  Osoba jen při shodě identifikátorem (IČO / DIČ), nikdy jménem, nikdy
  create.
- ✓ **D8 — Výzvy k platbě** jdou zatím jako `action`. Cílový stav je typ
  dokladu „zálohová faktura přijatá“ (navazuje na #79) — účetně bezpečnější,
  člověk hlídá, co platí. Až vznikne, prompt je přeřadí z `other`; do
  `docs/roadmap.md` jedna řádka u M2/M3 dle milníku #79.
- ✓ **D9 — Detail a nápověda.** `IncomingMessagesForm` ukáže pozornost,
  poznámku a lhůtu (read-only, vedle `ai_title` / `partner_name`);
  `help/posta/prijem-posty.md` dostane odstavce o K vyřízení a Archivovat
  vše; MCP `mail_list_pending` vrací `attention`, `action_note`,
  `action_due`.
- ✓ **D10 — Přechod.** Bez backfillu. Po `ds-upgrade` (sync profilu
  v4.7.0) platí pro novou poštu; čekající řádky Ostatní si uživatel
  reanalyzuje (akce v detailu) nebo odklidí po staru.

**Mimo rozsah:** úkoly, zálohové faktury přijaté (#79), automatický archiv
po N dnech, auto-koš newsletterů, efektivní odesílatel z hlaviček skupiny
(`X-Original-From`), oprava učícího handleru.

## Scope

1. Prompt + `output_schema` v4.7.0, changelog
2. Sloupce + cfgItem `attentionKinds`, `ds-upgrade`
3. `/result`: zápis pozornosti, poznámky, lhůty, protistrany
4. `PostAnalysisDisposer`: vynechat `action`
5. Feed: sekce `attention` (server + FE), Ostatní jen informativní,
   Archivovat vše + Vrátit
6. Detail zprávy, MCP, dokumentace, nápověda

## 1. Prompt a schéma — `modules/core/mail/profiles/czech_general.jsonc`

`prompt_version` `v4.6.2` → **`v4.7.0`** (všechny výskyty včetně
`source.promptVersion` v ukázce a `docs/ai-prompts.md`).

**`prompt_template`**, krok 1) TRIAGE, za odstavcem o `title` doplnit:

> U `"other"` přidej do `"message_classification"` vždy i `"attention"`:
> `"action"` — zpráva vyžaduje, aby člověk něco udělal nebo rozhodl
> (expirace domény či služby, výzva k platbě, upomínka, žádost, výpověď,
> termín); `"info"` — jen informuje, nic nevyžaduje (potvrzení platby nebo
> objednávky, změna stavu, notifikace banky, doručenka, sken obálky, prázdná
> zpráva); `"promo"` — obchodní sdělení (newsletter, leták, nabídka,
> pozvánka). U `"action"` přidej `"action_note"` — jednu českou větu, co má
> člověk udělat (nejvýše 200 znaků), a `"due_date"` (ISO datum), jen pokud
> je lhůta ve zprávě výslovně uvedená — NIKDY ji neodhaduj. U `"other"`
> přidej i `"party"` `{"name", "companyId", "email"}` — od koho zpráva
> skutečně pochází (provozovatel služby, dodavatel, úřad), NE kdo ji
> přeposlal; pole, která nevidíš, vynech. U dokladů a dokumentů tato pole
> nevracej.

Ukázka v závěru promptu („Pokud žádný doklad ani dokument neexistuje“)
rozšířit o `attention`, `action_note`, `due_date`, `party` — jeden příklad
`action` (expirace s lhůtou) stačí; neprodlužovat prompt dalšími.

**`output_schema`** → `message_classification.properties` (schéma má
`additionalProperties: false`, každý nový klíč musí být deklarovaný):

```jsonc
"attention":   { "type": "string", "enum": ["action", "info", "promo"] },
"action_note": { "type": "string", "maxLength": 200 },
"due_date":    { "type": "string", "pattern": "^\\d{4}-\\d{2}-\\d{2}$" },
"party": {
    "type": "object", "additionalProperties": false,
    "properties": {
        "name": { "type": "string" }, "companyId": { "type": "string" },
        "email": { "type": "string" }
    }
}
```

Všechna nová pole **volitelná** (`required` zůstává `["primary_type"]`) —
starší analyzer / profil bez nich nesmí padat (vzor P8 u `title`).

`modules/core/mail/docs/ai-prompts.md`: sekce *Default prompt* (verze),
*Output schema* (nová pole), **Changelog** `### v4.7.0 (datum)` ve formátu
jako v4.6.2: motivace (Ostatní není homogenní, registrátor), co se mění
v promptu, co dělá server nezávisle na verzi (nic — bez pole zůstává NULL).

## 2. Sloupce a cfgItem

`modules/core/mail/tables/core_mail_incoming_messages.jsonc` — tři sloupce
za `ai_title` (skupina jako `ai_title`):

| Sloupec | Typ | Pozn. |
|---|---|---|
| `attention` | `enumString`, length 10, nullable, `cfgItem: core.mail.attentionKinds` | NULL = neurčeno (starší analýza, doklad, dokument) |
| `action_note` | `varchar` 200, nullable | česky, jazyk AI profilu jako `ai_title` |
| `action_due` | `date`, nullable | lhůta z `due_date` |

Nový cfgItem `core.mail.attentionKinds` → `config/attentionKinds.jsonc`
(registrace v `module.jsonc` vedle `primaryTypes`): `action` „K vyřízení“ /
„Action required“, `info` „Informativní“ / „Informational“, `promo`
„Obchodní sdělení“ / „Promotional“, každý s `order`. Index není potřeba —
feed filtruje přes `docState` + `primary_type`, které už mají index.

Dokumentace tabulky `core_mail_incoming_messages.md` (tabulka sloupců
+ odstavec, kdo a kdy plní). Po změně `vendor/bin/shpd-ds ds-upgrade`
na dev DS (zároveň sync profilu v4.7.0 a nový cfgItem do compiled configu).

## 3. `/result` — `src/Api/Controller/AnalysisController.php`

V `applyMessageClassification()` (běží v transakci resultu, hned po zápisu
`primary_type`):

- `primary_type === 'other'`: `attention` validovat proti klíčům cfgItem
  `core.mail.attentionKinds` (neznámá hodnota → `ErrorLogger::warn`
  `ignoring unknown attention` + pole nechat NULL, uložení resultu nerozbít);
  `action_note` trim + sjednocení whitespace + oříznutí na 200 (stejný
  helper jako u titulku); `due_date` přijmout jen validní `YYYY-MM-DD`
  (`DateTimeImmutable::createFromFormat` + kontrola round-trip), jinak NULL;
  u `attention !== 'action'` poznámku i lhůtu zahodit (NULL), i kdyby
  je model poslal.
- `primary_type !== 'other'` (doklad / dokument): všechna tři pole
  **NULL** — zpráva mohla být dřív `other` a reanalýzou se stát fakturou.
- Respektovat `primary_type_source = 'user'`: tentýž `WHERE` jako u typu
  — ruční volba typu znamená, že uživatel rozhodl, AI nemění ani pozornost.
- **D7 protistrana:** `party` z `message_classification` předat
  `MessagePartnerWriter` jako jméno + identifikátory stejně, jako to dělá
  `writeFromCanonical()` pro `party` registry dokumentu — nejspíš nová
  metoda `writeFromClassification(int $messageNdx, array $party)` vedle
  stávající, sdílející normalizaci a `PartyResolver::resolve(…,
  identifiersOnly: true)`. Volat jen u `other` a jen když `document`
  je null (u dokladu partnera určuje canonical — beze změny). Selhání
  resolveru polykat (best-effort, jako dnes).

Kontrakt v `docs/mail/api-contract.md` (sekce `/result`,
`message_classification`): nová volitelná pole + co s nimi server dělá.

## 4. `PostAnalysisDisposer` — D6

`modules/core/mail/src/PostAnalysisDisposer.php`: do společných podmínek
(`primary_type = other`, confidence ≥ review, …) přidat
`attention IS NULL OR attention <> 'action'` — v `afterResult()` i
`applyToWaiting()`. Docblok třídy a odstavec v `docs/registry-mvp.md`
(vztah k D7 registry) doplnit: akční zpráva nikdy do archivu pravidlem.

Texty (`ds-upgrade` po změně):

- `config/senderRuleDispositions.jsonc` → `archiveIfOther` `name:cs`
  „Archivovat, když neobsahuje doklad ani nic k vyřízení“, `name:en`
  „Archive when it contains no document or action“; `help`/popis dispozice
  dle stávající struktury.
- `config/feedTexts.jsonc` → `senderRule.titleIfOther`: „Archivovat poštu
  od {pattern}, když neobsahuje doklad ani nic k vyřízení?“ + en; PHP
  fallback znak po znaku (`FeedTextsCatalogTest`).

## 5. Feed — sekce K vyřízení, Ostatní, Archivovat vše

### 5a. Server

- `src/Core/Feed/FeedSource.php`: `SECTION_ATTENTION = 'attention'`.
  `FeedCollector::SECTION_ORDER` dle D4 (`attention` mezi `review`
  a `failed`); `DEFAULT_SECTION_BY_KIND` beze změny (zdroj sekci nastavuje
  explicitně). Test `FeedCollectorTest`: pořadí a count sekce.
- `MailSuggestionsSource`:
  - `fetchNotInvoiceRows()` → dotaz navíc vybere `attention`,
    `action_note`, `action_due`; řádky `attention='action'` vrátí jako
    karty **`mail_attention:{ndx}`** s `kind=review`, `feedSection=attention`,
    `stateStyle=review`, `category=other`, `title=ai_title` (fallback
    `other.title`), `subtitle=action_note`, strukturované pole
    `actionDue` (ISO) a `dueState` (`overdue` / `soon` ≤ 3 dny / `later` /
    null — spočítat na serveru, FE jen kreslí), akce
    `[archive (primary), openMail, trash]` (pořadí = primární první);
    ostatní řádky jako dnes (`mail_notinvoice:*`, `feedSection=other`).
    Pro K vyřízení zapsat do karty i `emailSubject` podle stávajícího
    pravidla (jen když se liší od titulku).
  - Řazení sekce `attention` v `FeedCollector::sortAndCap` je `KIND_ORDER`
    → `timestamp` DESC; lhůtu zohlednit tak, že zdroj nastaví `timestamp`
    … **ne** — timestamp je čas zprávy a FE ho ukazuje. Místo toho
    `FeedCollector` zná volitelné pole karty `sortKey` (string; nižší
    dřív) použité před `timestamp`; zdroj ho u akčních karet nastaví na
    `action_due ?? '9999-12-31'`. Zdokumentovat v kartovém kontraktu
    (`docs/dashboard.md` §4) jako volitelné pole pro zdroje.
  - Katalog `feedTexts.jsonc`: `attention.due` „do {date}“ / „by {date}“,
    `attention.overdue` „po lhůtě {date}“ / „overdue {date}“ — datum
    formátované serverem dle jazyka (`ext-intl`, krátký formát bez roku,
    je-li letos). Alternativa: formátovat na FE — rozhodnout podle toho,
    kde už se datum na kartách formátuje (`timestamp` formátuje FE →
    pak posílat ISO a texty mít v `cs.js`/`en.js`; zvolit jedno, nemíchat).
- **Archivovat vše**: `POST /_mail/messages/archive-informational`
  (Router `resolveMailMessagesRoute`, vedle `upload`), `MailController`:
  v jedné transakci načte ID řádků splňujících přesně podmínku Ostatní
  (`docState=10`, `analysis_state=30`, `primary_type='other'`,
  `attention IN ('info','promo')`, bez otevřeného návrhu — sdílet SQL
  podmínku se zdrojem, ne kopírovat), každou převede do 80 přes
  `TableGateway` / `IncomingMessageDocument` (hooky, `docStateMain`,
  budoucí handlery), vrátí `{archived: [ids], count}`. Prázdný výběr → 200
  s `count: 0`. Práva jako `archive_message`.
- **Vrátit**: `POST /_mail/messages/restore-archived` `{ids: [...]}` —
  jen zprávy v `docState=80` s `auto_disposed_by IS NULL` (ruční archiv,
  ne pravidlo — to má vlastní undo), zpět do 10 přes gateway; vrátí
  `{restored: n}`. Cizí nebo už přesunuté ID tiše přeskočit.

### 5b. Frontend

- `Feed.svelte`: `SECTION_ORDER` zrcadlí server; sekce `attention`
  renderuje `FeedCard` v `shpd-feed__grid` (jako Ke kontrole), `MORE_VIEWER`
  `core.mail.incoming`; tečka sekce `shpd-feed__section-dot--attention`
  (barva review). `i18n`: `dashboard.feed.section.attention` „K vyřízení“
  / „To handle“; label akce `archive` na kartě `mail_attention:*`
  „Vyřízeno“ / „Done“ (dle `action.id` + `card.source`/prefixu id, jak se
  dnes lokalizují mail akce; v Ostatních zůstává „Archivovat“).
- `FeedCard`: badge lhůty z `actionDue` + `dueState` (`danger` pro
  `overdue` a `soon`, neutrální jinak); bez lhůty nic.
- Sekce Ostatní: v hlavičce sekce tlačítko **„Archivovat vše (N)“** —
  N = počet řádků `info`/`promo` v sekci, který musí poslat server
  (`sections[].archivable` nebo samostatné pole v odpovědi dashboardu —
  nepočítat z 30 zobrazených karet, sekce má `total`). Klik → bez
  potvrzovacího dialogu, protože je vratný: `archiveInformational()` →
  `load()` + toast „Archivováno N zpráv“ s akcí **Vrátit** →
  `restoreArchived(ids)` → `load()`. Toast rozšířit o kind `archived`
  (lokální toast v `Dashboard.svelte` — stejný vzor jako Vrátit vše
  digestu). Tlačítko skrýt při N = 0 (zůstanou-li jen NULL řádky).
- `api/dashboard.js`: `archiveInformational()`, `restoreArchived(ids)`.
- `npm run check:i18n && npm run build`.

## 6. Detail zprávy, MCP, dokumentace, nápověda

- `IncomingMessagesForm`: za `ai_title` read-only `attention` (select
  z cfgItem, read-only), `action_note`, `action_due`; skrýt, když
  `attention` je NULL (DocsHeadsForm vzor pro podmíněné elementy —
  případně jen read-only prázdné, pokud by skrývání vyžadovalo nový
  mechanismus; nepřidávat ho).
- `IncomingMessagesViewer`: řádek viewer bez změny; filtr / view group
  „K vyřízení“ **nepřidávat** (dashboard je vstup, viewer je archiv).
- `MailListPendingTool`: do výstupu `attention`, `action_note`,
  `action_due`; popis nástroje zmínit, že `action` = chce lidskou akci.
- `docs/dashboard.md`: §3 pořadí sekcí (7 sekcí), tabulka zdroj → kind →
  feedSection (`mail_attention:*`), odstavec *Karty ostatní pošty*
  rozdělit na *K vyřízení* a *Ostatní*, akce `archive_informational` /
  `restore_archived` do tabulky akcí + undo, `sortKey` do kontraktu §4,
  `sections[].archivable`.
- `modules/core/mail/docs/ai-analysis.md`: *Klasifikace typu zprávy* —
  pozornost, poznámka, lhůta, protistrana z klasifikace; *Partner zprávy*
  — druhý zdroj (klasifikace u `other`).
- `docs/mail/api-contract.md`: `/result` nová pole; nové endpointy.
- `modules/core/mail/README.md`: jedna řádka.
- `docs/roadmap.md`: řádek D8 (zálohové faktury přijaté přeřadí výzvy
  k platbě z `other`, vazba #79).
- `help/posta/prijem-posty.md`: sekce **K vyřízení** (co tam padá, lhůta,
  Vyřízeno = archiv, Koš) a **Ostatní** (Archivovat vše, Vrátit, proč
  některé řádky zůstávají — starší analýza, Zanalyzovat znovu). Názvy
  tlačítek a sekcí přesně podle `cs.js`. Poté `python3
  scripts/help-index.py`.

## Testy

- `tests/Unit/…/AnalysisControllerTest` (nebo kde se dnes testuje
  `applyMessageClassification` / `applyMessageTitle`): `other` + `action`
  s poznámkou a lhůtou → zapsáno; `info` s poslanou poznámkou → poznámka
  NULL; neznámá `attention` → warning, NULL, result uložen; doklad po
  reanalýze → tři pole NULL; `primary_type_source='user'` → nedotčeno;
  neplatné `due_date` → NULL.
- `MessagePartnerWriterTest`: `writeFromClassification` — jméno zapsáno,
  Osoba jen při shodě IČO, jen do NULL, jen dokud `target_row IS NULL`.
- `PostAnalysisDisposerTest`: `action` → neodklidí (afterResult
  i applyToWaiting); `info`, `promo`, NULL → odklidí jako dosud.
- `MailSuggestionsSourceTest`: řádek `action` → karta `mail_attention:*`
  se správným kind / section / akcemi / `sortKey` / `dueState`; `info` →
  `mail_notinvoice:*`; NULL → `mail_notinvoice:*`.
- `FeedCollectorTest`: pořadí sekcí se sedmi sekcemi, `sortKey` před
  `timestamp`, count `attention`.
- `FeedTextsCatalogTest`: nové klíče a fallbacky.
- Integrace (`SHIPARD_INTEGRATION_DS_PATH` na `lh6x`):
  `AnalysisResultEndpointTest` — `/result` s `attention` + `party` zapíše
  sloupce i partnera; nový `MailArchiveInformationalTest` — archivuje jen
  `info`/`promo` bez návrhu, `restore` vrací jen ručně archivované.
- JSON schema profilu: existující test validace `output_schema` (pokud
  je — `test_schema` v ai-analyzer validuje jen svou stranu; v shpd ověřit
  aspoň, že `czech_general.jsonc` je validní JSONC a `prompt_version`
  = verze v ukázce, pokud takový test existuje; jinak nepřidávat).

## Oprava po ověření (2026-10-07) — `due_date` null odmítá schéma

Při reanalýze na dev DS selhala jedna zpráva `other` / `action` bez lhůty
ve zprávě (ostatní čtyři akční měly lhůtu a prošly):

```
[schema_error] output does not match schema: None is not of type 'string'
at ['message_classification', 'due_date']
```

Model vrátil `"due_date": null`. Prompt v pravidlech výslovně dovoluje
„VYNECHEJ **nebo vrať null**“ a zbytek `output_schema` null připouští
(`document`, registry `party` mají `["object", "null"]`), ale nová pole
jsou deklarovaná jen jako `string` / `object`. Analyzer validuje celý výstup
→ **každá akční zpráva bez výslovné lhůty skončí v Nepodařilo se
zpracovat** (upomínka bez termínu, žádost, výpověď). Server je na null
připravený (`isoDateOrNull`, `MessageTitleComposer::clean`, `is_array`
u party) — chyba je jen ve schématu.

Oprava:

1. `output_schema` → `message_classification.properties`: `due_date`
   a `action_note` `"type": ["string", "null"]`; `party`
   `"type": ["object", "null"]` a jeho `name`, `companyId`, `email`
   `["string", "null"]`. `attention` zůstává string + enum (u `other` je
   povinná obsahově; chybět smí jen u dokladu, kde se nevrací vůbec).
2. V promptu, krok 1) TRIAGE, věta o `due_date` doplnit: „…jen pokud je
   lhůta ve zprávě výslovně uvedená — jinak vrať `null`, NIKDY ji
   neodhaduj.“ Ukázku s akcí bez lhůty nepřidávat (prompt neprodlužovat).
3. `prompt_version` **v4.7.0 → v4.7.1** ve všech výskytech (profil,
   `source.promptVersion` v ukázce, `docs/ai-prompts.md`). Bump je nutný:
   `ds-upgrade` synchronizuje profil jen při vyšší verzi a dev DS už
   v4.7.0 má. Changelog: nechat záznam v4.7.0 a přidat v4.7.1 (nullable
   pole, proč).
4. `tests/Unit/…` pro `/result`: případ `due_date: null`, `action_note:
   null`, `party: null` → uloženo NULL bez warningu (ověří, že server
   null snese; schéma samotné se na straně shpd netestuje).
5. Do `docs/ai-prompts.md` (sekce *Output schema*) jedna věta jako
   pravidlo pro příště: **každé volitelné pole, které prompt dovoluje
   vynechat, musí ve schématu připouštět i `null`** — modely absence
   běžně vyjadřují nullem a `additionalProperties: false` neodpustí nic.

Po opravě na dev DS: `ds-upgrade` (`[UPDATE] profile … v4.7.0 → v4.7.1`),
reanalýza zprávy s kódem `MSG-20261001-0049` (dnes `analysis_state=70`,
`needs_reanalysis=1`) projde a skončí v K vyřízení bez badge lhůty.

## Pasti

- `output_schema` má `additionalProperties: false` **na každé úrovni** —
  bez deklarace `party` v `message_classification` analyzer celý výstup
  odmítne (`schema_error`) a zpráva skončí v Nepodařilo se zpracovat.
  Nové klíče deklarovat dřív, než se prompt o ně začne říkat.
- Analyzer validuje proti schématu z `/claim`; **profil v DB** se
  aktualizuje až `ds-upgrade` (sync jen při vyšší verzi — bez bumpu
  `prompt_version` se nic nenasadí, viz `ai-prompts.md`).
- Reanalýza zprávy, která se z `other` stane dokladem, musí tři pole
  vynulovat — jinak by faktura nesla starou poznámku „Zaplatit“.
- `due_date` modelu **neodhadovat**: lhůta v badge je silný signál,
  vymyšlené datum je horší než žádné. Prompt to říká výslovně, server
  validuje jen formát.
- Archivovat vše počítat přes `TableGateway`, ne hromadným `UPDATE`:
  `docStateMain` (`resolveIncomingMainState`), hooky a budoucí
  `stateChanged` handlery by jinak minuly.
- Vrátit nesmí vracet zprávy archivované pravidlem (`auto_disposed_by`
  NOT NULL) — ty mají vlastní digest + Vrátit vše s jinou sémantikou
  (`analysis_state` zůstává 30).
- Sekce Ostatní ukazuje max 30 karet, ale Archivovat vše bere **všechny**
  odpovídající řádky — N v tlačítku musí přijít ze serveru, ne z délky
  pole karet.
- `Feed.svelte` zrcadlí `SECTION_ORDER` ručně — zapomenutá sekce na FE
  spadne do fallbacku dle `kind` (`review` → Ke kontrole) a karta „zmizí“
  do jiné sekce bez chyby.
- Formát data v badge: vybrat jednu stranu (server s `ext-intl`, nebo FE
  s `Intl.DateTimeFormat`) a držet ji; dnešní `timestamp` karet formátuje
  FE.
- Veřejné texty (docs, help, komentáře, testy): žádný název registrátora,
  domény ani firmy z reálných dat — fixtures s vymyšlenými hodnotami.

## Commit

Čtyři logické commity (push dělá člověk):

1. `feat(mail): prompt v4.7.0 — pozornost, lhůta a protistrana u zprávy bez dokladu`
   — profil + schéma + changelog, sloupce + cfgItem + docs tabulky,
   `/result` + `MessagePartnerWriter` + api-contract, testy (sekce 1–3).
2. `feat(mail): archiveIfOther vynechá zprávy k vyřízení (#105 D6)`
   — disposer, texty dispozice a karty, testy (sekce 4).
3. `feat(dashboard): sekce K vyřízení a Archivovat vše v Ostatních (#105 D4, D5)`
   — server feed + endpointy + FE + i18n + `docs/dashboard.md`, testy
   (sekce 5).
4. `docs(mail): detail zprávy, MCP a nápověda k ostatní poště (#105 D9)`
   — form, MCP, `ai-analysis.md`, README, roadmap, help (sekce 6),
   aktualizace `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

Před každým commitem `php -l` změněných souborů, cílený
`vendor/bin/phpunit --filter`, u FE `npm run check:i18n && npm run build`,
`python3 scripts/check-sensitive.py <soubory>`.

## Poznámky k implementaci (2026-10-07)

- **Formát lhůty na kartě** dělá server tímtéž `formatDate()` jako datum
  doručení (cs `j. n. Y`, en `Y-m-d`) — plné datum, ne krátký tvar bez
  roku z D4 (schváleno: jeden formát data na kartách). Katalog
  `attention.due` / `attention.overdue` nese jen text kolem data.
- **Popisek „Vyřízeno“** řeší id akce `done` (kind `archive_message`),
  ne sniffování prefixu karty — FE přidal jen klíč
  `dashboard.card.action.done`.
- **`sections[].archivable`** sčítá `FeedCollector::sortAndCap` z interního
  pole `archivable`, které zdroj nastaví řádkům `info` / `promo`; spolu
  se `sortKey` je odstraňuje `stripInternalFields`. `DashboardController`
  beze změny. Pravdivost N má stejný strop jako `total` (pojistný limit
  zdroje 500).
- **Sdílená podmínka Ostatní** = `OtherMailQuery` (modul pošty):
  `pendingWhere()` používá zdroj feedu, `informationalWhere()` endpoint
  Archivovat vše — SQL fragment z konstant bez placeholderů.
- **Archivovat vše není jedna transakce**: `TableGateway::saveDocument`
  otevírá vlastní transakci a MariaDB vnořené nemá (vnější `begin` by
  druhý `START TRANSACTION` tiše commitl), proto jede zpráva po zprávě
  jako `undoAutoArchive`; při chybě uprostřed odpověď nese jen skutečně
  archivované id. Vzor i pro Vrátit.
- **`MailController` dostal volitelný `DocumentEventDispatcher`**
  (9. parametr) a `dispatchMail()` v `public/index.php` ho předává — aby
  archiv přes gateway spouštěl handlery jako `SenderRulesController`.
- **Učící handler** se hromadným archivem nerozjede ani omylem:
  `IncomingMessageDocument` přechod stavu neeviduje, gateway `stateChanged`
  nevysílá (známý bug z 2026-10-06, D6). Po opravě bude Archivovat vše
  vypadat jako N ručních odklizení per odesílatel — poznámka v docbloku
  endpointu, rozhodnout při opravě.
- **`party.email`** z klasifikace se na Osobu nepáruje (resolver umí jen
  IČO / DIČ / VAT ID) — zůstává v `analysis_json`.
- **Zápis pozornosti** je součást téhož UPDATE jako `primary_type`
  (jeden guard na ruční volbu); existující testy klasifikace dostaly tři
  NULL sloupce navíc.
- Změna znění `archiveIfOther` se propsala i do `registry-mvp.md`,
  `core_mail_sender_rules.md`, README modulu, `MailDigestSourceTest`
  a nápovědy; `ProfileSchemaDriftTest` hlídá verzi v4.7.0 a nová pole
  schématu proti klíčům `attentionKinds.jsonc`.
- `ds-upgrade` proběhl jen na dev DS `lh6x-l` (režim volný): tři sloupce,
  cfgItem, profil v4.6.2 → v4.7.0 a po opravě schématu → v4.7.1. Ostatní
  zdroje čekají na ruční `ds-upgrade`.
- **Oprava po ověření** (sekce výše) je zapracovaná: nullable volitelná
  pole ve schématu, věta „jinak vrať null“ v promptu, v4.7.1, testy na
  null v `AnalysisControllerTest` a `AnalysisResultEndpointTest`,
  pravidlo pro volitelná pole v `ai-prompts.md` → *Output schema*.
- Do nápovědy přibyl odstavec v `co-dnes-nejde.md` (zálohová faktura
  přijatá neexistuje — výzvy k platbě jdou do K vyřízení), aby odkaz
  z `prijem-posty.md` nebyl prázdný; `kontrola-vytezeni.md` má nový výčet
  sekcí.

## Hotovo když

- [x] `czech_general.jsonc` je v4.7.1, schéma deklaruje `attention`,
      `action_note`, `due_date`, `party` (volitelná pole nullable);
      changelog v `ai-prompts.md`
- [x] akční zpráva **bez lhůty** projde analýzou (reanalýza
      `MSG-20261001-0049` na dev DS) a je v K vyřízení bez badge
- [x] `ds-upgrade` na dev DS přidal tři sloupce, cfgItem `attentionKinds`
      a synchronizoval profil (`[UPDATE] profile … → v4.7.0`) — `lh6x-l`
- [ ] nová zpráva bez dokladu na dev DS po analýze nese `attention`;
      `action` zpráva má poznámku, u lhůty ve zprávě i `action_due`;
      `partner_name` je dodavatel služby, ne přeposílající
- [x] reanalýza zprávy `other` → doklad vynuluje tři pole (integrační test)
- [ ] dashboard: sekce K vyřízení mezi Ke kontrole a Nepodařilo se
      zpracovat, karta s poznámkou, badge lhůty, Vyřízeno archivuje
- [ ] Ostatní: jen `info`/`promo`/NULL řádky; Archivovat vše (N) odklidí
      všechny `info`/`promo` (i mimo 30 zobrazených), toast Vrátit je
      vrátí; NULL řádky zůstaly
- [ ] pravidlo `archiveIfOther` na dev DS neodklidí zprávu `action`,
      `info` odklidí; digest + Vrátit vše beze změny
- [ ] detail zprávy ukazuje pozornost, poznámku, lhůtu; `mail_list_pending`
      je vrací
- [x] cílené unit + integrační testy prošly; `php -l`, `check:i18n`,
      `build`, `help-index.py --check`, `tasks-index.py --check`,
      `check-sensitive.py --all` čisté
- [x] `docs/dashboard.md`, `ai-analysis.md`, `ai-prompts.md`,
      `api-contract.md`, `registry-mvp.md`, `roadmap.md`, help aktuální
- [x] `**Stav:**` tohoto tasku aktualizován ve stejném commitu jako kód
