<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;

/**
 * Generátor saldo pohybů (economy_accbal_ledger) z účetního deníku.
 *
 * Vstup: (sourceKind, sourceId). Načte řádky deníku zdroje + nastavení
 * saldokont (balances + balance_accounts), vyrobí kandidátní pohyby a
 * idempotentně UPSERTuje ledger podle stabilního klíče pohybu = zdroj +
 * platební identita řádku (#69 D13):
 *
 *   (source_kind, source_id, balance, bal_side, account_number,
 *    partner, payment_reference, specific_symbol, currency)
 *
 * Řádky téhož zdroje se stejnou identitou se sčítají do jednoho pohybu
 * (faktura se dvěma řádky na 311 = jeden předpis), různé identity dají různé
 * pohyby (otevírací doklad období s desítkami pohledávek, zápočet, pokladní
 * doklad s více řádky). Symboly a měna v klíči jsou normalizované stejně
 * jako klíč případu (D10, {@see CaseQuery}). V DB klíč reprezentuje sloupec
 * `movement_key` = SHA-1 kanonické podoby ({@see movementKey}) — unikátní
 * index nad n-ticí by shodu přes NULL (VS/SS) nevynutil.
 *
 * Idempotence: `id` pohybu přežije přeúčtování (zdroj i identita řádku se
 * nemění), journal_row je jen denorm (volatilní). Prázdný deník (zdroj
 * opustil stav 40) → desired set prázdný → pohyby zdroje smazány. Případ
 * (#69 D1) je agregát klíče nad ledgerem (CaseQuery), žádná vedlejší tabulka
 * — smazaný pohyb z něj zmizí sám.
 *
 * Skupinu a druh pohybu určuje (1) operace řádku, pokud určuje stranu
 * ({@see OperationSides}, #69 D17), (2) jinak účet + strana + znaménko
 * podle řádku nastavení platného k účetnímu datu. Řádky uzávěrkového
 * období (`period_type` 2 měsíce řádku) do ledgeru nejdou (D20);
 * otevírací (0) zůstávají předpisem nového roku (D11).
 *
 * Clearing (261200/261300) není speciální case — je to běžná skupina
 * „Nespárované platby" v nastavení (varianta B, docs/accbal.md §4.4).
 *
 * Hromadná re-derivace (po změně generátoru, po ds-upgrade s novým klíčem):
 * `shpd-ds accbal-regenerate` volá generate() per zdroj a sčítá statistiky;
 * `$dryRun` diff jen spočítá. Nastavení saldokont se načítá jednou per
 * instance (dávka = jeden dotaz, ne dotaz na zdroj).
 *
 * Algoritmus a sémantika: docs/accbal.md §4.2/4.3.
 */
final class LedgerGenerator
{
    /** Aktivní docState (archivní sada) pro nastavení saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    /** @var list<array<string, mixed>>|null memo nastavení saldokont */
    private ?array $accounts = null;

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

    /**
     * Re-derivace pohybů jednoho zdroje. Vrací počty změn; v dry-runu je
     * jen spočítá a do DB nesahá.
     *
     * @return array{inserted: int, updated: int, deleted: int}
     */
    public function generate(string $sourceKind, int $sourceId, bool $dryRun = false): array
    {
        $accounts = $this->balanceAccounts();
        $journalRows = $this->loadJournalRows($sourceKind, $sourceId);
        $homeCurrency = strtolower($this->homeCurrency ?? 'czk');

        $desired = $this->buildDesired($sourceKind, $sourceId, $accounts, $journalRows, $homeCurrency);

        return $this->sync($sourceKind, $sourceId, $desired, $dryRun);
    }

    /**
     * Kanonický klíč pohybu → SHA-1 (sloupec `movement_key`). Jediné místo,
     * kde je kanonická podoba definována: hodnoty klíče oddělené `|`, NULL
     * jako prázdný řetězec, symboly a měna po normalizaci D10 (takže NULL
     * a '' v SS dají týž klíč, 'CZK' a 'czk' také).
     *
     * @param array<string, mixed> $move pohyb (nebo jeho klíčové sloupce)
     */
    public static function movementKey(array $move): string
    {
        $partner = $move['partner'] ?? null;
        return sha1(implode('|', [
            (string) ($move['source_kind'] ?? ''),
            (string) (int) ($move['source_id'] ?? 0),
            (string) (int) ($move['balance'] ?? 0),
            (string) (int) ($move['bal_side'] ?? 0),
            (string) ($move['account_number'] ?? ''),
            $partner === null || $partner === '' ? '' : (string) (int) $partner,
            CaseQuery::normalizeSymbol($move['payment_reference'] ?? null) ?? '',
            CaseQuery::normalizeSymbol($move['specific_symbol'] ?? null) ?? '',
            CaseQuery::normalizeCurrency($move['currency'] ?? null) ?? '',
        ]));
    }

    /**
     * Aktivní balance_accounts + info skupiny, seřazené dle sort_order,
     * načtené jednou per instance. Validitu (valid_from/to) řešíme per řádek
     * deníku v buildDesired.
     *
     * @return list<array<string, mixed>>
     */
    private function balanceAccounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }
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
        return $this->accounts = array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * Řádky deníku zdroje + typ období jejich měsíce (`fiscal_period_type`
     * = `period_type` měsíce; NULL = řádek bez měsíce) pro vyloučení
     * uzávěrky v buildDesired.
     *
     * @return list<array<string, mixed>>
     */
    private function loadJournalRows(string $sourceKind, int $sourceId): array
    {
        $column = $sourceKind === 'bankTransaction' ? 'bank_transaction' : 'doc_head';
        $rows = $this->db->fetchAll(
            'SELECT j.*, m.[period_type] AS fiscal_period_type
             FROM [economy_accounting_journal] j
             LEFT JOIN [economy_codebooks_fiscal_months] m ON m.[id] = j.[fiscal_month]
             WHERE j.[source_kind] = %s AND j.[' . $column . '] = %i
             ORDER BY j.[id]',
            $sourceKind,
            $sourceId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * Kandidátní pohyby agregované dle klíče pohybu (movementKey). Čistá
     * funkce nad poli (bez DB) — veřejná kvůli unit testům pravidel;
     * SQL a zápis zůstávají privátní.
     *
     * Pravidla per řádek deníku (#69 D17/D20, docs/accbal.md §4.2):
     *  1. chybový řádek (`is_error`) a řádek uzávěrkového období
     *     (`fiscal_period_type` = 2) pohyb nedají;
     *  2. operace určující stranu ({@see OperationSides}): skupina =
     *     první řádek nastavení s předpisem (bal_side 0, bez modify_sign)
     *     na straně operace, jehož prefix sedí; strana řádku proti
     *     předpisu dává předpis/úhradu, znaménko částky se zachová.
     *     `payment.*` = vždy úhrada ve skupině účtu, + na straně, kterou
     *     skupina sleduje jako úhradu, − na opačné (vratka). Účet mimo
     *     skupiny → řádek se přeskočí (operace je autoritativní);
     *  3. ostatní řádky → všechny řádky nastavení (účet + strana +
     *     znaménko vč. sign-pravidel a modify_sign) platné k účetnímu datu.
     *
     * @param list<array<string, mixed>> $accounts řádky nastavení (balanceAccounts)
     * @param list<array<string, mixed>> $journalRows
     * @return array<string, array<string, mixed>> movement_key → pohyb
     */
    public function buildDesired(
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
            // Uzávěrkové období: závěrkové zápisy (311/321 DAL jednou částkou
            // bez partnera) nejsou úhrady — do salda nepatří (D20).
            if (isset($row['fiscal_period_type'])
                && (int) $row['fiscal_period_type'] === FiscalMonthLookup::PERIOD_TYPE_CLOSING) {
                continue;
            }

            $accountNumber  = (string) ($row['account_number'] ?? '');
            $accountingDate = $this->dateString($row['accounting_date'] ?? null);

            foreach ($this->candidatesFor($row, $accountNumber, $accountingDate, $accounts) as $candidate) {
                $this->addCandidate($desired, $sourceKind, $sourceId, $row, $candidate, $homeCurrency);
            }
        }

        return $desired;
    }

    /**
     * Kandidáti pohybu jednoho řádku: (skupina, předpis/úhrada, strana
     * řádku, jejíž částka se bere, znaménko). Operace se stranou dává
     * nejvýš jednoho kandidáta, nastavení může dát víc (týž účet ve dvou
     * skupinách).
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $accounts
     * @return list<array{balance: int, bal_side: int, acc_side: int, sign: float}>
     */
    private function candidatesFor(array $row, string $accountNumber, string $accountingDate, array $accounts): array
    {
        $kind = OperationSides::kindOf(isset($row['operation']) ? (string) $row['operation'] : null);
        if ($kind === null) {
            return $this->settingsCandidates($row, $accountNumber, $accountingDate, $accounts);
        }

        $rowSide = self::rowSide($row);
        if ($rowSide === null) {
            return [];
        }

        if ($kind === OperationSides::PAYMENT) {
            $group = $this->paymentGroup($accountNumber, $accountingDate, $accounts);
            if ($group === null) {
                return [];
            }
            return [[
                'balance'  => $group['balance'],
                'bal_side' => 1,
                'acc_side' => $rowSide,
                'sign'     => $rowSide === $group['payment_side'] ? 1.0 : -1.0,
            ]];
        }

        $group = $this->requestGroup((int) $kind, $accountNumber, $accountingDate, $accounts);
        if ($group === null) {
            return [];
        }
        return [[
            'balance'  => $group['balance'],
            'bal_side' => $rowSide === $group['request_side'] ? 0 : 1,
            'acc_side' => $rowSide,
            'sign'     => 1.0,
        ]];
    }

    /**
     * Krok 3: řádky nastavení (účet + strana + znaménko), platné k datu.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $accounts
     * @return list<array{balance: int, bal_side: int, acc_side: int, sign: float}>
     */
    private function settingsCandidates(array $row, string $accountNumber, string $accountingDate, array $accounts): array
    {
        $out = [];
        foreach ($accounts as $acc) {
            if (!$this->matchesPrefix($acc, $accountNumber) || !$this->validAt($acc, $accountingDate)) {
                continue;
            }
            $accSide  = (int) ($acc['acc_side'] ?? 0);
            $activeHc = (float) ($accSide === 0 ? ($row['money_dr'] ?? 0) : ($row['money_cr'] ?? 0));
            $activeCur = (float) ($accSide === 0 ? ($row['money_dr_cur'] ?? 0) : ($row['money_cr_cur'] ?? 0));

            // Jednostranný řádek: bere se jen strana, kterou účet sleduje.
            if ($activeHc === 0.0 && $activeCur === 0.0) {
                continue;
            }
            if (!$this->passesAmountsSign((int) ($acc['amounts_sign'] ?? 0), $activeHc)) {
                continue;
            }
            $out[] = [
                'balance'  => (int) $acc['balance'],
                'bal_side' => (int) ($acc['bal_side'] ?? 0),
                'acc_side' => $accSide,
                'sign'     => !empty($acc['modify_sign']) ? -1.0 : 1.0,
            ];
        }
        return $out;
    }

    /**
     * Skupina pro operaci se stranou: první řádek nastavení s předpisem
     * (bal_side 0, bez modify_sign) na dané straně, jehož prefix sedí.
     *
     * @param list<array<string, mixed>> $accounts
     * @return array{balance: int, request_side: int}|null
     */
    private function requestGroup(int $side, string $accountNumber, string $accountingDate, array $accounts): ?array
    {
        foreach ($accounts as $acc) {
            if ((int) ($acc['bal_side'] ?? 0) !== 0 || !empty($acc['modify_sign'])
                || (int) ($acc['acc_side'] ?? 0) !== $side) {
                continue;
            }
            if (!$this->matchesPrefix($acc, $accountNumber) || !$this->validAt($acc, $accountingDate)) {
                continue;
            }
            return ['balance' => (int) $acc['balance'], 'request_side' => $side];
        }
        return null;
    }

    /**
     * Skupina pro `payment.*`: první řádek nastavení bez modify_sign, jehož
     * prefix sedí; strana úhrady skupiny pro ten prefix = acc_side jejího
     * řádku úhrady (bal_side 1), jinak opačná k jejímu předpisu.
     *
     * @param list<array<string, mixed>> $accounts
     * @return array{balance: int, payment_side: int}|null
     */
    private function paymentGroup(string $accountNumber, string $accountingDate, array $accounts): ?array
    {
        $balance = null;
        $paymentSide = null;
        $requestSide = null;
        foreach ($accounts as $acc) {
            if (!empty($acc['modify_sign'])
                || !$this->matchesPrefix($acc, $accountNumber) || !$this->validAt($acc, $accountingDate)) {
                continue;
            }
            $balance ??= (int) $acc['balance'];
            if ((int) $acc['balance'] !== $balance) {
                continue;
            }
            if ((int) ($acc['bal_side'] ?? 0) === 1) {
                $paymentSide ??= (int) ($acc['acc_side'] ?? 0);
            } else {
                $requestSide ??= (int) ($acc['acc_side'] ?? 0);
            }
        }
        if ($balance === null) {
            return null;
        }
        return ['balance' => $balance, 'payment_side' => $paymentSide ?? 1 - (int) $requestSide];
    }

    /**
     * Přičte kandidáta do desired setu: částky ze strany řádku, kterou
     * kandidát sleduje, znaménko dle kandidáta, agregace shodného klíče.
     *
     * @param array<string, array<string, mixed>> $desired
     * @param array<string, mixed> $row
     * @param array{balance: int, bal_side: int, acc_side: int, sign: float} $candidate
     */
    private function addCandidate(
        array &$desired,
        string $sourceKind,
        int $sourceId,
        array $row,
        array $candidate,
        string $homeCurrency,
    ): void {
        $accSide   = $candidate['acc_side'];
        $activeHc  = (float) ($accSide === 0 ? ($row['money_dr'] ?? 0) : ($row['money_cr'] ?? 0));
        $activeCur = (float) ($accSide === 0 ? ($row['money_dr_cur'] ?? 0) : ($row['money_cr_cur'] ?? 0));
        if ($activeHc === 0.0 && $activeCur === 0.0) {
            return;
        }
        $sign = $candidate['sign'];

        // Klíč pohybu = zdroj + platební identita řádku (D13). Klíč
        // případu se normalizuje při zápisu (#69 D10): symboly TRIM,
        // prázdné → NULL, měna malými písmeny — rovnost klíče pak jde
        // přes idx_case (CaseQuery); tytéž hodnoty vstupují do hashe.
        $identity = [
            'source_kind'       => $sourceKind,
            'source_id'         => $sourceId,
            'balance'           => $candidate['balance'],
            'bal_side'          => $candidate['bal_side'],
            'account_number'    => (string) ($row['account_number'] ?? ''),
            'partner'           => isset($row['partner']) && $row['partner'] !== null ? (int) $row['partner'] : null,
            'payment_reference' => CaseQuery::normalizeSymbol($row['payment_reference'] ?? null),
            'specific_symbol'   => CaseQuery::normalizeSymbol($row['specific_symbol'] ?? null),
            'currency'          => CaseQuery::normalizeCurrency($row['currency'] ?? null),
        ];
        $key = self::movementKey($identity);

        if (!isset($desired[$key])) {
            // Denorm z prvního řádku skupiny: journal_row, KS, splatnost, text.
            $desired[$key] = $identity + [
                'movement_key'      => $key,
                'doc_head'          => $sourceKind === 'doc' ? $sourceId : null,
                'bank_transaction'  => $sourceKind === 'bankTransaction' ? $sourceId : null,
                'journal_row'       => (int) $row['id'],
                'fiscal_year'       => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : null,
                'constant_symbol'   => CaseQuery::normalizeSymbol($row['constant_symbol'] ?? null),
                'due_date'          => $row['due_date'] ?? null,
                'home_currency'     => $homeCurrency,
                'amount'            => 0.0,
                'amount_hc'         => 0.0,
                'text'              => $row['text'] ?? null,
            ];
        }

        // Agregace shodného klíče (stejná identita v jednom zdroji) — součet částek.
        $desired[$key]['amount']    = round($desired[$key]['amount'] + $activeCur * $sign, 2);
        $desired[$key]['amount_hc'] = round($desired[$key]['amount_hc'] + $activeHc * $sign, 2);
    }

    /** Strana jednostranného řádku deníku: 0 = MD, 1 = DAL, null = bez částky. */
    private static function rowSide(array $row): ?int
    {
        if ((float) ($row['money_dr'] ?? 0) !== 0.0 || (float) ($row['money_dr_cur'] ?? 0) !== 0.0) {
            return 0;
        }
        if ((float) ($row['money_cr'] ?? 0) !== 0.0 || (float) ($row['money_cr_cur'] ?? 0) !== 0.0) {
            return 1;
        }
        return null;
    }

    /** account_number nastavení je prefix účtu ('311' chytí '311100'). */
    private function matchesPrefix(array $acc, string $accountNumber): bool
    {
        $prefix = (string) ($acc['account_number'] ?? '');
        return $prefix !== '' && str_starts_with($accountNumber, $prefix);
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
     * Sync desired setu se stavem zdroje v ledgeru podle `movement_key`:
     * DELETE pohybů mimo desired (vč. řádků z doby před D13 s NULL klíčem),
     * UPDATE shodných klíčů (id zachováno), INSERT nových. Vše v jedné
     * transakci; DELETE jde první, aby se nový pohyb nepotkal se starým
     * řádkem téhož zdroje. Dry-run jen spočítá diff.
     *
     * @param array<string, array<string, mixed>> $desired
     * @return array{inserted: int, updated: int, deleted: int}
     */
    private function sync(string $sourceKind, int $sourceId, array $desired, bool $dryRun): array
    {
        $existing = $this->db->fetchAll(
            'SELECT [id], [movement_key]
             FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i',
            $sourceKind,
            $sourceId,
        );

        $existingByKey = [];
        $orphanIds = [];
        foreach ($existing as $row) {
            $key = $row['movement_key'];
            if ($key !== null && isset($desired[$key]) && !isset($existingByKey[$key])) {
                $existingByKey[$key] = (int) $row['id'];
            } else {
                $orphanIds[] = (int) $row['id'];
            }
        }

        $stats = [
            'inserted' => count($desired) - count($existingByKey),
            'updated'  => count($existingByKey),
            'deleted'  => count($orphanIds),
        ];
        if ($dryRun) {
            return $stats;
        }

        $this->db->begin();
        try {
            foreach ($orphanIds as $id) {
                $this->db->delete('economy_accbal_ledger')->where('[id] = %i', $id)->execute();
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

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $stats;
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return $value !== null ? (string) $value : '';
    }
}
