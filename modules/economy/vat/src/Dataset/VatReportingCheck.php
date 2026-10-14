<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Dataset;

use Dibi\Connection;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;

/**
 * Kontrola 3 — přiznání DPH a kontrolní hlášení (tasks/testing-phase3.md
 * D28): očekávání `expected/vat.json` použitých dokladů sečtené po obdobích
 * ≟ `economy.vat.returnLive` / `economy.vat.controlStatementLive` nad
 * instancemi období (`economy_vat_report_periods`), do kterých data
 * spadají — instance najde kontrola sama (podle prvního dne měsíce DUZP),
 * i instance, kam Shipard zařadil doklad bez očekávání. Rozdíl se nikdy
 * neřeší úpravou očekávání bez schválení.
 *
 * Prázdné `vat.json` (neplátce, spolek): žádný použitý doklad v období DPH
 * a žádný pohyb deníku na účtech 343.
 */
final class VatReportingCheck implements VerifyCheck
{
    public const ID = 'vatReporting';
    public const RETURN_REPORT = 'economy.vat.returnLive';
    public const CS_REPORT = 'economy.vat.controlStatementLive';
    public const TOLERANCE = 0.005;

    public function __construct(private readonly ReportRunner $runner) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Přiznání DPH a kontrolní hlášení ≟ očekávání';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $docs = $ctx->appliedDocuments();
        if ($ctx->expectations->vat === []) {
            return $this->checkNoVat($ctx, $docs);
        }
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad');
        }
        $w = $ctx->wiring;
        if (!$w->hasTable('economy_vat_report_periods')) {
            return CheckResult::fromDifferences(self::ID, $this->label(), ['sada čeká DPH, ale zdroj dat nemá období DPH (economy.vat)']);
        }
        $db = $w->db;
        $messageIds = array_map(static fn (SetDocument $d): string => $d->messageId, $docs);
        $expected = VatExpectations::aggregate($ctx->expectations->vat, $messageIds);

        $differences = [];
        $notes = [];
        /** @var array<int, array{periods: list<string>, return: array<string, array{base: float, tax: float}>, items: array<string, array<string, array<string, mixed>>>, sums: array<string, array<int, array{base: float, tax: float}>>}> $byInstance */
        $byInstance = [];
        foreach ($expected as $period => $agg) {
            $instance = self::instance($db, 'return', $period . '-01');
            if ($instance === null) {
                $differences[] = "období {$period}: instance přiznání neexistuje, očekávané řádky " . implode(', ', array_keys($agg['return']));
                continue;
            }
            $id = (int) $instance['id'];
            $slot = $byInstance[$id] ?? ['periods' => [], 'return' => [], 'items' => [], 'sums' => []];
            $slot['periods'][] = $period;
            foreach ($agg['return'] as $row => $cell) {
                $slot['return'][$row] = [
                    'base' => ($slot['return'][$row]['base'] ?? 0.0) + $cell['base'],
                    'tax'  => ($slot['return'][$row]['tax'] ?? 0.0) + $cell['tax'],
                ];
            }
            foreach ($agg['items'] as $section => $items) {
                $slot['items'][$section] = ($slot['items'][$section] ?? []) + $items;
            }
            foreach ($agg['sums'] as $section => $rates) {
                foreach ($rates as $rate => $cell) {
                    $slot['sums'][$section][$rate] = [
                        'base' => ($slot['sums'][$section][$rate]['base'] ?? 0.0) + $cell['base'],
                        'tax'  => ($slot['sums'][$section][$rate]['tax'] ?? 0.0) + $cell['tax'],
                    ];
                }
            }
            $byInstance[$id] = $slot;
        }
        // Instance, kam Shipard zařadil použitý doklad bez očekávání — cokoli v nich je rozdíl.
        foreach ($db->fetchAll(
            'SELECT DISTINCT [vat_period] FROM [docs_core_heads] WHERE [id] IN %in AND [vat_period] IS NOT NULL',
            $ctx->appliedDocIds(),
        ) as $row) {
            $id = (int) $row['vat_period'];
            $byInstance[$id] ??= ['periods' => [], 'return' => [], 'items' => [], 'sums' => []];
        }
        ksort($byInstance);

        foreach ($byInstance as $instanceId => $slot) {
            $instance = $db->fetch('SELECT [id], [name], [date_begin] FROM [economy_vat_report_periods] WHERE [id] = %i', $instanceId);
            $name = (string) ($instance['name'] ?? "#{$instanceId}");
            $result = $this->runner->run(self::RETURN_REPORT, ['period' => (string) $instanceId])->toArray();
            $this->compareReturn($name, $slot['return'], $result, $differences, $notes);

            $hasCs = $slot['items'] !== [] || $slot['sums'] !== [];
            $dateBegin = $instance['date_begin'] ?? null;
            $dateBegin = $dateBegin instanceof \DateTimeInterface ? $dateBegin->format('Y-m-d') : (string) $dateBegin;
            $csInstance = $dateBegin !== '' ? self::instance($db, 'cs', $dateBegin) : null;
            if ($csInstance === null) {
                if ($hasCs) {
                    $differences[] = "období {$name}: instance kontrolního hlášení neexistuje";
                }
                continue;
            }
            $csResult = $this->runner->run(self::CS_REPORT, ['period' => (string) $csInstance['id']])->toArray();
            $this->compareControlStatement((string) ($csInstance['name'] ?? $name), $slot['items'], $slot['sums'], $csResult, $differences, $notes);
        }
        array_unshift($notes, count($byInstance) . ' období, ' . count($docs) . ' dokladů');
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }

    /** @param list<SetDocument> $docs */
    private function checkNoVat(VerifyContext $ctx, array $docs): CheckResult
    {
        $w = $ctx->wiring;
        $notes = ['sada bez očekávání DPH (neplátce)'];
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad', $notes);
        }
        $db = $w->db;
        $ids = $ctx->appliedDocIds();
        $differences = [];
        if ($w->hasTable('economy_vat_report_periods')) {
            $inPeriod = (int) $db->fetchSingle(
                'SELECT COUNT(*) FROM [docs_core_heads] WHERE [id] IN %in AND [vat_period] IS NOT NULL',
                $ids,
            );
            if ($inPeriod > 0) {
                $differences[] = "{$inPeriod} dokladů je zařazeno do období DPH, neplátce žádné nemá";
            }
        }
        if ($w->hasTable('economy_accounting_journal')) {
            $vatRows = (int) $db->fetchSingle(
                'SELECT COUNT(*) FROM [economy_accounting_journal] WHERE [doc_head] IN %in AND [account_number] LIKE %like~',
                $ids,
                '343',
            );
            if ($vatRows > 0) {
                $differences[] = "deník má {$vatRows} zápisů na účtech 343, neplátce DPH neúčtuje";
            }
        }
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }

    /** @return array<string, mixed>|null instance období daného typu obsahující datum */
    private static function instance(Connection $db, string $type, string $date): ?array
    {
        $row = $db->fetch(
            'SELECT [id], [name], [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [report_type] = %s AND [date_begin] <= %d AND [date_end] >= %d AND [docState] <> 90'
            . ' ORDER BY [id] LIMIT 1',
            $type,
            $date,
            $date,
        );
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    /**
     * @param array<string, array{base: float, tax: float}> $expectedRows
     * @param array<string, mixed>                           $result
     * @param list<string>                                   $differences
     * @param list<string>                                   $notes
     */
    private function compareReturn(string $name, array $expectedRows, array $result, array &$differences, array &$notes): void
    {
        $actual = [];
        foreach ($result['rows'] ?? [] as $row) {
            if (($row['kind'] ?? '') !== 'detail' || !isset($row['account'])) {
                continue;
            }
            $base = (float) ($row['values']['base']['balance'] ?? 0);
            $tax = (float) ($row['values']['tax']['balance'] ?? 0);
            if (abs($base) < self::TOLERANCE && abs($tax) < self::TOLERANCE) {
                continue;
            }
            $actual[(string) $row['account']] = ['base' => $base, 'tax' => $tax];
        }
        foreach ($expectedRows as $rowNo => $cell) {
            $got = $actual[(string) $rowNo] ?? null;
            if ($got === null) {
                $differences[] = sprintf('přiznání %s ř. %s: očekáváno základ %s / daň %s, v Shipardu nic', $name, $rowNo, self::money($cell['base']), self::money($cell['tax']));
                continue;
            }
            if (abs($got['base'] - $cell['base']) > self::TOLERANCE || abs($got['tax'] - $cell['tax']) > self::TOLERANCE) {
                $differences[] = sprintf('přiznání %s ř. %s: očekáváno základ %s / daň %s, Shipard %s / %s', $name, $rowNo,
                    self::money($cell['base']), self::money($cell['tax']), self::money($got['base']), self::money($got['tax']));
            }
        }
        foreach ($actual as $rowNo => $got) {
            if (!isset($expectedRows[(string) $rowNo])) {
                $differences[] = sprintf('přiznání %s ř. %s: Shipard má základ %s / daň %s, očekávání nic', $name, $rowNo, self::money($got['base']), self::money($got['tax']));
            }
        }
        foreach ($result['messages'] ?? [] as $message) {
            if (in_array($message['severity'] ?? '', ['warning', 'error'], true)) {
                $notes[] = "přiznání {$name}: {$message['severity']} {$message['code']} {$message['text']}";
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $expectedItems oddíl → klíč → položka
     * @param array<string, array<int, array{base: float, tax: float}>> $expectedSums oddíl → sazba → součet
     * @param array<string, mixed> $result
     * @param list<string> $differences
     * @param list<string> $notes
     */
    private function compareControlStatement(string $name, array $expectedItems, array $expectedSums, array $result, array &$differences, array &$notes): void
    {
        $sums = [];
        $items = [];
        $section = null;
        foreach ($result['rows'] ?? [] as $row) {
            $kind = $row['kind'] ?? '';
            if ($kind === 'subtotal' && isset($row['account'])) {
                $section = (string) $row['account'];
                $sums[$section] = $row['values'] ?? [];
                continue;
            }
            if ($kind === 'detail' && $section !== null) {
                $values = $row['values'] ?? [];
                $key = VatExpectations::itemKey((string) ($values['vatId'] ?? ''), (string) ($values['evidNumber'] ?? ''));
                $items[$section][$key] = $values;
            }
        }

        foreach ($expectedItems as $sec => $expected) {
            foreach ($expected as $key => $item) {
                $got = $items[$sec][$key] ?? null;
                $label = "KH {$name} {$sec} {$item['vatId']} {$item['documentNumber']}";
                if ($got === null) {
                    $differences[] = "{$label}: v Shipardu chybí" . (isset($items[$sec]) ? '' : " (oddíl {$sec} prázdný)");
                    continue;
                }
                $dppd = (string) ($got['dppd'] ?? '');
                if ($item['taxPointDate'] !== null && $dppd !== (string) $item['taxPointDate']) {
                    $differences[] = "{$label}: datum očekáváno {$item['taxPointDate']}, Shipard " . ($dppd === '' ? '—' : $dppd);
                }
                $code = (string) ($got['kodPredPl'] ?? '');
                if ((string) ($item['reverseChargeCode'] ?? '') !== $code) {
                    $differences[] = "{$label}: kód plnění očekáváno " . ($item['reverseChargeCode'] ?? '—') . ', Shipard ' . ($code === '' ? '—' : $code);
                }
                $this->compareBands($label, $item['rates'], $got, $differences);
            }
            foreach ($items[$sec] ?? [] as $key => $got) {
                if (!isset($expected[$key])) {
                    $differences[] = "KH {$name} {$sec}: Shipard má navíc {$got['vatId']} {$got['evidNumber']}";
                }
            }
        }
        foreach ($expectedSums as $sec => $rates) {
            if (!isset($sums[$sec])) {
                $differences[] = "KH {$name} {$sec}: očekáván součet, v Shipardu oddíl chybí";
                continue;
            }
            $this->compareBands("KH {$name} {$sec} součet", $rates, $sums[$sec], $differences);
        }
        foreach ($sums as $sec => $values) {
            if (isset($expectedItems[$sec]) || isset($expectedSums[$sec])) {
                continue;
            }
            $differences[] = "KH {$name} {$sec}: Shipard má data, očekávání nic";
        }
        foreach ($result['messages'] ?? [] as $message) {
            if (in_array($message['severity'] ?? '', ['warning', 'error'], true)) {
                $notes[] = "KH {$name}: {$message['severity']} {$message['code']} {$message['text']}";
            }
        }
    }

    /**
     * @param array<int, array{base: float, tax: float}> $expectedRates
     * @param array<string, mixed>                       $values buňky `base1`..`tax3` (money)
     * @param list<string>                               $differences
     */
    private function compareBands(string $label, array $expectedRates, array $values, array &$differences): void
    {
        $expectedBands = [];
        foreach ($expectedRates as $rate => $cell) {
            $band = VatExpectations::bandForRate((int) $rate);
            if ($band === null) {
                $differences[] = "{$label}: sazba {$rate} % nemá pásmo KH";
                continue;
            }
            $expectedBands[$band] = [
                'base' => ($expectedBands[$band]['base'] ?? 0.0) + $cell['base'],
                'tax'  => ($expectedBands[$band]['tax'] ?? 0.0) + $cell['tax'],
            ];
        }
        for ($band = 1; $band <= 3; $band++) {
            $base = (float) ($values["base{$band}"]['balance'] ?? 0);
            $tax = (float) ($values["tax{$band}"]['balance'] ?? 0);
            $want = $expectedBands[$band] ?? ['base' => 0.0, 'tax' => 0.0];
            if (abs($base - $want['base']) > self::TOLERANCE || abs($tax - $want['tax']) > self::TOLERANCE) {
                $differences[] = sprintf('%s: pásmo %d očekáváno základ %s / daň %s, Shipard %s / %s', $label, $band,
                    self::money($want['base']), self::money($want['tax']), self::money($base), self::money($tax));
            }
        }
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
