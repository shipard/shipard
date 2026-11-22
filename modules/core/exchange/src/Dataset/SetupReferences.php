<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Dibi\Connection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Export\SetupExporter;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;

/**
 * Cizí klíč v sadě přes přirozený klíč cílové tabulky (#131 fáze 2b, D29,
 * D37) — fasáda datových sad nad obecným {@see ReferenceResolver} (#166
 * D8). Říká, kam FK sloupec sady umí ukázat a jak se hodnota překládá tam
 * (dump) a zpět (seed):
 *
 *  - tabulka sekce `setup/` (`SetupExporter::TABLES`) s přirozeným klíčem
 *    v definici (`exchangeKey`) → hodnota klíče (druh zakázky názvem,
 *    bankovní účet kódem, středisko kódem, účet osnovy číslem, …);
 *  - `docs_core_number_series` → objekt `{docType, code}` (D37): výchozí
 *    řady z provisioneru kód nemají, samotný kód by je nedohledal;
 *  - ostatní reference sada nepřenáší — ani na tabulku, která přirozený
 *    klíč má (osoby, položky, doklady): sekce setup je nenese, odkaz by
 *    na cílovém zdroji dat neměl kam ukázat.
 *
 * Seed hledá přísně: nenalezený nebo nejednoznačný klíč je
 * `DatasetException` — nikdy tiché vynechání ani první nalezený. Záznam
 * v koši (`docState` 90) se nepočítá, archivovaný ano. Výjimka: účet osnovy
 * v sekci setup zůstává jako dřív (chybějící účet = null, hlídá ho až
 * engine) — řeší volající.
 */
final class SetupReferences
{
    public const ACCOUNTS_TABLE = 'economy_accounting_accounts';
    public const DOC_SERIES_TABLE = 'docs_core_number_series';

    private const DELETED = 90;

    private readonly ReferenceResolver $resolver;

    /** @var array<string, array<int, mixed>> tabulka → id → hodnota pro sadu */
    private array $exportCache = [];

    /** @var array<string, array<string, int>> tabulka → klíč → id */
    private array $resolveCache = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS —
     *        přirozený klíč cílové tabulky a to, zda má `docState`
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $tables = [],
    ) {
        $this->resolver = new ReferenceResolver($db, $tables);
    }

    /** Umí sada řádek tabulky odkázat přirozeným klíčem? */
    public function isPortable(string $table): bool
    {
        return $table === self::DOC_SERIES_TABLE || $this->keyColumn($table) !== null;
    }

    /**
     * Přirozený klíč (`exchangeKey`) tabulky, kterou nese sekce setup;
     * null = tabulka klíč nemá, není aktivní, nebo ji sada nenese.
     */
    public function keyColumn(string $table): ?string
    {
        foreach (SetupExporter::TABLES as $key => $spec) {
            if ($key !== SetupExporter::SETTINGS_TABLE && $spec['table'] === $table) {
                return $this->resolver->keyColumn($table);
            }
        }
        return null;
    }

    /**
     * Hodnota FK pro soubor sady: přirozený klíč cílového řádku, u řady
     * dokladů objekt `{docType, code}` bez prázdného kódu. Null = prázdná
     * reference, neexistující řádek nebo nepřenosná tabulka.
     */
    public function export(string $table, mixed $id): mixed
    {
        $id = ValueNormalizer::int($id);
        if ($id === null || $id <= 0 || !$this->isPortable($table)) {
            return null;
        }
        if (array_key_exists($id, $this->exportCache[$table] ?? [])) {
            return $this->exportCache[$table][$id];
        }

        if ($table === self::DOC_SERIES_TABLE) {
            $row = $this->db->fetch('SELECT [doc_type], [doc_number_code] FROM [docs_core_number_series] WHERE [id] = %i', $id);
            $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
            $value = $row === null ? null : ValueNormalizer::prune([
                'docType' => ValueNormalizer::str($row['doc_type'] ?? null),
                'code'    => ValueNormalizer::str($row['doc_number_code'] ?? null),
            ]);
        } else {
            $value = ValueNormalizer::str($this->resolver->keyOf($table, $id));
        }

        return $this->exportCache[$table][$id] = $value;
    }

    /**
     * Id cílového řádku podle hodnoty ze sady.
     *
     * @throws DatasetException nepřenosná tabulka, špatný tvar hodnoty,
     *         nenalezený nebo nejednoznačný klíč
     */
    public function resolve(string $table, mixed $value): int
    {
        if (!$this->isPortable($table)) {
            throw new DatasetException("tabulka {$table}: reference se přirozeným klíčem nepřenáší");
        }
        $cacheKey = is_array($value) ? json_encode($value) : (string) $value;
        if (isset($this->resolveCache[$table][$cacheKey])) {
            return $this->resolveCache[$table][$cacheKey];
        }

        if ($table === self::DOC_SERIES_TABLE) {
            $ids = $this->docSeriesIds($value);
            $label = 'řada dokladů ' . self::docSeriesLabel($value);
        } else {
            if (!is_scalar($value) || (string) $value === '') {
                throw new DatasetException("{$table}: klíč musí být neprázdný řetězec");
            }
            $label = "{$table} {$this->keyColumn($table)} '{$value}'";
            try {
                $ids = [$this->resolver->resolveByKey($table, (string) $value)];
            } catch (ReferenceException $e) {
                // Sady drží vlastní znění chyb — z obecné chyby se bere jen druh a id.
                $ids = match ($e->errorCode) {
                    ReferenceException::NOT_FOUND => [],
                    ReferenceException::AMBIGUOUS => $e->ids,
                    default => throw new DatasetException("{$label}: {$e->getMessage()}"),
                };
            }
        }

        if ($ids === []) {
            throw new DatasetException("{$label}: na tomto zdroji dat není");
        }
        if (count($ids) > 1) {
            throw new DatasetException("{$label}: není jednoznačná (" . count($ids) . ' záznamů: #' . implode(', #', $ids) . ')');
        }
        return $this->resolveCache[$table][$cacheKey] = $ids[0];
    }

    /**
     * D37: `{docType, code}` — s kódem přesná shoda (doc_type, doc_number_code),
     * bez kódu řada typu s prázdným kódem.
     *
     * @return list<int>
     */
    private function docSeriesIds(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['docType'] ?? null) || $value['docType'] === '') {
            throw new DatasetException('řada dokladů: hodnota musí být objekt {docType, code?}');
        }
        $code = $value['code'] ?? null;
        if ($code !== null && !is_string($code)) {
            throw new DatasetException('řada dokladů: code musí být řetězec nebo null');
        }
        $code = $code !== null ? trim($code) : '';
        $where = '[doc_type] = %s' . $this->notDeleted(self::DOC_SERIES_TABLE);
        if ($code !== '') {
            return $this->fetchIds(
                'SELECT [id] FROM [docs_core_number_series] WHERE ' . $where . ' AND [doc_number_code] = %s ORDER BY [id]',
                $value['docType'], $code,
            );
        }
        return $this->fetchIds(
            'SELECT [id] FROM [docs_core_number_series] WHERE ' . $where . " AND ([doc_number_code] IS NULL OR [doc_number_code] = '') ORDER BY [id]",
            $value['docType'],
        );
    }

    private static function docSeriesLabel(mixed $value): string
    {
        $docType = is_array($value) ? (string) ($value['docType'] ?? '?') : '?';
        $code = is_array($value) ? trim((string) ($value['code'] ?? '')) : '';
        return "typu '{$docType}'" . ($code !== '' ? " s kódem '{$code}'" : ' bez kódu');
    }

    private function notDeleted(string $table): string
    {
        $def = $this->tables[$table] ?? null;
        return $def === null || $def->docStates !== null ? ' AND [docState] <> ' . self::DELETED : '';
    }

    /** @return list<int> */
    private function fetchIds(string $sql, mixed ...$args): array
    {
        $out = [];
        foreach ($this->db->fetchAll($sql, ...$args) as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $out[] = (int) $row['id'];
        }
        return $out;
    }
}
