# Přehled firmy — obrazovka „jak na tom firma je“ (v1)

**Stav:** hotovo — implementováno a ověřeno prokliknutím na dev serveru 2026-10-16 (#120 D1–D20); odchylky od zadání v `docs/overview.md` §8

## Kontext / Cíl

Dashboard odpovídá na otázku „co je potřeba udělat“. Na otázku „jak na tom
firma je“ dnes jedna odpověď není — čísla jsou rozesetá po saldokontu,
bankovních výpisech, výsledovce, živém přiznání DPH a deníku. Tento task
přidává samostatnou obrazovku **Přehled firmy**, která výsledky
existujících výpočtů skládá do pěti bloků: Peníze, Pohledávky, Závazky,
DPH, Výsledek hospodaření. Každé číslo je proklik do zdroje a u každého
zůstatku je vidět, k jakému datu platí.

Obrazovka je **stav, ne fronta práce** — akce a upozornění zůstávají na
Dashboardu (#127).

## Návaznost

- **#120** — design issue, rozhodnutí D1–D19 (komentáře s rozhodnutími v issue)
- **#127** — upozornění na pohledávky po splatnosti a splatné závazky na
  Dashboardu; odložené, na tomto tasku nezávisí (D11)
- **#121** — kurzy ČNB; do té doby žádný přepočet měn (D4)
- `docs/reports.md` §2 (jeden výpočet, N prezentací), §16.5 (odkazy `link`)
- `docs/accbal.md` §3.4 (případ), §6 (měny), §7 (účetní období a otevírací
  sekvence)

## Před implementací přečti

- `docs/dashboard.md` §1 (vztah k feedu), §5 (texty z katalogu modulu)
- `docs/reports.md` §2, §16.4–16.5; `src/Core/Reports/ReportRunner.php`,
  `ReportParamValidator.php` (parametry `fiscalYear`, `monthFrom`, `monthTo`,
  `period`), `FiscalPeriodProvider::yearForDate()`
- `docs/accbal.md` §3.4, §6, §7; `modules/economy/accbal/src/CaseQuery.php`,
  `CasesViewer.php` (`buildConditions()`, `renderGridFooter()`)
- `modules/economy/accounting/src/Reports/ProfitLossBuilder.php`,
  `GeneralLedgerBuilder.php`, `JournalReportSupport.php`
- `modules/economy/vat/src/Reports/VatReturnLiveBuilder.php`,
  `src/Core/Reports/DbReportPeriodProvider.php`
- `src/Api/Controller/NavigationController.php` (panely v hlavní navigaci,
  `isItemForbidden()`), `src/Api/Controller/DsAboutController.php` (vzor
  read-only agregačního endpointu), `src/Api/ReadOnlyPolicy.php`
- `src/Core/Module/ModuleDefinition.php` + `src/Core/Config/ConfigCompiler.php`
  (vzor registrace `workOrderInvoiceContributors` → cfgItem)
- `src/Core/Feed/FeedTexts.php` (texty z katalogu, konstruktor s id cfgItemu)
- `frontend/src/components/layout/ContentArea.svelte` (mapa panelů),
  `frontend/src/components/reports/ReportsPage.svelte` (obsluha akcí
  `open_detail` / `open_viewer` / `open_report`),
  `frontend/src/components/dashboard/FeedReadySection.svelte` (`formatAmount`)

## Rozsah

**V rozsahu:** registrace bloků v `module.jsonc`, sběr a endpoint
`GET /_ui/overview`, pět bloků podle D5–D8, pojistka počátečních stavů (D6),
termín podání DPH (D7), úpravy vieweru případů saldokonta pro proklik
(filtry měny a splatnosti, patička v měně), panel v navigaci jen pro adminy
(D10), frontend obrazovka, dokumentace (`docs/overview.md`, `help/`).

**Mimo rozsah:** pruh na Dashboardu (D1), nástroj pro Chat / MCP (D2 —
pozdější renderer nad stejným výsledkem), obraty po zakázkách (D9),
upozornění (D11, #127), termíny Spisovny a K vyřízení (D13), přepočet měn
(D4, #121), kontrola výpis × deník, práva pro ne-adminy (D10).

## Rozhodnutí k designu (potvrzená, #120)

- ✓ **D1** Samostatná obrazovka, root položka navigace vedle Dashboardu;
  landing zůstává Dashboard; pruh nad feedem ve v1 ne.
- ✓ **D2** Jeden endpoint skládá bloky z existujících výpočtů; jediný nový
  výpočet je termín DPH (D7).
- ✓ **D3** Každé číslo je proklik do zdroje; u každého zůstatku datum, ke
  kterému platí.
- ✓ **D4** Měny per měna, domácí první; žádný součet přes měny, žádný
  přepočet kurzem.
- ✓ **D5** Peníze: banka = koncový zůstatek posledního výpisu per účet
  s datem výpisu; pokladna = zůstatek účtu pokladny z deníku, „podle
  zaúčtovaných dokladů“, s datem posledního zápisu.
- ✓ **D6** Pohledávky a závazky ze saldokonta za běžné účetní období;
  chybí-li otevírací sekvence a předchozí období má otevřené případy, blok
  místo čísla řekne, že počáteční stavy nejsou převedené. Závazky: po
  splatnosti + splatné do 7 dní.
- ✓ **D7** DPH: živé přiznání + termín podání z pravidla země; neplátce
  blok nevidí.
- ✓ **D8** Výsledek hospodaření od začátku účetního období z výsledovky.
- ✓ **D9** Obraty po zakázkách ve v1 ne.
- ✓ **D10** Jen pro adminy; práva pro ostatní později.
- ✓ **D11** Upozornění na pohledávky a závazky patří na Dashboard → #127.
- ✓ **D12** Mimo milníky roadmapy.
- ✓ **D13** Termíny Spisovny a K vyřízení ve v1 ne.
- ✓ **D14** Datum výpisu vždy viditelné; varování, je-li poslední výpis
  starší než 35 dní.

- ✓ **D15** Pojistka počátečních stavů jednou kontrolou
  `OpeningSequenceStatus` pro Pokladnu, Pohledávky i Závazky: běžné období
  nemá zápisy deníku v otevíracím měsíci a předchozí období zápisy má.
  Upřesňuje D6 (sdílené, o něco hrubší kritérium než „předchozí období má
  otevřené případy“) a rozšiřuje ho na pokladnu.
- ✓ **D16** DPH dvěma řádky: předchozí období, dokud k němu není podání
  (vlastní daň / nadměrný odpočet + termín), a běžné období průběžně.
- ✓ **D17** Termín DPH = 25. den po skončení období s posunem na pracovní
  den; kalendář státních svátků ČR v `world.cz` (pevná data + Velký pátek
  a Velikonoční pondělí).
- ✓ **D18** U Pohledávek a Závazků poznámka s počtem a součtem otevřených
  případů skupiny Nespárované platby a proklikem.
- ✓ **D19** Ze saldokonta jen skupiny `receivables` a `payables` (zálohové
  faktury, zálohy, půjčky a úvěry ve v1 ne); cizoměnová pokladna v měně
  pokladny ze sloupců `money_dr_cur` / `money_cr_cur` deníku.

## Kontrakt

`GET /_ui/overview` → `200`:

```json
{
  "asOf": "2026-10-16",
  "sections": [
    {
      "id": "money",
      "title": "Peníze",
      "items": [
        {
          "id": "bank:3",
          "label": "Běžný účet",
          "kind": "bank",
          "amount": { "value": 125000.00, "currency": "CZK" },
          "asOf": "2026-10-15",
          "state": "ok",
          "note": "Podle výpisu č. 182",
          "link": { "kind": "open_detail", "target": { "viewerId": "economy.bank.statements", "recordId": 812 } }
        }
      ],
      "messages": [
        { "severity": "warning", "code": "openingMissing", "text": "…" }
      ],
      "state": "ok"
    }
  ]
}
```

- `sections[]` v pořadí z cfgItemu `core.overview.sections`; prázdná sekce
  (žádný příspěvek bloku) se neposílá.
- `section.state`: `ok` | `unavailable` (pojistka D6 / D15 — místo položek
  jen zpráva) | `error` (blok selhal, viz Pasti).
- `item.state`: `ok` | `stale` (D14) | `missing` (účet bez výpisu).
- `amount.value` je číslo v plné přesnosti, formátuje frontend jednou
  sdílenou utilitou (krok 7). Položka v jiné měně je samostatná položka
  (D4), domácí měna první.
- `link` má tvar akcí reportů (`docs/reports.md` §16.5): `open_detail`,
  `open_viewer`, `open_report`.
- `401` bez přihlášení, `403 FORBIDDEN` pro ne-admina (D10 — `adminOnly`
  na panelu je jen úklid navigace, ne bariéra).

Texty sekcí, položek a zpráv jdou ze serveru z katalogu modulu, který blok
vyrábí (`*.overviewTexts`, čtení přes `FeedTexts` s vlastním id cfgItemu,
fallback anglicky v PHP).

## Kroky

Commit po každém kroku.

### 1. Jádro: registrace bloků, sběr, endpoint

- `module.jsonc` → nový klíč `overviewBlocks: [{id, section, order, class}]`.
  `ModuleDefinition` (validace jako u `recordSenderProviders`),
  `ConfigCompiler` složí cfgItem `core.overview.blocks` z aktivních modulů
  (duplicitní `id` = chyba, rezervovaný cfgItem jako u
  `workOrderInvoiceContributors`).
- cfgItem `core.overview.sections` v `core.system` (id, pořadí, název
  `:cs` / `:en`): `money`, `receivables`, `payables`, `vat`, `result`.
- `src/Core/Overview/`: rozhraní `OverviewBlock`
  (`isAvailable(OverviewContext): bool` — klíčová tabulka na DS,
  `collect(OverviewContext): OverviewBlockResult`), `OverviewContext`
  (db, config, language, `today`, `ReportRunner`), `OverviewBlockResult`
  (items, messages, state), `OverviewCollector` (pořadí sekcí → `order`
  bloku, **izolace per blok**: výjimka → sekce `error` + log, ostatní bloky
  běží dál), `OverviewController::index()` + routa `GET /_ui/overview` +
  `ReadOnlyPolicy` Allow.
- Testy: kompilace registrace (duplicita, chybějící třída), collector
  (pořadí, izolace výjimky, prázdná sekce vynechaná, `isAvailable` false),
  controller 401 / 403 / 200.

### 2. Peníze — banka (`economy.bank`)

- Blok `BankBalancesBlock` (sekce `money`, order 10): per platný bankovní
  účet (`economy_codebooks_bank_accounts`, `valid_to` prázdné nebo ≥ dnes,
  `docState` ≠ 90) poslední výpis podle `period_end` (`docState` ≠ 90) →
  `closing_balance`, `currency`, `asOf = period_end`.
- `state`: `stale`, je-li `period_end` starší než 35 dní před `today` (D14);
  `missing`, když účet výpis nemá. Datum se posílá vždy.
- `link`: `open_detail` výpisu; u `missing` `open_viewer` výpisů.
- Testy: výběr posledního výpisu, práh 35 dní (hranice), účet bez výpisu,
  neplatný účet vynechán, cizí měna jako vlastní položka.

### 3. Peníze — pokladna (`economy.accounting`)

- Kontrola `OpeningSequenceStatus` (D15): běžné účetní období podle
  `yearForDate(today)`; „chybí počáteční stavy“ = běžné období nemá zápisy
  deníku v otevíracím měsíci (`fiscal_months.period_type` 0) a předchozí
  období zápisy má. První rok firmy (bez předchozího období) varování nedává.
  Sdílí ji bloky pokladny i saldokonta.
- Blok `CashBalancesBlock` (sekce `money`, order 20): per platná pokladna
  s `accounting_account` → zůstatek účtu v běžném období z hlavní knihy
  (`ReportRunner` → `economy.accounting.generalLedger`, sloupec `closing`,
  řádek účtu); cizoměnová pokladna podle D19. `asOf` = datum posledního
  zápisu deníku na účtu, `note` „podle zaúčtovaných dokladů“ (D5).
- Pojistka: chybí počáteční stavy → položky pokladny nahradí zpráva
  `openingMissing`; bankovní položky v sekci zůstávají.
- `link`: `open_viewer` deníku (`economy.accounting.journal`, filtry
  `account`, `fiscal_year`).
- Testy: zůstatek = hlavní kniha pro týž účet a období, pojistka (běžné bez
  otevření + předchozí se zápisy / první rok), pokladna bez účtu vynechána.

### 4. Pohledávky a závazky (`economy.accbal`)

- `CaseTotals` (nová třída vedle `CaseQuery`): součty otevřených dluhů
  skupiny podle `code` per měna dokladu — `residual` a počet; volitelně jen
  po splatnosti / splatné do N dní. **Patička vieweru případů ji používá
  taky** — jeden výpočet.
- `CasesViewer`: nové filtry `currency` a `dueWithin` (počet dní, dluh se
  splatností v intervalu ⟨dnes, dnes + N⟩); s filtrem `currency` ukazuje
  patička součty v této měně (`residual`), bez něj dál v domácí měně
  (`residual_hc`).
- Bloky `ReceivablesBlock` (sekce `receivables`) a `PayablesBlock` (sekce
  `payables`), jen skupiny `receivables` / `payables` (D19), běžné účetní
  období (D6). Pohledávky: celkem + po splatnosti. Závazky: po splatnosti +
  splatné do 7 dní (D6). Per měna, domácí první.
- Pojistka D6 přes `OpeningSequenceStatus` (krok 3) → sekce `unavailable`
  se zprávou `openingMissing`.
- Nespárované platby podle D18: zpráva s počtem a součtem otevřených případů
  skupiny `unmatched_payments`, proklik.
- `link`: `open_viewer` `economy.accbal.cases` s `viewGroup` = kód skupiny
  a filtry `fiscal_year`, `currency`, `kind=debt`, `overdue=1` /
  `dueWithin=7` — **číslo po prokliku musí sedět s patičkou vieweru**.
- Testy: `CaseTotals` per měna (CZK + EUR, obchodní zůstatek), hranice
  splatnosti (dnes, dnes + 7), shoda bloku a patičky pro tytéž filtry,
  pojistka, filtr `currency` v patičce.

### 5. Výsledek hospodaření (`economy.accounting`)

- `ProfitLossBuilder`: dopočtený řádek výsledku dostane `key: "result"`
  (§16.4) — blok **nehledá řádek podle popisku**. Pokud rollup nese součty
  tříd 5 a 6, dostanou `key` `costs` / `revenues`; jinak blok ukazuje jen
  výsledek.
- Blok `ProfitLossBlock` (sekce `result`): `ReportRunner` →
  `economy.accounting.profitLoss`, `fiscalYear` = běžné období, `monthFrom`
  = `monthTo` = běžný měsíc, hodnota ze sloupce `ytd` (D8). Zprávy reportu
  se severity `error` (chybové masky) se propisují do sekce.
- `link`: `open_report` výsledovky se stejnými parametry.
- Testy: hodnota bloku = `ytd` řádku `result` reportu, klíč řádku
  v `ProfitLossBuilderTest`, období bez běžných měsíců.

### 6. DPH (`economy.vat` + `world.cz`)

- Termín podání: `VatFilingDeadline` pro zemi `cz` — 25. den po skončení
  období, posun na pracovní den podle D17. Země zdroje dat podle
  `docs/reports.md` §16.2; pro jinou zemi bez pravidla se termín neposílá.
- Blok `VatBlock` (sekce `vat`): neplátce (`economy.vatAgenda`) → blok
  nedostupný, sekce se neposílá (D7). Období podle D16 z instancí
  `economy_vat_report_periods` typu přiznání; hodnota z
  `economy.vat.returnLive` (`ReportRunner`, `period`) — řádek s `account`
  `64` (vlastní daň) nebo `65` (nadměrný odpočet), sloupec `tax`.
- `link`: `open_report` `economy.vat.returnLive` s `period`.
- Testy: termíny (měsíční i čtvrtletní období, víkend, svátek podle D17),
  výběr období, neplátce, řádek 64 vs. 65 vs. nula.

### 7. Frontend a navigace

- Panel `companyOverview` v `economy.accounting` `module.jsonc` → `panels[]`:
  `navSection: "_top"`, `navOrder: 22` (mezi Dashboard 20 a Chat 25),
  `adminOnly: true`, ikona podle významu v `icons.js`.
- `CompanyOverview.svelte` v mapě panelů `ContentArea.svelte`,
  `api/overview.js`. Sekce jako karty, položka = popisek, částka, datum,
  stav (`stale` / `missing` zvýrazněné), poznámka. Prokliky jako v
  `ReportsPage` (`ViewerDetailModal`, `navigateToViewer`,
  `navigateToReport`).
- `formatAmount` z `FeedReadySection.svelte` vytáhnout do sdílené utility
  (`utils/money.js`) a používat na obou místech.
- Na frontendu jen texty rozhraní (nadpis obrazovky, prázdný stav, chyba
  načtení) v `i18n/cs.js` + `en.js`; `npm run check:i18n`.
- Ověřit ve všech třech shellech (classic, sidebar, wild).

### 8. Dokumentace

- `docs/overview.md` — registrace bloků, kontrakt, pravidla (D1–D19),
  jak přidat blok; řádek v tabulce `CLAUDE.md`.
- `docs/accbal.md` — `CaseTotals`, filtry `currency` / `dueWithin`,
  patička v měně.
- `help/` — stránka o Přehledu firmy (co které číslo znamená, k jakému datu
  platí, proč může blok hlásit nepřevedené počáteční stavy); pravidla
  `docs/help-authoring.md`, `python3 scripts/help-index.py`.
- Tento task: `**Stav:**`, `python3 scripts/tasks-index.py`.

## Stav implementace (2026-10-16)

Kroky 1–8 hotové v jedné pracovní sadě (bez průběžných commitů — commit
až po ověření na dev serveru). Odchylky od zadání, které vyplynuly
z kódu, jsou vypsané v `docs/overview.md` §8 (pokladna přímým `SUM` nad
deníkem místo reportu hlavní knihy, „podání“ = stav Podáno, `dueDate`
v kontraktu, `formatMoney` v `utils/formatNumber.js`, tabulka pokladen
`economy_codebooks_cash_desks`, registr pravidel termínu bez
`class_exists`, chybějící třída bloku = warn + skip). Výkon dotazu
`CaseTotals` na zdroji s reálnou kopií: jde po `idx_case`, jednotky ms
(ledger tam má stovky řádků — měření na větším DS zbývá). Integrační sada
má 5 selhání nesouvisejících s touto prací (`AnalysisRunnerIntegrationTest`,
`DocPrintBuilderTest`, `DocJournalPrintTest` — stejná na čistém HEAD).

## Pasti

- **`adminOnly` na panelu není serverová bariéra** (`CLAUDE.md` →
  Navigace). Controller musí ne-adminovi vrátit 403 sám.
- **Dva zůstatky případu.** `residual` je v měně dokladu (obchodní),
  `residual_hc` v domácí měně v kurzu zaúčtování. Přehled per měna bere
  `residual`; patička vieweru dnes bere `residual_hc` — bez kroku 4 by
  proklik ukázal jiné číslo.
- **`fiscal_year` je součást klíče případu.** Součet přes všechna období
  po převodu počátečních stavů zdvojí dluh; jen běžné období v lednu před
  převodem ukáže skoro nulu. Proto pojistka, žádné „chytré“ sčítání.
- **Popisky řádků reportů jsou lokalizované** — řádky hledat podle `key` /
  `account`, nikdy podle textu.
- **Izolace bloků.** Výjimka jednoho bloku nesmí shodit obrazovku (vzor
  `FeedCollector`); zalogovat a vrátit sekci `error`.
- **DS bez modulu.** Blok, jehož klíčová tabulka na DS není, se neregistruje
  do výsledku (`isAvailable`), bez chyby.
- **Smazané záznamy.** `docState` 90 vynechat u výpisů, účtů, pokladen
  i instancí období DPH.
- **Výkon.** `GROUP BY` nad ledgerem (`docs/accbal.md` §13) změřit na
  zdroji dat s reálnou kopií — jen čtení přes read-only uživatele.
- **JS řetězce** jen s ASCII uvozovkami.
- **Žádný další výpočet.** Kromě termínu DPH nic nového; chybí-li zdroji
  číslo, blok ho neukáže, nedopočítá ho.

## Hotovo když

- [x] `GET /_ui/overview` vrací sekce podle kontraktu; ne-admin 403,
      nepřihlášený 401, read-only DS 200.
- [x] Peníze: poslední výpis per účet s datem, varování nad 35 dní, účet bez
      výpisu; pokladna z deníku s poznámkou a datem.
- [x] Pohledávky a závazky per měna; po prokliku sedí číslo s patičkou
      vieweru případů.
- [ ] Pojistka počátečních stavů ověřená testem i na datech (běžné období
      bez otevření). Testem ano (`OpeningSequenceStatusTest`,
      `CashBalancesBlockTest`, `ReceivablesBlockTest`); na dev zdrojích dat
      se situace nevyskytuje (předchozí období bez zápisů → bez varování),
      ověření na datech zbývá na zdroji s převedeným rokem.
- [x] DPH s termínem podání; neplátce sekci nevidí.
- [x] Výsledek hospodaření = `ytd` výsledovky, proklik do reportu.
- [x] Výjimka jednoho bloku neshodí ostatní.
- [ ] Panel v navigaci jen pro adminy, mezi Dashboardem a Chatem, ve všech
      shellech. Serverově ověřeno testem (`NavigationControllerTest`);
      vizuální kontrola tří shellů zbývá.
- [x] `php -l`, cílené PHPUnit testy, celá sada, `npm run build`,
      `npm run check:i18n`.
- [x] `docs/overview.md`, `docs/accbal.md`, `CLAUDE.md`, `help/` ve stejných
      commitech jako funkce.
- [x] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py`.
