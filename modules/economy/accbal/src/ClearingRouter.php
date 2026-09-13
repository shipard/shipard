<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;

/**
 * Přeúčtování clearingových úhrad na účet otevřeného předpisu (#69 D4).
 *
 * Kandidát = úhradový pohyb (bal_side 1) bankovní transakce ve skupině
 * „Nespárované platby" (clearing 261200/261300). Pro každého: bez partnera
 * → přeskočit; {@see OpenItemLookup} nenajde otevřený předpis pro klíč
 * (partner, VS, SS, měna) a směr → přeskočit; jinak
 * {@see BankTransactionAccountingEngine::accountTransaction} — engine si
 * účet předpisu dohledá sám (týž lookup), deník přepíše a vyšle
 * `journalWritten` → LedgerGenerator clearing pohyb odebere a založí
 * úhradu na 311/321. Router tedy nic nerozhoduje dvakrát; lookup předem
 * slouží jen k tomu, aby se nepřeúčtovávalo naprázdno a aby dry-run uměl
 * vypsat plán.
 *
 * Běh je sekvenční (datum transakce, id): každé přeúčtování hned sníží
 * reziduum klíče, takže druhá úhrada už uzavřeného předpisu zůstane na
 * clearingu. Dry-run pořadí nesimuluje — vypíše všechny kandidáty se
 * zásahem. Idempotentní: přeúčtovaná úhrada na clearingu není → další běh
 * ji nenajde.
 *
 * Vstupy: {@see ClearingRerouteHandler} (po zaúčtování předpisu, klíče
 * dokladu), CLI `accbal-match` a `POST /_accbal/match` (dávka s filtry).
 */
final class ClearingRouter
{
    /** Aktivní docState (archivní sada) pro skupiny saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    private const TX_STATE_DONE = 40;

    private const CLEARING_CODE = 'unmatched_payments';

    private ?int $clearingBalance = null;
    private bool $clearingResolved = false;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?JournalEventDispatcher $journalEvents,
        private readonly OpenItemLookup $openItems,
    ) {}

    /**
     * Dávka: všichni clearingoví kandidáti, volitelně jen partner / fiskální rok.
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     */
    public function rerouteAll(array $filters = [], bool $dryRun = false): RouteSummary
    {
        $summary = new RouteSummary();
        foreach ($this->loadCandidates($filters, null) as $candidate) {
            $summary->add($this->routeCandidate($candidate, $dryRun));
        }
        return $summary;
    }

    /**
     * Klíče právě zaúčtovaných předpisů: clearingové úhrady se shodným
     * klíčem (oba směry — o směru rozhodne lookup vůči směru transakce).
     *
     * @param list<array{partner: int, payment_reference: string, specific_symbol: string, currency: string}> $keys
     */
    public function rerouteForKeys(array $keys, bool $dryRun = false): RouteSummary
    {
        $summary = new RouteSummary();
        $seen = [];
        foreach ($keys as $key) {
            foreach ($this->loadCandidates([], $key) as $candidate) {
                $txId = (int) $candidate['bank_transaction'];
                if (isset($seen[$txId])) {
                    continue;
                }
                $seen[$txId] = true;
                $summary->add($this->routeCandidate($candidate, $dryRun));
            }
        }
        return $summary;
    }

    // ── Jádro ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed>|\Dibi\Row $c */
    private function routeCandidate(array|\Dibi\Row $c, bool $dryRun): RouteResult
    {
        $txId     = (int) $c['bank_transaction'];
        $partner  = (int) ($c['partner'] ?? 0);
        $amount   = (float) ($c['amount'] ?? 0);
        $amountHc = (float) ($c['amount_hc'] ?? 0);
        $currency = $c['currency'] !== null ? (string) $c['currency'] : null;

        if ($partner <= 0) {
            return RouteResult::skipped($txId, 'no_partner', $amount, $amountHc);
        }

        $item = $this->openItems->findOpenRequest(
            $partner,
            trim((string) ($c['payment_reference'] ?? '')),
            trim((string) ($c['specific_symbol'] ?? '')),
            strtolower(trim((string) $currency)),
            (int) ($c['direction'] ?? 0),
            'bankTransaction',
            $txId,
        );
        if ($item === null) {
            return RouteResult::skipped($txId, 'no_open_item', $amount, $amountHc);
        }
        if ($dryRun) {
            return RouteResult::planned($txId, $item->accountNumber, $partner, $currency, $amount, $amountHc);
        }

        try {
            $result = $this->engine()->accountTransaction($txId);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, "ClearingRouter: reaccount of bank transaction #{$txId} failed");
            return RouteResult::skipped($txId, 'engine_error', $amount, $amountHc);
        }
        if ((int) ($result['state'] ?? 2) !== 1) {
            return RouteResult::skipped($txId, 'engine_error', $amount, $amountHc);
        }

        return RouteResult::routed($txId, $item->accountNumber, $partner, $currency, $amount, $amountHc);
    }

    private function engine(): BankTransactionAccountingEngine
    {
        return new BankTransactionAccountingEngine($this->db, $this->config, $this->journalEvents, $this->openItems);
    }

    /**
     * Clearingoví kandidáti seřazení dle data transakce (FIFO plateb v dávce).
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     * @param array{partner: int, payment_reference: string, specific_symbol: string, currency: string}|null $key
     * @return list<\Dibi\Row>
     */
    private function loadCandidates(array $filters, ?array $key): array
    {
        $clearing = $this->clearingBalanceId();
        if ($clearing === null) {
            return [];
        }

        $conds = [
            'l.[balance] = %i', 'l.[bal_side] = 1', 'l.[source_kind] = %s',
            'l.[bank_transaction] IS NOT NULL', 't.[docState] = %i',
        ];
        $args = [$clearing, 'bankTransaction', self::TX_STATE_DONE];

        if (isset($filters['partner'])) {
            $conds[] = 'l.[partner] = %i';
            $args[]  = (int) $filters['partner'];
        }
        if (isset($filters['fiscalYear'])) {
            $conds[] = 'l.[fiscal_year] = %i';
            $args[]  = (int) $filters['fiscalYear'];
        }
        if ($key !== null) {
            $conds[] = 'l.[partner] = %i';
            $args[]  = (int) $key['partner'];
            $conds[] = 'TRIM(l.[payment_reference]) = %s';
            $args[]  = trim($key['payment_reference']);
            $conds[] = 'TRIM(COALESCE(l.[specific_symbol], \'\')) = %s';
            $args[]  = trim($key['specific_symbol']);
            $conds[] = 'LOWER(l.[currency]) = %s';
            $args[]  = strtolower(trim($key['currency']));
        }

        return $this->db->fetchAll(
            'SELECT l.[id], l.[bank_transaction], l.[partner], l.[payment_reference],
                    l.[specific_symbol], l.[currency], l.[amount], l.[amount_hc],
                    t.[direction]
             FROM [economy_accbal_ledger] l
             JOIN [economy_bank_transactions] t ON t.[id] = l.[bank_transaction]
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY t.[date_transaction] ASC, t.[id] ASC',
            ...$args,
        );
    }

    private function clearingBalanceId(): ?int
    {
        if (!$this->clearingResolved) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_accbal_balances] WHERE [code] = %s AND [docState] IN %in',
                self::CLEARING_CODE,
                self::ACTIVE_STATES,
            );
            $this->clearingBalance  = $row !== null ? (int) $row['id'] : null;
            $this->clearingResolved = true;
        }
        return $this->clearingBalance;
    }
}
