<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Accounting\AccountingRules;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;

/**
 * Trigger „platba dřív než proforma“ (#79 D3b, §4 tasku): po (pře)zápisu
 * deníku **dokladu** vezme z čerstvě re-derivovaného ledgeru předpisové
 * klíče dokladu ve skupinách s `closing_category`
 * ({@see LedgerOpenItemLookup::closingGroups}) a pro každý klíč najde
 * v deníku **ostatní** zdroje téhož fiskálního roku (D11) se spouštěcím
 * řádkem klíče ({@see CaseClosureContributor::TRIGGER_OPERATIONS} na účtu
 * s maskou `payment_category`, strana opačná k předpisu skupiny) a bez
 * uzavíracího řádku (žádný řádek na prefixu skupiny s touž identitou).
 * Ty přeúčtuje enginem dle druhu zdroje (doklad → AccountingEngine,
 * transakce → bankovní engine; oba s contributory) — contributor doplní
 * uzavírací pár.
 *
 * Bankovní platbu před proformou pokrývá už `ClearingRerouteHandler`
 * (čeká na clearingu, engine ji přesměruje na 324 a contributor uzavře);
 * tenhle handler je pro pokladní doklad s `advance.received` (účtuje 324
 * z předpisu, na clearingu nikdy není) a pro zdroje zaúčtované před
 * nasazením uzavírání. Filtr „bez uzavíracího řádku“ je jen úspora —
 * reaccount je idempotentní, přeúčtování už uzavřeného zdroje by nic
 * nezměnilo.
 *
 * Registrace v module.jsonc **za** ClearingRerouteHandler: ledger dokladu
 * je při volání aktuální a bankovní úhrada přeúčtovaná clearing triggerem
 * už uzavírací řádky má. Re-entrance: přeúčtovaný zdroj vyšle
 * `journalWritten` — transakce tu skončí hned, pokladní doklad má
 * předpis jen v Přijatých zálohách (bez `closing_category`) → no-op.
 * Zámek období se tu neověřuje (stejně jako u přeúčtování transakcí
 * clearing triggerem); výjimka enginu se zaloguje a další zdroje běží dál.
 */
final class CaseClosureRerouteHandler extends AbstractJournalEventHandler
{
    private ?LedgerOpenItemLookup $lookup = null;

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        if ($sourceKind !== 'doc' || $this->db === null) {
            return;
        }
        $lookup = $this->lookup();
        $groups = $lookup->closingGroups();
        if ($groups === []) {
            return;
        }

        $byBalance = [];
        foreach ($groups as $group) {
            $byBalance[$group['balance']] = $group;
        }
        $keys = $this->requestKeysOf($sourceId, array_keys($byBalance));
        if ($keys === []) {
            return;
        }

        $rules = AccountingRules::resolve($this->config, $this->db);
        $done = [];
        foreach ($keys as $key) {
            $group = $byBalance[(int) $key['balance']];
            $mask = AccountingRules::firstMaskForCategory($rules, $group['payment_category']);
            if ($mask === '') {
                continue;
            }
            foreach ($this->loadCandidates($sourceId, $key, $group, $mask) as $candidate) {
                $id = $candidate['kind'] . '#' . $candidate['id'];
                if (isset($done[$id])) {
                    continue;
                }
                $done[$id] = true;
                $this->reaccount($candidate['kind'], $candidate['id'], $lookup);
            }
        }
    }

    /**
     * Klíče předpisových pohybů dokladu v cílových skupinách; bez partnera,
     * období nebo VS není co uzavírat. Ledger je normalizovaný (D10).
     *
     * @param list<int> $balances
     * @return list<array<string, int|string|null>> klíč vč. balance (CaseQuery::normalizeKey)
     */
    private function requestKeysOf(int $docId, array $balances): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT [balance], [fiscal_year], [partner], [payment_reference], [specific_symbol], [currency]
             FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i AND [bal_side] = 0 AND [balance] IN %in
               AND [partner] IS NOT NULL AND [fiscal_year] IS NOT NULL
               AND [payment_reference] IS NOT NULL AND [payment_reference] <> \'\'',
            'doc',
            $docId,
            $balances,
        );
        $keys = [];
        foreach ($rows as $r) {
            $keys[] = CaseQuery::normalizeKey($r);
        }
        return $keys;
    }

    /**
     * Zdroje se spouštěcím řádkem klíče a bez uzavíracího řádku. Deník
     * není normalizovaný jako ledger, proto TRIM/LOWER na symbolech a měně;
     * prázdný SS deníku (NULL i '') sedí jen na prázdný SS klíče.
     *
     * @param array<string, int|string|null> $key
     * @param array{balance: int, request_side: int, prefixes: list<string>, payment_category: string, closing_category: string} $group
     * @return list<array{kind: string, id: int}>
     */
    private function loadCandidates(int $docId, array $key, array $group, string $paymentMask): array
    {
        $triggerAmount = $group['request_side'] === 0
            ? '(j.[money_cr] <> 0 OR j.[money_cr_cur] <> 0)'
            : '(j.[money_dr] <> 0 OR j.[money_dr_cur] <> 0)';
        $closingPrefixes = implode(' OR ', array_fill(0, count($group['prefixes']), 'c.[account_number] LIKE %like~'));

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT j.[source_kind], j.[doc_head], j.[bank_transaction]
             FROM [economy_accounting_journal] j
             WHERE j.[is_error] = 0
               AND j.[operation] IN %in
               AND j.[account_number] LIKE %like~
               AND ' . $triggerAmount . '
               AND j.[fiscal_year] = %i AND j.[partner] = %i
               AND TRIM(j.[payment_reference]) = %s
               AND COALESCE(NULLIF(TRIM(j.[specific_symbol]), \'\'), \'\') = %s
               AND LOWER(TRIM(COALESCE(j.[currency], \'\'))) = %s
               AND NOT (j.[source_kind] = %s AND j.[doc_head] = %i)
               AND NOT EXISTS (
                   SELECT 1 FROM [economy_accounting_journal] c
                   WHERE c.[source_kind] = j.[source_kind]
                     AND c.[doc_head] <=> j.[doc_head] AND c.[bank_transaction] <=> j.[bank_transaction]
                     AND c.[is_error] = 0
                     AND (' . $closingPrefixes . ')
                     AND c.[partner] = j.[partner]
                     AND TRIM(c.[payment_reference]) = TRIM(j.[payment_reference])
               )
             ORDER BY j.[accounting_date], j.[id]',
            CaseClosureContributor::TRIGGER_OPERATIONS,
            $paymentMask,
            (int) $key['fiscal_year'],
            (int) $key['partner'],
            (string) $key['payment_reference'],
            (string) ($key['specific_symbol'] ?? ''),
            (string) ($key['currency'] ?? ''),
            'doc',
            $docId,
            ...$group['prefixes'],
        );

        $out = [];
        foreach ($rows as $r) {
            $kind = (string) $r['source_kind'];
            $id = $kind === 'bankTransaction' ? (int) $r['bank_transaction'] : (int) $r['doc_head'];
            if ($id > 0) {
                $out[] = ['kind' => $kind, 'id' => $id];
            }
        }
        return $out;
    }

    private function reaccount(string $kind, int $id, LedgerOpenItemLookup $lookup): void
    {
        try {
            if ($kind === 'bankTransaction') {
                (new BankTransactionAccountingEngine($this->db, $this->config, $this->journalEvents, $lookup, $this->journalContributors))
                    ->accountTransaction($id);
            } else {
                (new AccountingEngine($this->db, $this->config, $this->journalEvents, $this->journalContributors))
                    ->accountDocument($id);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, "CaseClosureRerouteHandler: reaccount of {$kind} #{$id} failed");
        }
    }

    private function lookup(): LedgerOpenItemLookup
    {
        if ($this->lookup === null) {
            $lookup = new LedgerOpenItemLookup();
            $lookup->setDb($this->db);
            if ($this->config !== null) {
                $lookup->setConfig($this->config);
            }
            if ($this->dsConfig !== null) {
                $lookup->setDsConfig($this->dsConfig);
            }
            $this->lookup = $lookup;
        }
        return $this->lookup;
    }
}
