<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Dataset;

use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;

/**
 * Sekce `bank/` datové sady (#131 fáze 2a, D17, docs/datasets.md §3): každý
 * soubor = jeden výpis (`shpd.dataset.bankStatement.v1`, obálka nad
 * kanonickým formátem). Seeder jen přeloží kód našeho účtu na id a deleguje
 * na {@see BankStatementApplier} — žádná vlastní create logika: partnera
 * dohledá applier z protiúčtu, cílový stav 40 zaúčtuje
 * `BankTransactionEventHandler` a mikroengine dohledá otevřený předpis.
 * Proto běží až za sekcí `docs/` (předpisy musí existovat) a applier musí
 * dostat dispatcher událostí (jinak se nic nezaúčtuje).
 *
 * Výsledek: `success = false` nebo transakce, které se nezapsaly
 * (`_result.txErrors`), = failed; přeskočené transakce (dedup podle
 * `externalId`, typicky `--no-reset`) = ok s varováním.
 */
class BankStatementSeeder implements SectionSeeder
{
    public const SECTION = 'bank';

    /** @var array<string, ?int> kód účtu → id (cache per běh) */
    private array $accountIds = [];

    public function __construct(private readonly BankStatementApplier $applier)
    {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        foreach ($ctx->reader->listFiles(self::SECTION) as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }

            $code = trim((string) ($file['bankAccount'] ?? ''));
            $accountId = $this->accountId($ctx, $code);
            if ($accountId === null) {
                $report->failed(self::SECTION, "{$rel}: bankovní účet '{$code}' na DS neexistuje (chybí v setup/bank_accounts?)");
                continue;
            }

            $result = $this->applier->apply([
                'format'        => 'shpd.bank.statement',
                'formatVersion' => '1.0',
                'bankAccountId' => $accountId,
                'statement'     => is_array($file['statement'] ?? null) ? $file['statement'] : [],
                'transactions'  => array_values(is_array($file['transactions'] ?? null) ? $file['transactions'] : []),
                'applyOptions'  => ['targetState' => (int) ($file['docState'] ?? 10)],
            ]);

            if (!$result->success) {
                $detail = (string) ($result->errorMessage ?? $result->errorCode ?? 'apply selhalo');
                $issues = self::issueMessages($result->canonical['_issues'] ?? null);
                $report->failed(self::SECTION, "{$rel}: {$detail}" . ($issues !== '' ? " ({$issues})" : ''));
                continue;
            }

            $summary = is_array($result->canonical['_result'] ?? null) ? $result->canonical['_result'] : [];
            $txErrors = is_array($summary['txErrors'] ?? null) ? $summary['txErrors'] : [];
            if ($txErrors !== []) {
                $report->failed(self::SECTION, "{$rel}: transakce se nezapsaly: " . self::txErrorMessages($txErrors));
                continue;
            }
            $skipped = (int) ($summary['skipped'] ?? 0);
            if ($skipped > 0) {
                $report->warning("bank: {$rel}: {$skipped} transakcí už na DS bylo (dedup podle externalId) — přeskočeny");
            }
            $report->ok(self::SECTION);
        }
    }

    private function accountId(SeedContext $ctx, string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        if (!array_key_exists($code, $this->accountIds)) {
            $row = $ctx->db->fetch(
                'SELECT [id] FROM [economy_codebooks_bank_accounts] WHERE [code] = %s AND [docState] <> 90 ORDER BY [id] LIMIT 1',
                $code,
            );
            $this->accountIds[$code] = $row !== null ? (int) $row['id'] : null;
        }
        return $this->accountIds[$code];
    }

    private static function issueMessages(mixed $issues): string
    {
        if (!is_array($issues)) {
            return '';
        }
        $out = [];
        foreach ($issues as $issue) {
            $out[] = is_array($issue)
                ? trim((string) (($issue['path'] ?? '') . ' ' . ($issue['message'] ?? $issue['code'] ?? '')))
                : (string) $issue;
        }
        return implode('; ', array_filter($out, static fn(string $s): bool => $s !== ''));
    }

    /** @param list<mixed> $txErrors položky `_result.txErrors` ({date, amount, errors[]}) */
    private static function txErrorMessages(array $txErrors): string
    {
        $out = [];
        foreach ($txErrors as $tx) {
            if (!is_array($tx)) {
                $out[] = (string) $tx;
                continue;
            }
            $errors = [];
            foreach ((array) ($tx['errors'] ?? []) as $e) {
                $errors[] = is_array($e) ? (string) ($e['message'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string) $e;
            }
            $out[] = trim((string) ($tx['date'] ?? '') . ' ' . (string) ($tx['amount'] ?? '')) . ': ' . implode(', ', $errors);
        }
        return implode('; ', $out);
    }
}
