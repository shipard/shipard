<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;

/**
 * Pravidla odesílatelů + undo auto-archivu (Fáze 3 — šum):
 *
 *   POST /_mail/sender-rules/{id}/confirm  — návrh 10 → 40 (aktivní)
 *   POST /_mail/sender-rules/{id}/reject   — návrh 10 → 90 (koš)
 *   POST /_mail/auto-archive/undo          — body {date?: "YYYY-MM-DD"}
 *
 * Undo vrací všechny zprávy auto-archivované v daném dni (default dnešek,
 * povolen jen dnešek/včerejšek — starší přes viewer): docState 80 → 10,
 * `analysis_state` znovu do fronty (10; bez aktivního AI profilu 0 — zrcadlí
 * IncomingMessageDocument::resolveInitialAnalysisState), `auto_disposed_*`
 * NULL. Přechody jdou přes Document flow per záznam (TableGateway).
 *
 * Odpovědi: 404 NOT_FOUND, 409 INVALID_STATE, 422 VALIDATION_ERROR.
 */
class SenderRulesController
{
    private const RULES_TABLE = 'core_mail_sender_rules';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    /** docState pravidel (core.system.docStatesArchive). */
    private const RULE_STATE_DRAFT = 10;
    private const RULE_STATE_CONFIRMED = 40;
    private const RULE_STATE_TRASH = 90;

    /** docState zpráv (core.mail.docStatesIncoming). */
    private const MSG_STATE_NEW = 10;

    /** analysis_state (core.mail.analysisStates). */
    private const ANALYSIS_NONE = 0;
    private const ANALYSIS_QUEUED = 10;

    /**
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly array $tables,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
    ) {
    }

    public function confirmRule(AuthContext $auth, int $id): Response
    {
        return $this->transitionRule($auth, $id, self::RULE_STATE_CONFIRMED);
    }

    public function rejectRule(AuthContext $auth, int $id): Response
    {
        return $this->transitionRule($auth, $id, self::RULE_STATE_TRASH);
    }

    public function undoAutoArchive(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $date = is_array($body) && is_string($body['date'] ?? null)
            ? $body['date']
            : date('Y-m-d');

        $allowed = [date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))];
        if (!in_array($date, $allowed, true)) {
            return Response::error(
                'VALIDATION_ERROR',
                'date must be today or yesterday (YYYY-MM-DD); older messages via the viewer',
                422,
                [['field' => 'date']],
            );
        }

        $gateway = $this->buildGateway(self::MESSAGES_TABLE);
        if ($gateway === null) {
            return Response::error('INTERNAL_ERROR', 'Messages table is not available', 500);
        }

        $messageIds = $this->db->fetchAll(
            'SELECT id FROM %n WHERE auto_disposed_at >= %s AND auto_disposed_at < %s',
            self::MESSAGES_TABLE,
            $date . ' 00:00:00',
            date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00',
        );

        $analysisState = $this->hasActiveAiProfile() ? self::ANALYSIS_QUEUED : self::ANALYSIS_NONE;

        $restored = 0;
        foreach ($messageIds as $row) {
            $doc = $gateway->loadDocument((int) $row['id']);
            if ($doc === null) {
                continue;
            }
            $doc['docState'] = self::MSG_STATE_NEW;
            $doc['analysis_state'] = $analysisState;
            $doc['auto_disposed_by'] = null;
            $doc['auto_disposed_at'] = null;

            $result = $gateway->saveDocument($doc);
            if ($result->isSuccess()) {
                $restored++;
            }
        }

        return Response::success(['restored' => $restored]);
    }

    private function transitionRule(AuthContext $auth, int $id, int $targetState): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $gateway = $this->buildGateway(self::RULES_TABLE);
        if ($gateway === null) {
            return Response::error('INTERNAL_ERROR', 'Sender rules table is not available', 500);
        }

        $doc = $gateway->loadDocument($id);
        if ($doc === null) {
            return Response::error('NOT_FOUND', "Sender rule #{$id} not found", 404);
        }
        if ((int) ($doc['docState'] ?? 0) !== self::RULE_STATE_DRAFT) {
            return Response::error('INVALID_STATE', 'Only a draft rule (Koncept) can be confirmed or rejected', 409);
        }

        $doc['docState'] = $targetState;
        $result = $gateway->saveDocument($doc);
        if (!$result->isSuccess()) {
            $first = $result->getValidation()?->getErrors()[0] ?? null;
            return Response::error(
                'VALIDATION_ERROR',
                $first !== null ? "{$first->column}: {$first->message}" : ($result->getErrorMessage() ?? 'Save failed'),
                422,
            );
        }

        return Response::success(['id' => $id, 'docState' => $targetState]);
    }

    private function buildGateway(string $table): ?TableGateway
    {
        $def = $this->tables[$table] ?? null;
        if ($def === null) {
            return null;
        }

        return new TableGateway(
            $table,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->eventDispatcher,
            $def->docStates,
            $def,
        );
    }

    private function hasActiveAiProfile(): bool
    {
        return $this->db->fetchRow(
            'SELECT id FROM core_mail_ai_profiles WHERE is_active = %i LIMIT 1',
            1,
        ) !== null;
    }
}
