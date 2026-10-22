<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Document\ValidationError;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Economy\Assets\AssetCategories;
use Shipard\Module\Economy\Assets\AssetDocument;

/**
 * Sekce `assets/` při seedu (#131 fáze 2c, D38, D40) — první krok: karta
 * majetku přes `AssetDocument` stejně jako uživatel, **bez importního
 * módu** (`_import` by dal událostem původ `import`, D38). Běží za
 * zakázkami, před doklady — řádky pořízení odkazují kartu inventárním
 * číslem (dimenze `asset`). Události zapisuje až `AssetEventsSeeder`
 * za doklady.
 *
 * Překlad klíčů: typ názvem a účetní skupina kódem přes `SetupReferences`,
 * vlastník jen identifikátory (`PartyResolver`, nenalezený = chyba —
 * osoba musí být v `persons/`). Inventární číslo se převezme beze změny
 * (`AssetDocument` nepřepisuje zadané číslo). Dlouhodobá karta se ukládá
 * ve stavu V pořádku — do archivu ji přesune až potvrzené vyřazení
 * (D38); drobný majetek dostane cílový stav přímo. Karta se stejným
 * číslem (libovolný stav) = `skipped`, odmítnutí dokumentu = chyba
 * záznamu s cestou do obálky.
 */
class AssetSeeder implements SectionSeeder
{
    public const SECTION = 'assets';
    public const FORMAT = 'shpd.dataset.asset.v1';

    /** Stav obálky → `docState` karty. */
    public const STATES = [
        'draft'     => 10,
        'confirmed' => AssetDocument::STATE_CONFIRMED,
        'archived'  => AssetDocument::STATE_ARCHIVED,
    ];

    /** Klíč obálky → sloupec karty (reference a stav zvlášť). */
    public const HEAD_COLUMNS = [
        'assetNumber'  => 'asset_number',
        'name'         => 'name',
        'shortName'    => 'short_name',
        'note'         => 'note',
        'category'     => 'category',
        'tracking'     => 'tracking',
        'acquiredDate' => 'acquired_date',
        'disposedDate' => 'disposed_date',
        'price'        => 'price',
        'taxMethod'    => 'tax_method',
        'taxRule'      => 'tax_rule',
        'accMethod'    => 'acc_method',
        'accMonths'    => 'acc_months',
    ];

    /** Sloupec karty → klíč obálky pro cesty v chybách (reference a stav navíc k HEAD_COLUMNS). */
    private const HEAD_PATHS = [
        'asset_type'       => 'type',
        'accounting_group' => 'accountingGroup',
        'owner'            => 'owner',
        'is_foreign'       => 'foreign',
        'docState'         => 'state',
    ];

    public function __construct(private readonly PartyResolver $party)
    {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $references = new SetupReferences($ctx->db, $ctx->tables);
        $categories = new AssetCategories($ctx->config);
        foreach ($ctx->reader->listFiles(self::SECTION) as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
                $head = $this->headRow($file, $references, $categories);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }

            $number = (string) $head['asset_number'];
            if ($this->findCardId($ctx->db, $number) !== null) {
                $report->skipped(self::SECTION, "{$rel}: karta {$number} už na DS je — seed ji nemění");
                continue;
            }

            try {
                $result = $this->saveCard($ctx, $head);
            } catch (\Throwable $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }
            if (!$result->isSuccess()) {
                $report->failed(self::SECTION, "{$rel}: " . self::describe($result));
                continue;
            }
            $report->ok(self::SECTION);
        }
    }

    /**
     * Řádek karty z obálky — přirozené klíče → id, stav → `docState`.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     * @throws DatasetException
     */
    public function headRow(array $file, SetupReferences $references, AssetCategories $categories): array
    {
        $asset = $file['asset'] ?? null;
        if (!is_array($asset)) {
            throw new DatasetException('chybí objekt asset');
        }
        $number = trim((string) ($asset['assetNumber'] ?? ''));
        if ($number === '') {
            throw new DatasetException('asset.assetNumber: inventární číslo je povinné');
        }
        $state = (string) ($asset['state'] ?? '');
        if (!isset(self::STATES[$state])) {
            throw new DatasetException("asset.state: neznámý stav '{$state}'");
        }
        $category = (string) ($asset['category'] ?? '');
        $longTerm = $categories->isLongTerm($category);

        $head = [];
        foreach (self::HEAD_COLUMNS as $key => $column) {
            if (array_key_exists($key, $asset)) {
                $head[$column] = $asset[$key];
            }
        }
        $head['asset_number'] = $number;
        $head['is_foreign'] = !empty($asset['foreign']) ? 1 : 0;
        if ($longTerm) {
            // Data pořízení a vyřazení i cenu dosadí události (D13, D38).
            $head['acquired_date'] = null;
            $head['disposed_date'] = null;
            $head['price'] = null;
        }
        $head['asset_type'] = ($asset['type'] ?? null) !== null
            ? $references->resolve('economy_assets_types', $asset['type'])
            : null;
        $head['accounting_group'] = ($asset['accountingGroup'] ?? null) !== null
            ? $references->resolve('economy_assets_accounting_groups', $asset['accountingGroup'])
            : null;
        $head['owner'] = is_array($asset['owner'] ?? null) ? $this->ownerId($asset['owner']) : null;
        // Dlouhodobou kartu do archivu přesune až potvrzené vyřazení (D38).
        $head['docState'] = $longTerm && $state === 'archived' ? AssetDocument::STATE_CONFIRMED : self::STATES[$state];
        return $head;
    }

    /** @param array<string, mixed> $party */
    private function ownerId(array $party): int
    {
        $result = $this->party->resolve($party, null, true);
        if ($result->status !== ResolveStatus::Matched || $result->matchedId === null) {
            $ids = implode(', ', array_filter([
                isset($party['companyId']) ? 'IČO ' . $party['companyId'] : null,
                isset($party['vatId']) ? 'VAT ' . $party['vatId'] : null,
            ]));
            throw new DatasetException(
                "asset.owner: vlastník '" . (string) ($party['name'] ?? '?') . "'" . ($ids !== '' ? " ({$ids})" : '')
                . ' v adresáři není — osoba musí být v persons/',
            );
        }
        return $result->matchedId;
    }

    /**
     * Chyby dokumentu jako jedna věta s cestou do obálky.
     *
     * @param array<string, string> $columns klíč obálky → sloupec
     * @param array<string, string> $paths sloupce mimo `$columns` → klíč obálky
     */
    public static function describe(
        DocumentResult $result,
        string $prefix = 'asset',
        array $columns = self::HEAD_COLUMNS,
        array $paths = self::HEAD_PATHS,
    ): string {
        $validation = $result->getValidation();
        if ($validation === null) {
            return (string) ($result->getErrorMessage() ?? $result->getDomainErrorCode() ?? 'uložení selhalo');
        }
        $keys = array_flip($columns) + $paths;
        $parts = [];
        foreach ($validation->getErrors() as $error) {
            $column = $error->column;
            $path = $column === ValidationError::FIELD_FORM ? $prefix : $prefix . '.' . ($keys[$column] ?? $column);
            $parts[] = "{$path}: {$error->message}";
        }
        return $parts !== [] ? implode('; ', $parts) : 'validace selhala';
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** Id karty s inventárním číslem v libovolném stavu (unikátní index platí přes všechny stavy). */
    protected function findCardId(Connection $db, string $number): ?int
    {
        $row = $db->fetch('SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [asset_number] = %s', $number);
        $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Karta přes `TableGateway` (vlastní transakce, Document hooky, žádný
     * importní marker).
     *
     * @param array<string, mixed> $head
     */
    protected function saveCard(SeedContext $ctx, array $head): DocumentResult
    {
        $def = $ctx->tables[AssetDocument::TABLE] ?? null;
        if ($def === null) {
            throw new DatasetException('tabulka ' . AssetDocument::TABLE . ' na tomto zdroji dat není (modul neaktivní)');
        }
        $gateway = new TableGateway(
            AssetDocument::TABLE, $ctx->db, $ctx->registry, $def->childTables, $ctx->config, $ctx->dsConfig,
            docStates: $def->docStates, tableDef: $def,
        );
        return $gateway->saveDocument($head);
    }
}
