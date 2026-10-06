# Dashboard — řádek sekce Ostatní s titulkem podle obsahu zprávy

**Stav:** hotovo — implementováno 2026-10-06 (D1–D4)

## Cíl

Sekce **Ostatní** na dashboardu ukazuje kompaktní řádky došlých zpráv, ze
kterých AI nenavrhla doklad ani dokument Spisovny (`primary_type='other'`,
karta `mail_notinvoice:*` z `MailSuggestionsSource`). Titulek každého řádku
dnes zní **„Není faktura — Ostatní“**:

- „Není faktura“ pochází z doby, kdy pošta končila jen ve Fakturách
  přijatých. Od Spisovny je nepřesné — smlouva nebo úřední písemnost také
  „není faktura“, a přitom do Ostatních nepadá.
- Za `{type}` se dosazuje název primárního typu, jenže dotaz
  `fetchNotInvoiceRows()` bere výhradně `primary_type='other'` — druhá
  polovina je tedy vždy „Ostatní“ a jen opakuje nadpis sekce.

AI přitom ke každé analyzované zprávě vrací `message_classification.title`
→ sloupec `ai_title`, a prompt u `other` výslovně chce **stručný popis
obsahu** („Newsletter — novinky dodavatele“, „Dopis od úřadu bez dokladu“,
„Sken obálky“, „Upozornění na expiraci domény“). Na testovacím serveru ho
mají všechny zprávy, které dnes na řádek Ostatní padají. Na kartě se ale
ukáže jen tam, kde nahrazuje generický předmět (pravidlo D3
`IncomingMessageTitle`, typicky skeny).

Po tomto tasku je titulkem řádku `ai_title` — uživatel na první pohled vidí,
co ve zprávě je, a pozná, co odklidit a co otevřít.

## Před implementací přečti

- `docs/dashboard.md` — kartový kontrakt (§4), karty došlé pošty
  (odstavec „Karty „Není faktura““ a „Strukturovaná pole per druh karty“)
- `modules/core/mail/docs/ai-analysis.md` → *Titulek zprávy (ai_title)* —
  zdroj `ai_title` a pravidlo zobrazení `IncomingMessageTitle::display()`
- `CLAUDE.md` → *Backend — Vícejazyčnost* (Texty karet feedu) a
  `tasks/feed-texts-catalog.md` (katalog `core.mail.feedTexts`, konvence
  fallbacku D15)
- `docs/help-authoring.md` — úprava stránky v `help/`

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — Titulek řádku = `ai_title` zprávy** (oříznutý o bílé znaky).
  Beze změny promptu, klasifikace, akcí, `kind`, `feedSection`
  i `stateStyle`. Titulek je v jazyce AI profilu, stejně jako `ai_title`
  všude jinde v aplikaci.
- ✓ **D2 — Bez `ai_title` je titulkem konstanta z katalogu**:
  cs **„Neobsahuje doklad ani dokument“**, en „Contains no document“.
  Týká se analýz před promptem v4.3.0 a analyzeru, který `title` vynechá
  (pole je ve schématu volitelné). Text pokrývá obě cesty — Faktury
  i Spisovnu. Parametr `{type}` zaniká.
- ✓ **D3 — `emailSubject` se neposílá, když se shoduje s titulkem.** Nastane
  u skenů, ručně nahraných souborů a generických předmětů — tam
  `IncomingMessageTitle::display()` vrací právě `ai_title`. Rozhoduje
  server; frontend (`FeedRowCompact`, `FeedCard`) beze změny — pole
  `emailSubject` je na kartě volitelné už dnes.
- ✓ **D4 — Rozsah.** Katalog (klíč `notInvoice.title` → `other.title`),
  PHP fallback, testy, terminologie v `docs/`, docblocích a komentářích,
  stránka `help/posta/prijem-posty.md`. Interní identifikátory zůstávají:
  id karty `mail_notinvoice:*`, metody `fetchNotInvoiceRows()`
  a `buildNotInvoiceCard()`, proměnné v testech.

## Scope

**V rozsahu:** kroky 1–5 níže.

**Mimo rozsah:**

- Strukturovaná podklasifikace `other` (newsletter / notifikace / výzva
  k úhradě / sken obálky …) a jiné výchozí akce podle ní — samostatná
  diskuse po tomto tasku.
- Frontend kód, kartový kontrakt (pole se nepřidávají ani neodebírají,
  mění se jen hodnota `title` a podmínka `emailSubject`), MCP nástroje.
- Ostatní druhy mail karet (návrhové, chybové) — jejich `emailSubject`
  zůstává beze změny.

## 1. Katalog `modules/core/mail/config/feedTexts.jsonc`

Položku `notInvoice.title` nahradit (holé pole = anglický fallback, znak
po znaku shodný s fallbackem ve zdroji — hlídá `FeedTextsCatalogTest`):

```jsonc
    // Titulek karty ostatní pošty (zpráva `other` bez návrhu), když zpráva
    // nemá AI titulek `ai_title` — jinak je titulkem ten
    // (tasks/dashboard-other-row-title.md D1, D2).
    "other.title": {
        "text": "Contains no document",
        "text:cs": "Neobsahuje doklad ani dokument",
        "text:en": "Contains no document"
    },
```

Komentář u `partnerFrom` přepsat na „Podtitulek chybové karty a karty
ostatní pošty se známým partnerem.“ Položka `primaryType.other` zůstává —
dál ji používá `primaryTypeLabel()` (hint `secondaryFindings`).

## 2. `modules/core/mail/src/Feed/MailSuggestionsSource.php`

`buildNotInvoiceCard()`:

```php
        $subject = $this->messageTitle($ctx, $row);
        // D1, D2: titulek = AI popis obsahu zprávy; bez něj konstanta z katalogu.
        $aiTitle = trim((string) ($row['ai_title'] ?? ''));
        $title = $aiTitle !== ''
            ? $aiTitle
            : $texts->t('other.title', 'Contains no document');
        // …
            'title'      => $title,
        // …
        // D3: předmět jen když se od titulku liší — u skenů, ručního nahrání
        // a generických předmětů vrací messageTitle() právě ai_title.
        if ($subject !== '' && $subject !== $title) {
            $card['emailSubject'] = $subject;
        }
```

`ai_title` už dotaz `fetchNotInvoiceRows()` vybírá — SQL se nemění.
Volání `primaryTypeLabel()` z `buildNotInvoiceCard()` zaniká; metoda
zůstává (secondary findings).

Docblocky třídy a obou metod: „karty „Není faktura““ → „karty ostatní
pošty“ (zprávy bez dokladu i dokumentu Spisovny) a do hlavičky třídy
krátce pravidlo titulku a `emailSubject` s odkazem na tento task.

## 3. Testy

`tests/Unit/Module/Core/Mail/Feed/MailSuggestionsSourceTest.php`:

- `testEnglishCatalogAndFallbackWithoutCatalogAgree` — řádek má
  `ai_title => null` → `'Contains no document'` (katalog i fallback shodně).
- `testNotInvoiceCardWithTrashArchiveActions` — řádek bez `ai_title` →
  `'Neobsahuje doklad ani dokument'`; `emailSubject` `'Nabídka spolupráce'`
  zůstává. Mock `core.mail.primaryTypes` už titulek neovlivňuje — smí
  zůstat, nebo pryč.
- `testNotInvoiceSubtitleShowsPartnerAndSender` (ruční nahrání,
  `ai_title` = „Dopis od úřadu bez dokladu“) — titulek je `ai_title`,
  `emailSubject` na kartě **není** (`assertArrayNotHasKey`), subtitle beze
  změny.
- Nový test: e-mail s běžným předmětem a `ai_title` → titulek `ai_title`,
  `emailSubject` = předmět.
- Nový test: `ai_title` jen z bílých znaků → fallback z katalogu.
- Komentáře v docblocku testu („karta „Není faktura““) sjednotit na
  „karta ostatní pošty“.

Kosmetika: fixture `'title' => 'Není faktura'` v
`tests/Unit/Core/Dashboard/DashboardSummaryServiceTest.php` a komentář
v `tests/Integration/Mail/AnalysisResultEndpointTest.php` sjednotit
s novou terminologií (bez vlivu na chování).

```bash
vendor/bin/phpunit --filter 'MailSuggestionsSourceTest|FeedTextsCatalogTest|FeedTextsTest|DashboardControllerTest|DashboardSummaryServiceTest'
```

## 4. Dokumentace a komentáře

- `docs/dashboard.md`:
  - ASCII náčrt sekcí: řádek `Není faktura — … · „…“` → `{AI titulek} · „…“`;
  - výčty druhů karet (sekce `other`, tabulka `kind`, tabulka
    `feedSection`): „Není faktura“ → „karta ostatní pošty“;
  - odstavec „Karty „Není faktura““ přepsat: titulek = `ai_title`, bez něj
    `other.title` z katalogu (D1, D2), `emailSubject` jen když se od
    titulku liší (D3);
  - „Strukturovaná pole per druh karty“: u karty ostatní pošty
    `emailSubject` podmíněně (D3), věta o subtitlu bez „Není faktura“.
- `modules/core/mail/docs/ai-analysis.md`: odstavec o `document: null`
  + klasifikaci `other` (karta ostatní pošty s titulkem z `ai_title`);
  v *Titulek zprávy* → *Kde se pravidlo uplatňuje* doplnit, že karta
  ostatní pošty používá `ai_title` přímo jako titulek (popis obsahu),
  nezávisle na pravidle D3; zmínka u partnera zprávy.
- `docs/mail/api-contract.md` (krok 4 zpracování výsledku): „karta
  ostatní pošty“.
- Komentáře: `src/Core/Dashboard/DashboardSummaryService.php`
  (info karty bez signálu), `frontend/src/components/dashboard/Dashboard.svelte`
  (jednoklik Koš / Archiv) — jen text komentáře, build se nemění.

## 5. Nápověda `help/posta/prijem-posty.md`

Ve stejném commitu jako kód (`CLAUDE.md` → Uživatelská dokumentace).

- Front matter `summary`: „…a jak si poradit s poštou, ve které není doklad
  ani dokument.“ Do `keywords` přidat `ostatní pošta`, `sken obálky`,
  `neobsahuje doklad ani dokument`.
- Odstavec „**Když to není faktura.**“ přepsat zhruba takto (názvy akcí
  ověřené v `frontend/src/i18n/cs.js`):

  > **Když zpráva neobsahuje doklad ani dokument.** Reklamu, newsletter,
  > upozornění z e-shopu nebo sken obálky AI pozná a místo návrhu se na
  > Dashboardu objeví nenápadný řádek v sekci **Ostatní** s akcemi
  > **Do koše** a **Archivovat**. Tučně je na něm krátký popis toho, co ve
  > zprávě je — třeba „Newsletter — novinky dodavatele“ nebo „Sken
  > obálky“ —, vedle odesílatel a předmět e-mailu. U starších zpráv, ke
  > kterým AI popis nenapsala, je místo něj **Neobsahuje doklad ani
  > dokument**. Koš a Archiv se liší jen tím, kam zpráva zmizí; obojí ji
  > odklidí z cesty a přílohy zůstanou.

Pak `python3 scripts/help-index.py`.

## Pasti

- **Přejmenování klíče katalogu vyžaduje `ds-upgrade`.** Zdroj dat se
  starým compiled configem klíč `other.title` nemá → `FeedTexts` zaloguje
  warning (chybějící klíč v existujícím katalogu) a dá anglický fallback.
  Na řádcích s `ai_title` se to neprojeví, jen u starších zpráv bez něj.
  Při nasazení na alfu `ds-upgrade` všech zdrojů dat.
- **Shoda titulku a předmětu se porovnává na oříznutých řetězcích.**
  `IncomingMessageTitle::display()` vrací `trim()`; titulek ořízni stejně,
  jinak D3 kvůli mezeře na konci neodfiltruje duplicitu.
- **Titulek je delší než dnešní konstanta** (AI až ~120 znaků, sloupec
  varchar 200). Kompaktní řádek se zalamuje (`flex-wrap`), na serveru
  nezkracovat; při prokliku zkontrolovat vzhled dlouhého titulku.
- **`ai_title` přepisuje každá reanalýza** (i na `null`) — titulek řádku se
  po reanalýze může změnit nebo spadnout na fallback. Záměr, žádné
  ukládání stranou.
- **MCP `feed_cards` vrací titulky karet** — dostane nově `ai_title`.
  Kontrakt beze změny; AI shrnutí dashboardu info karty nečte.

## Commit

Jeden commit `feat(dashboard): titulek řádku Ostatní podle obsahu zprávy`
— katalog, zdroj, testy, docs, nápověda a tento `**Stav:**`
(+ `python3 scripts/tasks-index.py`).

## Hotovo když

- [x] Řádek v sekci Ostatní má titulek `ai_title`, bez něj „Neobsahuje
      doklad ani dokument“ (cs) / „Contains no document“ (en).
- [x] U skenu nebo ručně nahraného souboru se titulek na řádku neopakuje
      v uvozovkách jako předmět; u běžného e-mailu předmět zůstává.
- [x] Návrhové a chybové karty beze změny.
- [x] Cílené testy (filtr výše) zelené, `FeedTextsCatalogTest` hlídá nový
      klíč.
- [x] `docs/dashboard.md`, `ai-analysis.md`, `api-contract.md`
      a `help/posta/prijem-posty.md` nemluví o „Není faktura“
      (`grep -rn "Není faktura" --include=*.md --include=*.php --include=*.jsonc --include=*.svelte docs help modules/core/mail src frontend/src tests`
      vrátí nanejvýš historické tasky).
- [x] `ds-upgrade` na dev DS a ruční proklik dashboardu cs/en.
- [x] `**Stav:**` aktualizován, `tasks-index.py`, `help-index.py`.
