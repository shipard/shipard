<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Lifecycle účtování dokladů (registrace v module.jsonc →
 * documentEventHandlers):
 *
 *  - přechod DO 40 (V pořádku) → AccountingEngine přepíše deník dokladu
 *  - přechod ZE 40 (V opravě, Storno, …) → deník se maže, stav 0
 *  - beforeDelete → smazat řádky deníku (integrita je aplikační,
 *    bez úklidu by zůstali sirotci)
 *
 * Invariant: doklad má řádky v deníku právě tehdy, když je ve stavu 40.
 *
 * Neočekávaná výjimka enginu nesmí poslat chybu uživateli, který právě
 * uložil doklad (commit už proběhl) — catch → log + accounting_state 2
 * + message engine_error. Alert check pak uživatele upomíná.
 */
final class DocsHeadsEventHandler extends AbstractDocumentEventHandler
{
    private const DOC_STATE_OK = 40;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        $docId = (int) $data['id'];

        if ($newState === self::DOC_STATE_OK) {
            try {
                $this->engine()->accountDocument($docId);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, "AccountingEngine failed for doc #{$docId}");
                $this->markEngineError($docId, $e);
            }
            return;
        }

        if ($oldState === self::DOC_STATE_OK) {
            $this->engine()->clearDocument($docId);
        }
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        $this->db->delete('economy_accounting_journal')
            ->where('doc_head = %i', (int) $data['id'])
            ->execute();
    }

    private function engine(): AccountingEngine
    {
        return new AccountingEngine($this->db, $this->config, $this->journalEvents, $this->journalContributors);
    }

    private function markEngineError(int $docId, \Throwable $e): void
    {
        try {
            $messages = [[
                'code'    => 'engine_error',
                'message' => 'Účtování selhalo: ' . $e->getMessage(),
                'rowId'   => null,
            ]];
            $this->db?->update('docs_core_heads', [
                'accounting_state'    => 2,
                'accounting_messages' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->where('id = %i', $docId)->execute();
        } catch (\Throwable $inner) {
            // I zápis chybového stavu může selhat (DB down) — jen log.
            ErrorLogger::logException($inner, "Failed to mark accounting error for doc #{$docId}");
        }
    }
}
