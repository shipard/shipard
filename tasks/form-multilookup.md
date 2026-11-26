# Formulář — výběr více záznamů z tabulky, prvek `multilookup` (#176 D1–D10)

**Stav:** částečně — backend (prvek, `MultiLookupBinding`, `FormController`, fixture modul, testy) a frontend (`MultiLookupInput`, chyby pole, dirty stav; ověřeno v prohlížeči nad fixture modulem na jednorázovém zdroji dat) hotové 2026-11-26; zbývá dokumentace (commit 3)

> Task pro Claude Code (3 commity). Design: issue #176. Fáze 0 doplňku
> Budovy (#174 D6, D11) — první skutečné použití bude pole Místa na
> smlouvě; tento task ho neimplementuje, testuje na fixture modulu.
> Nezávislý na `tasks/periodic-invoicing-sources.md`.

## Kontext

- **`lookup`** (`docs/edit-forms.md` §4.7, kap. 22): jeden záznam z velké
  tabulky; hledání `GET /_ui/lookup/{table}/search`, předřešení hodnoty do
  `dataResolved` (`FormController::buildDataResolved()` /
  `resolveLookupValuesForRecord()`, prvky sbírá `collectLookupElements()`),
  filtry z přepočtu formuláře (`filter` v definici prvku). Frontend
  `components/ui/LookupInput.svelte`.
- **`multiselect`** (§4.2b): víc hodnot z pevné nabídky, sloupec `json`;
  frontend `components/ui/MultiselectInput.svelte` (štítky, nabídka jako
  vrstva `utils/layerStack.js`).
- **Child tabulky** (`docs/document-system.md` §6): rodič je deklaruje
  v `childTables` (`table`, `foreignKey`, `dataKey`). `TableGateway`
  je načte do `data[dataKey]` (`loadDocument`) a při uložení synchronizuje
  jen přítomný klíč — řádek bez `id` vloží, s `id` upraví, chybějící
  smaže.
- **Formulář dnes child sety nenese:** `FormController::meta()` čte jen
  řádek tabulky (`SELECT *`), `save()` propustí přes
  `filterWritableFields()` jen sloupce. Chyby polí rozbaluje
  `FormEditor.svelte::extractValidationErrors()` jen pro přesnou shodu
  `field` se sloupcem prvku.

## Rozhodnutí (#176, schválena 2026-11-26)

- **D1** — Prvek `multilookup`: hodnota ve formuláři = pole id
  odkazovaných záznamů (`[12, 7]`); úložiště = child tabulka z `childTables`
  rodiče, `column` prvku = její `dataKey`.
- **D2** — Odkazový sloupec = jediný sloupec child tabulky s `reference` na
  tabulku lookupu (mimo `foreignKey`); jinak chyba deklarace a výslovný
  `refColumn`. Pořadí v `order_pos`, má-li ho child tabulka.
- **D3** — Převod v jádru formuláře: načtení child set → seznam id podle
  pořadí, `dataResolved[column]` = seznam položek; uložení seznam → řádky
  (stejný odkaz si nechá `id`, nový bez `id`, chybějící vypadne →
  synchronizace gatewaye). Klíč projde jen u formuláře s prvkem; Document
  třída vidí řádky child tabulky.
- **D4** — Pořadí podle výběru, bez přetahování.
- **D5** — Duplicitu frontend nedovolí a server odmítne; chyba
  `places.1.place` se ukáže u celého pole, zpráva jmenuje záznam.
- **D6** — Hledání přes `TableLookup` odkazované tabulky, filtry jako
  u `lookup`; vybrané záznamy se v nabídce skryjí; bez úpravy a založení
  záznamu z pole.
- **D7** — Štítky ve vstupu (vzhled `MultiselectInput`), hledání jako
  `LookupInput` (zpoždění 300 ms, šipky, Enter, Esc zavře jen nabídku,
  Backspace v prázdném vstupu odebere poslední štítek); jen ke čtení =
  štítky bez křížku; na telefonu se zalamují.
- **D8** — Detail bez nového typu obsahu (blok `table` s `open_detail`).
- **D9** — Další sloupce vazby mimo prvek: nový řádek dostane výchozí
  hodnoty, existující se nemění.
- **D10** — `TabBuilder::multiLookup()`, JSONC, `FormContractTest`,
  detekce neuložených změn, dokumentace, fixture modul.

## Před implementací přečti

- `docs/edit-forms.md` §4.2b, §4.7, kap. 8 (chyby polí), kap. 22 (lookup,
  `dataResolved`, cascade přes přepočet), kap. 15 (sub-tabulky — co tímto
  prvkem **nevzniká**)
- `docs/document-system.md` §6 (child tabulky, kdy gateway na child sety
  sahá)
- `docs/edit-forms-cookbook.md`, `docs/frontend.md` (formulář, vrstvy)

## Kroky

### Commit 1 — backend

- `FormElement`: typ `multilookup` v `ALLOWED_TYPES`; povinný `column`
  a `lookup.table` (jako `lookup`); nesmí do `inline`; v definici nepovinně
  `lookup.refColumn`. `TabBuilder::multiLookup(string $column, string
  $table, ?array $filter = null, ?string $label = null, ?string
  $placeholder = null, bool $required = false, bool $readOnly = false,
  bool $hidden = false, ?string $triggers = null, ?string $hint = null,
  ?string $refColumn = null)`; `JsoncFormLoader` prvek přijme.
- **Vazba prvku** (`Core\Form\MultiLookupBinding`, čistá třída nad
  `TableDefinition` rodiče a definicemi tabulek): najde položku
  `childTables` s `dataKey === column`, odvodí odkazový sloupec (D2) a
  sloupec pořadí. Chybějící child tabulka, žádný nebo víc kandidátů bez
  `refColumn`, `refColumn` mimo child tabulku = `\LogicException`
  s tabulkou a sloupcem. `FormContractTest` (nebo nový test nad stromem)
  ověří vazbu u každého prvku `multilookup` ve všech formulářích.
- `FormController`:
  - **meta** (a odpovědi save, přechodu stavu): pro každý prvek načte
    child set rodiče seřazený podle pořadí (pak `id`) → `data[column]` =
    seznam id; nový záznam = `[]`.
  - **`dataResolved[column]`** = seznam `{id, primary, secondary}` v pořadí
    hodnoty (`TableLookup::resolve()` jedním voláním; nenalezené id
    vynechat). `collectLookupElements()` → sběr obou typů, `buildDataResolved`
    a `resolveLookupValuesForRecord()` větví podle typu.
  - **přepočet**: seznam z dat projde do `recalculate()` formuláře
    a `dataResolved` se pro něj sestaví stejně.
  - **save**: `filterWritableFields()` propustí `column` prvku
    `multilookup` aktuální definice formuláře; seznam se převede na řádky
    (`MultiLookupBinding::toRows(array $ids, array $existingRows)`):
    existující řádek se stejným odkazem → `{id, <ref>, order_pos}`, jinak
    `{<ref>, order_pos}`; ostatní sloupce existujícího řádku se neposílají
    (gateway je neupraví, D9). Hodnota, která není seznam kladných celých
    čísel, nebo duplicita → 422 s chybou na `column` (D5) dřív, než se
    volá gateway. Prvek `readOnly` nebo formulář jen ke čtení klíč
    nepropustí.
- Fixture modul `tests/Fixtures/modules/test/multiLookup/`: rodič se
  `childTables`, child tabulka (cizí klíč, odkaz, `order_pos`, jeden
  sloupec navíc s výchozí hodnotou), cílová tabulka s `TableLookup`,
  formulář rodiče s prvkem.
- Testy: `FormElementTest` / `TabBuilderTest` / `JsoncFormLoaderTest`
  (nový typ, chyby deklarace), `MultiLookupBindingTest` (odvození, chyby,
  `toRows` — zachování id, pořadí, odebrání, sloupec navíc se neposílá),
  `FormController` meta / save / přepočet (`dataResolved` seznam, nový
  záznam `[]`, duplicita 422, readOnly), integrační test uložení přes
  `TableGateway` na dev zdroji dat (vložení, změna pořadí, odebrání; sloupec
  navíc existujícího řádku zůstane).

### Commit 2 — frontend

- `components/ui/MultiLookupInput.svelte` podle mockupu v #176: štítky
  (vzhled jako `MultiselectInput`), vstup hledá přes
  `/_ui/lookup/{table}/search` s filtrem prvku (stejný kód jako
  `LookupInput` — sdílenou část vytáhnout do `utils/`, ne kopírovat),
  vybraná id z výsledků odfiltrovat, nabídka jako vrstva zásobníku,
  klávesnice podle D7, `disabled` = štítky bez křížku a bez vstupu.
  Popisky štítků z `dataResolved[column]`; výběr / odebrání volá
  `onResolveChange(column, seznam)`.
- `FormElement.svelte`: větev `multilookup`; `FormEditor.svelte`:
  `extractValidationErrors()` přiřadí chybu s `field` začínajícím
  `<column>.` k prvku `multilookup` (víc zpráv spojit), detekce
  neuložených změn porovná seznamy podle obsahu a pořadí.
- Texty (`i18n/{cs,en}.js`): zástupný text, „Žádné výsledky“, „Načítám…“,
  popisek křížku „Odebrat {název}“ — kde už klíč pro `LookupInput` /
  `MultiselectInput` existuje, použít ho.
- Čisté funkce do `utils/multiLookup.js` (odfiltrování vybraných, přidání,
  odebrání posledního, mapování chyb) s testy v `npm test`.
- `npm run build`, `npm run check:i18n`, `python3
  scripts/check-css-tokens.py`, `check-css-units.py`, `check-contrast.py`.

### Commit 3 — dokumentace

- `docs/edit-forms.md`: nový §4.7b `multilookup` (deklarace, vazba na
  `childTables`, `refColumn`, pořadí, tvar hodnoty a `dataResolved`,
  převod při uložení, chyby, sloupce navíc); odkaz v kap. 22.
  `docs/edit-forms-cookbook.md`: recept. `docs/document-system.md` §6:
  věta, že formulář child set ukládá jen přes prvek `multilookup`.
  `CLAUDE.md` → Editační formuláře: řádek k `multilookup` vedle
  `select` vs `lookup`.
- `tasks/README.md` — `**Stav:**`, `python3 scripts/tasks-index.py`;
  komentář do #176.

## Hotovo když

- [ ] prvek `multilookup` jde zapsat v PHP builderu i v JSONC a chybná
      deklarace skončí srozumitelnou chybou v testu nad stromem
- [ ] formulář fixture modulu načte, přepočítá a uloží vazby; změna pořadí
      ani odebrání nemění id ostatních řádků, sloupec navíc zůstane
- [ ] duplicita a neplatná hodnota = 422 na poli, chyba Document třídy
      s cestou `<column>.N.<sloupec>` se ukáže u pole
- [ ] v prohlížeči (fixture nebo dočasně Místa na libovolném formuláři
      volného zdroje dat — nekommitovat) funguje hledání, klávesnice podle
      D7, jen ke čtení, telefon, dirty stav a Zrušit bez uložení
- [ ] dokumentace podle commitu 3, `**Stav:**` aktualizovaný

## Pasti

- **Chybějící klíč ≠ prázdný seznam.** Gateway synchronizuje jen přítomný
  `dataKey`; formulář s prvkem posílá vždy seznam (i `[]` = smazat vše),
  formulář bez prvku klíč nesmí poslat — jinak by uložení hlavičky
  vymazalo vazby jiným formulářem.
- **Child tabulky dokladů** (`docs_core_heads` → `rows`, `vatRecap`) se
  tímto prvkem nemění — whitelist v `filterWritableFields()` jde jen podle
  prvků aktuálního formuláře, ne podle `childTables`.
- **`dataResolved` je replace, ne merge** (kap. 22) — seznam musí přijít
  celý v každé odpovědi meta / přepočtu / uložení, jinak štítky ztratí
  popisky.
- **Pořadí `order_pos` přepisovat jen u měněných řádků není nutné** —
  posílá se u všech řádků seznamu; gateway upraví jen poslané sloupce.
- **Lookup s `getAllowedFilterKeys()`** — filtr prvku projde jen
  s klíči z whitelistu, stejně jako u `lookup`; ověřit v testu.
