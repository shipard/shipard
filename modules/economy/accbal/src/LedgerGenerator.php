<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Generátor saldo pohybů (economy_accbal_ledger) z účetního deníku.
 *
 * Vstup: (sourceKind, sourceId). Načte řádky deníku zdroje + nastavení
 * saldokont (balances + balance_accounts), vyrobí kandidátní pohyby a
 * idempotentně UPSERTuje ledger podle stabilního klíče
 * (source_kind, source_id, balance, bal_side, account_number).
 *
 * Idempotence: `id` pohybu přežije přeúčtování (zdroj se nemění), journal_row
 * je jen denorm (volatilní). Prázdný deník (zdroj opustil stav 40) → desired
 * set prázdný → pohyby zdroje smazány. Případ (#69 D1) je agregát klíče nad
 * ledgerem (CaseQuery), žádná vedlejší tabulka — smazaný pohyb z něj zmizí sám.
 *
 * Clearing (261200/261300) není speciální case — je to běžná skupina
 * „Nespárované platby" v nastavení (varianta B, docs/accbal.md §4.4).
 *
 * Algoritmus a sémantika: docs/accbal.md §4.2/4.3.
 */
final class LedgerGenerator
{
    /** Aktivní docState (archivní sada) pro nastavení saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * $homeCurrency = rozhodnutí per DS (settings klíč `economy.homeCurrency`,
     * docs/ds-setup.md §5.2) — předává volající (JournalLedgerHandler),
     * generator si settings nečte sám. Null = nerozhodnuto → 'czk'.
     */
    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?string $homeCurrency = null,
    ) {}

    public function generate(string $sourceKind, int $sourceId): void
    {
        $accounts = $this->loadBalanceAccounts();
        $journalRows = $this->loadJournalRows($sourceKind, $sourceId);
        $homeCurrency = strtolower($this->homeCurrency ?? 'czk');

        $desired = $this->buildDesired($sourceKind, $sourceId, $accounts, $journalRows, $homeCurrency);

        $this->upsert($sourceKind, $sourceId, $desired);
    }

    /**
     * Aktivní balance_accounts + info skupiny, seřazené dle sort_order.
     * Validitu (valid_from/to) řešíme per řádek deníku v buildDesired.
     *
     * @return list<array<string, mixed>>
     */
    private function loadBalanceAccounts(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.[id], a.[balance], a.[account_number], a.[acc_side], a.[amounts_sign],
                    a.[bal_side], a.[modify_sign], a.[valid_from] AS a_from, a.[valid_to] AS a_to,
                    b.[valid_from] AS b_from, b.[valid_to] AS b_to
             FROM [economy_accbal_balance_accounts] a
             JOIN [economy_accbal_balances] b ON b.[id] = a.[balance]
             WHERE a.[docState] IN %in AND b.[docState] IN %in
             ORDER BY b.[sort_order], a.[sort_order], a.[id]',
            self::ACTIVE_STATES,
            self::ACTIVE_STATES,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadJournalRows(string $sourceKind, int $sourceId): array
    {
        $column = $sourceKind === 'bankTransaction' ? 'bank_transaction' : 'doc_head';
        $rows = $this->db->fetchAll(
            'SELECT * FROM [economy_accounting_journal]
             WHERE [source_kind] = %s AND [' . $column . '] = %i
             ORDER BY [id]',
            $sourceKind,
            $sourceId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * Kandidátní pohyby agregované dle stabilního klíče.
     *
     * @param list<array<string, mixed>> $accounts
     * @param list<array<string, mixed>> $journalRows
     * @return array<string, array<string, mixed>> klíč → pohyb
     */
    private function buildDesired(
        string $sourceKind,
        int $sourceId,
        array $accounts,
        array $journalRows,
        string $homeCurrency,
    ): array {
        $desired = [];

        foreach ($journalRows as $row) {
            // Chybový řádek deníku nemá dohledaný účet (account NULL, account_number
            // je jen nedořešená maska) — saldo z něj derivovat nelze, jinak by
            // vznikl fantomový pohyb maskující účetní chybu (docs/accbal.md §4.2).
            if (!empty($row['is_error'])) {
                continue;
            }

            $accountNumber = (string) ($row['account_number'] ?? '');
            $accountingDate = $this->dateString($row['accounting_date'] ?? null);

            foreach ($accounts as $acc) {
                $prefix = (string) ($acc['account_number'] ?? '');
                if ($prefix === '' || !str_starts_with($accountNumber, $prefix)) {
                    continue;
                }
                if (!$this->validAt($acc, $accountingDate)) {
                    continue;
                }

                $accSide = (int) ($acc['acc_side'] ?? 0);
                $activeHc  = (float) ($accSide === 0 ? ($row['money_dr'] ?? 0) : ($row['money_cr'] ?? 0));
                $activeCur = (float) ($accSide === 0 ? ($row['money_dr_cur'] ?? 0) : ($row['money_cr_cur'] ?? 0));

                // Jednostranný řádek: bere se jen strana, kterou účet sleduje.
                if ($activeHc === 0.0 && $activeCur === 0.0) {
                    continue;
                }
                if (!$this->passesAmountsSign((int) ($acc['amounts_sign'] ?? 0), $activeHc)) {
                    continue;
                }

                $sign = !empty($acc['modify_sign']) ? -1.0 : 1.0;
                $balance = (int) $acc['balance'];
                $balSide = (int) ($acc['bal_side'] ?? 0);

                $key = implode('|', [$sourceKind, $sourceId, $balance, $balSide, $accountNumber]);

                if (!isset($desired[$key])) {
                    $desired[$key] = [
                        'source_kind'       => $sourceKind,
                        'source_id'         => $sourceId,
                        'doc_head'          => $sourceKind === 'doc' ? $sourceId : null,
                        'bank_transaction'  => $sourceKind === 'bankTransaction' ? $sourceId : null,
                        'balance'           => $balance,
                        'bal_side'          => $balSide,
                        'account_number'    => $accountNumber,
                        'journal_row'       => (int) $row['id'],
                        'fiscal_year'       => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : null,
                        'partner'           => isset($row['partner']) && $row['partner'] !== null ? (int) $row['partner'] : null,
                        // Klíč případu se normalizuje při zápisu (#69 D10):
                        // symboly TRIM, prázdné → NULL, měna malými písmeny —
                        // rovnost klíče pak jde přes idx_case (CaseQuery).
                        'payment_reference' => CaseQuery::normalizeSymbol($row['payment_reference'] ?? null),
                        'specific_symbol'   => CaseQuery::normalizeSymbol($row['specific_symbol'] ?? null),
                        'constant_symbol'   => CaseQuery::normalizeSymbol($row['constant_symbol'] ?? null),
                        'due_date'          => $row['due_date'] ?? null,
                        'currency'          => CaseQuery::normalizeCurrency($row['currency'] ?? null),
                        'home_currency'     => $homeCurrency,
                        'amount'            => 0.0,
                        'amount_hc'         => 0.0,
                        'text'              => $row['text'] ?? null,
                    ];
                }

                // Agregace shodného klíče (grouping deníku) — součet částek.
                $desired[$key]['amount']    = round($desired[$key]['amount'] + $activeCur * $sign, 2);
                $desired[$key]['amount_hc'] = round($desired[$key]['amount_hc'] + $activeHc * $sign, 2);
            }
        }

        return $desired;
    }

    /** amounts_sign: 0 vše, 1 jen kladné, 2 jen záporné (dle domácí částky). */
    private function passesAmountsSign(int $amountsSign, float $activeHc): bool
    {
        return match ($amountsSign) {
            1       => $activeHc > 0.0,
            2       => $activeHc < 0.0,
            default => true,
        };
    }

    /** Platnost balance_accountu i jeho skupiny k účetnímu datu řádku. */
    private function validAt(array $acc, string $accountingDate): bool
    {
        if ($accountingDate === '') {
            return true;
        }
        foreach ([['a_from', 'a_to'], ['b_from', 'b_to']] as [$from, $to]) {
            $f = $this->dateString($acc[$from] ?? null);
            $t = $this->dateString($acc[$to] ?? null);
            if ($f !== '' && $accountingDate < $f) {
                return false;
            }
            if ($t !== '' && $accountingDate > $t) {
                return false;
            }
        }
        return true;
    }

    /**
     * UPSERT desired setu + smazání pohybů zdroje mimo desired. Vše v jedné
     * transakci.
     *
     * @param array<string, array<string, mixed>> $desired
     */
    private function upsert(string $sourceKind, int $sourceId, array $desired): void
    {
        $this->db->begin();
        try {
            $existing = $this->db->fetchAll(
                'SELECT [id], [balance], [bal_side], [account_number]
                 FROM [economy_accbal_ledger]
                 WHERE [source_kind] = %s AND [source_id] = %i',
                $sourceKind,
                $sourceId,
            );

            $existingByKey = [];
            foreach ($existing as $row) {
                $key = implode('|', [
                    $sourceKind,
                    $sourceId,
                    (int) $row['balance'],
                    (int) $row['bal_side'],
                    (string) $row['account_number'],
                ]);
                $existingByKey[$key] = (int) $row['id'];
            }

            foreach ($desired as $key => $move) {
                if (isset($existingByKey[$key])) {
                    $this->db->update('economy_accbal_ledger', $move)
                        ->where('[id] = %i', $existingByKey[$key])
                        ->execute();
                } else {
                    $this->db->insert('economy_accbal_ledger', $move)->execute();
                }
            }

            // Pohyby zdroje, které v desired nejsou → smazat.
            foreach ($existingByKey as $key => $id) {
                if (isset($desired[$key])) {
                    continue;
                }
                $this->db->delete('economy_accbal_ledger')->where('[id] = %i', $id)->execute();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return $value !== null ? (string) $value : '';
    }
}
