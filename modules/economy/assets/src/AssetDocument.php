<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Karta majetku (economy_assets_assets, docs/assets.md D19–D23).
 *
 * Pravidla:
 *   - dlouhodobý druh (příznak longTerm) → povinná účetní skupina, cena
 *     prázdná (cena dlouhodobého majetku vzniká z pohybů, D13);
 *   - cizí majetek → povinný vlastník; bez příznaku se vlastník vyprázdní;
 *   - datum vyřazení ≥ datum pořízení;
 *   - přechod do 70 (V archívu = vyřazeno) vyžaduje datum vyřazení;
 *   - inventární číslo unikátní přes všechny stavy (odpovídá unikátnímu
 *     indexu — smazaná karta číslo drží, jinak by DB vrátila SQL chybu
 *     místo srozumitelné validace).
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

    public const STATE_CONFIRMED = 40;
    public const STATE_ARCHIVED = 70;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $categories = $this->categories();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $category = (string) ($data['category'] ?? '');
        if ($category === '') {
            $result->addError('category', 'Druh majetku je povinný', 'required');
        } elseif ($categories->isUnknown($category)) {
            $result->addError('category', 'Neznámý druh majetku', 'invalid');
        } elseif ($categories->isLongTerm($category)) {
            if (empty($data['accounting_group'])) {
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

        if ((int) ($data['docState'] ?? 10) === self::STATE_ARCHIVED && $disposed === '') {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Vyřazení majetku vyžaduje datum vyřazení — vyplň ho v Opravit a teprve pak ukonči platnost.',
                'disposedDateRequired',
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
