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
 * Doklad v cizí měně (#103 D73, tasks/testing-rates-isdoc.md) se přepočítá
 * do domácí měny kurzem k účetnímu datu (DUZP, bez něj datum vystavení)
 * z lístků korpusu `tests/corpus/<země>/exchange-rates/` malým resolverem
 * přímo tady (poslední lístek ≤ D, nejvýš 7 dní, měna v něm) — nezávisle
 * na generátoru i na Shipardu; `expected/exchange-rates.json` nese kurz
 * per doklad v cizí měně.
 */
class DatasetExpectationsTest extends TestCase
{
    private const TOLERANCE = 0.005;
    private const RATE_TOLERANCE = 0.0000005;
    private const LIST_TYPE = 'cz.cnb.daily';
    private const MAX_AGE_DAYS = 7;
    /** @var array<string, list<array<string, mixed>>> lístky per země */
    private static array $rateLists = [];
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

    private static function homeCurrency(string $dir): string
    {
        $file = $dir . '/setup/settings.jsonc';
        if (is_file($file)) {
            foreach (JsoncParser::parseFile($file)['rows'] ?? [] as $row) {
                if (($row['key'] ?? null) === 'economy.homeCurrency' && is_string($row['value']) && $row['value'] !== '') {
                    return strtoupper($row['value']);
                }
            }
        }
        return 'CZK';
    }

    /** Účetní datum dokladu korpusu (D73): DUZP, bez něj datum vystavení. */
    private static function accountingDate(array $doc): string
    {
        return (string) ($doc['dates']['taxPointDate'] ?? $doc['dates']['issueDate']);
    }

    /** @return list<array<string, mixed>> denní lístky korpusu podle validFrom */
    private static function rateLists(string $country): array
    {
        if (!isset(self::$rateLists[$country])) {
            $lists = [];
            foreach (glob(self::repoRoot() . "/tests/corpus/{$country}/exchange-rates/*.json") ?: [] as $file) {
                $list = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (($list['listType'] ?? null) === self::LIST_TYPE) {
                    $lists[] = $list;
                }
            }
            usort($lists, static fn (array $a, array $b): int => strcmp($a['validFrom'], $b['validFrom']));
            self::$rateLists[$country] = $lists;
        }
        return self::$rateLists[$country];
    }

    /**
     * Malý resolver nad lístky korpusu (docs/exchange-rates.md § 4): poslední
     * lístek s validFrom ≤ D, nejvýš 7 dní starý (nebo do validTo), měna
     * v něm; kurz za jednotku = rate / amount na 6 míst.
     *
     * @return array{rate: float, listType: string, validFrom: string}|null
     */
    private static function rateFor(string $country, string $currency, string $date): ?array
    {
        $latest = null;
        foreach (self::rateLists($country) as $list) {
            if ($list['validFrom'] <= $date && ($latest === null || $list['validFrom'] > $latest['validFrom'])) {
                $latest = $list;
            }
        }
        if ($latest === null) {
            return null;
        }
        if (($latest['validTo'] ?? null) !== null) {
            if ($date > $latest['validTo']) {
                return null;
            }
        } elseif ((new \DateTimeImmutable($latest['validFrom']))->diff(new \DateTimeImmutable($date))->days > self::MAX_AGE_DAYS) {
            return null;
        }
        foreach ($latest['rates'] as $entry) {
            if ($entry['currency'] === $currency) {
                return [
                    'rate' => round((float) $entry['rate'] / max(1, (int) $entry['amount']), 6),
                    'listType' => (string) $latest['listType'],
                    'validFrom' => (string) $latest['validFrom'],
                ];
            }
        }
        return null;
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

    /**
     * `expected/messages.json` (F4, tasks/testing-phase4.md): zpráva → buňka
     * korpusu (`stem`, `variant`, `form`) — právě jeden záznam per zpráva,
     * stem = PDF zprávy a datový soubor korpusu, podoba jedna ze dvou.
     */
    #[DataProvider('datasets')]
    public function testMessagesMapEveryMessageToACorpusCell(string $country, string $profile): void
    {
        $dir = self::datasetDir($country, $profile);
        $messages = self::messages($dir);
        $records = self::readList($dir . '/expected/messages.json');

        $this->assertSame(array_column($messages, 'messageId'), array_column($records, 'messageId'), 'messages.json: právě jeden záznam per zpráva, v pořadí zpráv');
        $byId = array_column($messages, 'stem', 'messageId');
        foreach ($records as $record) {
            $this->assertSame(['messageId', 'stem', 'variant', 'form'], array_keys($record));
            $this->assertSame($byId[$record['messageId']], $record['stem'], 'stem = název PDF zprávy');
            $this->assertFileExists(self::repoRoot() . "/tests/corpus/{$country}/data/{$record['stem']}.jsonc");
            $data = JsoncParser::parseFile(self::repoRoot() . "/tests/corpus/{$country}/data/{$record['stem']}.jsonc");
            $this->assertSame($data['variant'], $record['variant']);
            $this->assertSame($data['form'], $record['form']);
            $this->assertContains($record['form'], ['pdf', 'pdfIsdoc']);
        }
    }

    #[DataProvider('datasets')]
    public function testRecordsRecomputeFromCorpus(string $country, string $profile): void
    {
        $dir = self::datasetDir($country, $profile);
        $vatPayer = self::vatAgenda($dir);
        $home = self::homeCurrency($dir);
        $mapping = self::mapping($country);
        $costs = array_column(self::readList($dir . '/expected/costs.json'), null, 'messageId');
        $vat = array_column(self::readList($dir . '/expected/vat.json'), null, 'messageId');
        $problems = [];

        foreach (self::messages($dir) as ['messageId' => $id, 'stem' => $stem]) {
            $doc = self::expectedDoc($country, $profile, $stem);
            $totals = $doc['totals'];
            $accDate = self::accountingDate($doc);

            // Kurz k účetnímu datu (D73): domácí měna 1, jinak z lístků korpusu.
            $rate = 1.0;
            if ($doc['currency'] !== $home) {
                $found = self::rateFor($country, $doc['currency'], $accDate);
                if ($found === null) {
                    $problems[] = "{$id}: bez lístku pro {$doc['currency']} k {$accDate} v tests/corpus/{$country}/exchange-rates";
                    continue;
                }
                $rate = $found['rate'];
            }

            // Náklad: plátce základ, neplátce základ + DPH, bez DPH celková částka; v domácí měně.
            $cost = $totals['totalBase'] === null
                ? (float) $totals['totalAmount']
                : ($vatPayer ? (float) $totals['totalBase'] : (float) $totals['totalBase'] + (float) $totals['totalVat']);
            $cost = round($cost * $rate, 2);
            $record = $costs[$id] ?? [];
            if (($record['currency'] ?? null) !== $doc['currency'] || !self::near($cost, $record['cost'] ?? null)) {
                $problems[] = "{$id}: costs.json čeká {$cost} ({$doc['currency']} × {$rate}), je " . json_encode($record);
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
            $expectedReturn = [];
            $expectedSection = null;
            $rates = [];
            if ($spec['regime'] !== 'none') {
                $czRate = $spec['czRate'] ?? null;
                $recap = $doc['vatRecap'];
                if ($czRate !== null && $recap === []) {
                    // Samovyměření bez rekapitulace (bez DPH, třetí země): základ = celková částka.
                    $recap = [['vatPct' => 0, 'base' => $totals['totalAmount'], 'tax' => 0]];
                }
                foreach ($recap as $entry) {
                    $pct = (int) $entry['vatPct'];
                    $base = round((float) $entry['base'] * $rate, 2);
                    if ($czRate === null) {
                        if ($pct === 0) {
                            continue;
                        }
                        $tax = round((float) $entry['tax'] * $rate, 2);
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

            $period = $spec['regime'] === 'none' ? null : substr($accDate, 0, 7);
            if (self::field($record, 'period') !== $period || ($record['currency'] ?? null) !== $doc['currency']) {
                $problems[] = "{$id}: období / měna záznamu neodpovídá účetnímu datu {$accDate}";
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
                'taxPointDate' => $accDate,
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

    /**
     * `expected/exchange-rates.json` (D73): právě doklady v cizí měně
     * v pořadí zpráv, datum kurzu = účetní datum (DUZP, bez něj vystavení),
     * lístek a kurz podle lístků korpusu.
     */
    #[DataProvider('datasets')]
    public function testExchangeRateRecordsMatchCorpus(string $country, string $profile): void
    {
        $dir = self::datasetDir($country, $profile);
        $home = self::homeCurrency($dir);
        $records = self::readList($dir . '/expected/exchange-rates.json');
        $problems = [];
        $foreign = [];
        foreach (self::messages($dir) as ['messageId' => $id, 'stem' => $stem]) {
            $doc = self::expectedDoc($country, $profile, $stem);
            if ($doc['currency'] === $home) {
                continue;
            }
            $foreign[$id] = $doc;
        }
        $this->assertSame(array_keys($foreign), array_column($records, 'messageId'), 'exchange-rates.json: právě doklady v cizí měně, v pořadí zpráv');

        foreach ($records as $record) {
            $id = (string) $record['messageId'];
            $doc = $foreign[$id];
            if (array_keys($record) !== ['messageId', 'currency', 'rateDate', 'listType', 'validFrom', 'rate']) {
                $problems[] = "{$id}: klíče " . implode(',', array_keys($record));
                continue;
            }
            $accDate = self::accountingDate($doc);
            $found = self::rateFor($country, $doc['currency'], $accDate);
            if ($found === null) {
                $problems[] = "{$id}: bez lístku pro {$doc['currency']} k {$accDate}";
                continue;
            }
            $want = ['currency' => $doc['currency'], 'rateDate' => $accDate, 'listType' => $found['listType'], 'validFrom' => $found['validFrom']];
            foreach ($want as $key => $value) {
                if ($record[$key] !== $value) {
                    $problems[] = "{$id}: {$key} čeká " . json_encode($value) . ', je ' . json_encode($record[$key]);
                }
            }
            if (!is_numeric($record['rate']) || abs((float) $record['rate'] - $found['rate']) > self::RATE_TOLERANCE) {
                $problems[] = "{$id}: kurz čeká {$found['rate']}, je " . json_encode($record['rate']);
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
