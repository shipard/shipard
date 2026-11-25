# Shipard — Místa (`base.places`)

Obecný číselník míst s hierarchií (areál → budova → podlaží → místnost →
místo). Základ budoucí správy budovy a společný číselník pro další moduly
(umístění majetku, zakázky, měřidla). Design: issue #169 (D1–D18).

Hotové jsou oba tasky: **A** (`tasks/places-model.md`) — model, validace,
formuláře, lookup, viewery a sekce **Místa** v Nastavení — a **B**
(`tasks/viewer-tree-layout.md`, D8–D12, D20–D25) — prohlížeč míst jako
strom s detailem v draweru a akcí Přidat podřízené. Strom sám je obecný
layout vieweru, popsaný v [`viewer-tree.md`](viewer-tree.md); tady je jen
to, co k němu dodávají Místa.

## 1. Model

Modul `modules/base/places/`, součást `install.base`. Dvě tabulky:

| Tabulka | `tableId` | Co nese |
|---|---|---|
| `base_places_kinds` | 466 | **druh místa** — uživatelský číselník (Kancelář, Sklad…): název, typ, volitelná ikona, `system_code` jen u seedu |
| `base_places_places` | 467 | **místo** — název, zkrácený název, kód, druh, nadřazené místo, pořadí, poznámka, adresa |

Obě mají stavy `core.system.docStatesArchive` a `stateTransitionsRunDocumentHooks`
— přechod stavu z formuláře tak jde přes dokument a kontroly koše a archivu
(§ 3) se spustí. Bez příznaku by šel přímým UPDATE mimo ně.

**Typ × druh** (D2, vzor druhů zakázek). **Typ** je systémový a řídí
chování; **druh** je uživatelský a typ nese. Místo má druh povinně a typ
z něj dědí — vlastní sloupec typu místo nemá. Pobočka není typ, ale druh.

Co se záměrně **neukládá** (D6): cesta, úroveň ani plný název — počítá je
`PlaceTree` při čtení. Kód nemá unikátní index (je jedinečný jen mezi
sourozenci) a tabulky nemají `exchangeKey` — přirozený klíč přijde
s prvním konzumentem.

## 2. Typy a úrovně

cfgItem `base.places.types` (`config/types.jsonc`), plochá mapa id → definice:

| `id` | Název | `level` | `hasAddress` |
|---|---|---|---|
| `site` | Areál | 10 | ano |
| `building` | Budova | 20 | ano |
| `section` | Část budovy | 30 | ne |
| `floor` | Podlaží | 40 | ne |
| `room` | Místnost | 50 | ne |
| `outdoor` | Venkovní plocha | 50 | ano |
| `spot` | Místo | 60 | ne |
| `other` | Ostatní | `null` | ne |

Každý typ nese i `icon` (klíč `place-*` z `frontend/src/icons.js`),
`order` (pořadí v nabídce) a `defaultChildType` — typ, který se navrhne
pro první podřízené místo (§ 6, D23): areál → budova, budova a část budovy
→ podlaží, podlaží → místnost, místnost a venkovní plocha → místo; Místo
a Ostatní žádný.

**Jediné čtení cfgItemu je `PlaceTypes`** — `label()`, `level()`, `icon()`,
`hasAddress()`, `isUnknown()`, `options()`, `canBeParent()`,
`canHaveChildren()`, `defaultChildType()`. Nikde v kódu není `=== 'room'`;
nový typ tak nevyžaduje úpravu PHP.

`canHaveChildren($type)` říká, jestli pod místo typu podle úrovní něco
smí: ne, když žádný typ nemá úroveň vyšší (dnes jen Místo). Typ bez
úrovně nadřazený být smí.

`PlaceTypes::canBeParent($parentType, $childType)` je pravidlo D3:
nadřazené místo musí mít **nižší** úroveň. Úrovně jde přeskočit (přízemní
budova má místnosti rovnou pod sebou) a strom nemusí začínat areálem. Typ
bez úrovně (`other`) pravidlo vypíná na kterékoli straně. Stejná úroveň
nestačí — místnost nejde pod venkovní plochu.

**Jak modul přidá typ.** cfgItemy se mezi moduly neslučují: cfgItem se
stejným id z pozdějšího modulu ten dřívější **přepíše celý**
(`docs/modules.md` → `sendPurposes`). Modul, který chce typ přidat, dnes
musí `base.places.types` deklarovat znovu se všemi typy. Vlastní klíč
v `module.jsonc`, který by `ConfigCompiler` skládal (vzor `sendPurposes`),
vznikne s prvním modulem, který typ opravdu přidá. Sloupce a taby detailu
přidává modul běžně přes extensions.

Ikony druhů: cfgItem `base.places.icons` — osm ikon typů a k nim
`place-office`, `place-meeting`, `place-storage`, `place-kitchen`,
`place-tech`, `place-parking`, `place-workplace`. Druh bez ikony bere
ikonu typu (D14).

## 3. Pravidla (`PlaceDocument`, `PlaceKindDocument`)

Místo — `PlaceDocument::validate()`:

| Pravidlo | Chyba |
|---|---|
| název a druh povinné; druh existuje a není v koši | `name` / `kind`: `required`, `not_found`, `invalid_state` |
| nadřazené místo existuje a je v Konceptu, V pořádku nebo V opravě | `parent`: `not_found`, `invalid_state` |
| **cyklus** — místo není nadřazené samo sobě ani pod svým potomkem | `parent`: `cycle` |
| **úroveň vůči nadřazenému** (D3) | `parent`: `level` |
| **úroveň vůči přímým podřízeným** mimo koš (D15) | `kind`: `level` |
| **kód** jedinečný mezi sourozenci mimo koš, bez ohledu na velikost písmen (D4) | `code`: `code_duplicate` |
| **koš** s podřízeným mimo koš, **archiv** s podřízeným mimo archiv a koš (D7) | `_form`: `has_children` |
| adresa: nález `PersonValues::checkAddress()` je chyba (D13) | `zip`: `zip_invalid` |
| GPS: vyplněná souřadnice je číslo, šířka ±90, délka ±180 (desetinná čárka = tečka) | `latitude` / `longitude`: `invalid_value` |

Úrovně se hlídají **oběma směry**: kontrola jen nadřazeného místa by
pustila změnu druhu budovy na místnost, pod kterou zůstanou podlaží.
Cyklus se hlídá vždy — typ Ostatní vypíná úrovně, ne cyklus. Sourozenci
jsou místa se stejným nadřazeným místem; nejvyšší úroveň (`parent` NULL)
je také jedna skupina.

`beforeSave()`:

- **adresa podle typu** — typ bez `hasAddress` má sloupce
  `PlaceDocument::ADDRESS_COLUMNS` vždy NULL, i když přišly v payloadu.
  Jinak `PersonValues::normalizeAddress()`; země pro pravidla PSČ je země
  zdroje dat (`DataSourceConfig::getCountry()`) — místo osobu nemá.
  Prázdná GPS souřadnice (formulář posílá `''`) se ukládá jako NULL —
  `numeric` sloupec prázdný řetězec odmítne; čárka se mění na tečku
- **pořadí** — nové místo bez pořadí a místo přesunuté pod jiné nadřazené
  jdou na konec sourozenců. Ručně změněné pořadí se nepřepisuje. Formulář
  posílá u nového záznamu výchozí hodnotu sloupce 0; ta se bere jako
  nezadaná

Druh — `PlaceKindDocument`: název a známý typ povinné, ikona prázdná nebo
ze sady. **Typ je po prvním potvrzení jen ke čtení** (D15, `typeLocked`) —
„potvrzený“ znamená, že uložený řádek už není v Konceptu. Nad rámec D15
je stejně zamčený **typ druhu v Konceptu, který už používá místo mimo
koš**: koncept druhu se místům nabízí a změna typu by jim změnila úroveň
bez kontroly stromu. **Druh, který
používá místo mimo koš, nejde do koše** (D18, `_form` / `in_use`); do
archivu jít smí — místa si druh ponechají, formulář místa ho už nenabízí.

Změna druhu místa je povolená, když po ní platí úrovně oběma směry.

## 4. `PlaceTree`

Čtení stromu nad všemi místy mimo koš, načtenými **jedním dotazem**
a zapamatovanými v instanci:

| Metoda | Vrací |
|---|---|
| `node(id)` | uzel `{id, parent, name, short_name, code, order_pos, docState, kind, kind_name, kind_type, kind_icon}` |
| `ancestors(id)` | předky shora, bez místa samotného |
| `pathLabels(id)` / `pathText(id)` | popisky předků (`short_name ?? name`) / spojené ` › ` |
| `children(id)` | přímá podřízená v pořadí stromu: pořadí → kód → název; `null` = nejvyšší úroveň |
| `compareNodes(a, b)` | statické porovnání sourozenců (pořadí → kód → název → id) — jediné místo pravidla, řadí jím `children()` i strom ve vieweru |
| `subtreeIds(id)` | místo a všechny potomky |
| `typeOf(id)` | typ místa |

Řazení je české a čísla porovnává podle hodnoty (`PlaceTree::compareText()`,
kód „9“ před „10“). Průchody mají strop `MAX_DEPTH = 100` a pamatují si
navštívené uzly — cyklus v poškozených datech vrátí zkrácený výsledek.
Místo, jehož nadřazené ve stromu není (je v koši), se čte jako místo
nejvyšší úrovně.

Používá ho viewer, detail, lookup a formulář. Dokument si při validaci čte
přímo (běží v ukládací transakci a potřebuje jen pár řádků).

## 5. Formuláře a lookup

**`PlacesForm`** — sekce **Adresa** je vidět jen u druhu s typem
`hasAddress`; při změně druhu (`triggers: 'reload'`) se formulář postaví
znovu. Lookup **Nadřazené místo** dostává filtr spočítaný z dat:

| Filtr | Význam |
|---|---|
| `childType` | typ zvoleného druhu — lookup nabídne jen místa, pod která typ smí (`PlaceTypes::canBeParent()`) |
| `exclude` | id záznamu — lookup vynechá místo a celý jeho podstrom |

Nový záznam s předvyplněným `parent` a `kind` (akce Přidat podřízené ho
otevírá přes `open_form` s `preset`, § 6) nepotřebuje další kód.

**`PlacesLookup`** hledá v názvu, zkráceném názvu a kódu a nabízí místa
v Konceptu, V pořádku a V opravě. `primary` = název, `secondary` = cesta
· druh; řadí podle cesty a názvu. Hledání dělá SQL, filtry a řazení PHP
nad `PlaceTree` — typ i podstrom jsou vlastnosti stromu. `resolve()`
filtry nezná: vybrané místo se zobrazí, i když jím neprojde.

**`PlaceKindsForm`** — typ je jen ke čtení, jakmile uložený druh opustí
Koncept, a u konceptu, který už používá místo; ikona má prázdnou volbu
**Podle typu**.

Nápovědy jdou z katalogu `base.places.formTexts`. Nápovědy polí ve skupině
`inline` formulář nekreslí, proto jsou u Kódu a Pořadí jako blok `hint`
pod řádkem.

## 6. Viewery a Nastavení

Sekce **Místa** v Nastavení (`settingsSections.jsonc`, `order` 16, mezi
Majetkem a Sklady) s viewery `base.places.places` a `base.places.kinds`.
Do hlavní navigace nepatří.

**`PlacesViewer`** má dva layouty; výchozí je **strom**, seznam zůstává
přes přepínač (D24 — hodí se pro „všechny kanceláře“ napříč budovami).

*Strom* ([`viewer-tree.md`](viewer-tree.md)): sloupce Název (se štítkem
stavu), Kód a Druh, ikona podle druhu. `selectRows()`, `selectTreeRows()`
i `selectTreeRowsByIds()` sdílejí jeden SELECT (`baseSelect()`) a filtr
(`filterWhere()` — stavy, hledání v názvu, zkráceném názvu a kódu);
sourozence řadí `PlaceTree::compareNodes()`, takže strom a tabulka
Podřízená místa v detailu mají vždy stejné pořadí.

*Seznam*: název, kód, ve druhém řádku cesta · druh, ikona podle druhu.

*Detail*: titulek název, podtitul cesta · druh. Přehled hlavičku neopakuje
— nese kód, zkrácený název (jen když se liší od názvu), pořadí, adresu
(jen typ s adresou; tvar dává sdílená `Base\Persons\Values\AddressFormat`),
poznámku a tabulku **Podřízená místa (N)** v pořadí stromu. Klik na řádek
podřízené místo vybere ve stromu a přepne na něj drawer (akce
`select_row`, #169 D26); když mezi načtenými řádky není (archivované
místo v záložce Aktivní), otevře se read-only náhled jako `open_detail`.

**Přidat podřízené** (D10, D23) — jedna akce `addChild` (`open_form`
s `preset`) na řádku stromu („+“) i v detailu, skládá ji
`PlacesViewer::addChildAction()`. Přidat v liště zakládá místo nejvyšší
úrovně. Akce chybí:

- u typu, pod který podle úrovní nic nesmí (`PlaceTypes::canHaveChildren()`
  — typ Místo)
- u místa mimo živý stav (archiv, koš) — nadřazené místo musí být
  v Konceptu, V pořádku nebo V opravě (§ 3)

`childPreset()` předvyplní nadřazené místo a navrhne druh:

1. druh **posledního podřízeného místa** mimo koš (poslední podle pořadí
   stromu) — další kancelář na patře bude nejspíš zase kancelář. Jen když
   je ten druh živý; vyřazený druh by formulář ukázal jako neplatný
2. jinak **výchozí podřízený typ** (`defaultChildType`) a z něj seedový
   druh (`system_code` = typ)
3. bez seedového druhu první živý druh toho typu podle názvu
4. bez vhodného druhu se předvyplní jen nadřazené místo

Živé druhy se čtou jedním dotazem na požadavek, strom jedním dotazem
z `PlaceTree` — návrh se nepočítá dotazem na řádek.

**Seed** (`PlaceKindsProvisioner`, volá `ds-upgrade` vedle jednotek): jeden
druh na typ, `system_code` = id typu, název v jazyce zdroje dat
(`getDefaultLanguage()`), stav V pořádku. Existující řádek se stejným
`system_code` v jakémkoli stavu se nepřepisuje ani nevrací z archivu.
Seed běží **bezpodmínečně, i pod `skipProvisioning`** (#169 D19): místa se
ze starého Shipardu nepřevádějí, takže druhy nejsou migrovaná data, a bez
druhu by na migrovaném zdroji dat nešlo založit žádné místo.

## 7. Mimo rozsah

Přetahování myší (změna nadřazeného místa a pořadí, D12 — druhá fáze);
výměnný formát, datové sady a `exchangeKey`; import ze starého Shipardu; vazby z jiných modulů
(umístění majetku); plocha, kapacita a další údaje správy budovy.
