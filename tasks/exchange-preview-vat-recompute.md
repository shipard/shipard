# Task: Náhled návrhu počítá rekapitulaci DPH a součty stejně jako doklad

**Stav:** hotovo — kód, testy, docs a help 2026-10-01 (3 commity: computeAmounts, _resolve.computed, náhled); ověřeno na dev zdroji 2026-10-01 (body 1–4)

**Issue:** #87 (task A; task B — volby DPH v náhledu — navazuje)

**Cíl:** Náhled návrhu (`DocumentExchangePreview`) ukazuje rekapitulaci DPH,
součty a sazbu řádků, **které skončí na dokladu** — spočítané stejným kódem
jako `DocDocument::beforeSave()`. Dnes vypisuje `canonical.vatRecap`
a `canonical.totals`, tedy to, co přečetla AI.

Návaznost: roadmapa M2 (uzavřený kruh na přijaté faktuře). Předpoklad pro
task B (#87): ruční volba kódu DPH, místa plnění a režimu musí mít v náhledu
viditelný efekt. Chybějící kódy číselníku řeší #89 (nezávislé).

## Před implementací přečti

- `docs/vat-calculation.md` — §4 speciální kódy, §5 autorita rekapitulace
  (převzatá vs. přepočítaná)
- `docs/exchange-format.md` — `vatRecap` a `totals` jako vstup vs. autorita
  (~ř. 328–390), §9 `_resolve` a audit bloky (~ř. 901–1000)
- `docs/document-system.md` — hooky, `TableGateway`, `DocumentRegistry`
- `modules/docs/core/src/DocRowCalculator.php` — vzor „živý přepočet
  stejným kódem jako uložení“ (#71)
- `tasks/exchange-received-reverse-charge.md` — D3 (rekapitulace
  u samovyměření vždy přepočítaná), sekce Pasti (náhled ukazuje `pct`
  z canonicalu)

## Kontext — co je dnes

- **Náhled je statický u všech dokladů.** `DocumentExchangePreview.svelte`
  vypisuje ve sloupci DPH řádku `row.vat.pct` (~ř. 732), v rekapitulaci
  `canonical.vatRecap` a v součtech `canonical.totals` (~ř. 741–795).
  Výsledek počítá až `DocDocument::beforeSave()` při apply.
- Shodu náhledu s dokladem dnes zajišťuje jen převzetí rekapitulace
  dodavatele (`resolveRecapSource()` → `declared`, #75), když projde
  aritmetickou kontrolou. Náhled se mýlí, když se rekapitulace přepočítává:
  fallback (nekonzistentní řádek, neznámý kód), samovyměření (D3 z #86 —
  dodavatel má 0 % a daň 0, doklad dostane nárok + oddaňovací pár),
  vystavené doklady (vždy přepočet).
- **Výpočetní blok** je v `DocDocument::beforeSave()` (~ř. 481–515): kódy
  dokladu (`resolveVatCodesForDoc`) → `calculateRowPrice` /
  `calculateRowVat` → `useDeclaredRecap` ? `takeOverVatRecapitulation` :
  `buildVatRecapitulation` → `sumTotals` → `applyTotalRounding` →
  `reconcileRowsToRecap` → `applyDomesticAmounts`. Nastavuje stav instance
  `$this->recapDeclared` (~ř. 93, čte ho `headTotalsIncludeRowsOutsideRecap()`
  ~ř. 1352) a `$this->computedRows` (~ř. 107, čte `persistRowComputedColumns()`).
  Z DB čte jen zemi registrace DPH (`resolveCountryFromVatRegistration`).
- **Polymorfismus:** `AccountingDocument` přetěžuje `sumTotals()`
  a `headTotalsIncludeRowsOutsideRecap()` — výpočet proto musí zůstat
  metodou dokumentu, statický kalkulátor (vzor `DocRowCalculator`) by
  přetížení obešel.
- **`transform()`** (`DocumentApplier` ~ř. 1123) bere kód a sazbu řádku
  z `$plan['resolvedRowVatCodes']` (= `_resolve.rows[i].vatCode`),
  jednotku z `resolvedRowUnits`, položky a strany z plánu / `sideIds`;
  číselná řada je parametr. `preview()` (~ř. 289) dnes plán nestaví.
- `TableGateway::saveDocument()` bere instanci přes
  `registry->getDocument($tableId, $data)` (nová instance per volání)
  a `injectDocServices()` (db, config, dsConfig, settings) — obojí privátní.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — sdílený výpočet.** Blok z `beforeSave()` se přesune do veřejné
  metody `DocDocument::computeAmounts(array &$data, ?array $originalData = null): array`
  bez vedlejších efektů mimo instanci (žádný zápis do DB, žádné číslování,
  snapshoty ani přechody stavů). `beforeSave()` ji volá — chování uložení
  se nemění. Náhled získá instanci podle typu dokladu přes registr, aby
  platila přetížení podtříd.
- ✓ **D2 — vstup náhledu.** Náhled postaví `$data` stejným `transform()`
  jako apply, s **náhledovým plánem**: kódy DPH a jednotky z čerstvého
  resolve, bez založených entit (`sideIds` prázdné, nespárované strany
  a položky `null`), číselná řada `null`, bez `rowOperationDefaults`.
  Mapování canonical → doklad zůstává na jediném místě.
- ✓ **D3 — výstup** `_resolve.computed`, v měně dokladu:

  ```jsonc
  "computed": {
    "recapSource":   "declared",        // declared | computed
    "recapFallback": null,              // důvod přepočtu (resolveRecapSource), jinak null
    "vatRecap": [
      { "vatCode": "cz-217", "vatPct": 21, "base": 1000.00, "tax": 210.00,
        "total": 1210.00, "isReversePair": false }
    ],
    "totals": { "totalBase": 1000.00, "totalVat": 0.00,
                "totalAmount": 1000.00, "totalRounding": 0.00 }
  }
  ```

  Domácí měna se nevrací (kurz cizí měny se doplňuje až v dokladu).
- ✓ **D4 — rozdíl proti dokladu dodavatele.** Porovnává se jen částka
  k úhradě (`computed.totals.totalAmount` vs. `canonical.totals.totalAmount`);
  u samovyměření se liší daň, částka k úhradě sedí. Rozdíl nad tolerancí →
  **warning**, ne error.

  **Upřesnění při psaní tasku (potvrzeno 2026-10-01):** kód `totals_mismatch` už
  existuje — `DocumentValidator` (~ř. 247) z canonicalu odhaduje varianty
  (součet bez DPH, s DPH per řádek, podle `vatRecap`) a hlásí, když
  nesedí žádná. Nový warning dostane vlastní kód **`computed_total_mismatch`**
  (path `totals.totalAmount`, pole `declared` a `computed`), tolerance
  0,01 — `computed` už nese zaokrouhlení podle `deriveTotalRoundingMode()`.
  Když je `computed` k dispozici, náhled heuristický `totals_mismatch`
  z výsledku **vyřadí** (skutečný výpočet ho nahrazuje, dvě hlášky o tomtéž
  by mátly). Apply a `validate()` se nemění. Chybí-li
  `canonical.totals.totalAmount`, porovnání se nedělá.
- ✓ **D5 — zobrazení.** Rekapitulace a součty z `_resolve.computed`
  s označením zdroje („převzatá z dokladu“ / „přepočítaná“ + důvod
  fallbacku). Údaje z dokladu dodavatele jen při rozdílu, jako sekundární
  řádek u **Celkem**. Sloupec DPH řádku: efektivní kód a sazba
  z `_resolve.rows[i].vatCode` (`createPayload.code` / `createPayload.pct`),
  fallback `row.vat.pct`.
- ✓ **D6 — degradace.** Výjimka ve výpočtu náhled neshodí: `computed: null`
  + info issue `computed_unavailable` (zalogovat přes `ErrorLogger`);
  frontend zobrazí data z canonicalu s poznámkou, že nejde o přepočet.
- ✓ **D7 — rozsah.** Všechny typy dokladů přes `DocumentApplier::preview()`
  (přijaté, vystavené, účetní — ty mají vlastní součty přes přetížení,
  pokladní). Spisovna (registry target) ne. Apply se mění jen refaktorem z D1.

### Upřesnění z kódu při implementaci (2026-10-01)

- **D1 — `AccountingDocument`.** `beforeSave()` účetního dokladu vynucoval
  `vat_mode = 0` před voláním rodiče; náhled volá `computeAmounts()` přímo
  a vynucení by obešel. Vynucení se přesunulo do overridu `computeAmounts()`
  (`beforeSave` override zrušen, `validate()` si své nechává). Ostatní
  podtřídy (`CashDeskDocumentBase`) před výpočtem částky neovlivňují.
- **D2 — plán náhledu** musí nést i `cashDeskId` (čte ho `transform()`,
  `reconcile()` ho do plánu nedává — doplňuje ho až `apply()`).
- **D3 — zapojení.** `withResolve()` dělá `$resolved + ['issues' => …]`
  a `buildSummary()` iteruje jen pevné klíče, takže stačí
  `$resolved['computed'] = …` před `withResolve()`; signatura se nemění.
  `recapSource` se bere z toho, co dokument skutečně použil
  (`recapDeclared` z výsledku), `recapFallback` z `resolveRecapSource()`.
- **Testy.** Applier dostává mock `ConfigRuntime` jen s `docTypes` /
  `vatPlaces`, ale dokument čte kódy DPH přes `VatRateResolver` ze svého
  `config` — dokument z mocku `createDocument()` dostává
  `ConfigRuntime::load(tmpDir)` s reálným `vat-cz.jsonc` (vzor
  `DocDocumentVatRecapDeclaredTest`). Parita přes `saveDocument()` callback,
  který nad payloadem pustí `beforeSave()` jako gateway.

## Kroky a commity

### Commit 1 — `refactor(docs): DocDocument::computeAmounts — výpočet řádků, rekapitulace a součtů bez uložení (exchange-preview-vat-recompute D1, 1/3)`

- `modules/docs/core/src/DocDocument.php`:
  - nová `public function computeAmounts(array &$data, ?array $originalData = null): array`
    — obsah dnešního bloku ~ř. 481–515 (od `$vatMode = …` po
    `$this->computedRows = …`), včetně propagace `rows` zpět do `$data`
    (jen když klíč existuje — viz komentář v bloku). Vrací
    `['rows' => …, 'recap' => …, 'recapDeclared' => bool]`; součty jsou
    v `$data` (`total_base`, `total_vat`, `total_amount`, `total_rounding`).
  - `beforeSave()` místo bloku volá `$this->computeAmounts($data, $originalData)`;
    pořadí ostatních kroků beze změny.
  - `recapDeclared` a `computedRows` nastavuje `computeAmounts()` na začátku
    vždy znovu (instance může jít přes víc dokladů — viz komentář „Reset
    per save“ ~ř. 453).
- `src/Core/Document/TableGateway.php`: veřejná
  `createDocument(array $data): Document` = `registry->getDocument()`
  + `injectDocServices()`; `saveDocument()` ji použije místo dvou řádků
  na začátku. Nová instance per volání (žádné sdílení stavu s uložením).
- Test `tests/Unit/Module/Docs/Core/DocDocumentComputeAmountsTest.php`:
  `computeAmounts()` nad fixní sadou (tuzemsko 21 % + 12 %, převzatá
  rekapitulace, samovyměření s párem) dá stejné řádky, rekapitulaci
  a součty jako `beforeSave()` nad kopií dat; nezapisuje do DB (mock
  `Connection` bez `insert`/`query` očekávání kromě čtení registrace).
- Stávající testy beze změny: `vendor/bin/phpunit --filter 'DocDocument|VatRecap|AccountingDocument'`.

### Commit 2 — `feat(exchange): náhled návrhu počítá rekapitulaci DPH a součty jako doklad — _resolve.computed, computed_total_mismatch (exchange-preview-vat-recompute D2–D4, D6, D7, 2/3)`

- `modules/core/exchange/src/Document/DocumentApplier.php`:
  - privátní `previewPlan(array $resolved): array` — tvar plánu jako
    `reconcile()` (všechny klíče, prázdné hodnoty), plní jen
    `resolvedRowVatCodes` a `resolvedRowUnits` z `$resolved['rows']`;
    **nevolá** `reconcile()` (ten by u nespárovaných referencí nastavil
    `unresolved_required`).
  - privátní `computePreviewAmounts(array $canonical, array $resolved, array &$issues): ?array`:
    `transform($canonical, previewPlan($resolved), [], null)` →
    `headsGateway->createDocument($data)` → `computeAmounts($data)` →
    tvar D3. `recapSource` / `recapFallback` z `resolveRecapSource()`
    (stejné volání jako v transform; kontext je cachovaný). Mapování
    rekapitulace: `vat_code`→`vatCode`, `vat_pct`→`vatPct`, `base`, `tax`,
    `total`, `is_reverse_pair`→bool. `try/catch (\Throwable)` → `null`
    + info `computed_unavailable` (D6).
  - D4: porovnání `totalAmount` → warning `computed_total_mismatch`;
    při úspěšném výpočtu odstranit z `$issues` heuristický `totals_mismatch`.
  - `preview()` (~ř. 289): po `resolveAll()` zavolat výpočet a výsledek
    dát do `_resolve.computed` (přes `withResolve()` — `$resolved['computed']`
    nebo argument navíc; `carryOverEnrichment` se ho netýká). `apply()`
    beze změny.
- `docs/exchange-format.md` §9: blok `computed` v příkladu `_resolve`
  a v „Audit bloky navíc“; u `totals` (~ř. 362–372) věta, že náhled
  počítá částky skutečným výpočtem a `computed_total_mismatch` nahrazuje
  v náhledu heuristický `totals_mismatch`. `docs/vat-calculation.md` §5:
  jedna věta, že náhled návrhu používá `DocDocument::computeAmounts()`.
- Testy v `tests/Unit/Module/Core/Exchange/Document/` (nový
  `DocumentApplierPreviewComputedTest.php`, harness podle
  `DocumentApplierTest`, `headsGateway` mock vrací skutečný
  `DocsHeadsDocument` s mockovanou DB pro registraci DPH):
  - tuzemsko, konzistentní rekapitulace → `recapSource: declared`,
    čísla = rekapitulace dodavatele, bez warningu;
  - samovyměření (EU služby, `pct` 0) → `computed`, dva řádky rekapitulace
    (`cz-217` + pár `isReversePair: true`), `totalAmount` = základ,
    bez `computed_total_mismatch`, sloupec sazby 21 %;
  - fallback (nekonzistentní řádek) → `computed` + `recapFallback`;
  - rozdíl částky k úhradě → `computed_total_mismatch`, heuristický
    `totals_mismatch` v issues není;
  - výjimka v gatewayi → `computed: null` + `computed_unavailable`,
    preview `success`;
  - parita: `_resolve.computed` = částky, které `apply()` předá do
    `saveDocument()` nad stejným canonicalem (zachytit payload mocku).
- `vendor/bin/phpunit --filter 'DocumentApplier'`.

### Commit 3 — `feat(mail): náhled návrhu zobrazuje přepočítanou rekapitulaci, součty a sazbu řádků; help; task hotový (exchange-preview-vat-recompute D5, 3/3)`

- `frontend/src/components/exchange/DocumentExchangePreview.svelte`:
  - `let computed = $derived(resolve?.computed ?? null)`;
  - sloupec DPH řádku (~ř. 731–734): `resolve?.rows?.[i]?.vatCode?.createPayload?.pct
    ?? row.vat?.pct`, u efektivního kódu i jeho klíč (malým písmem jako
    `shpd-exchange__row-code`);
  - rekapitulace a součty (~ř. 741–795) z `computed`, když je; jinak
    canonical + poznámka `exchange.preview.recap.notComputed`; nad tabulkou
    rekapitulace štítek zdroje (`…recap.declared` / `…recap.computed`,
    u `computed` s `recapFallback` jako title/podtext); řádek páru
    označit (`…recap.reversePair`);
  - při `computed_total_mismatch` pod **Celkem** sekundární řádek
    „Na dokladu dodavatele: …“ (`…totals.supplierTotal`) z
    `canonical.totals.totalAmount`;
  - `data-testid="review-total"` zůstává na hodnotě, která skončí na dokladu.
- `frontend/src/i18n/cs.js` + `en.js`: nové klíče `exchange.preview.recap.*`,
  `exchange.preview.totals.supplierTotal`; `npm run check:i18n`.
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`:
  beze změny (data přicházejí v `canonical._resolve`).
- Help (názvy sekcí ověřit v `cs.js`):
  - `help/posta/kontrola-vytezeni.md` — tabulka v kroku 3 (~ř. 38–39):
    **Součty** a **DPH rekapitulace** ukazují, co skončí na dokladu;
    porovnává se s originálem, rozdíl hlásí upozornění a pod **Celkem** je
    částka z faktury. Odstavec **Reverse charge** (~ř. 172): řádek daně
    a oddaňovací řádek uvidíš už v náhledu.
  - `help/co-dnes-nejde.md` — nic nemazat (položka o samovyměření ~ř. 168
    platí dál; ruční změna kódu přijde s taskem B).
  - `python3 scripts/help-index.py`.
- `**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření na dev zdroji`),
  řádek v indexu `tasks/README.md` (sekce Výměnný formát) zkontrolovat,
  `python3 scripts/tasks-index.py`.
- `cd frontend && timeout 90 npm run build`.

## Ověření na dev zdroji

Zdroj s režimem *volný* (zdroj a zprávy v chatu, mimo repo):

1. Přijatá tuzemská faktura s převzatou rekapitulací → náhled: štítek
   „převzatá z dokladu“, čísla shodná s fakturou, žádné nové upozornění.
2. Faktura se samovyměřením (EU služby, ověřená v #86) → náhled: sazba
   řádku 21 %, rekapitulace „přepočítaná“ se dvěma řádky (nárok + pár),
   **Celkem** = základ. „Vystavit koncept“ → doklad má stejná čísla.
3. Faktura, kde AI přečetla řádky neúplně → `computed_total_mismatch`
   a pod **Celkem** částka z faktury.
4. Mobilní šířka (<768 px) — rekapitulace se vejde / scrolluje.

## Mimo rozsah

- Ruční volba kódu DPH, místa plnění a režimu v náhledu — task B (#87).
- Zobrazení efektivního místa plnění a režimu v hlavičce náhledu (dnes
  canonical + upozornění `vat_place_derived` / `vat_mode_derived`) — task B.
- Částky v domácí měně, kurz ČNB.
- Chybějící kódy číselníku — #89.

## Pasti

- **Stav instance.** `headTotalsIncludeRowsOutsideRecap()` čte
  `$this->recapDeclared` během `sumTotals()` — `computeAmounts()` ho musí
  nastavit dřív, než sečte, a resetovat na začátku. Náhled vždy čerstvou
  instanci (`createDocument()`), nikdy instanci z uložení.
- **Child sync.** Propagace `rows` zpět do `$data` jen když klíč existuje —
  přidáním klíče by header-only save smazal řádky v DB. Neměnit.
- **`reconcile()` v náhledu nevolat** — u nespárované strany/položky
  nastaví `errorCode` a přidá error issue, které náhled dnes nemá.
- **`transform()` s prázdnými `sideIds`:** partner a položky `null`; pro
  výpočet DPH nevadí, ale nic, co `computeAmounts()` čte, nesmí na nich
  záviset (ověřit testem parity).
- **Cizí měna bez kurzu:** `applyDomesticAmounts()` bere kurz 1,0 —
  domácí hodnoty v náhledu nepoužívat.
- **`vat_registration` null** (zdroj neplátce, vystavený doklad bez
  registrace) → `resolveVatCodesForDoc()` vrátí null, rekapitulace prázdná,
  součty z řádků — to je správný výsledek, ne chyba (D6 se neuplatní).
- **`AccountingDocument`** — součty Σ MD z řádků, rekapitulace žádná;
  náhled nesmí očekávat neprázdnou `vatRecap`.
- **Dva warningy o součtu** — viz D4 upřesnění; v testu ověřit, že po
  úspěšném výpočtu heuristický zmizí a při `computed: null` zůstane.
- **Fixture a docs:** jen fiktivní dodavatelé a částky.

## Hotovo když

- [x] `DocDocument::computeAmounts()` a `TableGateway::createDocument()`;
      stávající testy `DocDocument|VatRecap|AccountingDocument` zelené,
      nový test parity zelený (`DocDocumentComputeAmountsTest`).
- [x] `preview()` vrací `_resolve.computed` podle D3; `computed_total_mismatch`
      a vyřazení heuristického `totals_mismatch` (D4); degradace (D6);
      testy `DocumentApplier` zelené (`DocumentApplierPreviewComputedTest`).
- [x] Náhled zobrazuje rekapitulaci, součty a sazbu řádků z `computed`
      se štítkem zdroje a fallbackem na canonical; i18n parita; build.
- [x] `docs/exchange-format.md`, `docs/vat-calculation.md`, help aktualizované;
      `help-index.py` a `tasks-index.py` prošly.
- [x] Ověření na dev zdroji (body 1–4) — 2026-10-01.
