<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Dataset;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;

/**
 * Kontrola 4 — náklady dokladů (tasks/testing-phase3.md, #97): per použitý
 * doklad součet MD na účtech třídy 5 z deníku bez řádků zaokrouhlení
 * ≟ `expected/costs.json` (plátce základ, neplátce vč. DPH; spočteno
 * generátorem nezávisle na Shipardu).
 *
 * Zaokrouhlení se pozná podle původu řádku, ne podle účtu (#122 D5): řádek
 * vzniká z hlavičkového kroku předpisu kategorie `rounding.cost` /
 * `rounding.revenue`, tedy bez operace a s textem kroku (ROUNDING_TEXT —
 * že ho nesou všechny kroky zaokrouhlení a žádný jiný, hlídá
 * CostsCheckTest). Na účtech 548 / 549 jsou totiž i pojištění, bankovní
 * poplatky a jiné náklady, které do nákladu dokladu patří.
 */
final class CostsCheck implements VerifyCheck
{
    public const ID = 'costs';
    public const TOLERANCE = 0.005;
    /** Text hlavičkových kroků zaokrouhlení v předpisu (accountingRules.*.jsonc). */
    public const ROUNDING_TEXT = 'Zaokrouhlení dokladu';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Náklady dokladů (deník třída 5 ≟ očekávání)';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $docs = $ctx->appliedDocuments();
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad');
        }
        if (!$ctx->wiring->hasTable('economy_accounting_journal')) {
            return CheckResult::fromDifferences(self::ID, $this->label(), ['zdroj dat nemá účetní deník (economy.accounting)']);
        }
        $home = strtoupper($ctx->homeCurrency);
        $costs = $ctx->expectations->costsByMessage();
        $differences = [];
        $notes = [];
        $compared = 0;
        foreach ($docs as $doc) {
            $record = $costs[$doc->messageId] ?? null;
            if ($record === null) {
                $differences[] = "{$doc->messageId} ({$doc->docNumber}): bez očekávaného nákladu v costs.json";
                continue;
            }
            $currency = strtoupper((string) ($record['currency'] ?? ''));
            if ($currency !== $home) {
                $notes[] = "{$doc->messageId}: náklad v {$currency} — porovnání čeká na kurzy (#121)";
                continue;
            }
            $expected = (float) ($record['cost'] ?? 0);
            $actual = self::journalCost($ctx->wiring->db, (int) $doc->docId);
            $compared++;
            if (abs($expected - $actual) > self::TOLERANCE) {
                $differences[] = sprintf(
                    '%s (%s): náklad očekáváno %s, deník %s (Δ %s)',
                    $doc->messageId,
                    $doc->docNumber ?? '—',
                    number_format($expected, 2, '.', ''),
                    number_format($actual, 2, '.', ''),
                    number_format($actual - $expected, 2, '.', ''),
                );
            }
        }
        array_unshift($notes, "{$compared} dokladů porovnáno");
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }

    /** Σ MD účtů třídy 5 dokladu bez řádků zaokrouhlení (podle původu řádku, ne účtu). */
    public static function journalCost(Connection $db, int $docId): float
    {
        return (float) $db->fetchSingle(
            'SELECT COALESCE(SUM([money_dr]), 0) FROM [economy_accounting_journal]'
            . ' WHERE [doc_head] = %i AND [account_number] LIKE %like~'
            . ' AND NOT ([operation] IS NULL AND [text] = %s)',
            $docId,
            '5',
            self::ROUNDING_TEXT,
        );
    }
}
