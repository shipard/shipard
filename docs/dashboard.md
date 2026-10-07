# Dashboard

Home obrazovka aplikace — výchozí pohled po přihlášení. Od fáze 2 je to
**prioritizovaný feed akčních karet**: uživatel vidí, co má řešit, a provede
akci **přímo z feedu** (apply / review / reject / reanalyze) bez procházení
viewerů.

Fáze 2 pokrývá jeden tok — **došlá pošta → doklad** — plus deterministické
alerty jako druhý zdroj. Fáze 2b přidává **generované AI shrnutí dne** nad
feedem (SSE, cache dle hashe feedu, tichá degradace na statické county — §11).

## 1. Přehled

```
┌──────────────────────────────────────────────────────────────┐
│  Dashboard                                       [Obnovit ↻]  │
├──────────────────────────────────────────────────────────────┤
│  🤖 Dnešní shrnutí                                            │
│  Aktuálně máte: 7 naléhavých, 59 ke kontrole, 41 připravených.│
├──────────────────────────────────────────────────────────────┤
│  🔵 Položky k založení (2)                                    │
│  [Pohonné hmoty · 3 doklady čekají]  [Dokončit nastavení]     │
│  🟢 Připraveno (41)                                           │
│  ┌──────────────────────────────────────────────────────────┐│
│  │ 38 faktur připravených k použití   ← sbalený souhrnný pruh││
│  │ Celkem 96 420,00 CZK · jistota 91–98 %  [Projít][Zobrazit]││
│  └──────────────────────────────────────────────────────────┘│
│  ┌ 3 dokumenty připravené k zařazení do Spisovny ───────────┐│
│  a 11 dalších                                                 │
│  🟡 Ke kontrole (52)                                          │
│  [plná karta]  [plná karta]        ← grid, 2 sloupce          │
│  … (30 karet)                                                 │
│  a 22 dalších                                                 │
│  🔴 Nepodařilo se zpracovat (7)                               │
│  ┌──────────────────────────────────────────────────────────┐│
│  │ AI vrátila nepoužitelný návrh      ← plná karta, full-width│
│  │ e-mail „Faktura 2026-0042"    [Otevřít e-mail][Znovu anal.]││
│  └──────────────────────────────────────────────────────────┘│
│  🟡 Upozornění (5)                                            │
│  [alert karta]  [alert karta]      ← grid, dle závažnosti     │
│  ⚪ Ostatní (93)                                               │
│    {AI titulek} · „…"            Koš · Archiv ← kompaktní řádek│
│  a 63 dalších                                                 │
└──────────────────────────────────────────────────────────────┘
```

Feed je rozdělený do **šesti sekcí podle toku práce** (#101 D1/D8):
Položky k založení → Připraveno → Ke kontrole → Nepodařilo se zpracovat
→ Upozornění → Ostatní. První čtyři jsou tok příchozí pošty (nejdřív to,
co odblokuje ostatní, pak rychlé potvrzení, pak kontrola, pak ruční
řešení), Upozornění je stav účetnictví (jiný režim práce, často
přetrvává), Ostatní úklid. Sekci karty **určuje server** (pole
`feedSection`, §4), frontend jen seskupuje. Vizuální váha odpovídá
potřebné pozornosti (Issue #32/2): selhané zpracování plné full-width
karty, položky / kontrola / upozornění grid, ready pásmo defaultně
**sbalené do souhrnného pruhu** (rozbalené = kompaktní jednořádkové
položky) a Ostatní tlumené řádky. Prázdná sekce se nerenderuje.

**Strop 30 karet platí per sekce** (D3a) a hlavička sekce ukazuje
**pravdivý počet** všech karet sekce (D3b); pod přetékající sekcí je
odkaz „a N dalších" do příslušného vieweru. Karta „…a další nezpracovaná
pošta" zanikla.

Ready pásmo se dělí **per kategorie do dvou pruhů** (D11): pruh
**Přijaté faktury** (součty per měna, jistota, Projít) a samostatný pruh
**Spisovna** (vlastní titulek, bez součtů a bez Projít — průchod
Spisovnou se přidá později). Zobrazí se jen neprázdné pruhy; počet
a součty v pruhu mluví o všech připravených dokladech skupiny, ne jen
o kartách pod stropem.

## 2. Princip

Fáze 1 (widget MVP) říkala *„přehled, ne přístupový bod"*. Fáze 2 ten princip
**vědomě obrací** pro ohraničenou, bezpečnou sadu akcí:

- Feed je **místo, kde se akce odehraje**, ne jen odkaz do vieweru.
- Bezpečné je to proto, že `apply` zakládá jen **Koncept** (`docState=10`),
  nic nefinalizuje, vše je auditovatelné (`source_kind='aiExtraction'`,
  `resolved_by` na analýze) a `apply` je **vratné** (unapply, §6.5).
- Deterministika zůstává deterministická: **pravidla (alerts) pro stav, AI
  (analyzer) pro jazyk a nejednoznačnost.** Oba zdroje padají do téhož feedu.
- **Řadí, stropuje a do sekcí dělí server** (`FeedCollector::sortAndCap`
  → `FeedResult`), frontend jen renderuje.
- **Sekce feedu určuje server** (#101 D2b): každá karta nese
  `feedSection` (výchozí z `kind`, zdroj smí přepsat), `Feed.svelte` jen
  seskupuje a renderuje v pořadí D8. Tři ortogonální pole: `category`
  (chipy filtru invoices/registry/other), `navSection` (badge navigace,
  `?section=`) a `feedSection` (sekce feedu) — warning alert je kategorie
  Ostatní, sekce Upozornění (záměr). Ve filtru se sekce počítají nad
  filtrovanou množinou; serverové počty `sections` platí jen v záložce
  Vše. Serverová rozšíření: `sections` a `readySummary` pro sbalený pruh
  (§7).

## 3. Architektura

```
            GET /_ui/dashboard
                   │
                   ▼
        ┌──────────────────────┐
        │  DashboardController │   napevno registrované FeedSource zdroje (D10)
        │  ::dashboard()       │
        └──────────┬───────────┘
        ┌──────────┴───────────┐
        ▼                      ▼
 MailSuggestionsSource     AlertsSource
 collectCards(ctx)         collectCards(ctx)
        │                      │
        └──────────┬───────────┘
                   ▼ sortAndCap (sekce → pásmo → čas, strop per sekce)
              FeedResult {cards, allCards, sections}
                   ▼
              Feed.svelte ── FeedCard × N
                              │
                              ├─ apply_message   → jednoklik apply (safe);
                              │     422 unresolved_required → fall-through
                              │     do DocumentExchangePreviewModal
                              ├─ review_message  → DocumentExchangePreviewModal
                              │     └─ Použít → applyMessage(ndx) → FormDialog/toast
                              ├─ reject_message  → RejectReasonPrompt → reject
                              ├─ reanalyze       → reanalyzeMessage(msgNdx)
                              ├─ open_detail     → ViewerDetailModal (read-only
                              │     náhled záznamu; mail „Otevřít e-mail")
                              └─ open_viewer/open_form → navigace
```

- **Sběr karet** = služba `Core\Feed\FeedCollector` (UI shells Fáze 3) —
  „one calculation, N presentations": nad `FeedCollector::collect()` stojí
  dashboard, AI shrnutí i `GET /_ui/section-badges` (badge stavů sekcí).
  `DashboardController` je prezentační vrstva (`sections`, readySummary,
  SSE); collector je bezstavový, controller si ho instancuje sám
  (`new FeedCollector()` — repo nemá DI kontejner).
- **Zdroj karet** = lehké rozhraní `FeedSource::collectCards(FeedContext): array`
  (`src/Core/Feed/`). Konzumenti registrovaní napevno v collectoru (D10);
  žádný plugin-registr. `MailSuggestionsSource` (`modules/core/mail/src/Feed/`),
  `AlertsSource` (`modules/core/alerts/src/Feed/`),
  `ContentTagSuggestionsSource` (`modules/core/exchange/src/Dashboard/`).
- **Degradace dle modulů** (task `hosting-07b`): zdroj se vůbec nezaregistruje,
  když jeho klíčová tabulka na DS není — mail zdroje vyžadují
  `core_mail_incoming_messages`, `AlertsSource` `core_alerts_alerts`,
  `ContentTagSuggestionsSource` `core_mail_message_analyses` +
  `economy_items` + `economy_accounting_accounts`
  (mapa tabulka → zdroj žije ve `FeedCollector::collect()`, `$tables` =
  runtime `TableDefinition` mapa z dispatche). Dashboard tak nepadá na DS
  bez `core.mail` (hosting DS).
- **Per-source izolace**: `collect()` obaluje každý zdroj try-catch —
  `\Throwable` se zaloguje (`Dashboard feed source failed: <class>`) a feed
  pokračuje ostatními zdroji. Dashboard nevrátí 500, dokud funguje aspoň
  jeho obálka.
- **Sekce, řazení + strop** dělá `FeedCollector::sortAndCap()` (#101) a
  vrací `FeedResult`:
  1. každé kartě doplní `feedSection` — explicitní ze zdroje, jinak
     `DEFAULT_SECTION_BY_KIND` (urgent → failed, review → review, ready →
     ready, info → other; neznámý kind i neznámá sekce → other);
  2. seřadí dle `SECTION_ORDER` (D8), uvnitř sekce dle `KIND_ORDER`
     (urgent/review/ready/info — v Upozornění = závažnost, D5), pak
     `timestamp` DESC (bez timestampu naspod);
  3. ořízne každou sekci na `MAX_CARDS_PER_SECTION = 30` a spočítá
     `sections[] {id, total, shown}` (jen neprázdné, v pořadí D8).
  `FeedResult::$cards` = po stropu, `$allCards` = bez stropu (county,
  badge, AI digest, `readySummary`), `hasMore()` = některá sekce přetekla.
- **Pojistný limit zdrojů**: `FeedContext::$sourceLimit`
  (`FeedCollector::SOURCE_LIMIT = 500`) omezuje `LIMIT` dotazů zdrojů —
  není to strop feedu (návrhy ready + review chodí jedním dotazem a pásmo
  se počítá až v PHP). Počty jsou pravdivé jen do limitu; nezvyšovat bez
  měření (`canonical_json` každého řádku se dekóduje a sběr běží i při
  pollingu badge).

## 4. Kartový kontrakt

```json
{
  "id": "mail_suggestion:123",
  "source": "mail",
  "kind": "ready",
  "icon": "check",
  "stateStyle": "done",
  "category": "invoices",
  "title": "Přijatá faktura — ČEZ a.s.",
  "headline": {
    "partnerName": "ČEZ a.s.",
    "typeLabel": "Přijatá faktura",
    "amountText": "4 200,00 CZK"
  },
  "confidencePct": 94,
  "emailSubject": "Faktura 2026000123",
  "receivedDateText": "28. 6. 2026",
  "details": [
    { "label": "Číslo dokladu", "value": "2026000123" },
    { "label": "Splatnost", "value": "29. 4. 2026" },
    { "label": "Variabilní symbol", "value": "2026000123" }
  ],
  "secondaryFindings": [
    { "type": "contract", "type_label": "Smlouva", "note": "Rámcová smlouva v příloze smlouva.pdf" }
  ],
  "warning": "Předzpracování: Odkaz na dokument nefunguje",
  "timestamp": "2026-06-28T10:00:00+00:00",
  "context": { "messageNdx": 123, "analysisNdx": 456, "confidence": 0.94, "target": "docs" },
  "attachments": [
    { "id": 12, "name": "Faktura.pdf", "mime_type": "application/pdf", "file_size": 245760 }
  ],
  "attachmentsTotal": 5,
  "actions": [
    { "id": "apply",  "kind": "apply_message",  "target": { "messageNdx": 123 }, "primary": true },
    { "id": "review", "kind": "review_message", "target": { "messageNdx": 123 } },
    { "id": "reject", "kind": "reject_message", "target": { "messageNdx": 123 } }
  ]
}
```

- `id` — stabilní `"{source}:{entityId}"` (dedup / animace mizení po akci).
- `stateStyle` — reuse globálních `docState_*` CSS tříd (proužek karty).
- `headline` — **volitelné**, strukturovaná hlavička návrhových karet:
  `partnerName` (povinný uvnitř objektu — bez partnera se `headline`
  neposílá a karta padá na title/subtitle fallback), `typeLabel` (povinný),
  `amountText` (volitelný server-formátovaný string; registry karty ho
  nemají). **Karta s `headline` neposílá `subtitle`** — jeho dnešní data
  (částka, jistota, e-mail) jsou strukturovaná. `title` zůstává (fallback
  + použití mimo kartu).
- `confidencePct` — **volitelné**, int 0–100; jen návrhové karty se známou
  jistotou (frontend kreslí donut). `context.confidence` je zdrojově
  specifický — frontend se váže na toto top-level pole.
- `emailSubject` — **volitelné**, lidský titulek zprávy bez obalu
  „e-mail „…"": předmět, u generického / prázdného předmětu a ručních
  zpráv titulek z AI (`ai_title`, pravidlo D3 `IncomingMessageTitle` —
  tasks/mail-message-title-partner.md); posílají ho všechny tři druhy mail
  karet, karta ostatní pošty jen když se liší od jejího `title`
  (tasks/dashboard-other-row-title.md D3). Frontend přidává ikonu obálky
  a uvozovky.
- `receivedDateText` — **volitelné**, lokalizované datum doručení zprávy
  (server-formátované, cs `j. n. Y` / en `Y-m-d`); posílají ho všechny tři
  druhy mail karet. Frontend ho zobrazuje za typem dokladu / subtitle
  (oddělené „·“). Na rozdíl od `timestamp` (řadicí pole všech karet,
  u alertů = last_seen) nese význam „kdy pošta přišla“.
- `details` — **volitelné**, pole `{label, value}` pro rozbalovací detail
  karty; labely lokalizuje server z katalogu textů feedu (§5). Jen neprázdné
  hodnoty; prázdné pole se neposílá (expander na frontendu se ukazuje jen
  když `details` existuje).
- `secondaryFindings` — **volitelné**, pole `{type, type_label, note}`
  z `analysis_json.secondary_findings` běhu (D7) — informativní hint
  dalších nálezů ve zprávě („+ smlouva v příloze"), žádné entity, žádné
  akce. Frontend kreslí hint řádek na kartě.
- `warning` — **volitelné**, lokalizovaný řádek upozornění (string).
  Dnes jen mail karty: zpráva s předzpracováním ve stavu 40 „Hotovo
  s chybami" nese „Předzpracování: {titulek kategorie}" z katalogu
  `core.mail.preprocessErrorKinds` (`PreprocessErrorPresenter::cardWarning`,
  tasks/mail-preprocess-error-messages.md D3c) — návrh i klasifikace
  vznikly bez dokumentu, který mělo předzpracování vytvořit. Žádné
  technické poznámky (nesou URL s tokeny). Frontend: plná karta
  (`FeedCard`) řádek ve stylu varování, kompaktní řádek (`FeedRowCompact`)
  ikona varování s tooltipem. Druh karty (`kind`) se nemění.
- `feedSection` — sekce feedu podle toku práce (#101 D2b), výčet
  `newItems` | `ready` | `review` | `failed` | `alerts` | `other`
  (konstanty `FeedSource::SECTION_*`, pořadí `FeedCollector::SECTION_ORDER`,
  D8). V odpovědi ji nese **každá** karta: zdroj ji nastaví jen tam, kde
  se karta má lišit od výchozího mapování z `kind`
  (`DEFAULT_SECTION_BY_KIND`: urgent → failed, review → review, ready →
  ready, info → other; neznámý kind → other), chybějící nebo neznámou
  hodnotu doplní collector. Řídí seskupení a rozvržení ve `Feed.svelte`
  a strop per sekce. Ortogonální ke `category` (filtr) i `navSection`
  (badge navigace) — tři různé účely, nemíchat. Mapování karet viz §4.1.
- `category` — **volitelné**, výčet `invoices` | `registry` | `other`
  (konstanty `FeedSource::CATEGORY_*`) — řídí klientský filtr feedu
  (`FeedFilter.svelte`). Karta **bez pole** se zobrazuje jen v záložce Vše
  (bezpečný default; dnes žádná taková karta není). Mapování: návrhová karta
  dle `context.target` (docs→invoices, registry→registry); chybové karty,
  karta ostatní pošty, digest, návrhy pravidel i alert karty → `other`.
- `navSection` — **volitelné**, id sekce navigace (`global.navSections`)
  nebo sentinel `_top` (`FeedSource::NAV_SECTION_TOP`) — atribuce karty pro
  **badge stavů sekcí** (UI shells Fáze 3, `GET /_ui/section-badges`).
  Opt-in: karta bez pole (či s `null`) se do badge nepočítá; počítají se
  jen pásma `urgent`/`review`. Jiný účel než `category` (ta řídí chips
  filtru feedu). Mapování: mail karty → `_top`, content-tag karta →
  `basic`, alert karty per check z `alertChecks[].navSection`
  v `module.jsonc` (setup checky pole nemají — agregují se do setup
  karty).
- `timestamp` — sekundární řazení uvnitř pásma (ATOM).
- `context` — volitelná zdrojově-specifická data.
- `attachments` + `attachmentsTotal` — **volitelná** pole, jen mail karty
  s ≥1 obsahovou přílohou zprávy (karty bez příloh je nemají vůbec).
  `attachments` max 3 položky (strop dělá server), struktura položky shodná
  s `fetchContentAttachments()` ve vieweru; `attachmentsTotal` = počet před
  stropem — frontend kreslí `+N`, když `attachmentsTotal > attachments.length`.
- `actions[].label` — u mail akcí **chybí**, frontend ho lokalizuje podle
  `action.id` (i18n `dashboard.card.action.*`); alert akce nesou vlastní
  pre-lokalizovaný `label` (passthrough).

### 4.1 `kind`, `feedSection` a řazení

`kind` je pásmo karty (barva proužku, `summary.counts`, badge sekcí
navigace, AI shrnutí) — **nemění se** (#101). Sekci feedu nese
`feedSection`; řazení je sekce (`SECTION_ORDER`) → `kind` (`KIND_ORDER`
urgent → review → ready → info) → `timestamp` DESC.

| `kind` | Pásmo | Zdroj → mapování |
|---|---|---|
| `urgent` | 🔴 | alert `error`; zpráva `analysis_state=70` (analýza selhala); nevalidní výstup AI (`mail_invalid`) |
| `review` | 🟡 | otevřený návrh v pásmu `review`/`low` (runtime resolver); alert `warning`; chybová karta s `primary_type=other`; karta položky k založení (content tag, D12 — blokuje povýšení návrhů); návrh pravidla odesílatele |
| `ready`  | 🟢 | otevřený návrh v pásmu `ready` (jednoklik apply) |
| `info`   | ℹ️ | alert `info`; karta ostatní pošty; digest auto-archivu |

Mapování karet na sekce (#101 D8, D10):

| Karta | `kind` | `feedSection` | Nastavuje |
|---|---|---|---|
| Položka k založení (`content_tag:*`) | review | `newItems` | zdroj |
| Dokončit nastavení (`alert-group:setup`) | dle závažnosti | `newItems` | zdroj |
| Návrh dokladu / Spisovny — pásmo ready | ready | `ready` | výchozí |
| Návrh dokladu / Spisovny — pásmo review/low | review | `review` | výchozí |
| Návrh pravidla odesílatele (`mail_rule_suggestion:*`; titulek podle dispozice — `senderRule.title` pro `archive`, `senderRule.titleIfOther` pro `archiveIfOther`) | review | `review` | výchozí |
| Selhaná analýza (`mail_message:*`), nevalidní výstup (`mail_invalid:*`) | urgent | `failed` | zdroj |
| Selhaná analýza zprávy s `primary_type=other` | review | `failed` | zdroj |
| Ostatní alerty — individuální i skupinové | dle závažnosti | `alerts` | zdroj |
| Ostatní pošta (`mail_notinvoice:*`), digest auto-archivu (zprávy archivované při příjmu i po analýze / při potvrzení pravidla — shodný audit `auto_disposed_*`) | info | `other` | výchozí |

### 4.2 Slovník `kind` akcí (chování odvozuje frontend)

| Action `kind` | Chování | Cíl |
|---|---|---|
| `apply_message` | jednoklik apply (safe, bez userActions); `422 unresolved_required` → fall-through do review modalu | `{messageNdx}` |
| `review_message` | otevři `DocumentExchangePreviewModal` | `{messageNdx}` |
| `reject_message` | `RejectReasonPrompt` → reject | `{messageNdx}` |
| `reanalyze` | inline `reanalyzeMessage(messageNdx)`, refetch | `{messageNdx}` |
| `trash_message` | zpráva do Koše (`docState=90`, docState-only save), refetch | `{messageNdx}` |
| `archive_message` | zpráva do Archivu (`docState=80`, docState-only save), refetch | `{messageNdx}` |
| `confirm_sender_rule` / `reject_sender_rule` | potvrzení/zamítnutí návrhu pravidla odesílatele, refetch; potvrzení po commitu odklidí čekající řádky Ostatní od adresy (`SenderRuleConfirmedHandler`, D8) — refetch je ukáže v digestu | `{ruleId}` |
| `undo_auto_archive` | „Vrátit vše" z digest karty auto-archivu, toast + refetch; zprávy archivované po analýze se vrátí jako řádky Ostatní bez nové analýzy (`analysis_state` 30 zůstává, D7), zprávy z pre-triage jdou do fronty | `{date?}` |
| `open_viewer` | navigace | `{viewerId, recordId?, viewGroup?, filters?}` — `viewGroup` chip cílového vieweru, `filters` `{filterId: value}` jeho custom filtrů (jednorázové hinty `pendingViewGroup` / `pendingFilters`, viz `docs/frontend.md`) |
| `open_form` | otevři form | `{table, recordId?/id?}` |
| `open_detail` | read-only detail záznamu v modalu (`ViewerDetailModal` → `GET /_ui/viewer/{viewerId}/detail/{id}`; `toolbar` z odpovědi se ignoruje, `tabId` ořeže detail na jediný tab) | `{viewerId, recordId, tabId?}` |
| `materialize_content_tag` | založení účetní položky pro obsahový štítek — `POST /_exchange/content-tags/materialize`, toast s „Otevřít" (form položky) + refetch; labely akcí posílá server (passthrough — u goods.stock nesou čísla účtů z osnovy) | `{tag, account?}` |

## 5. Zdroje karet

### Texty karet — katalog `*.feedTexts` (#101 D11–D16)

Titulky, podtitulky, labely detailu a serverem lokalizované labely akcí
skládá zdroj z **katalogu modulu, který kartu vyrábí**: cfgItemy
`core.mail.feedTexts`, `core.alerts.feedTexts`, `core.exchange.feedTexts`
(`config/feedTexts.jsonc`, registrace v `config[]` module.jsonc). Čte je
sdílený helper `Shipard\Core\Feed\FeedTexts`
(`FeedTexts::forContext($ctx, cfgItem)`, jedna instance per sběr zdroje,
předávaná helperům jako `AnalysisErrorPresenter`):

```php
$texts->t('digest.title', '{n, plural, one {# message auto-archived} other {# messages auto-archived}}', ['n' => $count]);
```

- Položka katalogu `{text, text:cs, text:en}`; `text` je vzor **ICU
  MessageFormat** se syntaxí frontendu (`frontend/src/i18n/cs.js`) —
  plurály, `{param}`; locale formátování = jazyk feedu. Uvnitř plurálu
  vždy `#` (PHP intl neumí `{n}` v plurálové větvi téhož argumentu),
  apostrof `''`. Pravidla: [modules.md](modules.md) → ICU vzory
  v katalozích textů.
- Druhý argument je **anglický fallback** — znak po znaku holé pole
  katalogu. Použije se bez compiled configu i bez cfgItemu (DS před
  `ds-upgrade`) tiše; chybějící klíč v existujícím katalogu nebo nevalidní
  vzor → `ErrorLogger::warn` (jednou per klíč a sběr) + fallback; selže-li
  i ten, vrátí se vzor beze změny. Nikdy výjimka — per-source izolace
  collectoru by jinak zahodila celý zdroj.
- `#` formátuje číslo v locale (`1 234 zpráv` s nezlomitelnou mezerou);
  doslovné `{n}` mimo plurál ne.
- **Jak přidat text:** klíč do `feedTexts.jsonc` modulu s `text` +
  `text:cs` + `text:en`, volání `$texts->t(klíč, stejný anglický text,
  params)` ve zdroji, `ds-upgrade` v dev DS. `FeedTextsCatalogTest` hlídá
  úplnost cs/en, parsovatelnost vzorů, shodné parametry obou jazyků,
  shodu fallbacků ve zdrojích s katalogem a že žádný klíč neleží ladem;
  `tests/Fixtures/Core/Feed/ShippedFeedTexts` dodává katalogy testům
  zdrojů (bez nich by assertovaly anglický fallback).
- Mimo katalog (D17): labely akcí mail karet (frontend podle
  `action.id`), formát data (`j. n. Y` / `Y-m-d` ve zdroji), prompt AI
  shrnutí, titulky a zprávy samotných alertů (#102), hlášky selhané
  analýzy (`analysisErrorKinds`, `preprocessErrorKinds`).

### 5.1 MailSuggestionsSource

Message-centricky (D10 z `tasks/mail-message-centric.md`): karta =
**zpráva s otevřeným dokumentovým návrhem** poslední úspěšné analýzy —
zprávy v docState 10/20, `analysis_state=30`, poslední úspěšný běh
(`MAX(analyzed_at)`, status=2) s `canonical_json IS NOT NULL` a
`resolution IS NULL`. Návrhy s `proposed_type='other'` se ignorují —
pojistka, prompt je zakazuje. Confidence pásmo se počítá za běhu
(`AnalysisConfidenceResolver`, prahy profilu běhu + strop pokrytí řádků):

- pásmo **ready** → `kind=ready`, `stateStyle=done`; akce `apply`
  (primary, jednoklik safe), `review`, `reject`.
- pásmo **review/low** → `kind=review`, `stateStyle=confirmed`/`edit`;
  akce `review` (primary), `reject`. (Jednoklik se u nižší jistoty
  záměrně nenabízí.)

Karta má `id = "mail_suggestion:{messageNdx}"`, akční targety
`{messageNdx}`.

**Chybové karty** — dva zdroje, obě `kind=urgent`, `stateStyle=error`,
`feedSection=failed` (Nepodařilo se zpracovat, #101 D4 — i degradovaná
review varianta níže), akce `reanalyze` (`{messageNdx}`) + `open_detail`
(read-only náhled zprávy, viewer `core.mail.incoming`, tab `content`):

- zprávy `analysis_state=70` (analýza selhala) mimo Archiv/Koš
  (`id = "mail_message:{ndx}"`); když už dřívější klasifikace určila
  `primary_type='other'`, karta degraduje na `kind=review`;
- otevřený návrh s nevalidním výstupem AI — forenzní wrapper
  `_validationError` v `canonical_json` (`id = "mail_invalid:{ndx}"`);
  návrh nelze použít.

Texty a primární akci obou karet dává katalog hlášek
`core.mail.analysisErrorKinds` přes `AnalysisErrorPresenter`
(`modules/core/mail/docs/ai-analysis.md` → „Chybové hlášky pro
uživatele", tasks/mail-analysis-error-messages.md D3c–D5):

- `title` = titulek kategorie (např. „AI vrátila data v nečekaném
  tvaru", „Odpověď AI se nevešla do limitu", u wrapperu „AI vrátila
  nepoužitelný návrh"); `subtitle` zůstává odesílatel, `emailSubject`
  předmět;
- `details` = dva řádky „Co se stalo" (vysvětlení + případný detail
  s cestou v návrhu) a „Co dělat" (hint). Technická hláška
  `error_message` na kartu nejde — může nést hodnoty z dokladu;
- **primární akce (D4)**: `reanalyze` je primární, jen když má výchozí
  aktivní profil novější `prompt_version` než selhaný běh (hint „Analýza
  se mezitím aktualizovala…"); jinak je primární `open_detail` a
  reanalyze zůstává sekundární (hint „Opakování se stejnou verzí…").
  Primární akce je vždy první v `actions`. Verzi selhaného běhu nese
  u stavu 70 poslední běh se `status=3` (korelovaný subselect
  v `fetchErrorRows`), u wrapperu samotný řádek návrhu.

**Karty ostatní pošty** (`mail_notinvoice:*`) — zprávy `analysis_state=30`,
`docState=10` (Nová), `primary_type='other'` bez otevřeného návrhu →
`kind=info`, `stateStyle=archive`; akce `trash_message` (primary),
`archive_message`, `open_detail`. Žádné auto-zavření ani digest — jedna
karta per zpráva s jednoklikovým úklidem. Titulek = `ai_title` zprávy
(AI popis obsahu — „Newsletter — novinky dodavatele", „Sken obálky"; jazyk
AI profilu), bez něj konstanta `other.title` z katalogu („Neobsahuje doklad
ani dokument" / „Contains no document") — analýzy před promptem v4.3.0 nebo
analyzer bez `title`. `emailSubject` jen když se od titulku liší: u skenů,
ručního nahrání a generických předmětů vrací pravidlo D3
`IncomingMessageTitle` právě `ai_title` a předmět by titulek jen opakoval
(tasks/dashboard-other-row-title.md D1–D3).

Titulek: `proposed_type` → label z cfgItem `core.mail.primaryTypes`
(registry typy label druhu z `base.registry.docKinds`) + partner
z `canonical_json` (kanonický doklad — protistrana dle `selfParty`,
registry `party.name`). Dotazy zdroje omezuje pojistný `sourceLimit`
(řádově stovky, ne strop feedu — §3), takže N `json_decode` je únosné;
denormalizace headline do sloupců je pozdější optimalizace.

**Strukturovaná pole per druh karty** (viz §4):

- **Návrhová karta (docs target)**: `headline.partnerName` = Osoba zprávy
  (`partner_person` — ruční volba / Použít mají přednost) → jinak
  `counterpartyName()` z canonicalu → jinak snapshot `partner_name` zprávy
  (bez partnera se `headline` neposílá → title/subtitle fallback),
  `headline.typeLabel` = `docTypeLabel()`,
  `headline.amountText` = `formatAmount()`; `confidencePct`; `emailSubject`;
  `details` v pořadí číslo dokladu (`docNumber`), splatnost (`dates.dueDate`,
  formát cs `j. n. Y` / en `Y-m-d`, nevalidní datum → řádek vynechat),
  variabilní symbol (`payment.paymentReference`) — jen neprázdné.
- **Registry karta**: `partnerName` = `party.name`, `typeLabel` =
  `docKindLabel()`, bez `amountText`; `details` = jediný řádek „Platí do"
  z `registryValidTo()` (bez něj se `details` neposílá).
- **Chybová karta / karta ostatní pošty**: bez `headline`/`confidencePct`;
  `emailSubject` u chybové karty vždy, u karty ostatní pošty jen když se
  liší od `title` (D3 výše); `details` jen chybová karta (dva řádky
  z katalogu hlášek, viz výše). Subtitle nedupluje předmět —
  nese odesílatele (`sender_name`, karta ostatní pošty s fallbackem na
  `sender_email`); má-li zpráva partnera (Osoba nebo `partner_name`),
  subtitle je „partner · od: odesílatel" (D7).
- **Návrhová karta s neprázdnými `secondary_findings`** běhu navíc nese
  `secondaryFindings` (viz §4) — hint dalších nálezů, D7.
- **Všechny tři druhy** (návrh vč. nevalidního výstupu, chybová, ostatní
  pošta) při `preprocess_state = 40` navíc nesou `warning` (viz §4) —
  „Předzpracování: {titulek}" z `PreprocessErrorPresenter`
  (`modules/core/mail/docs/preprocess.md` → „Hlášky pro uživatele").
  Dotazy čtou `preprocess_state` vždy a `preprocess_log` jen ve stavu 40
  (`IF(…)` přímo v SELECTu), žádný další dotaz.

**Interní pole `amount`/`currency`** (Issue #32/2, D8): návrhové karty
(docs target) s částkou i měnou v canonical nesou navíc numerické
`amount` (float z `totals.totalAmount`, tatáž hodnota jako
v `headline.amountText` — sdílený `amountValue()`) a `currency`.
Registry ani chybové karty je nemají. Slouží **jen** jako podklad pro
`readySummary` — `DashboardController` je po agregaci ze všech karet
(`FeedResult::$allCards`, bez stropu, #101 D3b) odstraní
(`stripInternalFields()`), do kartového kontraktu (§4) nepatří
a klient je nikdy nevidí.

**Přílohy karet** — všechny druhy mail karet nesou volitelná pole
`attachments`/`attachmentsTotal` (viz §4); zdroj je pro všechny stejný:
**všechny obsahové přílohy zprávy** (D10 — `source_attachments` filtr
zanikl spolu s extracted documents; tím zmizel i ISDOC zobrazovací bug,
kdy PDF sourozenec `.isdoc` přílohy nebyl na kartě vidět).

Obsahové přílohy = `core_attachments_files` s `table_id=303`
(`core_mail_incoming_messages`), bez smazaných a bez raw `.eml`
(`raw_source_attachment`) — stejný výběr jako
`IncomingMessagesViewer::fetchContentAttachments()`. Batch: **jeden** dotaz
na celý collect (`record_id IN` přes deduplikované messageNdx všech karet),
při prázdné množině se nespouští. Strop `MAX_CARD_ATTACHMENTS = 3` dělá
server (menší payload).

### 5.2 AlertsSource

Aktivní alerty (`core_alerts_alerts.alert_state=10`; Snoozed NE). `severity` →
`kind`: error→urgent, warning→review, info→info. `actions[]` alertu se **propíšou
beze změny** (už `open_form`/`open_viewer`, už lokalizované). `title`=titulek
alertu, `subtitle`=zpráva (fallback `check_id`), `timestamp`=`last_seen_at`,
`id`=`"alert:{id}"`. Alert karty jen navigují; snooze/dismiss zůstává ve viewer
detailu. **Sekce feedu** (#101 D5): individuální i skupinové karty nesou
`feedSection=alerts` (Upozornění) bez ohledu na závažnost — uvnitř sekce
je collector řadí dle `kind`, tedy dle závažnosti; setup karta (níže)
patří do Položek k založení (`newItems`). `kind` zůstává dle severity.

**Agregace per check** — víc než 3 aktivní alerty jednoho `check_id`
(`GROUP_THRESHOLD = 3`, tj. 4+) se sbalí do **jedné skupinové karty**, která
individuální karty daného checku plně nahrazuje; 1–3 alerty zůstávají
individuální. Sběr je dvoufázový: agregát `GROUP BY check_id` (bez LIMITu →
pravdivý počet i nad pojistným limitem zdroje), pak individuální řádky jen pro checky pod
prahem. Skupinová karta: `id = "alert-group:{check_id}"`, titulek =
lokalizovaný název checku z `AlertCheckRegistry` (fallback `check_id`, když
check mezitím zmizel z modulu / registr chybí), podtitulek s pravdivým počtem
(„27 upozornění"), `kind` dle **nejvyšší** severity ve skupině (stejné
mapování jako individuální karta — agregace nesnižuje viditelnost),
`timestamp` = `MAX(last_seen_at)`, `context = {checkId, count, severity,
group: true}`. Jediná primary akce `open_viewer` na `core.alerts.alerts`,
zatím bez per-check filtru (preset vieweru později, samostatně); label
lokalizuje zdroj (passthrough cesta jako u individuálních alert akcí).
Kartový kontrakt se nemění — obyčejná karta (title/subtitle fallback, bez
headline), frontend beze změny.

**Agregace podle tagu `setup`** (ds-setup.md D8) — fáze 0 **před** oběma
fázemi výše. Aktivní alerty všech checků s `'setup'` v `tags` (checky
z registry; check může nést i další tagy) se sbalí do **jedné** karty
`id = "alert-group:setup"` — **bez prahu**, od jedné položky, a dotčené
`check_id` se vyřadí z per-check agregace i z individuálních karet
(nikdy setup karta + individuální duplicity). Titulek „Dokončit
nastavení", podtitulek: jedna položka → její `title` (říká konkrétně, co
chybí), víc → počet se správným skloňováním (2–4 položky / 5+ položek).
`kind` dle `MAX(severity)`, `context = {tag: 'setup', count, severity,
group: true}`, jediná primary akce **`open_panel`** s `{panelId:
'dsSetup'}` — frontend přepne do Nastavení
(`navigationStore.navigateToPanel`). Karta se přidává mimo LIMIT fáze 2.
Bez registry (null) se tagová agregace přeskočí — fail-open, alerty
projdou individuálně. Karta čerpá z tabulky alertů (D12), může být až
5 minut za skutečností; panel sám spouští checky naživo.

### 5.3 ContentTagSuggestionsSource

Karta **položky k založení** (tasks/content-tag-ui.md D25; do #101 „Nová
kategorie"): otevřené
dokumentové návrhy (poslední úspěšná analýza, `resolution IS NULL`,
zpráva v docState 10/20) nesou obsahový štítek
(`core_mail_message_analyses.content_tag`), který **nemá živou otagovanou
položku** (`economy_items.content_tags`, stavy 10/40/80; JSON filtr
v PHP). Jedna karta per štítek — agregace `GROUP BY content_tag` dělá
dedupe přes zprávy; query-driven bez dismiss stavu (karta zmizí, jakmile
položka vznikne nebo žádný otevřený návrh štítek nepotřebuje).

`id = "content_tag:{tag}"`, `kind=review` (od Issue #32/2 D12 — plná
karta; původně `info`, ale karta blokuje povýšení návrhů a po založení
položky se přestane objevovat, takže si zaslouží plnou váhu; počítá se
tím i do `summary.counts.review`), `feedSection=newItems` (#101 D2 —
vlastní sekce Položky k založení na začátku feedu, karty odblokují
ostatní práci), `stateStyle=concept`,
`icon=question`, `category=invoices`, titulek = jen `{label}` štítku
(#101 D6; label z cfgItem `core.exchange.contentTags` — lokalizuje server),
podtitulek „{n} dokladů čeká · návrh: {starter} ({účet})",
`context={tag, waiting}`. Akce nesou **lokalizovaný `label` ze serveru**
(passthrough vzor alertů — dynamická čísla účtů):

- štítek s položkou v nabídce aktivní varianty osnovy → jediná primary
  akce „Založit položku" (`materialize_content_tag`, `{tag}`);
- `goods.stock` (bez mapování, D7) → dvě akce „Jako materiál (501…)" /
  „Jako zboží (504…)" s čísly prvních aktivních analytik 501/504
  (`{tag, account}`); bez 501/504 v osnově karta není;
- štítek vědomě bez mapování (admin.other, people.benefits, NPO bez
  protějšku) **nekartuje** — je „review by design", karta by neměla co
  založit.

Po založení se návrhy při dalším otevření povýší na plnou trojici bez
reanalýzy (fresh resolution, D16). Sesterská settings stránka:
Nastavení → Položky → Obsahové štítky (panel `contentTags`).

## 6. Akce a jejich sémantika

### 6.1 `apply_message` — jednoklik „Použít" z karty (pásmo ready)

Karta s návrhem v pásmu **ready** má primární akci **„Použít"** rovnou na
kartě: `applyMessage(messageNdx, null)` — **safe mód** bez userActions
(`targetDocState=10`). Odpověď `422 unresolved_required` → **fall-through**
do `DocumentExchangePreviewModal`, kde uživatel reference dořeší; ostatní
chyby → alert. Post-apply UX sdílí `finishApply` (viz 6.2, kroky 2–4).

### 6.2 Vystavení — „Použít" v review modalu

1. „Zkontrolovat" otevře `DocumentExchangePreviewModal`; „Použít" →
   `applyMessage(messageNdx, userActions)` — nasbíraná rozhodnutí referencí
   → strict mód; bez nich safe mód (`targetDocState=10`).
2. **Úspěch, target docs** → vystavený Koncept se rovnou otevře ve
   `FormDialog` (uživatel ho zkontroluje a může rovnou uzavřít 10→20);
   žádný toast.
3. **Úspěch, target registry** → toast *„Dokument #123 zařazen do Spisovny
   [Otevřít]“*.
4. Karta zmizí optimisticky, refetch. Zpráva přešla na Hotovo, verdikt
   `resolution=40` je zapsaný na analýze.

### 6.3 `review_message` — review modal

Otevře `DocumentExchangePreviewModal` na `messageNdx` (modal si sám stáhne
`GET /_mail/messages/{ndx}/preview`; PDF vlevo, kanonický náhled vpravo,
resolve panel, `Použít` gated `canApply`, panel příloh = všechny obsahové
přílohy zprávy). `onReject(ndx)` → `RejectReasonPrompt`. „Použít“ volá
`onApply(messageNdx, userActions, target)` — target (`docs`/`registry`)
z preview endpointu řídí post-apply UX (§6.2).

**Zdrojová zpráva v hlavičce** (`tasks/mail-source-message-link.md`
D1–D3, D8–D10): `subtitle` modalu je „Došlá zpráva #YYMMDD-NNNN · datum ·
odesílatel“ z bloku `message` preview endpointu (`api-contract.md` §9.12;
krátký kód `IncomingMessageCode::short()`, plný v tooltipu). Prop
`onOpenMessage(messageNdx)`: Dashboard jím otevře svou existující
instanci `ViewerDetailModal` (viewer `core.mail.incoming`, tab `content`
— stejný cíl jako akce `open_detail` mail karet) **nad** review modalem;
`ViewerDetailModal` je v DOM za review modalem, modal stack pošle Esc jen
jemu, zavření vrátí do review s rozhodnutími. `ViewerDetail` (review
z detailu téže zprávy) callback nepředává → kód je prostý text. Snippet
subtitle se předává po celou dobu `open`, obsah až s `data.message`,
aby badge počítadla fronty (`headerExtra`, se subtitle inline na jeho
řádku) neskákal při načítání další zprávy. Tentýž modal zprávy otevírá
i kód zdrojové zprávy na detailu dokladu (`DocumentDetail` → akce
`open_detail` přes `onAction`, obsluha ve `Viewer.svelte`; bez `onAction`
v read-only modalu zůstává přechod do vieweru Došlá pošta). Žádný nový
globální store ani hostitel modalu (D10).

### 6.4 `reject_message` — prompt na důvod

Sdílená komponenta `RejectReasonPrompt` (povinný neprázdný důvod) →
`rejectMessage(messageNdx, reason)`. Používá ji feed i modal i ViewerDetail.
Zapíše `resolution=50` + `rejected_reason`, zpráva → Hotovo.

### 6.5 `unapply` — bez UI, endpoint zachován

`POST /_mail/messages/{ndx}/unapply` (viz §7) nemá UI — zůstává jako
záchranná brzda (MCP / ruční volání). **Známý dluh**: shodí-li uživatel
vystavený Koncept do Koše ručně z formuláře, reverzní reconciliace
(resolution → NULL, zpráva 40→20) neproběhne — dělá ji jen tento endpoint.
Logika (`MessageProposalApplier::unapply`):

1. Poslední úspěšná analýza musí mít `resolution=40` (applied) a zpráva
   `target_row > 0`, jinak `409 INVALID_STATE`.
2. Cílový doklad musí být **stále nedotčený Koncept** (`docState=10`), jinak
   `409 DOC_ADVANCED` (uživatel řeší ručně); registry cíl guard
   `modified <= resolved_at`.
3. Cílová entita → **Koš** (`docState=90`, ne hard-delete — vratné) přes
   Document flow. Koncept nespotřeboval číslo dokladu (přiděluje se až
   10→20), takže není co vracet.
4. Analysis `resolution`/`rejected_reason`/`resolved_at/by` → NULL,
   zpráva `target_table_id`/`target_row` → NULL, `docState 40→20`.

### 6.6 Sériový průchod frontou — „Projít frontu" (Issue #32/1)

Tlačítko **Projít frontu (N)** vedle FeedFilteru (jen záložky Vše/Faktury,
jen když N > 0) otevře review modal na první zprávě fronty a po každém
verdiktu (Vystavit a uzavřít / Vystavit koncept / Zamítnout / Přeskočit)
načte další zprávu místo zavření. Vše frontend nad existujícími endpointy
— backend beze změn. Zadání a rozhodnutí D1–D9:
`tasks/dashboard-queue-walkthrough.md`.

- **Složení fronty (D1)**: jen karty `mail_suggestion:*` s
  `category='invoices'` a `context.target='docs'` (pásma ready + review;
  registry, urgent a info karty vyloučeny). **Snapshot** messageNdx se
  pořizuje při kliknutí — nezávislý na optimistickém mazání karet
  i na kartách přibylých během průchodu. Řazení chronologicky od
  nejstarší (`timestamp` ASC, null na konec) — s „Vystavit a uzavřít"
  drží čísla faktur pořadí doručení.
- **Batch mód potlačuje per-item UX (D2)**: žádný FormDialog po konceptu,
  žádné per-item toasty, žádné `load()` po položce. Stav drží
  `Dashboard.queue = {list, index, counts}`; batch větve ve `finishApply`
  a `submitRejectFlow` jen inkrementují counts a posunou `previewNdx`
  (modal zůstává otevřený, reload řeší jeho `$effect`). Na konci —
  doběhnutí i předčasné zavření modalu — jeden souhrnný toast
  „Uzavřeno X · Y konceptů · Zamítnuto Z · Přeskočeno W" (nulové části
  vynechány, bez akce Otevřít) a jeden `load()`.
- **Modal v batch módu**: props `queue {index, total}` + `onSkip` —
  počítadlo „i / n" přes `headerExtra`, tlačítko Přeskočit v patičce
  (posun bez verdiktu, karta zůstává ve feedu). Zamítnout otevírá
  `RejectReasonPrompt` **nad** modalem (modal stack; zrušení promptu =
  návrat na tutéž zprávu). Chyba apply = alert + zůstat na zprávě (D6).
  Single-message použití (karta „Zkontrolovat", ViewerDetail) je beze
  změny — `queue = null`.
- **Předkrok položek k založení (D8, Issue #35; texty #101 D6/D7)**:
  existují-li ve feedu `content_tag:*` karty, průchodu se předřadí
  `QueueCategoriesPrompt` („Nejdřív založte položky", prázdný stav
  „Všechny položky jsou založené.") — seznam štítků s materialize akcemi
  (labely ze serveru, vč. volby materiál/zboží). Úspěch → řádek zmizí
  + optimistické `dropCardById`
  u rodiče; bez toastů a bez `load()`. „Pokračovat" spustí průchod;
  návrhy povýšené založenou položkou se projeví přirozeně (preview se
  počítá čerstvě). Snapshot fronty se předkrokem nemění.
- **Ready-only průchod (Issue #32/2, D9)**: „Projít" v pruhu přijatých
  faktur (variant `invoices`; pruh Spisovny Projít nemá, D11) volá
  `startQueue('ready')` — snapshot je `queueableCards` navíc filtrované
  `kind === 'ready'`; řazení a chování jinak identické (chronologicky,
  counts, souhrnný toast). Stejný filtr projde i `tagCards` předkroku —
  content_tag karty jsou review, takže předkrok u ready průchodu dostane
  prázdný seznam a přirozeně se neukáže (logika se neobchází). Tlačítko
  „Projít frontu (N)" u filtru zůstává beze změny (ready + review).

## 7. API kontrakt

### `GET /_ui/dashboard`

**Auth**: Bearer token.

```json
{
  "success": true,
  "data": {
    "generatedAt": "2026-06-28T08:42:11+00:00",
    "summary": { "aiText": null, "counts": { "urgent": 7, "review": 59, "ready": 41 } },
    "sections": [
      { "id": "newItems", "total": 2,  "shown": 2 },
      { "id": "ready",    "total": 41, "shown": 30 },
      { "id": "review",   "total": 52, "shown": 30 },
      { "id": "failed",   "total": 7,  "shown": 7 },
      { "id": "alerts",   "total": 5,  "shown": 5 },
      { "id": "other",    "total": 93, "shown": 30 }
    ],
    "cards": [ /* seřazené sekce → kind → čas, strop 30 per sekce; každá nese feedSection */ ],
    "readySummary": {
      "invoices": {
        "count": 38,
        "shown": 27,
        "amounts": [ { "currency": "CZK", "total": 96420.0 }, { "currency": "EUR", "total": 120.0 } ],
        "confidenceMin": 91,
        "confidenceMax": 98
      },
      "registry": { "count": 3, "shown": 3, "amounts": [], "confidenceMin": 90, "confidenceMax": 97 }
    },
    "capabilities": { "mailUpload": true, "chat": true }
  }
}
```

- `summary.aiText` je `null` — generované shrnutí **neblokuje feed**, teče
  samostatným SSE endpointem (níže); `counts` = počet karet dle kind, jen
  actionable pásma (urgent/review/ready), ze **všech** karet bez stropu
  (#101 D3b).
- `sections` (#101 D3a/D3b) — jen neprázdné sekce v pořadí D8, `total` =
  pravdivý počet karet sekce (do pojistného limitu zdrojů), `shown` =
  kolik z nich je v `cards`. Strop `MAX_CARDS_PER_SECTION = 30` platí per
  sekce; karta „…a další nezpracovaná pošta" (`mail_more`) se už neposílá —
  odkaz „a N dalších" kreslí frontend (`N = total − shown`), kontrakt
  nenese akci.
- `cards` — seřazené sekce → kind → čas, po stropu per sekce, každá karta
  s `feedSection` (§4).
- `readySummary` (Issue #32/2, D8 + D11; #101 D3b) — souhrny ready pásma
  pro sbalené pruhy, **per kategorie**: klíče `invoices` (přijaté faktury;
  karty bez kategorie padají sem — defenzivní default shodný s frontendem)
  a `registry` (Spisovna), jen neprázdné skupiny. Počítá se ze **všech**
  ready karet (`count`, `amounts`, jistoty mluví o celé sekci Připraveno),
  `shown` = počet ready karet skupiny v `cards` (frontend z něj odečítá
  optimisticky smazané karty). `amounts` agregované **per měna** — nikdy se nesčítá
  napříč měnami; karta bez částky se do `amounts` nezapočítá, do `count`
  ano (registry karty částky nenesou → jejich `amounts` je vždy `[]`).
  `confidenceMin/Max` z `confidencePct` ready karet skupiny (defenzivně
  `null`, když žádná nemá jistotu). Bez ready karet se pole **vynechá**.
  Podklad jsou interní pole `amount`/`currency` návrhových karet (§5.1),
  která controller před odesláním z karet odstraní — kartový kontrakt (§4)
  se nemění. Frontend částky formátuje lokálně (stejný vzor jako serverový
  `formatAmount()`).
- `capabilities` (task `hosting-07b`, D9): frontend podle nich skrývá
  ovládání funkcí, které na DS neexistují nebo uživateli nepatří.
  `mailUpload` = přítomnost `core_mail_incoming_messages` (tlačítko Nahrát,
  drag&drop, `MailUploadModal`); `chat` = přítomnost
  `core_chat_conversations` **a** (`admin` nebo hosting neaktivní) —
  výraz identický s podmínkou Chat root leafu v `NavigationController`
  (D5 z hosting-07), aby `ChatLauncher` neobcházel skrytý nav leaf.
  Chybějící pole (starší server) frontend čte jako obě `true`.

**`?section=<id>`** (UI shells Fáze 5) — filtr karet na jednu sekci
navigace dle `navSection` karty; používá ho blok karet sekce v prázdné
scoped chat konverzaci (`SectionCards`, viz `docs/chat.md`). Filtruje se
**po** `collect()` nad stropnutými kartami (`FeedResult::$cards`);
`summary`, `sections`, `readySummary` i `capabilities` jsou celofeedové
a při filtru se vynechají — odpověď je jen `{generatedAt, cards}`.
Filtruje `navSection`, ne `feedSection`. Nevalidní hodnota →
prázdný seznam, ne chyba.

### `GET /_ui/dashboard/summary` (SSE)

**Auth**: Bearer token. **Content-Type**: `text/event-stream`.

Generované AI shrnutí feedu (fáze 2b, §11). Události:

| Událost | Payload | Kdy |
|---|---|---|
| `text` | `{ "delta": "…" }` | inkrementální text — jen při cache miss (LLM streamuje) |
| `done` | `{ "text": "…"\|null, "cached": bool }` | vždy poslední; `text=null` = prázdný feed / degradace |
| `error` | `{ "message": "…" }` | LLM/transport chyba → frontend tiše degraduje |

- **Prázdný feed** (žádné actionable karty) → rovnou `done{text:null}`, žádné LLM.
- **Cache hit** → `done{text, cached:true}` okamžitě, žádné LLM.
- **Cache miss** → stream `text` delt → `done{text, cached:false}` + upsert cache.
- **Backend chybí / bez API klíče / klíč nejde dešifrovat** → `done{text:null}`
  (tichá degradace, log server-side).

### `GET /_ui/section-badges`

**Auth**: Bearer token (stejný režim jako `/_ui/dashboard`).

Badge stavů sekcí navigace (UI shells Fáze 3) — stejný sběr karet jako
dashboard, jiná prezentace: agregace per `navSection`
(`FeedCollector::sectionBadges()`). Detailně `docs/rest-api.md`.

```json
{ "success": true, "data": { "sections": {
  "accounting": { "count": 3, "severity": "danger" },
  "_top":       { "count": 1, "severity": "warning" }
} } }
```

- Počítají se jen karty `urgent` (severity `danger`) a `review`
  (`warning`) s neprázdným `navSection`; sekce = součet + max severity
  (danger > warning). `ready`/`info` se nepočítají (D2 — trvale svítící
  badge není signál). Agregace běží nad **všemi** kartami feedu
  (`FeedResult::$allCards`), ne jen pod stropem (#101).
- Jen neprázdné sekce; `_top` je platný klíč (sidebar pilot ho
  nerenderuje, D6). Prázdný feed → `{}`.

### `POST /_mail/messages/{ndx}/unapply`

**Auth**: běžný uživatelský token. Transakčně vrátí apply — viz §6.5
a `docs/mail/api-contract.md` §9.11.

**Odpovědi**: `200 { messageNdx, analysisNdx, trashedDocId }`,
`409 INVALID_STATE` / `409 DOC_ADVANCED`, `404 NOT_FOUND`, `500 INTERNAL_ERROR`.

### `POST /api/v1/_exchange/content-tags/materialize`

**Auth**: běžný uživatelský token. Body `{tag, account?}` — založí účetní
položku pro obsahový štítek (karta položky k založení, settings stránka);
sdílená služba `AccountingItemMaterializer` (extrakce generátoru ze
`SetupController`), zápis přes `TableGateway` (ItemDocument validace).
Sesterské endpointy pro settings panel: `GET …/content-tags/overview`
(stav mapování + reverzní návrhy), `POST …/content-tags/tag-items`
(bulk otagování). Dispatcher `contentTags` → `ContentTagsController`.

**Odpovědi**: `200 { itemId, code, name }`, `409 ALREADY_MAPPED` /
`OFFER_UNAVAILABLE` / `ITEM_KIND_MISSING` / `UNIT_MISSING` /
`CODE_COLLISION`, `422 UNKNOWN_TAG` / `ACCOUNT_REQUIRED` /
`ACCOUNT_NOT_FOUND`, `500 SAVE_FAILED`.

## 8. Frontend komponenty

```
frontend/src/components/dashboard/
├── Dashboard.svelte      — fetch, layout, review modal,
│                           reject prompt, form po vystavení,
│                           toast (registry / auto-archiv),
│                           stav sériového průchodu frontou (§6.6)
├── Feed.svelte           — sekce podle toku práce (#101): seskupení karet dle
│                           card.feedSection (chybějící/neznámá → fallback
│                           z kind jako server, jinak other), render v pořadí
│                           D8; hlavička = barevná tečka + název + počet
│                           (`total − (shown − present)` z prop `sections`,
│                           bez ní počet viditelných karet — filtr / starší
│                           server); pod sekcí „a N dalších" (N = total −
│                           shown) jako syntetická open_viewer akce
│                           (mail viewer; alerts → viewer upozornění;
│                           newItems jen text); sekce s nulou doručených
│                           karet a zbytkem na serveru se dál renderuje;
│                           rozvržení per sekce: failed full-width stack,
│                           newItems/review/alerts grid (auto-fill
│                           minmax(360px,1fr) → 2 sloupce na desktopu,
│                           1 na mobilu; row-major = serverové řazení;
│                           stejná výška karet v řádku, žádný masonry),
│                           ready → FeedReadySection, other → FeedRowCompact;
│                           prázdný stav jen bez jediné sekce (prop
│                           emptyText → per-záložkový empty)
├── FeedReadySection.svelte — jeden pruh ready pásma (D3/D4/D6 + D11);
│                           Feed renderuje až dva (per kategorie): variant
│                           invoices = sbalený souhrnný pruh (počet
│                           `summary.count − (summary.shown − cards.length)`,
│                           bez summary.shown cards.length — #101 D3b;
│                           součty per měna + rozsah jistoty
│                           ze serverového readySummary.invoices, akce
│                           Projít → onWalkthrough a Zobrazit ▾ → toggle,
│                           rozbalené = orámovaný blok FeedRowCompact řádků
│                           s patičkou „Projít frontu"); variant registry =
│                           vlastní titulek, bez součtů a bez Projít
│                           (průchod Spisovnou zatím není); stav rozbalení
│                           lokální $state, default sbaleno, nepersistuje
├── FeedRowCompact.svelte — kompaktní jednořádková položka (mode ready/info):
│                           ready = donut jistoty, partner tučně, typ · datum,
│                           částka, Použít (apply_message) + oko
│                           (review_message); info = tlumený řádek
│                           title/subtitle, akce z card.actions (heterogenní
│                           — jen deleguje onAction, žádná vlastní logika);
│                           busy disabluje všechna tlačítka; card.warning
│                           jen jako ikona varování s tooltipem (oba módy)
├── FeedFilter.svelte     — chip bar filtru kategorií (Vše/Faktury/Spisovna/
│                           Ostatní), počet uvnitř chipu bez závorek; čistě
│                           prezentační, counts/urgent/filtered počítá
│                           Dashboard ($derived z doručených karet),
│                           přepínání bez refetche, volba nepřežije reload
├── FeedCard.svelte       — jedna karta: stavový proužek nahoře, sémantická
│                           ikona, strukturovaná hlavička (headline: partner
│                           tučně / typ dokladu / částka velkým) + donut
│                           jistoty (confidencePct, barva dle kind), předmět
│                           e-mailu (emailSubject + iconMail), chipy příloh,
│                           hint řádek dalších nálezů (secondaryFindings),
│                           řádek upozornění (card.warning, varovná barva),
│                           expander „Zobrazit detail" (details, lokální
│                           $state), akce; bez headline fallback title/subtitle
├── FeedCardAttachment.svelte — chip přílohy s ikonou typu (PDF/obrázek/soubor,
│                           bez mini náhledu): klik otevře v nové záložce
│                           (PDF/obrázky inline, jinak download), hover
│                           náhled (jen hover zařízení); „+N" nad strop 3
│                           je syntetická open_detail akce (náhled zprávy)
├── RejectReasonPrompt.svelte — sdílený prompt na důvod (feed i ViewerDetail)
├── QueueCategoriesPrompt.svelte — předkrok průchodu frontou (§6.6):
│                           založení otagovaných položek z content_tag
│                           karet před otevřením první zprávy
├── AiSummaryCard.svelte  — AI shrnutí přes SSE (fallback county dle kind, §11)
└── ChatLauncher.svelte   — plovoucí chat input (pill, sticky dole na středu,
                            width min(560px,100%)): odeslání →
                            chatStore.newConversation() + chatPanelStore.open()
                            + chatStore.send(); skrytý, když je panel otevřený
```

API: `frontend/src/api/dashboard.js` (`fetchDashboard()`,
`setMessageDocState()`, `streamDashboardSummary()` — SSE konzument dle
vzoru `chat.js`), `api/exchange.js` (`previewMessage`, `applyMessage`,
`rejectMessage`, `unapplyMessage`, `reanalyzeMessage` — wrappery
message-centrických `/_mail/messages/{ndx}/*` endpointů),
`api/contentTags.js` (`materializeContentTag`, `fetchContentTagsOverview`,
`tagContentItems` — `/_exchange/content-tags/*`).

- **Doc-state proužek**: globální `.docState_*` třídy (`styles/base.css`), pruh
  přes `--shpd-row-bar`. Kind→stateStyle mapuje server. Na kartě feedu je pruh
  **nahoře** (jen dashboard — geometrie v scoped CSS FeedCard, viewery beze
  změny; návrat vlevo = jen jiná geometrie, `--shpd-row-bar` zůstává). Ready
  karty (`stateStyle=done`, globálně záměrně bez pruhu) mají dashboardový
  override `.shpd-feed-card.docState_done → --shpd-row-bar: success` v scoped
  CSS FeedCard — globální `.docState_done` se nemění.
- **Toast**: app nemá toast infrastrukturu → minimální lokální toast v
  `Dashboard.svelte` (fixed dole, „Otevřít“ u registry, auto-dismiss ~8 s).
  Bottom offset `calc(var(--shpd-space-lg) + 72px)` — vyskakuje nad
  ChatLauncherem, nepřekrývají se.
- **Boční AI chat panel**: `ChatPanel.svelte` (components/chat/) mountovaný
  v **AppShellu** (ne v Dashboardu — přežije navigaci), otevíraný z
  ChatLauncheru přes mini store `stores/chatPanel.svelte.js`. Non-modální
  overlay zprava `width: min(480px, 90vw)`, z-index 80 (pod drawerem
  90/100, ThemePanelem 200, Modalem/FormDialogem 1000); mobil fullscreen
  pod top barem. Obsah = sdílený `chatStore` + `<ChatThread />` — detaily
  `docs/chat.md` §7.
- **Ikony**: server posílá sémantický `icon` (check/question/warning/info/…),
  frontend překládá přes `resolveIcon()` (`icons.js`, fallback `iconTable`).

## 9. Form modal nad dashboardem

`Dashboard.svelte` drží `formModal = {open, table, recordId, wasSaved}` a
mountuje `<FormDialog>` — obsluhuje `open_form` akce karet (alerty…),
vystavenou fakturu po apply z review modalu (rovnou, bez toastu) a toast
„Otevřít“ u Spisovny (`base_registry_documents`). Refetch po close je
podmíněný (`wasSaved` se nastaví jen v `onSaved`):

| Scénář | Refetch? |
|---|---|
| Otevření záznamu → close bez editace | Ne |
| Edit → **Uložit** → close | Ano |
| **Hotovo** ve FormStateBar (closeForm: 1) | Ano |
| Edit + Esc/× → confirm OK | Ne |

**Read-only detail modal** (`open_detail`, Issue #30): `Dashboard.svelte`
drží `detailModal = {open, viewerId, recordId, tabId}` a mountuje
`<ViewerDetailModal>` (`components/viewer/`) — třetí hostitel
`ViewerDetail` (vedle inline panelu a draweru). Fetchuje
`GET /_ui/viewer/{viewerId}/detail/{id}`, `toolbar` z odpovědi ignoruje
celý, `onAction`/`onRefresh` nepředává a `tabId` ořeže detail na jediný
tab (mail „Otevřít e-mail" → tab `content`; hlavička s předmětem,
odesílatelem a badges zůstává). Tab lišta se skrývá přes opt-in prop
`ViewerDetail.hideSingleTabBar`. Zavření **nevolá** `load()` — čtení
feed nemění. Stejný modal otevírá i chip „+N" příloh (`FeedCard.svelte`).

## 10. Empty stavy a refresh

| Stav | Text | i18n |
|---|---|---|
| žádná sekce k zobrazení (žádná doručená karta ani zbytek nad stropem) | „Vše zpracováno ✓ — dnes nic nečeká." (bez chip baru) | `dashboard.feed.empty` |
| prázdná záložka filtru (feed neprázdný) | „V této kategorii nic nečeká." | `dashboard.feed.emptyCategory` |

**Refresh** — fetch při mountu + manuální tlačítko. Žádný polling / SSE.

## 11. AI shrnutí (fáze 2b)

Nad feedem se zobrazuje **generované shrnutí dne** — krátká próza (2–4 věty)
o tom, co je nejnaléhavější, co čeká na kontrolu a co je připravené. První
viditelný generativní AI prvek na home obrazovce.

**Backend** — `DashboardSummaryService` (`src/Core/Dashboard/`):

- **Vstup (digest)**: county dle kind + top ~6 karet
  (kind, id, titulek, subtitle) + dnešní datum (`Y-m-d`) + jazyk. Nikdy plný
  `canonical_json` (D13). Digest je kanonický — tentýž slouží pro hash i prompt.
- **Cache** (D12): `core_ai_dashboard_summary` (modul `core.ai`), jeden řádek
  per jazyk `{language UNIQUE, input_hash, text, input_tokens, output_tokens,
  generated_at}`. Klíč = `sha256(digest)`; **datum v digestu** realizuje
  „regeneruj aspoň jednou denně" bez TTL časovače — přes půlnoc nový hash,
  v rámci dne regenerace jen při změně feedu. Usage (tokeny) se ukládá pro
  budoucí telemetrii.
- **LLM cesta**: default backend přes `AiBackendResolver` (`src/Core/Ai/` —
  extrakce chat vzoru: default aktivní řádek `core_ai_backends` + dešifrování
  klíče `DsSecretCipher`), jedno streamované `LlmClient::streamChat` volání,
  `maxTokens ~300`, `temperature=null`, `tools=null` (D15).
- `DashboardController::summary()` sdílí `FeedCollector::collect()`
  s `dashboard()` a digest dostává **všechny** karty (`allCards`, #101):
  county jsou pravdivé nad stropem, top karty jdou z čela seznamu v pořadí
  sekcí (Položky k založení, Připraveno, …); SSE vzor z `ChatController`.

**Frontend** — `AiSummaryCard.svelte` po každém načtení dashboardu (mount i
refresh — hit/miss rozhodne server) otevře `streamDashboardSummary()`; text
dotéká do karty, během streamu běží nenápadný indikátor „Generuji shrnutí…"
(`dashboard.aiSummary.generating`). Prázdné/`null` shrnutí nebo chyba → statický
text z countů (2a), unmount/refresh zavře stream (`handle.close()`).

**Rozhodnutí**: shrnutí je **per-DS + per-jazyk** (feed není per-user); prompt
je pevný; levnější model override odložen (D15); žádný polling — jen mount +
manuální refresh. **Soukromí**: digest obsahuje partnery/částky — stejná data,
jaká analyzer LLM už posílá (viz `ai.md`).

## 12. Budoucí rozšíření

- **Module-driven feed zdroje** přes `module.jsonc` (zatím napevno, D10).
- **Tasks jako plnohodnotný zdroj karet** — až přibude assignee.
- **Denormalizace headline** (partner/částka do sloupců místo parse
  `canonical_json`).
- **Auto-refresh** — polling / SSE, pokud bude potřeba (samostatný task).
- **Serverový filtr kategorií** — `?category=` parametr + pravdivé DB totály
  v chipech (dnes klientský filtr nad doručenými kartami, počty chipů =
  doručené karty; strop 30 platí per sekce feedu, #101 — chipy se
  z `sections` nedopočítávají). Aditivní krok, kontrakt se nemění — až
  strop začne vadit.
- **Texty feed zdrojů do cfgItem** (#101 D11) — všechny zdroje dnes skládají
  titulky `$cs ? … : …` v PHP a cfgItemy neumí plurál; samostatný task
  napříč zdroji.

---

[← README.md](../README.md) · [Frontend](frontend.md) · [Alerts](alerts.md) · [Exchange formát](exchange-format.md)
