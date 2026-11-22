# Task: Pojistka před přestavbou ukládání dokladů — matice round-tripu

**Stav:** naplánováno — zadání 2026-11-21 (#166 etapa 1)

**Issue:** #166 (etapa 1; navazuje #164, #159)

**Cíl:** Než se začne měnit `DocumentApplier` (#166 etapy 3 a 5), vznikne
integrační test, který hlídá invariant „co uloží `DocDocument`, to výměnný
formát přenese beze změny“: doklad uložený přes `TableGateway` → export
(`DocumentExporter`) → apply (`DocumentApplier`) → druhý uložený doklad se
musí shodovat ve všech sloupcích, které nejsou identitou ani auditem.
Případy, kde to dnes neplatí, test zapíše jako **známé mezery** s přesnou
příčinou — etapa 3 je pak zavírá jednu po druhé a test jí řekne, že je
zavřela.

Task **nemění chování aplikace** — jen testy, jejich pomocné třídy
a dokumentaci.

## Před implementací přečti

- issue #166 (cílový model, D1–D14, etapy)
- `docs/exchange-format.md` — § 3, § 5 („Pole `vatRecap` a `totals`“),
  § 10 (apply pipeline, „Apply a doc state“ — `importNumber`), § 8.4 jen
  pro orientaci, co applier odvozuje
- `docs/datasets.md` — § 4 „Round-trip a co formát nenese“ (vědomé
  normalizace — jsou to první kandidáti na výjimky porovnání)
- `docs/vat-calculation.md` — § 1.1, § 5, § 5.2, § 6
- `tests/Integration/README.md`, `tests/Integration/IntegrationTestCase.php`
- vzory integračních testů nad dokladem:
  `tests/Integration/Docs/TotalRoundingAutoGatewayTest.php` (sestavení
  `TableGateway` pro `docs_core_heads`),
  `tests/Integration/Docs/CorrectiveDocGatewayTest.php`,
  `tests/Integration/Exchange/DocumentImportSeriesStatesTest.php`
  (sestavení `DocumentApplier`, `importNumber`, úklid),
  `tests/Integration/Dataset/DatasetRoundTripTest.php`
- `modules/core/exchange/src/Export/DocumentExporter.php` — celý
- `modules/core/exchange/src/Dataset/Verify/ApplyFidelityCheck.php` —
  vzor „výjimky s důvodem, další nepřidávat bez schválení“
- `modules/core/exchange/src/Document/DocumentApplier.php` — `transform()`
  (~ř. 1612), `vatContext()` (~ř. 2019), `decideRowVatCode()` (~ř. 2744),
  `deriveTotalRoundingMode()` (~ř. 4630); jen číst
- `modules/docs/core/tables/docs_core_heads.jsonc`, `docs_core_rows.jsonc`,
  `docs_core_vat_recap.jsonc`

## Kontext

- Formulář ukládá přes `FormController` → `TableGateway` → `DocDocument`.
  Výměnný formát jde přes `DocumentApplier`, který doklad před uložením
  znovu posuzuje (místo plnění z DIČ dodavatele, kód DPH ze signálů, režim
  DPH ze součtů, mód zaokrouhlení z částek). Import a datové sady proto
  některé doklady, které jdou pořídit formulářem, neuloží nebo je uloží
  jinak.
- Známý případ: přijatý doklad Bez DPH od dodavatele s DIČ jiného státu,
  jehož řádky nesou objekt `vat` bez kódu, končí v importním módu chybou
  `vat_code_unknown`.
- Round-trip datové sady (`DatasetRoundTripTest`, `dataset-verify`) hlídá
  sadu jako celek a papírovou projekci návrhů z pošty. Nehlídá, že se
  **každý sloupec** dokladu vrátí stejný.
- Výchozí hodnoty, které doplňuje jen formulář (předvyplnění nového
  dokladu), sem nepatří — sjednocuje je etapa 4. Proto test zakládá doklad
  přes `TableGateway`, ne přes `FormController`.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **Invariant** — co projde formulářem (`TableGateway` + `DocDocument`),
  projde výměnným formátem a naopak; etapa 1 ho zapíše jako test.
- ✓ **D5** — importní mód nese jen historii; výjimky z validace nemá.
  Tenhle task ji neodstraňuje, jen ji zachytí jako mezeru, pokud se
  v matici projeví.
- ✓ **D4** — mód zaokrouhlení má jít říct výslovně; dnes se odhaduje.
  Zachytit jako mezeru.

## Upřesnění pro implementaci

- **U1 — Kde test žije.** `tests/Integration/Exchange/DocumentRoundTripMatrixTest.php`
  (suite Integration, živý zdroj dat ze `SHIPARD_INTEGRATION_DS_PATH`).
  Přeskočí se (`markTestSkipped`) na zdroji bez vlastní firmy, bez
  registrace DPH nebo bez aktivních řad potřebných typů — jako stávající
  integrační testy.
- **U2 — Jeden průchod.**
  1. Založ doklad A přes `TableGateway::saveDocument('docs_core_heads')`
     z interních dat případu (hlavička, řádky, případně `vatRecap`).
  2. Načti A z databáze (hlavička, řádky, rekapitulace).
  3. Exportuj A (`DocumentExporter`).
  4. V exportu změň jen to, co by kolidovalo: `applyOptions.importNumber`
     dostane jiné číslo s `sequenceNumber: null` (čítač řady se nehne);
     nic dalšího se neupravuje.
  5. Apply (`DocumentApplier::apply`) → doklad B; načti B.
  6. Porovnej A a B (U3).
- **U3 — Co se porovnává.** Všechny sloupce `docs_core_heads`,
  `docs_core_rows` a `docs_core_vat_recap` **podle definice tabulek**, ne
  podle ručního seznamu — nový sloupec se do porovnání dostane sám.
  Vyloučené sloupce jsou v jedné konstantě `column → důvod`:
  - identita a audit (`id`, cizí klíč na hlavičku, `doc_number`,
    `sequence_number`, časy vzniku a změny),
  - původ (`source_*`),
  - co plyne ze změny v kroku 4.

  Čísla porovnávej na počet desetinných míst sloupce. Řádky páruj podle
  `order_pos`, rekapitulaci podle klíče skupiny (kód DPH, sazba, předmět
  plnění, datum sazby, příznak oddaňovacího páru).
  Unit test hlídá, že každý vyloučený sloupec v definici tabulky existuje
  (seznam nesmí zastarat).
  **Další výjimku nepřidávej bez schválení** — sloupec, který se liší
  a není identita, je nález (U5).
- **U4 — Matice případů.** Data provider, každý případ má id, popis
  a očekávání `holds` | `gap`. Minimálně:

  | id | doklad |
  |---|---|
  | `issued-base` | faktura vydaná, tuzemsko, Ze základu, dvě sazby |
  | `issued-total` | faktura vydaná, Z celkové částky |
  | `issued-cash-rounding` | faktura vydaná hrazená hotově, automatické zaokrouhlení |
  | `issued-rounding-manual` | faktura vydaná, automatika vypnutá, ručně zvolený mód zaokrouhlení |
  | `issued-corrective` | opravný daňový doklad (příznak, blok `correction`) |
  | `issued-foreign-currency` | faktura vydaná v cizí měně s kurzem na dokladu |
  | `received-declared-recap` | faktura přijatá, tuzemsko, převzatá rekapitulace s haléřovým rozdílem proti řádkům |
  | `received-eu-services` | faktura přijatá, služba z EU, samovyměření |
  | `received-domestic-rc` | faktura přijatá, tuzemské přenesení s předmětem plnění |
  | `received-non-payer` | faktura přijatá Bez DPH od tuzemského neplátce |
  | `received-no-vat-foreign-id` | faktura přijatá Bez DPH, dodavatel s DIČ jiného státu |
  | `received-out-of-scope-row` | faktura přijatá s DPH a řádkem `cz-000` „Mimo DPH“, převzatá rekapitulace |
  | `received-rate-dates` | faktura přijatá, datum sazby na řádcích (`vat_rate_source` 2), dvě data |
  | `received-dimensions` | faktura přijatá se střediskem na hlavičce i řádku (jen když zdroj dimenzi má) |
  | `accounting-doc` | účetní doklad s kontačními řádky |
  | `cash-doc` | pokladní doklad, příjem (jen když zdroj má pokladnu) |

  Každý případ ve stavu V pořádku (40); u `issued-base`
  a `received-declared-recap` navíc varianta v Konceptu (10).
  Partneři, položky a číselníky se zakládají s prefixem `IT-RT` a po testu
  mažou; zahraniční dodavatel má DIČ s prefixem jiného členského státu.
  Částky a názvy syntetické.
- **U5 — Známá mezera.** Případ s očekáváním `gap` nese **přesný popis
  rozdílu**: buď kód chyby apply (např. `vat_code_unknown`), nebo seznam
  `sloupec → (A, B)`. Test u něj tvrdí, že rozdíl je **přesně tenhle**:
  - rozdíl zmizel → test selže hláškou „mezera `<id>` je zavřená —
    přepni na `holds`“;
  - rozdíl je jiný → test selže s oběma verzemi.

  Očekávání `gap` nastav podle skutečného běhu, neodhaduj ho. Předem se
  čeká: `received-no-vat-foreign-id` (`vat_code_unknown`)
  a `issued-rounding-manual` (mód zaokrouhlení). Co dalšího vyjde, zapiš.
- **U6 — Žádná oprava applieru.** Když případ selže, neopravuj kód ani
  export. Výjimkou je chyba v samotném testu.
- **U7 — Pomocné třídy** patří do `tests/` (stavba případů, načtení
  dokladu, porovnání). Do `modules/` se nic nepřidává.

## Co je potřeba udělat

### Commit 1 — kostra testu a případy, které drží

1. Pomocné třídy podle U2, U3, U7 + unit test seznamu vyloučených sloupců.
2. `DocumentRoundTripMatrixTest` s maticí U4; případy, které projdou,
   jako `holds`.
3. `vendor/bin/phpunit --testsuite Integration --filter 'DocumentRoundTripMatrixTest'`
   proti ukázkovému zdroji dat s třídou dat `free`.

### Commit 2 — známé mezery

1. Případy, které neprošly, přepni na `gap` s přesným rozdílem (U5).
2. Do sekce „Poznámky k implementaci“ tohoto tasku sepiš tabulku mezer:
   id, rozdíl, příčina v kódu (soubor a metoda), která etapa #166 ji
   zavírá.

### Commit 3 — dokumentace a stav

1. `tests/Integration/README.md` — odstavec o testu (co hlídá, jak číst
   `gap`).
2. `docs/exchange-format.md` § 10 — krátký oddíl „Invariant ukládání“:
   znění invariantu, odkaz na test a na #166; seznam mezer **ne** (žije
   v testu).
3. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`)

- Před taskem i po něm `dataset-verify` veřejných sad: výsledky se
  nesmí změnit. Výsledek zapiš do poznámek — je to výchozí stav pro
  etapy 3 a 5.

## Mimo rozsah

- Jakákoli změna `DocumentApplier`, `DocDocument`, exporteru nebo schématu.
- Cesta z pošty (návrhy, volby uživatele) — hlídá ji `dataset-verify`
  a stávající unit testy applieru.
- Výchozí hodnoty formuláře (etapa 4), reference přirozeným klíčem
  (`tasks/exchange-key-references.md`).
- Import ze starého Shipardu.

## Pasti

- Integrační suite běží proti sdílenému zdroji dat — úklid podle prefixu
  `IT-RT` i při pádu testu (`onTearDown`), čítače řad se nesmí pohnout
  (`sequenceNumber: null`; doklad A zakládej tak, aby po úklidu nezůstala
  díra v řadě — viz jak to řeší `DocumentImportSeriesStatesTest`).
- Doklad ve stavu 40 se zaúčtuje; úklid musí smazat i řádky deníku
  a saldokonta, jak to dělají stávající testy.
- Export nenese vlastní stranu ani interní id; partnera druhý průchod
  dohledá podle IČO / DIČ — testovací partner je proto musí mít
  jednoznačné.
- `vat_pct` se neukládá ze vstupu, vždy se odvodí z kódu a data sazby —
  případy stav sazeb k datu nesmí předpokládat natvrdo, vezmi kódy
  a sazby z konfigurace zdroje.
- Nespouštěj celou integrační suite bez potřeby; filtruj na třídu testu.

## Hotovo když

- [ ] `DocumentRoundTripMatrixTest` běží proti ukázkovému zdroji, všechny
      případy matice U4 jsou `holds` nebo `gap` s přesným rozdílem
- [ ] porovnání bere sloupce z definic tabulek; vyloučené sloupce mají
      důvod a hlídá je unit test
- [ ] tabulka mezer je v poznámkách tasku (do #166 ji po ověření
      přenese Claude v chatu)
- [ ] `dataset-verify` veřejných sad beze změny, výsledek v poznámkách
- [ ] žádná změna v `modules/` ani `src/`
- [ ] `docs/exchange-format.md`, `tests/Integration/README.md`, stav tasku
