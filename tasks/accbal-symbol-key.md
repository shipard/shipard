# Saldokonto — párovací klíč místo alokací (odstranění matcheru, pohled po případech)

**Stav:** hotovo — 2026-09-14 (#69 T2; odchylky od zadání viz „Poznámky k implementaci"); zbývá alfa (reset + reimport DS, `ds-upgrade`) a ruční proklik UI

## Kontext

Issue #69 (komentář s D1–D8 z 2026-09-12) mění saldokonto z alokačního
modelu na **párovací symbol**. T1 přesunul rozhodnutí „311 vs. clearing"
do bankovního enginu (`OpenItemLookup`, `ClearingRouter`); matcher od té doby
nikdo nevolá. T2 dokončuje D1:

- saldokontní **případ** = klíč `(saldokonto, partner, VS, SS, měna)`, stav
  případu = Σ předpisy − Σ úhrady z ledgeru (deník je jediný zdroj pravdy),
- vrstva alokací (tabulka 419, `AllocationPlanner`, `BalanceMatcher`,
  `MatchResult`/`MatchSummary`, config `allocationOrigins`) **se maže**,
- UI dostane pohled **po případech** (kdo kolik dluží, co je otevřené),
- `docs/accbal.md` se přepíše na nový model.

Žádná změna účtování ani enginů — T2 je odstraňování + agregace + UI + docs.

## Návaznost

- **Staví na:** T1 (`OpenItemLookup`, `ClearingRouter`, `ClearingRerouteHandler`,
  kontrakt `/_accbal/match` v2). Nic z toho se nemění.
- **Nahrazuje:** rozhodnutí #5, #9, #16, #17 v `docs/accbal.md` §10 (pohyb vs.
  párování, odvozený bucket, FIFO/VS signál, dvě vrstvy rozpárování). #13–#15
  nahradil už T1.
- **Následuje:** T3 `bank-effective-symbols` (originál/efektivní symboly,
  pravidla dohledání 2–3 z D5), T5 průvodci oprav interními doklady (D6).
- **Migrace tabulky 419 se neřeší** — DS na alfě se resetují a importují znovu
  (#69). `ds-upgrade` tabulky nedropuje (`table-definitions.md` §10): na
  existujícím DS zůstane osiřelá, nový DS ji nedostane. **tableId 419
  nerecyklovat** (poznámka do `docs/table-definitions.md` / registru id).

## Před implementací přečti

- Issue #69 — komentář s D1–D8; `tasks/bank-payment-routing.md` vč. „Poznámky
  k implementaci" (proč je routing reziduální a co dělá `excludeSource`).
- `docs/accbal.md` celý — přepisuješ ho; §3.3 (ledger), §4 (generátor,
  stabilní klíč), §6 (měny), §7 (období) zůstávají platné a přebírají se.
- `docs/viewer-grid.md` §7.4 (grid se skupinami, footer) a
  `tasks/accbal-ledger-grid.md`, `accbal-ledger-viewgroup-chips.md` — vzor
  pro pohled po případech.
- `modules/economy/accbal/src/LedgerViewer.php` (`RESIDUAL_SQL`, footer),
  `LedgerGenerator.php` (cascade na allocations), `AccbalSourceCleanupHandler.php`,
  `LedgerOpenItemLookup.php` (agregace klíče — stejná definice, kterou tu
  zobecníš).
- `tests/Integration/Accbal/CashPaymentMatchingTest.php` — přepisuje se na
  agregát případu.

## 1. Případ jako agregát (D1)

Případ se **nemodeluje jako entita** ani jako sloupec. Definice na jednom
místě — třída `CaseQuery` (nebo rozšíření `LedgerOpenItemLookup`), kterou
sdílí lookup, viewer případů i footer ledgeru:

```
klíč     = (balance, fiscal_year, partner, payment_reference, specific_symbol, currency)
předpisy = Σ amount   WHERE bal_side = 0      (+ amount_hc)
úhrady   = Σ amount   WHERE bal_side = 1      (+ amount_hc)
zůstatek = předpisy − úhrady                  (obchodní: měna dokladu;
                                               účetní: domácí, §6 accbal.md)
otevřený = zůstatek ≠ 0   (kladný = dluží, záporný = přeplatek / úhrada bez předpisu)
```

- **Normalizace klíče při zápisu (D10):** `LedgerGenerator` ukládá symboly
  `TRIM`nuté, prázdné jako `NULL`, měnu malými písmeny — rovnost klíče pak jde
  přes index bez `TRIM/COALESCE` ve `WHERE`. `LedgerOpenItemLookup` po tomto
  kroku zjednoduš (porovnání `= / IS NULL`). Nový index
  `idx_case (balance, fiscal_year, partner, payment_reference, specific_symbol, currency)`
  — složený index nad sloupci rovnosti; žádný uložený „hash klíč" (bodové
  dohledání stejně rychlé, prefixové dotazy partner/období navíc).
  `idx_bucket` ponechat.
- Splatnost případu = `MIN(due_date)` otevřených předpisů klíče; dny po
  splatnosti se počítají k dnešku (jen v UI, nic se neukládá).
- **`fiscal_year` je součást klíče (D11)** — případ žije v rámci účetního
  období, zůstatky přenáší otevírací sekvence dokladů (`accbal.md` §7;
  ze starého Shipardu přijdou importem). Sloupec na ledgeru už je; do klíče
  vstupuje jen v n-tici a indexu. Lookup (`OpenItemLookup`) se zúží na
  období účetního data transakce — doplň parametr / odvození v enginu.

## 2. Odstranění alokační vrstvy

Smazat (grep `allocation|BalanceMatcher|AllocationPlan|MatchResult|MatchSummary`
s `--include=*.php,*.jsonc,*.md` přes `modules/economy/accbal`, `src`,
`tests`, `docs`):

- `tables/economy_accbal_allocations.jsonc`, řádek v `module.jsonc`
  (`tables`, `settingsItems`, cfgItem `allocationOrigins`), `config/allocationOrigins.jsonc`,
- `AllocationPlanner`, `AllocationPlan`, `BalanceMatcher`, `MatchResult`,
  `MatchSummary` (ověř, že `RouteSummary` z T1 na nich nezávisí),
- cascade na allocations v `LedgerGenerator` (delete pohybů zdroje) a
  v `AccbalSourceCleanupHandler` (zůstane jen úklid ledgeru),
- `RESIDUAL_SQL` v `LedgerViewer` (§3),
- testy `BalanceMatcherTest`, `AllocationPlannerTest`; `CashPaymentMatchingTest`
  přepsat: po zaúčtování PD je zůstatek případu (partner, VS) = 0, žádný
  matcher; `CashDocumentImportTest` — zkontrolovat, co z alokací asertuje.
- `MCP`: ověř `docs/mcp-server.md` a `modules/*/src/**/Mcp*` grepem, zda žádný
  nástroj nečte 419 (index tasků zmiňuje `mcp-server-02-read-tools`,
  `mcp-server-04-aggregate`).

CLI `accbal-match` a endpoint zůstávají jak je nechal T1.

## 3. UI

### 3.1 Nový viewer `economy.accbal.cases` — „Saldokonto" (po případech)

Read-only, vlastní `selectRows` s `GROUP BY` klíče (vzor `LedgerViewer`
s custom SQL). Jeden řádek = případ.

- **ViewGroups** = saldokonta (chipy jako u ledgeru, identita `code`).
- **Sloupce:** partner, VS, SS, měna, předpisy, úhrady, **zůstatek** (měna
  dokladu), zůstatek HC, splatnost (nejstarší otevřený předpis), po
  splatnosti (dní, jen kladný zůstatek), počet pohybů.
- **Filtry:** partner, VS, jen otevřené (default **zapnuto**), po splatnosti,
  **typ otevřenosti**: dluh / přeplatek / úhrada bez předpisu (na reimportovaném
  DS je úhrad bez předpisu z pokladních a interních dokladů ~3 000 případů —
  musí být samostatně filtrovatelné, ne schované mezi přeplatky).
- **Grid** se skupinami per partner (`viewer-grid.md` §7.4) — skupinový řádek
  nese součet zůstatku partnera; **footer**: Σ předpisy / Σ úhrady / Σ
  zůstatek v HC pro aktuální filtr (řádky přes všechny stránky).
- **Akce řádku:** „Pohyby případu" → otevře ledger viewer s filtrem na klíč
  (partner + VS + SS + skupina); zvýraznit záporný zůstatek (přeplatek)
  odlišně od kladného (dluh) — design-system doc-state konvence, ne nová
  barva.
- Navigace: v sidebaru vedle „Saldo pohyby" (`accbal-nav-items.md` —
  navigation provider); viewer případů je **výchozí** vstup do saldokonta,
  pohyby jsou detail.

### 3.2 `LedgerViewer` (pohyby)

- Sloupec „Zbývá" (per pohyb) zrušit — u pohybu nemá v symbolovém modelu
  význam. Místo něj zůstatek **případu** ve skupinovém řádku nebo jako
  sloupec (stejná hodnota na všech pohybech klíče) — zvol podle
  `viewer-grid.md`, ať nepřibývá druhá definice.
- Přidat sloupec SS; filtry rozšířit o SS a filtr „jen otevřené" přepnout na
  otevřenost **případu** (pohyb je otevřený, když je otevřený jeho případ).
- Přijmout filtr z akce „Pohyby případu" (§3.1).

Frontend: pokud viewer framework agregovaný viewer zvládne bez změn
(`LedgerViewer` už dělá custom SQL a grid), Svelte se nemění. Když ne,
minimální doplněk a zápis do `docs/viewer-grid.md`.

## 4. Dokumentace — přepis `docs/accbal.md`

Celý dokument přepsat na nový model; zachovat čísla sekcí, kde obsah platí
(§3.1–§3.3, §3.5–§3.6, §4, §6, §7), aby odkazy z jiných docs nevyhnily.
Nová osnova:

1. Motivace a princip — párovací symbol, případ = agregát, proč ne alokace
   (odstavec z komentáře #69: druhý zdroj pravdy, vysvětlitelnost,
   srovnatelnost se starým systémem). Odkaz na starý Saldo2 zůstává, ale
   jako *přebraný* princip, ne odmítnutý.
2. Architektura — schéma bez vrstvy allocations; routing v enginu
   (`OpenItemLookup`, `bank.md` §6.1), trigger (`ClearingRerouteHandler`).
3. Datový model — ledger (beze změny), případ jako agregát (§1 tohoto tasku),
   normalizace klíče, indexy; §3.4 allocations **odstranit** (ponechat
   nadpis „§3.4 — zrušeno, viz #69 D1", ať odkazy nevedou do prázdna).
4. Generování pohybů — beze změny.
5. Routing a přeúčtování clearingu — přenést z T1 (§5.1 kontrakt s enginem,
   §5.2 trigger, §5.7 API v2); pravidla dohledání D5 s poznámkou, která platí
   dnes (1) a která přijdou s T3 (2–3).
6. Měny — dvojí uzavření případu (obchodní/účetní) — beze změny principu,
   přepsat z allocations na agregát.
7. Období — beze změny.
8. Opravy salda — **jen interními doklady** (D6), pořadí oprav (symboly na
   transakci → na předpisu → doklad); průvodci jsou T5, sem jen princip.
9. Mimo scope — zálohy, zápočty, kurzové rozdíly, otevírací doklady,
   opakované platby (T4), efektivní symboly (T3).
10. Fáze — 0–3 historicky hotové, **revize #69** T1–T6 s odkazy na tasky.
11. Log rozhodnutí — #1–#18 ponechat; #5, #9, #13–#17 označit
    „**nahrazeno** #69 D…"; doplnit #19–#2x = D1–D10 z #69 (s odkazem na
    komentář issue) a D9 (§ Rozhodnutí níže).
12. Otevřené body — partner resolution, výkon, generátor otevíracích dokladů.

Dále: `docs/README.md` řádek accbal (dnes tvrdí „+ allocations, matcher
v přípravě"), `docs/bank.md` §6 (odkaz na případ místo bucketu), `CLAUDE.md`
tabulka docs, pokud accbal zmiňuje; `help/` pokud existuje stránka o saldu
(`grep -rl saldokont help/`).

## 5. Testy

PHPUnit jen s úzkým `--filter`.

- Unit `CaseQueryTest`: agregát klíče; prázdný SS ≠ vyplněný; přeplatek =
  záporný zůstatek; `MIN(due_date)` jen z otevřených předpisů; normalizace
  (`' 123 '` = `'123'`, `''` = `NULL`, `CZK` = `czk`).
- Integrace `LedgerOpenItemLookupTest` — po zjednodušení projde beze změny
  chování (stejné případy jako v T1).
- `CashPaymentMatchingTest` → přejmenovat (`CashPaymentCaseTest`): zůstatek
  případu 0 po zaúčtování PD.
- Viewer případů: test `selectRows` + footer nad fixturou se dvěma partnery,
  třemi klíči, jedním přeplatkem (vzor testů `LedgerViewer`, pokud existují;
  jinak integrační test nad DB).
- `BankPaymentRoutingTest` z T1 musí projít beze změny.

## Commit strategie

1. `CaseQuery` + normalizace klíče v generátoru + index + zjednodušení
   lookupu + unit testy.
2. Odstranění alokační vrstvy (tabulka, třídy, config, cascade, testy),
   přepis `CashPaymentMatchingTest`.
3. Viewer případů + úpravy `LedgerViewer` + navigace + testy.
4. Přepis `docs/accbal.md` + `docs/README.md` + `bank.md` + hlavička tasku
   `**Stav:** hotovo` + `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `grep -rn "allocation" modules/economy/accbal src tests docs --include=*.php,*.jsonc,*.md`
      najde jen historické tasky v `tasks/`, poznámku „zrušeno" v `accbal.md`
      a řádek vyřazeného ID v `table-definitions.md`
- [x] tabulka 419 není v definicích modulu; tableId 419 označen jako
      nerecyklovatelný (`table-definitions.md`, sekce `tableId`)
- [x] viewer případů ukazuje otevřené případy po skupinách, footer sedí na
      Σ ledgeru pro stejný filtr (`CasesViewerTest::testFooterMatchesLedgerSumsForSameFilter`)
- [x] `accbal-match --all --dry-run` a `BankPaymentRoutingTest` beze změny
      chování (seedy dostaly `fiscal_year`, přibyl scénář D11)
- [x] `docs/accbal.md` popisuje symbolový model; #5, #9, #13–#17 označena
      jako nahrazená; `docs/README.md` řádek aktuální
- [x] docs a index tasků ve stejném commitu jako kód

## Rozhodnutí k designu (potvrzená)

- ✓ **D1** případ = klíč `(saldokonto, partner, VS, SS, měna)`, agregát
  z ledgeru, žádná entita ani sloupec (#69).
- ✓ **D10** normalizace klíče při zápisu ledgeru (trim, prázdné = NULL,
  měna lowercase) — rovnost přes index.
- ✓ **D11** `fiscal_year` v klíči případu i v lookupu (období účetního
  data transakce). Platba v novém období za předpis ze starého spadne na
  clearing a přeúčtuje se, jakmile se zaúčtuje otevírací doklad — je to
  `doc`, takže projde `ClearingRerouteHandler` bez zvláštní cesty.
  `BankPaymentRoutingTest` doplnit o tento scénář.
- ✓ **D9** routing zůstává **reziduální** (T1 — platba se přeúčtuje jen když
  má klíč kladný zůstatek; přeplatek zůstává na clearingu jako signál dle
  D5/4), ne existenční (vše s klíčem na 311, přeplatek jako záporný zůstatek).
  Potvrzeno 2026-09-13; zapsat do `accbal.md` §11.

## Poznámky k implementaci (2026-09-14)

Commity `f2d134e` (PRD), `1e3b85f` (1/n CaseQuery + normalizace + období
v lookupu), `ae72cfd` (2/n odstranění alokační vrstvy), `3cbb904` (3/n viewer
případů + navigace + filtry v `open_viewer`), docs 4/n.

Odchylky od zadání, všechny ověřené v kódu a testech:

- **`OpenItemLookup::findOpenRequest` má nový parametr `?int $fiscalYear`**
  hned za `$direction` (před vyloučením zdroje); `null` = bez klíče → miss
  (jako prázdný VS). Dotčené: `NullOpenItemLookup`, `LedgerOpenItemLookup`,
  test doubly v `OpenItemLookupLoaderTest` a `ClearingRouterTest`, volající
  engine (`$fiscalYear` z účetního data transakce) a `ClearingRouter`
  (období clearingového pohybu). `ClearingRerouteHandler` staví klíče přes
  `CaseQuery::normalizeKey` vč. období.
- **Lookup zůstává užší než případ:** dál počítá jen řádky na předpisových
  účtech skupiny (T1 testy, dobropisové řádky mimo hru); s `CaseQuery` sdílí
  klíč a normalizaci (`keyConditions`), ne filtr účtů. Zapsáno v `accbal.md`
  §3.4 a §5.6.
- **Seedy integračních testů potřebují `fiscal_year`** — bez něj by lookup
  po D11 minul. `BankPaymentRoutingTest`, `LedgerOpenItemLookupTest`,
  `CashPaymentCaseTest` (přejmenovaný `CashPaymentMatchingTest`) ho
  odvozují z fiskálního roku účetního data; scénář D11 používá druhý rok
  z číselníku (4l3j má 2026 i 2027), jinak syntetické id.
- **`CaseQueryTest` je dvojí:** unit (normalizace, tvar SQL, klasifikace,
  dny po splatnosti) + integrační (agregát nad 4l3j: SS prázdný ≠ vyplněný,
  přeplatek, úhrada bez předpisu, `MIN(due_date)` jen z předpisů, období
  odděluje, `keyOfRow` round-trip).
- **Zůstatek případu na pohybu korelovaným subdotazem, ne joinem:** LEFT
  JOIN na derived `GROUP BY` s `<=>` v ON vrací v MariaDB 10.11 při
  optimalizaci `split_materialized` NULL u bodového dotazu (`WHERE l.id = ?`,
  detail pohybu); volba splitu je cost-based, takže by se chyba projevila
  nepředvídatelně i v seznamu. `CaseQuery::residualSubquerySql` = bodové
  dohledání přes `idx_case` per řádek (`balance` obyčejnou rovností, zbytek
  `<=>`). Ověřeno přímo v SQL i HTTP smokem.
- **„Jen otevřené" výchozí přes obrácený checkbox „Včetně uzavřených"** —
  `ViewerFilters` nemá výchozí hodnoty filtrů. Typ `Uzavřeno` ve filtru Typ
  uzavřené zahrne sám.
- **Součet partnera ve skupinovém řádku jde do labelu** (`ViewerGrid` umí ve
  skupinovém řádku jen text): okno `SUM(residual_hc) OVER (PARTITION BY
  partner)` po WHERE, tedy přes filtrovaný set a přes hranici stránek.
  Svelte grid beze změny.
- **Jediná FE změna:** `open_viewer` nese volitelně `viewGroup` a `filters`,
  `navigation.svelte.js` má jednorázový `pendingFilters` (vzor
  `pendingViewGroup`), `Viewer.svelte` je převezme do `activeFilters` a
  prvního fetche; `Dashboard` a `SectionCards` průchod doplněn. Zapsáno
  v `docs/frontend.md` a `docs/dashboard.md`.
- **„Pohyby případu" předává viditelné filtry** (chip saldokonta, partner
  jménem, VS a SS prefixově) — žádný skrytý exaktní filtr klíče, uživatel
  vidí a může uvolnit; období se do pohybů nepředává (pohyby klíče přes
  roky jsou v detailu záměrně pohromadě).
- **Id řádku případu = `MIN(id)` pohybů klíče** (`row_id`): `ViewerController`
  před detailem dělá `SELECT * FROM tabulka WHERE id` — pohyb existuje,
  detail z něj klíč odvodí (`CaseQuery::keyOfRow`). Bez změny controlleru.
- **Navigace:** Saldokonto (případy) `navOrder` 31 hned za Bankovními
  transakcemi (30), položky saldokont z provideru 32+, Saldo pohyby 39,
  Výpisy 40 (původně by Bankovní transakce seděly uprostřed bloku).
  `NavigationControllerTest` a `BalancesNavigationProviderTest` upraveny.
- **Sdílený `AccbalViewerBase`** (viewGroups, formátování, popisky typů,
  `getDefaultLayout` grid, prázdný toolbar) — `LedgerViewer` i `CasesViewer`.
- **Grep kritérium:** slovo „allocation" bylo i v anglickém komentáři
  `NextTableIdCommand.php` (nesouvisí) — přeformulováno.
- **Dev DS 4l3j:** `ds-upgrade` proběhl (index `idx_case`), ledger už byl
  normalizovaný (import z doby po D10 nebyl potřeba). Alfa: reset + reimport
  dle #69; na DS z doby před T2 zůstane tabulka 419 osiřelá.

## Otevřené body

- **Otevírací doklady období** — generátor v novém Shipardu zatím není
  (samostatný task, do M3); do té doby je na importovaném DS zdrojem
  otevíracích předpisů import ze starého Shipardu. Ověřit, že importovaný
  otevírací doklad nese partnera + VS/SS na řádcích (jinak D11 nemá co
  párovat).
- Výkon `GROUP BY` nad ledgerem pro tisíce partnerů — měřit na
  importovaném DS po nasazení; případně materializovat zůstatky až podle
  čísel, ne předem.
