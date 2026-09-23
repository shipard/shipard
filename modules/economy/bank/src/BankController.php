<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\StatementImportService;

/**
 * REST controller bankovního modulu.
 *
 * POST /_bank/import-statement (multipart/form-data) — nahrání souboru výpisu,
 * import přes StatementImportService, vrácení souhrnu. Volitelné pole
 * `account` (kód / id) override detekce vlastního účtu. Zdrojový soubor se
 * uloží jako příloha výpisu.
 *
 * POST /_bank/reaccount, body {"transactionId": N} — přeúčtuje transakci ve
 * stavu 40 (po opravě rozvrhu / pohybu). Vrací {accountingState, messages}.
 */
final class BankController
{
    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly string $dsPath,
        private readonly array $tables,
        private readonly DataSourceConfig $dsConfig,
        private readonly DocumentRegistry $registry,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
        private readonly ?JournalEventDispatcher $journalEvents = null,
        private readonly ?OpenItemLookup $openItems = null,
        private readonly ?JournalContributorSet $journalContributors = null,
    ) {
    }

    public function importStatement(AuthContext $auth): Response
    {
        if ($this->config === null) {
            return Response::error('INTERNAL_ERROR', 'Konfigurace není k dispozici', 500);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $message, 400);
        }

        $file = $_FILES['file'];
        $tmpPath = (string) $file['tmp_name'];
        $originalName = (string) ($file['name'] ?? 'statement');

        $raw = file_get_contents($tmpPath);
        if ($raw === false || $raw === '') {
            return Response::error('UPLOAD_ERROR', 'Nahraný soubor je prázdný nebo nečitelný.', 400);
        }

        $account = isset($_POST['account']) && trim((string) $_POST['account']) !== ''
            ? (string) $_POST['account']
            : null;
        $userId = $auth->isAuthenticated ? $auth->userId : null;

        $attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $service = StatementImportService::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $this->registry,
            $this->tables,
            $this->eventDispatcher,
            $attachments,
        );

        try {
            $summary = $service->import($raw, $account, $tmpPath, $originalName, $userId);
        } catch (ImportException $e) {
            return Response::error('IMPORT_ERROR', $e->getMessage(), 422);
        }

        return Response::success($summary);
    }

    public function reaccount(Request $request): Response
    {
        $body = $request->getBody();
        $txId = is_array($body) ? (int) ($body['transactionId'] ?? 0) : 0;
        if ($txId <= 0) {
            return Response::error('BAD_REQUEST', 'Body must contain a positive transactionId', 400);
        }

        $tx = $this->db->fetchRow(
            'SELECT id, docState FROM economy_bank_transactions WHERE id = %i',
            $txId,
        );
        if ($tx === null) {
            return Response::error('NOT_FOUND', "Transaction {$txId} not found", 404);
        }
        if ((int) $tx['docState'] !== 40) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Only transactions in state 40 (Accounted) can be re-accounted',
                422,
            );
        }

        $engine = new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            $this->config,
            $this->journalEvents,
            $this->openItems,
            $this->journalContributors,
        );
        $result = $engine->accountTransaction($txId);

        return Response::success([
            'accountingState' => $result['state'],
            'messages'        => $result['messages'],
        ]);
    }
}
