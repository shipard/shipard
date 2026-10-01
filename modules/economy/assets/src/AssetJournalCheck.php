<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Posting\AssetPostingBuilder;
use Shipard\Module\Economy\Assets\Posting\AssetPostingInput;

/**
 * Kontrola evidence majetku proti deníku (docs/assets.md invariant §1,
 * D67) — sdílí ji report „Kontrola evidence × deník“, alerty a varování
 * na kartě.
 *
 * Co má být v deníku, počítá **z událostí samotných** (tabulka D49:
 * zařazení a TZ MD majetek / DAL pořízení, snížení obráceně, účetní odpis
 * MD odpisy / DAL oprávky, vyřazení MD oprávky + MD zůstatková cena /
 * DAL majetek), ne přes `AssetPostingBuilder` ani plánovač — kontrola je
 * tak nezávislá na kódu, který účtuje, a nepotřebuje pravidla země.
 *
 * Nesoulady po kartách:
 *  - `posting` (a) — zaúčtované události ≠ řádky deníku operací `asset.*`
 *    s dimenzí karty, po účtech a stranách;
 *  - `acquisition` (b) — pořízení na účtu pořízení (04x) s dimenzí karty
 *    ≠ potvrzená zařazení + TZ − snížení; jen u karet, které nějaké
 *    pořízení na dokladech mají. Počítá se z potvrzených událostí, ne ze
 *    zůstatku účtu — nezaúčtované zařazení nesoulad není;
 *  - `unposted` (c) — potvrzené účtovatelné události bez dokladu.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetJournalCheck
{
    /** Po kolika dnech je rozdíl pořízení × zařazení nesouladem k řešení (D67). */
    public const ACQUISITION_DAYS = 30;

    public const ROLES = [
        AssetPostingInput::ACCOUNT_ASSET,
        AssetPostingInput::ACCOUNT_ACQUISITION,
        AssetPostingInput::ACCOUNT_ACCUMULATED,
        AssetPostingInput::ACCOUNT_DEPRECIATION,
        AssetPostingInput::ACCOUNT_DISPOSAL,
    ];

    /** Účty se stavem (porovnává se konečný zůstatek); ostatní role obratem roku. */
    public const BALANCE_ROLES = [
        AssetPostingInput::ACCOUNT_ASSET,
        AssetPostingInput::ACCOUNT_ACQUISITION,
        AssetPostingInput::ACCOUNT_ACCUMULATED,
    ];

    /** Součty deníku účtu bez jediného zápisu. */
    private const NO_JOURNAL = ['opening' => 0.0, 'turnover' => 0.0, 'withAsset' => 0.0, 'withoutAsset' => 0.0, 'withoutAssetRows' => 0, 'rows' => 0];

    /** Operace vlastního zaúčtování majetku v deníku. */
    public const SYSTEM_OPERATIONS = 'asset.%';

    public function __construct(protected readonly ?\Dibi\Connection $db)
    {
    }

    // ── Po kartách ──────────────────────────────────────────────────────────

    /**
     * Nesoulady (a) a (b) karet k datu (`null` = bez omezení), od karty
     * s nejnižším inventárním číslem.
     *
     * @return array{
     *     posting: list<array{assetId: int, number: string, name: string,
     *         accounts: list<array{account: string, expectedDr: float, expectedCr: float, journalDr: float, journalCr: float}>}>,
     *     acquisition: list<array{assetId: int, number: string, name: string, acquired: float, activated: float,
     *         difference: float, started: bool, since: ?string}>,
     * }
     */
    public function cardFindings(?string $asOf = null, ?int $assetId = null): array
    {
        $cards = $this->loadCards($assetId);
        $ledgers = $this->ledgers($cards, $this->loadEvents($assetId), $asOf);
        $journal = $this->loadPostingJournal($asOf, $assetId);
        $acquisitions = $this->loadAcquisitionJournal($asOf, $assetId);

        $posting = [];
        $acquisition = [];
        foreach ($cards as $id => $card) {
            $ledger = $ledgers[$id];

            // (a) zaúčtované události × řádky deníku `asset.*` karty.
            $expected = [];
            foreach ($ledger['entries'] as $entry) {
                $account = $card['accounts'][$entry['role']] ?? '';
                $expected[$account] ??= ['dr' => 0.0, 'cr' => 0.0];
                $expected[$account]['dr'] += $entry['dr'];
                $expected[$account]['cr'] += $entry['cr'];
            }
            $actual = $journal[$id] ?? [];
            $accounts = [];
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $account) {
                $account = (string) $account;
                $row = [
                    'account'    => $account,
                    'expectedDr' => round($expected[$account]['dr'] ?? 0.0, 2),
                    'expectedCr' => round($expected[$account]['cr'] ?? 0.0, 2),
                    'journalDr'  => round($actual[$account]['dr'] ?? 0.0, 2),
                    'journalCr'  => round($actual[$account]['cr'] ?? 0.0, 2),
                ];
                if (self::differs($row['expectedDr'], $row['journalDr']) || self::differs($row['expectedCr'], $row['journalCr'])) {
                    $accounts[] = $row;
                }
            }
            if ($accounts !== []) {
                usort($accounts, static fn(array $a, array $b): int => strcmp($a['account'], $b['account']));
                $posting[] = self::identity($card) + ['accounts' => $accounts];
            }

            // (b) pořízení na 04x s dimenzí karty × zařazení + TZ − snížení.
            if (isset($acquisitions[$id])) {
                $acquired = round($acquisitions[$id]['amount'], 2);
                $activated = round($ledger['activated'], 2);
                if (self::differs($acquired, $activated)) {
                    $dates = array_filter([$acquisitions[$id]['lastDate'], $ledger['lastValueDate']]);
                    $acquisition[] = self::identity($card) + [
                        'acquired'   => $acquired,
                        'activated'  => $activated,
                        'difference' => round($acquired - $activated, 2),
                        'started'    => $ledger['started'],
                        'since'      => $dates === [] ? null : max($dates),
                    ];
                }
            }
        }
        // Řádky deníku `asset.*` s kartou, kterou evidence nezná (smazaná karta).
        foreach ($journal as $id => $accounts) {
            if (isset($cards[$id])) {
                continue;
            }
            $rows = [];
            foreach ($accounts as $account => $sums) {
                $rows[] = [
                    'account'    => (string) $account,
                    'expectedDr' => 0.0,
                    'expectedCr' => 0.0,
                    'journalDr'  => round($sums['dr'], 2),
                    'journalCr'  => round($sums['cr'], 2),
                ];
            }
            $posting[] = ['assetId' => (int) $id, 'number' => '', 'name' => '#' . (int) $id, 'accounts' => $rows];
        }

        return ['posting' => $posting, 'acquisition' => $acquisition];
    }

    /**
     * Nesoulad (c): potvrzené účtovatelné události bez živého dokladu
     * s datem do `$until` (konec posledního období, které už mělo být
     * zaúčtované).
     *
     * @return list<array{assetId: int, number: string, name: string, eventId: int, kind: string, date: string, amount: float}>
     */
    public function unposted(string $until, ?int $assetId = null): array
    {
        $cards = $this->loadCards($assetId);
        $out = [];
        foreach ($this->loadEvents($assetId) as $event) {
            $id = (int) $event['asset'];
            $date = substr((string) $event['event_date'], 0, 10);
            if (!isset($cards[$id]) || !empty($event['posted']) || $date > $until
                || !AssetPostingBuilder::isPostable((string) $event['event_kind'], (string) $event['scope'])
            ) {
                continue;
            }
            $out[] = self::identity($cards[$id]) + [
                'eventId' => (int) $event['id'],
                'kind'    => (string) $event['event_kind'],
                'date'    => $date,
                'amount'  => round((float) $event['amount'], 2),
            ];
        }
        return $out;
    }

    /** Je nesoulad pořízení starší než lhůta (D67)? Bez data se bere jako starý. */
    public static function isOverdue(?string $since, string $today, int $days = self::ACQUISITION_DAYS): bool
    {
        if ($since === null) {
            return true;
        }
        return $since < (new \DateTimeImmutable($today))->modify("-{$days} days")->format('Y-m-d');
    }

    // ── Po účtech ───────────────────────────────────────────────────────────

    /**
     * Účty účetních skupin za účetní rok: stav / obrat podle evidence proti
     * deníku. Účty majetku, pořízení a oprávek se porovnávají konečným
     * zůstatkem roku (otevírací období + běžné měsíce), účty odpisů
     * a zůstatkové ceny obratem roku.
     *
     * Evidence = počáteční stavy + **zaúčtované** události do konce roku
     * (nezaúčtované hlásí `unposted()`); u účtu pořízení pořízení s kartou
     * z deníku mínus zaúčtovaná zařazení a TZ. `withAsset` / `withoutAsset`
     * je obrat běžných měsíců roku s kartou a bez ní — zápis bez karty
     * na účtu majetku evidence nevidí.
     *
     * Účet bez stavu v evidenci a bez jediného zápisu v deníku roku se vynechá.
     *
     * @param list<int> $openingMonthIds účetní měsíce před běžnými (otevírací období)
     * @param list<int> $regularMonthIds běžné účetní měsíce roku
     * @return list<array{account: string, name: string, role: string, balance: bool, evidence: float, journal: float,
     *     difference: float, withAsset: float, withoutAsset: float, withoutAssetRows: int}>
     */
    public function accounts(string $yearBegin, string $yearEnd, int $fiscalYearId, array $openingMonthIds, array $regularMonthIds): array
    {
        $cards = $this->loadCards(null);
        $ledgers = $this->ledgers($cards, $this->loadEvents(null), $yearEnd);
        $acquisitions = $this->loadAcquisitionJournal($yearEnd, null);

        // Role účtu: z účetních skupin (i bez karet — zápis na účtu majetku
        // bez jediné karty je přesně to, co kontrola hledá).
        $roles = [];
        foreach ($this->loadGroupAccounts() as $group) {
            foreach (self::ROLES as $role) {
                $number = (string) ($group[$role] ?? '');
                if ($number !== '') {
                    $roles[$number] ??= $role;
                }
            }
        }

        $evidence = array_fill_keys(array_keys($roles), 0.0);
        foreach ($cards as $id => $card) {
            $ledger = $ledgers[$id];
            foreach (self::ROLES as $role) {
                $number = $card['accounts'][$role] ?? '';
                if ($number === '' || !isset($roles[$number])) {
                    continue;
                }
                $balance = in_array($roles[$number], self::BALANCE_ROLES, true);
                foreach ($ledger['entries'] as $entry) {
                    if ($entry['role'] === $role && ($balance || $entry['date'] >= $yearBegin)) {
                        $evidence[$number] += $entry['dr'] - $entry['cr'];
                    }
                }
                if ($role === AssetPostingInput::ACCOUNT_ASSET) {
                    $evidence[$number] += $ledger['openingEntry'];
                } elseif ($role === AssetPostingInput::ACCOUNT_ACCUMULATED) {
                    $evidence[$number] -= $ledger['openingAccumulated'];
                } elseif ($role === AssetPostingInput::ACCOUNT_ACQUISITION) {
                    $evidence[$number] += $acquisitions[$id]['amount'] ?? 0.0;
                }
            }
        }

        $opening = array_flip($openingMonthIds);
        $regular = array_flip($regularMonthIds);
        $journal = [];
        foreach ($this->loadAccountJournal($fiscalYearId, array_map(strval(...), array_keys($roles))) as $row) {
            $number = (string) $row['account_number'];
            $month = (int) $row['fiscal_month'];
            $journal[$number] ??= self::NO_JOURNAL;
            $amount = (float) $row['dr'] - (float) $row['cr'];
            if (isset($opening[$month])) {
                $journal[$number]['opening'] += $amount;
                $journal[$number]['rows'] += (int) $row['row_count'];
            } elseif (isset($regular[$month])) {
                $journal[$number]['turnover'] += $amount;
                $journal[$number]['rows'] += (int) $row['row_count'];
                if (empty($row['with_asset'])) {
                    $journal[$number]['withoutAsset'] += $amount;
                    $journal[$number]['withoutAssetRows'] += (int) $row['row_count'];
                } else {
                    $journal[$number]['withAsset'] += $amount;
                }
            }
        }

        $names = $this->loadAccountNames(array_map(strval(...), array_keys($roles)));
        $out = [];
        foreach ($roles as $number => $role) {
            $number = (string) $number;
            $balance = in_array($role, self::BALANCE_ROLES, true);
            $sums = $journal[$number] ?? self::NO_JOURNAL;
            $journalValue = round($sums['turnover'] + ($balance ? $sums['opening'] : 0.0), 2);
            $evidenceValue = round($evidence[$number], 2);
            if (!self::differs($evidenceValue, 0.0) && $sums['rows'] === 0) {
                continue;
            }
            $out[] = [
                'account'          => $number,
                'name'             => $names[$number] ?? $number,
                'role'             => $role,
                'balance'          => $balance,
                'evidence'         => $evidenceValue,
                'journal'          => $journalValue,
                'difference'       => round($evidenceValue - $journalValue, 2),
                'withAsset'        => round($sums['withAsset'], 2),
                'withoutAsset'     => round($sums['withoutAsset'], 2),
                'withoutAssetRows' => $sums['withoutAssetRows'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['account'], $b['account']));

        return $out;
    }

    // ── Evidence ────────────────────────────────────────────────────────────

    /**
     * Co události karet znamenají pro deník, k datu: zápisy zaúčtovaných
     * událostí po rolích účtů (`entries`), počáteční stav účetního okruhu
     * a součet zařazení + TZ − snížení (`activated`, potvrzené události
     * bez ohledu na zaúčtování).
     *
     * @param array<int, array<string, mixed>> $cards
     * @param list<array<string, mixed>> $events potvrzené události s příznakem `posted`
     * @return array<int, array{entries: list<array{role: string, dr: float, cr: float, date: string}>,
     *     openingEntry: float, openingAccumulated: float, activated: float, started: bool, lastValueDate: ?string}>
     */
    private function ledgers(array $cards, array $events, ?string $asOf): array
    {
        $byAsset = [];
        foreach ($events as $event) {
            $byAsset[(int) $event['asset']][] = $event;
        }

        $ledgers = [];
        foreach (array_keys($cards) as $id) {
            $own = $byAsset[$id] ?? [];
            usort($own, static fn(array $a, array $b): int => [
                substr((string) $a['event_date'], 0, 10), AssetEvent::kindOrder((string) $a['event_kind']), (int) $a['id'],
            ] <=> [
                substr((string) $b['event_date'], 0, 10), AssetEvent::kindOrder((string) $b['event_kind']), (int) $b['id'],
            ]);

            $ledger = [
                'entries' => [], 'openingEntry' => 0.0, 'openingAccumulated' => 0.0,
                'activated' => 0.0, 'started' => false, 'lastValueDate' => null,
            ];
            $entry = 0.0;
            $accumulated = 0.0;
            foreach ($own as $event) {
                $date = substr((string) $event['event_date'], 0, 10);
                if ((string) $event['scope'] === AssetEvent::SCOPE_TAX || ($asOf !== null && $date > $asOf)) {
                    continue;
                }
                $kind = (string) $event['event_kind'];
                $amount = (float) $event['amount'];
                $posted = !empty($event['posted']);
                $post = static function (string $debit, string $credit, float $value) use (&$ledger, $posted, $date): void {
                    if (!$posted || abs($value) < Amounts::EPSILON) {
                        return;
                    }
                    $ledger['entries'][] = ['role' => $debit, 'dr' => $value, 'cr' => 0.0, 'date' => $date];
                    $ledger['entries'][] = ['role' => $credit, 'dr' => 0.0, 'cr' => $value, 'date' => $date];
                };

                switch ($kind) {
                    case AssetEvent::KIND_OPENING:
                        $entry = $amount;
                        $accumulated = (float) ($event['accumulated'] ?? 0);
                        $ledger['openingEntry'] = $entry;
                        $ledger['openingAccumulated'] = $accumulated;
                        $ledger['started'] = true;
                        break;
                    case AssetEvent::KIND_ACTIVATION:
                    case AssetEvent::KIND_IMPROVEMENT:
                        $entry += $amount;
                        $ledger['activated'] += $amount;
                        $ledger['started'] = $ledger['started'] || $kind === AssetEvent::KIND_ACTIVATION;
                        $ledger['lastValueDate'] = $date;
                        $post(AssetPostingInput::ACCOUNT_ASSET, AssetPostingInput::ACCOUNT_ACQUISITION, $amount);
                        break;
                    case AssetEvent::KIND_REDUCTION:
                        $entry -= $amount;
                        $ledger['activated'] -= $amount;
                        $ledger['lastValueDate'] = $date;
                        $post(AssetPostingInput::ACCOUNT_ACQUISITION, AssetPostingInput::ACCOUNT_ASSET, $amount);
                        break;
                    case AssetEvent::KIND_DEPRECIATION:
                        $accumulated += $amount;
                        $post(AssetPostingInput::ACCOUNT_DEPRECIATION, AssetPostingInput::ACCOUNT_ACCUMULATED, $amount);
                        break;
                    case AssetEvent::KIND_DISPOSAL:
                        // Čistý zápis: oprávky a zůstatková cena proti účtu majetku.
                        $post(AssetPostingInput::ACCOUNT_ACCUMULATED, AssetPostingInput::ACCOUNT_ASSET, $accumulated);
                        $post(AssetPostingInput::ACCOUNT_DISPOSAL, AssetPostingInput::ACCOUNT_ASSET, $entry - $accumulated);
                        break;
                }
            }
            $ledgers[$id] = $ledger;
        }
        return $ledgers;
    }

    /**
     * @param array<string, mixed> $card
     * @return array{assetId: int, number: string, name: string}
     */
    private static function identity(array $card): array
    {
        return [
            'assetId' => (int) $card['id'],
            'number'  => trim((string) ($card['asset_number'] ?? '')),
            'name'    => trim((string) ($card['name'] ?? '')),
        ];
    }

    private static function differs(float $a, float $b): bool
    {
        return abs($a - $b) >= Amounts::EPSILON;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Karty mimo smazané s čísly účtů účetní skupiny (`accounts`: role →
     * číslo účtu, nevyplněný účet chybí), podle inv. čísla. Koncepty sem
     * patří — pořízení na dokladu může nést i kartu, která ještě není
     * potvrzená.
     *
     * @return array<int, array<string, mixed>> id → karta
     */
    protected function loadCards(?int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $select = '';
        $joins = '';
        foreach (self::ROLES as $role) {
            $select .= ", [acc_{$role}].[number] AS [number_{$role}]";
            $joins .= " LEFT JOIN [economy_accounting_accounts] [acc_{$role}] ON [acc_{$role}].[id] = [g].[account_{$role}]";
        }
        $args = [
            'SELECT [a].[id], [a].[asset_number], [a].[name], [a].[category]' . $select
            . ' FROM [' . AssetDocument::TABLE . '] [a]'
            . ' LEFT JOIN [economy_assets_accounting_groups] [g] ON [g].[id] = [a].[accounting_group]'
            . $joins
            . ' WHERE [a].[docState] <> %i',
            AssetDocument::STATE_DELETED,
        ];
        if ($assetId !== null) {
            array_push($args, 'AND [a].[id] = %i', $assetId);
        }
        $args[] = 'ORDER BY [a].[asset_number], [a].[id]';
        $rows = $this->db->fetchAll(...$args);
        $cards = [];
        foreach ($rows as $row) {
            $card = AssetPlanService::plain($row);
            $card['accounts'] = [];
            foreach (self::ROLES as $role) {
                $number = trim((string) ($card["number_{$role}"] ?? ''));
                if ($number !== '') {
                    $card['accounts'][$role] = $number;
                }
            }
            $cards[(int) $card['id']] = $card;
        }
        return $cards;
    }

    /**
     * Potvrzené události (všech karet nebo jedné) s příznakem `posted` —
     * navázaný doklad mimo Storno / Smazáno (D52).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadEvents(?int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $args = [
            'SELECT [e].[id], [e].[asset], [e].[event_kind], [e].[scope], [e].[event_date], [e].[amount], [e].[accumulated],'
            . ' ([h].[id] IS NOT NULL) AS [posted]'
            . ' FROM [' . AssetPlanService::EVENTS_TABLE . '] [e]'
            . ' LEFT JOIN [docs_core_heads] [h] ON [h].[id] = [e].[doc_head] AND [h].[docState] NOT IN %in',
            AssetEventDocument::DEAD_DOC_STATES,
            'WHERE [e].[docState] = %i',
            AssetPlanService::STATE_CONFIRMED,
        ];
        if ($assetId !== null) {
            array_push($args, 'AND [e].[asset] = %i', $assetId);
        }
        $args[] = 'ORDER BY [e].[asset], [e].[event_date], [e].[id]';
        $rows = $this->db->fetchAll(...$args);
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }

    /**
     * Řádky deníku operací `asset.*` s dimenzí karty, součty po kartě a účtu.
     *
     * @return array<int, array<string, array{dr: float, cr: float}>> id karty → číslo účtu → strany
     */
    protected function loadPostingJournal(?string $asOf, ?int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $args = [
            'SELECT [asset], [account_number], SUM([money_dr]) AS [dr], SUM([money_cr]) AS [cr]'
            . ' FROM [economy_accounting_journal]'
            . ' WHERE [asset] > 0 AND [operation] LIKE %s',
            self::SYSTEM_OPERATIONS,
        ];
        self::restrict($args, $asOf, $assetId);
        $args[] = 'GROUP BY [asset], [account_number]';
        $rows = $this->db->fetchAll(...$args);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['asset']][(string) $row['account_number']] = ['dr' => (float) $row['dr'], 'cr' => (float) $row['cr']];
        }
        return $out;
    }

    /**
     * Pořízení karet na účtech pořízení (04x) v deníku mimo operace
     * `asset.*`: zůstatek MD − DAL a datum posledního řádku.
     *
     * @return array<int, array{amount: float, lastDate: ?string}> id karty → pořízení
     */
    protected function loadAcquisitionJournal(?string $asOf, ?int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $args = [
            'SELECT [asset], SUM([money_dr] - [money_cr]) AS [amount], MAX([accounting_date]) AS [last_date]'
            . ' FROM [economy_accounting_journal]'
            . ' WHERE [asset] > 0 AND [account_number] LIKE %s AND ([operation] IS NULL OR [operation] NOT LIKE %s)',
            AssetAcquisitionService::ACQUISITION_ACCOUNT_PREFIX . '%',
            self::SYSTEM_OPERATIONS,
        ];
        self::restrict($args, $asOf, $assetId);
        $args[] = 'GROUP BY [asset]';
        $rows = $this->db->fetchAll(...$args);
        $out = [];
        foreach ($rows as $row) {
            $row = AssetPlanService::plain($row);
            $out[(int) $row['asset']] = [
                'amount'   => (float) $row['amount'],
                'lastDate' => ($row['last_date'] ?? null) !== null ? substr((string) $row['last_date'], 0, 10) : null,
            ];
        }
        return $out;
    }

    /**
     * Podmínky deníku „do data“ a „jen karta“.
     *
     * @param list<mixed> $args argumenty dotazu (SQL fragmenty a hodnoty)
     */
    private static function restrict(array &$args, ?string $asOf, ?int $assetId): void
    {
        if ($asOf !== null) {
            array_push($args, 'AND [accounting_date] <= %s', $asOf);
        }
        if ($assetId !== null) {
            array_push($args, 'AND [asset] = %i', $assetId);
        }
    }

    /**
     * Čísla účtů účetních skupin mimo smazané: role → číslo účtu.
     *
     * @return list<array<string, string>>
     */
    protected function loadGroupAccounts(): array
    {
        if ($this->db === null) {
            return [];
        }
        $select = [];
        $joins = '';
        foreach (self::ROLES as $role) {
            $select[] = "[acc_{$role}].[number] AS [{$role}]";
            $joins .= " LEFT JOIN [economy_accounting_accounts] [acc_{$role}] ON [acc_{$role}].[id] = [g].[account_{$role}]";
        }
        $rows = $this->db->fetchAll(
            'SELECT ' . implode(', ', $select) . ' FROM [economy_assets_accounting_groups] [g]' . $joins
            . ' WHERE [g].[docState] <> %i ORDER BY [g].[sort_order], [g].[id]',
            AssetDocument::STATE_DELETED,
        );
        return array_map(static fn(iterable $row): array => array_map(
            static fn(mixed $value): string => trim((string) ($value ?? '')),
            AssetPlanService::plain($row),
        ), $rows);
    }

    /**
     * Deník účetního roku na daných účtech: součty po účtu, účetním měsíci
     * a příznaku karty.
     *
     * @param list<string> $accountNumbers
     * @return list<array{account_number: string, fiscal_month: int, with_asset: int, dr: float, cr: float, row_count: int}>
     */
    protected function loadAccountJournal(int $fiscalYearId, array $accountNumbers): array
    {
        if ($this->db === null || $accountNumbers === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [account_number], [fiscal_month], ([asset] > 0) AS [with_asset],'
            . ' SUM([money_dr]) AS [dr], SUM([money_cr]) AS [cr], COUNT(*) AS [row_count]'
            . ' FROM [economy_accounting_journal]'
            . ' WHERE [fiscal_year] = %i AND [account_number] IN %in'
            . ' GROUP BY [account_number], [fiscal_month], ([asset] > 0)',
            $fiscalYearId,
            $accountNumbers,
        );
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }

    /**
     * @param list<string> $accountNumbers
     * @return array<string, string> číslo účtu → název
     */
    protected function loadAccountNames(array $accountNumbers): array
    {
        if ($this->db === null || $accountNumbers === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->fetchAll(
            'SELECT [number], [name] FROM [economy_accounting_accounts] WHERE [number] IN %in AND [docState] <> 90',
            $accountNumbers,
        ) as $row) {
            $names[(string) $row['number']] = (string) $row['name'];
        }
        return $names;
    }
}
