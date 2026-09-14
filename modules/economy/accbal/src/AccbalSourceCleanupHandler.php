<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\AbstractDocumentEventHandler;

/**
 * Úklid saldo pohybů při mazání zdroje (doklad / bankovní transakce).
 * Registrace v module.jsonc → documentEventHandlers beforeDelete na
 * docs_core_heads i economy_bank_transactions.
 *
 * economy_accbal_ledger FK-uje na doc_head / bank_transaction → bez úklidu
 * by delete zdroje spadl na referenční integritu. Běží uvnitř delete
 * transakce zdroje (výjimka rollbackne celý delete). Případ (#69 D1) je
 * agregát nad ledgerem, žádná další tabulka k úklidu.
 */
final class AccbalSourceCleanupHandler extends AbstractDocumentEventHandler
{
    private const TABLE_HEADS = 'docs_core_heads';
    private const TABLE_BANK_TX = 'economy_bank_transactions';

    public function onBeforeDelete(string $tableId, array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }

        // $tableId je název tabulky (dispatcher matchuje registrace dle `table`).
        $column = match ($tableId) {
            self::TABLE_BANK_TX => 'bank_transaction',
            self::TABLE_HEADS   => 'doc_head',
            default             => null,
        };
        if ($column === null) {
            return;
        }

        $this->db->delete('economy_accbal_ledger')
            ->where('[' . $column . '] = %i', (int) $data['id'])
            ->execute();
    }
}
