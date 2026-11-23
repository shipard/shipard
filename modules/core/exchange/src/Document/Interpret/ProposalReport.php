<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;

/**
 * Zpráva o dokladu z **papírové podoby** pro náhled (#166 D51, U3): bloky
 * `_resolve`, které review návrhu ukazuje navíc k výsledku resolve —
 * efektivní položka a účet řádku, auditní stopa obohacení řádků, porovnání
 * částky z papíru s částkou, která skončí na dokladu. Jen čte.
 */
final class ProposalReport
{
    /** Volba `useExisting:<id>` smí mířit i na archiv (70); jen ne na Smazáno (90). */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /**
     * D4 (tasks/exchange-preview-vat-recompute.md): rozdíl částky k úhradě
     * mezi dokladem dodavatele a skutečným výpočtem, od kterého náhled
     * hlásí `computed_total_mismatch`. `computed` už nese zaokrouhlení
     * podle {@see RoundingInterpretation::deriveTotalRoundingMode()},
     * takže stačí haléř.
     */
    private const COMPUTED_TOTAL_TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $db,
        /**
         * `economy_items.accounting_account` existuje (extension
         * `economy.accounting`) — účet položky pro `effectiveAccount`
         * (#111 D7b). False = zdroj dat bez účetnictví: účet z položky se
         * nedohledává, `effectiveAccount` z položky je null.
         */
        private readonly bool $itemsHaveAccountingAccount = false,
    ) {
    }

    /**
     * Zpráva návrhu doplněná o blok `computed` — rekapitulaci, součty a ceny
     * řádků, **které skončí na dokladu**. Počítá je pokyn nad hotovým
     * dokladem (jeho náhled), interpretace k nim přidá, co ví jen ona:
     *
     *  - indexy řádků převede zpět do původního payloadu (`rowMap`);
     *  - `recapFallback` je důvod, proč se rekapitulace z papíru nepřevzala;
     *  - skutečný výpočet nahrazuje heuristiku validátoru (`totals_mismatch`
     *    se vyřadí) a částka z papíru se s ním porovná
     *    ({@see computedTotalIssue()}).
     *
     * @param array<string, mixed> $instructionResolve `_resolve` náhledu pokynu
     *        nad `InterpretedDocument::$document`
     * @return array<string, mixed> `_resolve` náhledu návrhu
     */
    public static function withComputed(InterpretedDocument $interpreted, array $instructionResolve): array
    {
        $report = $interpreted->report;
        $issues = $report['issues'] ?? [];
        $computed = $instructionResolve['computed'] ?? null;

        if (!is_array($computed)) {
            // Výpočet se nepovedl — pokyn to říká sám; náhled ukáže údaje z papíru.
            foreach ($instructionResolve['issues'] ?? [] as $issue) {
                if (($issue['code'] ?? null) === 'computed_unavailable') {
                    $issues[] = $issue;
                }
            }
            $report['computed'] = null;
        } else {
            foreach ($computed['rows'] ?? [] as $position => $row) {
                $computed['rows'][$position]['index'] = $interpreted->rowMap[$row['index']] ?? $row['index'];
            }
            $computed['recapFallback'] = $interpreted->recapFallback;
            $report['computed'] = $computed;

            $issues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => ($issue['code'] ?? null) !== 'totals_mismatch',
            ));
            $mismatch = self::computedTotalIssue($interpreted->paper, (float) ($computed['totals']['totalAmount'] ?? 0));
            if ($mismatch !== null) {
                $issues[] = $mismatch;
            }
        }

        $report['issues'] = $issues;
        $blocks = $report;
        unset($blocks['issues'], $blocks['summary'], $blocks['computed']);
        $report['summary'] = PayloadResolve::summary($blocks, $issues);
        return $report;
    }

    /**
     * `_resolve` odpovědi **uložení** návrhu: bloky zprávy (po uložení se
     * založenými záznamy jako nalezenými), nálezy a souhrn. Zpráva pro
     * uložení bloky `item.display` a `effectiveAccount` nenese — ty patří
     * náhledu ({@see DocumentInterpreter::interpret()} s `$saving`).
     *
     * @param list<array<string, mixed>> $issues nálezy odpovědi, cesty do původního payloadu
     * @param array<string, mixed> $created id záznamů založených z `pending`
     *        ({@see PendingRecords::create()}); prázdné = nic se nezaložilo
     * @return array<string, mixed>
     */
    public static function forSave(InterpretedDocument $interpreted, array $issues, array $created = []): array
    {
        $report = $interpreted->report;
        unset($report['issues'], $report['summary']);
        $summaryBlocks = self::withCreated($report, $created);
        return $summaryBlocks + ['issues' => $issues, 'summary' => PayloadResolve::summary($summaryBlocks, $issues)];
    }

    /**
     * Nálezy pokynu nad hotovým dokladem, které zpráva návrhu ještě nenese
     * (#166 U1): cesty řádků převedené přes `rowMap` na původní payload.
     * Co interpretace hlásí sama (sdílené kontroly payloadu běží v obou),
     * se neopakuje — shoda kódu a cesty; `resolve_ignored` a výchozí hodnoty
     * pokynu do odpovědi návrhu nepatří.
     *
     * @param list<array<string, mixed>> $known nálezy, které odpověď už má
     * @param list<array<string, mixed>> $instructionIssues `_resolve.issues` odpovědi pokynu
     * @return list<array<string, mixed>>
     */
    public static function instructionIssues(InterpretedDocument $interpreted, array $known, array $instructionIssues): array
    {
        $seen = [];
        foreach ($known as $issue) {
            $seen[($issue['code'] ?? '') . '@' . ($issue['path'] ?? '')] = true;
        }
        $out = [];
        foreach ($instructionIssues as $issue) {
            if (!is_array($issue) || ($issue['code'] ?? null) === 'resolve_ignored') {
                continue;
            }
            $issue['path'] = self::originalPath((string) ($issue['path'] ?? ''), $interpreted->rowMap);
            $key = ($issue['code'] ?? '') . '@' . $issue['path'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $issue;
        }
        return $out;
    }

    /**
     * Cesta v hotovém dokladu → cesta v původním payloadu: vynechané řádky
     * posouvají indexy (`rowMap`), ostatní cesty jsou shodné.
     *
     * @param list<int> $rowMap
     */
    public static function originalPath(string $path, array $rowMap): string
    {
        if (preg_match('/^rows\.(\d+)(\..*)?$/', $path, $m) !== 1) {
            return $path;
        }
        return 'rows.' . ($rowMap[(int) $m[1]] ?? (int) $m[1]) . ($m[2] ?? '');
    }

    /**
     * Bloky po uložení: záznam, který se založil, je nalezený
     * (`matchedBy: "created"`) s novým id.
     *
     * @param array<string, mixed> $resolved bloky `_resolve`
     * @param array<string, mixed> $created id založených záznamů ({@see PendingRecords::create()})
     * @return array<string, mixed>
     */
    public static function withCreated(array $resolved, array $created): array
    {
        foreach (['supplier', 'customer', 'balanceParty', 'supplierBank'] as $key) {
            if (($resolved[$key]['status'] ?? null) === 'canCreate' && ($created[$key] ?? null) !== null) {
                $resolved[$key]['status'] = 'matched';
                $resolved[$key]['matchedId'] = $created[$key];
                $resolved[$key]['matchedBy'] = 'created';
            }
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowR) {
            if (($rowR['item']['status'] ?? null) === 'canCreate' && isset($created['rowItems'][$i])) {
                $resolved['rows'][$i]['item']['status'] = 'matched';
                $resolved['rows'][$i]['item']['matchedId'] = $created['rowItems'][$i];
                $resolved['rows'][$i]['item']['matchedBy'] = 'created';
            }
        }
        return $resolved;
    }

    /**
     * D4: částka k úhradě z papíru proti částce, která skončí na dokladu
     * → warning `computed_total_mismatch` (u samovyměření se liší daň,
     * k úhradě sedí). Bez `totals.totalAmount` se neporovnává. V pokynu jsou
     * `totals` kontrolní součet (`TotalsCheck`), ne signál.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>|null
     */
    public static function computedTotalIssue(array $canonical, float $computedTotal): ?array
    {
        $declared = $canonical['totals']['totalAmount'] ?? null;
        if ($declared === null || !is_numeric($declared)) {
            return null;
        }
        $declaredF = round((float) $declared, 2);
        $computedF = $computedTotal;
        if (abs($declaredF - $computedF) <= self::COMPUTED_TOTAL_TOLERANCE) {
            return null;
        }
        return [
            'severity' => 'warning',
            'path'     => 'totals.totalAmount',
            'code'     => 'computed_total_mismatch',
            'message'  => "Částka k úhradě na dokladu dodavatele {$declaredF} se liší od částky,"
                . " která skončí na dokladu ({$computedF}) — zkontroluj řádky a režim DPH.",
            'declared' => $declaredF,
            'computed' => $computedF,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $freshRows
     * @return array<int, array<string, mixed>>
     */
    public static function carryOverEnrichment(mixed $previousRows, array $freshRows): array
    {
        if (!is_array($previousRows) || $previousRows === []) {
            return $freshRows;
        }
        $byIndex = [];
        foreach ($previousRows as $entry) {
            if (is_array($entry) && isset($entry['index']) && is_array($entry['enrichment'] ?? null)) {
                $byIndex[(int) $entry['index']] = $entry['enrichment'];
            }
        }
        if ($byIndex === []) {
            return $freshRows;
        }
        foreach ($freshRows as $pos => $entry) {
            $idx = $entry['index'] ?? null;
            if (is_int($idx) && isset($byIndex[$idx])) {
                $freshRows[$pos]['enrichment'] = $byIndex[$idx];
            }
        }
        return $freshRows;
    }

    /**
     * Doplní do `_resolve.rows[*]` bloky pro review modal (#111 D3, D7b),
     * jen `/preview`:
     *
     *  - `item.display` = `{id, code, name, pinned}` efektivní položky:
     *    uložená volba `useExisting:<id>` (`pinned: true`) má přednost před
     *    automatickým napárováním (`status: matched`, `pinned: false`).
     *    Volba na neexistující nebo smazanou položku → klíč chybí (apply ji
     *    stejně odmítne `conflict`). `skip` a nerozhodnutý nenapárovaný
     *    řádek `display` nemají.
     *  - `effectiveAccount` = účet, podle kterého se řádek zaúčtuje, nebo
     *    návrh účtu řádku; `null` = sloupec Účet ukáže „—“. Klíč je na
     *    každém řádku. Volba `skip` → null; kontační řádek (`accSide`) →
     *    účet řádku (`source: "row"`); efektivní položka →
     *    její účet, jen u účetní položky (`item_type` 2) s vyplněným
     *    `accounting_account` (`source: "item"`), jinak null — účet doplněný
     *    historií nebo štítkem se na řádek s položkou při apply nezapíše
     *    (D7b, {@see RecordMatching::reconcile()}); bez efektivní položky (nerozhodnuto,
     *    `canCreate`, `ambiguous`, `notFound`, pin na neexistující id) →
     *    účet řádku jako návrh (`source: "row"`).
     *
     *  - `item.matchedDisplay` = `{id, code, name}` automaticky napárované
     *    položky u každého bloku `status: matched`, nezávisle na volbě
     *    (#111 D10) — panel „Napárováno automaticky“ i po ruční volbě.
     *    Smazaná položka klíč nemá (klient ukáže `#id`).
     *
     * Volby se čtou stejně jako v {@see RecordMatching::reconcile()} — pozičně z
     * `_resolve.rows[$pos].item.userAction`; vynechané řádky dává
     * `$rowSkips` ({@see skippedRowIndices}). Dva dotazy nezávisle na počtu
     * řádků: položky (efektivní i napárované, s LEFT JOIN na účty, jen když
     * sloupec `accounting_account` existuje) a účty řádků (název).
     *
     * @param array<string, mixed> $canonical Vstup s klientským `_resolve`.
     * @param array<string, mixed> $resolved  Výstup {@see resolveAll}.
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array<string, mixed>
     */
    public function annotateRowDisplay(array $canonical, array $resolved, array $rowSkips): array
    {
        $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
        if ($rows === []) {
            return $resolved;
        }
        $canonicalRows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $clientRows = is_array($canonical['_resolve']['rows'] ?? null) ? $canonical['_resolve']['rows'] : [];

        // 1. Per řádek: volba, efektivní a napárovaná položka, id účtu řádku.
        $decisions = [];
        $effectiveItems = [];
        $matchedItems = [];
        $itemIds = [];
        $accountIds = [];
        foreach ($rows as $pos => $rowResolve) {
            if (!is_array($rowResolve)) {
                continue;
            }
            $clientRow = is_array($clientRows[$pos] ?? null) ? $clientRows[$pos] : [];
            $itemAction = is_array($clientRow['item'] ?? null) ? ($clientRow['item']['userAction'] ?? null) : null;
            $decision = null;
            $itemId = null;
            // Automatické napárování nezávisle na volbě (D10).
            $matchedId = null;
            if (($rowResolve['item']['status'] ?? null) === 'matched') {
                $candidate = $rowResolve['item']['matchedId'] ?? null;
                $matchedId = is_int($candidate) && $candidate > 0 ? $candidate : null;
            }
            if (in_array($pos, $rowSkips, true)) {
                // Řádková i položková volba skip ({@see skippedRowIndices}).
                $decision = 'skip';
            } elseif (!isset($rowResolve['item'])) {
                // Řádek bez bloku položky (text, kontace) — volby položky
                // reconcile ignoruje, tady také.
            } elseif (is_string($itemAction) && str_starts_with($itemAction, 'useExisting:')) {
                $idStr = substr($itemAction, strlen('useExisting:'));
                if (ctype_digit($idStr) && (int) $idStr > 0) {
                    $decision = 'useExisting';
                    $itemId = (int) $idStr;
                }
            }
            if ($decision === null) {
                $itemId = $matchedId;
            }
            $decisions[$pos] = $decision;
            $effectiveItems[$pos] = $itemId;
            $matchedItems[$pos] = $matchedId;
            if ($itemId !== null) {
                $itemIds[] = $itemId;
            }
            if ($matchedId !== null) {
                $itemIds[] = $matchedId;
            }
            if (($rowResolve['account']['status'] ?? null) === 'matched' && isset($rowResolve['account']['matchedId'])) {
                $accountIds[] = (int) $rowResolve['account']['matchedId'];
            }
        }

        $items = $this->fetchItemsForDisplay(array_values(array_unique($itemIds)));
        $accounts = $this->fetchAccountsForDisplay(array_values(array_unique($accountIds)));

        // 2. Zápis bloků.
        foreach ($rows as $pos => $rowResolve) {
            if (!is_array($rowResolve)) {
                continue;
            }
            $decision = $decisions[$pos] ?? null;
            $item = ($effectiveItems[$pos] ?? null) !== null ? ($items[$effectiveItems[$pos]] ?? null) : null;
            if ($item !== null) {
                $resolved['rows'][$pos]['item']['display'] = [
                    'id'     => $item['id'],
                    'code'   => $item['code'],
                    'name'   => $item['name'],
                    'pinned' => $decision === 'useExisting',
                ];
            }
            $matched = ($matchedItems[$pos] ?? null) !== null ? ($items[$matchedItems[$pos]] ?? null) : null;
            if ($matched !== null) {
                $resolved['rows'][$pos]['item']['matchedDisplay'] = [
                    'id'   => $matched['id'],
                    'code' => $matched['code'],
                    'name' => $matched['name'],
                ];
            }

            $rowAccount = null;
            if (($rowResolve['account']['status'] ?? null) === 'matched' && isset($rowResolve['account']['matchedId'])) {
                $accId = (int) $rowResolve['account']['matchedId'];
                $rowAccount = [
                    'id'     => $accId,
                    'number' => (string) ($rowResolve['account']['number'] ?? ($accounts[$accId]['number'] ?? '')),
                    'name'   => $accounts[$accId]['name'] ?? null,
                    'source' => 'row',
                ];
            }
            $canonicalRow = $canonicalRows[$rowResolve['index'] ?? $pos] ?? null;
            $contation = is_array($canonicalRow) && isset($canonicalRow['accSide']);

            if ($decision === 'skip') {
                $effective = null;
            } elseif ($contation) {
                $effective = $rowAccount;
            } elseif ($item !== null) {
                $effective = $item['item_type'] === 2 && $item['account_id'] !== null
                    ? [
                        'id'     => $item['account_id'],
                        'number' => (string) $item['account_number'],
                        'name'   => $item['account_name'],
                        'source' => 'item',
                    ]
                    : null;
            } else {
                $effective = $rowAccount;
            }
            $resolved['rows'][$pos]['effectiveAccount'] = $effective;
        }
        return $resolved;
    }

    /**
     * Položky pro `display` / `effectiveAccount` jedním dotazem; účet jen
     * s extension `economy.accounting` (jinak `account_*` null).
     *
     * @param list<int> $itemIds
     * @return array<int, array{id: int, code: string, name: string, item_type: int, account_id: ?int, account_number: ?string, account_name: ?string}>
     */
    private function fetchItemsForDisplay(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $rows = $this->itemsHaveAccountingAccount
            ? $this->db->fetchAll(
                'SELECT [i.id], [i.code], [i.name], [i.item_type],
                        [a.id] AS [account_id], [a.number] AS [account_number], [a.name] AS [account_name]
                 FROM [economy_items] AS [i]
                 LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [i.accounting_account]
                 WHERE [i.id] IN %in AND [i.docState] IN %in',
                $itemIds, self::LINKABLE_STATES,
            )
            : $this->db->fetchAll(
                'SELECT [i.id], [i.code], [i.name], [i.item_type]
                 FROM [economy_items] AS [i]
                 WHERE [i.id] IN %in AND [i.docState] IN %in',
                $itemIds, self::LINKABLE_STATES,
            );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $accountId = isset($row['account_id']) ? (int) $row['account_id'] : null;
            $out[$id] = [
                'id'             => $id,
                'code'           => (string) ($row['code'] ?? ''),
                'name'           => (string) ($row['name'] ?? ''),
                'item_type'      => (int) ($row['item_type'] ?? 0),
                'account_id'     => $accountId,
                'account_number' => $accountId !== null ? (string) ($row['account_number'] ?? '') : null,
                'account_name'   => $accountId !== null && isset($row['account_name']) ? (string) $row['account_name'] : null,
            ];
        }
        return $out;
    }

    /**
     * Názvy účtů řádků (`_resolve.rows[*].account`) jedním dotazem.
     *
     * @param list<int> $accountIds
     * @return array<int, array{number: string, name: ?string}>
     */
    private function fetchAccountsForDisplay(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [number], [name] FROM [economy_accounting_accounts] WHERE [id] IN %in',
            $accountIds,
        ) as $row) {
            $out[(int) $row['id']] = [
                'number' => (string) ($row['number'] ?? ''),
                'name'   => isset($row['name']) ? (string) $row['name'] : null,
            ];
        }
        return $out;
    }
}
