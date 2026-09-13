<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Lifecycle účtování bankovních transakcí (registrace v module.jsonc →
 * documentEventHandlers) — bankovní obdoba DocsHeadsEventHandler:
 *
 *  - přechod DO 40 (Zaúčtováno) → BankTransactionAccountingEngine přepíše
 *    deník transakce
 *  - přechod ZE 40 → deník se maže, accounting_state 0
 *  - beforeDelete → smazat řádky deníku (integrita je aplikační, bez úklidu
 *    by zůstali sirotci)
 *
 * Invariant: transakce má řádky v deníku právě tehdy, když je ve stavu 40.
 *
 * Neočekávaná výjimka enginu nesmí poslat chybu uživateli, který právě
 * uložil přechod (commit už proběhl) — catch → log + accounting_state 2
 * + message engine_error. Alert check pak uživatele upomíná.
 */
final class BankTransactionEventHandler extends AbstractDocumentEventHandler
{
    private const TX_STATE_DONE = 40;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        $txId = (int) $data['id'];

        if ($newState === self::TX_STATE_DONE) {
            try {
                $this->engine()->accountTransaction($txId);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, "BankTransactionAccountingEngine failed for tx #{$txId}");
                $this->markEngineError($txId, $e);
            }
            return;
        }

        if ($oldState === self::TX_STATE_DONE) {
            $this->engine()->clearTransaction($txId);
        }
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        $this->db->delete('economy_accounting_journal')
            ->where('bank_transaction = %i', (int) $data['id'])
            ->execute();
    }

    private function engine(): BankTransactionAccountingEngine
    {
        return new BankTransactionAccountingEngine($this->db, $this->config, $this->journalEvents, $this->openItems);
    }

    private function markEngineError(int $txId, \Throwable $e): void
    {
        try {
            $messages = [[
                'code'    => 'engine_error',
                'message' => 'Účtování selhalo: ' . $e->getMessage(),
                'rowId'   => null,
            ]];
            $this->db?->update('economy_bank_transactions', [
                'accounting_state'    => 2,
                'accounting_messages' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->where('id = %i', $txId)->execute();
        } catch (\Throwable $inner) {
            ErrorLogger::logException($inner, "Failed to mark accounting error for tx #{$txId}");
        }
    }
}
