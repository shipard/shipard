<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunFactory;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunOptions;

/**
 * HTTP obal akcí periodické fakturace v detailu zakázky (D24, tasks §4):
 *
 *   POST /_work-orders/invoice-run          {workOrder}  Vystavit dlužná období (bez pojistky dohánění)
 *   POST /_work-orders/periods/regenerate   {period}     Přegenerovat koncept období (replaceConcept)
 *   POST /_work-orders/periods/restore      {period}     Obnovit zastavené období
 *
 * Logika je v InvoicingRunService; sem patří jen vstup, výstup a mapování
 * \DomainException (kód výjimky = HTTP status) na chybové odpovědi.
 */
class WorkOrdersInvoicingController
{
    /**
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
        private readonly ?DocumentRegistry $documents = null,
        private readonly array $tables = [],
        private readonly ?DocumentEventDispatcher $dispatcher = null,
    ) {
    }

    public function issueDue(Request $request): Response
    {
        $workOrderId = self::id($request->getBody(), 'workOrder');
        if ($workOrderId === null) {
            return Response::error('BAD_REQUEST', 'Body must contain workOrder id', 400);
        }
        try {
            $report = $this->service()->run(RunOptions::today(workOrderId: $workOrderId, force: true));
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\RuntimeException $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
        return Response::success($report->toArray());
    }

    public function regenerate(Request $request): Response
    {
        $periodId = self::id($request->getBody(), 'period');
        if ($periodId === null) {
            return Response::error('BAD_REQUEST', 'Body must contain period id', 400);
        }
        try {
            $line = $this->service()->regenerate($periodId, RunOptions::today());
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\RuntimeException $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
        return Response::success($line->toArray());
    }

    public function restore(Request $request): Response
    {
        $periodId = self::id($request->getBody(), 'period');
        if ($periodId === null) {
            return Response::error('BAD_REQUEST', 'Body must contain period id', 400);
        }
        try {
            $line = $this->service()->restore($periodId, RunOptions::today());
        } catch (\DomainException $e) {
            return $this->domainError($e);
        } catch (\RuntimeException $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
        return Response::success($line->toArray());
    }

    protected function service(): InvoicingRunService
    {
        if ($this->documents === null) {
            throw new \RuntimeException('Document registry is not available');
        }
        return InvoicingRunFactory::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $this->documents,
            $this->tables,
            $this->dispatcher,
        );
    }

    private function domainError(\DomainException $e): Response
    {
        $status = $e->getCode();
        $status = in_array($status, [404, 409, 422], true) ? $status : 409;
        $code = match ($status) {
            404     => 'NOT_FOUND',
            422     => 'PERIOD_NOT_BUILDABLE',
            default => 'PERIOD_STATE_CONFLICT',
        };
        return Response::error($code, $e->getMessage(), $status);
    }

    /** @param mixed $body */
    private static function id(mixed $body, string $key): ?int
    {
        if (!is_array($body)) {
            return null;
        }
        $value = $body[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        return is_int($value) && $value > 0 ? $value : null;
    }
}
