# Task: Review přijaté faktury — vynechaný řádek v součtech náhledu, název automatického napárování

**Stav:** naplánováno — doplněk k `exchange-preview-matched-item.md`, rozhodnutí D9–D12 potvrzená v chatu (#111; D12 2026-10-09)

**Issue:** #111

**Cíl:** Dorovnat mezery, které zůstaly po `exchange-preview-matched-item.md`:
náhled počítá součty i s řádkem, který uživatel vynechal; panel po ruční
volbě ukazuje automatické napárování jen jako `#id`; v helpu je nepřesná
věta o DPH.

## Před implementací přečti

- `tasks/exchange-preview-matched-item.md` — celé, hlavně sekce
  „Odchylky při implementaci“ (oprava `skip` v `reconcile()`)
- `tasks/exchange-preview-vat-recompute.md` — D2 (náhledový plán bez
  `reconcile()`), D4 (`computed_total_mismatch`)
- `docs/exchange-format.md` §9 (`_resolve`, `computed`, audit bloky)

## Kontext — co je dnes

- **Vynechání řádku.** Review modal posílá „Vynechat řádek“ jako
  `rows[i].item = skip`. `reconcile()` ho od #111 převádí na `rowSkips`
  a apply řádek na doklad nezapíše. Náhled ale počítá částky přes
  `previewPlan($resolved)`, který má `rowSkips` vždy prázdné (docblock:
  „bez klientských rozhodnutí“). `computed.rows`, rekapitulace i součty
  v náhledu tak vynechaný řádek dál započítávají — náhled ukazuje jiné
  **Celkem**, než bude na dokladu.
- **Převzatá rekapitulace.** `resolveRecapSource()` čte jen canonical, plán
  nezná. U převzaté rekapitulace (`recapSource: declared` — typická přijatá
  faktura, kde AI opsala konzistentní rekapitulaci) je rekapitulace
  autoritou součtů dokladu (`DocDocument::computeAmounts`, `recapDeclared`,
  `headTotalsIncludeRowsOutsideRecap`). Vynechaný řádek tak z dokladu
  zmizí, ale **Celkem** i rekapitulace zůstanou za všechny řádky — na
  dokladu i v náhledu — a `computed_total_mismatch` nepadne, protože
  součty se rovnají dokladu dodavatele. Samotné D9 by tedy u běžné faktury
  nic viditelného nezměnilo.
- **Automatické napárování v panelu.** `annotateRowDisplay()` dává do
  `item.display` jen efektivní položku. Po ruční volbě je to zvolená
  položka, takže `automaticMatchLabel()` ve `DocumentExchangePreview.svelte`
  nemá název původního napárování a ukáže `#id`. Zdroj se pak počítá bez
  `display`, takže řádek napárovaný historií ukáže „náš kód“ místo
  „z historie“ (a komponenta obchází `user` přes `'ourCode'`).
- **Help.** `help/posta/kontrola-vytezeni.md`, odstavec **Sloupec Účet**:
  „sazba DPH zůstává, jak ji přečetla AI“ — kód DPH mohla doplnit
  i historie.

## Rozhodnutí

- ✓ **D9** — Náhled respektuje vynechání řádku. Jedna funkce určuje
  vynechané řádky z klientského `_resolve` (řádková `rows[i].userAction`
  i položková `rows[i].item.userAction` = `skip`, se stejnou podmínkou
  jako dnes v `reconcile()`); používá ji `reconcile()` i `previewPlan()`.
  Vynechaný řádek pak chybí v `computed.rows`, rekapitulaci i součtech.
  V tabulce náhledu řádek zůstává (⊘, „řádek se vynechá“) s cenami
  z canonical jako dnes. Když součet přestane sedět s fakturou, ukáže se
  existující `computed_total_mismatch` — žádné nové upozornění.
- ✓ **D10** — Server posílá `item.matchedDisplay {id, code, name}` u každého
  `matched` bloku — automatické napárování nezávisle na volbě uživatele.
  `display` zůstává efektivní položka. Panel „Napárováno automaticky: …“
  bere název i zdroj z `matchedDisplay`.
- ✓ **D11** — Help: věta o DPH → kód DPH se při ruční volbě položky nemění;
  u **Vynechat řádek** doplnit, že se řádek nezapočítá do součtů a náhled
  případný rozdíl oproti faktuře ukáže.
- ✓ **D12** (2026-10-09) — Vynechaný řádek vynutí přepočet rekapitulace
  z řádků. Když plán nese `rowSkips`, `resolveRecapSource()` vrátí
  `computed` s důvodem „vynechaný řádek“ (existující info issue
  `recap_source_computed_fallback`, náhled důvod ukazuje u nadpisu
  rekapitulace), i při explicitním `recapSource: declared`. Platí pro
  náhled i apply — doklad s vynechaným řádkem má součty podle řádků, které
  na něm jsou, a rozdíl proti faktuře ukáže `computed_total_mismatch`.
  Vynechané řádky se určují jednou na začátku `preview()` / `apply()`
  z canonicalu a klientského `_resolve` a předávají se dál — stejný seznam
  dostane `reconcile()`, `previewPlan()`, `resolveRecapSource()`
  i `appendRecapSourceIssue()`.

## Kroky a commity

### Commit 1 — `fix(exchange): náhled — vynechaný řádek mimo součty a rekapitulaci, automatické napárování s názvem (#111 D9, D10, D12, 1/2)`

`modules/core/exchange/src/Document/DocumentApplier.php`:

- nová privátní `skippedRowIndices(array $canonical, array $clientResolve): array`
  (list indexů canonicalu) — přesně dnešní podmínky z `reconcile()`:
  řádková `_resolve.rows[i].userAction === 'skip'`, nebo položková
  `rows[i].item.userAction === 'skip'` u řádku, který má blok položky.
  „Má blok položky“ = tatáž podmínka, podle které `resolveAll()` blok
  `item` vyrábí (`is_array($row['item']) && $row['item'] !== []`) —
  vytáhnout do statické `rowHasItem(mixed $row): bool` a použít na obou
  místech. Funkce jede nad canonicalem, protože ji `appendRecapSourceIssue()`
  potřebuje ještě před `resolveAll()` (D12); pozice v `_resolve.rows`
  odpovídá indexu canonicalu.
- `preview()` i `apply()` spočítají `$rowSkips` jednou hned po schématu
  a předají dál. `reconcile()` dostane čtvrtý parametr `array $rowSkips`:
  `$plan['rowSkips'] = $rowSkips`, v cyklu jen `continue` pro vynechaný
  index místo dvou inline větví (chování beze změny).
- `previewPlan(array $resolved, array $rowSkips)` naplní `rowSkips`;
  `computePreviewAmounts()` dostane `$rowSkips` parametrem. Docblock:
  náhled nebere klientská rozhodnutí kromě vynechání řádku (D9) — strany,
  položky a účty dál `null`.
- D12: `resolveRecapSource(array $canonical, array $rowSkips = []): array`
  — neprázdné `rowSkips` vrátí `{source: 0, recap: [], fallback:
  'vynechaný řádek'}` v místě, kde by se jinak rekapitulace převzala (za
  kontrolou prázdné rekapitulace a `selfParty`, před samovyměřením), takže
  důvod sedí i při explicitním `declared`. Volající: `transform()`
  (`$plan['rowSkips']`), `computePreviewAmounts()` (`$rowSkips`),
  `appendRecapSourceIssue(array $canonical, array &$issues, array
  $rowSkips = [])` — default kvůli testu přes reflexi
  (`DocumentApplierTest::recapIssues`).
- `annotateRowDisplay()`: u `status: matched` vždy přidat
  `matchedId` do dávky položek a zapsat `item.matchedDisplay {id, code, name}`;
  `display` beze změny. Žádný dotaz navíc.

Dokumentace: `docs/exchange-format.md` §9 — `computed` nepočítá řádky
vynechané volbou `skip` a rekapitulace se při vynechání přepočítá
(`recapFallback`); `item.matchedDisplay` do příkladu a do „Audit bloky
navíc“; slovník `userAction` u `skip`. §8 (odstavec k `vat.recapSource`):
vynechaný řádek = důvod přepočtu (D12).

Testy:

- `DocumentApplierPreviewComputedTest` — payload se třemi řádky s různými
  cenami; položková `skip` v prostředním řádku: index chybí
  v `computed.rows`, ceny následujících řádků sedí na svých indexech,
  `totals` bez řádku, `computed_total_mismatch` přítomný; řádková `skip`
  totéž; bez volby beze změny a bez mismatch. Ve stejném testu ověřit, že
  součty náhledu = součty, které zapíše apply se stejným `_resolve`.
  D12: varianta s převzatou rekapitulací (konzistentní `vatRecap` za tři
  řádky) — po `skip` je `computed.recapSource` `computed`,
  `recapFallback` „vynechaný řádek“, issue `recap_source_computed_fallback`
  přítomné, rekapitulace i `totals` jen za dva řádky; bez `skip` zůstává
  `declared` bez issue.
- `DocumentApplierItemPinTest` — regrese: `skip` (obě varianty) dál
  vynechá řádek z dokladu.
- `DocumentApplierRowDisplayTest` — `matchedDisplay` u matched bez volby
  i s volbou `useExisting` (pak `display` = zvolená, `matchedDisplay` =
  automatická); u ne-matched chybí; počet dotazů na položky beze změny.
- `vendor/bin/phpunit --filter 'DocumentApplierPreviewComputedTest|DocumentApplierItemPinTest|DocumentApplierRowDisplayTest|DocumentApplierNoItemTest|DocumentApplierVatPinsTest'`

### Commit 2 — `fix(mail): review — automatické napárování v panelu s názvem a zdrojem; help; task hotový (#111 D10, D11, 2/2)`

`DocumentExchangePreview.svelte` — `automaticMatchLabel()`: název
z `block.matchedDisplay` (fallback `#matchedId` jen bez něj), zdroj
`matchSourceKey(block, enrichment, block.matchedDisplay)` bez obcházení
`user` → `ourCode`. Komentář nad funkcí upravit.

`frontend/src/components/exchange/rowMatch.js` — beze změny API; když se
ukáže potřeba, test v `frontend/tests/Unit/rowMatch.test.mjs` pro zdroj
z `matchedDisplay` (historie → `historyExact`, ne `user`).

Help `help/posta/kontrola-vytezeni.md`:

- odstavec **Sloupec Účet**: „sazba DPH zůstává, jak ji přečetla AI“ →
  kód DPH se nemění;
- odrážka **Vynechat řádek**: řádek se na doklad nezapíše a nezapočítá
  se do součtů v náhledu; když pak součet nesedí s fakturou, náhled to
  ukáže upozorněním. Text upozornění ověřit v `DocumentApplier`
  (`computed_total_mismatch`).
- `python3 scripts/help-index.py`

Uzavření: `**Stav:**` v tomto tasku, `python3 scripts/tasks-index.py`;
`cd frontend && npm run test && npm run check:i18n && npm run build`.

## Ověření na dev zdroji (`lh6x-l`, režim volný)

1. Zpráva s aspoň dvěma řádky a převzatou rekapitulací: **Vynechat
   řádek** → po obnovení náhledu **Celkem** a rekapitulace bez řádku, nad
   rekapitulací *přepočítaná podle řádků — vynechaný řádek*, upozornění na
   rozdíl proti faktuře a pod **Celkem** částka *Na dokladu dodavatele*.
   **Vystavit koncept** → součet dokladu = **Celkem** z náhledu (SQL),
   `vat_recap_source` přepočítaná.
2. **Zrušit výběr** u vynechaného řádku → součty zpět.
3. Řádek napárovaný z historie, ruční volba jiné položky → panel ukazuje
   „Napárováno automaticky: kód název (z historie)“.

## Mimo rozsah

- Vizuální odlišení vynechaného řádku v tabulce (ztlumení) — stav nese
  badge a druhý řádek.
- „Jen účet — bez položky“ — #36 (včetně textu helpu u této volby).

## Pasti

- **Jedna funkce pro obě cesty.** `reconcile()` a `previewPlan()` musí
  vynechání určovat stejně — proto sdílená `skippedRowIndices()`, ne
  kopie podmínky.
- **Mapování cen řádků.** `computePreviewAmounts()` přiřazuje spočítané
  ceny přes `transformedRowIndices($rows, $plan)`; s naplněným `rowSkips`
  to funguje samo — v testu ověřit řádek *za* vynechaným.
- **Převzatá rekapitulace** (`recapSource: declared`): bez D12 by součty
  dokladu dávala rekapitulace z faktury a vynechání by je nezměnilo —
  proto D12 přepočet vynucuje. Pozor na pořadí v `resolveRecapSource()`:
  kontrola `rowSkips` až za větví neplátce a za explicitním
  `recapSource: computed` (tam důvod nedává smysl), ale před převzetím.
- **`appendRecapSourceIssue()` běží před `resolveAll()`** — proto
  `skippedRowIndices()` čte canonical, ne `$resolved`. Test
  `DocumentApplierTest::recapIssues` ji volá reflexí se dvěma argumenty,
  třetí musí mít default.
- **`previewPlan()` dál bez `reconcile()`** — jinak by náhled hlásil
  `unresolved_required` (D2 `exchange-preview-vat-recompute.md`).
- **`display` se nemění.** Druhý řádek a sloupec Účet dál podle
  efektivní položky; `matchedDisplay` čte jen panel.

## Hotovo když

- [ ] Vynechaný řádek chybí v `computed` (řádky, rekapitulace, součty);
      náhled = apply (D9).
- [ ] Vynechaný řádek vynutí přepočet rekapitulace s důvodem, i u převzaté
      (D12).
- [ ] `item.matchedDisplay` u `matched`; panel „Napárováno automaticky“
      s názvem a správným zdrojem (D10).
- [ ] Help opravený (D11); `help-index.py`, `tasks-index.py`.
- [ ] Testy (filtry výše), `npm run test`, `npm run check:i18n`,
      `npm run build`.
- [ ] Ověření na dev zdroji body 1–3.
- [ ] Komentář do #111 se shrnutím obou tasků, issue uzavřené.
