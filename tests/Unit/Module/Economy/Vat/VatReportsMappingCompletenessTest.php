<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\VatOutputsMapping;

/**
 * Úplnost mapování DPH výstupů (tasks/taxes-phase01.md, issue #55, D3 —
 * vzor VatAnalyticsCompletenessTest): každý kód z world.vat.cz má záznam
 * ve vat-reports-cz.jsonc a naopak; DP3 řádek se shoduje s `vatReturnRow`
 * číselníku (dokud pole existuje — chrání před překlepem při přepisu);
 * tvary záznamů drží kontrakt configu. Programová kontrola — žádný ruční
 * seznam.
 */
class VatReportsMappingCompletenessTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const KH_GROUPS = ['A1', 'A2', 'A4A5', 'B1', 'B2B3'];
    /** Druhy podání (#55 D16) — zdroj pravdy je VatOutputsMapping::FILING_KINDS. */
    private const FILING_KINDS = VatOutputsMapping::FILING_KINDS;
    private const REPORT_TYPE_KEYS = [
        'validFrom', 'filingKinds', 'supplementaryMode', 'dateFoundRequiredFor', 'roundingUnit',
        'accounting',
    ];

    /** Zaúčtování přiznání (#55 D29) — jen typ, který se účtuje. */
    private const ACCOUNTING_KEYS = [
        'payableDueDays', 'refundDueDays', 'specificSymbolPrefix', 'constantSymbol', 'excludeAccounts',
    ];
    /** Odpočtové řádky se sloupcem „V plné výši" / „Krácený odpočet". */
    private const DP3_DEDUCTION_ROWS = [40, 41, 43, 44];

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        $cfg = JsoncParser::parseFile(self::MODULES . '/world/vat/config/vat-cz.jsonc');
        $this->assertNotEmpty($cfg['vatCodes']);
        return $cfg['vatCodes'];
    }

    /** @return array<string, mixed> celý config vat-reports-cz.jsonc */
    private function reportsConfig(): array
    {
        return JsoncParser::parseFile(
            self::MODULES . '/economy/vat/config/vat-reports-cz.jsonc',
        );
    }

    public function testEveryCodeHasMappingAndNothingExtra(): void
    {
        $codes   = array_keys($this->vatCodes());
        $mapping = array_keys($this->reportsConfig()['vatOutputs']);

        $this->assertSame(
            [],
            array_diff($codes, $mapping),
            'Kódy číselníku bez záznamu v mapování (i vyloučení musí být explicitní null)',
        );
        $this->assertSame(
            [],
            array_diff($mapping, $codes),
            'Záznamy mapování bez kódu v číselníku',
        );
    }

    public function testDp3RowsMatchVatReturnRow(): void
    {
        $codes   = $this->vatCodes();
        $mapping = $this->reportsConfig()['vatOutputs'];

        foreach ($codes as $code => $def) {
            if (!array_key_exists('vatReturnRow', $def)) {
                // Odstranění vatReturnRow z world.vat je samostatný úklid
                // po M1 — pak tato větev vyřadí kód z kontroly.
                continue;
            }
            $expected = (int) $def['vatReturnRow'];
            $dp3      = $mapping[$code]['dp3'] ?? null;
            if ($expected === 0) {
                $this->assertNull($dp3, "Kód {$code}: vatReturnRow 0, ale mapování má dp3 řádek");
                continue;
            }
            $this->assertNotNull($dp3, "Kód {$code}: vatReturnRow {$expected}, ale mapování má dp3 null");
            $this->assertSame(
                $expected,
                (int) $dp3['row'],
                "Kód {$code}: dp3.row nesouhlasí s vatReturnRow číselníku",
            );
        }
    }

    public function testRecordShapes(): void
    {
        $mapping = $this->reportsConfig()['vatOutputs'];

        foreach ($mapping as $code => $record) {
            $this->assertSame(
                ['dp3', 'kh', 'sh'],
                array_keys($record),
                "Kód {$code}: záznam musí mít právě klíče dp3/kh/sh",
            );

            $dp3 = $record['dp3'];
            if ($dp3 !== null) {
                $this->assertIsInt($dp3['row'], "Kód {$code}: dp3.row musí být int");
                if (isset($dp3['col'])) {
                    $this->assertContains($dp3['col'], ['full', 'reduced'], "Kód {$code}: dp3.col");
                    $this->assertContains(
                        $dp3['row'],
                        self::DP3_DEDUCTION_ROWS,
                        "Kód {$code}: dp3.col patří jen odpočtovým řádkům 40/41/43/44",
                    );
                } else {
                    $this->assertNotContains(
                        $dp3['row'],
                        self::DP3_DEDUCTION_ROWS,
                        "Kód {$code}: odpočtový řádek {$dp3['row']} musí mít dp3.col",
                    );
                }
            }

            $kh = $record['kh'];
            if ($kh !== null) {
                $this->assertContains($kh['group'], self::KH_GROUPS, "Kód {$code}: kh.group");
                // Kód předmětu plnění A.1 / B.1 nese rekapitulace dokladu,
                // ne mapování kódu DPH (#89 D13).
                $this->assertArrayNotHasKey(
                    'kodPredPl',
                    $kh,
                    "Kód {$code}: kodPredPl do mapování nepatří — předmět plnění je v rekapitulaci dokladu",
                );
            }

            $sh = $record['sh'];
            if ($sh !== null) {
                $this->assertContains($sh['kod'], [0, 3], "Kód {$code}: sh.kod musí být 0 (zboží) | 3 (služby)");
            }
        }
    }

    public function testReportTypesShape(): void
    {
        $config = $this->reportsConfig();
        $this->assertArrayHasKey('reportTypes', $config, 'sekce reportTypes (#58)');

        // Všechny tři typy musí druhy podání deklarovat — typ bez
        // `filingKinds` by znamenal „za toto tvrzení nelze nic podat".
        $this->assertSame(
            ['return', 'cs', 'rs'],
            array_keys($config['reportTypes']),
            'reportTypes: právě tři typy výstupu v kanonickém pořadí',
        );

        foreach ($config['reportTypes'] as $type => $entry) {
            $this->assertSame(
                [],
                array_diff(array_keys($entry), self::REPORT_TYPE_KEYS),
                "reportTypes.{$type}: neznámý klíč (validTo záměrně neexistuje)",
            );
            if ($type === 'return') {
                $this->assertSame(
                    self::ACCOUNTING_KEYS,
                    array_keys($entry['accounting'] ?? []),
                    'reportTypes.return.accounting: kompletní blok zaúčtování přiznání',
                );
            } else {
                $this->assertArrayNotHasKey('accounting', $entry, "reportTypes.{$type}: hlášení se neúčtují");
            }

            if (array_key_exists('validFrom', $entry)) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $entry['validFrom'],
                    "reportTypes.{$type}.validFrom: ISO datum",
                );
            }

            $kinds = $entry['filingKinds'] ?? [];
            $this->assertNotEmpty($kinds, "reportTypes.{$type}.filingKinds: aspoň jeden druh podání");
            $this->assertSame(
                [],
                array_diff($kinds, self::FILING_KINDS),
                "reportTypes.{$type}.filingKinds: neznámý druh podání",
            );
            $this->assertContains('regular', $kinds, "reportTypes.{$type}: řádné podání musí být povolené");
            // Legislativa zná po lhůtě u přiznání dodatečné, u hlášení
            // následné — nikdy oba (#55 D16).
            $this->assertFalse(
                in_array('supplementary', $kinds, true) && in_array('subsequent', $kinds, true),
                "reportTypes.{$type}: dodatečné a následné se u jednoho typu vylučují",
            );

            if (in_array('supplementary', $kinds, true)) {
                $this->assertContains(
                    $entry['supplementaryMode'] ?? 'full',
                    ['diff', 'full'],
                    "reportTypes.{$type}.supplementaryMode",
                );
            } else {
                $this->assertArrayNotHasKey(
                    'supplementaryMode',
                    $entry,
                    "reportTypes.{$type}: supplementaryMode bez druhu supplementary nic neřídí",
                );
            }

            $this->assertSame(
                [],
                array_diff($entry['dateFoundRequiredFor'] ?? [], $kinds),
                "reportTypes.{$type}.dateFoundRequiredFor: druh, který typ vůbec nezná",
            );

            $this->assertContains(
                $entry['roundingUnit'] ?? null,
                [1, 0.01],
                "reportTypes.{$type}.roundingUnit: celé Kč (1) nebo haléře (0.01)",
            );
        }
    }

    public function testFilingKindsCfgItemCoversEveryUsedKind(): void
    {
        $names = JsoncParser::parseFile(self::MODULES . '/economy/vat/config/filingKinds.jsonc');

        $this->assertSame(
            [],
            array_diff(array_keys($names), self::FILING_KINDS),
            'filingKinds.jsonc: druh, který VatOutputsMapping::FILING_KINDS nezná',
        );

        $used = [];
        foreach ($this->reportsConfig()['reportTypes'] as $entry) {
            foreach ($entry['filingKinds'] ?? [] as $kind) {
                $used[$kind] = true;
            }
        }
        foreach (array_keys($used) as $kind) {
            $this->assertArrayHasKey($kind, $names, "filingKinds.jsonc: chybí název druhu {$kind}");
            $this->assertNotSame('', (string) ($names[$kind]['name'] ?? ''), "filingKinds[{$kind}]: prázdný name");
            $this->assertNotSame('', (string) ($names[$kind]['name:cs'] ?? ''), "filingKinds[{$kind}]: prázdný name:cs");
        }
    }

    public function testDocStatesFilingsAutomaton(): void
    {
        $states = JsoncParser::parseFile(self::MODULES . '/economy/vat/config/docStatesFilings.jsonc');

        // JsoncParser vrací numerické klíče jako int (PHP koerce klíčů polí).
        $this->assertSame([10, 40, 90], array_keys($states), 'docStatesFilings: sestaveno / podáno / zrušeno');
        // Koncept je jediná cesta dál; podané ani zrušené podání se needituje
        // (oprava = nové podání jiného druhu, #55 D18).
        $this->assertSame([40, 90], $states['10']['goto']);
        $this->assertSame([], $states['40']['goto']);
        $this->assertSame([], $states['90']['goto']);
        $this->assertSame(1, $states['40']['readOnly'] ?? 0, 'stav Podáno je readOnly');
        $this->assertSame('active', $states['40']['viewGroup']);
        $this->assertSame('trash', $states['90']['viewGroup']);
    }

    public function testEveryUsedDp3RowHasLabel(): void
    {
        $config = $this->reportsConfig();
        $labels = $config['dp3Rows'];

        $usedRows = [];
        foreach ($config['vatOutputs'] as $record) {
            if ($record['dp3'] !== null) {
                $usedRows[(int) $record['dp3']['row']] = true;
            }
        }
        // Dopočtené řádky živého přiznání (46, 62–65).
        foreach ([46, 62, 63, 64, 65] as $computed) {
            $usedRows[$computed] = true;
        }

        foreach (array_keys($usedRows) as $row) {
            $this->assertArrayHasKey(
                (string) $row,
                $labels,
                "dp3Rows: chybí popisek řádku {$row}",
            );
            $this->assertNotSame('', (string) ($labels[(string) $row]['label'] ?? ''), "dp3Rows[{$row}]: prázdný label");
            $this->assertNotSame('', (string) ($labels[(string) $row]['label:cs'] ?? ''), "dp3Rows[{$row}]: prázdný label:cs");
        }
    }
}
