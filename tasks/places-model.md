# Místa — model, formuláře a číselníky v Nastavení (task A)

**Stav:** naplánováno — design #169 D1–D18, navazuje task B (layout `tree`)

> PRD pro jednu Claude Code session (5 commitů). Design: issue #169
> (D1–D18). Navazuje task B — generický layout `tree` ve
> vieweru s detailem v draweru (D8–D12), který Místa převede na strom.

## Kontext

Aplikace potřebuje obecný číselník míst s hierarchií (areál → budova →
podlaží → místnost → místo) jako základ budoucí správy budovy a pro další
moduly (umístění majetku, zakázky, měřidla). Starý Shipard měl plochou
tabulku s pevným výčtem typů a slabým pravidlem nadřazenosti — viz #169.

Tento task založí model a správu v Nastavení. Prohlížeč zůstane v běžném
list layoutu s cestou ve druhém řádku; strom a drawer přinese task B.

## Cíl

1. Modul `base.places` v `install.base`: typy míst (cfgItem), druhy míst
   (číselník se seedem), místa s hierarchií, pořadím a adresou.
2. Validace: úrovně oběma směry, cyklus, kód mezi sourozenci, koš a archiv
   s podřízenými, zámek typu druhu, adresa podle typu.
3. Formuláře, lookup s cestou a filtry, viewery s detailem podle kontraktu,
   nová sekce **Místa** v Nastavení.
4. `docs/places.md`, nápověda, uzavření otázky v `docs/assets.md` §8.

## Před implementací přečti

- issue #169 (všechna rozhodnutí; `gh issue view 169 --repo shipard/shipard --json number,title,body,comments`)
- `docs/modules.md`, `docs/table-definitions.md` (nové tabulky; `tableId`
  z `shpd-server next-table-id`, `docs/cli.md`)
- `docs/document-system.md` (hooky, `ValidationResult`, `FIELD_FORM`)
- `docs/edit-forms.md` kap. 22 (lookup; **dynamický filtr lookupu přes
  `recalculate()`** — vzor `DocsHeadsForm`), kap. 11 a §4.5–4.6 (texty
  formuláře z katalogu `*.formTexts`)
- `docs/app-settings.md` (`settingsItems`, sekce Nastavení),
  `modules/install/base/config/settingsSections.jsonc`
- `docs/frontend.md` → Registrace vieweru, Formát detail panelu;
  `docs/design-system.md` §12 (detail, Přehled neopakuje hlavičku #140 D26)
- `docs/help-authoring.md` před psaním do `help/`
- vzory v `modules/economy/workOrders/src/`: `WorkOrderTypes` (jediné
  čtení cfgItem typů), `KindDocument` + `KindsForm` (zámek typu po
  potvrzení), `WorkOrderDocument::validateParent()` / `chainContains()`
  (cyklus, `MAX_PARENT_DEPTH`), `WorkOrderTreeService`, `WorkOrdersLookup`
  (filtry lookupu), `KindsViewer`
- seed: `modules/core/units/src/UnitsProvisioner.php` a jeho volání
  v `src/Command/DataSource/DsUpgradeCommand.php`
- adresa: `modules/base/persons/src/Values/PersonValues.php`,
  `modules/base/persons/src/AddressDocument.php`,
  `modules/base/persons/tables/base_persons_addresses.jsonc`
- přílohy záznamu a detail: vzor `modules/economy/assets/` (formulář
  karty, `AssetsViewer`)

## Scope

**Uvnitř:** vše v Cíli.

**Mimo (#169 D16):** layout `tree`, drawer, hledání s předky a akce
Přidat podřízené (task B); drag&drop; plochý seznam jako druhý layout;
výměnný formát, datové sady a `exchangeKey` (D6); import ze starého
Shipardu; vazby z jiných modulů (umístění majetku apod.); rozšíření
správy budovy (plocha, kapacita). Do `docs/roadmap.md` se nezapisuje (D17).

## Rozhodnutí k designu (potvrzená)

Úplné znění v #169. Pro implementaci podstatné:

- ✓ **D1** modul `base.places` v `install.base`, tabulky
  `base_places_places` a `base_places_kinds`, sekce Nastavení **Místa**.
- ✓ **D2** typ (cfgItem `base.places.types`) × druh (číselník, nese typ
  a ikonu); místo má druh povinně.
- ✓ **D3** nadřazené místo má nižší úroveň, přeskakování povoleno, typ
  Ostatní bez pravidla, cyklus vždy.
- ✓ **D4** pole místa (níže v Datovém modelu); kód jedinečný mezi
  sourozenci; řazení pořadí → kód → název.
- ✓ **D5 / D13** adresa vlastními poli jen u typů s `hasAddress`,
  normalizace a kontroly přes `PersonValues`.
- ✓ **D6** cesta se počítá při čtení, nic denormalizovaného; žádný
  `exchangeKey`.
- ✓ **D7** do koše ani archivu s živými podřízenými.
- ✓ **D14** seed jednoho druhu na typ; ikona druhu volitelná z pevné
  sady, bez ní ikona typu.
- ✓ **D15** typ druhu po prvním potvrzení jen ke čtení; změna druhu místa
  jen když platí D3 vůči nadřazenému i přímým podřízeným.
- ✓ **D18** druh, který používá místo mimo koš, nejde do koše; do
  archivu jde (místa si druh ponechají, lookup ho nově nenabízí).

## Datový model

### Typy míst — cfgItem `base.places.types`

`modules/base/places/config/types.jsonc`, záznam per typ:
`{id, name, name:cs, name:en, level, icon, hasAddress, order}`.

| `id` | cs / en | `level` | `icon` | `hasAddress` |
|---|---|---|---|---|
| `site` | Areál / Site | 10 | `place-site` | ano |
| `building` | Budova / Building | 20 | `place-building` | ano |
| `section` | Část budovy / Building section | 30 | `place-section` | ne |
| `floor` | Podlaží / Floor | 40 | `place-floor` | ne |
| `room` | Místnost / Room | 50 | `place-room` | ne |
| `outdoor` | Venkovní plocha / Outdoor area | 50 | `place-outdoor` | ano |
| `spot` | Místo / Spot | 60 | `place-spot` | ne |
| `other` | Ostatní / Other | `null` | `place-other` | ne |

Třída `PlaceTypes` je **jediné čtení** cfgItemu (vzor `WorkOrderTypes`):
`label()`, `level()`, `icon()`, `hasAddress()`, `isUnknown()`,
`canBeParent(string $parentType, string $childType): bool` — `other` na
kterékoli straně = `true`, jinak `level(parent) < level(child)`. Nikde
v kódu `=== 'room'` apod.

### Ikony míst — cfgItem `base.places.icons`

Pevná sada klíčů ikon pro druh (D14): sedm ikon typů z tabulky výše
a k nim `place-office`, `place-meeting`, `place-storage`,
`place-kitchen`, `place-tech`, `place-parking`, `place-workplace`.
Každý klíč má položku v `frontend/src/icons.js` (`iconMap`, ikona podle
významu z Font Awesome — vyber sám, ať jsou odlišitelné). Ikona sekce
Nastavení = `place-building`.

### `base_places_kinds` — druhy míst

`displayPattern: "{name}"`, stavy `core.system.docStatesArchive`.

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int AI PK | |
| `name` | varchar(100), not null | |
| `type` | enumString(16), cfgItem `base.places.types`, not null | po prvním potvrzení jen ke čtení (D15) |
| `icon` | enumString(32), cfgItem `base.places.icons`, null | null = ikona typu |
| `system_code` | varchar(16), null, unikátní index | jen seed (= id typu), ve formuláři není |
| `docState`, `docStateMain` | | |

### `base_places_places` — místa

`displayPattern: "{name}"`, stavy `core.system.docStatesArchive`, přílohy
jako karta majetku. **Bez `exchangeKey`** (D6).

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int AI PK | |
| `name` | varchar(120), not null | |
| `short_name` | varchar(40), null | do cesty a stromu |
| `code` | varchar(20), null | jedinečný mezi sourozenci — **ne** unikátní index v DB |
| `kind` | int, not null, odkaz `base_places_kinds` | index |
| `parent` | int, null, odkaz `base_places_places` | index; null = nejvyšší úroveň |
| `order_pos` | int, not null, výchozí 0 | pořadí mezi sourozenci |
| `note` | text, null | |
| skupina `address`: `street`, `house_number`, `orientation_number`, `city`, `zip`, `country`, `latitude`, `longitude` | typy a délky **přesně** jako v `base_persons_addresses` | jen typy s `hasAddress` (D13) |
| `docState`, `docStateMain` | | |

## Chování

### `PlaceTree` (čtení stromu)

Služba nad všemi místy mimo koš (id, parent, name, short_name, code,
order_pos, docState, kind, typ druhu) načtenými **jedním dotazem**,
memoizovaná v instanci; DB v protected metodách (přepsatelné v testech).
Metody: `ancestors(id)`, `pathLabels(id)` (předci shora, `short_name ??
name`), `pathText(id)` (spojené ` › `), `children(id)` (seřazené pořadí →
kód → název), `subtreeIds(id)`, `typeOf(id)`. Strop průchodu
`MAX_DEPTH = 100` jako pojistka proti poškozeným datům. Používá ji
viewer, detail, lookup a formulář; dokument si smí číst přímo.

### `PlaceKindDocument`

- název povinný; typ povinný a známý; ikona prázdná nebo z cfgItemu ikon
- zámek typu po prvním potvrzení (D15) — logika i kód chyby `typeLocked`
  jako `KindDocument` zakázek
- **D18:** přechod do Smazáno (90), když druh používá místo mimo koš →
  chyba `_form`, kód `in_use`

### `PlaceDocument`

`validate()`:

- název povinný; druh povinný, existuje a není v koši
- nadřazené místo: existuje, není v archivu ani v koši; není samo sebou
  ani v podstromu záznamu → `cycle` (vzor `validateParent()` /
  `chainContains()`)
- **úrovně oběma směry (D3, D15):** `canBeParent(typ nadřazeného, vlastní
  typ)` a pro každé přímé podřízené mimo koš `canBeParent(vlastní typ,
  typ podřízeného)`; chyba na `kind` (podřízená) nebo `parent`
  (nadřazené), kód `level`. Text říká, který typ pod který nejde
- kód: mezi sourozenci (stejný `parent`, včetně nejvyšší úrovně
  s `parent` NULL) mimo koš nesmí být stejný kód; porovnání bez ohledu na
  velikost písmen (collation sloupce stačí); kód `code_duplicate`
- **D7:** přechod do Archivu (70), když existuje podřízené místo ve stavu
  mimo 70 / 90; přechod do koše (90), když existuje podřízené místo mimo
  koš → chyba `_form`, kód `has_children` („Místo má podřízená místa —
  nejdřív je přesuňte nebo vyřaďte.“)
- adresa (D13): `PersonValues::checkAddress()` se zemí zdroje dat jako
  fallbackem; nález je **chyba** (místa jsou vlastní údaje, jako adresa
  vlastní firmy — `PersonDocument::report(..., true)`)

`beforeSave()`:

- typ druhu bez `hasAddress` → všechny sloupce skupiny `address` na NULL
  (i z payloadu; vzor nulování skupiny sloupců u opravného dokladu)
- jinak `PersonValues::normalizeAddress($data, uložená země, země zdroje
  dat)`
- pořadí: nový záznam bez `order_pos`, nebo změna `parent` bez změny
  `order_pos` → max pořadí sourozenců mimo koš + 1

### Formulář místa (`PlacesForm`, PHP `TableForm`)

Tab Základní: Název; Zkrácený název a Kód vedle sebe (nápověda u Kódu:
jedinečný v rámci nadřazeného místa); Druh (`select` živých druhů,
popisek `Název (Typ)`) a Pořadí; Nadřazené místo (lookup); Poznámka.
Sekce Adresa jen pro typ s `hasAddress` — přepočet při změně druhu
(`recalculate`). Přílohy jako formulář karty majetku.

Lookup Nadřazené místo dostává **dynamický filtr** z `recalculate()`:
`childType` = typ zvoleného druhu, `exclude` = id záznamu (u nového nic).

Nový záznam s předvyplněným `parent` (a případně `kind`) musí fungovat
— task B ho otevírá přes `open_form` s `preset`. Pořadí se dopočítá
v dokumentu (viz výše), formulář ho smí ukázat jako nápovědu.

Texty prvků (nápovědy) z katalogu `base.places.formTexts` přes
`TableForm::formText()`; názvy tabů a sekcí zatím česky (#154).

Formulář druhu (`PlaceKindsForm`): Název, Typ (jen ke čtení po prvním
potvrzení, vzor `KindsForm`), Ikona (select s volbou „Podle typu“ = null).

### Lookup míst (`PlacesLookup`)

Registrace v `lookups`. Hledá v `name`, `short_name`, `code` přes
`SearchCondition`; stavy Koncept, V pořádku, V opravě (10, 40, 80).
`primary` = název, `secondary` = cesta · druh (prázdná cesta = jen druh).
Řazení podle cesty a názvu. Filtry (`getAllowedFilterKeys`):

- `childType` — jen místa, jejichž typ smí být nadřazený danému typu
  (`PlaceTypes::canBeParent`)
- `exclude` — vynechá místo a celý jeho podstrom (`PlaceTree::subtreeIds`)

`resolve()` vrací týž tvar (i pro místo, které filtrem neprojde).

### Viewery a detail

**`PlacesViewer`** (list layout, dočasně do tasku B): t1 název, i1 kód,
t2 cesta · druh + `rowStateTag()`; ikona řádku podle druhu (ikona druhu,
jinak typu); hledání v názvu, zkráceném názvu a kódu; řazení podle názvu.

**Detail** (builder, `renderDetail()` je `final`):

- `detailHeader()`: titulek název, podtitul `cesta · druh` (u místa bez
  nadřazeného jen druh), ikona podle druhu
- Přehled: vlastnosti Kód, Zkrácený název (jen když se liší od názvu),
  Pořadí; skupina Adresa (jen typ s `hasAddress` a vyplněná adresa;
  formát jako u adres osob — najdi existující formátovač, nepiš vlastní);
  Poznámka; **Podřízená místa (N)** — tabulka název / kód / druh, řádek
  s akcí `open_detail` na tentýž viewer (vzor obsahu `table` s odkazy
  u opravného dokladu); přílohy na konci od builderu
- Přehled neopakuje hlavičku (#140 D26) — druh ani cesta ve vlastnostech
  nejsou

**`PlaceKindsViewer`**: t1 název, t2 typ + `rowStateTag()`, ikona druhu.

Texty viewerů z katalogu `base.places.viewerLabels` (`labelText()`),
popisky sloupců přes `columnLabel()` — žádné literály s diakritikou
(`ViewerSourceTextsTest`).

### Nastavení a seed

- `settingsSections.jsonc`: sekce `places` „Místa“ / „Places“, ikona
  `place-building`, `order` 16 (mezi Majetek a Sklady)
- `settingsItems`: viewer míst `order` 10, viewer druhů `order` 20
- `PlaceKindsProvisioner` (vzor `UnitsProvisioner`, volání vedle něj
  v `DsUpgradeCommand` se stejným zacházením s `skipProvisioning`): pro
  každý typ z cfgItemu druh se `system_code` = id typu, název v jazyce
  zdroje dat (`getDefaultLanguage()`), stav V pořádku; existuje-li řádek
  se stejným `system_code` v jakémkoli stavu, nic nedělá

## Kroky a commity

1. **`feat(places): modul míst — typy, druhy a tabulky`** — `module.jsonc`
   (`base.places`, závislosti `core.system`, `core.attachments`,
   `base.persons`, `world.validations`; přidat do `install.base`),
   cfgItemy typů a ikon, `PlaceTypes`, obě tabulky, ikony v `icons.js`,
   `PlaceKindsProvisioner` + registrace. Testy: `PlaceTypesTest`
   (matice `canBeParent` včetně `other` a stejné úrovně
   `room`/`outdoor`), `PlaceKindsProvisionerTest` (idempotence, druh
   v archivu i koši se znovu nezaloží).
2. **`feat(places): dokumenty a strom míst`** — `PlaceTree`,
   `PlaceKindDocument`, `PlaceDocument`, registrace `documentClasses`.
   Testy viz níže.
3. **`feat(places): formuláře a lookup míst`** — `PlacesForm`,
   `PlaceKindsForm`, `PlacesLookup`, katalog `formTexts`.
4. **`feat(places): viewery, detail a sekce Místa v Nastavení`** —
   oba viewery, detail, `viewerLabels`, `settingsItems` a sekce.
5. **`docs(places): dokumentace, nápověda, stav tasku`** — `docs/places.md`
   (model, typy a úrovně, pravidla D3/D7/D13/D15/D18, `PlaceTree`,
   filtry lookupu, jak modul přidá typ, co je mimo rozsah), řádek v tabulce
   dokumentů v `CLAUDE.md`, `docs/assets.md` §8 — otázku míst nahradit
   odkazem na `docs/places.md`; nápověda `help/mista/` (Místa a druhy míst:
   jak založit strom, úrovně, přesun polem Nadřazené místo; ověř popisky
   ve zdroji), `help/co-dnes-nejde.md` (místa nejdou přetahovat myší,
   jiné části aplikace je zatím nepoužívají), `python3
   scripts/help-index.py`; `**Stav:**` tohoto tasku + `python3
   scripts/tasks-index.py`, zaškrtnout task A v #169 (popis issue, ne
   komentář — dělá člověk nebo chat).

## Testy

- `PlaceDocumentTest`: povinná pole; cyklus (sám sobě, potomek jako
  rodič); úrovně — místnost pod podlažím ano, pod místností ne, místnost
  rovnou pod budovou ano, změna druhu budovy na místnost s podlažím pod
  sebou ne, `other` kdekoli; kód — duplicita mezi sourozenci chyba, stejný
  kód v jiné budově i na nejvyšší úrovni u jiného rodiče ano, záznam v koši
  se nepočítá, velikost písmen; D7 — archiv s aktivním podřízeným ne,
  s archivovaným ano, koš s archivovaným podřízeným ne; adresa u podlaží
  se vymaže i z payloadu, u budovy se normalizuje (PSČ podle země zdroje
  dat); pořadí — nový záznam na konec, změna rodiče na konec nových
  sourozenců, ručně změněné pořadí se nepřepíše
- `PlaceKindDocumentTest`: zámek typu po potvrzení; ikona mimo sadu;
  D18 koš s použitým druhem ne, archiv ano
- `PlaceTreeTest`: cesta ze zkrácených názvů, podstrom, pořadí dětí,
  pojistka hloubky
- `PlacesLookupTest`: filtr `childType` (pro `room` nabízí areály,
  budovy, části budov, podlaží a Ostatní, ne místnosti, venkovní plochy
  ani místa; venkovní plochu nabízí až pro `spot`), `exclude` vynechá
  podstrom
- kontraktní testy projdou beze změny výjimek: `DetailContractTest`,
  `ViewerDetailContractTest`, `ViewerSourceTextsTest`,
  `FormTextsCatalogTest`, `ExchangeKeyDefinitionsTest`
- PHPUnit cíleně (`--filter`), pak celá sada; `npm run build`,
  `check-css-tokens.py`, `check-css-units.py`

## Ověření

Na vývojovém zdroji dat s třídou `free` (`ds-upgrade`): v Nastavení →
Místa je osm seedových druhů (jeden na typ); založit strom
Areál › Budova A › 1. NP › Kancelář 101, přízemní Budova B s místnostmi
rovnou pod budovou a Parkoviště se stáními; zkusit místnost pod
místnost (chyba), budovu do koše s podlažím (chyba), kód 101 v obou
budovách (projde), adresu u budovy (pole vidět) a u podlaží (pole
schovaná); lookup Nadřazené místo u úpravy podlaží nenabízí jeho
místnosti ani jiná podlaží.

## Hotovo když

- [ ] modul v `install.base`, `ds-upgrade` vytvoří tabulky a seed druhů
- [ ] validace D3, D7, D13, D15, D18 a kódu mezi sourozenci s testy
- [ ] formulář s adresou podle typu a lookupem s filtry z `recalculate()`
- [ ] viewery a detail podle kontraktu, sekce Místa v Nastavení
- [ ] `docs/places.md`, `CLAUDE.md`, `docs/assets.md` §8, nápověda
- [ ] celá sada PHPUnit, build a kontroly CSS projdou
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **Kód není unikátní index.** Místnost 101 je v každé budově; unikátnost
  je jen mezi sourozenci a hlídá ji dokument. NULL rodič = nejvyšší úroveň
  je taky jedna skupina sourozenců.
- **Úrovně oběma směry.** Kontrola jen nadřazeného místa pustí změnu
  druhu budovy na místnost, pod kterou zůstanou podlaží (D15).
- **`other` vypíná pravidlo úrovní, ne kontrolu cyklu.** S Ostatními jde
  cyklus postavit — `cycle` se hlídá vždy.
- **Cesta se neukládá** (D6) — žádný sloupec `path`, `level` ani
  `full_name`. Všechno počítá `PlaceTree`.
- **Žádný `exchangeKey`** (D6, mimo rozsah) — jinak spadne
  `ExchangeKeyDefinitionsTest` a otevře se téma výměnného formátu.
- **Země pro PSČ** bere fallback ze zdroje dat (`DataSourceConfig::getCountry()`),
  ne z osoby — místo osobu nemá.
- **Filtr lookupu musí být dynamický** (druh se mění ve formuláři) —
  statický `filter` v definici nestačí; vzor `DocsHeadsForm::recalculate()`.
- **Typ čte jen `PlaceTypes`** — žádné porovnání s řetězcem typu mimo něj.
- **Seed nepřepisuje** existující druhy ani nevrací archivované —
  uživatel si je mohl přejmenovat nebo vyřadit.
