# Dashboard — sekce feedu podle toku práce, strop per sekce

**Stav:** hotovo — implementováno 2026-10-06 (#101); ruční proklik na dev DS viz checklist

## Cíl

Feed dashboardu se dnes dělí do čtyř sekcí podle pásma karty (`kind`):
Vyžaduje pozornost, Ke kontrole, Připraveno, Ostatní. Ke kontrole míchá
návrhy dokladů, karty „Nová kategorie“, varovné alerty i kartu „Dokončit
nastavení“; pořadí neodpovídá toku práce; strop 30 karet platí na celý feed
a zdroje ho dostávají jako `LIMIT` do SQL (návrhy ready + review chodí jedním
dotazem, takže dohromady nikdy nepřesáhnou 30).

Po tomto tasku má feed šest sekcí v pořadí toku práce, sekci karty určuje
server (`feedSection`), strop je 30 **per sekce** a každá sekce zná pravdivý
počet karet.

```
Položky k založení (2)        ← content tag karty + Dokončit nastavení
Připraveno (41)               ← pruhy Přijaté faktury / Spisovna, součty ze všech 41
Ke kontrole (52)              ← 30 karet + „a 22 dalších“
Nepodařilo se zpracovat (7)
Upozornění (5)                ← alerty, řazené dle závažnosti
Ostatní (93)                  ← 30 řádků + „a 63 dalších“
```

## Před implementací přečti

- `docs/dashboard.md` — celý; hlavně §3 (FeedCollector), §4 (kartový
  kontrakt, `category` vs `navSection`), §5 (zdroje), §6.6 (průchod frontou),
  §7 (API, `readySummary`), §8 (komponenty)
- `docs/alerts.md` — skupinové a setup karty ve feedu
- `docs/help-authoring.md` — před úpravou stránek v `help/`
- Issue #101 — rozhodnutí D1–D11

## Rozhodnutí k designu (potvrzená v #101)

- ✓ **D1 — Pořadí sekcí podle toku práce** (výsledné pořadí v D8).
- ✓ **D2 — Karty položek mají vlastní sekci**; `kind` karty se nemění.
- ✓ **D2b — Pole `feedSection` nese každá karta a nastavuje ho server.**
  Výchozí hodnota z `kind`, zdroj ji smí přepsat. Frontend seskupuje jen
  podle něj.
- ✓ **D3a — Strop 30 per sekce.** Zdroje dostanou místo stropu feedu
  pojistný limit, ořez per sekce dělá `FeedCollector`.
- ✓ **D3b — Pravdivé počty per sekce.** Hlavička sekce = skutečný počet,
  pod sekcí „a N dalších“. Karta „…a další nezpracovaná pošta“ zaniká.
  `readySummary` se počítá ze všech ready karet, ne jen z karet pod stropem.
- ✓ **D4 — Sekce selhání** nese jen selhané zpracování pošty, název
  „Nepodařilo se zpracovat“.
- ✓ **D5 — Sekce „Upozornění“** pro všechny alerty bez ohledu na závažnost
  (uvnitř řazení dle závažnosti); setup karta „Dokončit nastavení“ patří
  do Položek k založení.
- ✓ **D6 — Texty o položkách:** sekce „Položky k založení“, titulek karty
  jen label štítku, předkrok průchodu „Nejdřív založte položky“ /
  „Všechny položky jsou založené.“
- ✓ **D7 — Předkrok položek v „Projít frontu“ zůstává**, mění se jen texty.
- ✓ **D8 — Pořadí:** Položky k založení → Připraveno → Ke kontrole →
  Nepodařilo se zpracovat → Upozornění → Ostatní.
- ✓ **D9 — nahrazeno D11.**
- ✓ **D10 — Karta návrhu pravidla odesílatele** („Vždy archivovat poštu
  od …?“, `MailDigestSource`, `kind=review`) zůstává v **Ke kontrole**
  (výchozí mapování z `kind`). Digest auto-archivu (`info`) → Ostatní.
  Doplněno v #101 dodatečně.
- ✓ **D11 — Texty feed zdrojů do cfgItem samostatným taskem** napříč všemi
  zdroji (dnes všechny používají `$cs ? … : …` v PHP a cfgItemy neumí
  plurál). Tady se v `ContentTagSuggestionsSource` jen odebere prefix
  titulku. Nahrazuje D9; navazující task až po dokončení tohoto.

## Scope

**V rozsahu:** `feedSection` na kartách, `FeedResult`, strop a počty per
sekce, `sections` v odpovědi `GET /_ui/dashboard`, `readySummary` ze všech
karet, zánik karty „a další“, MCP `feed_cards`, `Feed.svelte` a pruh
Připraveno, i18n, texty karty položek a předkroku, dokumentace a nápověda.

**Mimo rozsah:** serverový filtr kategorií (`?category=`), změny závažností
alert checků, texty feed zdrojů do cfgItem (D11), změny `kind`, parametr `?section=` (navSection). Badge sekcí navigace
se mění jen v tom, že počítají ze všech karet.

## 1. Backend — sekce a strop (D2b, D3a, D3b, D8)

### 1.1 Identifikátory sekcí — `src/Core/Feed/FeedSource.php`

```php
/** Sekce feedu (docs/dashboard.md §4, #101 D8). Pořadí řídí FeedCollector::SECTION_ORDER. */
public const string SECTION_NEW_ITEMS = 'newItems';
public const string SECTION_READY     = 'ready';
public const string SECTION_REVIEW    = 'review';
public const string SECTION_FAILED    = 'failed';
public const string SECTION_ALERTS    = 'alerts';
public const string SECTION_OTHER     = 'other';
```

Doplnit docblock rozhraní: `feedSection` je volitelné pole karty; bez něj
(nebo s neznámou hodnotou) ho collector odvodí z `kind`.

### 1.2 `FeedContext` — pojistný limit místo stropu

`maxCards` → **`sourceLimit`** (přejmenovat, ne přidat — staré jméno by
svádělo k použití jako strop). Docblock: pojistka proti neomezenému SELECTu,
ne strop feedu; strop dělá collector per sekce.

### 1.3 `FeedResult` — nový readonly value object v `src/Core/Feed/`

```php
final readonly class FeedResult
{
    /**
     * @param list<array<string,mixed>> $cards     seřazené, strop per sekce, s interními poli
     * @param list<array<string,mixed>> $allCards  seřazené, bez stropu, s interními poli
     * @param list<array{id:string, total:int, shown:int}> $sections
     *        jen neprázdné sekce, v pořadí SECTION_ORDER
     */
    public function __construct(
        public array $cards,
        public array $allCards,
        public array $sections,
    ) {}

    public function hasMore(): bool { /* některá sekce total > shown */ }
}
```

### 1.4 `FeedCollector`

- Konstanty: `MAX_CARDS_PER_SECTION = 30`, `SOURCE_LIMIT = 500`;
  `MAX_CARDS` zaniká (jediný externí uživatel je `FeedCardsTool`, krok 1.7).
- `SECTION_ORDER` = D8; `DEFAULT_SECTION_BY_KIND` = `urgent → failed`,
  `review → review`, `ready → ready`, `info → other`.
- `collect()` vrací `FeedResult`. Postup:
  1. sběr ze zdrojů s `new FeedContext(..., self::SOURCE_LIMIT)`;
  2. **normalizace sekce**: chybí-li `feedSection` nebo není v
     `SECTION_ORDER`, nastaví se z `DEFAULT_SECTION_BY_KIND` (neznámý `kind`
     → `other`, stejná defenziva jako dnes ve frontendu);
  3. **řazení**: sekce dle `SECTION_ORDER`, uvnitř `KIND_ORDER`
     (u Upozornění = závažnost, D5), pak `timestamp` DESC — dnešní
     komparátor jen rozšířený o první klíč;
  4. **strop per sekce** `MAX_CARDS_PER_SECTION`, počty `total` / `shown`.
- `sortAndCap()` přepsat na per-sekční variantu (veřejná kvůli testům);
  `sectionBadges()` a `countByKind()` beze změny — volající jim předají
  `allCards`.

### 1.5 Zdroje

- **`ContentTagSuggestionsSource`**: `feedSection = SECTION_NEW_ITEMS`;
  titulek = jen `$label` (D6); `$ctx->maxCards` → `$ctx->sourceLimit`.
- **`AlertsSource`**: setup karta (`alert-group:setup`) →
  `SECTION_NEW_ITEMS`; všechny ostatní alert karty (individuální
  i skupinové per check) → `SECTION_ALERTS`; LIMIT fáze 2 přes
  `sourceLimit`.
- **`MailSuggestionsSource`**: chybové karty (`buildErrorCard` — včetně
  degradované `kind=review` při `primary_type='other'`) a karta nevalidního
  výstupu (`buildInvalidOutputCard`) → explicitně `SECTION_FAILED`.
  Návrhové a „Není faktura“ karty bez pole (výchozí mapování).
  Všechny tři dotazy `$ctx->sourceLimit`.
- **`MailDigestSource`**: bez `feedSection` (D10 — výchozí mapování);
  LIMIT návrhů pravidel přes `sourceLimit`.

### 1.6 `DashboardController`

- `dashboard()`:
  - `cards` = `$result->cards` (strip interních polí), **bez** `andMoreCard()`
    — metodu smazat;
  - nové pole **`sections`** = `$result->sections`;
  - `summary.counts` = `countByKind($result->allCards)` (pravdivé);
  - `readySummary` = `buildReadySummary($result->allCards, $result->cards)` —
    `count`, `amounts`, `confidenceMin/Max` ze **všech** ready karet skupiny,
    nové pole **`shown`** = počet ready karet skupiny v `cards`;
  - `?section=` (navSection, UI shells Fáze 5) filtruje dál `$result->cards`,
    tvar odpovědi beze změny.
- `summary()` předá službě `$result->allCards` (pravdivé county v digestu;
  top karty jsou díky řazení dle sekcí).
- `sectionBadges()` počítá z `$result->allCards`.

### 1.7 MCP `FeedCardsTool`

Karty z `$result->cards`; položka navíc `feedSection`; `pagination.limit`
= `MAX_CARDS_PER_SECTION`, `has_more` = `$result->hasMore()`. Text
`summary` doplnit, že výpis je zkrácený, když `hasMore()`.

## 2. API kontrakt

`GET /_ui/dashboard` — nové a změněné části:

```json
{
  "summary": { "aiText": null, "counts": { "urgent": 7, "review": 59, "ready": 41 } },
  "sections": [
    { "id": "newItems", "total": 2,  "shown": 2 },
    { "id": "ready",    "total": 41, "shown": 30 },
    { "id": "review",   "total": 52, "shown": 30 },
    { "id": "failed",   "total": 7,  "shown": 7 },
    { "id": "alerts",   "total": 5,  "shown": 5 },
    { "id": "other",    "total": 93, "shown": 30 }
  ],
  "cards": [ { "id": "content_tag:fuel", "kind": "review", "feedSection": "newItems", "…": "…" } ],
  "readySummary": {
    "invoices": { "count": 38, "shown": 27, "amounts": [ … ], "confidenceMin": 90, "confidenceMax": 99 },
    "registry": { "count": 3,  "shown": 3,  "amounts": [], "confidenceMin": 91, "confidenceMax": 97 }
  }
}
```

- `sections` jen neprázdné, v pořadí D8. Karta „…a další nezpracovaná
  pošta“ (`id: mail_more`) se už neposílá.
- Odkaz „a N dalších“ řeší frontend (krok 3.2) — kontrakt nenese akci.

## 3. Frontend

### 3.1 `Feed.svelte`

- Nové props `sections` (z odpovědi). Fallback pro starší server: chybí-li,
  odvodit sekce z karet (`feedSection` → jinak `kind` dle stejného mapování
  jako server, `total = shown =` počet karet).
- Seskupení karet dle `card.feedSection` (neznámá / chybějící → `other`),
  render v pořadí `sections`. Rozvržení podle **sekce**, ne podle `kind`:

  | Sekce | Rozvržení |
  |---|---|
  | `newItems` | grid `FeedCard` (dnešní review grid) |
  | `ready` | `FeedReadySection` — pruhy invoices / registry jako dnes |
  | `review` | grid `FeedCard` |
  | `failed` | full-width stack `FeedCard` (dnešní urgent) |
  | `alerts` | grid `FeedCard` |
  | `other` | `FeedRowCompact mode="info"` |

- Barevná tečka hlavičky per sekce: `failed` danger, `review` a `alerts`
  warning, `ready` success, `other` text-secondary, `newItems` — ověř
  v `styles/` dostupný akcentní token (primary/accent), jinak warning.
- **Počet v hlavičce** v záložce Vše = `total − (shown − present)`, kde
  `present` = počet karet sekce právě ve feedu (optimistické mazání
  `dropCardById` / průchod frontou počet sníží bez refetche). V záložce
  filtru = počet viditelných karet sekce (sections jsou celofeedové).
- **„a N dalších“** pod sekcí jen v záložce Vše, když `total > shown`;
  `N = total − shown`. Odkaz `open_viewer` přes stávající `onCardAction`
  cestu (syntetická akce, vzor chipu „+N“ příloh):
  `ready` / `review` / `failed` / `other` → `core.mail.incoming`,
  `alerts` → `core.alerts.alerts`, `newItems` bez odkazu (jen text).

### 3.2 `FeedReadySection.svelte`

Počet v titulku pruhu = `summary.count − (summary.shown − cards.length)`
(dnes `cards.length`) — titulek a součty tak mluví o téže množině.
Bez `summary` fallback na `cards.length`. Rozbalený seznam ukazuje
doručené karty; „a N dalších“ řeší sekce (3.1), pruh ne.

### 3.3 `Dashboard.svelte`

Předat `sections={data.sections}` do `Feed`. `queueableCards`, filtr
a průchod frontou beze změny — pracují s doručenými kartami.

### 3.4 i18n (`cs.js`, `en.js`)

Klíče `dashboard.feed.section.{urgent,review,ready,info}` nahradit
(nejdřív grep jiných použití):

| Klíč | cs | en |
|---|---|---|
| `dashboard.feed.section.newItems` | Položky k založení | Items to create |
| `dashboard.feed.section.ready` | Připraveno | Ready |
| `dashboard.feed.section.review` | Ke kontrole | To review |
| `dashboard.feed.section.failed` | Nepodařilo se zpracovat | Could not be processed |
| `dashboard.feed.section.alerts` | Upozornění | Alerts |
| `dashboard.feed.section.other` | Ostatní | Other |
| `dashboard.feed.sectionMore` | `{n, plural, one {a # další} few {a # další} many {a # dalších} other {a # dalších}}` | `{n, plural, one {and # more} other {and # more}}` |
| `dashboard.queue.precheckTitle` | Nejdřív založte položky | Create items first |
| `dashboard.queue.precheckEmpty` | Všechny položky jsou založené. | All items have been created. |

`npm run check:i18n` musí projít.

## 4. Dokumentace a nápověda

- `docs/dashboard.md`: náčrt §1, princip sekcí §2 (sekce už nejsou čistě
  prezentační — určuje je server), §3 (`FeedResult`, strop per sekce,
  pojistný limit), §4 pole `feedSection` (vztah ke `category`
  a `navSection` — tři ortogonální pole) + tabulka §4.1 doplnit o sekci,
  §5.2 / §5.3 sekce karet, §6.6 texty předkroku, §7 `sections`,
  `readySummary.shown`, zánik karty „a další“, county a badge ze všech
  karet, §8 `Feed.svelte` / `FeedReadySection`, §12 projít.
- `CLAUDE.md` → *Frontend — Dashboard*: věta o `sortAndCap` / `MAX_CARDS ~30`
  + „a další…“ karta.
- grep `MAX_CARDS`, `a další`, `Vyžaduje pozornost` v `docs/` (např.
  `docs/rest-api.md`, `docs/mcp-server.md`, `docs/chat.md`) a opravit.
- `help/posta/prijem-posty.md`, `help/posta/kontrola-vytezeni.md`:
  sekce a jejich pořadí, „a N dalších“.
- `help/polozky/obsahove-stitky.md`: karta je v sekci Položky k založení,
  titulek = název štítku, předkrok průchodu; přejmenovat titulek stránky
  i `keywords` z „karta Nová kategorie“. Názvy ověř v `cs.js` (zdroj
  pravdy), pak `python3 scripts/help-index.py`.

## Testy

PHPUnit (rozšířit stávající):

- `FeedCollectorTest`: výchozí sekce z `kind`; explicitní `feedSection`
  přebije výchozí; neznámá hodnota → výchozí; řazení sekce → kind → čas;
  strop 30 per sekce (31 karet jedné sekce → `shown` 30, `total` 31,
  ostatní sekce nedotčené); `sections` jen neprázdné a v pořadí D8;
  `allCards` bez stropu.
- `DashboardControllerTest`: `sections` v odpovědi; žádná karta `mail_more`;
  `readySummary` ze všech ready karet + `shown`; `summary.counts`
  pravdivé nad stropem; `?section=` tvar beze změny.
- `ContentTagSuggestionsSourceTest`: `feedSection=newItems`, titulek bez
  prefixu.
- `AlertsSourceTest`: setup karta → `newItems`, individuální i skupinová
  karta → `alerts`.
- `MailSuggestionsSourceTest`: chybová karta (urgent i degradovaná
  review) a nevalidní výstup → `failed`; návrh bez pole.
- `MailDigestSourceTest`, `FeedCardsToolTest`: `sourceLimit`, `has_more`,
  `feedSection` v položkách.

Frontend: `npm run build`, `npm run check:i18n`, ruční proklik (níže).

## Task breakdown

### Commit 1 — Backend: sekce, strop a počty per sekce
Kroky 1.1–1.7 + PHPUnit. `feat(dashboard): sekce feedu a strop per sekce (#101)`

### Commit 2 — Frontend: sekce feedu
Kroky 3.1–3.4. `feat(dashboard): feed podle sekcí toku práce (#101)`

### Commit 3 — Dokumentace, nápověda, stav tasku
Krok 4, `**Stav:**`, `python3 scripts/tasks-index.py`,
`python3 scripts/help-index.py`. `docs(dashboard): sekce feedu (#101)`

## Pasti

- **`kind` se nemění nikde.** Barvy proužku karet, `summary.counts`,
  badge sekcí navigace i AI shrnutí na něm stojí. Sekci nese jen
  `feedSection`.
- **Tři ortogonální pole:** `category` (chipy filtru), `navSection`
  (badge navigace, `?section=`), `feedSection` (sekce feedu). Parametr
  `?section=` filtruje **navSection** — nepřejmenovávat ani nemíchat.
- **Pojistný limit 500** — `MailSuggestionsSource` dekóduje
  `canonical_json` každého řádku a dashboard i badge (polling 60 s) volají
  sběr celý. Na reálných datech je otevřených návrhů řádově desítky,
  `canonical_json` v průměru ~2 kB — únosné. Počet je pravdivý jen do
  limitu; nezvyšovat bez měření.
- **Návrhy ready + review chodí jedním dotazem** a pásmo se počítá až
  v PHP — limit dotazu proto nesmí být strop sekce.
- **Optimistické mazání karet** — hlavička nesmí ukazovat serverový
  `total` bez odečtu smazaných (vzorec v 3.1), jinak počet po akci
  „nesedí“ až do refetche. Průchod frontou maže bez `load()`.
- **Průchod frontou** bere jen doručené karty (≤ 30 ready + ≤ 30 review);
  po jeho konci `load()` dotáhne další. Neměnit.
- **Chipy filtru** počítají z doručených karet (dnešní stav, §12
  dashboard.md) — nesnažit se je dopočítávat z `sections`.
- **AI shrnutí**: digest se počítá z `allCards` → změní se hash a cache se
  jednou přegeneruje. Očekávané.
- JS jen s ASCII uvozovkami; české `„…“` jen v textech i18n.
- Testovací data v testech syntetická — žádné reálné názvy ani částky.

## Hotovo když

- [x] Každá karta v odpovědi nese `feedSection`; `sections` v pořadí D8
      jen s neprázdnými sekcemi.
- [x] Strop 30 platí per sekce; hlavička ukazuje pravdivý počet,
      pod sekcí „a N dalších“ s odkazem dle 3.1.
- [x] Karta „…a další nezpracovaná pošta“ zmizela.
- [x] Pruh Připraveno ukazuje počet i součty za všechny připravené doklady.
- [x] Karty položek a „Dokončit nastavení“ jsou nahoře v Položkách
      k založení; titulek karty položky je jen název štítku.
- [x] Alerty jsou v Upozornění, selhané zprávy (včetně degradované
      review karty) v Nepodařilo se zpracovat.
- [x] Návrh pravidla odesílatele v Ke kontrole, digest v Ostatní (D10).
- [x] Badge sekcí navigace a `summary.counts` počítají ze všech karet.
- [x] MCP `feed_cards` vrací `feedSection` a pravdivé `has_more`.
- [x] PHPUnit (cílené filtry, pak celá sada), `npm run build`,
      `npm run check:i18n` prošly.
- [ ] Ruční proklik na dev DS: pořadí sekcí, počty po jednokliku
      Použít a po průchodu frontou, „a N dalších“, filtr Faktury.
- [x] `docs/dashboard.md`, `CLAUDE.md` a stránky `help/` aktualizované,
      `help-index.py` a `tasks-index.py` prošly, `**Stav:**` aktualizovaný.
