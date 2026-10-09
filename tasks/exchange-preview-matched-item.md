# Task: Review přijaté faktury — napárovaná položka v řádku a změna napárování

**Stav:** hotovo

**Issue:** #111

**Cíl:** V review modalu přijaté faktury je u každého řádku vidět, na kterou
položku z našeho číselníku se napároval a odkud napárování pochází — bez
najíždění myší. Napárovanou položku jde v review změnit stejně jako
nenapárovanou. Účet řádku jde s položkou a oprava napárování se propíše do
naučeného mapování kódu dodavatele, aby se chyba příště neopakovala.

## Před implementací přečti

- `docs/exchange-format.md` — §8.2 ItemResolver (mapování kódů dodavatele),
  §9 `_resolve` (příklad, „Audit bloky navíc“, slovník `userAction`)
- `modules/core/mail/docs/ai-analysis.md` — sekce „Obohacení řádků
  z historie“, „Obsahová eskalace“ a odstavec **UI** (sloupec Účet, `noItem`)
- `tasks/exchange-preview-vat-choices.md` — vzor: badge klikací i u
  napárované hodnoty (D16), nový náhled po uložení rozhodnutí (D15),
  `saveSeq` / `refreshSeq` v modalu
- `tasks/mail-review-decisions-persist.md` — persistence rozhodnutí (#76),
  whitelist cest `userActions`
- `tasks/content-tag-ui.md` — D23 (sloupec Účet), D24 (`noItem`)
- `docs/accounting.md` — vlajky operací (`rowAccount`) a předpis pro
  `invni`: proč zaúčtování účet řádku s položkou nečte
- `docs/help-authoring.md` a `help/posta/kontrola-vytezeni.md`

## Kontext — co je dnes

- **Zobrazení.** Buňka Položka (`DocumentExchangePreview.svelte`, tabulka
  řádků) nese text řádku, kód dodavatele, badge stavu (`statusBadge`)
  a u obohacených řádků ikonu ⟲ (`enrichBadge`). Tooltip ✓ je jen
  „Napárováno na #{id}“ — `_resolve.rows[i].item` má u `matched` jen
  `status`, `matchedId`, `matchedBy` (`ResolveResult::toArray`). Kód
  a název položky jsou jen v tooltipu ⟲ (`enrichment.itemName`), a to jen
  u řádků z historie nebo obsahové klasifikace. U napárování kódem
  dodavatele, EAN, SKU nebo názvem se uživatel název nedozví.
- **Změna.** `statusBadge` je interaktivní jen pro `status !== 'matched'`.
  Backend změnu umí už dnes: `DocumentApplier::resolveOne` přijme
  `useExisting:<id>` i nad `matched` blokem (vrací pinnuté id, bez
  `conflict`). Rozhodnutí o položce nový náhled nespouští — štítek po volbě
  je „Bude použito #{id}“, v panelu „Vybráno: Použít #{id}“.
- **Účet.** Sloupec Účet ukazuje `canonical.rows[i].account` — u řádku
  s položkou je to kopie, kterou doplnila historie (`RowHistoryEnricher`,
  trojice ourCode + kód DPH + účet) nebo obsahová klasifikace
  (`suggested.account`). Zaúčtování přijaté faktury účet řádku s položkou
  nečte: pohyb se odvodí z druhu položky (`applyRowOperations.jsonc`) —
  účetní položka → `acc.entry` → `accountSrc: "item"`
  (`accounting_account` položky), služba / zásoba / ostatní → `purchase.*`
  → maska kategorie. Na volném dev zdroji mají všechny řádky s položkou
  i účtem účet shodný s účtem položky.
- **Učení.** `DocumentApplier::writeSupplierCodeMappings` i
  `SupplierCodeCaptureHandler` zapisují `economy_items_supplier_codes`
  přes `INSERT IGNORE` (unique `person + supplier_code`). Jednou naučené
  špatné mapování oprava nepřepíše a příští faktura se napáruje stejně
  špatně. Historie řádků se opraví sama — `loadHistory` řadí
  `ORDER BY h.id DESC`, vyhrává nejnovější doklad.
- **Náhled s rozhodnutími.** `AnalysisController::previewMessage` už před
  `applier->preview()` merguje uložená rozhodnutí do `_resolve`
  (`MessageProposalApplier::mergeUserActions`), cesta `rows[i].item` je ve
  whitelistu. Applier je v náhledu dnes nečte (kromě voleb DPH).

## Rozhodnutí (potvrzená v #111)

Zobrazení:

- ✓ **D1** — Napárovaná položka natrvalo jako druhý řádek pod textem řádku
  v buňce Položka (menší písmo, šedě) — ne v tooltipu, ne v novém sloupci.
  Obsah = co apply zapíše na řádek dokladu: kód a název naší položky.
  Rozhodnuté stavy slovy („jen účet …“, „řádek se vynechá“). Nenapárovaný
  řádek bez rozhodnutí druhý řádek nemá — stav nese badge.
- ✓ **D2** — Za názvem krátký zdroj napárování: kód dodavatele, EAN, SKU,
  náš kód, z historie, podobný text, podle názvu, častá položka
  dodavatele, kategorie {štítek}, zvoleno ručně. Slabé zdroje jantarově
  (`enrichment.confidence` medium/low, resolver `matchedBy: name`), silné
  šedě. Ikona ⟲ zaniká, její detail (zdrojový doklad, doplněná pole,
  upozornění k odpočtu DPH) přechází do tooltipu druhého řádku. Souhrn
  „Z historie: N ř.“ v nadpisu sekce zůstává.
- ✓ **D3** — Kód a název dodává server: `_resolve.rows[i].item.display`
  u `matched` i u uložené volby `useExisting`. Po rozhodnutí o položce se
  náhled obnoví stejně jako po volbě DPH; do obnovení frontend ukáže název
  z vybraného výsledku hledání. U „jen účet“ server dodá i název účtu.

Změna napárování:

- ✓ **D4** — Klik na ✓ u napárované položky otevře týž
  `ResolveDecisionPanel` jako u nenapárované. Klikací je i druhý řádek
  (větší cíl, tatáž akce).
- ✓ **D5** — Panel u napárované položky: nahoře „Napárováno automaticky:
  kód název (zdroj)“, pod tím hledání, vytvoření nové položky, Jen účet
  (když řádek nese účet) a Vynechat řádek. Po volbě je ✓ obtažený
  (`matchedDecided`), „Zrušit výběr“ vrací automatické napárování.
  Reconcile beze změny. Server dodá `createPayload` i u `matched` bloku.
- ✓ **D6** — Hromadné „+“ v hlavičce sloupce Položka zůstává jen pro
  nenapárované řádky; napárované se mění po jednom.
- ✓ **D7a** — Kód DPH se při změně položky nemění — je z analýzy.
- ✓ **D7b** — Účet jde s položkou. Sloupec Účet ukazuje u řádku s položkou
  účet, podle kterého se zaúčtuje: účetní položka → její účet; služba nebo
  zásoba bez vlastního účtu → „—“. Při ruční volbě položky se účet doplněný
  historií nebo štítkem na řádek nezapíše. Řádek „jen účet“ si účet
  nechává.
- ✓ **D8** — Ruční volba položky v review přepíše naučené mapování kódu
  dodavatele (upsert místo `INSERT IGNORE`) — jen pro řádek se
  `supplierCode`, kde uživatel položku výslovně zvolil.

### Upřesnění z kódu (potvrzená)

- ✓ **U1 (k D2)** — ⟲ zůstává jen u řádku, který nese enrichment, ale druhý
  řádek nemá (obsahová klasifikace jen s návrhem účtu, `resolution:
  guarded`, návrh bez napárování). Jinak by u nerozhodnutého řádku zmizela
  kategorie a upozornění k odpočtu DPH. U řádku s druhým řádkem ⟲ není.
- ✓ **U2 (k D1, D5)** — Popisek volby je ve skutečnosti **Vynechat řádek**
  (`exchange.preview.decide.skipRow`); druhý řádek proto „řádek se vynechá“.
- ✓ **U3 (k D5)** — `createPayload` u `matched` nese jen `name`
  a `description`. Bez `code`, `sku` a `ean`: to jsou identifikátory, přes
  které se řádek napároval (`ourCode` z historie = kód existující
  položky), nová položka by s nimi kolidovala.
- ✓ **U4 (k D3)** — Panel v řádku „Vybráno: …“ ukazuje název zvolené
  položky, ne „Použít #{id}“.
- ✓ **U5** — Příklad v `docs/exchange-format.md` §9 uvádí u položky
  `itemId`; resolver vrací `matchedId`. Opravit při úpravě §9.

## Kontrakt — nové bloky v `_resolve` (jen `/preview`)

```jsonc
"rows": [
  {
    "index": 0,
    "item": {
      "status": "matched", "matchedId": 18, "matchedBy": "ourCode",
      // D5, U3: předvyplnění formuláře nové položky — jen name + description
      "createPayload": { "name": "Toner černý", "description": "" },
      // D3: efektivní položka — volba useExisting > napárování
      "display": { "id": 18, "code": "SPOTR-TON", "name": "Tonery a náplně", "pinned": false }
    },
    // D7b: účet, podle kterého se řádek zaúčtuje (nebo návrh účtu řádku)
    "effectiveAccount": { "id": 412, "number": "501300", "name": "Spotřeba materiálu", "source": "item" }
  }
]
```

`item.display`:

| Řádek | `display` |
|---|---|
| volba `useExisting:<id>`, položka existuje | `{id, code, name, pinned: true}` |
| bez volby, `status: matched` | `{id, code, name, pinned: false}` |
| jinak (`noItem`, `skip`, nerozhodnuto, neexistující id) | klíč chybí |

`effectiveAccount` (`null` = sloupec ukáže „—“):

| Řádek | `effectiveAccount` |
|---|---|
| volba `skip` | `null` |
| kontační řádek (`accSide`) nebo volba `noItem` | účet řádku (`_resolve.rows[i].account`, je-li `matched`), `source: "row"` |
| efektivní položka (jako `display`) | účet položky, když `item_type` = 2 a `accounting_account` je vyplněný, `source: "item"`; jinak `null` |
| bez efektivní položky (nerozhodnuto, `canCreate`, `ambiguous`, `notFound`) | účet řádku jako návrh (dnešní chování), `source: "row"`; jinak `null` |

## Kroky a commity

### Commit 1 — `feat(exchange): náhled — položka a účet řádku ze serveru, předvyplnění nové položky i u napárované (#111 D3, D5, D7b, 1/3)`

`modules/core/exchange/src/Resolve/ItemResolver.php`:

- u každého `matched` výsledku (`ourCode`, `supplierCode`, `ean`, `sku`,
  `name`) přidat `createPayload` jen s `name` a `description` (U3) —
  konstruktor `ResolveResult` ho bere; `ResolveResult::matched()` rozšířit
  o volitelný parametr, nebo `new ResolveResult(...)` přímo. Bez názvu
  (`name === null`) payload prázdný. `canCreate` beze změny.

`modules/core/exchange/src/Document/DocumentApplier.php`:

- nová privátní `annotateRowDisplay(array $resolved, array $clientResolve): array`
  volaná v `preview()` po `resolveAll()` (před `computePreviewAmounts`):
  - volbu řádku čte stejně jako `reconcile()` (`$clientResolve['rows'][$i]['item']['userAction']`);
  - jedním dotazem načte efektivní položky:
    `economy_items` (`id`, `code`, `name`, `item_type`, `accounting_account`)
    + `LEFT JOIN economy_accounting_accounts` (`number`, `name`); účty řádků
    (`_resolve.rows[i].account.matchedId`) druhým dotazem — žádný dotaz
    per řádek;
  - doplní `rows[pos].item.display` a `rows[pos].effectiveAccount` podle
    tabulek výše; `pinned` podle volby;
  - položka z volby neexistuje nebo je smazaná → `display` chybí (apply ji
    stejně odmítne `conflict`).
- `apply()` ani `reconcile()` se v tomto commitu nemění.

Dokumentace:

- `docs/exchange-format.md` §9 — `display`, `effectiveAccount` a
  `createPayload` u `matched` do příkladu a do „Audit bloky navíc“; slovník
  `userAction`: `useExisting` platí i nad `matched` (přebije automatické
  napárování), doplnit chybějící `noItem`; opravit `itemId` → `matchedId`
  (U5). §8.2: `matched` nese `createPayload` pro předvyplnění.

Testy:

- `tests/Unit/Module/Core/Exchange/Resolve/ItemResolverTest.php` — matched
  nese `createPayload` `{name, description}` bez `code` / `sku` / `ean`;
  `canCreate` beze změny.
- nový `tests/Unit/Module/Core/Exchange/Document/DocumentApplierRowDisplayTest.php`
  (harness jako `DocumentApplierNoItemTest`, `fetchAll` vrací položky
  a účty):
  - matched řádek → `display` s `pinned: false`; účetní položka s účtem →
    `effectiveAccount` `source: item`;
  - volba `useExisting` nad matched → `display` volené položky,
    `pinned: true`, účet volené položky;
  - položka druhu služba bez účtu → `effectiveAccount` `null`, i když
    canonical řádek nese účet z historie;
  - `noItem` → účet řádku `source: row`, bez `display`;
  - `skip` → bez `display`, `effectiveAccount` `null`;
  - nerozhodnutý `canCreate` s účtem z obsahové klasifikace → účet řádku
    `source: row`;
  - volba na neexistující id → bez `display`;
  - počet dotazů nezávisí na počtu řádků.
- `vendor/bin/phpunit --filter 'ItemResolverTest|DocumentApplierRowDisplayTest|DocumentApplierNoItemTest|DocumentApplierPreviewComputedTest'`

### Commit 2 — `feat(exchange): ruční volba položky — účet z historie se nezapíše, mapování kódu dodavatele se přepíše (#111 D7b, D8, 2/3)`

`modules/core/exchange/src/Document/DocumentApplier.php`:

- `reconcile()`:
  - nový klíč plánu `rowItemPins` (list indexů) — řádky s volbou
    `useExisting:<id>` (platná, `resolveOne` vrátil id);
  - D7b: u řádku v `rowItemPins` porovnat číslo účtu řádku
    (`$rowResolve['account']['number']`) s `enrichment.suggested.account`
    téhož řádku (enrichment hledat v `$clientResolve['rows']` podle klíče
    `index`, ne podle pozice). Shoda → `resolvedRowAccounts[$i] = null`.
    Účet od uživatele nebo z AI (bez shody se `suggested.account`) zůstává.
    `noItem` se nemění.
- `writeSupplierCodeMappings()` (D8): řádek v `rowItemPins` →
  `INSERT … ON DUPLICATE KEY UPDATE [item] = VALUES([item]), [supplier_name] = VALUES([supplier_name])`;
  ostatní řádky dál `INSERT IGNORE`. `SupplierCodeCaptureHandler` beze
  změny.
- `docs/exchange-format.md` §8.2 — výslovná volba položky v review
  mapování přepíše, automatické napárování a potvrzení z Konceptu ne.
- `modules/core/mail/docs/ai-analysis.md` — v sekci o obohacení řádků
  věta, že ruční volba položky odvolá účet navržený historií nebo štítkem
  (kód DPH zůstává, D7a).

Testy (v `DocumentApplierRowDisplayTest` nebo novém
`DocumentApplierItemPinTest`, SQL zachytit přes `executeSql`):

- pin nad matched řádkem s účtem z historie → uložený řádek bez `account`,
  kód DPH beze změny;
- pin s účtem, který se `suggested.account` neshoduje → účet zůstává;
- pin u řádku se `supplierCode` → `ON DUPLICATE KEY UPDATE`; matched bez
  pinu → `INSERT IGNORE`; `noItem` a `skip` → žádný zápis mapování;
- `noItem` dál vyžaduje účet (`no_item_requires_account`).
- `vendor/bin/phpunit --filter 'DocumentApplier'`

### Commit 3 — `feat(mail): review — napárovaná položka v řádku, změna napárování, účet podle položky; help; task hotový (#111 D1–D7, 3/3)`

Nový `frontend/src/components/exchange/rowMatch.js` (čisté helpery, vzor
`enrichBadge.js`, bez Svelte):

- `itemDecision(userActions, i)` → `{kind: 'useExisting', id} | {kind: 'noItem'} | {kind: 'skip'} | null`;
- `matchSourceKey(itemBlock, enrichment, display)` → `user` (pin) |
  `historyExact` | `historyFuzzy` | `historyDominant` | `contentTag`
  (napárováno `ourCode` a `display.code === enrichment.suggested.ourCode`)
  | `ourCode` | `supplierCode` | `ean` | `sku` | `name` (podle
  `item.matchedBy`);
- `isWeakSource(key)` → `true` pro `historyFuzzy`, `historyDominant`,
  `contentTag`, `name`.
- test `frontend/tests/Unit/rowMatch.test.mjs`.

`DocumentExchangePreview.svelte`:

- **druhý řádek** (D1, D2) pod `.shpd-exchange__row-name`:
  - efektivní položka → `↳ {code} {name} · {zdroj}`; `--weak` modifikátor
    jantarově (existující token pro text varování, žádná nová barva;
    ověřit čitelnost ve světlém i tmavém vzhledu);
  - `noItem` → „jen účet {číslo} {název}“ z `effectiveAccount`;
  - `skip` → „řádek se vynechá“;
  - nerozhodnutý nenapárovaný řádek → nic;
  - tooltip: u enrichmentu dnešní `enrichTitle(e)`, jinak text zdroje;
  - v interaktivním režimu `<button>` vzhledu textu, který otevře tentýž
    popover jako badge (D4); v read-only (`onUserActionsChange === null`,
    tab Návrh v detailu zprávy) prostý text.
- ⟲ jen podle U1.
- `statusBadge`: interaktivní i u `matched`, **jen pro `kind === 'item'`**
  (strany a bankovní účet beze změny); `effectiveStatusKey` u položky
  nejdřív volba, pak status (dnes vrací `matched` před kontrolou volby).
  Tooltip ✓: „Napárováno: {kód} {název}“ / „Zvoleno: …“ místo „#{id}“.
- **optimistický štítek** (D3): `ResolveDecisionPanel` volá
  `onDecide(action, {label})` — label z `item.primary` výsledku hledání,
  z kandidáta, z uloženého záznamu po „Vytvořit novou položku“; preview
  drží `pendingLabels[path] = {action, label}` (i pro bulk cesty) do
  doby, než nový náhled přinese `display` se stejným id.
- **sloupec Účet** (D7b): `hasAccountColumn` podle
  `_resolve.rows[i].effectiveAccount` (bez `_resolve` fallback na
  `canonical.rows[i].account` jako dnes); buňka `number`, název do
  `title`; badge stavu účtu jen při `source: "row"`.
- `bulkItemIndices` beze změny (D6).
- Popover: `ResolveDecisionPanel` dostane `automaticLabel` (D5 — „Napárováno
  automaticky: …“, jen nad `matched`) a `currentLabel` pro „Vybráno: …“
  (U4).

`ResolveDecisionPanel.svelte`: props `automaticLabel`, `currentLabel`;
`onDecide(action, meta)`. Pro `kind` ≠ `item` se nic nemění.

`DocumentExchangePreviewModal.svelte`: `vatChoiceChanged` → obecné „po
uložení obnovit náhled“ i pro `rows[i].item` (regex
`^(vat\.(place|mode)|rows\[\d+\]\.(vatCode|item))$`); upravit hlavičkový
komentář („rozhodnutí o položkách nový náhled spouští — D3, #111“).

i18n (`cs.js` + `en.js`, `npm run check:i18n`) — návrh klíčů:

- `exchange.preview.match.source.{supplierCode,ean,sku,ourCode,historyExact,historyFuzzy,historyDominant,name,user}`
  a `exchange.preview.match.source.contentTag` s `{tag}`;
- `exchange.preview.match.noItem` („jen účet {account}“),
  `exchange.preview.match.skip` („řádek se vynechá“),
  `exchange.preview.match.automatic` („Napárováno automaticky: {label} ({source})“);
- `exchange.preview.status.matchedItem` („Napárováno: {label}“),
  `exchange.preview.status.decided.useItem` („Zvoleno: {label}“).
- Klíče `exchange.preview.status.matched` a `decided.useExisting` zůstávají
  pro strany.

Help (stejný commit):

- `help/posta/kontrola-vytezeni.md` — v postupu u řádků: pod textem řádku
  je položka z číselníku a zdroj, jantarové ověřit; změna klikem na ✓
  nebo na řádek s položkou, **Zrušit výběr** vrací automatické napárování;
  oprava se zapamatuje pro kód dodavatele. Odstavec **Doplněno
  z historie** — zdroj je za názvem položky, detail po najetí myší.
  Odstavec o obsahové klasifikaci a sloupci **Účet** — účet ukazuje, kam
  se řádek zaúčtuje (u položky její účet). Klíčová slova: napárování,
  špatná položka, změnit položku.
- `help/slovnicek.md` — heslo **Doplněno z historie** („poznámka u pole“
  → zdroj u položky řádku).
- Názvy ověřit v `frontend/src/i18n/cs.js`; `python3 scripts/help-index.py`.

Uzavření: `**Stav:**` v tomto tasku + `python3 scripts/tasks-index.py`;
`cd frontend && npm run test && npm run check:i18n && npm run build`.

## Odchylky při implementaci (2026-10-09)

- **Vynechání řádku opraveno.** Náhled posílá `rows[i].item = skip`, ale
  `reconcile()` plnil `rowSkips` jen z řádkové `rows[i].userAction` —
  řádek s **Vynechat řádek** zůstával na dokladu bez položky. Item-level
  `skip` teď řádek do `rowSkips` přidá (Commit 2, test
  `DocumentApplierItemPinTest::testItemLevelSkipLeavesRowOutOfDocument`).
- **Read-only větev** (`onUserActionsChange === null`) nemá živé použití —
  tab Návrh v detailu zprávy náhled nerenderuje, jen souhrn a tlačítko do
  modalu. Druhý řádek je v ní implementovaný jen defenzivně (prostý text);
  ověřovací bod 5 níže je neaplikovatelný.
- `DocumentApplier` dostal konstruktorový parametr
  `itemsHaveAccountingAccount` (z `$tables['economy_items']` v `create()`),
  aby dotaz na účet položky přežil zdroj dat bez `economy.accounting`.
- Po ruční volbě nese `display` zvolenou položku; „Napárováno automaticky“
  v panelu pak ukazuje jen `#id` původního napárování (název server nenese).

## Ověření na dev zdroji (`lh6x-l`, režim volný)

1. Zpráva s řádky napárovanými historií a obsahovou klasifikací: druhý
   řádek s kódem, názvem a zdrojem; častá položka a kategorie jantarově.
2. Změna položky napárovaného řádku: popover z ✓ i z druhého řádku, po
   volbě hned název (bez „#id“), po obnovení náhledu ✓ obtažený,
   „zvoleno ručně“, sloupec Účet ukazuje účet nové položky.
3. **Zrušit výběr** → zpět automatické napárování.
4. **Vystavit koncept** → řádek dokladu má zvolenou položku a účet
   z historie na něm není (SQL); `economy_items_supplier_codes` pro kód
   dodavatele řádku míří na zvolenou položku.
4b. **Vynechat řádek** → **Vystavit koncept** → vynechaný řádek na dokladu
   není (oprava, viz Odchylky).
5. ~~Detail zprávy, tab Návrh (read-only): druhý řádek bez klikání.~~ —
   neaplikovatelné, tab náhled nerenderuje (viz Odchylky).
6. Světlý i tmavý vzhled: jantarová čitelná.

## Mimo rozsah

- Badge napárovaného dodavatele, odběratele a bankovního účtu — klikací
  se nestávají.
- Hromadná změna napárovaných řádků (D6).
- Přepis mapování při opravě v Konceptu (`SupplierCodeCaptureHandler`) —
  otevřené v #111 D8.
- Kaskáda kódu DPH při změně položky (D7a).
- „Jen účet — bez položky“ dnes nejde potvrdit ani zaúčtovat — řeší #36
  (včetně textu v `help/posta/kontrola-vytezeni.md`, který tvrdí opak).

## Pasti

- **`statusBadge` je sdílený se stranami.** Interaktivitu u `matched`
  rozšiřovat jen pro `kind === 'item'`.
- **`_resolve.rows` čti podle `index`.** Enrichment se zapisuje s klíčem
  `index` (`RowHistoryEnricher::writeEnrichment`), volby přes
  `mergeUserActions` na klíč pole. V praxi se shodují, ale D7b porovnání
  hledá enrichment podle `index`.
- **`accounting_account` je extension `economy.accounting`.** Bez modulu
  sloupec chybí — dotaz `annotateRowDisplay` musí to přežít (zjistit
  přítomnost sloupce, jinak `effectiveAccount` z položky `null`), náhled
  nesmí spadnout.
- **`createPayload` u `matched`.** `resolveOne` a `autoCreate` ho čtou
  jen u `canCreate`; ověřit, že žádné jiné místo nepodmiňuje přítomností
  `createPayload` (summary, safety guard). U3 — bez `code` / `sku` / `ean`.
- **Obnovení náhledu až po úspěšném uložení** (`persist()` vrací true) —
  jinak server ukáže stav bez volby. `refreshSeq` zahodí starší odpověď.
- **D7b jen při shodě** se `suggested.account` — účet, který přišel z AI
  nebo od uživatele, se nesmí smazat.
- **D8 jen pro výslovnou volbu.** Automatické napárování a bulk bez volby
  dál `INSERT IGNORE`. `VALUES()` v `ON DUPLICATE KEY UPDATE` MariaDB
  podporuje.
- **JS jen s ASCII uvozovkami**; české „…“ patří jen do textů v i18n.

## Hotovo když

- [x] Druhý řádek u každého napárovaného a rozhodnutého řádku, zdroj
      a jantarové zvýraznění slabých zdrojů (D1, D2, U1).
- [x] `display`, `effectiveAccount` a `createPayload` u `matched`
      v `/preview` (D3, D5, U3).
- [x] Změna napárované položky z ✓ i z druhého řádku, optimistický
      štítek, obnovení náhledu (D3, D4, D5, U4).
- [x] Sloupec Účet podle `effectiveAccount`; účet z historie se po ruční
      volbě nezapíše (D7b).
- [x] Mapování kódu dodavatele se po ruční volbě přepíše (D8).
- [x] Testy PHPUnit (filtry výše), `npm run test`, `npm run check:i18n`,
      `npm run build`.
- [x] `docs/exchange-format.md`, `modules/core/mail/docs/ai-analysis.md`,
      help a slovníček aktualizované; `help-index.py`, `tasks-index.py`.
- [x] Ověření na dev zdroji body 1–4b, 6 (2026-10-09).
- [ ] Komentář do #111 se shrnutím.
