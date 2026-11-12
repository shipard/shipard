# Design system — dvojjazyčné texty viewerů z katalogů (D65–D67, F2)

**Stav:** hotovo — 2026-11-12 (#133 Další nesoulady, D65, D66, D67; 6 commitů, odchylky viz „Odchylky od zadání“); ověřeno snímkem výstupu viewerů a v Chromiu v `cs` i `en` (viz Ověření při implementaci), ruční ověření na dev serveru prošlo (viz Ruční ověření)

## Kontext / Cíl

Druhá polovina bodu #133 „texty viewerů“ (D65). F1
(`design-system-viewer-texts.md`) převedl literály jen v jednom jazyce na
`columnLabel()` a katalogy; dvojjazyčný zápis přímo v PHP nechal a test
`ViewerSourceTextsTest` (D67) mu dal výjimku „do F2“:

- ternár s proměnnou jazyka — `$cs ? 'Zůstatky' : 'Balances'`,
  `$isCs ? …`, `$this->language === 'cs' ? … : …`,
  `(($this->language ?? 'en') === 'cs') ? …`;
- pole pod klíči jazyka — `['cs' => 'Základ', 'en' => 'Base']`;
- `sprintf($cs ? '%d řádků · základ %s' : '%d rows · base %s', …)`.

Funguje v obou jazycích rozhraní, ale obchází katalogy (cfgItems) —
konvence v `CLAUDE.md` (Backend — Vícejazyčnost) i D66.

Inventura (2026-11-11, řádky se vzorem jazyka včetně přiřazení `$cs = …`):
≈ 450 řádků ve 22 souborech — tabulka v kroku 0.

**Cíl:**

1. Žádný dvojjazyčný zápis ve viewerech — texty z definice tabulky,
   katalogu modulu nebo `core.system` stejně jako v F1 (D66).
2. Výjimka v `ViewerSourceTextsTest` zmizí a dvojjazyčný zápis s textem
   se sám stane porušením (D67).

**Mimo rozsah:**

- dvojjazyčný zápis mimo viewery (`NavigationController` — popisek
  skupiny Reporty, buildery reportů, formuláře, kontroly upozornění,
  feed) — jen vypsat do odchylek s počtem;
- formát data a čísel — task `design-system-date-format.md` (D73);
  pokud podmínka jazyka vybírá formát čísla nebo data, nech ji, pokud ji
  task D73 ještě nepřevedl, a zapiš;
- obsah textů — překládá se 1 : 1, nepřeformulovávat (kromě plurálů,
  krok 2.4).

## Návaznost

- **#133** — D65, D66, D67; checklist „Další nesoulady“ (bod Dvojjazyčný
  zápis)
- `tasks/design-system-viewer-texts.md` (F1) — vzor převodu, sdílené
  helpery `columnLabel()`, `sharedText()`, `yesNoText()`, nové katalogy
- `tasks/design-system-date-format.md` (D73) — **musí být hotový dřív**
  (stejné viewery, mění `formatDate()`)

## Před implementací přečti

- `tasks/design-system-viewer-texts.md` — kroky 2–3, Odchylky, Pasti
- `CLAUDE.md` → Backend — Vícejazyčnost (odstavec „Texty viewerů nejsou
  literály v PHP“)
- `src/Core/Viewer/TableViewer.php` — `columnLabel()`, `detailText()`,
  `labelText()`, `sharedText()`, `yesNoText()`
- `tests/Unit/Core/Viewer/ViewerSourceTextsTest.php` — výjimka
  (`LANGUAGE_VARIABLES`, `LANGUAGE_CODES`, `inLanguageTernary()`,
  `testBilingualNotationIsExemptUntilF2`)
- `modules/core/system/config/viewerDetailLabels.jsonc` (sdílené texty)

## Rozhodnutí (potvrzená v #133)

- ✓ **D65** F2 = dvojjazyčný zápis v PHP na katalogy (texty detailu
  i seznamu).
- ✓ **D66** Zdroj textu: popisek sloupce z definice tabulky
  (`columnLabel()`), ostatní katalog modulu (`*.viewerLabels` přes
  `labelText()`, kde ho modul má, jinak `*.viewerDetailLabels` sekce
  `texts` přes `detailText()`), sdílené texty `core.system`
  (`sharedText()`); anglický fallback v PHP.
- ✓ **D67** Kontrola `ViewerSourceTextsTest`; výjimka pro dvojjazyčný
  zápis platí jen do F2.

## 0. Inventura

Ověř vlastním průchodem (tokenizer jako test). Odhad z 2026-11-11 (řádky
se vzorem jazyka):

| Modul | Viewer | Řádky |
|---|---|---|
| economy.vat | `FilingsViewer` | 109 |
| economy.accbal | `CasesViewer`, `LedgerViewer`, `BalanceAccountsViewer`, `BalancesViewer`, `AccbalViewerBase` | 130 |
| economy.bank | `BankTransactionsViewer`, `BankStatementsViewer` | 45 |
| economy.accounting | `JournalViewer` | 29 |
| hosting.core | `AiUsageViewer`, `AiTokensViewer`, `MailRoutersViewer`, `DataSourcesViewer`, `DsUsersViewer`, `ServersViewer` | 68 |
| core.mail | `Sent/SentMessagesViewer`, `SendersViewer` | 36 |
| core.system | `UsersViewer` | 17 |
| core.exchange | `Enrich/TagRulesViewer` | 13 |
| docs.core | `DocsHeadsViewer` | 5 |
| core.alerts | `AlertsViewer` | 4 |
| core.ai | `AiFunctionsViewer` | 1 |

Všechny moduly už katalog mají (F1). Nové registrace jen tam, kde by
chyběl — zapiš.

## 1. Existující zdroj má přednost

Než text přidáš do katalogu, zkontroluj, jestli ho modul už nemá
lokalizovaný jinde a jen ho viewer opakuje:

- popisek sloupce → `columnLabel()`;
- hodnota výčtu (stav, druh, typ) → cfgItem výčtu modulu (`name:cs` /
  `name:en`); několik viewerů má privátní `enumLabel()` — použij ji, nebo
  ji při té příležitosti sjednoť do `TableViewer` (zapiš);
- řádky a oddíly přiznání a hlášení DPH (`FilingsViewer` — krátké popisky
  řádků 62, 63, 64…, složky Základ / V plné výši / Krácený) → zkontroluj
  `modules/economy/vat/config/` (`vat-reports-cz.jsonc`, `filingProfileCz.jsonc`,
  `filingHeader*.jsonc`); pokud tam lokalizovaný název je, čti ho odtud;
- text sdílený se třemi a více moduly → `core.system` (`sharedText()`),
  jak stanovil F1 (Částky, Zůstatky, Období… — ověř, co už tam je).

## 2. Převod (D66)

Postup a pravidla jako F1 krok 2 (klíče camelCase podle významu, holé
`name` = anglický fallback znak po znaku, prefix jako celý vzor
s parametrem). Navíc:

1. **Ternár → jedno volání.** `$cs ? 'Zůstatky' : 'Balances'` →
   `$this->detailText('balances', 'Balances')` (nebo `labelText()` /
   `sharedText()` podle zdroje). Anglická větev je fallback.
2. **`sprintf` → `{param}`.** `sprintf($cs ? '%d řádků · základ %s' : …,
   $n, $base)` → katalog `"{count} rows · base {base}"` a `detailText(…,
   ['count' => $n, 'base' => $base])`. Pořadí parametrů se může
   v jazycích lišit — proto pojmenované, ne poziční.
3. **Pole `['cs' => …, 'en' => …]`** (konstanty tříd jako mapy kód →
   text) → mapa kód → klíč katalogu; text až při použití.
4. **Plurály** — katalogy nemají ICU (F1 Pasti). Text s počtem přeformuluj
   na tvar bez skloňování („Řádků: {count}“, „Rozdíly proti deníku:
   {count}“) a každý zapiš do odchylek s původním zněním. Kde je vedle
   sebe jednotné a množné číslo podle počtu (`$n === 1 ? …`), stačí jeden
   tvar bez skloňování.
5. **Proměnná jazyka** (`$cs = …`, `$isCs = …`) po převodu zmizí; zůstane
   jen tam, kde vybírá něco jiného než text (formát čísla, jazyk
   dokumentu) — zapiš, kde a proč.

## 3. Kontrola (D67)

`ViewerSourceTextsTest`:

- smaž výjimku (`LANGUAGE_VARIABLES`, `LANGUAGE_CODES` jako výjimka,
  `inLanguageTernary()` jako výjimka, komentáře „F2 odstraní“);
- **dvojjazyčný zápis s textem je porušení:** literál s písmenem ve větvi
  ternáru s podmínkou jazyka a literál s písmenem pod klíčem `'cs'` /
  `'en'` → hláška „dvojjazyčný zápis — text do katalogu“. Detekci
  ternáru z dnešní výjimky využij, jen ji obrať;
- literál bez písmene (oddělovač, formátovací znak) v ternáru jazyka
  porušením není (formát čísla) — test na to;
- `testBilingualNotationIsExemptUntilF2` → test, že tytéž příklady jsou
  porušení; přidej případ bez písmene, který projde.

## 4. Dokumentace

- `CLAUDE.md` → odstavec „Texty viewerů nejsou literály v PHP“: věta
  o výjimce do F2 pryč, doplnit, že dvojjazyčný zápis test odmítne.
- `tasks/design-system-viewer-texts.md` se nemění (historie).
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

Po oblastech jako F1, každý commit nechá strom zelený (výjimka v testu
platí, dokud nepřijde commit 5):

1. `refactor(viewer): dvojjazyčné texty DPH z katalogů (#133, D65, D66)` — economy.vat
2. `refactor(viewer): dvojjazyčné texty saldokonta, banky a deníku z katalogů (#133, D65, D66)` — economy.accbal, economy.bank, economy.accounting
3. `refactor(viewer): dvojjazyčné texty core a docs z katalogů (#133, D65, D66)` — core.*, docs.core
4. `refactor(viewer): dvojjazyčné texty hostingu z katalogů (#133, D65, D66)` — hosting.core
5. `test: dvojjazyčný zápis ve viewerech je porušení (#133, D67)` — krok 3
6. `docs: texty viewerů bez dvojjazyčného zápisu (#133, D67)` — krok 4 +
   `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `php -l`; PHPUnit s úzkým filtrem:
  `--filter 'ViewerSourceTextsTest|ViewerDetailLabelsCatalogTest|TableViewerLabelTextTest'`,
  pak testy dotčených viewerů po třídách.
- **Výstup beze změny:** na zdroji `free` po `ds-upgrade` porovnej výstup
  API (seznam, detail) dotčených viewerů v `cs` i `en` před a po (JSON,
  diff) — jako v F1. Rozdíl smí být jen u plurálů (krok 2.4) a u textů
  převzatých z existujícího zdroje (krok 1), každý vypsaný v odchylkách.
- Prohlížeč (Playwright, zdroj `free`, `cs` i `en`): Podání DPH (detail
  přiznání a kontrolního hlášení — taby Výstupní řádky, Rozdíly, Zprávy),
  saldokonto (případ, účet), banka (výpis, transakce), deník, Uživatelé,
  Odeslaná pošta.
- Viewery `hosting.core` na dev zdroji neověříš — testy, `php -l` a diff
  výstupu, pokud jde vyvolat; zapiš.
- `ds-upgrade` jen na zdrojích s třídou `free` podle `shpd-server ds-list --json`.

## Hotovo když

- [x] Ve viewerech není dvojjazyčný zápis s textem (ternár jazyka ani pole
      `'cs'` / `'en'`)
- [x] Texty jdou z existujícího zdroje nebo katalogu podle D66
- [x] `ViewerSourceTextsTest` bez výjimky; dvojjazyčný zápis s textem
      hlásí jako porušení
- [x] Výstup v `cs` i `en` beze změny (kromě vypsaných plurálů a převzatých
      textů)
- [x] Dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- Anglická větev ternáru je dnes jediný anglický text — z ní je holé
  `name` i fallback v PHP; nepiš anglický text znovu „lépe“.
- Pořadí parametrů v `sprintf` se mezi jazyky někdy liší — při převodu
  na `{param}` porovnej obě větve, ne jen českou.
- `FilingsViewer` má texty, které jsou zároveň popisky řádků podání
  (oficiální formulář) — pokud je čteš z konfigurace podání, ověř, že je
  tam stejné znění; jinak raději katalog vieweru a zapiš.
- Bez kompilace katalogu (`ds-upgrade`) viewer tiše vezme anglický
  fallback i v češtině — na zdroji `realCopy` ho nespouštěj; po nasazení
  ho spustí člověk.
- Formát data — pokud task D73 ještě není hotový, F2 nezačínej.

## Odchylky od zadání

- **Inventura:** tokenizerem 815 literálů ve větvi ternáru jazyka a 16 pod
  klíči `'cs'` / `'en'` na 494 řádcích ve 22 souborech. `AiFunctionsViewer`
  má jen desetinný oddělovač (`',' : '.'`) — zůstal beze změny, je to
  jediná podmínka jazyka, která ve viewerech zbyla. `SentMessagesViewer`
  dál předává jazyk do `SentMessageTransportInfo` (není výběr textu).
- **Pořadí commitů:** hosting šel před core a docs (commity 3 a 4 jsou
  prohozené); každý commit nechává strom zelený.
- **core.exchange katalog neměl** (F1 ho nezaložil) — nový
  `core.exchange.viewerDetailLabels` s registrací a záznamem
  v `ViewerDetailLabelsCatalogTest::EXPECTED`.
- **Popisek sloupce jen při přesné shodě češtiny.** Kde se český text liší
  i jen formou („Splatnost“ × „Datum splatnosti“, „Částka“ × „Částka (měna
  dokladu)“, „Pořadí v tvrzení“ × „Pořadí“), šel 1 : 1 do katalogu —
  čeština se tak nemění nikde. Na `columnLabel()` jde 120 textů.
- **Angličtina změněná převzetím popisku sloupce** (čeština shodná):
  účty saldokonta a tabulka účtů skupiny „Side“ → „Account side“; bankovní
  transakce „Variable symbol“ → „Payment reference“; deník „Error line“ →
  „Error row“; pravidla štítků „Last hit“ → „Last hit at“.
- **Plurály přeformulované** (Podání DPH): „Doklady: 27 / 27 řádků“ →
  „Doklady / řádky: 27 / 27“; „9 řádků · základ … · daň …“ → „Řádků: 9 ·
  základ … · daň …“; „5 rozdílů proti deníku“ → „Rozdíly proti deníku: 5“;
  „3 plnění · …“ → „Plnění: 3 · …“ (česky se neskloňuje, anglické
  „1 supplies“ ano). Anglicky stejně („Rows: 9 · …“).
- **Podání DPH — nic z konfigurace podání:** krátké popisky řádků 62–66
  ani sloty Základ / V plné výši / Krácený v `modules/economy/vat/config/`
  nejsou (`vat-reports-cz.jsonc` má jen dlouhé názvy řádků) → katalog
  vieweru. Názvy tabů jsou v nové sekci `tabs` katalogu
  (`detailTabLabel()`); mapy `SLOT_LABELS` a `LINE_KIND_LABELS` nahradily
  metody s `match` a literálními klíči (dají se staticky zkontrolovat proti
  katalogu).
- **Existující zdroje (krok 1):** `LedgerViewer` Předpis / Úhrada z výčtu
  `economy.accbal.balSides`; akce Upozornění Zkontrolovat znovu / Vrátit do
  aktivních z `core.alerts.viewerDefaults` → `rowActions` (`unsnooze`
  doplněn); „(bez předmětu)“ Odeslané pošty z existujícího klíče
  `noSubject`.
- **Sdílené texty:** do `core.system.viewerDetailLabels` přibylo pět textů,
  které se opakují ve třech modulech a nejsou popiskem sloupce — Částky
  (`groupAmounts`), Datum, Stav („State“), Osoba a krátké VS
  („Reference“). Texty jen pro Uživatele (bez hesla, Lokální heslo…) jsou
  v témže katalogu, protože je to katalog modulu `core.system`.
  **Nesdílené, i když jsou ve třech a více modulech:** MD / DAL
  („Debit“ / „Credit“ — accounting, assets a workOrders už od F1, nově bank
  a docs.core) a „Stav“ / „Status“ (mail, ai, assets od F1, nově exchange).
  F1 je nechal v katalozích modulů; stěhování starších klíčů je mimo rozsah.
- **`!== 'en'` × `=== 'cs'`:** banka a DPH měly `$cs = $this->language
  !== 'en'` — čeština pro každý jiný jazyk než angličtinu. Katalog dává
  pro neznámý jazyk angličtinu. Rozhraní má jen `cs` a `en`.
- **Privátní `enumLabel()`** (pět viewerů, dvě signatury) se
  nesjednocovala — F2 ji nepotřeboval.
- **Test:** hledání podmínky jazyka bere interpolovaný řetězec jako celek
  (dřív ho ukončila složená závorka `{$date}` — druhou větev
  `$cs ? "podáno {$d}" : "filed {$d}"` test neviděl). Porušením je každý
  literál s písmenem, tedy i `$cs ? 'cs' : 'en'` — kód jazyka se z podmínky
  jazyka ve vieweru skládat nemá. Nad zdroji před převodem hlásí 832
  literálů ve 21 viewerech.
- **Testy viewerů** berou texty ze skutečných zdrojů: nový helper
  `tests/Fixtures/Core/Viewer/ViewerTexts` (katalogy a definice tabulek ze
  zdrojových jsonc, lokalizované jako při kompilaci). Očekávané texty se
  nezměnily; upraven i integrační `tests/Integration/Accbal/CasesViewerTest`.
  Trvalý test shody fallbacku s holým `name` nepřibyl (jako v F1) —
  jednorázová kontrola 747 volání prošla, dva starší nálezy mimo rozsah
  (`AssetsViewer` `text.notActivated`, složený literál `unlockConfirm`).
- **Dvojjazyčný zápis mimo viewery** (mimo rozsah, tokenizerem): 62
  souborů, 446 literálů v ternáru a 32 pod klíči jazyka — kontroly
  upozornění 32 souborů (216 literálů), buildery reportů a export 18
  (216), `SetupController` 18, `NavigationController` 12, XML a období DPH
  6 souborů (10), `ActiveUsersOptions`, `PeriodLabel`
  a `DashboardSummaryService` po 2.
- **Dokumentace navíc:** `docs/frontend.md` (lokalizace textů z backendu).

## Ověření při implementaci

- `php -l` na změněných souborech; celá sada `--testsuite Unit` (11 350
  testů) prošla po posledním commitu; integrační `tests/Integration/Bank`
  a `tests/Integration/Accbal` prošly.
- **Výstup beze změny:** snímek výstupu všech 58 viewerů (meta, řádky
  seznamu, mřížka s patičkou, detail až šesti záznamů) před převodem a po
  něm na volných zdrojích `zey0-g` a `nham-q` po `ds-upgrade`, v `cs`
  i `en`. Čeština: jen čtyři plurály Podání DPH. Angličtina: navíc tři
  převzaté popisky sloupců, které se na datech ukázaly (Side, Variable
  symbol, Last hit); „Error line“ se neukázal — na volných zdrojích není
  chybový řádek deníku.
- **Podání DPH** na volných zdrojích nebyla — na `zey0-g` jsou pro ověření
  sestavená tři (`vat-filing-compose`: přiznání, kontrolní a souhrnné
  hlášení za 01/2026); zůstanou tam do příštího seedu zdroje.
- **Prohlížeč** (Playwright, `zey0-g`, `cs` i `en`): seznam, detail a taby
  u Podání DPH (všechna tři), Pohledávek, Saldo pohybů, Bankovních výpisů
  a transakcí, Účetního deníku, Faktur přijatých (tab Zaúčtování)
  a v Nastavení u Skupin a Účtů saldokonta a Pravidel štítků — bez chyby
  stránky a bez odpovědi 5xx.
- **Jen testy a `php -l`** (bez dat na volných zdrojích): Odesílatelé,
  Odeslaná pošta, Upozornění; u Podání DPH taby Rozdíly, Rozdíly importu
  a Zprávy a akce podaného podání (není předchozí, importované ani podané
  podání). Uživatelé jsou ověření snímkem, v navigaci Nastavení na `zey0-g`
  nejsou.
- **`hosting.core`** na vývojovém zdroji není: kontrola volání proti
  katalogu a porovnání výstupu tříd před převodem a po něm v procesu nad
  zdrojovými katalogy (`cs`, `en`, bez konfigurace) — 78 porovnání bez
  rozdílu.

## Ruční ověření (2026-11-12)

Firefox, dev zdroj `lh6x` (po `ds-upgrade`), čeština i angličtina —
v pořádku: Podání DPH (seznam, detail, taby, přeformulované plurály),
saldokonto (případy, saldo pohybů, skupiny a účty v Nastavení), banka
(výpisy, transakce), účetní deník a tab Zaúčtování faktury, Uživatelé,
Upozornění; v angličtině „Account side“ a „Payment reference“.

Bez dat na `lh6x`: Pravidla štítků („Last hit at“), Odesílatelé, Odeslaná
pošta, akce Vrátit do aktivních (žádné vyřešené upozornění), chybný řádek
deníku („Error row“).

Nálezy v angličtině mimo rozsah F2:

- **Upozornění — tlačítko „Otevřít doklad“** česky: popisek akce vzniká
  v kontrole při jejím běhu a ukládá se u upozornění (dvojjazyčný zápis
  v `*Check.php`, viz Odchylky — mimo viewery) → #154.
- **Sidebar česky** — ověřeno: po přepnutí i po obnovení stránky je
  navigace anglicky, česky jsou jen skupiny saldokonta (data, viz níže).
- **Skupiny saldokonta v navigaci** („Pohledávky“, „Zál. faktury vyd.“…) —
  názvy jsou data zdroje (seed `balancesDefault.cz.jsonc`, upravitelné
  uživatelem), v angličtině zůstávají česky jako ostatní data.
