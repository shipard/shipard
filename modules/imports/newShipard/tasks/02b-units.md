# Task: Import jednotek (Fáze 02b)

## Kontext

Fáze 02b doplňuje subkomand `units` — import měrných jednotek ze starého
Shipardu (`e10_witems_units`) do nového (`core_units`).

**Proč samostatně, mimo původní Fázi 02:** Při importu na DS se
`skipProvisioning = true` (zdrojová data importujeme, nechceme aby je
provisioning duplikoval). `skipProvisioning` ale vypíná **celý**
provisioning včetně `UnitsProvisioner` — takže `core_units` zůstane
prázdná. Items (Fáze 04) i docs (Fáze 05) mapují `unit` přes
`UnitResolver`, který v prázdné tabulce nic nenajde → import selže.

**Řešení (rozhodnuto):** importovat jednotky ze starého Shipardu. Důvody
proti pouhé výjimce v `skipProvisioning`:
- Zdrojová data obsahují i **uživatelské jednotky** (`bal`, `sada`,
  `kart`, …), které žádný seed nepokryje.
- Jeden mechanismus (import) místo dvou (seed + import).

**Cíl Fáze 02b:** Po dokončení musí jít:

```bash
shpd-app cli-action --action=imports.newShipard/import units
shpd-app cli-action --action=imports.newShipard/import units --dry-run -v
```

a `all-codebooks` musí zařadit `units` **před** ostatní (items/docs na ně
navazují, ale samotné codebooks na units nezávisí — viz pořadí níže).

## Jak funguje UnitResolver (klíč k návrhu)

`UnitResolver` (v novém Shipardu) mapuje `unit` string z items/docs na
`core_units.id` takto:

1. Normalizuje vstup (lowercase, trim).
2. `ALIASES` (`ks`→`pcs`, `hod`→`hr`, …) → hledá podle `system_code`.
3. Fallback: case-insensitive lookup podle `shortcut`.

**Důsledek pro import:** importovaná jednotka se najde, pokud má buď
správný `system_code` (přes alias / přímý probe), nebo matching `shortcut`
(fallback).

## Dva zdroje jednotek ve starém Shipardu (klíč k návrhu)

Hodnota `defaultUnit` (items) / `unit` (řádky docs) je **enumString klíč**
do merged configu `e10.witems.units` (`cfgValue=""` → ukládá se klíč). Items
i docs runnery posílají tenhle token **doslova** do UnitResolveru. Token má
dvě podoby podle zdroje jednotky:

| Zdroj | Token v items/docs | Kde žije |
|---|---|---|
| **Systémová** (`Kus`, `Normostrana`, …) | kód: `pcs`, `hr`, `stdpage`, `word`, `dgrcls` | jen config `e10/witems/config/e10.witems.units.json` — **NE v DB** |
| **Uživatelská** (`bal`, …) | `_<ndx>` (např. `_5`) | DB `e10_witems_units` (merge do configu pod `_<ndx>`, viz `TableUnits::saveConfig`) |

Import **obou** zdrojů je nutný: systémové jednotky nejsou v DB, takže by
chyběly úplně; uživatelské se referencují přes `_<ndx>`, ne přes shortcut.

## Strategie mapování (rozhodnuto: system_code = původní token)

Každá jednotka se importuje se **`system_code = původní klíč`** — kód pro
systémové, `_<ndx>` pro uživatelské. UnitResolver pak každý token z items/docs
trefí přímo přes **system_code probe** (systémové i uživatelské). Shody zkratek
(systémová `pcs`/shortcut `ks` vs. uživatelská `ks`) nevadí — resolvuje se přes
system_code, ne přes shortcut.

- `quantity` (NOT NULL): systémové podle kódu (`SYS_UNIT_QUANTITY`),
  uživatelské podle shortcutu (`SHORTCUT_QUANTITY`); neznámé → `"other"`.
- `system_code` je unikátní z principu (kódy nezačínají `_`, `_<ndx>` unikátní
  podle ndx) → **žádná kolizní logika** není potřeba.
- Systémová jednotka `none` (prázdná) a prázdné definice se neimportují —
  items/docs je mapují na NULL (viz `DocsRunner::unitOrNull`).

## Před implementací přečti

Z hotové infrastruktury (Fáze 01–02):

- **`modules/imports/newShipard/libs/BaseCodebookRunner.php`** — base class.
  `units` je generic-CRUD codebook, dědí stejný flow jako bank-accounts.
  Pozor: `processRow` doplňuje `docState` přes `mapDocState()` (starý
  1000/4000/8000/9000 → nový 10/40/80/70, fallback 40); `docStateMain`
  nevkládá (je `system: true`, server ho dopočítá z `docState`). Existující
  záznamy (hit v LocalIdMap) **přeskakuje** se statusem `already-imported` —
  NEpatchuje (docState=40 záznamy jsou readOnly).
- **`modules/imports/newShipard/libs/runners/ItemKindsRunner.php`** —
  nejbližší vzor (taky řeší `system_code` mapování + seedované záznamy).
- **`modules/imports/newShipard/libs/CrudClient.php`** — `create`/`patch`.
- **`modules/imports/newShipard/libs/LocalIdMap.php`** — přidat `ENTITY_UNIT`.

Z nového Shipardu (kompletně):

- **`nov_shipard:modules/core/units/tables/core_units.jsonc`** — cílová
  tabulka. Klíčové: `name` NOT NULL, `shortcut` NOT NULL, `system_code`
  nullable s UNIQUE indexem, `quantity` NOT NULL (enumString),
  `coefficient` nullable, `is_base` default 0.
- **`nov_shipard:modules/core/units/config/quantities.jsonc`** — hodnoty
  `quantity`: `weight`, `volume`, `length`, `area`, `time`, `energy`,
  `count`, `other`.
- **`nov_shipard:modules/core/units/unitsSeed.jsonc`** — zdroj mapovací
  konstanty (system_code + shortcut + quantity + coefficient pro 18 ISO
  jednotek).
- **`nov_shipard:modules/core/exchange/src/Resolve/UnitResolver.php`** —
  ALIASES tabulka (rozšiřuje mapování o varianty `h`→`hr`, `ltr`→`l`, …).

Ze starého Shipardu:

- **`modules/e10/witems/tables/units.json`** — zdroj uživatelských jednotek.
  Jen `shortcut` (varchar 15) + `fullName` (varchar 60) + docState. Žádná
  veličina ani system_code.
- **`modules/e10/witems/config/e10.witems.units.json`** — zdroj systémových
  jednotek (kód → `{text, shortcut}`). NEJSOU v DB. Čte se přes
  `__SHPD_MODULES_DIR__ . 'e10/witems/config/e10.witems.units.json'`.
- **`modules/e10/witems/tables/units.php`** (`TableUnits::saveConfig`) —
  ukazuje, že uživatelské jednotky se do merged configu mergují pod klíčem
  `_<ndx>` → proto je items/docs referencují tímto klíčem, ne shortcutem.

## Co implementovat

### 1. `LocalIdMap::ENTITY_UNIT` konstanta

```php
public const ENTITY_UNIT = 'unit';
```

### 2. `libs/runners/UnitsRunner.php`

Generic-CRUD runner nad `BaseCodebookRunner`. Klíčové prvky:

- **`sourceQuery()`** — DB uživatelské jednotky:
  `SELECT * FROM [e10_witems_units] WHERE [docState] != 9800 ORDER BY [ndx]`.
- **`fetchSourceRows()`** (override) — vezme DB řádky z parenta, každému
  doplní `_unitKey = '_'.ndx` a `_source = 'db'`, a **připojí systémové
  jednotky** z `systemUnitRows()`.
- **`systemUnitRows()`** — načte `e10.witems.units.json`, pro každou položku
  (kromě `none` / prázdných) vytvoří row-shape: `ndx` = `syntheticNdx(code)`
  (stabilní záporné — `-1 - crc32(code) % 2e9`, nekoliduje s kladnými DB
  ndx a je deterministické → idempotence), `shortcut`/`fullName` z JSON,
  `docState = 4000` (→ mapDocState → 40), `_unitKey = code`, `_source = 'sys'`.
- **`mapRow()`** — `system_code = _unitKey` (kód / `_<ndx>`, oříznuto na 25);
  `quantity` ze `SYS_UNIT_QUANTITY[code]` (sys) nebo
  `SHORTCUT_QUANTITY[lower(shortcut)]` (db), jinak `"other"`; `name`/`shortcut`
  NOT NULL s fallbackem (prázdný shortcut → z fullName / `_unitKey`);
  `coefficient = null`, `is_base = 0`.

Dvě konstantní mapy nahradily původní `ISO_UNIT_MAP` + kolizní logiku:
`SYS_UNIT_QUANTITY` (kód → veličina) a `SHORTCUT_QUANTITY` (shortcut → veličina).
**Žádný `usedSystemCodes`** — `system_code` je unikátní z principu.

**Poznámky:**

- `system_code` je vždy vyplněný a unikátní (kódy systémových jednotek
  nezačínají `_`, `_<ndx>` je unikátní podle ndx) → žádná kolize, žádný NULL.
- `coefficient` nevyplňujeme (NULL) — pro import 1:1 převody nepotřebujeme;
  případně se doplní ručně v UI. `is_base = 0` pro všechny (UnitResolver ho
  nepotřebuje).
- Systémové jednotky se čtou z **kódu**, ne z DB — proto se importují i na DS,
  kde žádné uživatelské jednotky nejsou.

**Idempotence:** druhý běh → LocalIdMap hit (reálné i syntetické ndx jsou
stabilní) → `processRow` vrátí `skipped / already-imported`, **žádný PATCH ani
POST** (base class nepatchuje). Výsledek: `created=0, skipped=N`. UNIQUE
konflikt na `system_code` při re-runu nenastane, protože se nic neposílá.

### 3. Zařazení do `AllCodebooksRunner`

V **`libs/runners/AllCodebooksRunner.php`** přidat `UnitsRunner` do
sekvence. Units nezávisí na žádném jiném codebooku, ale items/docs na ně
navazují — zařadit kamkoliv v rámci codebooks (doporučeně na konec, vedle
item-kinds, ať jsou "katalogové" číselníky pohromadě):

```php
$sequence = [
    VatRegistrationsRunner::class,
    FiscalYearsRunner::class,
    BankAccountsRunner::class,
    CostCentersRunner::class,
    WarehousesRunner::class,
    CashDesksRunner::class,
    NumberSeriesRunner::class,
    ItemKindsRunner::class,
    UnitsRunner::class,       // ← přidat
];
```

(Pořadí v rámci codebooks je volné — žádný cross-FK. Důležité je jen, že
`all-codebooks` proběhne před `items`/`docs`, což už platí.)

### 4. Dispatch v `ImportApp`

```php
case 'units':
    return (new runners\UnitsRunner($this->context()))->run();
```

A `printUsage()` rozšířit o `units`.

### 5. Update `README.md`

Tabulka subkomand: `units | ✅ Fáze 02b`.

## Hotovo když

1. **`LocalIdMap::ENTITY_UNIT`** konstanta existuje.
2. **`UnitsRunner`** importuje **oba zdroje** → `core_units`: uživatelské
   z `e10_witems_units` (system_code `_<ndx>`) + systémové z
   `e10.witems.units.json` (system_code = kód). Přes generic CRUD + LocalIdMap.
3. **`system_code = původní token`** u všech jednotek → UnitResolver trefí
   každý token z items/docs přes system_code probe. `quantity` z mapy, neznámé
   → `"other"`.
4. **Žádná kolize / žádný NULL system_code** — tokeny jsou unikátní z principu.
   `none` a prázdné systémové jednotky se neimportují.
5. **`name`/`shortcut` NOT NULL** vždy vyplněné (fallback pokud prázdné).
6. **`AllCodebooksRunner`** zahrnuje `units`.
7. **`ImportApp::dispatch()`** routuje `units`. `printUsage()` aktualizován.
8. **Idempotence:** druhý běh → `created=0, skipped=N` (base class
   nepatchuje, jen přeskakuje), žádný UNIQUE konflikt.
9. **Smoke test** na DS `68908901448295`:
   - `units -v` → uživatelské (`_<ndx>`) i systémové (`pcs`, `stdpage`,
     `word`, `dgrcls`, …) jednotky v `core_units`, každá se `system_code`.
   - Ověřit, že **navazující item/doc import** (Fáze 04/05) teď najde
     jednotky — systémové (`pcs`, …) i uživatelské (`_<ndx>`). To byl původní
     problém (UnitResolver nic nenašel).
   - Druhý běh → idempotence (`created=0, skipped=N`).
10. **README** aktualizovaný.

## Doporučené pořadí implementace

1. **`ENTITY_UNIT` + dispatch + printUsage** — skeleton.
2. **`UnitsRunner`** — `sourceQuery` + `mapRow` pro DB jednotky. Smoke
   `units --dry-run -v`: ověřit mapování v logu.
3. **Systémové jednotky** — `fetchSourceRows`/`systemUnitRows` čte JSON config,
   `syntheticNdx`. Smoke: v dry-run logu jsou i `pcs`/`stdpage`/`word`/`dgrcls`.
4. **`AllCodebooksRunner`** — přidat do sekvence.
5. **End-to-end:** `units`, pak `items --limit=5` → ověřit, že jednotky
   (systémové i `_<ndx>`) se resolvnou. Idempotence druhým během.
6. **README**.

## Otevřené body / rozhodnutí

### 1. `coefficient` a `is_base` — nevyplňujeme

Import nevyplňuje `coefficient` (NULL) ani `is_base` (0). Pro import 1:1
převody mezi jednotkami nepotřebujeme. Pokud uživatel chce převody (např.
`g` → `kg`), doplní `coefficient` ručně v UI u ISO jednotek. Custom
jednotky převody nemají.

### 2. system_code = `_<ndx>` u uživatelských jednotek

Uživatelské jednotky dostávají `system_code = '_<ndx>'` (původní token).
Vypadá to neobvykle (`system_code` evokuje ISO kódy), ale je to nutné: items/
docs referencují uživatelské jednotky právě tímto klíčem a UnitResolver nemá
jinou cestu než system_code probe (shortcut fallback by selhal — shortcut je
`bal`, ne `_5`). Veličina se přesto vyplní podle shortcutu (`SHORTCUT_QUANTITY`).

### 3. Veličina pro neznámé jednotky — `"other"`

Jednotky mimo `SYS_UNIT_QUANTITY` (systémové: `stdpage`, `word`, `dgrcls`) /
`SHORTCUT_QUANTITY` (uživatelské s neznámým shortcutem) → `quantity = "other"`.
Žádná heuristika podle názvu (křehká). UI je neseskupí podle veličiny, ale to
je přijatelné (převody stejně nejsou).

### 4. Drift map `quantity` vs nový Shipard

`SYS_UNIT_QUANTITY` / `SHORTCUT_QUANTITY` jsou statické kopie veličin z
`unitsSeed.jsonc`. Veličina ovlivňuje jen seskupení v UI — resolvování běží
přes `system_code`, takže případný drift nerozbije import (jen by nová ISO
jednotka spadla do `"other"`). Graceful degradation, ne chyba.

### 5. Pořadí `units` v `all-codebooks`

Units nezávisí na žádném codebooku a žádný codebook nezávisí na units
(items/docs ano, ale ty jsou mimo `all-codebooks`). Pořadí v rámci
codebooks je tedy volné. Zařazeno na konec (vedle item-kinds) jen pro
logické uspořádání "katalogových" číselníků.

## Vztah k ostatním fázím

- **Fáze 04 (items)** a **Fáze 05 (docs)** spoléhají na importované
  jednotky — `units` (resp. `all-codebooks`) musí proběhnout před nimi.
- `all` orchestrátor (Fáze 06) zařadí pořadí: `all-codebooks` (vč. units)
  → persons → items → docs.
