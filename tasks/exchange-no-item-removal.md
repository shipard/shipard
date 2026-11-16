# Task: Zrušení volby „Jen účet — bez položky“ v kontrole vytěženého dokladu

**Stav:** naplánováno — #36 D1–D4 potvrzené 2026-11-16

**Issue:** #36

**Cíl:** Volba „Jen účet — bez položky“ (userAction `noItem`,
`tasks/content-tag-ui.md` D24) vede do slepé uličky. Na přijaté faktuře
dostane řádek bez položky pohyb Účetní položka a doklad nejde potvrdit; na
vydané faktuře se řádek zaúčtuje maskou kategorie a účet, který ukazoval
náhled, se tiše zahodí. Volbu rušíme: řádek faktury z AI analýzy se pořídí
vždy s položkou, nebo se vynechá. Kategorie, která v tabulce položek nemá
položku, se řeší jejím založením — karta **Položky k založení**, dialog
**Nejdřív založte položky** (`tasks/content-tag-ui.md` D25, D26 — beze
změny).

## Před implementací přečti

- `tasks/content-tag-ui.md` — D24 (rušená volba), D25, D26 (karta
  a materializace položky — zůstávají)
- `modules/core/mail/docs/ai-analysis.md` — sekce *Obsahová eskalace*:
  resolution štítek → položka, audit `_resolve.rows[i].enrichment`, UI
- `docs/exchange-format.md` — `_resolve.rows[i]` (`item.display`,
  `effectiveAccount`) a slovník `userAction`
- `tasks/exchange-preview-matched-item.md` — #111 D7b (účet doplněný
  historií nebo štítkem se na řádek s ručně zvolenou položkou nezapíše)

## Kontext — co je dnes

- **Nabídka volby.** `ResolveDecisionPanel.svelte` (prop `allowNoItem`)
  ukáže volbu u položky řádku, který v canonicalu nese `account`
  (`DocumentExchangePreview.svelte` → `rowHasAccount()`), i v hromadném
  rozhodnutí sloupce Položka.
- **Odkud má řádek bez položky účet.** Z obsahové eskalace:
  `ContentTagResolver::resolveItemForTag()` bez živé otagované položky
  vezme `AccountingItemsOffer::defaultAccountForTag()` — účet první položky
  JSON nabídky účetních položek aktivní varianty osnovy se stejným štítkem,
  jinak se stejnou skupinou štítku (`vehicle.consumables` → `vehicle.*`)
  — a vrátí `accountOnly`. `RowEnrichmentPipeline::applyTagToRows()` ten
  účet propíše do `rows[i].account`. Je to jen účet položky, kterou nabízí
  založit karta; vlastní informaci nenese.
- **Co udělá applier.** `DocumentApplier::reconcile()` při `noItem` pořídí
  řádek bez položky (`rowNoItems`); pohyb doplní
  `docs.core.applyRowOperations` → `default` (`invni` `acc.entry`, `invno`
  `sale.services`, `cashreg` `sale.goods`). `acc.entry` bez položky odmítne
  `DocRowOperationRules::validateRow()` (`item_required_for_acc_entry`)
  a předpis ho účtuje s `accountSrc: "item"`; `sale.services` účtuje
  kategorie `revenue` a účet řádku nečte.
- **Data (2026-11-16, dev server i testovací server):** žádný řádek faktury
  bez položky s pohybem `acc.entry`, žádný řádek faktury bez položky s účtem
  (mimo `purchase.asset`) a žádná uložená volba `noItem`
  v `core_mail_message_analyses.user_actions_json`. Úprava dat není potřeba.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — volba se ruší.** Volba „Jen účet — bez položky“ (userAction
  `noItem`) zaniká; nahrazuje `tasks/content-tag-ui.md` D24. Řádek faktury
  z AI analýzy se pořídí vždy s položkou, nebo se vynechá (`skip`). Pohyb
  Účetní položka, `rowOperations.jsonc`, `applyRowOperations.jsonc`
  a účtovací předpis se nemění.
- ✓ **D2 — applier `noItem` nezná.** Hodnota spadne do stávající chyby
  neznámé userAction (`DocumentApplier::resolveOne()`). Bez zpětné
  kompatibility — uložené volby nikde nejsou.
- ✓ **D3 — štítek bez položky účet nenavrhuje.** Obsahová eskalace bez
  živé otagované položky na řádek účet nezapíše; `accountOnly` zaniká.
  `resolveItemForTag()` vrací `item` | `ambiguous` | `unmapped`
  (= žádná živá otagovaná položka), `guarded` dál určuje pipeline. Řádek
  zůstane bez účtu a nerozhodnutý, audit s kategorií (⟲) zůstává.
  `AccountingItemsOffer::defaultAccountForTag()` zůstává pro Nastavení →
  Obsahové štítky (`ContentTagsController::overview`, stav *výchozí účet
  z nabídky*); `ContentTagResolver` nabídku nepotřebuje.
- ✓ **D4 — nápověda a dokumentace.** Help a docs volbu ani návrh účtu ze
  štítku nepopisují; historické tasky dostanou odkaz na #36, text jejich
  rozhodnutí se nemění.

## Co je potřeba udělat

### Commit 1 — `refactor(exchange): bez volby „jen účet“ a bez návrhu účtu ze štítku bez položky (#36 D1–D3)`

1. **`DocumentApplier`** — odstraň větev `noItem` v `reconcile()`, klíč
   plánu `rowNoItems` (všechny výskyty včetně `previewPlan()` a phpdoc),
   kontrolu `no_item_requires_account`, `annotateNoItemRows()` a jeho volání
   v apply, rozhodnutí `noItem` v doplnění `_resolve.rows[*]` pro náhled
   (`effectiveAccount`: účet řádku dál u kontačního řádku `accSide`
   a jako návrh u řádku bez efektivní položky). `noItem` pak skončí ve
   `resolveOne()` chybou neznámé userAction (D2).
2. **`ContentTagResolver::resolveItemForTag()`** — bez nabídky: žádná živá
   otagovaná položka → `unmapped`. Odeber parametr konstruktoru
   `?AccountingItemsOffer $offer` a uprav místa vytvoření
   (`RowEnrichmentPipeline::create()`, `ContentTagsController::resolver()`)
   i docblock tvarů výsledku.
3. **`RowEnrichmentPipeline::applyTagToRows()`** — účet propisuje jen
   resolution `item`. `revertContentTagSuggestions()` nech beze změny —
   persistnutý canonical z `/result` může účet z dřívějšího běhu
   `accountOnly` nést a odvolání ho při dalším čtení odstraní.
4. **`AccountingItemsOffer`** — docblock třídy a `defaultAccountForTag()`:
   čtenáři jsou `SetupController`, materializace položky a přehled štítků
   v Nastavení, ne `ContentTagResolver`.
5. **Testy:** `DocumentApplierNoItemTest` přepiš na jeden test „`noItem` →
   chyba neznámé userAction“; z `DocumentApplierItemPinTest`
   a `DocumentApplierRowDisplayTest` odeber případy `noItem`;
   `ContentTagResolverTest` (žádná živá položka → `unmapped`, resolver bez
   nabídky); `RowEnrichmentPipelineTest` (štítek bez živé položky → řádek
   bez účtu, `resolution: unmapped`; účet z dřívějšího běhu `accountOnly`
   v persistnutém canonicalu se odvolá); `ContentTagsControllerTest`
   a `AccountingItemsOfferTest` zelené.

### Commit 2 — `refactor(exchange): náhled bez volby „Jen účet — bez položky“ (#36 D1)`

1. **`ResolveDecisionPanel.svelte`** — odeber prop `allowNoItem`,
   `chooseNoItem()`, tlačítko a řádek komentáře s výsledkem `noItem`.
2. **`DocumentExchangePreview.svelte`** — `allowNoItem` v otevření panelu
   (řádek i hromadně), `rowHasAccount()` / `rowHasAccountForPath()`, pokud
   je nic jiného nepoužívá, stav `noItemDecided` (glyf, popisek, CSS),
   `noItem` v `rowMatchInfo()` a ve snippetu `rowMatchLine`.
3. **`rowMatch.js`** — druh rozhodnutí `noItem`; testy
   `frontend/tests/Unit/rowMatch.test.mjs`, `reviewGate.test.mjs`.
4. **i18n** `cs.js` / `en.js` — odeber `exchange.preview.decide.noItem`,
   `exchange.preview.status.decided.noItem`, `exchange.preview.match.noItem`.
5. `npm test`, `npm run check:i18n`, `npm run build`.

### Commit 3 — `docs: zrušená volba „jen účet“ a návrh účtu ze štítku (#36 D1–D4)`

1. **`help/posta/kontrola-vytezeni.md`** — položka **Jen účet — bez
   položky** v seznamu voleb panelu; zmínky v odstavci o změně napárované
   položky (volba v panelu, rozhodnutí *jen účet* pod řádkem); *Obsahová
   klasifikace* — místo „kategorie najde jen účet“: kategorie nemá v tabulce
   položku → ikona ⟲ s kategorií, položku vyber nebo založ, případně založ
   kartou **Položky k založení**; *Sloupec Účet* bez „řádku *jen účet*“.
2. **`help/polozky/obsahove-stitky.md`** — situace „návrh má u řádku jen
   účet“ v úvodu a odstavec **Návrh „jen účet“ je platný** → kategorie bez
   položky se řeší založením položky (karta, dialog). Názvy tlačítek ověř
   v `frontend/src/i18n/cs.js`. Pak `python3 scripts/help-index.py`.
3. **`docs/exchange-format.md`** — komentář `effectiveAccount` v ukázce
   `_resolve`, odstavce `item.display` a `effectiveAccount` (bez `noItem`),
   řádek `"noItem"` ve slovníku `userAction` odeber.
4. **`modules/core/mail/docs/ai-analysis.md`** — *Obsahová eskalace*:
   bod 5 (resolution bez `accountOnly` a fallback účtu z nabídky), výčet
   `resolution` v auditu, v odstavci *UI* odeber userAction `noItem`.
5. **Historické tasky** — `tasks/content-tag-ui.md`: pod hlavičku
   poznámka „D24 zrušeno #36 D1 (`tasks/exchange-no-item-removal.md`)“;
   `tasks/content-tag-enrichment.md`: u výčtu `resolution` poznámka, že
   `accountOnly` zaniklo (#36 D3).
6. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

## Pasti

- **`skipped: 'noItemRow'`** v `RowHistoryEnricher` je jiná věc (řádek bez
  položkového bloku, např. text) — nechat.
- **Návrh účtu řádku v náhledu zůstává** u řádku bez efektivní položky,
  který účet nese z extrakce nebo kontace — D3 mění jen to, že ho tam
  nedá štítek.
- **Materializace** (`ContentTagsController::materialize`) vrací 409 jen
  pro `item` / `ambiguous` — po D3 beze změny. Karta
  (`ContentTagSuggestionsSource`) čte nabídku sama přes `entryForTag()`.
- **Kontace účetního dokladu** (`accSide`, pohyb `acc.record`) se volby
  netýká — řádek bez bloku položky volby položky ignoruje.

## Hotovo když

- [ ] V náhledu volba „Jen účet — bez položky“ není (řádek ani hromadně);
      `noItem` v `_resolve` applier odmítne chybou neznámé userAction.
- [ ] Řádek se štítkem bez živé otagované položky nemá účet, audit
      `resolution: unmapped` a ⟲ s kategorií; karta Položky k založení beze
      změny.
- [ ] `vendor/bin/phpunit --filter 'DocumentApplierNoItem|DocumentApplierItemPin|DocumentApplierRowDisplay|ContentTagResolver|RowEnrichmentPipeline|ContentTagsController|AccountingItemsOffer'`
      zelené, pak celá sada; `npm test`, `npm run check:i18n`,
      `npm run build`.
- [ ] Help a docs aktualizované; `help-index.py` a `tasks-index.py`
      prošly.
- [ ] Ověření na volném zdroji dat (třída `free`): zpráva s fakturou, jejíž
      kategorie nemá v tabulce položku → náhled bez účtu a bez volby
      „Jen účet“; karta **Založit položku** → náhled položku napáruje;
      doklad jde vystavit.
