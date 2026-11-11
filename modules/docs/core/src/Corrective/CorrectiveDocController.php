<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Corrective;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;

/**
 * HTTP obal akce Vystavit opravný doklad (#92 D6, task B U8):
 *
 *   POST /_docs/corrective   {docId}   → {docId: <nový koncept>}
 *
 * Logika je v {@see CorrectiveDocService}; sem patří vstup, výstup
 * a mapování {@see CorrectiveDocException} (kód = HTTP status, `errorCode`
 * = kód chyby, `details` = hlášky applieru). Read-only režim zdroje dat
 * vrací 403 už ReadOnlyPolicy (fail-closed), oprávnění = přihlášený
 * uživatel jako u založení dokladu formulářem.
 */
class CorrectiveDocController
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

    public function create(Request $request): Response
    {
        $docId = self::id($request->getBody(), 'docId');
        if ($docId === null) {
            return Response::error('BAD_REQUEST', 'Body must contain docId', 400);
        }
        try {
            $newId = $this->service()->createFor($docId);
        } catch (CorrectiveDocException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->getCode(), $e->details);
        } catch (\RuntimeException $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
        return Response::success(['docId' => $newId]);
    }

    protected function service(): CorrectiveDocService
    {
        if ($this->documents === null) {
            throw new \RuntimeException('Document registry is not available');
        }
        return CorrectiveDocService::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $this->documents,
            $this->tables,
            $this->dispatcher,
        );
    }

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
