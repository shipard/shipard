<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Dataset;

use Shipard\Core\Reports\ReportDiff;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;

/**
 * Kontrola 5 — deník proti schválenému snímku (tasks/testing-phase3.md D29):
 * účty určuje nastavení Shipardu (položky → účty), ne zákon, proto se
 * hlavní kniha (`economy.accounting.generalLedger`, analyticky) za rozsah
 * pokrývající měsíce použitých dokladů porovná přes `ReportDiff`
 * s `expected/general-ledger.json` (kopie `profiles/<profil>/approved/`).
 * Chybějící snímek = přeskočeno. `--write-ledger-snapshot` snímek zapíše,
 * vypíše obraty po účtech a skončí přeskočením — schválení je lidský krok.
 */
final class LedgerSnapshotCheck implements VerifyCheck
{
    public const ID = 'ledgerSnapshot';
    public const REPORT = 'economy.accounting.generalLedger';

    public function __construct(private readonly ReportRunner $runner) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Deník proti schválenému snímku (hlavní kniha)';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $ids = $ctx->appliedDocIds();
        if ($ids === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad');
        }
        $w = $ctx->wiring;
        if (!$w->hasTable('economy_accounting_journal') || !$w->hasTable('economy_codebooks_fiscal_months')) {
            return CheckResult::fromDifferences(self::ID, $this->label(), ['zdroj dat nemá účetní deník nebo fiskální měsíce']);
        }
        $db = $w->db;
        $used = $db->fetchAll(
            'SELECT DISTINCT [fiscal_year], [fiscal_month] FROM [economy_accounting_journal] WHERE [doc_head] IN %in',
            $ids,
        );
        if ($used === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'použité doklady nemají zápisy v deníku');
        }
        $years = array_values(array_unique(array_map(static fn ($r): int => (int) $r['fiscal_year'], $used)));
        if (count($years) !== 1) {
            return CheckResult::skipped(self::ID, $this->label(), 'použité doklady leží ve více fiskálních rocích (' . implode(', ', $years) . ') — snímek je za jeden rok');
        }
        $yearId = $years[0];
        $yearName = (string) $db->fetchSingle('SELECT [name] FROM [economy_codebooks_fiscal_years] WHERE [id] = %i', $yearId);
        $positions = [];
        foreach ($db->fetchAll(
            'SELECT [id] FROM [economy_codebooks_fiscal_months] WHERE [fiscal_year] = %i AND [period_type] = 1 ORDER BY [date_begin], [id]',
            $yearId,
        ) as $n => $month) {
            $positions[(int) $month['id']] = $n + 1;
        }
        $usedPositions = [];
        $notes = [];
        foreach ($used as $r) {
            $pos = $positions[(int) $r['fiscal_month']] ?? null;
            if ($pos === null) {
                $notes[] = "zápisy v mimořádném měsíci #{$r['fiscal_month']} (otevírací / uzávěrkový) se do rozsahu nepočítají";
                continue;
            }
            $usedPositions[] = $pos;
        }
        if ($usedPositions === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'použité doklady nemají zápisy v řádných měsících', $notes);
        }
        $range = LedgerRange::pick(min($usedPositions), max($usedPositions), count($positions));

        $result = $this->runner->run(self::REPORT, [
            'fiscalYear' => $yearName,
            'monthFrom'  => (string) $range['from'],
            'monthTo'    => (string) $range['to'],
            'detail'     => 'analytic',
        ])->toArray();
        $notes[] = sprintf('hlavní kniha %s, měsíce %d–%d (%s), stav reportu %s', $yearName, $range['from'], $range['to'], $range['granularity'], $result['status'] ?? '?');
        foreach ($result['messages'] ?? [] as $message) {
            if (in_array($message['severity'] ?? '', ['warning', 'error'], true)) {
                $notes[] = "report: {$message['severity']} {$message['code']} {$message['text']}";
            }
        }

        if ($ctx->ledgerSnapshotOut !== null) {
            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false || @file_put_contents($ctx->ledgerSnapshotOut, $json . "\n") === false) {
                return CheckResult::fromDifferences(self::ID, $this->label(), ["snímek deníku nejde zapsat do {$ctx->ledgerSnapshotOut}"], $notes);
            }
            $notes[] = "snímek zapsán do {$ctx->ledgerSnapshotOut}";
            foreach ($result['rows'] ?? [] as $row) {
                if (($row['kind'] ?? '') !== 'detail') {
                    continue;
                }
                $turnover = $row['values']['turnover'] ?? [];
                $notes[] = sprintf('%-8s %-32s MD %14s  D %14s', $row['account'] ?? '', mb_substr((string) ($row['label'] ?? ''), 0, 32),
                    number_format((float) ($turnover['md'] ?? 0), 2, '.', ' '), number_format((float) ($turnover['d'] ?? 0), 2, '.', ' '));
            }
            return CheckResult::skipped(self::ID, $this->label(), 'snímek zapsán — schválení je lidský krok (profiles/<profil>/approved/general-ledger.json)', $notes);
        }

        $snapshot = $ctx->expectations->ledgerSnapshot;
        if ($snapshot === null) {
            return CheckResult::skipped(self::ID, $this->label(), 'schválený snímek chybí (expected/general-ledger.json) — vyrob ho přes --write-ledger-snapshot', $notes);
        }
        $diff = (new ReportDiff())->diff($snapshot, $result);
        $differences = [];
        foreach ($diff['differences'] as $d) {
            $differences[] = sprintf('účet %s %s.%s: snímek %s, deník %s (Δ %s)', $d['account'], $d['column'], $d['field'],
                self::show($d['a']), self::show($d['b']), self::show($d['delta']));
        }
        foreach ($diff['onlyInA'] as $account) {
            $differences[] = "účet {$account} jen ve snímku";
        }
        foreach ($diff['onlyInB'] as $account) {
            $differences[] = "účet {$account} jen v deníku";
        }
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }

    private static function show(mixed $value): string
    {
        return is_float($value) || is_int($value) ? number_format((float) $value, 2, '.', '') : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
