<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\JournalEventDispatcher;

/**
 * REST endpointy účetnictví (`/_accounting/*`).
 *
 * POST /_accounting/reaccount, body {"docId": N} — přeúčtuje doklad ve
 * stavu 40 (po opravě rozvrhu / položky). Vrací {accountingState,
 * messages}. Zamčený doklad (documentLockProviders, #55 D27) odmítne
 * 422 DOCUMENT_LOCKED — deník je derivát dokladu a přes zámek se sám
 * nemění; vědomé obejití má jen CLI `doc-reaccount --force`.
 */
final class AccountingController
{
    private const DOC_STATE_OK = 40;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?JournalEventDispatcher $journalEvents = null,
        private readonly ?DocumentRegistry $documents = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?JournalContributorSet $journalContributors = null,
    ) {}

    public function reaccount(Request $request): Response
    {
        $body = $request->getBody();
        $docId = is_array($body) ? (int) ($body['docId'] ?? 0) : 0;
        if ($docId <= 0) {
            return Response::error('BAD_REQUEST', 'Body must contain a positive docId', 400);
        }

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        if ($head === null) {
            return Response::error('NOT_FOUND', "Document {$docId} not found", 404);
        }
        if ((int) $head['docState'] !== self::DOC_STATE_OK) {
            return Response::error(
                'INVALID_DOC_STATE',
                'Only documents in state 40 (OK) can be re-accounted',
                422,
            );
        }

        $reasons = $this->lockReasons((array) $head);
        if ($reasons !== []) {
            return Response::error(
                DocumentLockRegistry::DOMAIN_CODE,
                DocumentLockRegistry::summarize($reasons),
                422,
                array_map(static fn(DocumentLockReason $r): array => $r->toArray(), $reasons),
            );
        }

        $engine = new AccountingEngine(
            $this->db->getDibiConnection(), $this->config, $this->journalEvents, $this->journalContributors,
        );
        $result = $engine->accountDocument($docId);

        return Response::success([
            'accountingState' => $result['state'],
            'messages'        => $result['messages'],
        ]);
    }

    /**
     * @param array<string, mixed> $head
     * @return list<DocumentLockReason>
     */
    private function lockReasons(array $head): array
    {
        if ($this->documents === null || !$this->documents->hasLockProviders('docs_core_heads')) {
            return [];
        }
        return DocumentLockRegistry::forDocuments(
            $this->documents,
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
        )->reasons('docs_core_heads', $head, $head);
    }
}
