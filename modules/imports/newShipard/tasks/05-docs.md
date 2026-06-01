# Task: Import dokladů (Fáze 05)

## Kontext

Fáze 05 implementuje import dokladů ze starého Shipardu do nového přes
exchange formát `shpd.docs.document.v1` (endpoint
`POST /api/v1/_exchange/docs/document/apply`). Nejkomplexnější fáze —
hlavička + řádky + obě strany (partner + vlastní firma) + položky + DPH.

**Infrastruktura existuje** — `BaseExchangeRunner`, `ExchangeClient`,
rate limiting, LocalIdMap (persons z Fáze 03, items z Fáze 04). Fáze 05
dodává `DocsRunner` + `ENTITY_DOC` + `--from`/`--to` filtr.

**Cíl Fáze 05 (MVP scope):** Faktury přijaté (`invni`) a faktury vydané
(`invno`) — dva nejdůležitější typy dokladů. Ostatní typy (pokladní,
bankovní, objednávky, dodací listy) jsou mimo scope prvního pokusu (viz
Open Issue 1).

```bash
shpd-app cli-action --action=imports.newShipard/import docs
shpd-app cli-action --action=imports.newShipard/import docs --from=2024-01-01 --to=2024-12-31
shpd-app cli-action --action=imports.newShipard/import docs --from=2024-01-01 --limit=20 -v
```

## ⚠ Klíčová zjištění o exchange formátu dokladů

Při průzkumu DocumentApplier jsem zjistil zásadní věci, které tvarují
celou fázi:

1. **Exchange formát je business-level, ne FK-level.** Doklad se NEodkazuje
   na codebook ID. Místo toho:
   - `number_series` — applier vybere automaticky první aktivní řadu pro
     daný `docType` (`resolveNumberSeriesFor`).
   - `vat_registration` — applier vybere podle `vat.registrationCountry`
     (`resolveVatRegistrationFor`).
   - `fiscal_year` / `fiscal_month` / `vat_period` — dopočítá
     `DocDocument::beforeSave` podle `accounting_date`.
   - `partner` — z `supplier`/`customer` Party (PartyResolver, business klíče).

   **Důsledek:** LocalIdMap z Fáze 02 (codebooks) se u dokladů **NEPOUŽIJE**.
   Posíláme business-level data, server si FK doplní sám.

2. **`cost_center` (středisko) a `warehouse` (sklad) NEEXISTUJÍ na
   `docs_core_heads`.** Nový Shipard zatím doklady neváže na střediska
   ani sklady. **Tato data se při importu ztratí** — není kam je dát.
   To není naše chyba; je to stav nového Shipardu. (Open Issue 2.)

3. **`selfParty` mechanismus vyžaduje označenou vlastní firmu.** Když
   pošleme `selfParty: "supplier"`, applier volá `PartyResolver::resolveSelfParty()`,
   který hledá firmu s `is_own = 1` v novém Shipardu. **Fáze 03 importovala
   persons s `isOwn = false` (hardcoded).** Bez označené vlastní firmy
   selfParty resolve selže. → **Tvrdá prerekvizita, viz sekce "Prerekvizity".**

## Prerekvizity

### V novém Shipardu (manuální, před importem dokladů)

**Vlastní firma musí být označená `is_own = 1`.** Po Fázi 03 jsou všechny
osoby `is_own = false`. Uživatel musí v UI nového Shipardu (nebo SQL)
označit svou firmu:

```sql
-- Zjistit ID vlastní firmy (podle IČO):
SELECT id, full_name, company_id FROM base_persons_persons WHERE company_id = '<vlastní-IČO>';

-- Označit jako vlastní:
UPDATE base_persons_persons SET is_own = 1 WHERE id = <id>;
```

`DocsRunner` na začátku **ověří**, že existuje alespoň jedna `is_own = 1`
firma (přes generic CRUD GET), a pokud ne, **abortuje** s instrukcí. Viz
sekce 2.2.

### Pořadí importu

`all` (Fáze 06) sekvence: codebooks → persons → items → **docs**. Docs
spoléhají na to, že partneři (osoby) a položky už jsou v DB. Pokud nejsou,
applier je vytvoří přes `autoCreateMode` (viz 2.5) — ale to není ideální
(duplikáty). Pro čistý import dělej fáze v pořadí.

## Před implementací přečti

Z hotové infrastruktury:

- **`modules/imports/newShipard/libs/BaseExchangeRunner.php`** — base class.
  Pozor: hook `afterApplied`, LocalIdMap hit → skip, `buildCanonical`
  může vrátit null.
- **`modules/imports/newShipard/libs/runners/PersonsRunner.php`** a
  **`ItemsRunner.php`** — vzory pro Party konstrukci (`loadSupplierParty`
  v ItemsRunner je přímý vzor pro partner Party tady).
- **`modules/imports/newShipard/libs/ExchangeClient.php`** — `apply`.

Z nového Shipardu (kompletně):

- **`nov_shipard:docs/exchange-format.md`** — kanonická spec dokladů.
- **`nov_shipard:modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`** —
  schema. Klíčové: `docType` required, `selfParty` enum, `supplier`/`customer`
  Party, `dates`, `vat`, `payment`, `rows[]` s `RowItem`, `applyOptions.targetDocState`
  enum **[10, 20]** (jiné než persons/items!).
- **`nov_shipard:modules/core/exchange/src/Document/DocumentApplier.php`** —
  KRITICKÉ. Pochopit:
  - `transform()` — co se mapuje, co se dopočítává.
  - `resolveNumberSeriesFor` / `resolveVatRegistrationFor` — auto FK.
  - `DOC_TYPE_MAP`, `VAT_MODE_MAP`, `VAT_PLACE_MAP`, `PAYMENT_METHOD_MAP`,
    `ROW_KIND_MAP`, `PRICE_CALC_MODE_MAP` — enum mapování (canonical →
    interní). Posíláme canonical hodnoty (string), applier mapuje.
  - `autoCreateMode` semantics (strict/safe/liberal) + `safetyGuardOk`.
- **`nov_shipard:modules/docs/core/tables/docs_core_heads.jsonc`** +
  `docs_core_rows.jsonc` — cílové tabulky. **Ověř `docState` enum hodnoty**
  (Open Issue 3 — co je 10 vs 20).
- **`nov_shipard:modules/docs/core/config/docTypes.jsonc`** — ověř, že
  `invni`/`invno` existují, nebo jaké jsou klíče.

Ze starého Shipardu:

- **`modules/e10doc/core/tables/heads.json`** — zdrojová hlavička.
- **`modules/e10doc/core/tables/rows.json`** — zdrojové řádky.
- **`modules/e10pro/install/docs-core/config/e10.docs.types.json`** —
  docType definice. Klíčové atributy: `tradeDir` (1=prodej, 2=nákup),
  `taxDir`, `docDir`.

## Co implementovat

### 1. `LocalIdMap::ENTITY_DOC` konstanta

```php
public const ENTITY_DOC = 'doc';
```

### 2. `libs/runners/DocsRunner.php`

#### 2.1 Identifikace + konstanty

```php
final class DocsRunner extends BaseExchangeRunner
{
    /** Mapování starého docType → canonical docType. MVP: jen faktury. */
    private const DOC_TYPE_MAP = [
        'invni' => 'invoiceReceived',
        'invno' => 'invoiceIssued',
    ];

    /**
     * Směr dokladu — kdo jsme MY. Odvozeno z tradeDir v e10.docs.types:
     *   tradeDir=1 (prodej) → my supplier → selfParty=supplier, partner=customer
     *   tradeDir=2 (nákup)  → my customer → selfParty=customer, partner=supplier
     * Pro MVP fixně podle docType (invno=prodej, invni=nákup).
     */
    private const SELF_PARTY_MAP = [
        'invni' => 'customer',  // faktura přijatá: my jsme zákazník
        'invno' => 'supplier',  // faktura vydaná: my jsme dodavatel
    ];

    /** taxCalc (hlavička) → canonical vat.mode. */
    private const VAT_MODE_MAP = [
        0 => 'none',       // nedaňový
        1 => 'fromBase',   // ze základu
        2 => 'fromTotal',  // z ceny celkem KOEF
        3 => 'fromTotal',  // z ceny celkem
    ];

    /** taxType (hlavička) → canonical vat.place. */
    private const VAT_PLACE_MAP = [
        0 => 'domestic',     // tuzemsko
        1 => 'intracom',     // intrakomunitární
        2 => 'thirdCountry', // zahraničí
    ];

    /** Tabulka v novém Shipardu pro post-apply PATCH (zachování čísla vydané faktury). */
    private const NEW_HEADS_TABLE = 'docs_core_heads';

    /** paymentMethod (hlavička) → canonical payment.method. */
    private const PAYMENT_METHOD_MAP = [
        0 => 'cash',
        1 => 'bankTransfer',
        2 => 'card',
        3 => 'cashOnDelivery',
        // starý cfgItem e10.docs.paymentMethods může mít víc hodnot;
        // neznámé → bankTransfer fallback + warning.
    ];

    protected function entityType(): string  { return LocalIdMap::ENTITY_DOC; }
    protected function exchangeFlow(): string { return 'docs'; }
    protected function exchangeType(): string { return 'document'; }
    protected function savedIdKey(): string  { return 'savedDocId'; }
    protected function entityLabel(): string { return 'document'; }
}
```

#### 2.2 Pre-flight check — vlastní firma

Override `run()` (nebo lépe přidat pre-check do `buildCanonical` první
iterace) — ověřit existenci `is_own = 1` firmy předtím, než zpracujeme
první doklad.

**Doporučení:** override `run()`, zavolat parent po checku:

```php
public function run(): bool
{
    if (!$this->isDryRun() && !$this->hasOwnCompany()) {
        $this->err("No own company (is_own=1) found in target Shipard.");
        $this->err("Documents use selfParty resolution which requires a flagged own company.");
        $this->err("Set it via UI or SQL:");
        $this->err("  UPDATE base_persons_persons SET is_own = 1 WHERE company_id = '<your-ICO>';");
        return false;
    }
    return parent::run();
}

private function hasOwnCompany(): bool
{
    $crud = new CrudClient($this->http());
    $row = $crud->findOneBy('base_persons_persons', 'is_own', 1);
    return $row !== null;
}
```

(Pokud `findOneBy` filter na `is_own=1` nefunguje přes generic CRUD,
fallback: GET list a scan. Viz Fáze 02 Open Issue 8 o filter formátu.)

#### 2.3 Source query — s `--from`/`--to` filtrem

```php
protected function sourceQuery(): array
{
    $docTypes = array_keys(self::DOC_TYPE_MAP);  // ['invni', 'invno']

    $q = [
        'SELECT h.* FROM [e10doc_core_heads] h'
        . ' WHERE h.[docState] != %i', 9800,        // ne smazané
        ' AND h.[docType] IN %in', $docTypes,        // jen faktury (MVP)
    ];

    // Filtr období na dateAccounting (rozhodnuto v PRD diskusi — zajišťuje
    // kompletní fiskální období, na rozdíl od dateIssue).
    $from = $this->dateArg('from');
    $to   = $this->dateArg('to');
    if ($from !== null) {
        $q[] = ' AND h.[dateAccounting] >= %d';
        $q[] = $from;
    }
    if ($to !== null) {
        $q[] = ' AND h.[dateAccounting] <= %d';
        $q[] = $to;
    }

    $q[] = ' ORDER BY h.[ndx]';
    return $q;
}

/**
 * Parse --from / --to CLI arg jako YYYY-MM-DD. Vrátí null pokud chybí
 * nebo nevalidní (s warningem).
 */
private function dateArg(string $name): ?string
{
    $raw = $this->app()->arg($name);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $this->warn("Invalid --{$name} date '{$raw}' (expected YYYY-MM-DD), ignoring.");
        return null;
    }
    return $raw;
}
```

**Pozor na Dibi parametrizaci:** sestavení query array s podmíněnými
částmi musí respektovat, jak Dibi páruje `%d` placeholdery s argumenty.
Sleduj existující pattern v PersonsRunner sourceQuery (modifikátory
inline). Pokud podmíněné přidávání placeholderů je křehké, alternativa:
postavit WHERE klauzule jako samostatné stringy a spojit. Ověř, že
výsledná query funguje (smoke test s `--from`).

#### 2.4 `buildCanonical`

```php
protected function buildCanonical(array $oldRow): ?array
{
    $oldNdx = (int) $oldRow['ndx'];
    $oldDocType = (string) ($oldRow['docType'] ?? '');

    $docType = self::DOC_TYPE_MAP[$oldDocType] ?? null;
    if ($docType === null) {
        $this->warn("doc {$oldNdx}: unsupported docType '{$oldDocType}', skipping");
        return null;
    }

    $selfParty = self::SELF_PARTY_MAP[$oldDocType] ?? null;

    // Partner = protistrana. Pro invni je to supplier (person),
    // pro invno customer (person). Vlastní firma jde přes selfParty flag.
    $partnerNdx = (int) ($oldRow['person'] ?? 0);
    $partnerParty = $partnerNdx > 0 ? $this->loadParty($partnerNdx) : null;

    $supplier = $selfParty === 'supplier' ? null : $partnerParty;
    $customer = $selfParty === 'customer' ? null : $partnerParty;

    $rows = $this->loadRows($oldNdx);
    if ($rows === []) {
        $this->warn("doc {$oldNdx}: no rows, skipping");
        return null;
    }

    return [
        'format'        => 'shpd.docs.document',
        'formatVersion' => '1.0',

        'source' => [
            'kind' => 'import.oldShipard',
            'raw'  => ['oldNdx' => $oldNdx],
        ],

        'docType'   => $docType,
        'docNumber' => $this->emptyToNull($oldRow['docNumber'] ?? null),
        'docText'   => $this->emptyToNull($oldRow['title'] ?? null),
        'selfParty' => $selfParty,

        'supplier' => $supplier,
        'customer' => $customer,

        'dates' => [
            'issueDate'         => $this->dateToString($oldRow['dateIssue'] ?? null),
            'dueDate'           => $this->dateToString($oldRow['dateDue'] ?? null),
            'accountingDate'    => $this->dateToString($oldRow['dateAccounting'] ?? null),
            'taxPointDate'      => $this->dateToString($oldRow['dateTax'] ?? null),
            'vatObligationDate' => $this->dateToString($oldRow['dateTaxDuty'] ?? null),
            'periodFrom'        => $this->dateToString($oldRow['datePeriodBegin'] ?? null),
            'periodTo'          => $this->dateToString($oldRow['datePeriodEnd'] ?? null),
        ],

        'currency'     => $this->currencyUpper($oldRow['currency'] ?? null),
        'exchangeRate' => $this->positiveOrNull($oldRow['exchangeRate'] ?? null),

        'vat' => [
            'mode'                => self::VAT_MODE_MAP[(int) ($oldRow['taxCalc'] ?? 1)] ?? 'fromBase',
            'place'               => self::VAT_PLACE_MAP[(int) ($oldRow['taxType'] ?? 0)] ?? 'domestic',
            'registrationCountry' => $this->emptyToNull($oldRow['taxCountry'] ?? null),
        ],

        'payment' => [
            'method'         => self::PAYMENT_METHOD_MAP[(int) ($oldRow['paymentMethod'] ?? 1)] ?? 'bankTransfer',
            'variableSymbol' => $this->emptyToNull($oldRow['symbol1'] ?? null),
            'specificSymbol' => $this->emptyToNull($oldRow['symbol2'] ?? null),
            'constantSymbol' => null,
        ],

        'notes' => [
            'internal'   => null,
            'onDocument' => null,
        ],

        'rows' => $rows,

        'totals' => [
            'totalBase'     => $this->moneyOrNull($oldRow['sumBase'] ?? null),
            'totalVat'      => $this->moneyOrNull($oldRow['sumTax'] ?? null),
            'totalAmount'   => $this->moneyOrNull($oldRow['sumTotal'] ?? null),
            'totalRounding' => $this->moneyOrNull($oldRow['rounding'] ?? null),
        ],

        'applyOptions' => [
            'targetDocState'        => $this->targetDocState(),
            'autoCreateMode'        => 'safe',
            'createMissingEntities' => true,
            'rejectOnIssues'        => ['error'],
        ],
    ];
}
```

#### 2.5 `loadParty` — partner Party fragment

Analogie `ItemsRunner::loadSupplierParty`, ale plnější (s adresou +
bankovním účtem, protože doklad nese víc partner detailů).

```php
private function loadParty(int $personNdx): ?array
{
    $personRow = $this->db()->query(
        'SELECT * FROM [e10_persons_persons] WHERE [ndx] = %i', $personNdx,
    )->fetch();
    if ($personRow === null) {
        return null;
    }
    $person = is_object($personRow) && method_exists($personRow, 'toArray')
        ? $personRow->toArray() : (array) $personRow;

    $properties = $this->loadPersonProperties($personNdx);
    $address = $this->loadMainAddress($personNdx);
    $bank = $this->loadFirstBankAccount($personNdx);

    return [
        'name'              => $this->emptyToNull($person['fullName'] ?? null),
        'country'           => $address['country'] ?? null,
        'companyId'         => $properties['oid']   ?? null,
        'taxId'             => null,
        'vatId'             => $properties['taxid'] ?? null,
        'courtRegistration' => null,
        'address'           => $address['canonical'] ?? null,
        'contact'           => null,
        'bankAccount'       => $bank,
        'paymentTermDays'   => null,
    ];
}
```

Pomocné metody `loadPersonProperties` (kopie z ItemsRunner),
`loadMainAddress` (vrátí `{country, canonical}` — Address fragment dle
docs schema `$defs/Address`), `loadFirstBankAccount` (vrátí BankAccount
fragment nebo null).

**Address fragment** (docs schema `$defs/Address`) má jiná pole než
persons addresses — pouze `street`, `houseNumber`, `city`, `cityPart`,
`zip`, `country`, `registryCode`, `displayLine`, `displayBlock`. Mapuj
z `e10_persons_personsContacts` (flagMainAddress=1).

**BankAccount fragment** (docs schema `$defs/BankAccount`) — `accountNumber`,
`iban`, `bic`, `currency`. Mapuj z `e10_persons_personsBA` (první).

#### 2.6 `loadRows` — řádky dokladu

```php
private function loadRows(int $docNdx): array
{
    $rows = $this->db()->query(
        'SELECT r.*, i.[id] AS item_code, i.[fullName] AS item_name'
        . ' FROM [e10doc_core_rows] r'
        . ' LEFT JOIN [e10_witems_items] i ON r.[item] = i.[ndx]'
        . ' WHERE r.[document] = %i', $docNdx,
        ' ORDER BY r.[rowOrder], r.[ndx]',
    )->fetchAll();

    $out = [];
    $pos = 0;
    foreach ($rows as $r) {
        $row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
        $pos++;

        // RowItem — ourCode z items.id, name z item nebo text
        $itemCode = $this->emptyToNull($row['item_code'] ?? null);
        $itemName = $this->emptyToNull($row['item_name'] ?? null)
            ?? $this->emptyToNull($row['text'] ?? null);

        $rowKind = ($itemCode !== null || ($row['item'] ?? 0) > 0) ? 'item' : 'text';

        $out[] = [
            'rowKind'  => $rowKind,
            'orderPos' => $pos,
            'item'     => $rowKind === 'item' ? [
                'ourCode'      => $itemCode,
                'supplierCode' => null,
                'sku'          => null,
                'ean'          => null,
                'name'         => $itemName,
                'description'  => $this->emptyToNull($row['text'] ?? null),
            ] : null,
            'unit'           => $this->emptyToNull($row['unit'] ?? null),
            'quantity'       => $this->numberOrNull($row['quantity'] ?? null),
            'unitPrice'      => $this->numberOrNull($row['priceItem'] ?? null),
            'totalPrice'     => $this->moneyOrNull($row['priceAll'] ?? null),
            'priceCalcMode'  => ((int) ($row['priceSource'] ?? 0) === 1) ? 'fromTotal' : 'fromUnitPrice',
            'discountPct'    => null,
            'discountAmount' => null,
            'vat' => [
                'code' => $this->emptyToNull($row['taxCode'] ?? null),
                'pct'  => $this->numberOrNull($row['taxPercents'] ?? null),
            ],
        ];
    }
    return $out;
}
```

#### 2.7 `targetDocState`

```php
/**
 * Cílový docState pro importované doklady. Schema applieru dovoluje jen
 * 10 (Koncept) nebo 20 (aktivní/pořízený doklad). ROZHODNUTO: import
 * vytváří rovnou aktivní doklady (20) — importujeme reálná data, ne koncepty.
 *
 * Pozn.: pokud Claude Code při implementaci zjistí, že docState=20 spouští
 * nechtěné side-efekty (např. EET odeslání, externí notifikace), eskaluj —
 * záměr je "aktivní/platný doklad bez externích akí". Override pro
 * testování: --target-state=10.
 */
private function targetDocState(): int
{
    $arg = $this->app()->arg('target-state');
    if ($arg !== null && (int) $arg === 10) {
        return 10;
    }
    return 20;
}
```

#### 2.7b `afterApplied` — zachování čísla vydané faktury (ROZHODNUTO)

DocumentApplier mapuje `canonical.docNumber → partner_doc_number` a vlastní
`doc_number` generuje z number_series. Pro **přijaté faktury** (invni) je to
správně — naše uložené číslo = dodavatelovo číslo → `partner_doc_number`.

Pro **vydané faktury** (invno) ale chceme **zachovat původní číslo** —
jinak by importovaná faktura dostala nové číslo z number_series a rozbila by
se návaznost číslování. Proto po apply provedeme PATCH `doc_number` na původní
hodnotu.

```php
protected function afterApplied(array $oldRow, int $newId, CrudClient $crud): void
{
    $oldDocType = (string) ($oldRow['docType'] ?? '');

    // Jen vydané faktury — zachovat původní doc_number. Přijaté faktury
    // mají naše docNumber správně v partner_doc_number, applier vygeneroval
    // vlastní doc_number — to je žádoucí.
    if ($oldDocType !== 'invno') {
        return;
    }

    $origNumber = $this->emptyToNull($oldRow['docNumber'] ?? null);
    if ($origNumber === null) {
        return;
    }

    if ($this->isDryRun()) {
        $this->debug("DRY-RUN: would PATCH " . self::NEW_HEADS_TABLE . "/{$newId} doc_number={$origNumber}");
        return;
    }

    try {
        $crud->patch(self::NEW_HEADS_TABLE, $newId, ['doc_number' => $origNumber]);
        $this->debug("doc {$oldRow['ndx']}: restored original doc_number '{$origNumber}'");
    } catch (HttpException $e) {
        // doc_number má unique index per (number_series, fiscal_year). Pokud
        // PATCH selže na konfliktu, logni warning, ale neruš import — doklad
        // je uložený, jen má vygenerované číslo místo původního.
        $this->warn("doc {$oldRow['ndx']}: could not restore doc_number '{$origNumber}' "
            . "(HTTP {$e->statusCode}: {$e->errorMessage}); keeping generated number");
    }
}
```

**Pozor na unique index.** `doc_number` má v `docs_core_heads` unique index
v rámci `(number_series, fiscal_year)`. PATCH na původní hodnotu může
selhat, pokud:
- number_series counter mezitím vygeneroval stejné číslo pro jiný doklad, nebo
- dva staré doklady měly stejné `docNumber` (nemělo by, ale legacy data).

PATCH selhání je **non-fatal** — doklad zůstává uložený s vygenerovaným
číslem, jen logujeme warning. Při smoke testu ověř, kolik PATChů selže —
pokud hodně, ještě zvážíme alternativní strategii (např. přednastavení
number_series counteru).

#### 2.8 Helpery

`emptyToNull`, `dateToString`, `numberOrNull`, `moneyOrNull` —
kopie z PersonsRunner/ItemsRunner. Plus:

```php
private function currencyUpper(mixed $val): ?string
{
    $s = strtoupper(trim((string) ($val ?? '')));
    return preg_match('/^[A-Z]{3}$/', $s) ? $s : null;
}

private function positiveOrNull(mixed $val): ?float
{
    if ($val === null || $val === '') return null;
    $f = (float) $val;
    return $f > 0 ? $f : null;
}
```

### 3. Dispatch v `ImportApp`

```php
case 'docs':
    return (new runners\DocsRunner($this->context()))->run();
```

A `printUsage()` rozšířit o `docs` + `--from`/`--to`/`--target-state` opce.

### 4. Update `README.md`

Tabulka subkomand: `docs | ✅ Fáze 05`. Plus dokumentace `--from`/`--to`
filtru a prerekvizity vlastní firmy.

## Hotovo když

1. **`LocalIdMap::ENTITY_DOC`** konstanta existuje.
2. **`DocsRunner`** mapuje `e10doc_core_heads` + `e10doc_core_rows` na
   canonical `shpd.docs.document.v1`.
3. **Směr dokladu** správně: `invni` → selfParty=customer, partner=supplier;
   `invno` → selfParty=supplier, partner=customer.
4. **Pre-flight check** vlastní firmy — runner abortuje s instrukcí, pokud
   není `is_own=1` firma.
5. **`--from`/`--to` filtr** na `dateAccounting` funguje.
6. **`--target-state`** override (default 20 — aktivní doklady).
7. **Zachování čísla vydaných faktur** — `afterApplied` PATCHne `doc_number`
   na původní hodnotu pro `invno` (non-fatal při unique konfliktu).
7. **Partner Party** se sestaví z persons + properties + main address +
   bank account.
8. **Řádky** se mapují s RowItem (ourCode z items.id), unit, quantity,
   prices, vat code/pct.
9. **`autoCreateMode: "safe"`** — partner/item bez identifikace se
   nevytvoří (zabrání duplikátům, protože persons+items jsou už importované).
10. **Idempotence:** druhý běh → skip přes LocalIdMap (BaseExchangeRunner).
11. **`ImportApp::dispatch()`** routuje `docs`. `printUsage()` aktualizován.
12. **Smoke test** na DS `68908901448295`:
    - Označit vlastní firmu `is_own=1` v novém Shipardu.
    - `docs --from=2024-01-01 --to=2024-12-31 --limit=10 -v` → 10 dokladů
      v novém Shipardu, správný partner, řádky, částky.
    - Ověřit v UI, že faktury mají správný směr (přijaté vs vydané),
      partnera, položky.
    - Plný `docs --from=2024-01-01 --to=2024-12-31`.
    - Druhý běh → idempotence (skip).
13. **README** aktualizovaný.

## Doporučené pořadí implementace

1. **`ENTITY_DOC` + dispatch + printUsage** — skeleton.
2. **`DocsRunner` kostra** + konstanty + `sourceQuery` (bez filtru).
   Smoke: vypsat počet dokladů.
3. **Pre-flight check** vlastní firmy.
4. **`buildCanonical` minimal** — format, docType, selfParty, dates,
   currency, vat, payment (bez partner, bez rows). Smoke `--limit=1`:
   ověřit, že applier přijme prázdný doklad (asi 422 — chybí rows; OK,
   ověřujeme transport).
5. **`loadRows`** — řádky. Smoke `--limit=1`: doklad s řádky se uloží.
6. **`loadParty` + sub-helpery** (address, bank). Smoke: partner se
   napáruje (matched přes companyId).
7. **`--from`/`--to` filtr** — přidat do sourceQuery. Smoke s `--from`.
8. **`afterApplied`** PATCHne původní `doc_number` pro vydané faktury
   (invno). Smoke: vydaná faktura má v novém Shipardu původní číslo, ne
   vygenerované z number_series.
9. **End-to-end** na rok dat, ověřit idempotenci.
10. **README**.

## Otevřené body / rozhodnutí

### 1. Scope — jen faktury (invni/invno)

MVP importuje jen faktury přijaté a vydané. Ostatní typy:

- `cash` (pokladní), `bank` (bankovní) — jiná struktura (cashBoxDir,
  credit/debit), potřebují vlastní mapping. Fáze 06+.
- `orderin`/`orderout` (objednávky), `dlvrnote` (dodací list),
  `prfmin`/`invpo` (zálohové) — Fáze 06+.

Rozšíření `DOC_TYPE_MAP` + `SELF_PARTY_MAP` o další typy je přímočaré,
jakmile bude jasné, jak je mapovat. Pro první pokus stačí faktury.

### 2. Středisko + sklad se ztrácí

`docs_core_heads` nemá `cost_center` ani `warehouse`. Importované doklady
je nebudou mít. To je **stav nového Shipardu**, ne chyba importu.

Pokud nový Shipard tyto sloupce v budoucnu přidá, doplníme:
- post-apply PATCH (jako persons docState) — `afterApplied` zapíše
  cost_center/warehouse z LocalIdMap (Fáze 02 mapping).

Pro teď: data se ztratí, dokumentovat v README jako known limitation.

### 3. `targetDocState` — 10 vs 20 (ROZHODNUTO: 20, aktivní doklady)

> **Rozhodnuto:** import vytváří rovnou aktivní doklady (docState 20). Viz
> sekce 2.7 `targetDocState`. Níže původní analýza — Claude Code stále ověří
> význam 20 v cfgItem; pokud spouští externí side-efekty (EET, notifikace),
> eskaluj.

Schema docs `applyOptions.targetDocState` enum je **[10, 20]** (ne 10/40
jako persons/items). Potřebuji ověřit význam v
`nov_shipard:modules/docs/core/config/` docStates cfgItem:
- 10 = Koncept (pravděpodobně)
- 20 = ? (Pořízeno / Vystaveno / Aktivní)

**Doporučení:** default 20 (aktivní doklad — importujeme reálná data).
Pokud 20 znamená něco jiného (nebo spustí nechtěné side-efekty jako
přiřazení čísla, EET, …), použij 10 a nech uživatele aktivovat.

Claude Code: ověř význam před nastavením defaultu. Pokud nejistota,
default 10 (bezpečnější — koncept) a dokumentuj, že aktivace je manuální.

### 4. Číslo dokladu — partner_doc_number vs doc_number (ROZHODNUTO: zachovat původní)

> **Rozhodnuto:** zachovat původní čísla. Přijaté faktury (invni) — naše
> docNumber → partner_doc_number (správně, applier generuje vlastní doc_number).
> Vydané faktury (invno) — `afterApplied` PATCHne `doc_number` na původní
> hodnotu (viz sekce 2.7b). Níže původní analýza variant.

Starý `docNumber` je naše číslo dokladu. V novém Shipardu:
- `doc_number` — generuje se z number_series (applier).
- `partner_doc_number` — číslo dokladu od partnera (u přijatých faktur).

DocumentApplier mapuje `canonical.docNumber → partner_doc_number`! Tj.
naše staré číslo se uloží jako "partnerovo číslo". Pro **přijaté faktury**
(invni) to dává smysl — `docNumber` je často číslo dodavatelovy faktury.
Pro **vydané faktury** (invno) je `docNumber` naše číslo — a applier ho
dá do `partner_doc_number`, což je špatně, a navíc vygeneruje nové číslo
z number_series.

**Problém:** importované vydané faktury dostanou NOVÁ čísla z number_series,
ne původní. To může být nežádoucí (rozbije návaznost).

Možnosti:
- A) Akceptovat — vydané faktury dostanou nová čísla. Jednoduché, ale
  ztrácí původní číslování.
- B) Post-apply PATCH `doc_number` na původní hodnotu (afterApplied).
  Zachová číslování, ale obchází number_series counter (možný konflikt).
- C) Nastavit number_series counter tak, aby generoval správná čísla
  (komplikované).

**Doporučení pro MVP:** B — post-apply PATCH `doc_number` na původní
`docNumber` pro vydané faktury. Pro přijaté faktury nechat applier
(naše docNumber → partner_doc_number je správně). Otestovat, že PATCH
na `doc_number` nezpůsobí konflikt s unique indexem.

Claude Code: zvaž a rozhodni podle reality. Pokud B je riskantní
(unique konflikt), fallback A s dokumentací.

### 5. VAT code mapping

Starý `taxCode` (enumString z `e10.base.taxCodes`) → canonical `vat.code`.
VatCodeResolver v novém Shipardu mapuje. Otázka: kryjí se hodnoty?

Starý: pravděpodobně `high`, `low`, `zero`, `none`, `rchg` (reverse charge)…
Nový: VatCodeResolver akceptuje nějaké kódy + pct.

Posíláme `vat.code` + `vat.pct`. Pokud kód nematchne, applier použije
pct. **Doporučení:** posílat oba (code i pct) — pokud code selže,
pct zachrání mapping. Otestovat na reálných datech, jaké taxCode hodnoty
DS má, a případně doplnit mapping tabulku v DocsRunner.

### 6. Doklady bez partnera

Některé doklady (interní, opravné) nemají `person`. `buildCanonical`
pak má `partnerParty = null`, supplier i customer null. Applier s
selfParty vyřeší jednu stranu (vlastní firma), druhá zůstane null.

Pro fakturu to je nestandardní (faktura má vždy dvě strany). Pokud
`partnerNdx = 0`, runner buď:
- skip s warning (faktura bez partnera je podezřelá), nebo
- pošle bez partnera a nech applier rozhodnout.

**Doporučení:** pro faktury (invni/invno) skip s warning, pokud
`partnerNdx = 0`. Logni "doc N: no partner, skipping".

### 7. Řádky typu sada (rowType 1/2)

Starý `e10doc_core_rows.rowType`: 0=ručně, 1=sada-zásoby, 2=sada-doplněk.
Sady rozkládají položku na komponenty. Pro MVP importujeme **všechny
řádky** jak jsou (vč. rozložených sad). To může vytvořit duplicitní
řádky (sada + její komponenty).

**Doporučení:** importovat jen `rowType = 0` (ruční řádky) + `rowType = 1`
(hlavní řádek sady), přeskočit `rowType = 2` (doplňkové komponenty)?
Nebo importovat vše? Pro první pokus importovat **rowType IN (0, 1)**,
přeskočit 2 — zabráníme duplicitě cen. Otestovat na reálných datech.

Claude Code: ověř, jak sady reálně vypadají v DS, a rozhodni filter.

### 8. Totals mismatch warning

Posíláme `totals` jako informative. Applier je recomputuje a porovná
(`totals_mismatch` warning, ne error). To je OK — drobné rozdíly v
zaokrouhlení nezablokují import. Pokud `rejectOnIssues: ['error']`,
warning neblokuje.

Pokud by mismatche byly velké (chyba v mappingu cen), uvidíme to v
logu. Sleduj při smoke testu.

### 9. exchange_rate pro CZK doklady

Starý DS má doklady v CZK s `exchangeRate = 0` nebo `1` nebo prázdné.
`positiveOrNull` vrátí null pro 0. Applier pak nemá rate — pro CZK
domácí měnu to nevadí (rate 1). Pro cizí měnu by chyběl — ale to je
edge case.

**Doporučení:** null pro CZK je OK. Pro cizí měny ověř, že `exchangeRate`
ve starém DS je vyplněný.

## Vztah k Fázi 06

Po Phase 05:

- Všechny entity (codebooks, persons, items, docs) jsou importovatelné.
- Fáze 06 přidá `all` orchestrátor (celá sekvence), hromadné statistiky,
  rozšíření docType scope (cash, bank, orders), wrapper script, force
  re-import flag, persistent logging.
- Případné post-apply PATChe (cost_center/warehouse pokud nový Shipard
  doplní, doc_number pro vydané faktury) se doladí podle reálných dat.
