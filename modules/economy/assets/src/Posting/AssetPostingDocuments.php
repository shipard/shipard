<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Docs\Core\DocRowOperationRules;

/**
 * Účetní doklad majetku přes Document lifecycle (docs/assets.md D51, D53):
 * založení rovnou ve stavu V pořádku a storno při zrušení zaúčtování.
 *
 * Gateway je `TransactionlessTableGateway` — transakci vlastní
 * {@see AssetPostingService}. Deník generuje standardní handler účtování
 * (enginy zapisují přes `NestedTransaction`, vnější transakci nekončí).
 * Marker `_systemOperations` povolí řádky `asset.*` a pustí zápis přes
 * zámek „spravuje Majetek“; zámek fiskálního měsíce a ostatní providery
 * platí dál — odmítnutí se vrátí jako {@see AssetPostingException}.
 */
class AssetPostingDocuments
{
    public const HEADS_TABLE = 'docs_core_heads';

    public const STATE_OK = 40;
    public const STATE_CANCELLED = 30;

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?DataSourceConfig $dsConfig,
        private readonly ?DocumentRegistry $documents,
        private readonly array $tables,
        private readonly ?DocumentEventDispatcher $dispatcher,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->documents !== null && isset($this->tables[self::HEADS_TABLE]);
    }

    /**
     * Založí doklad ve stavu V pořádku a ověří, že se zaúčtoval bez chyby.
     *
     * @param array<string, mixed> $head hlavička s `rows`
     * @return array{id: int, number: string}
     * @throws AssetPostingException uložení nebo účtování selhalo
     */
    public function create(array $head): array
    {
        $head['docState'] = self::STATE_OK;
        $head[DocRowOperationRules::SYSTEM_OPERATIONS_KEY] = true;

        $result = $this->gateway()->saveDocument($head);
        if (!$result->isSuccess()) {
            throw $this->saveFailure('Účetní doklad majetku se nepodařilo uložit.', $result);
        }
        $docId = (int) ($result->getData()['id'] ?? 0);

        $row = $this->db->fetch(
            'SELECT [doc_number], [accounting_state], [accounting_messages] FROM [' . self::HEADS_TABLE . '] WHERE [id] = %i',
            $docId,
        );
        // Bez dispatcheru (test, degradovaný běh) deník nevzniká a není co ověřit.
        if ($this->dispatcher !== null && (int) ($row['accounting_state'] ?? 0) !== 1) {
            throw new AssetPostingException(
                'accounting_failed',
                'Účetní doklad majetku se nepodařilo zaúčtovat.',
                self::accountingDetails($row['accounting_messages'] ?? null),
            );
        }

        return ['id' => $docId, 'number' => (string) ($row['doc_number'] ?? '')];
    }

    /**
     * Storno dokladu (zrušení zaúčtování období).
     *
     * @throws AssetPostingException přechod selhal (zamčený měsíc, …)
     */
    public function cancel(int $docId): void
    {
        // Celý uložený doklad jako přechod stavu z formuláře — DocDocument
        // částečný payload nevaliduje.
        $gateway = $this->gateway();
        $document = $gateway->loadDocument($docId);
        if ($document === null) {
            throw new AssetPostingException('document_not_found', "Účetní doklad #{$docId} neexistuje.");
        }
        $document['docState'] = self::STATE_CANCELLED;
        $document[DocRowOperationRules::SYSTEM_OPERATIONS_KEY] = true;

        $result = $gateway->saveDocument($document);
        if (!$result->isSuccess()) {
            throw $this->saveFailure('Účetní doklad majetku se nepodařilo stornovat.', $result);
        }
    }

    private function gateway(): TableGateway
    {
        $def = $this->tables[self::HEADS_TABLE] ?? null;
        if ($this->documents === null || $def === null) {
            throw new AssetPostingException(
                'documents_unavailable',
                'Chybí definice tabulky dokladů — spusťte ds-upgrade.',
            );
        }
        return new TransactionlessTableGateway(
            self::HEADS_TABLE,
            $this->db,
            $this->documents,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->dispatcher,
            $def->docStates,
            $def,
        );
    }

    private function saveFailure(string $message, DocumentResult $result): AssetPostingException
    {
        $details = [];
        $validation = $result->getValidation();
        if ($validation !== null) {
            foreach ($validation->getErrors() as $error) {
                $details[] = ['code' => (string) ($error->code ?: 'invalid'), 'message' => $error->message];
            }
        } elseif ($result->getErrorMessage() !== null) {
            $details[] = ['code' => $result->getDomainErrorCode() ?: 'error', 'message' => $result->getErrorMessage()];
        }
        if ($details !== []) {
            $message .= ' ' . implode(' ', array_column($details, 'message'));
        }
        return new AssetPostingException('document_save_failed', $message, $details);
    }

    /** @return list<array{code: string, message: string}> */
    private static function accountingDetails(mixed $raw): array
    {
        $messages = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $details = [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (is_array($message) && ($message['level'] ?? 'error') === 'error') {
                $details[] = ['code' => (string) ($message['code'] ?? 'error'), 'message' => (string) ($message['message'] ?? '')];
            }
        }
        return $details;
    }
}
