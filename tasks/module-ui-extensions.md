# Taby a štítky detailu, záložka formuláře a štítky řádku z modulů (#172 D16–D19)

**Stav:** hotovo — 2026-11-25 (#172 D16–D19)

> Task pro Claude Code (4 commity). Design: issue #172 (komentář
> „Body rozšíření v UI“). Navazuje na `tasks/module-commands-cron.md`
> a `tasks/module-server-config.md` (hotové). Akce záznamu s obsluhou
> v modulu (D12–D15) jsou samostatný task C2. První odběratel je doplněk
> Evidence tržeb (#171): záložka Evidence tržeb a štítek v detailu dokladu,
> zaškrtávátko Kontaktní platba ve formuláři dokladu, štítek EET v seznamu
> dokladů.

## Kontext

Doplněk (a obecně modul) se do cizí tabulky smí napojit jen přes body
rozšíření (#170 D6, `docs/modules.md` kap. 9). Pro UI dnes existuje jen
rozšíření tabulky (`extensions` — sloupce). Chybí:

- **Detail** — skládá ho jediný builder `TableViewer::renderDetail()`
  (`final`, #136) z háčků vieweru (`detailTabs`, `detailBadges`, …).
  Taby jsou obecné typy obsahu (`properties`, `table`, `notice`, `badges`,
  `empty`, `text`, `code`, `heading`, `composite`, `hint`), které frontend
  kreslí bez znalosti modulu (`ContentBlock.svelte`).
- **Formulář** — `FormController::resolveFormDefinition()` sestaví
  `FormDefinition` z třídy formuláře (`FormRegistry`), JSONC nebo
  automaticky. Ukládá všechny nesystémové sloupce tabulky
  (`filterWritableFields`) — tedy i sloupce, které tabulce přidal jiný
  modul přes `extensions`. Read-only podle stavu dokladu hlídá
  `guardReadOnlyUpdate`.
- **Řádek vieweru** — `ViewerController::rows()` volá `renderRow()` po
  řádcích; pole `t2` přijímá řetězec, objekt `{text, class?, badge?}`
  nebo pole objektů (`docs/frontend.md` § Formát řádku).

## Rozhodnutí (#172)

- **D16** — `detailExtensions: [{table, class}]` — taby (obecné typy
  obsahu) a štítky hlavičky detailu cizí tabulky; builder je přidá za taby
  a štítky vieweru. Bez změny frontendu.
- **D17** — `formTabs: [{table, class}]` — celá záložka se sloupci
  z rozšíření tabulky, na konec formuláře; ukládá se běžným uložením;
  read-only podle stavu dokladu jako zbytek formuláře; v 1. fázi bez
  přepočtu. Bez změny frontendu.
- **D18** — `rowDecorators: [{table, class}]` — dávkově pro stránku
  řádků (bez dotazu na každý řádek), spany do `t2`. Bez změny frontendu.
- **D19** — Jen aktivní moduly zdroje dat (vzor `alertChecks`); výjimka
  v poskytovateli → log a přeskočení, detail / formulář / seznam se
  vykreslí bez něj; pořadí `order` (výchozí 500), pak id modulu.

## Před implementací přečti

- `docs/modules.md` kap. 5 (pole `documentEventHandlers` — vzor `{table,
  class}`), kap. 9 (Doplňky)
- `docs/frontend.md` — § Formát řádku, § Formát detail panelu (builder,
  háčky, typy obsahu), § Akce detailu
- `docs/edit-forms.md` — `TableForm`, `TabBuilder`, `FormDefinition`,
  read-only stavy
- `src/Core/Viewer/TableViewer.php` (`renderDetail`, háčky),
  `src/Core/Viewer/ViewerRegistry.php` (`createViewer`),
  `src/Api/ViewerLoader.php`, `src/Api/Controller/ViewerController.php`
  (`rows`, `detail`)
- `src/Api/Controller/FormController.php` (`resolveFormDefinition` — volá
  ho `meta`, `recalculate` i sub-tabulky; `filterWritableFields`,
  `guardReadOnlyUpdate`), `src/Api/FormLoader.php`, `src/Core/Form/FormRegistry.php`,
  `src/Core/Form/TableForm.php` (`tab()`), `src/Core/Form/TabBuilder.php`,
  `src/Core/Form/FormDefinition.php`
- `src/Core/Module/ModuleDefinition.php` — parser `documentEventHandlers`,
  `src/Api/DocumentEventHandlerLoader.php` (instance bez argumentů
  konstruktoru, kontext v parametrech)
- `src/Core/Overview/OverviewCollector.php` — vzor izolace poskytovatele
- `tests/Unit/Core/Viewer/TableViewerDetailBuilderTest.php`,
  `tests/Unit/Core/Viewer/DetailContractTest.php`

## 1. Deklarace a společný základ (D19)

```jsonc
// module.jsonc doplňku
"detailExtensions": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocDetailExtension"}
],
"formTabs": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocFormTab", "order": 500}
],
"rowDecorators": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocRowDecorator"}
]
```

- Tvar každé položky `{table, class, order?}` — `order` celé číslo,
  výchozí 500. Validace tvaru při načtení modulu (výjimka jako
  u `cronJobs`). Tabulka smí být vlastní i cizí.
- Test nad stromem `modules/` (vzor `ModuleCommandRules`): třída existuje,
  implementuje správné rozhraní, leží v namespace svého modulu; tabulka
  existuje v definicích tabulek stromu (vlastní, nebo modulu, na kterém
  modul tranzitivně závisí).
- Načítají se jen z modulů aktivních ve zdroji dat — ze stejného
  `ModuleResolver::resolve` jako viewery a formuláře (`ViewerLoader`,
  `FormLoader`), ne druhým načítáním stromu.
- Instance bez argumentů konstruktoru (vzor `documentEventHandlers`);
  kontext (`DataSourceConnection`, `ConfigRuntime`, `DataSourceConfig`,
  jazyk) dostanou v parametru — navrhni jeden objekt kontextu pro všechny
  tři druhy.
- **Izolace:** výjimka z poskytovatele se zaloguje (`ErrorLogger::error`
  s id modulu, třídou, tabulkou a druhem rozšíření) a poskytovatel se
  přeskočí; zbytek detailu / formuláře / seznamu se vykreslí.
- **Pořadí:** `order` vzestupně, pak id modulu, pak pořadí deklarace.
- **Polymorfní tabulky** (`docs_core_heads` přes `doc_type`): registrace
  je per tabulka; jestli se poskytovatel týká záznamu (typ dokladu, stav),
  rozhoduje sám a vrátí prázdný výsledek.

Commit 1 (`feat(modules): deklarace detailExtensions, formTabs a rowDecorators (#172 D16–D19)`)
— parser, validace, test stromu, načítání z aktivních modulů, kontext,
izolace a řazení; podkapitola v `docs/modules.md` kap. 5. Ještě bez
napojení na detail, formulář a seznam.

## 2. Detail (D16)

- Rozhraní (návrh) `Shipard\Core\Viewer\DetailExtension`:
  - `tabs(string $table, array $record, <kontext>): array` — seznam tabů
    ve stejném tvaru jako `detailTabs()` vieweru (`id`, `label`,
    `content` obecného typu); id tabu prefixuj id modulu, ať nekoliduje
    s taby vieweru;
  - `badges(string $table, array $record, <kontext>): array` — štítky
    `{label, style}` jako `detailBadges()`.
- `ViewerRegistry` dostane rozšíření z aktivních modulů a předá je
  vieweru při `createViewer()`; `renderDetail()` přidá taby za taby vieweru
  (před tab Přílohy, když ho builder přidává) a štítky za štítky vieweru.
  `renderDetail()` zůstává `final`.
- Záznam pro rozšíření = záznam, který dostávají háčky vieweru
  (`loadDetailRecord`).
- Viewer bez tabulky rozšíření nemá.
- `DetailContractTest` musí dál procházet; rozšíření nesmí obcházet
  kontrakt (žádný `html`, jen sdílené typy obsahu — kontrola v testu
  pravidel nebo v builderu).

Commit 2 (`feat(viewer): taby a štítky detailu z modulů (#172 D16)`).

## 3. Formulář (D17)

- Rozhraní / abstraktní třída (návrh) `Shipard\Core\Form\ModuleFormTab`:
  `build(array $data, bool $isNew, <kontext>): ?FormTab` — `null` = záložka
  se pro tento záznam nezobrazí (jiný typ dokladu). Pomocník pro stavbu
  záložky nad definicí tabulky (popisky sloupců jako `TableForm::tab()`) —
  sdílej kód s `TableForm`, ne kopii.
- `resolveFormDefinition()` přidá záložky modulů na konec `tabs` pro
  všechny tři cesty (PHP třída, JSONC, automatický formulář) — `meta`,
  `recalculate` i sub-tabulky tak vidí stejnou definici.
- Záložka smí obsahovat jen sloupce tabulky (včetně rozšíření) a prvky
  bez hodnoty (oddělovač, `content`); sub-tabulky a lookupy v 1. fázi ne —
  nepovolený prvek = výjimka v poskytovateli → izolace podle D19.
- **Ukládání beze změny** — sloupce z rozšíření už `filterWritableFields`
  propouští. Ověř testem, že hodnota zaškrtávátka ze záložky modulu se
  uloží a v read-only stavu dokladu se odmítne jako ostatní pole.
- Bez přepočtu: změna pole v záložce modulu nevolá háček modulu
  (`recalculate` formuláře běží jako dnes).

Commit 3 (`feat(forms): záložka formuláře z modulu (#172 D17)`).

## 4. Řádek vieweru (D18) a dokumentace

- Rozhraní (návrh) `Shipard\Core\Viewer\RowDecorator`:
  `decorate(string $table, array $rawRows, <kontext>): array` — mapa
  id řádku → seznam spanů `{text, class?, badge?}`. Jedno volání na
  stránku řádků.
- `ViewerController::rows()` po `renderRow()` (jen layout `list`; `grid`
  má sloupce, ne `t2`) přidá spany na konec `t2` — `t2` jako řetězec nebo
  objekt nejdřív převeď na pole, pořadí ostatních spanů zachovej.
- Výkon: test, že dekorátor se volá jednou na stránku, ne na řádek.
- **Dokumentace:**
  - `docs/modules.md` kap. 9 Doplňky — mezi body rozšíření `detailExtensions`,
    `formTabs`, `rowDecorators`; věta „do formuláře a detailu cizí tabulky
    se doplněk napojuje celou vlastní záložkou (mechanismus má vlastní
    issue)“ → odkaz na tento mechanismus;
  - `docs/frontend.md` — § Formát detail panelu (taby a štítky z modulů za
    háčky vieweru), § Formát řádku (spany z modulů na konci `t2`);
  - `docs/edit-forms.md` — záložka formuláře z modulu (kde se přidá, co
    smí obsahovat, ukládání, read-only, bez přepočtu).

Commit 4 (`feat(viewer): štítky řádku z modulů; docs (#172 D18, D19)`) včetně
`**Stav:**` tohoto tasku a `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `module.jsonc` umí `detailExtensions`, `formTabs`, `rowDecorators`
      (`{table, class, order?}`); vadný tvar selže při načtení; test stromu
      hlídá třídu, rozhraní, namespace a existenci tabulky
- [x] načítají se jen aktivní moduly; výjimka poskytovatele se zaloguje
      a detail / formulář / seznam se vykreslí bez něj (test pro každý
      druh); pořadí podle `order` a id modulu
- [x] detail cizí tabulky ukazuje taby a štítky z modulu za taby a štítky
      vieweru; `renderDetail()` zůstává `final`; `DetailContractTest` zelený
- [x] formulář cizí tabulky má záložku z modulu na konci pro PHP, JSONC
      i automatický formulář; sloupec z rozšíření se uloží, v read-only
      stavu se odmítne
- [x] seznam cizí tabulky má spany z modulu na konci `t2`; dekorátor se
      volá jednou na stránku
- [x] fixtury v `tests/Fixtures/modules/test/…` (modul s rozšířením
      testovací tabulky) pokrývají všechny tři druhy; žádný skutečný modul
      zatím mechanismus nepoužívá
- [x] dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný
- [x] celá sada PHPUnit lokálně zelená

## Implementační poznámky

Hotovo 2026-11-25 ve čtyřech commitech podle zadání. Frontend se neměnil.

**Jak je to udělané.**

- **Registr a izolace.** `Shipard\Core\Module\ModuleUiExtensions` se staví
  z resolvovaných modulů uvnitř `ViewerLoader::load()` a `FormLoader::load()`
  (týž `ModuleResolver::resolve`, žádné další načítání stromu) a registry
  ho předávají dál. Izolace je na jednom místě — `call()`: chybějící třída,
  špatné rozhraní, výjimka poskytovatele i výsledek mimo kontrakt skončí
  `ErrorLogger::error` „Module UI extension failed“ (modul, třída, tabulka,
  druh, výjimka) a `null`. Instance poskytovatele je jedna na registr.
- **Kontext** `ModuleExtensionContext` nese kromě zadaných čtyř věcí i jméno
  tabulky a její definici (`tableDef`) — záložka formuláře z ní bere popisky
  sloupců a sběr proti ní kontroluje, co záložka edituje.
- **Kontrola výsledku je ve sběru, ne v testu pravidel**:
  `Viewer\Detail\DetailExtensions` (taby jen se sdílenými typy obsahu,
  štítky `{label, style}`), `Form\ModuleFormTabs` (typ `fields`, prvky
  s hodnotou jen nad zapisovatelným sloupcem), `Viewer\RowDecorations`
  (spany `{text, class?, badge?}`). Porušení je selhání poskytovatele jako
  výjimka a přeskakuje se **celý** poskytovatel (u detailu taby i štítky).
- **Prefix id dělá sběr**, ne poskytovatel: tab detailu i záložka formuláře
  dostanou `<id modulu>.<id>`. Poskytovatel píše krátké id a nemůže prefix
  zapomenout.
- **Pravidla nad stromem** (`ModuleUiExtensionRules`) navíc hlídají, že
  třída jde vytvořit bez argumentů konstruktoru a že tatáž třída není pro
  tabulku v jednom druhu registrovaná dvakrát.

**Odchylky od zadání.**

- **`recalculate` PHP formuláře nejde přes `resolveFormDefinition()`** —
  `TableForm::recalculate()` si definici staví sám. Záložky z modulů proto
  přidává `FormController::withModuleTabs()` na dvou místech: na konci
  `resolveFormDefinition()` a za PHP větví `recalculate()`. Bez toho by
  záložka po prvním přepočtu formuláře dokladu zmizela (test
  `testModuleTabSurvivesRecalculate` pro všechny tři cesty).
- **Sub-tabulky zůstaly beze změny.** `resolveSubtableContext()` hledá jen
  taby typu `subtable` a ty jsou v záložce modulu zakázané. Stejně tak
  dohledání lookupů po uložení jde nad definicí bez záložek modulů
  (`resolveBaseFormDefinition()`), aby se poskytovatel nevolal dvakrát.
- **Stromový layout** (`tree`, #169) má buňky jako grid, ne `t2` —
  dekorátory se ho netýkají.
- **Citlivé sloupce** (`sensitive`) záložka modulu editovat nesmí:
  whitelist `getEditableSensitiveColumns()` patří formuláři tabulky
  a uložení by je odmítlo.
- Signatury `DetailExtension` a `RowDecorator` nesou `string $table` podle
  návrhu v zadání; `ModuleFormTab::build()` ji bere z kontextu.

**Fixtury.** `tests/Fixtures/modules/test/uiHost` (tabulka
`test_uihost_records`, `tableId` 9003, JSONC i PHP formulář — PHP třída
není v `module.jsonc`, testy ji registrují samy, aby šly testovat všechny
tři cesty) a `test/uiExtensions` (sloupec `contact_payment` přes
`extensions`, tři platní poskytovatelé v `module.jsonc` a vadné varianty
jen jako třídy pro syntetické registrace). Společné zázemí testů:
`tests/Unit/Core/Module/UiExtensionFixtures.php`.

**Ověření.** Unit sada 12 750 testů zelená. Integrační testy nad zdrojem
dat `blkm-i` (po `ds-upgrade` kvůli tabulkám Míst): `DocumentRoundTripMatrixTest`,
`DocumentFormInstructionInvariantTest`, `LedgerViewerTest`,
`CasesViewerTest` zelené; v `ViewerDetailContractTest` padá jediný test
`testOverviewDoesNotRepeatHeader` na vieweru `base.places.kinds` (#169,
položka „Typ místa“ opakuje titulek) — nesouvisí s tímto taskem, žádný
skutečný modul mechanismus zatím nepoužívá.

**Na co navazuje.** Doplněk Evidence tržeb (#171) a task C2 (akce záznamu
s obsluhou v modulu, D12–D15).
