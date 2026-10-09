# Shipard — Dokumentový systém

## 1. Přehled

Dokumentový systém je vrstva nad databázovými tabulkami, která zajišťuje business logiku při práci s daty. Poskytuje:

- **Životní cyklus dokumentu** — hooky pro validaci, transformaci a reakci na události (uložení, smazání, načtení)
- **Polymorfní chování** — různé PHP třídy pro různé typy dokumentů v téže tabulce (např. přijatá vs. vydaná faktura)
- **Dokumentové API** — jednoduché rozhraní pro ukládání dat, kde stačí předat klíčové údaje a systém dopočítá zbytek
- **Hlavička + řádky** — podpora pro dokumenty s podřízenými záznamy (faktura s řádky), vždy v DB transakci

---

## 2. Architektura

```
┌─────────────────────────────────────────────────────────┐
│  REST API / CLI                                         │
├─────────────────────────────────────────────────────────┤
│  TableGateway                                           │
│  - loadRecord(), saveDocument(), deleteDocument()       │
│  - najde správnou Document třídu podle typu             │
├─────────────────────────────────────────────────────────┤
│  Document (abstraktní)                                  │
│  - validate(), beforeSave(), afterSave()                │
│  - beforeDelete(), afterDelete(), onLoad()              │
├──────────────────────┬──────────────────────────────────┤
│  PersonDocument      │  IssuedInvoiceDocument           │
│  (base.persons)      │  (economy.docs, doc_type=inv_i)  │
├──────────────────────┼──────────────────────────────────┤
│  Dibi (SQL)          │  TableDefinition (metadata)      │
└──────────────────────┴──────────────────────────────────┘
```

### Klíčové třídy

| Třída | Umístění | Účel |
|-------|----------|------|
| `TableGateway` | `src/Core/Document/TableGateway.php` | Vstupní bod pro práci s tabulkou — CRUD operace, orchestrace dokumentového lifecycle |
| `Document` | `src/Core/Document/Document.php` | Abstraktní třída s hooky. Pracuje s daty jako `array` |
| `DefaultDocument` | `src/Core/Document/DefaultDocument.php` | Výchozí implementace bez business logiky (pro tabulky bez vlastní třídy) |
| `DocumentRegistry` | `src/Core/Document/DocumentRegistry.php` | Registr mapování tabulka/typ → PHP třída. Načítá se z JSONC konfigurace |
| `ValidationResult` | `src/Core/Document/ValidationResult.php` | Strukturovaná chybová zpráva z validace |

---

## 3. Třída `Document`

### Abstraktní třída

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

abstract class Document
{
    protected array $data = [];
    protected array $originalData = [];
    protected ?TableGateway $tableGateway = null;

    /**
     * Validace dat před uložením.
     * Vrací ValidationResult — pokud obsahuje chyby, uložení se neprovede.
     */
    public function validate(array &$data): ValidationResult
    {
        return new ValidationResult();
    }

    /**
     * Transformace dat před uložením.
     * Volá se PO úspěšné validaci. Může modifikovat data (dopočítat pole atd.).
     */
    public function beforeSave(array &$data): void
    {
    }

    /**
     * Akce po uložení dokumentu.
     * Data již obsahují ID (u nového záznamu).
     */
    public function afterSave(array $data): void
    {
    }

    /**
     * Akce před smazáním dokumentu.
     */
    public function beforeDelete(array $data): void
    {
    }

    /**
     * Akce po smazání dokumentu.
     */
    public function afterDelete(array $data): void
    {
    }

    /**
     * Akce po načtení dokumentu z DB.
     * Může doplnit computed/virtuální pole.
     */
    public function onLoad(array &$data): void
    {
    }
}
```

### Pravidla

- Všechny hooky mají výchozí prázdnou implementaci — přetěžuje se jen to, co je potřeba
- Data jsou vždy PHP `array` — žádné property per sloupec (kvůli extensions)
- `validate()` a `beforeSave()` dostávají data referencí (`&$data`) — mohou modifikovat
- `validate()` se volá PŘED `beforeSave()` — pokud validace selže, `beforeSave` se nevolá
- `afterSave()` a `onLoad()` dostávají data bez reference (jen čtení) / s referencí (může doplnit)

---

## 4. Třída `ValidationResult`

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationResult
{
    /** @var ValidationError[] */
    private array $errors = [];

    public function addError(string $column, string $message, string $code = ''): self
    {
        $this->errors[] = new ValidationError($column, $message, $code);
        return $this;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /** @return ValidationError[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        return array_map(fn(ValidationError $e) => $e->toArray(), $this->errors);
    }
}
```

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

class ValidationError
{
    /** Konvenční `column` pro chyby bez vazby na konkrétní pole. */
    public const FIELD_FORM = '_form';

    public function __construct(
        public readonly string $column,
        public readonly string $message,
        public readonly string $code = '',
    ) {}

    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
```

Příklad použití v UI — API vrátí:
```json
{
    "success": false,
    "errors": [
        {"column": "customer_id", "message": "Odběratel je povinný", "code": "required"},
        {"column": "rows.0.unit_price", "message": "Cena musí být kladná", "code": "positive"}
    ]
}
```

Pole `column` umožňuje UI nastavit focus na konkrétní pole. Pro chyby v řádcích se použije tečková notace `rows.{index}.{column}`.

Význam hodnot `column` (kontrakt s frontendem, detailně viz `docs/edit-forms.md` sekce 8):

- **Konkrétní sloupec** → chyba se zobrazí vedle pole ve formuláři (+ tabová tečka + banner s labelem).
- **`_form`** (konstanta `ValidationError::FIELD_FORM`) → chyba bez vazby na pole; vykreslí se jen v top-level banneru formuláře.
- **Cokoli jiného** (např. `rows`, neznámý sloupec) → frontend fallbackne na form-level (banner). Doporučená cesta pro nové form-level validace je explicitně používat `FIELD_FORM`:

```php
$result->addError(ValidationError::FIELD_FORM, 'Není nastavena vlastní firma…', 'no_own_company');
```

---

## 5. Třída `TableGateway`

Vstupní bod pro všechny operace s tabulkou.

### Základní metody

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

class TableGateway
{
    public function __construct(
        private string $tableId,           // ID tabulky (economy_docs_heads)
        private DataSourceConnection $db,
        private DocumentRegistry $registry,
        private TableDefinition $definition,
    ) {}

    /**
     * Načte jeden záznam podle ID. Vrací surová data z DB.
     */
    public function loadRecord(int $id): ?array
    {
        // SELECT * FROM table WHERE id = $id
        // → return row as array, or null
    }

    /**
     * Načte dokument — záznam + child záznamy + zavolá onLoad hook.
     */
    public function loadDocument(int $id): ?array
    {
        // 1. Načíst hlavní záznam
        // 2. Pro každou childTable načíst podřízené záznamy
        // 3. Najít Document třídu (z registry)
        // 4. Zavolat document->onLoad($data)
        // 5. Vrátit data včetně child záznamů
    }

    /**
     * Uloží dokument (insert nebo update).
     * Vrací uložená data (s ID, dopočítanými poli) nebo chybu.
     */
    public function saveDocument(array $inputData): DocumentResult
    {
        // 1. Najít Document třídu (z registry, podle typu pokud existuje)
        // 2. Zavolat document->validate($data)
        //    → pokud chyby, vrátit DocumentResult s chybami
        // 3. Zavolat document->beforeSave($data)
        // 4. BEGIN TRANSACTION
        // 5. INSERT/UPDATE hlavní záznam
        // 6. Pro každou childTable: sync řádky (insert/update/delete)
        // 7. COMMIT
        // 8. Zavolat document->afterSave($data)
        // 9. Vrátit DocumentResult s uloženými daty
    }

    /**
     * Smaže dokument (hlavní záznam + child záznamy).
     */
    public function deleteDocument(int $id): DocumentResult
    {
        // 1. Načíst stávající data
        // 2. Najít Document třídu
        // 3. Zavolat document->beforeDelete($data)
        // 4. BEGIN TRANSACTION
        // 5. Smazat child záznamy
        // 6. Smazat hlavní záznam
        // 7. COMMIT
        // 8. Zavolat document->afterDelete($data)
    }
}
```

### `DocumentResult`

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

class DocumentResult
{
    public function __construct(
        private bool $success,
        private ?array $data = null,
        private ?ValidationResult $validation = null,
        private ?string $errorMessage = null,
    ) {}

    public static function ok(array $data): self { /* ... */ }
    public static function validationFailed(ValidationResult $validation): self { /* ... */ }
    public static function error(string $message): self { /* ... */ }

    public function isSuccess(): bool { return $this->success; }
    public function getData(): ?array { return $this->data; }
    public function getValidation(): ?ValidationResult { return $this->validation; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
}
```

---

## 6. Child tabulky (hlavička + řádky)

### Definice v JSONC

V definici tabulky se přidá nepovinné pole `childTables`:

```jsonc
{
    "tableId": 201,
    "name": "Document heads",
    "name:cs": "Hlavičky dokladů",

    // Podřízené tabulky
    "childTables": [
        {
            "table": "economy_docs_rows",       // ID child tabulky
            "foreignKey": "head_id",             // FK sloupec v child tabulce
            "dataKey": "rows"                    // klíč v API datech
        }
    ],

    "columns": [ /* ... */ ]
}
```

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `childTables[].table` | string | Ano | ID podřízené tabulky |
| `childTables[].foreignKey` | string | Ano | Název FK sloupce v child tabulce (odkazuje na `id` hlavičky) |
| `childTables[].dataKey` | string | Ano | Klíč, pod kterým se child záznamy předávají v API datech |

### Chování při ukládání

```
saveDocument({
    doc_type: "inv_issued",
    customer_id: 42,
    rows: [                          ← dataKey = "rows"
        {item: "A", qty: 1},        ← row bez "id" = INSERT
        {id: 5, item: "B", qty: 2}, ← row s "id" = UPDATE
    ]
})
```

1. **Nový řádek** (bez `id`) → `INSERT INTO economy_docs_rows (..., head_id) VALUES (..., $headId)`
2. **Existující řádek** (s `id`) → `UPDATE economy_docs_rows SET ... WHERE id = $id AND head_id = $headId`
3. **Smazaný řádek** (existuje v DB, ale chybí ve vstupních datech) → `DELETE FROM economy_docs_rows WHERE id = $id AND head_id = $headId`

Při smazání dokumentu se nejprve smažou všechny child záznamy, pak hlavička.

### Důležité — kdy gateway na child sety sáhá

`TableGateway` synchronizuje pouze ty child sety, které jsou **přítomny** v `$data` v okamžiku po `Document::beforeSave`. Pokud klíč v `$data` chybí, gateway se nedotkne existujících řádků v DB — nesmazá je, neupdatuje, nic.

To má zcela praktický důvod: v UI flow se hlavička dokumentu často ukládá **bez řádků** (řádky jsou spravované přes vlastní sub-form endpoint). Kdyby gateway na chybějící `rows` reagoval jako na prázdný seznam, všechny řádky v DB by se při každém save hlavičky vyhladily.

Důsledek pro Document classes:

- Pokud `beforeSave` chce **nahradit** child set v DB (např. server-side computed agregát — v dokladech `vatRecap`), zapíše ho do `$data` pod příslušný `dataKey`. Gateway pak provede full sync (insert nových, update existujících podle `id`, delete zbylých).
- Pokud `beforeSave` potřebuje child rows **jen pro výpočty**, načti je do **lokální proměnné**, nikdy zpět do `$data`. Gateway by je jinak synchronizoval, což u client-managed setů (jako jsou řádky dokladů) způsobí tichý data loss.

Vzor: `Shipard\Module\Docs\Core\DocDocument::resolveRowsForCompute()` — helper, který buď vezme `rows` z payloadu, když jsou tam, nebo si je načte z DB do lokální proměnné. Jeho výstup nikdy neputuje zpět do `$data['rows']`.

Pokud naopak chceš zajistit, aby změna v child setu triggerovala recompute na hlavičce (např. doplnit řádek do faktury → přepočítat totals + DPH rekapitulaci), použij `Document::afterSave` na child entitě. Z FK sloupce vyčteš `id` parenta a vyvoláš jeho recompute. Vzor je `DocRowsDocument::afterSave()`.

Při smazání dokumentu se nejprve smažou všechny child záznamy, pak hlavička.

### Transakce

Celá operace (hlavička + všechny child tabulky) probíhá v jedné DB transakci. Pokud cokoliv selže, provede se ROLLBACK.

---

## 7. Registrace Document tříd — JSONC konfigurace

### Jedna třída pro celou tabulku

V `module.jsonc` se přidá pole `documentClasses`:

```jsonc
{
    "id": "base.persons",
    "name": "Persons",
    "name:cs": "Osoby",

    "tables": ["base_persons_persons"],

    // Registrace Document tříd
    "documentClasses": [
        {
            "table": "base_persons_persons",
            "class": "Shipard\\Module\\Base\\Persons\\PersonDocument"
        }
    ]
}
```

### Více tříd podle typu dokumentu

```jsonc
{
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",

    "tables": ["economy_docs_heads", "economy_docs_rows"],

    "documentClasses": [
        {
            "table": "economy_docs_heads",
            "typeColumn": "doc_type",
            "classes": {
                "inv_issued": "Shipard\\Module\\Economy\\Docs\\IssuedInvoiceDocument",
                "inv_received": "Shipard\\Module\\Economy\\Docs\\ReceivedInvoiceDocument",
                "order_issued": "Shipard\\Module\\Economy\\Docs\\IssuedOrderDocument"
            },
            "defaultClass": "Shipard\\Module\\Economy\\Docs\\GenericDocDocument"
        }
    ]
}
```

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `table` | string | Ano | ID tabulky |
| `class` | string | Podmíněně | PHP třída — pro tabulky s jednou Document třídou |
| `typeColumn` | string | Podmíněně | Sloupec určující typ dokumentu — pro tabulky s více třídami |
| `classes` | object | Podmíněně | Mapování hodnota typeColumn → PHP třída |
| `defaultClass` | string | Ne | Výchozí třída, pokud hodnota typeColumn nemá mapování |

Pokud tabulka nemá žádnou registraci v `documentClasses`, použije se `DefaultDocument` (prázdné hooky).

---

## 8. Třída `DocumentRegistry`

Načítá mapování z kompilované konfigurace a poskytuje správnou Document třídu.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Document;

class DocumentRegistry
{
    /**
     * Vrátí Document instanci pro danou tabulku a data.
     *
     * Logika:
     * 1. Najdi registraci pro $tableId
     * 2. Pokud registrace má typeColumn:
     *    a. Přečti hodnotu typeColumn z $data
     *    b. Najdi třídu v classes mapě
     *    c. Pokud nenalezena → defaultClass → DefaultDocument
     * 3. Pokud registrace má class → použij ji
     * 4. Pokud žádná registrace → DefaultDocument
     */
    public function getDocument(string $tableId, array $data = []): Document
    {
        // ...
    }
}
```

---

## 9. Umístění PHP tříd modulů

Document třídy žijí v adresáři modulu, ale v PHP namespace:

```
modules/base/persons/
├── module.jsonc
├── tables/
│   └── base_persons_persons.jsonc
└── src/
    └── PersonDocument.php          ← Shipard\Module\Base\Persons\PersonDocument

modules/economy/docs/
├── module.jsonc
├── tables/
│   ├── economy_docs_heads.jsonc
│   └── economy_docs_rows.jsonc
└── src/
    ├── IssuedInvoiceDocument.php   ← Shipard\Module\Economy\Docs\IssuedInvoiceDocument
    ├── ReceivedInvoiceDocument.php
    └── GenericDocDocument.php
```

### PSR-4 autoloading

V `composer.json` se přidá mapování pro moduly:

```json
{
    "autoload": {
        "psr-4": {
            "Shipard\\": "src/",
            "Shipard\\Module\\": "modules/"
        }
    }
}
```

Namespace konvence: `Shipard\Module\{Skupina}\{Modul}\` → `modules/{skupina}/{modul}/src/`

---

## 10. PHP Enum pro enumInt sloupce

Pro sloupce typu `enumInt` se v PHP používají nativní backed enum typy. Každý enum je samostatný soubor v `src/` adresáři modulu.

### Konvence

- Soubor: `modules/{skupina}/{modul}/src/{EnumName}.php`
- Backed enum s `int` hodnotami odpovídajícími hodnotám v DB
- Hodnoty musí odpovídat klíčům v konfigurační položce (`cfgItem`)
- Hodnota `0` = neurčeno / nevalidní stav (pokud je to relevantní)

### Příklad — PersonType

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

/**
 * Typ osoby — backed enum s int hodnotami odpovídajícími DB sloupci person_type (enumInt).
 */
enum PersonType: int
{
    case Undefined = 0;
    case Person = 1;
    case Company = 2;
}
```

Použití:
```php
// Z DB hodnoty na enum
$type = PersonType::tryFrom((int) $data['person_type']); // null pro neznámou hodnotu
$type = PersonType::from((int) $data['person_type']);     // výjimka pro neznámou hodnotu

// Porovnání
if ($type === PersonType::Company) { ... }

// Do DB
$data['person_type'] = PersonType::Company->value; // 2

// Všechny hodnoty
$allTypes = PersonType::cases(); // [Undefined, Person, Company]
```

---

## 11. Příklad — PersonDocument

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class PersonDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        if ($personType === null || $personType === PersonType::Undefined) {
            $result->addError('person_type', 'Typ osoby je povinný', 'required');
            return $result;
        }

        if ($personType === PersonType::Company && empty($data['full_name'])) {
            $result->addError('full_name', 'Název firmy je povinný', 'required');
        }

        if ($personType === PersonType::Person) {
            if (empty($data['last_name'])) {
                $result->addError('last_name', 'Příjmení je povinné', 'required');
            }
            if (empty($data['first_name'])) {
                $result->addError('first_name', 'Jméno je povinné', 'required');
            }
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        if ($personType === PersonType::Company) {
            // Firma: firstName prázdné, lastName = fullName (pro řazení)
            $data['first_name'] = '';
            $data['last_name'] = $data['full_name'] ?? '';
        }

        if ($personType === PersonType::Person) {
            // Člověk: fullName = firstName + lastName
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')
            );
        }
    }
}
```

---

## 12. Příklad — IssuedInvoiceDocument

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Docs;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class IssuedInvoiceDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['customer_id'])) {
            $result->addError('customer_id', 'Odběratel je povinný', 'required');
        }

        if (empty($data['issue_date'])) {
            $result->addError('issue_date', 'Datum vystavení je povinné', 'required');
        }

        $rows = $data['rows'] ?? [];
        if (empty($rows)) {
            $result->addError('rows', 'Faktura musí mít alespoň jeden řádek', 'required');
        }

        foreach ($rows as $i => $row) {
            if (empty($row['unit_price']) || $row['unit_price'] <= 0) {
                $result->addError("rows.{$i}.unit_price", 'Cena musí být kladná', 'positive');
            }
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        $totalAmount = 0;
        $rows = $data['rows'] ?? [];

        foreach ($rows as $i => &$row) {
            // Dopočítat celkovou cenu řádku
            $qty = $row['quantity'] ?? 0;
            $price = $row['unit_price'] ?? 0;
            $row['total_price'] = round($qty * $price, 2);
            $totalAmount += $row['total_price'];
        }
        unset($row);

        $data['rows'] = $rows;

        // Celková částka hlavičky
        $data['total_amount'] = round($totalAmount, 2);

        // Dopočítat DPH (zjednodušeně)
        $vatRate = $data['vat_rate'] ?? 21;
        $data['vat_amount'] = round($totalAmount * $vatRate / 100, 2);
        $data['total_with_vat'] = round($totalAmount + $data['vat_amount'], 2);
    }
}
```

---

## 12b. Doklady, které Shipard generuje sám

Doklady, které nevznikají z ruky uživatele ani z externího zdroje, ale
Shipard je skládá z vlastních dat — periodická fakturace zakázek
(#110 Q1), později faktura ze zakázky, faktura ze zálohy apod. — vznikají
**i se přegenerovávají výhradně přes výměnný formát a `DocumentApplier`**
(`docs/exchange-format.md` §1, § Apply a doc state → `replaceConcept`).
Generátor sestaví kanonický `shpd.docs.document.v1` a applier udělá
zbytek: validaci, resolve, odvození DPH, řadu, zaokrouhlení, dimenze,
`TableGateway::saveDocument` s Document hooky. Žádné přímé skládání
hlavičky a řádků mimo applier — druhá implementace by se rozešla.

Známé výjimky z doby před touto zásadou (#112): zaúčtování podaného
přiznání DPH (`VatReturnAccountingService`) a účetní doklady majetku
(`AssetPostingDocuments`) jdou přes `TableGateway` přímo; sjednotí je
samostatný task.

## 13. Tok dat — saveDocument

```
API volání: saveDocument({customer_id: 42, rows: [...]})
│
├─ 1. DocumentRegistry.getDocument('economy_docs_heads', data)
│     → IssuedInvoiceDocument (podle doc_type)
│
├─ 1b. `created_by` (jen insert, viz níže „Kdo plní `created_by`“)
│
├─ 2. document.validate(data)
│     → ValidationResult
│
├─ 2b. zámek záznamu (documentLockProviders, sekce 16): providery tabulky
│      nad (data, originál) — každý důvod = chyba `_form` s kódem `locked`;
│      document.isLockExempt(data) (import mód) providery nevolá,
│      `_forceUnlock` (jen CLI) pustí a zaloguje warn
│     → pokud chyby → return DocumentResult::validationFailed(...)
│
├─ 3. document.beforeSave(data)
│     → modifikuje data (dopočítá total_amount, DPH atd.)
│
├─ 4. BEGIN TRANSACTION
│
├─ 4b. documentEventHandlers: beforeSave (cizí moduly, smí mutovat data;
│      výjimka = rollback) — viz docs/modules.md
│
├─ 5. INSERT/UPDATE economy_docs_heads
│     → $headId = lastInsertId nebo existující ID
│
├─ 6. Sync child tabulky:
│     ├─ economy_docs_rows:
│     │   ├─ rows bez "id" → INSERT (head_id = $headId)
│     │   ├─ rows s "id" → UPDATE
│     │   └─ rows v DB ale ne ve vstupu → DELETE
│
├─ 7. COMMIT
│
├─ 8. document.afterSave(data)
│
├─ 8b. documentEventHandlers: afterSave (každé uložení), pak stateChanged
│      (jen při změně docState; insert mimo Koncept = old 0) — výjimky
│      se logují a polykají. Přechod plní Document třída v beforeSave
│      sdíleným helperem Document::trackStateChange (volat jako první)
│
└─ 9. return DocumentResult::ok(data)
```

### Kdo plní `created_by`

Auditní sloupec `created_by` (→ `core_system_users`, `system: true`) plní
`TableGateway::saveDocument` — ne controller (#93 D10):

- jen při **insertu** a jen u tabulky, která sloupec má;
- zdroj je `CurrentUser::id()` — přihlášený uživatel, u API klíče uživatel
  klíče; ve strojovém kontextu (CLI, cron) zůstává NULL;
- **přítomný klíč se nepřepisuje**, ani když je `null` — applier, import
  nebo služba tím rozhodla sama (`RegistryImportService`, provisionery);
- update `created_by` nikdy nemění.

Gateway k tomu potřebuje definici tabulky (`tableDef` v konstruktoru) — bez
ní se vyplnění tiše přeskočí, továrny ji proto předávají. Zápisy mimo
gateway (`AttachmentService`, fronta odchozí pošty, generické CRUD) si
`created_by` řeší samy. Doklady `created_by` nemají — nesou `author`
(„Vystavil“, #93 D2), viz níže.

### Autor dokladu (`docs_core_heads.author`)

Uživatel, který doklad založil — tiskne se jako „Vystavil“ (`docs/prints.md`).
Na rozdíl od `created_by` není `system`: edituje se ve formuláři (tab
Nastavení, `docs/edit-forms.md` kap. 23). Výchozí hodnotu při **vzniku**
dokladu určuje `Shipard\Module\Docs\Core\DocAuthorResolver`, volaný
z `DocDocument::beforeSave` (#93 D1, D11):

1. klíč `author` v datech **je** (i `null`) → beze změny — rozhodl formulář,
   applier (`applyOptions.author`) nebo import;
2. doklad zakládá člověk — přihlášený **ne-systémový** uživatel → on;
3. strojový kontext → autor automaticky vystavených dokladů z číselné řady
   (`docs_core_number_series.auto_author`) → z nastavení `docs.autoAuthor`
   (Nastavení → Účetnictví → Doklady) → NULL.

Strojový kontext je „nikdo přihlášený“ (CLI, cron) **i systémový uživatel**
(`is_system`, typicky API klíč integrace) — jméno integrace jako „Vystavil“
na faktuře nikdo nechce. Neaktivní uživatel z řady ani z nastavení se
nepoužije (warning v logu) a pokračuje se dál v pořadí. Při updatu se autor
nemění, pokud ho nepošle formulář.

Formulář nového dokladu autora předvyplní (`applyNewRecordDefaults`), aby
pole ukázalo výchozí hodnotu; kdo ji vymaže, uloží doklad bez autora (bod 1).

---

## 13. Tok dat — loadDocument

```
API volání: loadDocument(42)
│
├─ 1. SELECT * FROM economy_docs_heads WHERE id = 42
│
├─ 2. Pro každou childTable:
│     SELECT * FROM economy_docs_rows WHERE head_id = 42
│     → data['rows'] = [...]
│
├─ 3. DocumentRegistry.getDocument('economy_docs_heads', data)
│     → IssuedInvoiceDocument
│
├─ 4. document.onLoad(data)
│     → může doplnit computed pole
│
└─ 5. return data
```

---

## 14. Implementační plán pro Claude Code

### Fáze 1 — Základní třídy

**Soubory:**
- `src/Core/Document/Document.php` — abstraktní třída s hooky
- `src/Core/Document/DefaultDocument.php` — výchozí prázdná implementace
- `src/Core/Document/ValidationResult.php` — výsledek validace
- `src/Core/Document/ValidationError.php` — jedna chyba
- `src/Core/Document/DocumentResult.php` — výsledek operace

**Testy:**
- ValidationResult: přidání chyb, isValid(), toArray()
- DocumentResult: ok, validationFailed, error

### Fáze 2 — DocumentRegistry

**Soubory:**
- `src/Core/Document/DocumentRegistry.php` — registr tříd

**Testy:**
- Jedna třída pro tabulku
- Více tříd podle typeColumn
- defaultClass fallback
- Tabulka bez registrace → DefaultDocument

### Fáze 3 — TableGateway

**Soubory:**
- `src/Core/Document/TableGateway.php` — CRUD operace s lifecycle hooky

**Testy:**
- loadRecord (jednoduchý SELECT)
- saveDocument — insert nového záznamu (bez child)
- saveDocument — update existujícího
- saveDocument — s child tabulkou (insert/update/delete řádků)
- saveDocument — validace selže → vrátí chyby
- deleteDocument — s child záznamy
- Transakce — rollback při chybě

### Fáze 4 — Příklady Document tříd

- `modules/base/persons/src/PersonDocument.php`
- Aktualizace `modules/base/persons/module.jsonc` s `documentClasses`

### Fáze 5 — PSR-4 autoloading pro moduly

- Aktualizace `composer.json` s namespace `Shipard\Module\`

---

## 15. Rozšíření JSONC definic

### `module.jsonc` — nové pole `documentClasses`

Viz sekce 7. Přidává se do `module.jsonc`.

### Definice tabulky — nové pole `childTables`

Viz sekce 6. Přidává se do definice tabulky (`.jsonc`).

Tyto změny je nutné promítnout do:
- `ModuleDefinition.php` — nové pole `documentClasses`
- `TableDefinition.php` — nové pole `childTables`

---

## 16. Zámek záznamu — `documentLockProviders` (#55 D24)

Obecný mechanismus „záznam je uzamčený": modul, který o zámku rozhoduje,
dodá **provider**, jádro ho vynucuje na všech zápisových cestách a posílá
důvody do UI. `docs.core` o DPH ani fiskálních měsících neví — providery
dodávají `economy.vat` (`VatPeriodLockProvider`, zamčená instance tvrzení),
`economy.codebooks` (`FiscalMonthLockProvider`, zamčený fiskální měsíc),
`economy.assets` (`AssetEventLockProvider` nad `economy_assets_events`:
pozdější potvrzený odpis okruhu + zamčený měsíc + zaúčtování,
`docs/assets.md` §5.3; `AssetPostingDocLockProvider` nad `docs_core_heads`:
doklad s řádky `asset.*` spravuje Majetek, §5.4) a `economy.workOrders`
(`WorkOrderRowLockProvider` nad `economy_work_orders_rows`: řádek předpisu
zakázky ve stavu s `readOnly` — V pořádku, Ukončeno, Zrušeno, Smazáno —
`docs/work-orders.md` §5.4).

### Rozhraní a registrace

```php
interface DocumentLockProvider
{
    /** @return list<DocumentLockReason> prázdné = volný */
    public function lockReasons(string $tableId, array $data, ?array $original): array;
}
```

- `$data` = nový stav záznamu (s injektovaným efektivním `docState`,
  child sety z payloadu), `$original` = uložený řádek s child sety, null
  u insertu. Provider rozhoduje sám, co je pro něj „obsah" (DPH provider
  kouká na rekapitulaci a ukazatele, měsíční na `fiscal_month`).
- `DocumentLockReason` (readonly VO): `source` (`vat_period` |
  `fiscal_month` | …), `title` (věta pro uživatele), `message`,
  `subjectTableId`/`subjectRowId` (zamykající entita), `params` (pro
  lokalizaci na klientu), `unlockAction` (rezervováno pro průvodce
  odemknutím). `AbstractDocumentLockProvider` dává settery `db`/`config`/
  `dsConfig` jako u handlerů.
- Registrace v `module.jsonc`: `documentLockProviders: [{table, class}]`.
  `DocumentLoader::load()` je sebere ze všech resolvovaných modulů a
  **připojí k `DocumentRegistry`** (`getLockProviders()`,
  `hasLockProviders(table)`) — každá `TableGateway` tak má stejnou sadu
  providerů bez zapojování na místech konstrukce (fail-closed by
  construction; 15 call sites gatewaye vs. 5 konstrukce registru).
- `DocumentLockRegistry::forDocuments($documents, $db, $config, $dsConfig)`
  staví instance providerů (lazy, jedna per třída) s DB a konfigurací
  volajícího; `reasons()` sjednotí důvody všech providerů tabulky
  v pořadí registrace, `describe($table, $row)` vrací UI kontrakt.

### Kde se zámek vynucuje

| Cesta | Chování |
|---|---|
| `TableGateway::saveDocument` | po `validate()`, před `beforeSave()`: každý důvod = `addError('_form', title, 'locked')` → 422 `VALIDATION_ERROR`. Výjimka providera = zápis selže (`DocumentResult::error`) — nikdy tiché povolení |
| `TableGateway::deleteDocument` | důvody nad uloženým řádkem → `DocumentResult::domainError(…, 'DOCUMENT_LOCKED')` před `beforeDelete` |
| přechody stavů | tabulky se `stateTransitionsRunDocumentHooks` jdou přes `saveDocument` → totéž; `DocStateTransitionFilter` zamčenému záznamu vrátí **prázdnou** nabídku přechodů |
| `CrudController` update/patch/delete | generické REST píše mimo gateway (přímý UPDATE/DELETE) → vlastní guard: důvody nad (řádek ⊕ patch, řádek) → 422 `DOCUMENT_LOCKED` s `details` |
| `AccountingController::reaccount` | zamčený doklad → 422 `DOCUMENT_LOCKED`; deník je derivát dokladu |

Import mód (`Document::isLockExempt($data)`, `DocDocument` → payload
s `_importNumber`) providery **nevolá** — import zrcadlí cizí systém,
needituje ho (D26). Virtuální klíč `_forceUnlock: true` zámek pustí a
zaloguje `warn` (`document lock bypassed by force`); z HTTP nikdy nepřijde,
allow-listy `FormController`/`CrudController` neznámé klíče zahazují.
Gateway ho z dat strhne před SQL. Jediný CLI konzument je
`shpd-ds doc-reaccount --force` (ten ale přes gateway nejde — guard
obchází přímo a loguje sám).

**Výjimka jednoho provideru.** `isLockExempt` je vše-nebo-nic. Když má
služba obejít jen *svůj* zámek a ostatní providery pro ni mají platit dál,
rozhoduje provider sám podle markeru v `$data` — vzor
`AssetPostingDocLockProvider`: zápis služby majetku nese
`_systemOperations` (`DocRowOperationRules::SYSTEM_OPERATIONS_KEY`),
provider ho pustí, zámek fiskálního měsíce se uplatní normálně.
`DocDocument::beforeSave` marker před SQL odstraní; z HTTP přijít nemůže
(není to sloupec tabulky).

### UI kontrakt

`GET /_ui/form/{table}/meta/{id}` → `doc_states.lock = {locked, reasons:
[{source, title, message, params, subjectTableId, subjectRowId}]}` a
`read_only = true`, když je zamčeno; `transitions` prázdné.
`GET /_ui/viewer/{id}/detail/{recordId}` → `detail.lock` stejného tvaru a
toolbar bez akce `edit`. Klient (`DocumentLockBanner.svelte`, sdílí
FormEditor i ViewerDetail) lokalizuje důvody podle `source` + `params`
(`lock.source.*`, `lock.message.*` v `i18n`), neznámý zdroj spadne na text
ze serveru. Nový záznam do zamčeného období odmítne až save — meta zámek
nemá (žádný `id`).

### Zamykatelné entity

Jednotný tvar: `locked` (boolean) + `locked_at` (datetime, nullable,
system) + `locked_by` (int → `core_system_users`, nullable, system).
`LockStamp::apply()` v `beforeSave` zamykatelné tabulky vyplní čas a
uživatele z request-scoped `Core\Auth\CurrentUser` (nastavuje
`public/index.php` po autentizaci; CLI/import = null → pole zůstanou NULL,
UI ukáže „Uzamčeno (import)") a při odemknutí je vymaže. Dnes: instance
tvrzení DPH (`economy_vat_report_periods`), fiskální měsíc
(`economy_codebooks_fiscal_months`).

Testy: `tests/Unit/Core/Document/DocumentLockRegistryTest`,
`TableGatewayTest` (sekce zámek), `DocStateTransitionFilterTest`,
`LockStampTest`, `tests/Unit/Api/Controller/CrudControllerTest` (guard),
providery v `tests/Unit/Module/Economy/{Vat,Codebooks}`.
