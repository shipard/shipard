<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Strážce očekávání datových sad pro `dataset-verify` (#103 F3,
 * tasks/testing-phase3.md): `expected/` každé sady odpovídá korpusu
 * a mapování DPH (`tests/corpus/<země>/vat-reporting.jsonc`) pokrývá
 * všechny varianty. Částky záznamů se přepočítají z očekávaného canonicalu
 * dokladu nezávisle na generátoru (Σ rekapitulace po sazbách, daň
 * samovyměření = základ × sazba, pravidlo nákladu per profil, práh B.2).
 */
class DatasetExpectationsTest extends TestCase
{
    private const TOLERANCE = 0.005;
    private const DP3_ROWS = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 20, 21, 22, 23, 24, 25, 26, 30, 31, 32, 33,
        40, 41, 42, 43, 44, 45, 46, 47, 50, 51, 52, 53, 60, 61, 62, 63, 64, 65, 66,
    ];
    private const CS_SECTIONS = ['A2' => 'A.2', 'B1' => 'B.1', 'B2orB3' => null, 'B3' => 'B.3'];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, array{0: string, 1: string}> země / profil */
    public static function datasets(): array
    {
        $cases = [];
        foreach (glob(self::repoRoot() . '/tests/datasets/*/*/manifest.jsonc') ?: [] as $manifest) {
            $profile = basename(dirname($manifest));
            $country = basename(dirname($manifest, 2));
            $cases["{$country}/{$profile}"] = [$country, $profile];
        }
        return $cases;
    }

    private static function datasetDir(string $country, string $profile): string
    {
        return self::repoRoot() . "/tests/datasets/{$country}/{$profile}";
    }

    /** @return array<string, mixed> */
    private static function mapping(string $country): array
    {
        return JsoncParser::parseFile(self::repoRoot() . "/tests/corpus/{$country}/vat-reporting.jsonc");
    }

    /** @return list<array<string, mixed>> */
    private static function readList(string $file): array
    {
        $raw = (string) file_get_contents($file);
        self::assertStringEndsWith("\n", $raw, basename($file) . ' nekončí \n');
        self::assertStringNotContainsString("\t", $raw, basename($file) . ' obsahuje tabulátor');
        $list = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($list) && array_is_list($list), basename($file) . ' není pole');
        return $list;
    }

    /** @return list<array{messageId: string, stem: string}> v pořadí souborů */
    private static function messages(string $dir): array
    {
        $out = [];
        $files = glob($dir . '/mail/*.jsonc') ?: [];
        sort($files);
        foreach ($files as $file) {
            $message = JsoncParser::parseFile($file);
            $out[] = [
                'messageId' => (string) $message['messageId'],
                'stem' => basename((string) ($message['attachments'][0]['file'] ?? ''), '.pdf'),
            ];
        }
        return $out;
    }

    private static function vatAgenda(string $dir): bool
    {
        $file = $dir . '/setup/settings.jsonc';
        if (!is_file($file)) {
            return false;
        }
        foreach (JsoncParser::parseFile($file)['rows'] ?? [] as $row) {
            if (($row['key'] ?? null) === 'economy.vatAgenda') {
                return (bool) $row['value'];
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private static function expectedDoc(string $country, string $profile, string $stem): array
    {
        $file = self::repoRoot() . "/tests/corpus/{$country}/expected/{$profile}/{$stem}.json";
        self::assertFileExists($file);
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Mapování ────────────────────────────────────────────────────────────

    public function testMappingCoversCorpusVariants(): void
    {
        $countries = array_unique(array_map(static fn (array $c): string => $c[0], self::datasets()));
        $this->assertNotEmpty($countries);
        foreach ($countries as $country) {
            $variants = [];
            foreach (glob(self::repoRoot() . "/tests/corpus/{$country}/data/*.jsonc") ?: [] as $file) {
                $variants[(string) JsoncParser::parseFile($file)['variant']] = true;
            }
            $mapping = self::mapping($country);
            $this->assertSame('shpd.corpus.vatReporting', $mapping['format'] ?? null);
            $this->assertGreaterThan(0, $mapping['controlStatementThreshold'] ?? 0);
            $problems = [];
            foreach ($mapping['regimes'] as $regime => $rates) {
                foreach ($rates as $rate => $rows) {
                    foreach ($rows as $kind => $row) {
                        if (!in_array($kind, ['output', 'deduction'], true) || !in_array($row, self::DP3_ROWS, true)) {
                            $problems[] = "{$country}: regimes.{$regime}.{$rate}.{$kind} = {$row} mimo formulář DP3";
                        }
                    }
                }
            }
            foreach ($mapping['variants'] as $variant => $spec) {
                $regime = $spec['regime'] ?? null;
                if ($regime !== 'none' && !isset($mapping['regimes'][$regime])) {
                    $problems[] = "{$country}: variants.{$variant}: neznámý režim {$regime}";
                }
                $section = $spec['controlStatement'] ?? null;
                if ($section !== null && !array_key_exists($section, self::CS_SECTIONS)) {
                    $problems[] = "{$country}: variants.{$variant}: neznámý oddíl KH {$section}";
                }
                if ($regime !== 'none' && $regime !== 'domesticDeduction' && !isset($spec['czRate'])) {
                    $problems[] = "{$country}: variants.{$variant}: samovyměření bez czRate";
                }
            }
            $this->assertSame([], $problems, implode("\n", $problems));
            $this->assertSame(
                array_keys($variants),
                array_values(array_intersect(array_keys($variants), array_keys($mapping['variants']))),
                "{$country}: varianty bez mapování: " . implode(', ', array_diff(array_keys($variants), array_keys($mapping['variants']))),
            );
            $this->assertSame([], array_values(array_diff(array_keys($mapping['variants']), array_keys($variants))), "{$country}: mapování pro neznámé varianty");
        }
    }

    // ── Per sada ────────────────────────────────────────────────────────────

    #[DataProvider('datasets')]
    public function testProjectionCopyMatchesCorpus(string $country, string $profile): void
    {
        $copy = self::datasetDir($country, $profile) . '/expected/projection.jsonc';
        $this->assertFileExists($copy, 'spusť python3 tests/corpus/cz/build.py');
        $this->assertSame(
            file_get_contents(self::repoRoot() . '/tests/corpus/projection.jsonc'),
            file_get_contents($copy),
            "{$country}/{$profile}: expected/projection.jsonc ≠ tests/corpus/projection.jsonc",
        );
    }

    #[DataProvider('datasets')]
    public function testExpectationsCoverEveryMessage(string $country, string $profile): void
    {
        $dir = self::datasetDir($country, $profile);
        $ids = array_column(self::messages($dir), 'messageId');
        $this->assertNotEmpty($ids);

        $costs = self::readList($dir . '/expected/costs.json');
        $this->assertSame($ids, array_column($costs, 'messageId'), 'costs.json: právě jeden záznam per zpráva, v pořadí zpráv');

        $vat = self::readList($dir . '/expected/vat.json');
        if (self::vatAgenda($dir)) {
            $this->assertSame($ids, array_column($vat, 'messageId'), 'vat.json: právě jeden záznam per zpráva, v pořadí zpráv');
        } else {
            $this->assertSame([], $vat, 'profil bez agendy DPH má vat.json prázdné');
        }
    }

    #[DataProvider('datasets')]
    public function testRecordsRecomputeFromCorpus(string $country, string $profile): void
    {
        $dir = self::datasetDir($country, $profile);
        $vatPayer = self::vatAgenda($dir);
        $mapping = self::mapping($country);
        $costs = array_column(self::readList($dir . '/expected/costs.json'), null, 'messageId');
        $vat = array_column(self::readList($dir . '/expected/vat.json'), null, 'messageId');
        $problems = [];

        foreach (self::messages($dir) as ['messageId' => $id, 'stem' => $stem]) {
            $doc = self::expectedDoc($country, $profile, $stem);
            $totals = $doc['totals'];

            // Náklad: plátce základ, neplátce základ + DPH, bez DPH celková částka.
            $cost = $totals['totalBase'] === null
                ? (float) $totals['totalAmount']
                : ($vatPayer ? (float) $totals['totalBase'] : (float) $totals['totalBase'] + (float) $totals['totalVat']);
            $record = $costs[$id] ?? [];
            if (($record['currency'] ?? null) !== $doc['currency'] || !self::near($cost, $record['cost'] ?? null)) {
                $problems[] = "{$id}: costs.json čeká {$cost} {$doc['currency']}, je " . json_encode($record);
            }
            if (!$vatPayer) {
                continue;
            }

            $record = $vat[$id] ?? [];
            $variant = substr($stem, 5);
            $spec = $mapping['variants'][$variant] ?? null;
            if ($spec === null || ($record['variant'] ?? null) !== $variant) {
                $problems[] = "{$id}: varianta {$variant} bez mapování nebo jiná v záznamu";
                continue;
            }
            $duzp = $doc['dates']['taxPointDate'];
            $expectedReturn = [];
            $expectedSection = null;
            $rates = [];
            if ($spec['regime'] !== 'none') {
                $czRate = $spec['czRate'] ?? null;
                foreach ($doc['vatRecap'] as $entry) {
                    $pct = (int) $entry['vatPct'];
                    $base = (float) $entry['base'];
                    if ($czRate === null) {
                        if ($pct === 0) {
                            continue;
                        }
                        $tax = (float) $entry['tax'];
                    } else {
                        if ($pct !== 0) {
                            $problems[] = "{$id}: samovyměření s nenulovou sazbou na dokladu";
                            continue 2;
                        }
                        $pct = (int) $czRate;
                        $tax = round($base * $pct / 100, 2);
                    }
                    $rates[$pct] = [($rates[$pct][0] ?? 0) + $base, ($rates[$pct][1] ?? 0) + $tax];
                    foreach ($mapping['regimes'][$spec['regime']][(string) $pct] ?? [] as $row) {
                        $expectedReturn[(string) $row] = [
                            ($expectedReturn[(string) $row][0] ?? 0) + $base,
                            ($expectedReturn[(string) $row][1] ?? 0) + $tax,
                        ];
                    }
                }
                $expectedSection = self::CS_SECTIONS[$spec['controlStatement']] ?? null;
                if ($spec['controlStatement'] === 'B2orB3') {
                    $expectedSection = (float) $totals['totalAmount'] > (float) $mapping['controlStatementThreshold'] ? 'B.2' : 'B.3';
                }
            }

            $period = $spec['regime'] === 'none' || $duzp === null ? null : substr($duzp, 0, 7);
            if (self::field($record, 'period') !== $period || ($record['currency'] ?? null) !== $doc['currency']) {
                $problems[] = "{$id}: období / měna záznamu neodpovídá DUZP " . json_encode($duzp);
            }
            // Číselné klíče (řádky, sazby) dělá json_decode z int — srovnat obě strany.
            $actualReturn = $record['return'] ?? [];
            ksort($actualReturn);
            ksort($expectedReturn);
            if (array_keys($actualReturn) !== array_keys($expectedReturn)) {
                $problems[] = "{$id}: řádky přiznání " . implode(',', array_keys($actualReturn)) . ' ≠ ' . implode(',', array_keys($expectedReturn));
            } else {
                foreach ($expectedReturn as $row => [$base, $tax]) {
                    if (!self::near($base, $actualReturn[$row]['base'] ?? null) || !self::near($tax, $actualReturn[$row]['tax'] ?? null)) {
                        $problems[] = "{$id}: řádek {$row} čeká {$base} / {$tax}, je " . json_encode($actualReturn[$row]);
                    }
                }
            }

            $cs = $record['controlStatement'] ?? null;
            if ($expectedSection === null) {
                if ($cs !== null) {
                    $problems[] = "{$id}: KH má být null";
                }
                continue;
            }
            $codes = array_values(array_unique(array_filter(array_map(
                static fn (array $r): ?string => $r['vat']['reverseChargeCode'] ?? null,
                $doc['rows'],
            ))));
            $want = [
                'section' => $expectedSection,
                'supplierVatId' => $doc['supplier']['vatId'],
                'documentNumber' => $doc['docNumber'],
                'taxPointDate' => $duzp,
                'reverseChargeCode' => $codes[0] ?? null,
            ];
            foreach ($want as $key => $value) {
                if (self::field($cs, $key) !== $value) {
                    $problems[] = "{$id}: KH {$key} čeká " . json_encode($value) . ', je ' . json_encode(self::field($cs, $key));
                }
            }
            krsort($rates);
            if (array_keys($rates) !== array_keys($cs['rates'] ?? [])) {
                $problems[] = "{$id}: KH sazby " . implode(',', array_keys($cs['rates'] ?? [])) . ' ≠ ' . implode(',', array_keys($rates));
            } else {
                foreach ($rates as $pct => [$base, $tax]) {
                    if (!self::near($base, $cs['rates'][(string) $pct]['base'] ?? null) || !self::near($tax, $cs['rates'][(string) $pct]['tax'] ?? null)) {
                        $problems[] = "{$id}: KH sazba {$pct} čeká {$base} / {$tax}";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    private static function near(float $expected, mixed $actual): bool
    {
        return is_numeric($actual) && abs($expected - (float) $actual) <= self::TOLERANCE;
    }

    /** Hodnota klíče; chybějící klíč = `false` (odliší se od `null` v záznamu). */
    private static function field(mixed $record, string $key): mixed
    {
        return is_array($record) && array_key_exists($key, $record) ? $record[$key] : false;
    }
}
