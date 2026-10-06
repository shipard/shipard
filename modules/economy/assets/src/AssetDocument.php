<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\World\Assets\TaxRulesRegistry;

/**
 * Karta majetku (economy_assets_assets, docs/assets.md D19–D23, D30, D38).
 *
 * Pravidla:
 *   - dlouhodobý druh (příznak longTerm) → povinná účetní skupina, cena
 *     prázdná (cena dlouhodobého majetku vzniká z pohybů, D13);
 *   - odepisovaný druh → odpisové nastavení dle pravidel země
 *     (`DepreciationSettingsValidator`); po prvním potvrzeném daňovém
 *     odpisu je daňová metoda i pravidlo neměnné (`taxMethodLocked`),
 *     účetní metodu změnit lze; neodepisovaný druh nastavení vyprázdní;
 *   - u dlouhodobého druhu jsou datum pořízení a vyřazení jen ke čtení —
 *     plní je potvrzené události (D38), hodnota z payloadu se ignoruje;
 *     do archivu jde karta jen s potvrzeným vyřazením a vyřazená karta
 *     se nevrací do V pořádku; druh karty s potvrzenými událostmi se
 *     nemění a karta se nesmaže;
 *   - odepisovaný druh jde potvrdit jen s úplnou účetní skupinou — účet
 *     odpisů i oprávek (D57, `accountingGroupIncomplete`); koncept se
 *     uložit smí;
 *   - cizí majetek → povinný vlastník; bez příznaku se vlastník vyprázdní;
 *   - datum vyřazení ≥ datum pořízení;
 *   - přechod do 70 (V archívu = vyřazeno) vyžaduje datum vyřazení;
 *   - inventární číslo unikátní přes všechny stavy (odpovídá unikátnímu
 *     indexu — smazaná karta číslo drží, jinak by DB vrátila SQL chybu
 *     místo srozumitelné validace);
 *   - importní mód (marker `_import`, applier fáze 6): dlouhodobá karta
 *     bez účetní skupiny se smí uložit jako koncept (D79), lock providery
 *     se nevolají; marker do SQL nejde.
 *
 * Přidělení inventárního čísla (D22) při přechodu do 40 běží
 * v afterPersist(): gateway otevírá transakci až po beforeSave(), takže
 * teprve tady drží `SELECT … FOR UPDATE` nad kartami s prefixem zámek
 * až do commitu a dva souběžně potvrzované koncepty nedostanou stejné
 * číslo. Endpoint přechodu stavu záznam po uložení znovu načte, UI
 * číslo vidí. Ručně zadané ani importované číslo se nepřepisuje.
 */
class AssetDocument extends Document
{
    public const TABLE = 'economy_assets_assets';

    /** Marker importního módu v payloadu (applier fáze 6); do SQL nejde. */
    public const IMPORT_KEY = '_import';

    /** Stavy archivní sady (core.system.docStatesArchive) a jejich mainState. */
    public const STATE_CONFIRMED = 40;
    public const STATE_ARCHIVED = 70;
    public const STATE_EDIT = 80;
    public const STATE_DELETED = 90;
    public const MAIN_EDIT = 2;
    public const MAIN_ARCHIVED = 4;

    /** Importní mód: marker `_import` v payloadu (applier fáze 6). */
    public function isLockExempt(array $data): bool
    {
        return !empty($data[self::IMPORT_KEY]);
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $import = $this->isLockExempt($data);
        $categories = $this->categories();
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadCardRow($id) : null;
        $events = $id !== null ? $this->loadConfirmedEventKinds($id) : [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $category = (string) ($data['category'] ?? '');
        $longTerm = $category !== '' && $categories->isLongTerm($category);
        if ($longTerm) {
            // D38: data dlouhodobého majetku plní události, payload se ignoruje.
            $data['acquired_date'] = $original !== null ? self::isoDate($original['acquired_date'] ?? null) : null;
            $data['disposed_date'] = $original !== null ? self::isoDate($original['disposed_date'] ?? null) : null;
        }

        if ($category === '') {
            $result->addError('category', 'Druh majetku je povinný', 'required');
        } elseif ($categories->isUnknown($category)) {
            $result->addError('category', 'Neznámý druh majetku', 'invalid');
        } elseif ($longTerm) {
            // D79: import ukládá dlouhodobou kartu bez skupiny jako koncept.
            if (empty($data['accounting_group']) && !($import && (int) ($data['docState'] ?? 10) === 10)) {
                $result->addError(
                    'accounting_group',
                    'Dlouhodobý majetek musí mít účetní skupinu.',
                    'required',
                );
            }
            if (self::hasValue($data['price'] ?? null)) {
                $result->addError(
                    'price',
                    'Cena dlouhodobého majetku vzniká ze zařazení a technického zhodnocení, na kartě se nezadává.',
                    'not_allowed',
                );
            }
        }

        if ($events !== [] && $original !== null && (string) ($original['category'] ?? '') !== $category) {
            $result->addError('category', 'Druh karty s potvrzenými událostmi nelze změnit.', 'categoryLockedByEvents');
        }

        if ($category !== '' && $categories->isDepreciable($category)) {
            $validator = new DepreciationSettingsValidator($this->rules(), $categories);
            foreach ($validator->problems($data, self::isoDate($data['acquired_date'] ?? null)) as $problem) {
                $result->addError($problem['column'], $problem['message'], $problem['code']);
            }
            if ($original !== null && $this->hasTaxDepreciation($events)) {
                foreach (['tax_method', 'tax_rule'] as $col) {
                    if ((string) ($data[$col] ?? '') !== (string) ($original[$col] ?? '')) {
                        $result->addError(
                            $col,
                            'Po prvním potvrzeném daňovém odpisu nelze daňovou metodu ani skupinu měnit.',
                            'taxMethodLocked',
                        );
                    }
                }
            }
        }

        // D57: bez účtu odpisů a oprávek by zaúčtování kartu vyřadilo až
        // v náhledu období — odmítne se už potvrzení karty.
        if ($category !== ''
            && $categories->isDepreciable($category)
            && !empty($data['accounting_group'])
            && (int) ($data['docState'] ?? 10) === self::STATE_CONFIRMED
        ) {
            $group = $this->loadAccountingGroup((int) $data['accounting_group']);
            if ($group !== null
                && (empty($group['account_depreciation']) || empty($group['account_accumulated']))
            ) {
                $result->addError(
                    'accounting_group',
                    'Účetní skupina nemá účet odpisů a oprávek — pro odepisovaný majetek je doplň, nebo zvol jinou skupinu.',
                    'accountingGroupIncomplete',
                );
            }
        }

        if (!empty($data['is_foreign']) && empty($data['owner'])) {
            $result->addError('owner', 'Cizí majetek musí mít vlastníka.', 'required');
        }

        $acquired = (string) ($data['acquired_date'] ?? '');
        $disposed = (string) ($data['disposed_date'] ?? '');
        if ($acquired !== '' && $disposed !== '' && $disposed < $acquired) {
            $result->addError(
                'disposed_date',
                'Datum vyřazení nesmí být dříve než datum pořízení.',
                'invalid_range',
            );
        }

        $newState = (int) ($data['docState'] ?? 10);
        if ($newState === self::STATE_ARCHIVED && $disposed === '') {
            $result->addError(
                ValidationError::FIELD_FORM,
                $longTerm
                    ? 'Dlouhodobý majetek se vyřazuje akcí Vyřadit na kartě — vyřazení kartu přesune do archivu samo.'
                    : 'Vyřazení majetku vyžaduje datum vyřazení — vyplň ho v Opravit a teprve pak ukonči platnost.',
                'disposedDateRequired',
            );
        }
        if ($longTerm && $newState === self::STATE_CONFIRMED && $disposed !== '') {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Majetek je vyřazen — vrať ho do archivu, nebo zruš událost vyřazení.',
                'disposedAssetActive',
            );
        }
        if ($newState === self::STATE_DELETED && $events !== []) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Kartu s potvrzenými událostmi nelze smazat — nejdřív zruš události.',
                'hasConfirmedEvents',
            );
        }

        $number = trim((string) ($data['asset_number'] ?? ''));
        if ($number !== '') {
            if (mb_strlen($number) > AssetNumberAllocator::MAX_LENGTH) {
                $result->addError(
                    'asset_number',
                    'Inventární číslo smí mít nejvýše ' . AssetNumberAllocator::MAX_LENGTH . ' znaků.',
                    'max_length',
                );
            } elseif ($this->db !== null) {
                $ownerId = $this->findAssetNumberOwner($number, isset($data['id']) ? (int) $data['id'] : null);
                if ($ownerId !== null) {
                    $result->addError(
                        'asset_number',
                        "Inventární číslo {$number} už má jiná karta.",
                        'duplicate',
                    );
                }
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->trackStateChange($data, $originalData);
        unset($data[self::IMPORT_KEY]);

        foreach (['asset_number', 'name', 'short_name'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }

        if (array_key_exists('is_foreign', $data) && empty($data['is_foreign'])) {
            $data['owner'] = null;
        }

        if (array_key_exists('price', $data) && !self::hasValue($data['price'])) {
            $data['price'] = null;
        }

        $categories = $this->categories();
        $category = (string) ($data['category'] ?? $originalData['category'] ?? '');
        if ($category !== '' && $categories->isLongTerm($category)) {
            // D38: data pořízení a vyřazení drží události; při změně druhu
            // na dlouhodobý se ruční hodnoty drobného majetku zahodí.
            $wasLongTerm = $originalData !== null
                && $categories->isLongTerm((string) ($originalData['category'] ?? ''));
            $data['acquired_date'] = $wasLongTerm ? self::isoDate($originalData['acquired_date'] ?? null) : null;
            $data['disposed_date'] = $wasLongTerm ? self::isoDate($originalData['disposed_date'] ?? null) : null;
        }

        if ($category !== '' && !$categories->isDepreciable($category)) {
            foreach (['tax_method', 'tax_rule', 'acc_method', 'acc_months'] as $col) {
                $data[$col] = null;
            }
        } elseif ($category !== '') {
            $validator = new DepreciationSettingsValidator($this->rules(), $categories);
            foreach (['tax_method', 'tax_rule', 'acc_method'] as $col) {
                if (array_key_exists($col, $data) && !self::hasValue($data[$col])) {
                    $data[$col] = null;
                }
            }
            $taxMethod = $data['tax_method'] ?? $originalData['tax_method'] ?? null;
            if (!$validator->usesRule(is_string($taxMethod) ? $taxMethod : null)) {
                $data['tax_rule'] = null;
            }
            $accMethod = $data['acc_method'] ?? $originalData['acc_method'] ?? null;
            $data['acc_months'] = $accMethod === 'time' && self::hasValue($data['acc_months'] ?? null)
                ? (int) $data['acc_months']
                : null;
        }
    }

    public function afterPersist(array $data): void
    {
        $t = $this->stateTransition;
        if ($t === null || $t['new'] !== self::STATE_CONFIRMED) {
            return;
        }
        if (self::hasValue($data['asset_number'] ?? null) || empty($data['id']) || $this->db === null) {
            return;
        }

        $category = (string) ($data['category'] ?? '');
        $prefix = $this->resolveNumberPrefix($category);
        $number = AssetNumberAllocator::next($prefix, $this->lockNumbersWithPrefix($prefix));

        $this->writeAssetNumber((int) $data['id'], $number);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Přechod docState (vzor DocDocument::trackStateChange). Nový záznam
     * vzniklý rovnou mimo Koncept (import, exchange) je taky přechod
     * s old = 0, aby dostal inventární číslo.
     */
    protected function trackStateChange(array $data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);
        if ($newState !== $oldState) {
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
        }
    }

    /**
     * Prefix inventárního čísla: settings klíč per druh, fallback prefix
     * z cfgItem druhu, fallback DEFAULT_PREFIX.
     */
    protected function resolveNumberPrefix(string $category): string
    {
        $fromSettings = $this->settings?->get(AssetCategories::PREFIX_SETTING . $category);
        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return trim($fromSettings);
        }
        return $this->categories()->defaultNumberPrefix($category);
    }

    protected function categories(): AssetCategories
    {
        return new AssetCategories($this->config);
    }

    protected function rules(): \Shipard\Module\World\Assets\TaxDepreciationRules
    {
        return TaxRulesRegistry::forCountry($this->config, $this->dsConfig?->getCountry() ?? 'cz');
    }

    /** @param list<array{event_kind: string, scope: string}> $events */
    private function hasTaxDepreciation(array $events): bool
    {
        foreach ($events as $event) {
            if ($event['event_kind'] === AssetEvent::KIND_DEPRECIATION && $event['scope'] === AssetEvent::SCOPE_TAX) {
                return true;
            }
        }
        return false;
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }

    /** Uložený řádek karty (bez události), null = nová karta. */
    protected function loadCardRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Účty odpisů a oprávek účetní skupiny, null = skupina neexistuje.
     *
     * @return array{account_depreciation: mixed, account_accumulated: mixed}|null
     */
    protected function loadAccountingGroup(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [account_depreciation], [account_accumulated] FROM [economy_assets_accounting_groups] WHERE [id] = %i',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Druh a okruh potvrzených událostí karty.
     *
     * @return list<array{event_kind: string, scope: string}>
     */
    protected function loadConfirmedEventKinds(int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [event_kind], [scope] FROM [' . AssetEventDocument::TABLE . '] WHERE [asset] = %i AND [docState] = %i',
            $assetId,
            AssetEventDocument::STATE_CONFIRMED,
        ) as $row) {
            $out[] = ['event_kind' => (string) $row['event_kind'], 'scope' => (string) $row['scope']];
        }
        return $out;
    }

    /** Id jiné karty s tímto číslem (libovolný stav), null = volné. */
    protected function findAssetNumberOwner(string $number, ?int $excludeId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . self::TABLE . '] WHERE [asset_number] = %s AND [id] <> %i LIMIT 1',
            $number,
            $excludeId ?? 0,
        );
        return $row === null || $row === false ? null : (int) $row['id'];
    }

    /**
     * Inventární čísla karet s daným prefixem, zamčená do konce transakce
     * (běží uvnitř save transakce gateway — viz doc-comment třídy).
     *
     * @return list<string>
     */
    protected function lockNumbersWithPrefix(string $prefix): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [asset_number] FROM [' . self::TABLE . '] WHERE [asset_number] LIKE %like~ FOR UPDATE',
            $prefix,
        );
        $numbers = [];
        foreach ($rows as $row) {
            $numbers[] = (string) $row['asset_number'];
        }
        return $numbers;
    }

    protected function writeAssetNumber(int $id, string $number): void
    {
        $this->db?->query(
            'UPDATE [' . self::TABLE . '] SET [asset_number] = %s WHERE [id] = %i',
            $number,
            $id,
        );
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false;
    }
}
