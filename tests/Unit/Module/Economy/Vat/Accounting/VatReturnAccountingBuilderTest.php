<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingBuilder;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingInput;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingPlan;

/**
 * Matice obsahu účetního dokladu přiznání (#55 D28–D30) — do posledního
 * haléře, bez DB. Účty jsou smyšlené id, čísla dle konvence rozvrhu.
 */
final class VatReturnAccountingBuilderTest extends TestCase
{
    private const MD  = VatReturnAccountingBuilder::SIDE_DEBIT;
    private const DAL = VatReturnAccountingBuilder::SIDE_CREDIT;

    private const VAT_CODES = [
        'cz-110' => ['direction' => 'input',  'fullName' => 'Tuzemsko/Vstup/Základní'],
        'cz-118' => ['direction' => 'input',  'fullName' => 'Tuzemsko/Vstup/Základní krácená'],
        'cz-120' => ['direction' => 'output', 'fullName' => 'Tuzemsko/Výstup/Základní'],
        'cz-205' => ['direction' => 'output', 'fullName' => 'EU/Výstup/Zboží/Základní (odběratel)'],
        'cz-215' => ['direction' => 'input',  'fullName' => 'EU/Vstup/Zboží/Základní'],
    ];

    private const ACCOUNTING = [
        'payableDueDays'       => 25,
        'refundDueDays'        => 60,
        'specificSymbolPrefix' => '705',
        'constantSymbol'       => '1148',
    ];

    /** @return array{id: int, number: string} */
    private static function acc(string $number): array
    {
        return ['id' => (int) substr($number, -4) + 1000, 'number' => $number];
    }

    /** @return array<string, array{id: int, number: string}> */
    private static function categories(): array
    {
        return [
            'payable'         => self::acc('343801'),
            'receivable'      => self::acc('343802'),
            'nondeductible'   => self::acc('548000'),
            'roundingCost'    => self::acc('548000'),
            'roundingRevenue' => self::acc('648000'),
        ];
    }

    private static function analytic(): \Closure
    {
        return static fn (string $code): ?array => self::acc('343' . substr($code, 3));
    }

    /** @param array<int, float|array{taxFull?: float, taxReduced?: float}> $rows */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row => $value) {
            $out[$row] = is_array($value)
                ? ['base' => 0.0, 'taxFull' => $value['taxFull'] ?? 0.0, 'taxReduced' => $value['taxReduced'] ?? 0.0]
                : ['base' => 0.0, 'taxFull' => $value, 'taxReduced' => 0.0];
        }
        return $out;
    }

    /** @param array<string, mixed> $override */
    private function input(array $override = []): VatReturnAccountingInput
    {
        $args = array_merge([
            'kind'                        => 'regular',
            'periodName'                  => '01/2026',
            'dateEnd'                     => '2026-01-31',
            'taxByCode'                   => ['cz-120' => 21000.00, 'cz-110' => 6549.78],
            'previousTaxByCode'           => [],
            'exactRows'                   => self::rows([1 => 21000.00, 40 => 6549.78, 46 => 6549.78, 62 => 21000.00, 63 => 6549.78, 64 => 14450.22]),
            'previousExactRows'           => [],
            'filedRows'                   => self::rows([1 => 21000.00, 40 => 6550.00, 46 => 6550.00, 62 => 21000.00, 63 => 6550.00, 64 => 14450.00]),
            'previousCumulativeFiledRows' => [],
            'vatCodes'                    => self::VAT_CODES,
            'analyticAccount'             => self::analytic(),
            'categoryAccounts'            => self::categories(),
            'accounting'                  => self::ACCOUNTING,
            'vatId'                       => 'CZ46343504',
            'taxOfficePerson'             => 339,
        ], $override);
        return new VatReturnAccountingInput(...$args);
    }

    private function build(array $override = []): VatReturnAccountingPlan
    {
        return (new VatReturnAccountingBuilder())->build($this->input($override));
    }

    /** @return array<string, array{side: int, amount: float}> číslo účtu → strana + částka (první řádek účtu) */
    private static function byAccount(VatReturnAccountingPlan $plan): array
    {
        $out = [];
        foreach ($plan->rows as $row) {
            $out[$row['account_number']] ??= ['side' => $row['acc_side'], 'amount' => $row['amount']];
        }
        return $out;
    }

    private static function assertBalanced(VatReturnAccountingPlan $plan): void
    {
        self::assertEqualsWithDelta($plan->summary['debit'], $plan->summary['credit'], 0.001, 'Σ MD = Σ DAL');
    }

    /** @return array<string, mixed> */
    private static function rowOf(VatReturnAccountingPlan $plan, string $number): array
    {
        foreach ($plan->rows as $row) {
            if ($row['account_number'] === $number) {
                return $row;
            }
        }
        self::fail("Řádek na účtu {$number} chybí");
    }

    // ── řádné podání ────────────────────────────────────────────────────────

    public function testRegularPayableSettlesAnalyticsAndBooksLiability(): void
    {
        $plan = $this->build();

        $this->assertTrue($plan->isOk());
        $this->assertSame([], $plan->warnings());
        $this->assertBalanced($plan);
        $this->assertSame([
            '343110' => ['side' => self::DAL, 'amount' => 6549.78],
            '343120' => ['side' => self::MD,  'amount' => 21000.00],
            '343801' => ['side' => self::DAL, 'amount' => 14450.00],
            '648000' => ['side' => self::DAL, 'amount' => 0.22],
        ], self::byAccount($plan));

        $balance = self::rowOf($plan, '343801');
        $this->assertSame(339, $balance['partner']);
        $this->assertSame('46343504', $balance['payment_reference']);
        $this->assertSame('705202601', $balance['specific_symbol']);
        $this->assertSame('1148', $balance['constant_symbol']);
        $this->assertSame('2026-02-25', $balance['due_date']);
        $this->assertSame('Odvod DPH 01/2026', $balance['description']);
        $this->assertSame('Tuzemsko/Vstup/Základní', self::rowOf($plan, '343110')['description']);
        $this->assertArrayNotHasKey('partner', self::rowOf($plan, '343110'));

        $this->assertSame(14450.00, $plan->summary['liabilityDelta']);
        $this->assertSame(0.22, $plan->summary['rounding']);
    }

    public function testRegularRefundBooksReceivableWithLongerDueDate(): void
    {
        $plan = $this->build([
            'taxByCode' => ['cz-120' => 1000.00, 'cz-110' => 6549.78],
            'exactRows' => self::rows([1 => 1000.00, 40 => 6549.78, 46 => 6549.78, 62 => 1000.00, 63 => 6549.78, 65 => 5549.78]),
            'filedRows' => self::rows([1 => 1000.00, 40 => 6550.00, 46 => 6550.00, 62 => 1000.00, 63 => 6550.00, 65 => 5550.00]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $refund = self::rowOf($plan, '343802');
        $this->assertSame(self::MD, $refund['acc_side']);
        $this->assertSame(5550.00, $refund['amount']);
        $this->assertSame('2026-04-01', $refund['due_date']);
        $this->assertSame('Nadměrný odpočet DPH 01/2026', $refund['description']);
        // Přesný odpočet 5549.78 vs. podaných 5550 → vracejí nám o 0.22 víc → výnos.
        $this->assertSame(['side' => self::DAL, 'amount' => 0.22], self::byAccount($plan)['648000']);
    }

    public function testReverseChargePairSettlesBothAnalytics(): void
    {
        $plan = $this->build([
            'taxByCode' => ['cz-215' => 307.86, 'cz-205' => 307.86],
            'exactRows' => self::rows([3 => 307.86, 43 => 307.86, 46 => 307.86, 62 => 307.86, 63 => 307.86]),
            'filedRows' => self::rows([3 => 308.00, 43 => 308.00, 46 => 308.00, 62 => 308.00, 63 => 308.00]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $this->assertSame([
            '343205' => ['side' => self::MD,  'amount' => 307.86],
            '343215' => ['side' => self::DAL, 'amount' => 307.86],
        ], self::byAccount($plan));
        $this->assertSame(0.0, $plan->summary['liabilityDelta']);
    }

    // ── krácené kódy ────────────────────────────────────────────────────────

    public function testReducedCodesSplitNondeductiblePartFromRounding(): void
    {
        $plan = $this->build([
            'taxByCode' => ['cz-120' => 1000.00, 'cz-118' => 484.60],
            'exactRows' => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 484.60], 46 => ['taxReduced' => 484.60],
                52 => 436.14, 62 => 1000.00, 63 => 436.14, 64 => 563.86,
            ]),
            'filedRows' => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 485.00], 46 => ['taxReduced' => 485.00],
                52 => 437.00, 62 => 1000.00, 63 => 437.00, 64 => 563.00,
            ]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $this->assertSame(['side' => self::DAL, 'amount' => 484.60], self::byAccount($plan)['343118']);
        $this->assertSame(['side' => self::DAL, 'amount' => 563.00], self::byAccount($plan)['343801']);
        $this->assertSame(48.46, $plan->summary['nondeductible']);
        // 548 nese náklad z krácení; zaokrouhlení zvlášť na 648.
        $rows548 = array_values(array_filter($plan->rows, static fn (array $r): bool => $r['account_number'] === '548000'));
        $this->assertCount(1, $rows548);
        $this->assertSame(self::MD, $rows548[0]['acc_side']);
        $this->assertSame(48.46, $rows548[0]['amount']);
        $this->assertSame(['side' => self::DAL, 'amount' => 0.86], self::byAccount($plan)['648000']);
    }

    public function testCoefficientIncreaseReversesNondeductible(): void
    {
        $previousExact = self::rows([
            1 => 1000.00, 40 => ['taxReduced' => 484.60], 46 => ['taxReduced' => 484.60],
            52 => 436.14, 62 => 1000.00, 63 => 436.14, 64 => 563.86,
        ]);
        $plan = $this->build([
            'kind'                        => 'supplementary',
            'taxByCode'                   => ['cz-120' => 1000.00, 'cz-118' => 484.60],
            'previousTaxByCode'           => ['cz-120' => 1000.00, 'cz-118' => 484.60],
            'exactRows'                   => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 484.60], 46 => ['taxReduced' => 484.60],
                52 => 484.60, 62 => 1000.00, 63 => 484.60, 64 => 515.40,
            ]),
            'previousExactRows'           => $previousExact,
            'filedRows'                   => self::rows([52 => 48.00, 63 => 48.00, 66 => -48.00]),
            'previousCumulativeFiledRows' => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 485.00], 46 => ['taxReduced' => 485.00],
                52 => 437.00, 62 => 1000.00, 63 => 437.00, 64 => 563.00,
            ]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        // Koeficient nahoru: náklad se vrací (DAL 548), povinnost klesla o 48 → pohledávka MD 343802.
        $this->assertSame(-48.46, $plan->summary['nondeductible']);
        $this->assertSame(['side' => self::DAL, 'amount' => 48.46], self::byAccount($plan)['548000']);
        $this->assertSame(['side' => self::MD, 'amount' => 48.00], self::byAccount($plan)['343802']);
        $this->assertSame(-48.00, $plan->summary['liabilityDelta']);
        $this->assertSame('Dodatečné přiznání DPH 01/2026', self::rowOf($plan, '343802')['description']);
    }

    // ── dodatečné a opravné ─────────────────────────────────────────────────

    public function testSupplementaryBooksOnlyDeltaAndRow66(): void
    {
        $plan = $this->build([
            'kind'                        => 'supplementary',
            'taxByCode'                   => ['cz-120' => 21500.00, 'cz-110' => 6549.78],
            'previousTaxByCode'           => ['cz-120' => 21000.00, 'cz-110' => 6549.78],
            'exactRows'                   => self::rows([1 => 21500.00, 40 => 6549.78, 46 => 6549.78, 62 => 21500.00, 63 => 6549.78, 64 => 14950.22]),
            'previousExactRows'           => self::rows([1 => 21000.00, 40 => 6549.78, 46 => 6549.78, 62 => 21000.00, 63 => 6549.78, 64 => 14450.22]),
            'filedRows'                   => self::rows([1 => 500.00, 62 => 500.00, 66 => 500.00]),
            'previousCumulativeFiledRows' => self::rows([1 => 21000.00, 40 => 6550.00, 46 => 6550.00, 62 => 21000.00, 63 => 6550.00, 64 => 14450.00]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $this->assertSame([
            '343120' => ['side' => self::MD,  'amount' => 500.00],
            '343801' => ['side' => self::DAL, 'amount' => 500.00],
        ], self::byAccount($plan));
        $this->assertSame('Dodatečné přiznání DPH 01/2026', self::rowOf($plan, '343801')['description']);
        $this->assertSame(500.00, $plan->summary['liabilityDelta']);
        $this->assertSame(0.0, $plan->summary['rounding']);
    }

    public function testSupplementaryNegativeDeltaFlipsSidesAndBooksReceivable(): void
    {
        $plan = $this->build([
            'kind'                        => 'supplementary',
            'taxByCode'                   => ['cz-120' => 20000.00, 'cz-110' => 6549.78],
            'previousTaxByCode'           => ['cz-120' => 21000.00, 'cz-110' => 6549.78],
            'exactRows'                   => self::rows([1 => 20000.00, 40 => 6549.78, 46 => 6549.78, 62 => 20000.00, 63 => 6549.78, 64 => 13450.22]),
            'previousExactRows'           => self::rows([1 => 21000.00, 40 => 6549.78, 46 => 6549.78, 62 => 21000.00, 63 => 6549.78, 64 => 14450.22]),
            'filedRows'                   => self::rows([1 => -1000.00, 62 => -1000.00, 66 => -1000.00]),
            'previousCumulativeFiledRows' => self::rows([1 => 21000.00, 40 => 6550.00, 46 => 6550.00, 62 => 21000.00, 63 => 6550.00, 64 => 14450.00]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $this->assertSame([
            '343120' => ['side' => self::DAL, 'amount' => 1000.00],
            '343802' => ['side' => self::MD,  'amount' => 1000.00],
        ], self::byAccount($plan));
        $this->assertSame('2026-04-01', self::rowOf($plan, '343802')['due_date']);
    }

    public function testCorrectiveDiffsAgainstCumulativeFiledState(): void
    {
        $plan = $this->build([
            'kind'                        => 'corrective',
            'taxByCode'                   => ['cz-120' => 21500.00, 'cz-110' => 6549.78],
            'previousTaxByCode'           => ['cz-120' => 21000.00, 'cz-110' => 6549.78],
            'exactRows'                   => self::rows([1 => 21500.00, 40 => 6549.78, 46 => 6549.78, 62 => 21500.00, 63 => 6549.78, 64 => 14950.22]),
            'previousExactRows'           => self::rows([1 => 21000.00, 40 => 6549.78, 46 => 6549.78, 62 => 21000.00, 63 => 6549.78, 64 => 14450.22]),
            // Opravné = plná náhrada: podané řádky nesou celý obsah.
            'filedRows'                   => self::rows([1 => 21500.00, 40 => 6550.00, 46 => 6550.00, 62 => 21500.00, 63 => 6550.00, 64 => 14950.00]),
            'previousCumulativeFiledRows' => self::rows([1 => 21000.00, 40 => 6550.00, 46 => 6550.00, 62 => 21000.00, 63 => 6550.00, 64 => 14450.00]),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertBalanced($plan);
        $this->assertSame([
            '343120' => ['side' => self::MD,  'amount' => 500.00],
            '343801' => ['side' => self::DAL, 'amount' => 500.00],
        ], self::byAccount($plan));
        $this->assertSame('Opravné přiznání DPH 01/2026', self::rowOf($plan, '343801')['description']);
    }

    public function testNoChangeProducesNoRows(): void
    {
        $same = self::rows([1 => 21000.00, 40 => 6549.78, 46 => 6549.78, 62 => 21000.00, 63 => 6549.78, 64 => 14450.22]);
        $filed = self::rows([1 => 21000.00, 40 => 6550.00, 46 => 6550.00, 62 => 21000.00, 63 => 6550.00, 64 => 14450.00]);
        $plan = $this->build([
            'kind'                        => 'supplementary',
            'previousTaxByCode'           => ['cz-120' => 21000.00, 'cz-110' => 6549.78],
            'exactRows'                   => $same,
            'previousExactRows'           => $same,
            'filedRows'                   => self::rows([66 => 0.0]),
            'previousCumulativeFiledRows' => $filed,
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertSame([], $plan->rows);
        $this->assertSame(
            [VatReturnAccountingBuilder::MSG_NOTHING_TO_ACCOUNT],
            array_column($plan->messages, 'code'),
        );
    }

    // ── chyby a varování ────────────────────────────────────────────────────

    public function testRoundingOutOfToleranceIsAnError(): void
    {
        // Podaná daň na výstupu (ř. 62) neodpovídá dokladům — zbytek 5 550 Kč místo haléřů.
        $plan = $this->build([
            'filedRows' => self::rows([1 => 26550.00, 40 => 6550.00, 46 => 6550.00, 62 => 26550.00, 63 => 6550.00, 64 => 20000.00]),
        ]);

        $this->assertFalse($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_ROUNDING_OUT_OF_RANGE], array_column($plan->errors(), 'code'));
        $this->assertArrayNotHasKey('648000', self::byAccount($plan));
        $this->assertArrayNotHasKey('548000', self::byAccount($plan));
    }

    public function testRoundingToleranceGrowsWithTaxRows(): void
    {
        // Tři řádky s daní (1, 40, 52) → tolerance 1.51; zbytek 0.86 projde.
        $plan = $this->build([
            'taxByCode' => ['cz-120' => 1000.00, 'cz-118' => 484.60],
            'exactRows' => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 484.60], 46 => ['taxReduced' => 484.60],
                52 => 436.14, 62 => 1000.00, 63 => 436.14, 64 => 563.86,
            ]),
            'filedRows' => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 485.00], 46 => ['taxReduced' => 485.00],
                52 => 437.00, 62 => 1000.00, 63 => 437.00, 64 => 563.00,
            ]),
        ]);
        $this->assertTrue($plan->isOk());
        $this->assertSame(0.86, $plan->summary['rounding']);
    }

    public function testMissingTaxOfficeIsWarningWithoutPartner(): void
    {
        $plan = $this->build(['taxOfficePerson' => null]);

        $this->assertTrue($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_TAX_OFFICE_MISSING], array_column($plan->warnings(), 'code'));
        $this->assertNull(self::rowOf($plan, '343801')['partner']);
        $this->assertSame('46343504', self::rowOf($plan, '343801')['payment_reference']);
    }

    public function testMissingVatIdIsWarningWithoutVariableSymbol(): void
    {
        $plan = $this->build(['vatId' => '']);

        $this->assertTrue($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_VAT_ID_MISSING], array_column($plan->warnings(), 'code'));
        $this->assertSame('', self::rowOf($plan, '343801')['payment_reference']);
    }

    public function testMissingAnalyticAccountIsError(): void
    {
        $plan = $this->build([
            'analyticAccount' => static fn (string $code): ?array => $code === 'cz-110' ? null : self::acc('343' . substr($code, 3)),
        ]);

        $this->assertFalse($plan->isOk());
        $errors = $plan->errors();
        $this->assertCount(1, $errors);
        $this->assertSame(VatReturnAccountingBuilder::MSG_ACCOUNT_MISSING, $errors[0]['code']);
        $this->assertStringContainsString('cz-110', $errors[0]['message']);
        // Ostatní řádky vzniknou, zaokrouhlení se po chybě nedopočítává.
        $this->assertArrayHasKey('343120', self::byAccount($plan));
        $this->assertArrayNotHasKey('648000', self::byAccount($plan));
    }

    public function testMissingBalanceAccountIsError(): void
    {
        $categories = self::categories();
        $categories['payable'] = null;
        $plan = $this->build(['categoryAccounts' => $categories]);

        $this->assertFalse($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_ACCOUNT_MISSING], array_column($plan->errors(), 'code'));
        $this->assertStringContainsString('343801', $plan->errors()[0]['message']);
        $this->assertArrayNotHasKey('343801', self::byAccount($plan));
    }

    public function testMissingNondeductibleAccountIsError(): void
    {
        $categories = self::categories();
        $categories['nondeductible'] = null;
        $plan = $this->build([
            'categoryAccounts' => $categories,
            'categoryMasks'    => ['nondeductible' => ['549']],
            'taxByCode'        => ['cz-120' => 1000.00, 'cz-118' => 484.60],
            'exactRows'        => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 484.60], 46 => ['taxReduced' => 484.60],
                52 => 436.14, 62 => 1000.00, 63 => 436.14, 64 => 563.86,
            ]),
            'filedRows'        => self::rows([
                1 => 1000.00, 40 => ['taxReduced' => 485.00], 46 => ['taxReduced' => 485.00],
                52 => 437.00, 62 => 1000.00, 63 => 437.00, 64 => 563.00,
            ]),
        ]);

        $this->assertFalse($plan->isOk());
        $this->assertStringContainsString('vat.nondeductible, maska 549', $plan->errors()[0]['message']);
    }

    public function testMissingRoundingAccountMessageNamesMaskChainFromRules(): void
    {
        // Výchozí scénář potřebuje zaokrouhlení 0,22 na výnos; bez účtu je
        // to chyba s maskou z předpisu (#122 D3, v NNO 649), bez masek jen
        // kategorie — žádná natvrdo zapsaná 648.
        $categories = self::categories();
        $categories['roundingRevenue'] = null;

        $plan = $this->build([
            'categoryAccounts' => $categories,
            'categoryMasks'    => ['roundingRevenue' => ['649100', '649']],
        ]);
        $this->assertFalse($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_ACCOUNT_MISSING], array_column($plan->errors(), 'code'));
        $this->assertStringContainsString('rounding.revenue, maska 649100 / 649', $plan->errors()[0]['message']);

        $plan = $this->build(['categoryAccounts' => $categories]);
        $this->assertStringContainsString('(kategorie rounding.revenue)', $plan->errors()[0]['message']);
        $this->assertStringNotContainsString('648', $plan->errors()[0]['message']);
    }

    public function testUnknownVatCodeIsWarningTreatedAsInput(): void
    {
        $plan = $this->build([
            'taxByCode' => ['cz-120' => 21000.00, 'cz-999' => 6549.78],
            'analyticAccount' => static fn (string $code): ?array => self::acc('343' . substr($code, 3)),
        ]);

        $this->assertTrue($plan->isOk());
        $this->assertSame([VatReturnAccountingBuilder::MSG_UNKNOWN_VAT_CODE], array_column($plan->warnings(), 'code'));
        $this->assertSame(['side' => self::DAL, 'amount' => 6549.78], self::byAccount($plan)['343999']);
        $this->assertSame('cz-999', self::rowOf($plan, '343999')['description']);
    }

    public function testQuarterlyPeriodUsesEndMonthInSpecificSymbol(): void
    {
        $plan = $this->build(['periodName' => 'Q1/2026', 'dateEnd' => '2026-03-31']);
        $this->assertSame('705202603', self::rowOf($plan, '343801')['specific_symbol']);
        $this->assertSame('2026-04-25', self::rowOf($plan, '343801')['due_date']);
        $this->assertSame('Odvod DPH Q1/2026', self::rowOf($plan, '343801')['description']);
    }
}
