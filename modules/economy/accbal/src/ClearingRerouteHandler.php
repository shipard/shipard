<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\AbstractJournalEventHandler;

/**
 * Trigger „platba dřív než faktura" (#69 D4): po (pře)zápisu deníku
 * **dokladu** vezme z čerstvě re-derivovaného ledgeru předpisové pohyby
 * zdroje a jejich klíče případu (období, partner, VS, SS, měna) a nechá
 * {@see ClearingRouter} přeúčtovat čekající clearingové úhrady se shodným
 * klíčem. Ve starém Shipardu tohle uživatel dělal ručně; tady je to záměr.
 *
 * Období je součást klíče (D11): úhrada z nového období za předpis ze
 * starého čeká na clearingu, dokud se nezaúčtuje otevírací doklad nového
 * období — je to `doc`, takže projde tudy bez zvláštní cesty.
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
     * Klíče předpisových pohybů dokladu; bez partnera, období nebo VS není
     * co párovat. Ledger je normalizovaný při zápisu (D10), prázdný VS je
     * NULL — podmínka na '' je jen pojistka pro řádky z doby před D10.
     *
     * @return list<array{fiscal_year: int, partner: int, payment_reference: string, specific_symbol: ?string, currency: ?string}>
     */
    private function requestKeysOf(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT [fiscal_year], [partner], [payment_reference], [specific_symbol], [currency]
             FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i AND [bal_side] = 0
               AND [partner] IS NOT NULL AND [fiscal_year] IS NOT NULL
               AND [payment_reference] IS NOT NULL AND [payment_reference] <> \'\'',
            'doc',
            $docId,
        );

        $keys = [];
        foreach ($rows as $r) {
            $keys[] = CaseQuery::normalizeKey($r);
        }
        return $keys;
    }
}
