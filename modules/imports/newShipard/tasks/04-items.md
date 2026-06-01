# Task: Import položek (Fáze 04)

## Kontext

Fáze 04 implementuje import položek ze starého Shipardu do nového přes
exchange formát `shpd.items.item.v1` (endpoint
`POST /api/v1/_exchange/items/item/apply`).

**Infrastruktura už existuje** — `BaseExchangeRunner` (Phase 03),
`ExchangeClient` (Phase 03), rate limiting + retry (Phase 03a), LocalIdMap
s konstantami `ENTITY_ITEM_KIND` (z Phase 02) a `ENTITY_PERSON`
(z Phase 03). Phase 04 dodává pouze:

1. **`ItemsRunner`** extending `BaseExchangeRunner` s mapováním
   `e10_witems_items` + `e10_witems_itemtypes` + `e10_witems_itemSuppliers`
   na canonical payload.
2. **`LocalIdMap::ENTITY_ITEM`** konstanta.
3. Dispatch v `ImportApp`.

**Cíl Fáze 04:** Po dokončení musí jít:

```bash
shpd-app cli-action --action=imports.newShipard/import items
shpd-app cli-action --action=imports.newShipard/import items --dry-run
shpd-app cli-action --action=imports.newShipard/import items --limit=50 -v
```

**Pořadí závislostí v `all` (Fáze 06):** codebooks (vč. item-kinds) →
persons → **items** → docs. Suppliers (osoby) musí být napřed v
LocalIdMap, jinak applier dělá supplier `canCreate` → skip warning per
mapping (item se uloží, jen supplier_codes záznam chybí).

**Mimo scope Fáze 04:**

- Sady položek (`isSet=1`) — nový Shipard nemá ekvivalent. Filter SQL
  je obsahuje, ale runner je importuje jako jednoduché položky. Sub-items
  (`e10_witems_itemsets`) ignorujeme — `description` nebo `name` nese
  podstatu, sub-rows ne.
- Kódy položek (`e10_witems_itemCodes`) — EAN, SKU, nomenklatury. Phase 04
  posílá jen `e10_witems_items.id` (jako `code`). Mapování `itemCodes`
  s `codeKind`-based filtrem (EAN, manufacturerCode) → `sku`/`ean` v
  novém schema je Phase 06 polish.
- `manufacturerId`, `niceUrl`, `brand`, `useFor`, `useBalance`,
  `askQCashRegister`, `askPCashRegister`, `groupCashRegister`,
  `weightNetto*`, `successorItem` — nemapujeme. Phase 06 podle reality.
- Doklady (Fáze 05) — používá items přes LocalIdMap.

## Před implementací přečti

Z hotové infrastruktury (Phase 01–03a):

- **`modules/imports/newShipard/libs/BaseExchangeRunner.php`** — kompletní
  base class. Klíčové: `afterApplied()` hook, LocalIdMap hit → skip
  (NIKOLI useExisting), `buildCanonical()` může vrátit null.
- **`modules/imports/newShipard/libs/runners/PersonsRunner.php`** —
  hlavní vzor pro per-row mapping. Sleduj strukturu (private helpers
  `emptyToNull`, `dateToString`, `numberOrNull`, DOC_STATE_MAP konstanta,
  `afterApplied` pro post-apply PATCH).
- **`modules/imports/newShipard/libs/ExchangeClient.php`** — `apply()`
  vrací `{canonical, savedId}`.
- **`modules/imports/newShipard/libs/LocalIdMap.php`** — `ENTITY_ITEM_KIND`
  z Phase 02 + `ENTITY_PERSON` z Phase 03.

Z nového Shipardu (kompletně přečíst):

- **`nov_shipard:docs/exchange-format-items.md`** — kanonická spec.
- **`nov_shipard:modules/core/exchange/schemas/shpd.items.item.v1.jsonc`** —
  finální schema. Klíčové:
  - `unit` je **required** v top-level (`minLength: 1`).
  - `name` je required.
  - `kind` má 3 hinty (`code`, `name`, `itemType`); ItemValidator vyžaduje
    alespoň jeden (kind_required issue).
  - `supplier.country` pattern `^[a-z]{2}$` — striktně ISO alpha-2
    lowercase.
  - `kind.itemType` enum: `null | 0 | 1 | 2 | 3`.
  - `status.docState` enum: `null | 10 | 40 | 70 | 80 | 90`.
  - `applyOptions.targetDocState` enum: `null | 10 | 40` (jako persons).
- **`nov_shipard:modules/core/exchange/src/Item/ItemApplier.php`** —
  pro pochopení supplier flow (canCreate skip, mergeStrategy semantics).
- **`nov_shipard:modules/economy/items/tables/economy_items.jsonc`** —
  cílová tabulka. Pozor na `code` UNIQUE constraint a `name` NOT NULL.

Ve starém Shipardu (zdrojová data):

- **`modules/e10/witems/tables/items.json`** — hlavní zdroj.
- **`modules/e10/witems/tables/itemtypes.json`** — druhy (Phase 02 už
  importuje, mapping je v LocalIdMap).
- **`modules/e10/witems/tables/itemSuppliers.json`** — per-partner kódy.

## Co implementovat

### 1. `LocalIdMap::ENTITY_ITEM` konstanta

Přidat do **`libs/LocalIdMap.php`**:

```php
public const ENTITY_ITEM = 'item';
```

### 2. `libs/runners/ItemsRunner.php`

Implementace `BaseExchangeRunner` pro položky.

#### 2.1 Identifikace

```php
final class ItemsRunner extends BaseExchangeRunner
{
    /**
     * Mapování e10.base.defaultDocStatesArchive → core.system.docStatesArchive.
     * 9800 (Smazáno) je filtrováno ze source query.
     */
    private const DOC_STATE_MAP = [
        1000 => 10,  // Rozpracováno → Koncept
        4000 => 40,  // Potvrzeno    → V pořádku
        8000 => 80,  // V opravě     → V opravě
        9000 => 70,  // V archívu    → V archívu
    ];

    /** Tabulka v novém Shipardu pro post-apply PATCH. */
    private const NEW_ITEMS_TABLE = 'economy_items';

    /**
     * Mapování starého e10_witems_items.itemKind (enumInt) na canonical
     * kind.itemType. Oba enumy mají identický 0/1/2/3 = Služba/Zásoba/
     * Účetní/Ostatní, mapping 1:1.
     */
    private const ITEM_KIND_MAP = [
        0 => 0,  // Služba
        1 => 1,  // Zásoba
        2 => 2,  // Účetní položka
        3 => 3,  // Ostatní
    ];

    protected function entityType(): string  { return LocalIdMap::ENTITY_ITEM; }
    protected function exchangeFlow(): string { return 'items'; }
    protected function exchangeType(): string { return 'item'; }
    protected function savedIdKey(): string  { return 'savedItemId'; }
    protected function entityLabel(): string { return 'item'; }

    // ... viz dále
}
```

#### 2.2 Source query

```php
protected function sourceQuery(): array
{
    return [
        'SELECT i.*, t.[fullName] AS kind_name, t.[id] AS kind_code'
        . ' FROM [e10_witems_items] i'
        . ' LEFT JOIN [e10_witems_itemtypes] t ON i.[itemType] = t.[ndx]'
        . ' WHERE i.[docState] != %i', 9800,
        ' ORDER BY i.[ndx]',
    ];
}
```

Pozor: `itemtypes` LEFT JOIN — pokud item nemá itemType (vzácné, ale
možné u legacy dat), `kind_name`/`kind_code` budou NULL. Mapování pak
spadne zpět na `kind.itemType` fallback.

#### 2.3 `buildCanonical`

```php
protected function buildCanonical(array $oldRow): ?array
{
    $oldNdx = (int) $oldRow['ndx'];

    // Validační gate: name je required v schema (minLength: 1).
    $fullName = trim((string) ($oldRow['fullName'] ?? ''));
    if ($fullName === '') {
        $this->warn("item {$oldNdx}: missing fullName, skipping");
        return null;
    }

    // Unit je required. Pokud starý nemá defaultUnit, použij fallback "pcs".
    $unit = trim((string) ($oldRow['defaultUnit'] ?? ''));
    if ($unit === '') {
        $unit = 'pcs';
        $this->debug("item {$oldNdx}: empty defaultUnit, falling back to 'pcs'");
    }

    $supplierCodes = $this->loadSupplierCodes($oldNdx);

    return [
        'format'        => 'shpd.items.item',
        'formatVersion' => '1.0',

        'source' => [
            'kind'        => 'import.oldShipard',
            'fetchedAt'   => date('c'),
            'registryRef' => (string) $oldNdx,
        ],

        'code'        => $this->emptyToNull($oldRow['id'] ?? null),
        'name'        => $fullName,
        'description' => $this->emptyToNull($oldRow['description'] ?? null),
        'sku'         => null,
        'ean'         => null,

        'kind' => $this->buildKindObject($oldRow),

        'validFrom'       => $this->dateToString($oldRow['validFrom'] ?? null),
        'validTo'         => $this->dateToString($oldRow['validTo'] ?? null),

        'salesPriceNoVat' => $this->moneyOrNull($oldRow['priceSellBase'] ?? null),
        'unit'            => $unit,

        'supplierCodes'   => $supplierCodes,

        'status' => [
            'isClosed' => null,   // items nemají closed flag ve starém Shipardu
        ],

        'applyOptions' => [
            'mergeStrategy'  => 'fullSync',
            'targetDocState' => $this->insertDocState($oldRow),
            'rejectOnIssues' => ['error'],
        ],
    ];
}
```

#### 2.4 `buildKindObject` — tři hinty pro KindResolver

```php
private function buildKindObject(array $oldRow): array
{
    $oldItemKind = $oldRow['itemKind'] ?? null;
    $itemType = null;
    if ($oldItemKind !== null && isset(self::ITEM_KIND_MAP[(int) $oldItemKind])) {
        $itemType = self::ITEM_KIND_MAP[(int) $oldItemKind];
    }

    return [
        // Posíláme všechny tři hinty; KindResolver vybere první match:
        //   1. code (system_code) — starý kód itemtype, pokud match na seed.
        //   2. name — match podle name v economy_items_kinds. Tady spoléháme,
        //      že Phase 02 ItemKindsRunner importoval druhy se stejným name.
        //   3. itemType — fallback na seedovaný systémový druh (service/stock/
        //      accounting/other).
        'code'     => $this->emptyToNull($oldRow['kind_code'] ?? null),
        'name'     => $this->emptyToNull($oldRow['kind_name'] ?? null),
        'itemType' => $itemType,
    ];
}
```

**Klíčový poznatek:** Phase 02 ItemKindsRunner importuje druhy přes LocalIdMap
mapping `oldItemTypeNdx → newKindId`. KindResolver však pracuje na business
úrovni (system_code, name, itemType), ne přes LocalIdMap. Proto stačí
poslat tři hinty a applier si vybere.

Otevřený bod 1: rozšířit `KindResolver` o LocalIdMap-aware mapping?
Nedoporučuji — LocalIdMap je per-DS klientská cache, ne universal lookup.

#### 2.5 `loadSupplierCodes` — sub-kolekce

```php
private function loadSupplierCodes(int $itemNdx): array
{
    // JOIN se starým persons table + properties pro IČO/DIČ
    $rows = $this->db()->query(
        'SELECT s.*, p.[fullName] AS supplier_name, c.[cca2] AS country_iso'
        . ' FROM [e10_witems_itemSuppliers] s'
        . ' JOIN [e10_persons_persons] p ON s.[supplier] = p.[ndx]'
        . ' LEFT JOIN [e10_persons_personsContacts] pc'
        . '   ON pc.[person] = p.[ndx] AND pc.[flagMainAddress] = 1 AND pc.[docState] != 9800'
        . ' LEFT JOIN [e10_world_countries] c ON pc.[adrCountry] = c.[ndx]'
        . ' WHERE s.[item] = %i', $itemNdx,
        ' AND p.[docState] != %i', 9800,
        ' ORDER BY s.[rowOrder], s.[ndx]',
    )->fetchAll();

    $codes = [];
    $seenSupplierNdxs = [];

    foreach ($rows as $r) {
        $row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

        $supplierCode = trim((string) ($row['itemId'] ?? ''));
        if ($supplierCode === '') {
            // Schema vyžaduje supplierCode minLength: 1. Skip prázdné.
            continue;
        }

        $supplierNdx = (int) ($row['supplier'] ?? 0);

        // De-duplication: PersonsContacts může mít víc adres (víc řádků pro
        // stejnou osobu kvůli more addresses). JOIN je vynásobí. Bereme jen
        // první kombinaci supplier × supplierCode.
        $key = $supplierNdx . ':' . $supplierCode;
        if (isset($seenSupplierNdxs[$key])) {
            continue;
        }
        $seenSupplierNdxs[$key] = true;

        $supplier = $this->loadSupplierParty($supplierNdx, $row);
        if ($supplier === null) {
            continue;  // log v loadSupplierParty
        }

        $codes[] = [
            'supplier'     => $supplier,
            'supplierCode' => $supplierCode,
            'supplierName' => null,
        ];
    }

    return $codes;
}
```

#### 2.6 `loadSupplierParty` — Party fragment ze starého persons

```php
/**
 * Sestaví Party fragment pro supplier z hlavičky persons + properties.
 * Vrátí null pokud supplier nemá nic identifikovatelného (žádné jméno,
 * žádné IČO/DIČ).
 *
 * @param array<string, mixed> $joinRow Result row z LEFT JOIN s country_iso.
 */
private function loadSupplierParty(int $supplierNdx, array $joinRow): ?array
{
    if ($supplierNdx <= 0) {
        return null;
    }

    // IČO + DIČ z e10_base_properties
    $properties = $this->loadPersonProperties($supplierNdx);

    $name = $this->emptyToNull($joinRow['supplier_name'] ?? null);
    $companyId = $properties['oid'] ?? null;
    $vatId = $properties['taxid'] ?? null;

    // Bez identifikace skip. Applier by ho jinak resolve canCreate → skip
    // s warningem stejně, ale my šetříme HTTP request a logujeme tady.
    if ($name === null && $companyId === null && $vatId === null) {
        $this->debug("supplier ndx={$supplierNdx}: no identifiable data, skipping");
        return null;
    }

    // Country — striktně ISO 3166-1 alpha-2 lowercase pro schema validation
    $country = null;
    $rawCountry = strtolower(trim((string) ($joinRow['country_iso'] ?? '')));
    if (strlen($rawCountry) === 2 && ctype_alpha($rawCountry)) {
        $country = $rawCountry;
    }

    return [
        'name'      => $name,
        'country'   => $country,
        'companyId' => $companyId,
        'taxId'     => null,
        'vatId'     => $vatId,
        'govEBoxId' => $properties['govDataBox'] ?? null,
    ];
}

/**
 * Načtení properties pro osobu (IČO/DIČ/govDataBox). Sub-set logiky z
 * PersonsRunner::loadProperties — duplikujeme, abychom nezatahovali
 * cross-runner závislost.
 *
 * @return array<string, string>
 */
private function loadPersonProperties(int $personNdx): array
{
    $rows = $this->db()->query(
        'SELECT [property], [valueString], [ndx]'
        . ' FROM [e10_base_properties]'
        . ' WHERE [tableid] = %s', 'e10.persons.persons',
        ' AND [recid] = %i', $personNdx,
        ' AND [property] IN %in', ['oid', 'taxid', 'govDataBox'],
        ' ORDER BY [ndx] ASC',
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
        $prop = (string) ($row['property'] ?? '');
        if ($prop === '' || isset($result[$prop])) {
            continue;
        }
        $val = trim((string) ($row['valueString'] ?? ''));
        if ($val !== '') {
            $result[$prop] = $val;
        }
    }
    return $result;
}
```

#### 2.7 `afterApplied` — post-apply PATCH pro docState

Stejná logika jako `PersonsRunner` — applier umí jen 10/40, pro 70/80
PATCH přes generic CRUD.

```php
protected function afterApplied(array $oldRow, int $newId, CrudClient $crud): void
{
    $target = $this->mapDocState($oldRow);
    $insert = $this->insertDocState($oldRow);
    if ($target === $insert) {
        return;
    }

    if ($this->isDryRun()) {
        $this->debug("DRY-RUN: would PATCH " . self::NEW_ITEMS_TABLE . "/{$newId} docState={$target}");
        return;
    }

    $crud->patch(self::NEW_ITEMS_TABLE, $newId, ['docState' => $target]);
    $this->debug("item {$oldRow['ndx']}: post-apply PATCH docState {$insert} → {$target}");
}

private function mapDocState(array $oldRow): int
{
    $old = (int) ($oldRow['docState'] ?? 0);
    if (isset(self::DOC_STATE_MAP[$old])) {
        return self::DOC_STATE_MAP[$old];
    }
    $this->warn("item {$oldRow['ndx']}: unknown old docState={$old}, defaulting to 40");
    return 40;
}

private function insertDocState(array $oldRow): int
{
    return $this->mapDocState($oldRow) === 10 ? 10 : 40;
}
```

#### 2.8 Helpery (duplikace s PersonsRunner)

```php
private function emptyToNull(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
}

private function dateToString(mixed $date): ?string
{
    if ($date === null) {
        return null;
    }
    if ($date instanceof \DateTimeInterface) {
        return $date->format('Y-m-d');
    }
    $s = (string) $date;
    if ($s === '' || str_starts_with($s, '0000-00-00')) {
        return null;
    }
    return substr($s, 0, 10);
}

private function moneyOrNull(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $f = (float) $value;
    // Dovoluji 0.0 (nula je validní cena). Negativní hodnoty filter v applier.
    return $f;
}
```

### 3. Dispatch v `ImportApp`

V **`libs/ImportApp.php`** `dispatch()` přidat:

```php
case 'items':
    return (new runners\ItemsRunner($this->context()))->run();
```

A do `printUsage()` přidat:

```
  items                Položky (přes exchange formát).
```

### 4. Update `README.md`

V `modules/imports/newShipard/README.md` aktualizovat tabulku subkomand:

| Subkomand | Stav |
|---|---|
| `items` | ✅ Fáze 04 |

## Hotovo když

1. **`LocalIdMap::ENTITY_ITEM`** konstanta existuje.
2. **`ItemsRunner`** implementuje per-row mapping ze `e10_witems_items` +
   `e10_witems_itemtypes` (LEFT JOIN) + `e10_witems_itemSuppliers` na
   canonical `shpd.items.item.v1` payload.
3. **`kind` object** posílá všechny tři hinty (`code`, `name`, `itemType`)
   — applier vybere první match.
4. **`unit` fallback** na `"pcs"` pokud starý `defaultUnit` prázdný.
5. **`supplierCodes`** load z `itemSuppliers` JOIN s persons + properties.
   Skip prázdné `itemId`, skip suppliers bez identifikace.
6. **De-duplication** v `loadSupplierCodes` přes `(supplierNdx, supplierCode)`
   klíč — JOIN s personsContacts může vyrobit duplikáty.
7. **`afterApplied`** post-apply PATCH pro docState 70/80.
8. **`ImportApp::dispatch()`** routuje `items` na `ItemsRunner`.
   `printUsage()` aktualizován.
9. **Idempotence:** druhý běh nad stejnými items → většina rows má status
   `skipped (already-imported)` přes LocalIdMap.
10. **Smoke test** na DS `68908901448295`:
    - `items --limit=10 -v` projde, v UI nového Shipardu uvidíš 10 položek.
    - `items` (full) projde bez fatal errors.
    - Druhý běh `items` má `skipped=N, created=0` (LocalIdMap idempotence).
    - Items s vyplněnými supplierCodes mají v UI viditelné dodavatelské
      kódy.
11. **README aktualizovaný** o `items` subkomand.

## Doporučené pořadí implementace

1. **`LocalIdMap::ENTITY_ITEM`** + dispatch v ImportApp + printUsage —
   tenký skeleton, aby se subkomand "items" zaregistrovala.
2. **`ItemsRunner` kostra** — protected metody `entityType()`,
   `exchangeFlow()`, atd. + minimální `buildCanonical` jen s `format`,
   `source`, `code`, `name`, `unit`, `kind.itemType` fallback.
   Smoke test: `items --limit=1 -v`. Otestovat, že applier přijme.
3. **`buildKindObject`** s LEFT JOIN — přidat `kind.code`, `kind.name`
   z itemtypes. Smoke test: applier by měl matchnout přes name (pokud
   Fáze 02 importovala druhy se stejnou hodnotou `name`).
4. **`loadSupplierCodes` + `loadSupplierParty`** — sub-kolekce. Smoke
   test: položka s několika dodavateli (`e10_witems_itemSuppliers`)
   → v UI uvidíš supplier code mapping.
5. **`afterApplied` post-PATCH** pro 70/80. Smoke test: položka v
   archívu (`docState=9000`) v starém Shipardu → po importu má 70 v novém.
6. **Helpery** + edge cases (prázdný name, prázdný unit, smazaný supplier).
7. **End-to-end test:** `items` na velký DS, ověř idempotenci druhým
   spuštěním.
8. **README update**.

## Otevřené body / rozhodnutí

### 1. Kind matching přes LocalIdMap mapping

Phase 02 `ItemKindsRunner` zapisuje mapping `oldItemTypeNdx → newKindId`
do LocalIdMap. KindResolver v applieru ale **nepoužívá** LocalIdMap —
matchuje přes business klíče (system_code, name, itemType).

Phase 04 spoléhá na to, že **Phase 02 importovala druhy se stejným `name`**
a applier KindResolver napáří přes `kind.name` (krok 2 priority).

Alternativa: před apply runner SQL lookup `economy_items_kinds.name`
přes generic CRUD a vrátí ID, pak `kind.code` pošleme s tímto ID (NE
system_code, ale "interní" identifikátor)? **Nedoporučuji** — schema
očekává v `kind.code` system_code, ne ID.

Pokud se ukáže, že kind matching není spolehlivý, doplníme do PRD
explicit lookup přes CrudClient před apply. Pro Phase 04 spoléháme na
business match.

### 2. Sady položek (`isSet=1`)

Starý `e10_witems_items.isSet = 1` znamená "sada/balíček" s sub-items
v `e10_witems_itemsets`. Nový Shipard sady nemá.

Phase 04 importuje sady jako **jednoduché položky** (header only) —
sub-items se ignorují. Důsledek: položka s `isSet=1` se naimportuje,
ale "co je v ní" se ztratí.

Pokud uživatel sady aktivně používá, doplníme samostatný subkomand
`item-sets` v Phase 06, který sady namapuje na nějaký jiný koncept v
novém Shipardu (např. pomocná tabulka, popisek). Pro MVP zachováme
header a důvěřujeme uživateli, že je doplní ručně.

### 3. EAN / SKU z `itemCodes`

Starý `e10_witems_itemCodes` drží různé kódy (EAN, manufacturerCode,
nomenklatury) v generic schema přes `codeKind` enum. Phase 04 nemapuje —
posíláme `sku: null, ean: null`.

Pokud uživatel EANy aktivně používá, doplníme v Phase 06:
- SELECT z itemCodes WHERE codeKind = <EAN_VALUE>, vzít první →
  `canonical.ean`.
- SELECT z itemCodes WHERE codeKind = <SKU_VALUE>, vzít první →
  `canonical.sku`.

Konkrétní hodnoty `codeKind` enum jsou v cfgItem `e10.witems.codesKinds`
ve starém Shipardu — runner by je musel rozumět.

### 4. `manufacturerId` mapování

Starý `e10_witems_items.manufacturerId` (kód výrobce) — nový Shipard
nemá ekvivalent. Phase 04 ignoruje.

Pokud by se ukázalo, že je důležitý, jedna z možností:
- Připojit jako suffix k `description`: `"$description (kód výrobce: $manufacturerId)"`.
- Uložit jako další record v `itemCodes` v novém Shipardu (Phase 06).

Pro MVP ignorovat.

### 5. Cena s DPH vs bez

Schema posílá jen `salesPriceNoVat` (= `priceSellBase` ve starém). Starý
má i `priceSellTotal` (s DPH) a `vatRate` (sazba). Nový Shipard cenu s
DPH dopočítává sám z VAT rate na položce. Phase 04 ignoruje
`priceSellTotal`/`vatRate` v payloadu — vstupní cena je bez DPH.

Pokud se ukáže, že některé staré položky mají jen `priceSellTotal`
(prázdný `priceSellBase`), runner pošle `salesPriceNoVat: null`. To je
v pořádku — schema akceptuje null.

### 6. `successorItem` — nahrazená položka

Starý `successorItem` (FK na novou položku, která tu starou nahrazuje)
+ `successorDate` — nový Shipard nemá. Phase 04 ignoruje.

Pokud se ukáže potřeba, doplníme do `description` heuristiku "Nahrazena
položkou X od Y". Phase 06.

### 7. Supplier missing v novém DS

Pokud supplier (odkazovaný v `e10_witems_itemSuppliers`) nebyl importován
v Phase 03 (např. importujeme jen items bez persons), applier ho v
PartyResolver nenajde → `canCreate` → skip mapping s warningem (per
spec ItemApplier Phase 1).

Phase 04 **nereaguje aktivně** — důvěřuje sequenci Phase 03 → Phase 04.
Pokud `--continue-on-error` flag je on, runner tolerates per-row failures
a pokračuje. Pro production sequence to nemělo nastávat.

### 8. Re-import scénář

Druhý běh `items` má všechny existující items v LocalIdMap → SKIP
s `reason: 'already-imported'`. Pokud chceš nuked re-import po opravě
dat ve starém DS:

```sql
sqlite3 import-newShipard.sqlite "DELETE FROM id_map WHERE entity_type='item'"
```

A pak `items` znovu. To je manuální cesta. Phase 06 polish:
`--force-reimport=item` flag, který `LocalIdMap::forgetAll('item')` zavolá
před iterací.

## Vztah k Fázi 05+

Po Phase 04:

- LocalIdMap má `ENTITY_ITEM` mapping — Phase 05 (docs) ho použije pro
  document_rows.item FK resolution.
- Items se sub-kolekcí supplierCodes umožňují docs aplikovat per-supplier
  Auto-import logiku (i když to zatím není ve Fázi 05 explicitně řešeno).
- `BaseExchangeRunner` pattern je třikrát potvrzený (persons + items +
  docs) — bez surprises.
