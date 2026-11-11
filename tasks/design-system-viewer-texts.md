# Design system — texty viewerů z katalogů místo literálů v PHP (D65–D67, F1)

**Stav:** hotovo — 2026-11-11 (6 commitů; odchylky viz „Odchylky od zadání“); ruční proklik čeká

## Kontext / Cíl

Bod checklistu #133 „Popisky detailu natvrdo česky v PHP“. Viewery skládají
detail i řádky seznamu z literálů přímo v PHP — `addItem($items, 'Platnost',
…)`, `'title' => 'Identifikace'`, štítky „výchozí / aktivní / neaktivní“,
„(bez předmětu)“, „IČO: “. V angličtině se ukážou česky (a pár anglických
literálů jako „Dismiss“ naopak v češtině anglicky). Konvence v `CLAUDE.md`
(Backend — Vícejazyčnost server-driven labels) přitom říká: texty, které
generuje backend, jdou přes cfgItems.

Inventura (2026-11-11): ≈ 350 literálů jako popisek a ≈ 90 dalších českých
textů ve 42 viewerech — tabulka v kroku 0.

**Cíl:**

1. Žádný text vieweru jen v jednom jazyce: popisek shodný s popiskem sloupce
   z definice tabulky, ostatní z katalogu modulu (D66).
2. Kontrola testem, aby literály nevznikaly znovu (D67).

**Mimo rozsah:**

- **dvojjazyčný zápis přímo v PHP** (`$cs ? 'česky' : 'anglicky'`,
  `['cs' => …, 'en' => …]`) — funguje v obou jazycích, převede ho task F2
  (D65); v souborech, kde je vedle literálů, ho nech beze změny;
- formuláře (`*Form.php`, jsonc formuláře), tisky, reporty, hlášky
  validace, výjimky a logy;
- texty, které už jdou z katalogu nebo z definice tabulky;
- vzhled detailu — mění se jen zdroj textu.

## Návaznost

- **#133** — D30 (typované bloky, `detailText()`), D65, D66, D67; checklist
  „Další nesoulady“ (bod Popisky detailu natvrdo česky v PHP)
- `tasks/design-system-small-fixes.md` — `labelText()` a sdílená
  `catalogText()` v `TableViewer`, sekce `fields` Spisovny
- **F2** (napíše se po F1) — dvojjazyčný zápis na katalogy, konec výjimky
  v testu z kroku 5

## Před implementací přečti

- `CLAUDE.md` → Backend — Vícejazyčnost server-driven labels
- `src/Core/Viewer/TableViewer.php` — `detailTabLabel()`, `detailText()`,
  `detailSectionText()`, `labelText()`, `catalogText()`, `addItem()`
- `src/Core/Form/TableForm.php` — `subtableLabel()` (vzor `formLabel ?? name`)
- `src/Api/TableLoader.php` — definice tabulek jsou lokalizované
  (`ConfigLocalizer`)
- `modules/base/registry/config/viewerDetailLabels.jsonc` a registrace
  v `modules/base/registry/module.jsonc` (`config`)
- `modules/core/ai/config/viewerLabels.jsonc` (plochý tvar)
- `tests/Unit/Core/Viewer/ViewerDetailLabelsCatalogTest.php`,
  `TableViewerLabelTextTest.php`, `tests/Unit/Core/Feed/FeedTextsCatalogTest.php`
- `docs/frontend.md` (typované bloky, `empty`), `docs/design-system.md`
  okolí ř. 1314

## Rozhodnutí (potvrzená v #133)

- ✓ **D65** Převádějí se texty detailu i seznamu (popisky, štítky, zástupné
  texty). F1 = literály jen v jednom jazyce (tento task), F2 = dvojjazyčný
  zápis v PHP.
- ✓ **D66** Popisek shodný s popiskem sloupce z lokalizované definice
  tabulky (`TableViewer::columnLabel()`, `formLabel ?? name` jako formulář);
  ostatní z katalogu modulu — plochý `*.viewerLabels` přes `labelText()`,
  kde ho modul má, jinak `*.viewerDetailLabels` sekce `texts` přes
  `detailText()` (i pro texty seznamu). Anglický fallback v PHP.
- ✓ **D67** Unit test nad zdroji viewerů: žádný řetězcový literál s českou
  diakritikou mimo komentáře, žádný literál jako popisek v `addItem()`,
  `'title'`, `'label'`; dvojjazyčný zápis má výjimku do F2.

## 0. Inventura

Před prvním commitem si výčet ověř vlastním průchodem (tokenizer, ne jen
grep — popisky bez diakritiky jako „Stav“, „Typ“, „Druh“ grep na diakritiku
mine). Odhad z 2026-11-11 (L = literál jako popisek, T = další český text,
katalog modulu dnes):

| Modul | Viewer | L | T | Katalog |
|---|---|---|---|---|
| base.persons | `PersonsViewer` | 12 | 8 | detail |
| base.registry | `BindersViewer`, `RegistryDocumentsViewer` | 18 | 3 | detail |
| core.ai | `AIBackendsViewer` | 13 | 3 | plochý |
| core.alerts | `AlertsViewer` | 21 | 5 | detail |
| core.chat | `ConversationsViewer` | 8 | 1 | — |
| core.exchange | `Enrich/TagRulesViewer` | 1 | 0 | — |
| core.mail | `AIProfilesViewer`, `IncomingMessagesViewer`, `MailboxesViewer`, `Preprocess/PreprocessRulesViewer`, `SenderRulesViewer`, `SendersViewer` | 83 | 24 | detail |
| core.prints | `PrintTextsViewer` | 13 | 3 | — |
| core.system | `UsersViewer` | 1 | 0 | detail |
| core.units | `UnitsViewer` | 5 | 1 | — |
| docs.core | `DocsHeadsViewer`, `NumberSeriesViewer` | 10 | 1 | plochý |
| economy.accbal | `BalancesViewer`, `CasesViewer`, `LedgerViewer` | 7 | 0 | — |
| economy.accounting | `AccountsViewer`, `JournalViewer` | 16 | 4 | detail |
| economy.bank | `BankTransactionsViewer` | 2 | 0 | — |
| economy.codebooks | `BankAccountsViewer`, `CashDesksViewer`, `CostCentersViewer`, `FiscalYearsViewer`, `PaymentTerminalsViewer`, `TransportsViewer`, `VatRegistrationsViewer`, `WarehousesViewer` | 61 | 13 | detail |
| economy.exchangeRates | `ExchangeRateListsViewer` | 10 | 5 | detail |
| economy.items | `ItemKindsViewer`, `ItemsViewer` | 17 | 10 | detail |
| economy.vat | `DeductionCoefficientsViewer`, `FilingsViewer`, `ReportPeriodsViewer` | 35 | 5 | — |
| hosting.core | `AiUsageViewer`, `DataSourcesViewer`, `ServersViewer` | 12 | 0 | — |
| tasks.core | `TasksViewer` | 5 | 3 | — |

Moduly s „—“ dostanou nový `config/viewerDetailLabels.jsonc` a registraci
v `module.jsonc` (`<modul>.viewerDetailLabels`, vzor `base.registry`).

## 1. `TableViewer::columnLabel()` (D66)

- `protected function columnLabel(string $column, string $fallback, ?string $table = null): string`
  — `formLabel ?? name` sloupce z `$this->tables[$table ?? $this->table]`;
  bez definice tabulky nebo sloupce `$fallback` (anglicky).
- Hodnota z připojené tabulky (např. `kind_name` z číselníku druhů) bere
  popisek ze sloupce zdrojové tabulky přes `$table`, jen pokud popisek
  odpovídá významu v detailu; jinak katalog.
- `TableForm::subtableLabel()` je stejná logika ve formuláři — nech ji,
  jen ať obě metody mají stejné chování (formulář a viewer nejsou jedna
  hierarchie; sdílet přes trait jen pokud to vyjde bez dalších změn).
- Unit test (`TableViewerColumnLabelTest`): `formLabel` má přednost před
  `name`, chybějící tabulka i sloupec → fallback, cizí tabulka přes
  `$table`.

## 2. Převod viewerů (D65, D66)

Pro každý text v pořadí:

1. **Popisek sloupce** — text vlastnosti v detailu, který v češtině
   i angličtině odpovídá `formLabel ?? name` sloupce, jehož hodnotu
   zobrazuje → `columnLabel()`. Liší-li se dnešní český literál od popisku
   sloupce jen formou („Název zkrácený“ × „Zkrácený název“), použij popisek
   sloupce a zapiš do odchylek; liší-li se významem, katalog.
2. **Katalog modulu** — ostatní:
   - modul s plochým `*.viewerLabels` (core.ai, docs.core) → nové klíče
     tam, čtení přes `labelText()` (tenké `text()` báze modulu, pokud ji má);
   - ostatní → `*.viewerDetailLabels` sekce `texts`, čtení přes
     `detailText()` — i texty řádků seznamu (`renderRow()`), štítky
     a zástupné texty;
   - **sdílené stavy** — text, který se opakuje ve třech a více modulech
     (pravděpodobně „výchozí“, „aktivní“, „neaktivní“, „systémový“), jde
     do `core.system.viewerDetailLabels` sekce `texts` (jako sdílený tab
     `overview`), ne do každého modulu zvlášť;
   - `core.mail` má dnes vedle `texts` vlastní sekci `labels` a privátní
     `IncomingMessagesViewer::viewerLabel()` — přesuň položky do `texts`
     a metodu nahraď `detailText()` (D66: žádný další tvar).
3. **Klíče** — camelCase podle významu, ne podle místa; stejný text
   v témže modulu = jeden klíč. Položka `{name, name:cs, name:en}`, holé
   `name` = anglická varianta = fallback v PHP znak po znaku.
4. **Parametry** — `{param}` (prostý `str_replace`, žádné ICU). Text
   s plurálem nepřeváděj na ICU: přeformuluj na tvar bez skloňování
   („Počet dokumentů: {n}“) a zapiš do odchylek.
5. **Měna** — `' Kč'` v `ItemsViewer` (řádek seznamu i detail) nahraď
   symbolem domácí měny: `economy.homeCurrency` ze `SettingsStore` (vzor
   `BankTransactionForm`), symbol z cfgItem `world.base.currencies` (pole
   `symbol`, fallback kód měny velkými písmeny); bez nastavené domácí měny
   jen číslo. Čísla dál formátuje dnešní kód.
6. **Prefixy** („IČO: “, „DIČ: “, „č. “) — celý vzor do katalogu
   s parametrem (`"IČO: {value}"` / `"Reg. no.: {value}"`), ne skládání
   přeloženého slova s dvojtečkou v PHP.

Anglické literály (např. „Dismiss“, „Base URL“, „SMTP“, „MD“ / „DAL“) jdou
stejnou cestou — i zkratka, která je v obou jazycích stejná, má položku
v katalogu (test z kroku 5 literál jako popisek nepustí).

## 3. Katalogy a registrace

- Nové `config/viewerDetailLabels.jsonc` v modulech, které ho nemají
  (core.chat, core.exchange, core.prints, core.units, economy.accbal,
  economy.bank, economy.vat, hosting.core, tasks.core — podle toho, co
  v kroku 2 skutečně potřebuješ) + registrace v `module.jsonc`; úvodní
  komentář katalogu jako u `base.registry` (který viewer, které sekce).
- `ViewerDetailLabelsCatalogTest::EXPECTED` doplnit o nové katalogy.
  Existuje-li obdobný test pro plochý `*.viewerLabels`, ověř, že nové klíče
  projdou; pokud neexistuje, nepřidávej ho (mimo rozsah, zapiš do
  odchylek).
- `ds-upgrade` jen na zdrojích s třídou `free` podle
  `shpd-server ds-list --json` — ostatní zdroje (`realCopy`, i bez třídy)
  nespouštěj. Bez kompilace katalogu viewer tiše použije anglický
  fallback — na nepřekompilovaném zdroji se tak dočasně ukáže angličtina
  i v češtině; to je známý degradovaný stav, ne chyba.

## 4. Převod po oblastech

Převod jde po skupinách modulů (kroky 2–3 pro danou skupinu v jednom
commitu), aby se dal procházet:

- base + core (persons, registry, ai, alerts, chat, exchange, mail, prints,
  system, units)
- docs + economy
- hosting + tasks

## 5. Kontrola (D67)

`tests/Unit/Core/Viewer/ViewerSourceTextsTest.php` nad
`modules/*/*/src/**/*Viewer*.php` (včetně bází jako `AccbalViewerBase`)
a `src/Core/Viewer/**/*.php`, přes `token_get_all` (komentáře se tak
vynechají samy):

1. Žádný `T_CONSTANT_ENCAPSED_STRING` s českou diakritikou.
2. Žádný neprázdný řetězcový literál jako druhý argument `addItem(` ani
   jako hodnota klíče `'title'` / `'label'` v poli (anglický fallback
   v `detailText(…, 'Fallback')` je argument jiné funkce — povolen).
3. **Výjimka do F2:** dvojjazyčný zápis — literál ve větvi ternárního
   výrazu, jehož podmínka je proměnná jazyka (`$cs`, `$isCs`…), nebo
   hodnota pod klíčem `'cs'` / `'en'`. Výjimka je pravidlem vzoru, ne
   seznamem souborů (12 viewerů má oba zápisy vedle sebe a výjimka po
   souborech by schovala i literály F1). Tvar výjimky zapiš do odchylek
   a do komentáře testu (`F2 odstraní`).
4. Hláška testu: soubor, řádek, literál a co místo něj (`columnLabel()`,
   `detailText()`, `labelText()`).

Test přidej až po převodu (každý commit musí nechat strom zelený).

## 6. Dokumentace

- `CLAUDE.md` → Backend — Vícejazyčnost server-driven labels: u Detail
  tabů vieweru doplnit `columnLabel()` (popisek sloupce z definice
  tabulky), pravidlo sdílených stavů v `core.system` a kontrolu
  `ViewerSourceTextsTest` (D67).
- `docs/design-system.md` (okolí ř. 1314) — odkaz na D66, pokud se tam
  mluví o zdroji textů detailu.
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `feat(viewer): popisek sloupce z definice tabulky (#133, D66)` — krok 1
2. `refactor(viewer): texty viewerů base a core z katalogů (#133, D65, D66)`
3. `refactor(viewer): texty viewerů docs a economy z katalogů (#133, D65, D66)`
4. `refactor(viewer): texty viewerů hosting a tasks z katalogů (#133, D65, D66)`
5. `test: literály v textech viewerů (#133, D67)` — krok 5
6. `docs: zdroj textů viewerů (#133, D66, D67)` — krok 6 + `**Stav:**`
   tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `php -l` na změněných souborech; PHPUnit s úzkým filtrem:
  `--filter 'TableViewerColumnLabelTest|ViewerSourceTextsTest|ViewerDetailLabelsCatalogTest|TableViewerLabelTextTest'`,
  pak testy dotčených viewerů po modulech (`--filter` názvem třídy).
- **Čeština beze změny:** na zdroji `free` po `ds-upgrade` porovnej výstup
  API detailu (`renderDetail`) a stránky seznamu (`renderRow`) pro pár
  záznamů každého dotčeného vieweru v `cs` před a po převodu (uložit JSON,
  diff). Rozdíl smí být jen tam, kde krok 2.1 záměrně převzal popisek
  sloupce, a u měny — každý vypiš do odchylek.
- **Angličtina:** stejný průchod s `Accept-Language: en` — v textech
  vieweru žádná česká diakritika (kromě dat záznamů); vypiš, co zbylo,
  a proč (F2, data).
- Prohlížeč (Playwright, zdroj `free`, `en` i `cs`): detail a seznam
  aspoň u Osob, Došlé pošty, Účtů, Bankovních účtů, Položek a Období DPH.
- Viewery `hosting.core` na dev zdroji neověříš — stačí testy a `php -l`,
  zapiš do odchylek.

## Hotovo když

- [x] Žádný viewer neskládá popisek ani text seznamu z literálu jen
      v jednom jazyce (kromě dvojjazyčného zápisu pro F2)
- [x] Popisky shodné se sloupcem jdou z definice tabulky přes
      `columnLabel()`
- [x] Nové katalogy registrované, `ViewerDetailLabelsCatalogTest` prochází
- [x] `ViewerSourceTextsTest` prochází a hlídá oba vzory z D67
- [x] Čeština beze změny (kromě vypsaných odchylek), angličtina bez českých
      textů vieweru
- [x] Dokumentace podle kroku 6, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- Definice tabulek jsou lokalizované už při načtení (`TableLoader` +
  `ConfigLocalizer`) — `columnLabel()` nic nepřekládá, jen vybírá
  `formLabel ?? name`.
- Holé `name` v katalogu musí být anglicky a stejné jako fallback v PHP —
  jinak se na nepřekompilovaném zdroji ukáže jiný text než po kompilaci.
- `detailText()` nahrazuje `{param}` prostým `str_replace` — hodnota
  parametru se nepřekládá; data záznamu předávej jako parametr, ne jako
  součást klíče.
- Popisky bez diakritiky („Stav“, „Typ“, „Druh“, „Text“, „Model“) najde jen
  kontrola literálu jako popisku, ne kontrola diakritiky — inventuru dělej
  tokenizerem.
- `ds-upgrade` jen na `free` zdrojích; na `realCopy` (i zdroji bez třídy)
  ne — ani kvůli ověření.
- Neměň dvojjazyčný zápis ani v souborech, kde převádíš literály — F2 ho
  převede celý najednou a výjimka testu na něj spoléhá.

## Odchylky od zadání

- **Inventura:** tokenizerem 372 literálů jako popisek a 90 dalších českých
  textů ve 42 viewerech; 14 viewerů (ne 12) má oba zápisy vedle sebe.
  `addItem()` není v `TableViewer` — je to privátní metoda v každém vieweru,
  test ji pozná podle jména volání; nesjednocovala se.
- **Texty bez diakritiky mimo místo popisku** (≈ 70: „Ano“ / „Ne“, „od “ /
  „do “, „bez konce“, „VS “, „Pokladna “, „splat. “) převedené podle D65,
  i když je pravidla D67 nechytí. Test má proto druhé pravidlo i pro klíč
  `'text'` (řádky seznamu); „Ano“ / „Ne“ jako hodnotu neuhlídá.
- **Test — pravidlo 1** platí i pro části interpolovaných řetězců
  (`T_ENCAPSED_AND_WHITESPACE`; tři české texty v nich byly).
- **Test — tvar výjimky do F2:** literál ve větvi ternáru, jehož podmínka
  končí proměnnou jazyka (`$cs`, `$isCs`), porovnáním s kódem jazyka
  (`$this->language === 'cs'`, `… === 'en'`) nebo závorkou, která kód jazyka
  obsahuje (`($this->language ?? 'en') === 'cs'`); a hodnota pod klíčem
  `'cs'` / `'en'`. Hledá se zpět v rámci téhož výrazu — čárka, středník,
  přiřazení a `=>` ho ukončují.
- **Sdílené texty:** `TableViewer::sharedText()` a `yesNoText()` nad
  `core.system.viewerDetailLabels` → `texts`. Vedle stavových slov
  (výchozí / aktivní / neaktivní / systémový / admin) tam jsou texty, které
  hranici tří modulů splňují už mezi literály F1: Ano / Ne, „od {date}“,
  „do {date}“, „bez konce“, Identifikace, Platnost, „(bez názvu)“, Vše
  a relativní čas. Stavová slova jsou mezi literály F1 jen ve dvou modulech
  — do `core.system` šla předem, aby se v F2 nestěhovala.
- **Hlavičky sloupců tabulek zůstaly krátké** (z katalogu, ne z popisku
  sloupce): měsíce fiskálního roku, tab Analýzy Došlé pošty, řádky
  kontrolního hlášení, úzké sloupce mřížky využití AI. Plný popisek sloupce
  („Kalendářní měsíc“, „Verze promptu“) by tabulku roztáhl; krok 2.1 mluví
  o vlastnosti v detailu.
- **Převzaté popisky sloupců** (čeština se liší jen formou, krok 2.1):
  Osoby „Web“ → „Webová stránka“; Spisovna „Doručeno“ → „Datum doručení“;
  pravidla štítků „IČO“ → „IČO dodavatele“; AI profily „Přemýšlení“ →
  „Přemýšlení (thinking)“, „Úsilí“ → „Úsilí (effort)“; Došlá pošta
  „Message-ID“ → „RFC822 Message-ID“, „Stav“ → „Stav předzpracování“,
  „Velikost“ → „Velikost souboru“, „MIME“ → „MIME typ“; Upozornění
  „Kontrola“ → „ID kontroly“; Chat „Model“ → „Model (snapshot)“; Fiskální
  roky „Začátek“ / „Konec“ → „Začátek období“ / „Konec období“; Kurzovní
  lístky filtr „Typ“ → „Typ lístku“; Položky „Druh“ → „Druh položky“, „Typ“
  → „Typ položky“, „Cena bez DPH“ → „Prodejní cena bez DPH“.
- **Plurály přeformulované:** Kurzovní lístky „29 měn“ → „Počet měn: 29“;
  Upozornění „před 3 dny“ → „před 3 d“.
- **Další změny češtiny:** tab Analýzy „ano“ / „ne“ → „Ano“ / „Ne“ (sdílený
  text).
- **Měna:** symbol domácí měny i u zůstatků účtu 343 v detailu období DPH
  (`ReportPeriodsViewer`) — zadání jmenovalo jen `ItemsViewer`, literál
  „ Kč“ by ale test nepustil. Pravidlo je v obou viewerech stejné (privátní
  metoda, bez sdílené třídy).
- **core.mail:** `labels.from` je vzor `texts.fromSender` („od: {sender}“),
  `labels.subject` do `texts` nepřešel — je to popisek sloupce `subject`.
  Česká nouzová mapa názvů typů zpráv v `IncomingMessagesViewer` zanikla,
  názvy jdou jen z `core.mail.primaryTypes`.
- **core.alerts:** akce Odložit / Zamítnout byly vnořený dvojjazyčný zápis,
  který výjimka testu nepozná; popisky čtou z existujícího
  `core.alerts.viewerDefaults` → `rowActions`.
- **core.ai:** „Kód připojení“ a „Výchozí připojení“ jsou v katalogu —
  popisky sloupců říkají „Kód backendu“ / „Výchozí backend“ (starý termín,
  formulář je ukazuje dál).
- **core.exchange** katalog nedostal (stačil popisek sloupce); `core.system`
  má jediný literál převedený na sdílený text.
- **Test plochých katalogů** `*.viewerLabels` neexistuje a nepřidával se;
  shodu fallbacku s holým `name` hlídá jen `FeedTextsCatalogTest` u feedu.
  Jednorázová kontrola při implementaci (volání `detailText()` /
  `labelText()` / `sharedText()` proti katalogům: klíč, fallback, parametry)
  prošla; našla jeden starší rozdíl mimo rozsah — `AssetsViewer`
  `text.notActivated` má v PHP kratší fallback než katalog.
- **Dokumentace navíc:** `docs/frontend.md` (lokalizace textů z backendu)
  a zastaralé zmínky sekce `labels` v `modules/core/mail/docs/ai-analysis.md`.

## Ověření při implementaci

- `php -l` na všech změněných souborech; celá sada `--testsuite Unit`
  (11 096 testů) prošla; mezistavy po commitech 2 a 3 ověřené zvlášť
  (testy viewerů a katalogů).
- **Čeština beze změny:** snímek výstupu všech 58 viewerů (meta, řádky
  seznamu, mřížka, detail až šesti záznamů) před převodem a po něm na
  volných zdrojích `zey0-g` a `nham-q` po `ds-upgrade`. Rozdíl jen
  v odchylkách vypsaných výše (11 dvojic).
- **Angličtina:** tentýž průchod v `en` — z původních literálů a textů
  katalogů nezbyl žádný; řetězce s diakritikou jsou data záznamů (názvy
  druhů položek, jednotek, řad).
- **Prohlížeč** (Playwright, `zey0-g`, `cs` i `en`): seznam a detail včetně
  tabů u Osob, Došlé pošty, Účtů, Položek, Období DPH, Spisovny, Faktur
  přijatých a v Nastavení u Bankovních účtů, Fiskálních roků, Číselných
  řad, Jednotek a Druhů položek — bez chyby stránky a bez odpovědi 5xx.
- **Bez dat na volných zdrojích** (jen testy a `php -l`): Upozornění, Chat,
  Pravidla odesílatelů, Odesílatelé, Texty na tiscích, Střediska, Platební
  terminály, Dopravy, Sklady, Koeficienty odpočtu, Podání DPH, Úkoly;
  `hosting.core` na vývojovém zdroji není.
- Integrační `ViewerDetailContractTest` nad `zey0-g`: tři testy prošly,
  `testOverviewDoesNotRepeatHeader` hlásí u bankovních transakcí položku
  „Název protistrany“ — stejně i před převodem (na obvyklém integračním
  zdroji transakce nejsou), s tímto taskem nesouvisí.
