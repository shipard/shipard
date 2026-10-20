# Testování: kurzy a ISDOC v datových sadách, evaluace zpráv s ISDOC

**Stav:** naplánováno — design schválen 2026-10-20 (#103 D72–D77), čeká na implementaci

## Kontext / Cíl

#121 (kurzy cizích měn) a #119 fáze 1 (generátor ISDOC) odemkly dvě
místa testování, která se zatím obcházela:

- **F3 přeskakuje doklady v cizí měně** (#103 D30): u plátce 6 zpráv
  (varianty 5, 6, 8), u neplátce a spolku po 2 (varianta 6). Sady nemají
  kurzovní lístky a očekávání DPH a nákladů jsou jen v měně dokladu.
  `docs/exchange-rates.md` § 7 to výslovně nechává na samostatný task (U6).
- **Sady nenesou ISDOC.** Build korpusu ho vyrábí pro šest tuzemských
  dokladů podoby PDF + ISDOC na profil (#119 D10: `0102`, `0202`, `0302`,
  `0402`, `0702`, `1002`), ale do sad se nedostal (#119 O1). Evaluace AI
  (F4) by navíc ISDOC poslala modelu jako text, zatímco produkce zprávu
  s ISDOC zpracuje deterministicky (`IsdocImportService`) bez AI extrakce.

**Cíl:**

1. Kurzy v korpusu a sadách (D72). Očekávání v Kč počítá generátor
   sám (D73).
2. `dataset-verify` bez přeskakování cizí měny. Snímky deníku se znovu
   schválí (D74).
3. ISDOC jako příloha zpráv v sadách (D75).
4. Evaluace zpráv s ISDOC produkční cestou (D76).
5. Nový běh `baseline` nad novými sadami (D77).

## Návaznost

- **#103** D72–D77 (oddíl na konci); D5, D14, D28–D30, D34–D41
- **#121** — `docs/exchange-rates.md` (formát lístku § 2, resolver, kurz
  k účetnímu datu D5, applier doplní chybějící kurz D6, sady § 7)
- **#119** — `docs/einvoice.md` (§ 8 korpus a round-trip, výjimky round-tripu),
  `tasks/einvoice-phase1.md` (D10, O1)
- **#123** — účetní revize; nové snímky se schvalují se stejnou výhradou
- `tasks/testing-phase1.md` až `testing-phase4b.md`, `docs/test-corpus.md`

## Před implementací přečti

- `docs/exchange-rates.md` § 2, § 5 (resolver: který lístek platí pro datum),
  § 7, § 9 (služba `https://data.shipard.app/feed/money-exchange-rates`)
- `docs/einvoice.md` § 8 (round-trip, výjimky)
- `docs/test-corpus.md` — generátor, `vat.json`, `costs.json`, sady, evaluace
- `tools/invoice-generator/invoicegen/` (`compute.py`, `expected.py`, `dataset.py`)
- `modules/core/exchange/src/Dataset/Verify/` (přeskočení cizí měny D30)
- `modules/core/mail/src/IsdocImportService.php`,
  `modules/core/mail/src/Preprocess/PreprocessRunner.php`,
  `modules/core/mail/src/Analysis/AnalysisComputation.php`,
  `modules/core/mail/src/Analysis/AttachmentPreparer.php`, `modules/core/mail/src/Eval/`

## 1. Kurzy v korpusu (D72)

- **Zdroj:** skutečné denní kurzovní lístky ČNB (`listType` 1) ze služby
  kurzů. Stahují se jednou a commitují do `tests/corpus/cz/exchange-rates/`
  ve formátu `shpd.exchangeRates.list.v1`, jeden soubor na lístek.
- **Které dny:** lístky, které resolver použije pro účetní data dokladů
  korpusu v cizí měně (§ 2), a k nim lístky z předchozího pracovního dne
  jako rezerva pro pravidlo platnosti. Seznam dnů vypočti z dat korpusu,
  ne ručně.
- **Stažení:** jednorázově (skript v `tools/invoice-generator/` nebo
  `shpd-server` příkaz, který vypíše lístky za dny do souborů, ověř, co
  už #121 nabízí). Build lístky jen čte a síť nepotřebuje.
- **Do sad:** build zapíše do každé sady sekci `exchangeRates/` jen
  s lístky, které potřebují doklady té sady (neplátce a spolek jen
  varianta 6). Formát a pojmenování podle `docs/datasets.md`.

## 2. Očekávání v Kč (D73)

Generátor přepočítává sám (D5), nezávisle na resolveru Shipardu:

- **Datum kurzu = účetní datum dokladu.** Pro přijaté doklady korpusu
  je to DUZP, a pokud ho doklad nemá (služba z USA), datum vystavení.
  U intrakomunitárních plnění je DUZP a účetní datum v praxi shodné.
- **Lístek pro datum:** podle pravidla platnosti z `docs/exchange-rates.md`
  § 5, implementovaného v generátoru znovu. Unit test generátoru i krok
  ověření porovnají jeho kurz s `shpd-ds exchange-rate <měna> <datum>`
  na naseedovaném zdroji dat.
- **Částky:** `Decimal`, zaokrouhlení na haléře:
  - základ v Kč = základ v cizí měně × kurz (per sazba),
  - daň v Kč u samovyměření = základ v Kč × sazba,
  - u DPH dodavatele (varianta 6 u neplátců) celkem v Kč = celkem
    v cizí měně × kurz.
- **`vat.json`:** řádky přiznání a oddíl KH A.2 v Kč (dnes v měně dokladu).
  **`costs.json`:** náklad v Kč, pole `currency` zůstane a uvede původní měnu.
- Pokud Shipard dá doklad do jiného účetního data nebo vezme jiný lístek,
  je to nález. **Očekávání se kvůli tomu neupravuje, zastav se a popiš rozdíl.**

## 3. `dataset-verify` bez přeskakování (D74)

- Přeskočení dokladů v cizí měně (#103 D30) se zruší. Kurz doplní applier
  z lístku sady (#121 D6).
- Kontroly 1–4 musí projít na všech třech profilech včetně dokladů
  v cizí měně.
- **Snímky deníku** všech tří profilů se vyrobí znovu
  (`--write-ledger-snapshot`) a **zastavíš se kvůli schválení**: vypiš
  obraty po účtech a rozdíl proti dnešním snímkům (nové doklady v cizí
  měně). Commit až po odsouhlasení, s výhradou #123.

## 4. ISDOC v sadách (D75)

- Zprávy podoby PDF + ISDOC dostanou soubor `.isdoc` z `tests/corpus/cz/out/<profil>/`
  jako **druhou přílohu** (`order` 2, MIME podle `IsdocImportService`).
  Ideální analýza (`analyses[0]`) zůstává beze změny.
- Kontrola v buildu: každý doklad korpusu s `.isdoc` má zprávu s druhou
  přílohou a žádná jiná zpráva ISDOC nemá.
- F2 a F3 se tím nemění. Seed nespouští předzpracování, protože zpráva
  je ve stavu Analyzováno.

## 5. Evaluace zpráv s ISDOC (D76)

- **Produkční cesta:** zprávu, u které by produkční předzpracování ISDOC
  rozpoznalo (`IsdocImportService::detect()`), evaluace nanečisto
  nezpracuje AI extrakcí. Udělá deterministický import ISDOC → canonical,
  pak validaci a obohacení včetně AI klasifikace štítků, stejně jako
  produkce. Nic nezapíše: vytáhni z `IsdocImportService` čistou část
  (ISDOC → canonical) podobně jako `AnalysisComputation` z runneru. Ostrý
  import jede přes ni beze změny chování.
- **Porovnání:** stejný porovnávač a projekce. Pole, která ISDOC
  z principu nenese (výjimky round-tripu v `docs/einvoice.md` § 8), se
  u cesty ISDOC nepočítají jako odchylky. Mají vlastní stav
  `notCarried` s důvodem a seznam výjimek má **jediný zdroj** sdílený
  round-trip testem a evaluací.
- **Metriky:** nový rozpad `byPath` (`ai`, `isdoc`) vedle `byVariant`
  a `byForm`. Volání cesty ISDOC nese jen cenu štítků. Prohlížeč (F6a)
  ukáže cestu u dokladu a rozpad v detailu běhu.
- **Pojistka:** `AttachmentPreparer` u zpráv cesty AI ISDOC nedostane
  (zprávy s ISDOC jdou cestou ISDOC). Test ověří, že se ISDOC modelu
  jako text neposílá.

## 6. Nový základní běh (D77)

Po přestavbě sad se změní hash, takže dosavadní běhy zůstanou historií.
Na evaluačním zdroji dat (přeseedovat sadou plátce) **`baseline` × 3**
s odhadem ceny (~3 USD; doklady s ISDOC stojí jen štítky). **Pouštěj až
po schválení v chatu.** Výsledek commitni a stručně vyhodnoť v tasku:
celkové metriky a rozpad podle cesty. U cesty ISDOC čekáme téměř 100 %
mimo štítky. Každá věcná odchylka čtečky je nález pro #119.

## Kroky

1. Lístky v korpusu (D72), build sekce `exchangeRates/` v sadách.
2. Generátor: přepočet do Kč (D73), `vat.json`, `costs.json`; unit testy.
3. ISDOC jako příloha v sadách (D75), kontrola v buildu.
4. `dataset-verify` bez přeskočení (D74); ověření na třech volných zdrojích
   dat (přeseedovat), shoda kurzu generátoru s resolverem; nové snímky
   → **zastavit se**.
5. Evaluace cestou ISDOC (D76): čistý import, `notCarried`, `byPath`,
   prohlížeč; unit testy s falešným LLM.
6. Běh `baseline` × 3 (D77) po schválení útraty.
7. Dokumentace (`docs/test-corpus.md`, `docs/exchange-rates.md` § 7,
   `docs/einvoice.md` § 8 — O1 vyřešené), hlavička tasku.

## Hotovo když

- [ ] lístky v `tests/corpus/cz/exchange-rates/`, sady mají `exchangeRates/`;
      build idempotentní
- [ ] `vat.json` a `costs.json` v Kč; kurz generátoru = výsledek
      `shpd-ds exchange-rate` pro každý doklad v cizí měně
- [ ] `dataset-verify` na třech profilech: kontroly 1–4 `ok`, nic
      nepřeskočeno; nové snímky schválené a commitnuté
- [ ] sady: šest zpráv na profil s ISDOC jako druhou přílohou
- [ ] evaluace: zprávy s ISDOC cestou ISDOC (bez AI extrakce), `notCarried`,
      `byPath`; ISDOC se modelu jako text neposílá (test)
- [ ] `baseline` × 3 nad novou sadou plátce, vyhodnocení v tasku
- [ ] dokumentace, hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Očekávání se neupravuje podle Shipardu** (datum, lístek, zaokrouhlení).
- **Síť jen při stažení lístků**, build a seed jsou offline.
- **Výjimky round-tripu mají jediný zdroj.** Nekopíruj seznam do evaluace.
- **Útrata a schválení snímků:** dvě zastavení, nic z toho bez souhlasu.

## Rozhodnutí k designu (potvrzená)

- ✓ **D72 — Skutečné kurzy ČNB pro dny korpusu** stažené jednou ze služby
  a commitnuté v `tests/corpus/cz/exchange-rates/`; sady nesou jen lístky,
  které potřebují.
- ✓ **D73 — Očekávání v Kč počítá generátor** kurzem k účetnímu datu
  (DUZP, bez něj datum vystavení) podle pravidla platnosti lístku, nezávisle
  na resolveru.
- ✓ **D74 — `dataset-verify` přestane přeskakovat cizí měnu** (konec D30);
  snímky deníku se znovu schválí s výhradou #123.
- ✓ **D75 — Sady nesou ISDOC** jako druhou přílohu zpráv podoby PDF + ISDOC;
  ideální analýza beze změny (řeší #119 O1).
- ✓ **D76 — Evaluace zpráv s ISDOC jde produkční cestou** (deterministický
  import + AI klasifikace štítků, bez AI extrakce); pole, která ISDOC nenese,
  jako `notCarried`; metriky po cestách.
- ✓ **D77 — Nový běh `baseline` × 3** nad novými sadami po schválení útraty;
  dosavadní běhy zůstávají historií.

### Doplněná při implementaci (2026-10-20)

- ✓ **D78 — Účetní datum přijaté faktury = DUZP**, bez DUZP datum vystavení
  (ostatní typy datum vystavení). Shipard i starý Shipard dávaly datum
  vystavení — s D73 by u 0501/0502/0601/0602 vyšel jiný lístek. Jedno
  pravidlo `AccountingDateRule` (docs.core) pro `DocDocument::applyDateDefaults`,
  `DocumentApplier` (účetní datum i datum kurzu) a `ExchangeRatesExporter`
  (návrhy, wiring closure v `DatasetDumpCommand`).
- ✓ **D80 — Samovyměřená daň v domácí měně = základ v domácí měně × sazba.**
  Shipard počítal daň v měně dokladu, zaokrouhlil a přepočetl — na 0501 o 7
  haléřů víc než D73. Jen oddaňovací páry a jen při kurzu ≠ 1
  (`DocDocument::buildVatRecapitulation`, `takeOverVatRecapitulation`);
  dodavatelská DPH v cizí měně beze změny (`docs/vat-calculation.md`).
- ✓ **D79 — Výjimka věrnosti `exchangeRate` + kontrola Kurzy.** Projekce
  porovnání nese `exchangeRate`, papír ho neuvádí (`null`), applier ho doplní
  z lístku — výjimka `ApplyFidelityCheck::EXCEPTIONS_FOREIGN_CURRENCY` jen
  u dokladu v cizí měně; hodnotu i účetní datum hlídá nová kontrola 5
  `ExchangeRateCheck` (economy.exchangeRates) proti
  `expected/exchange-rates.json` (`{messageId, currency, rateDate, listType,
  validFrom, rate}` z generátoru) a proti `ExchangeRateResolver` k témuž datu.
  Kurz do očekávání korpusu nejde (applier by ho převzal a resolver by se
  neověřil; evaluace AI by čekala kurz, který na papíře není).
- ✓ **D81 — Lístky v sadách a hash.** Sada nese použité i rezervní lístky
  svých dokladů v tvaru exporteru (měny seřazené, jen měny
  z `world.base.currencies`, bez `validTo` null); dump naseedovaného zdroje
  dat dá jen použité lístky — byte-shoda s dumpem se pro `exchangeRates/`
  neslibuje. `EvalRunStore::HASHED_DIRS` zahrnuje `exchangeRates/`, takže
  zdroj dat seedovaný bez lístků je zastaralá sada.
- ✓ **D82 — Evaluace cestou ISDOC.** `IsdocDocumentCollector` (čistý sběr
  ISDOC → canonical vyjmutý z `IsdocImportService`, ostrý import beze změny),
  `EvalComputationRouter` (`AnalysisComputing` + `PathReporting::lastPath()`)
  nad `AnalysisComputation` s touž instancí `RowEnrichmentPipeline`;
  `IsdocFieldCoverage::NOT_CARRIED` jako jediný zdroj polí, která ISDOC
  nenese (round-trip test přidává jen `_resolve`); `FieldState::NotCarried`
  (`isCounted()` false — mimo `n` i odchylky), `FieldOutcome::$reason`;
  soubor běhu zůstává `shpd.eval.run.v1` s volitelnými `calls[].path`,
  `counts.notCarried`, `byPath` (staré běhy = cesta `ai`); odhad ceny počítá
  u `pdfIsdoc` jen štítky; `AttachmentPreparer` ISDOC (přípona nebo XML
  s kořenem ISDOC) modelu neposílá.
- ✓ **D83 — Nálezy prvního ověření bez přeskakování (2026-10-20, schváleno
  v chatu).** Apply prošel u všech dokladů, kontrola Kurzy `ok`; rozdíly
  věrnosti a DPH vyřešené takto: **A** kurz 1 u domácí měny ≡ null
  (pravidlo adaptéru, #121 U4 — ověření od října neběželo); **B** druh
  plnění řádku z definice kódu DPH; **C** text řádku složený z názvu
  a popisu (`CanonicalRowText`) → obojí z návrhu; **D** výjimka
  `rows[].item.ean` (applier EAN neukládá); **E** papírové přenesení jen
  u PDP a dokladu jen se službami z EU; **F** výjimka `vat.mode` u služby
  ze třetí země bez DPH (applier ji samovyměří); **G** dopravné na faktuře
  za zboží z EU je služba — generátor mapuje samovyměření per řádek podle
  `supplyKind` (ř. 3 / ř. 5, skupiny zvlášť), adaptér sloučí položky
  rekapitulace se stejnou sazbou; **H** náklad = Σ účtovaných částek
  přepočtených zvlášť (neplátce po řádcích; zbytek na 548 / 549).
  Snímky deníku se dělají až po vyřešení nálezů.
