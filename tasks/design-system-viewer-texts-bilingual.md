# Design system — dvojjazyčné texty viewerů z katalogů (D65–D67, F2)

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady, D65, D66, D67)

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

- [ ] Ve viewerech není dvojjazyčný zápis s textem (ternár jazyka ani pole
      `'cs'` / `'en'`)
- [ ] Texty jdou z existujícího zdroje nebo katalogu podle D66
- [ ] `ViewerSourceTextsTest` bez výjimky; dvojjazyčný zápis s textem
      hlásí jako porušení
- [ ] Výstup v `cs` i `en` beze změny (kromě vypsaných plurálů a převzatých
      textů)
- [ ] Dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
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
