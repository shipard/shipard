<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\AbstractJournalEventHandler;

/**
 * Trigger „platba dřív než faktura" (#69 D4): po (pře)zápisu deníku
 * **dokladu** vezme z čerstvě re-derivovaného ledgeru předpisové pohyby
 * zdroje a jejich klíče (partner, VS, SS, měna) a nechá
 * {@see ClearingRouter} přeúčtovat čekající clearingové úhrady se shodným
 * klíčem. Ve starém Shipardu tohle uživatel dělal ručně; tady je to záměr.
 *
 * Registrace v module.jsonc **za** JournalLedgerHandler — dispatcher volá
 * handlery v pořadí registrace, takže ledger dokladu je při volání už
 * aktuální. Na `bankTransaction` handler nereaguje: přeúčtování transakce
 * vyšle journalWritten(bankTransaction) (re-entrantně na témže
 * dispatcheru), které tu skončí hned → žádná smyčka reaccount → událost →
 * reaccount. Vymazaný deník dokladu (opuštění stavu 40) → ledger bez
 * předpisů → žádné klíče → no-op; zpětný přesun úhrady na clearing se
 * automaticky nedělá (reaccount transakce ji tam vrátí sám — engine je bez
 * paměti).
 *
 * Běží po commitu deníku zdroje. V importních cestách
 * (TransactionlessTableGateway, DocumentApplier / StatementImportService
 * s vlastní transakcí) engine `begin()` vnější transakci implicitně
 * commitne — stejný stav jako dnes u AccountingEngine::writeResult, T1 na
 * něm nic nemění.
 */
final class ClearingRerouteHandler extends AbstractJournalEventHandler
{
    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        if ($sourceKind !== 'doc' || $this->db === null) {
            return;
        }

        $keys = $this->requestKeysOf($sourceId);
        if ($keys === []) {
            return;
        }

        $lookup = new LedgerOpenItemLookup();
        $lookup->setDb($this->db);
        if ($this->config !== null) {
            $lookup->setConfig($this->config);
        }
        if ($this->dsConfig !== null) {
            $lookup->setDsConfig($this->dsConfig);
        }

        (new ClearingRouter($this->db, $this->config, $this->journalEvents, $lookup))
            ->rerouteForKeys($keys, false);
    }

    /**
     * Klíče předpisových pohybů dokladu; bez partnera nebo VS není co párovat.
     *
     * @return list<array{partner: int, payment_reference: string, specific_symbol: string, currency: string}>
     */
    private function requestKeysOf(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT [partner], [payment_reference], [specific_symbol], [currency]
             FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i AND [bal_side] = 0
               AND [partner] IS NOT NULL
               AND TRIM(COALESCE([payment_reference], \'\')) <> \'\'',
            'doc',
            $docId,
        );

        $keys = [];
        foreach ($rows as $r) {
            $keys[] = [
                'partner'           => (int) $r['partner'],
                'payment_reference' => trim((string) $r['payment_reference']),
                'specific_symbol'   => trim((string) ($r['specific_symbol'] ?? '')),
                'currency'          => strtolower(trim((string) ($r['currency'] ?? ''))),
            ];
        }
        return $keys;
    }
}
