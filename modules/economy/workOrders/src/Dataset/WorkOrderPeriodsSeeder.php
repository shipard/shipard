<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Dataset;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\WorkOrders\Invoicing\ContentHash;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;

/**
 * Evidence vyfakturovaných období (`periods[]` v `workOrders/*.jsonc`,
 * #131 fáze 2b, D30, D31) — druhý krok seedu zakázek, až za `docs/`:
 * období odkazuje doklad číslem a doklad musí už existovat. Sekce bez
 * vlastních souborů a `counts` (jako `fiscalYears`); jednotka počítání =
 * soubor zakázky s obdobími.
 *
 * Zápis jen přes `PeriodRepository` (`ensurePlanned` + `markIssued`),
 * `content_hash` z uloženého dokladu (`ContentHash::ofStoredDocument`)
 * stejně jako běh po apply — shoda otisku pak znamená „koncept nikdo
 * ručně neupravil“. Doklad se hledá mezi vydanými doklady
 * (`invno` / `invpo`) mimo koš; koncept zástupným číslem `!` + id.
 */
class WorkOrderPeriodsSeeder implements SectionSeeder
{
    public const SECTION = 'workOrderPeriods';

    /** Typy dokladů, které periodická fakturace vystavuje. */
    public const DOC_TYPES = ['invno', 'invpo'];

    private const DELETED = 90;

    public function __construct(private readonly ?PeriodRepository $periods = null)
    {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $repository = $this->periods ?? new PeriodRepository($ctx->db);
        $now = $this->now();
        foreach ($ctx->reader->listFiles(WorkOrderSeeder::SECTION) as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }
            $periods = is_array($file['periods'] ?? null) ? array_values($file['periods']) : [];
            if ($periods === []) {
                continue;
            }
            $number = trim((string) ($file['workOrder']['number'] ?? ''));
            $workOrderId = $number !== '' ? $this->workOrderId($ctx->db, $number) : null;
            if ($workOrderId === null) {
                $report->failed(self::SECTION, "{$rel}: zakázka '{$number}' na DS není — evidence období se nezapsala");
                continue;
            }

            $errors = 0;
            foreach ($periods as $i => $period) {
                try {
                    $this->writePeriod($ctx->db, $repository, $workOrderId, $period, $now);
                } catch (\Throwable $e) {
                    $report->warning("workOrderPeriods {$rel} periods[{$i}]: {$e->getMessage()}");
                    $errors++;
                }
            }
            if ($errors > 0) {
                $report->failed(self::SECTION, "{$rel}: {$errors} období se nezapsalo (viz varování)");
            } else {
                $report->ok(self::SECTION);
            }
        }
    }

    private function writePeriod(Connection $db, PeriodRepository $repository, int $workOrderId, mixed $period, string $now): void
    {
        if (!is_array($period)) {
            throw new DatasetException('období není objekt');
        }
        $from = trim((string) ($period['from'] ?? ''));
        $to = trim((string) ($period['to'] ?? ''));
        $docNumber = trim((string) ($period['docNumber'] ?? ''));
        if ($from === '' || $to === '') {
            throw new DatasetException('období bez from / to');
        }
        if ($docNumber === '') {
            throw new DatasetException("období {$from}: chybí docNumber");
        }
        $docId = $this->documentId($db, $docNumber);
        if ($docId === null) {
            throw new DatasetException("období {$from}: doklad {$docNumber} mezi vydanými doklady není");
        }

        $repository->ensurePlanned($workOrderId, [new Period($from, $to)], $now);
        $row = $db->fetch(
            'SELECT [id] FROM [' . PeriodRepository::TABLE . '] WHERE [work_order] = %i AND [period_from] = %d',
            $workOrderId, $from,
        );
        $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
        if ($row === null) {
            throw new DatasetException("období {$from}: řádek evidence nevznikl");
        }
        $repository->markIssued((int) $row['id'], $docId, ContentHash::ofStoredDocument($db, $docId), $now);
    }

    private function workOrderId(Connection $db, string $number): ?int
    {
        $row = $db->fetch(
            'SELECT [id] FROM [' . WorkOrderDocument::TABLE . '] WHERE [number] = %s AND [docState] <> %i ORDER BY [id] LIMIT 1',
            $number, self::DELETED,
        );
        $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
        return $row === null ? null : (int) $row['id'];
    }

    private function documentId(Connection $db, string $docNumber): ?int
    {
        $row = $db->fetch(
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] = %s AND [doc_type] IN %in AND [docState] <> %i ORDER BY [id] LIMIT 1',
            $docNumber, self::DOC_TYPES, self::DELETED,
        );
        $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
        return $row === null ? null : (int) $row['id'];
    }

    /** Razítko `created_at` / `updated_at` (přepsatelné v testech). */
    protected function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
