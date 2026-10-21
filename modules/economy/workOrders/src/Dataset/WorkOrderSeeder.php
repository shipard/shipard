<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Dataset;

use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;

/**
 * Sekce `workOrders/` při seedu (#131 fáze 2b, D30, D31): soubor
 * `shpd.dataset.workOrder.v1` → payload `shpd.workOrders.workOrder.v1`
 * s id místo přirozených klíčů → `WorkOrderImportApplier` (hlavička
 * v Konceptu → řádky předpisu → cílový stav; hlavičku ani řádky seeder
 * nepíše mimo applier). Běží za osobami a položkami, před doklady —
 * doklady odkazují zakázku číslem (dimenze). Evidenci období zapisuje
 * až `WorkOrderPeriodsSeeder` za `docs/`.
 *
 * Překlad klíčů: řada zakázek kódem a středisko kódem přes
 * `SetupReferences`, zákazník jen identifikátory (`PartyResolver`,
 * nenalezený = chyba — osoba musí být v `persons/`), řada dokladů
 * `{docType, code}` (D37) a účet kódem, položka `ourCode`
 * (`ItemResolver`). Zakázka se stejným číslem = `skipped` (applier
 * nepřepisuje), odmítnutí applieru = chyba záznamu s cestou payloadu.
 */
class WorkOrderSeeder implements SectionSeeder
{
    public const SECTION = 'workOrders';

    private const HEAD_KEYS = [
        'number', 'sequenceNumber', 'title', 'state', 'dateStart', 'dateEnd', 'internalNote',
        'currency', 'paymentReference', 'parent',
    ];
    private const INVOICING_KEYS = ['periodicity', 'invoiceFrom', 'docText', 'docType', 'dueDays', 'timing', 'vatMode', 'paymentMethod'];
    private const ROW_KEYS = ['description', 'quantity', 'unit', 'unitPrice', 'vatCode', 'operation', 'validFrom', 'validTo'];

    public function __construct(
        private readonly WorkOrderImportApplier $applier,
        private readonly PartyResolver $party,
        private readonly ItemResolver $items,
    ) {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $references = new SetupReferences($ctx->db, $ctx->tables);
        foreach ($ctx->reader->listFiles(self::SECTION) as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
                $payload = $this->payload($file, $references);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }

            $result = $this->applier->apply($payload);
            if (!$result->success) {
                $detail = (string) ($result->errorMessage ?? $result->errorCode ?? 'import selhal');
                $issues = self::issueMessages($result->issues);
                $report->failed(self::SECTION, "{$rel}: {$detail}" . ($issues !== '' ? " ({$issues})" : ''));
                continue;
            }
            if ($result->status === WorkOrderImportApplier::STATUS_SKIPPED) {
                $report->skipped(self::SECTION, "{$rel}: zakázka {$result->number} už na DS je — import ji nemění");
                continue;
            }
            foreach ($result->warnings as $warning) {
                $report->warning("workOrders {$rel}: " . self::issueMessage($warning));
            }
            $report->ok(self::SECTION);
        }
    }

    /**
     * Payload importu z obálky sady — přirozené klíče → id.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     * @throws DatasetException
     */
    public function payload(array $file, SetupReferences $references): array
    {
        $wo = $file['workOrder'] ?? null;
        if (!is_array($wo)) {
            throw new DatasetException('chybí objekt workOrder');
        }
        $head = [];
        foreach (self::HEAD_KEYS as $key) {
            if (array_key_exists($key, $wo)) {
                $head[$key] = $wo[$key];
            }
        }
        $head['numberSeries'] = $references->resolve(WorkOrderDocument::SERIES_TABLE, $wo['numberSeries'] ?? null);
        if (($wo['costCenter'] ?? null) !== null) {
            $head['costCenter'] = $references->resolve('economy_codebooks_cost_centers', $wo['costCenter']);
        }
        if (is_array($wo['customer'] ?? null)) {
            $head['customer'] = $this->customerId($wo['customer']);
        }
        if (is_array($wo['invoicing'] ?? null)) {
            $invoicing = [];
            foreach (self::INVOICING_KEYS as $key) {
                if (array_key_exists($key, $wo['invoicing'])) {
                    $invoicing[$key] = $wo['invoicing'][$key];
                }
            }
            if (($wo['invoicing']['numberSeries'] ?? null) !== null) {
                $invoicing['numberSeries'] = $references->resolve(SetupReferences::DOC_SERIES_TABLE, $wo['invoicing']['numberSeries']);
            }
            if (($wo['invoicing']['bankAccount'] ?? null) !== null) {
                $invoicing['bankAccount'] = $references->resolve('economy_codebooks_bank_accounts', $wo['invoicing']['bankAccount']);
            }
            $head['invoicing'] = $invoicing;
        }

        $payload = [
            'format'    => WorkOrderImportApplier::FORMAT_ID . '.v' . WorkOrderImportApplier::FORMAT_VERSION,
            'workOrder' => $head,
        ];
        $rows = [];
        foreach ((array) ($file['rows'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                throw new DatasetException("rows[{$i}] není objekt");
            }
            $out = [];
            foreach (self::ROW_KEYS as $key) {
                if (array_key_exists($key, $row)) {
                    $out[$key] = $row[$key];
                }
            }
            if (($row['item'] ?? null) !== null) {
                $out['item'] = $this->itemId((string) $row['item'], (int) $i);
            }
            $rows[] = $out;
        }
        if ($rows !== []) {
            $payload['rows'] = $rows;
        }
        return $payload;
    }

    /** @param array<string, mixed> $party */
    private function customerId(array $party): int
    {
        $result = $this->party->resolve($party, null, true);
        if ($result->status !== ResolveStatus::Matched || $result->matchedId === null) {
            $ids = implode(', ', array_filter([
                isset($party['companyId']) ? 'IČO ' . $party['companyId'] : null,
                isset($party['vatId']) ? 'VAT ' . $party['vatId'] : null,
            ]));
            throw new DatasetException(
                "zákazník '" . (string) ($party['name'] ?? '?') . "'" . ($ids !== '' ? " ({$ids})" : '')
                . ' v adresáři není — osoba musí být v persons/',
            );
        }
        return $result->matchedId;
    }

    private function itemId(string $code, int $index): int
    {
        $result = $this->items->resolve(['ourCode' => $code], null, true);
        if ($result->status !== ResolveStatus::Matched || $result->matchedId === null) {
            throw new DatasetException("rows[{$index}]: položka '{$code}' v číselníku není — musí být v items/");
        }
        return $result->matchedId;
    }

    /** @param list<mixed> $issues */
    private static function issueMessages(array $issues): string
    {
        $out = [];
        foreach ($issues as $issue) {
            $text = self::issueMessage($issue);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return implode('; ', $out);
    }

    private static function issueMessage(mixed $issue): string
    {
        if (!is_array($issue)) {
            return (string) $issue;
        }
        return trim((string) (($issue['path'] ?? '') . ' ' . ($issue['message'] ?? $issue['code'] ?? '')));
    }
}
