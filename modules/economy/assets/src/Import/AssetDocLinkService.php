<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Import;

use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Assets\AssetDocument;

/**
 * Doplnění karty majetku na už importované doklady (docs/assets.md D8,
 * D80; §5.7) — `POST /_exchange/assets/doc-links/apply`, jeden request =
 * jeden doklad (nové id, mapu starý → nový doklad drží runner).
 *
 * Párování řádků: kandidáti = řádky dokladu se stejným číslem účtu,
 * `vat_base_dom` = `amount` na haléř a stranou, je-li v payloadu (účetní
 * doklady; faktury a pokladní doklady stranu nemají). Jeden kandidát =
 * shoda; víc kandidátů rozhodne `orderHint` (`order_pos`), jinak
 * `ambiguous`. Každý řádek dokladu se spáruje nejvýš jednou. Řádek, který
 * už nese jinou kartu, je `conflict`; stejnou → `unchanged`.
 *
 * Doklad se mění celý, nebo vůbec (transakce): nastaví se `asset` na
 * řádcích a hlavičce; u dokladu ve stavu 40 se přegeneruje deník
 * (`AccountingEngine::accountDocument`, zapisuje přes `NestedTransaction`)
 * přes zámky měsíce a DPH stejně jako `doc-reaccount --force` — zámek se
 * nevynucuje, jen zaloguje (`warn`, důvod „assets backfill“). Pojistka:
 * součty MD / DAL po účtech dokladu před a po přegenerování se musí
 * shodovat, jinak rollback a `turnover_changed`; neúspěšné účtování
 * (`accounting_state` ≠ 1) je `accounting_failed` s rollbackem. Doklad mimo
 * stav 40 dostane jen sloupce. Nastavení „Sledovat náklady na majetek“
 * se neuplatní — sloupce existují vždy.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetDocLinkService
{
    public const STATUS_LINKED = 'linked';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_NOT_FOUND = 'notFound';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_TURNOVER_CHANGED = 'turnover_changed';
    public const STATUS_ACCOUNTING_FAILED = 'accounting_failed';
    /** Payload neodpovídá tvaru — `issues` nese nálezy. */
    public const STATUS_INVALID = 'invalid';

    public const DOC_STATE_OK = 40;
    public const ACCOUNTING_OK = 1;

    public const HEADS_TABLE = 'docs_core_heads';
    public const ROWS_TABLE = 'docs_core_rows';
    public const JOURNAL_TABLE = 'economy_accounting_journal';

    /** Strana payloadu → `acc_side` řádku (docs.core.accSides). */
    private const SIDES = ['dr' => 0, 'cr' => 1];

    private const AMOUNT_EPSILON = 0.005;

    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly ?DataSourceConfig $dsConfig,
        protected readonly ?DocumentRegistry $documents,
        protected readonly ?JournalEventDispatcher $journalEvents,
        protected readonly ?JournalContributorSet $journalContributors,
    ) {
    }

    /**
     * @param array<string, mixed> $payload {docId, headAsset?, rows: [{account, side?, amount, asset, orderHint?, sourceRef?}]}
     * @return array<string, mixed> {status, docId, rows: [{index, sourceRef, rowId, status}], head?, issues?, messages?}
     */
    public function apply(array $payload): array
    {
        $issues = $this->shapeIssues($payload);
        if ($issues !== []) {
            return ['status' => self::STATUS_INVALID, 'docId' => (int) ($payload['docId'] ?? 0), 'rows' => [], 'issues' => $issues];
        }
        $docId = (int) $payload['docId'];
        $head = $this->loadHead($docId);
        if ($head === null) {
            return ['status' => self::STATUS_NOT_FOUND, 'docId' => $docId, 'rows' => []];
        }

        $rows = $this->loadRows($docId);
        $taken = [];
        $updates = [];
        $results = [];
        $problem = null;
        foreach ($payload['rows'] as $index => $spec) {
            $sourceRef = isset($spec['sourceRef']) ? (string) $spec['sourceRef'] : null;
            $match = $this->match($spec, $rows, $taken);
            if (is_string($match)) {
                $problem ??= $match;
                $results[] = ['index' => $index, 'sourceRef' => $sourceRef, 'rowId' => null, 'status' => $match];
                continue;
            }
            $taken[(int) $match['id']] = true;
            $current = (int) ($match['asset'] ?? 0);
            $wanted = (int) $spec['asset'];
            if ($current > 0 && $current !== $wanted) {
                $problem ??= self::STATUS_CONFLICT;
                $status = self::STATUS_CONFLICT;
            } elseif ($current === $wanted) {
                $status = self::STATUS_UNCHANGED;
            } else {
                $status = self::STATUS_LINKED;
                $updates[(int) $match['id']] = $wanted;
            }
            $results[] = ['index' => $index, 'sourceRef' => $sourceRef, 'rowId' => (int) $match['id'], 'status' => $status];
        }

        $headChange = null;
        $headStatus = null;
        if (array_key_exists('headAsset', $payload)) {
            $wanted = $payload['headAsset'] !== null ? (int) $payload['headAsset'] : null;
            $current = !empty($head['asset']) ? (int) $head['asset'] : null;
            $headStatus = $wanted === $current ? self::STATUS_UNCHANGED : self::STATUS_LINKED;
            if ($wanted !== $current) {
                $headChange = ['asset' => $wanted];
            }
        }

        $out = ['status' => self::STATUS_UNCHANGED, 'docId' => $docId, 'rows' => $results];
        if ($headStatus !== null) {
            $out['head'] = $headStatus;
        }
        if ($problem !== null) {
            $out['status'] = $problem;
            return $out;
        }
        if ($updates === [] && $headChange === null) {
            return $out;
        }

        $this->begin();
        try {
            foreach ($updates as $rowId => $asset) {
                $this->updateRowAsset($rowId, $asset);
            }
            if ($headChange !== null) {
                $this->updateHeadAsset($docId, $headChange['asset']);
            }
            if ((int) $head['docState'] === self::DOC_STATE_OK) {
                $this->logBypassedLocks($head);
                $before = $this->journalTurnover($docId);
                $accounting = $this->reaccount($docId);
                if ((int) $accounting['state'] !== self::ACCOUNTING_OK) {
                    $this->rollback();
                    return ['status' => self::STATUS_ACCOUNTING_FAILED, 'messages' => $accounting['messages']] + $out;
                }
                $after = $this->journalTurnover($docId);
                if (!self::sameTurnover($before, $after)) {
                    $this->rollback();
                    return ['status' => self::STATUS_TURNOVER_CHANGED, 'turnover' => ['before' => $before, 'after' => $after]] + $out;
                }
            }
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $out['status'] = self::STATUS_LINKED;
        return $out;
    }

    /**
     * Řádek dokladu k zadání, nebo stav (`notFound` / `ambiguous`).
     *
     * @param array<string, mixed> $spec
     * @param list<array<string, mixed>> $rows
     * @param array<int, true> $taken
     * @return array<string, mixed>|string
     */
    private function match(array $spec, array $rows, array $taken): array|string
    {
        $account = trim((string) $spec['account']);
        $amount = round((float) $spec['amount'], 2);
        $side = isset($spec['side']) ? self::SIDES[(string) $spec['side']] : null;

        $candidates = [];
        foreach ($rows as $row) {
            if (isset($taken[(int) $row['id']])
                || (string) ($row['account_number'] ?? '') !== $account
                || abs(round((float) ($row['vat_base_dom'] ?? 0), 2) - $amount) >= self::AMOUNT_EPSILON
                || ($side !== null && (int) ($row['acc_side'] ?? -1) !== $side)
            ) {
                continue;
            }
            $candidates[] = $row;
        }
        if ($candidates === []) {
            return self::STATUS_NOT_FOUND;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (isset($spec['orderHint'])) {
            $hinted = array_values(array_filter(
                $candidates,
                static fn(array $row): bool => (int) ($row['order_pos'] ?? 0) === (int) $spec['orderHint'],
            ));
            if (count($hinted) === 1) {
                return $hinted[0];
            }
        }
        return self::STATUS_AMBIGUOUS;
    }

    /**
     * @param array<string, array{dr: float, cr: float}> $before
     * @param array<string, array{dr: float, cr: float}> $after
     */
    private static function sameTurnover(array $before, array $after): bool
    {
        if (array_keys($before) !== array_keys($after)) {
            ksort($before);
            ksort($after);
            if (array_keys($before) !== array_keys($after)) {
                return false;
            }
        }
        foreach ($before as $account => $sums) {
            if (abs($sums['dr'] - $after[$account]['dr']) >= self::AMOUNT_EPSILON
                || abs($sums['cr'] - $after[$account]['cr']) >= self::AMOUNT_EPSILON
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tvar payloadu bez JSON schématu — formát je malý a interní.
     *
     * @param array<string, mixed> $payload
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    private function shapeIssues(array $payload): array
    {
        $issues = [];
        $error = static function (string $path, string $code, string $message) use (&$issues): void {
            $issues[] = ['severity' => 'error', 'path' => $path, 'code' => $code, 'message' => $message];
        };
        if (!isset($payload['docId']) || !is_int($payload['docId']) || $payload['docId'] <= 0) {
            $error('docId', 'required', 'docId musí být kladné celé číslo.');
        }
        if (array_key_exists('headAsset', $payload) && $payload['headAsset'] !== null
            && (!is_int($payload['headAsset']) || $payload['headAsset'] <= 0)
        ) {
            $error('headAsset', 'invalid', 'headAsset musí být id karty, nebo null.');
        } elseif (!empty($payload['headAsset']) && !$this->assetExists((int) $payload['headAsset'])) {
            $error('headAsset', 'asset_not_found', 'Karta majetku v tomto zdroji dat neexistuje.');
        }
        if (!isset($payload['rows']) || !is_array($payload['rows']) || !array_is_list($payload['rows'])) {
            $error('rows', 'required', 'rows musí být seznam řádků.');
            return $issues;
        }
        foreach ($payload['rows'] as $index => $row) {
            if (!is_array($row)) {
                $error("rows.{$index}", 'invalid', 'Řádek musí být objekt.');
                continue;
            }
            if (!isset($row['account']) || !is_string($row['account']) || trim($row['account']) === '') {
                $error("rows.{$index}.account", 'required', 'Číslo účtu je povinné.');
            }
            if (isset($row['side']) && !isset(self::SIDES[(string) $row['side']])) {
                $error("rows.{$index}.side", 'invalid', 'Strana musí být dr, nebo cr.');
            }
            if (!isset($row['amount']) || !is_numeric($row['amount'])) {
                $error("rows.{$index}.amount", 'required', 'Částka je povinná.');
            }
            if (!isset($row['asset']) || !is_int($row['asset']) || $row['asset'] <= 0) {
                $error("rows.{$index}.asset", 'required', 'Id karty je povinné.');
            } elseif (!$this->assetExists($row['asset'])) {
                $error("rows.{$index}.asset", 'asset_not_found', 'Karta majetku v tomto zdroji dat neexistuje.');
            }
            if (isset($row['orderHint']) && !is_int($row['orderHint'])) {
                $error("rows.{$index}.orderHint", 'invalid', 'orderHint musí být celé číslo.');
            }
        }
        return $issues;
    }

    /**
     * Zámky dokladu (zamčený měsíc, instance tvrzení DPH) se nevynucují —
     * backfill je systémová oprava derivátu, zaloguje se jako u
     * `doc-reaccount --force`.
     *
     * @param array<string, mixed> $head
     */
    private function logBypassedLocks(array $head): void
    {
        $reasons = $this->lockReasons($head);
        if ($reasons === []) {
            return;
        }
        $this->logBypass((int) $head['id'], array_map(
            static fn(DocumentLockReason $r): string => $r->source . ':' . (string) ($r->subjectRowId ?? ''),
            $reasons,
        ));
    }

    // ── DB přístup a služby (přepsatelné v testech) ─────────────────────────

    /** @return array{id: int, docState: int, asset: ?int, doc_number: string, accounting_date: mixed}|null */
    protected function loadHead(int $docId): ?array
    {
        $row = $this->db?->fetch('SELECT * FROM [' . self::HEADS_TABLE . '] WHERE [id] = %i', $docId);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Řádky dokladu s číslem účtu rozvrhu.
     *
     * @return list<array{id: int, account_number: ?string, acc_side: ?int, vat_base_dom: mixed, order_pos: int, asset: ?int}>
     */
    protected function loadRows(int $docId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [r].[id], [r].[acc_side], [r].[vat_base_dom], [r].[order_pos], [r].[asset], [a].[number] AS [account_number]'
            . ' FROM [' . self::ROWS_TABLE . '] [r]'
            . ' LEFT JOIN [economy_accounting_accounts] [a] ON [a].[id] = [r].[account]'
            . ' WHERE [r].[doc_head] = %i ORDER BY [r].[order_pos], [r].[id]',
            $docId,
        );
        return array_map(static fn(iterable $row): array => iterator_to_array($row), $rows);
    }

    protected function assetExists(int $assetId): bool
    {
        if ($this->db === null) {
            return true;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [id] = %i AND [docState] <> %i',
            $assetId,
            AssetDocument::STATE_DELETED,
        );
        return $row !== null && $row !== false;
    }

    protected function updateRowAsset(int $rowId, int $assetId): void
    {
        $this->db?->query('UPDATE [' . self::ROWS_TABLE . '] SET [asset] = %i WHERE [id] = %i', $assetId, $rowId);
    }

    protected function updateHeadAsset(int $docId, ?int $assetId): void
    {
        $this->db?->query('UPDATE [' . self::HEADS_TABLE . '] SET [asset] = %iN WHERE [id] = %i', $assetId, $docId);
    }

    /**
     * Obraty deníku dokladu po účtech.
     *
     * @return array<string, array{dr: float, cr: float}> číslo účtu → strany
     */
    protected function journalTurnover(int $docId): array
    {
        if ($this->db === null) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [account_number], SUM([money_dr]) AS [dr], SUM([money_cr]) AS [cr] FROM [' . self::JOURNAL_TABLE . ']'
            . ' WHERE [doc_head] = %i GROUP BY [account_number] ORDER BY [account_number]',
            $docId,
        ) as $row) {
            $out[(string) $row['account_number']] = ['dr' => round((float) $row['dr'], 2), 'cr' => round((float) $row['cr'], 2)];
        }
        return $out;
    }

    /** @return array{state: int, messages: list<array<string, mixed>>} */
    protected function reaccount(int $docId): array
    {
        if ($this->db === null) {
            return ['state' => self::ACCOUNTING_OK, 'messages' => []];
        }
        $result = (new AccountingEngine($this->db, $this->config, $this->journalEvents, $this->journalContributors))
            ->accountDocument($docId);
        return ['state' => (int) $result['state'], 'messages' => $result['messages']];
    }

    /**
     * @param array<string, mixed> $head
     * @return list<DocumentLockReason>
     */
    protected function lockReasons(array $head): array
    {
        if ($this->db === null || $this->documents === null || !$this->documents->hasLockProviders(self::HEADS_TABLE)) {
            return [];
        }
        return DocumentLockRegistry::forDocuments($this->documents, $this->db, $this->config, $this->dsConfig)
            ->reasons(self::HEADS_TABLE, $head, $head);
    }

    /** @param list<string> $reasons */
    protected function logBypass(int $docId, array $reasons): void
    {
        ErrorLogger::warn('document lock bypassed by force (assets backfill)', [
            'table'   => self::HEADS_TABLE,
            'id'      => $docId,
            'reasons' => $reasons,
        ]);
    }

    protected function begin(): void
    {
        $this->db?->begin();
    }

    protected function commit(): void
    {
        $this->db?->commit();
    }

    protected function rollback(): void
    {
        $this->db?->rollback();
    }
}
