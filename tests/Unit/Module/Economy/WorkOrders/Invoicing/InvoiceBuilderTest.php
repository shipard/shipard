<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoiceBuildException;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoiceBuilder;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;

/**
 * Builder kanonického dokladu za období (§2 zadání): dědičnost druh →
 * zakázka, řádky k DUZP, `{období}` v jazyce zákazníka, VS, dimenze,
 * zdroj, zálohová faktura, vlastní účet; výsledek prochází schématem
 * a DocumentValidator jako každý jiný payload.
 */
class InvoiceBuilderTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../../modules/economy/workOrders';

    private const KIND = [
        'id' => 4, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_due_days' => 14,
        'inv_timing' => 'start', 'inv_vat_mode' => 1, 'inv_payment_method' => 1, 'inv_bank_account' => null,
    ];

    private const ROWS = [
        ['id' => 1, 'order_pos' => 1, 'item' => 42, 'description' => 'Nájem kanceláře', 'quantity' => '1.0000', 'unit' => 4, 'unit_price' => '12000.0000', 'vat_code' => 'cz-110', 'operation' => 'sale.services', 'valid_from' => null, 'valid_to' => null, 'contributor' => null],
        ['id' => 2, 'order_pos' => 2, 'item' => null, 'description' => 'Služby spojené s nájmem', 'quantity' => '1.0000', 'unit' => 4, 'unit_price' => '2000.0000', 'vat_code' => 'cz-110', 'operation' => null, 'valid_from' => null, 'valid_to' => '2026-09-30', 'contributor' => null],
        ['id' => 3, 'order_pos' => 3, 'item' => null, 'description' => 'Služby spojené s nájmem', 'quantity' => '1.0000', 'unit' => 4, 'unit_price' => '2500.0000', 'vat_code' => 'cz-110', 'operation' => null, 'valid_from' => '2026-10-01', 'valid_to' => null, 'contributor' => null],
        ['id' => 4, 'order_pos' => 4, 'item' => null, 'description' => 'Spotřeba energií', 'quantity' => '1.0000', 'unit' => null, 'unit_price' => '1.0000', 'vat_code' => 'cz-110', 'operation' => null, 'valid_from' => null, 'valid_to' => null, 'contributor' => 'energy.consumption'],
    ];

    /** @return array<string, mixed> */
    private function workOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 6, 'number' => 'S260001', 'title' => 'Nájem kanceláře', 'customer' => 2, 'currency' => 'czk',
            'cost_center' => 1, 'payment_reference' => '20260001', 'kind' => 4, 'type' => 'periodic',
            'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_doc_text' => null,
            'inv_doc_type' => null, 'inv_number_series' => null, 'inv_due_days' => null, 'inv_timing' => null,
            'inv_vat_mode' => null, 'inv_payment_method' => null, 'inv_bank_account' => null,
        ], $overrides);
    }

    private function builder(?string $personLanguage = null, ?string $partyCountry = 'cz', ?int $defaultBank = 3, bool $customerExists = true): InvoiceBuilder
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'world.base.documentLanguages' => ['cs' => [], 'en' => [], 'sk' => [], 'de' => []],
            'world.base.countries' => ['cz' => ['languages' => ['cs']], 'de' => ['languages' => ['de']], 'sk' => ['languages' => ['sk']]],
            default => null,
        });
        $texts = JsoncParser::parseFile(self::MODULE . '/config/periodTexts.jsonc');
        $configForLanguage = function (string $language) use ($texts): ConfigRuntime {
            $c = $this->createMock(ConfigRuntime::class);
            $localized = ConfigLocalizer::localize($texts, $language);
            $c->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $id === 'economy.workOrders.periodTexts' ? $localized : null);
            return $c;
        };

        return new class($this->createMock(Connection::class), $config, 'cz', $configForLanguage, $personLanguage, $partyCountry, $defaultBank, $customerExists) extends InvoiceBuilder {
            public function __construct(
                Connection $db, ?ConfigRuntime $config, string $ownCountry, \Closure $configForLanguage,
                private readonly ?string $language, private readonly ?string $country, private readonly ?int $defaultBank, private readonly bool $customerExists,
            ) {
                parent::__construct($db, $config, $ownCountry, $configForLanguage);
            }

            protected function customerParty(int $personId): ?array
            {
                if (!$this->customerExists) {
                    return null;
                }
                $party = ['name' => 'MP toner, spol. s r.o.', 'companyId' => '12345678'];
                if ($this->country !== null) {
                    $party['country'] = $this->country;
                    $party['address'] = ['city' => 'Zlín', 'country' => $this->country];
                }
                return $party;
            }

            protected function personLanguage(int $personId): ?string
            {
                return $this->language;
            }

            protected function costCenterCode(int $costCenterId): ?string
            {
                return $costCenterId === 1 ? 'S01' : null;
            }

            protected function itemCode(int $itemId): ?string
            {
                return $itemId === 42 ? 'NAJEM' : null;
            }

            protected function unitShortcut(?int $unitId): ?string
            {
                return $unitId === 4 ? 'měs' : null;
            }

            protected function defaultBankAccount(): ?int
            {
                return $this->defaultBank;
            }
        };
    }

    private function october(): Period
    {
        return new Period('2026-10-01', '2026-10-31');
    }

    /** Kanonický doklad musí projít schématem i validátorem jako každý jiný payload (Q1). */
    private function assertValidCanonical(array $canonical): void
    {
        $schemaIssues = (new SchemaValidator(SchemaLoader::default()))->validate($canonical, DocumentApplier::FORMAT_ID, DocumentApplier::FORMAT_VERSION);
        $this->assertSame([], $schemaIssues, json_encode($schemaIssues, JSON_UNESCAPED_UNICODE));
        $errors = array_filter((new DocumentValidator())->validate($canonical), static fn(array $i): bool => $i['severity'] === 'error');
        $this->assertSame([], array_values($errors), json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    public function testBuildsIssuedInvoiceDraftFromKindDefaultsAndWorkOrder(): void
    {
        $built = $this->builder()->build($this->workOrder(), self::KIND, $this->october(), self::ROWS);
        $c = $built->canonical;
        $this->assertValidCanonical($c);

        $this->assertSame('invoiceIssued', $c['docType']);
        $this->assertSame('supplier', $c['selfParty']);
        $this->assertSame('MP toner, spol. s r.o.', $c['customer']['name']);
        $this->assertSame('useExisting:2', $c['_resolve']['customer']['userAction']);
        $this->assertSame('CZK', $c['currency']);
        $this->assertSame(
            ['issueDate' => '2026-10-01', 'accountingDate' => '2026-10-01', 'taxPointDate' => '2026-10-01', 'dueDate' => '2026-10-15', 'periodFrom' => '2026-10-01', 'periodTo' => '2026-10-31'],
            $c['dates'],
        );
        $this->assertSame('fromBase', $c['vat']['mode']);
        $this->assertSame(['method' => 'bankTransfer', 'paymentReference' => '20260001'], $c['payment']);
        $this->assertSame(['workOrder' => 'S260001', 'costCenter' => 'S01'], $c['dimensions']);
        $this->assertSame(['kind' => 'workOrder'], $c['source']);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesId' => 1, 'importOwnBankAccount' => 3], $c['applyOptions']);
        $this->assertSame('Nájem kanceláře říjen 2026', $c['docText']);
        $this->assertSame('cs', $built->language);
        $this->assertSame('2026-10-01', $built->billingDate);
        $this->assertSame('říjen 2026', $built->periodLabel);
    }

    public function testRowsValidAtTaxPointDateAndContributorRowsStartEmpty(): void
    {
        $built = $this->builder()->build($this->workOrder(), self::KIND, $this->october(), self::ROWS);
        $rows = $built->canonical['rows'];

        // Řádek 2 platil do 30. 9., řádek 3 od 1. 10. — říjen má novou cenu.
        $this->assertSame([1, 3, 4], array_column($built->rows, 'id'));
        $this->assertSame([1, 2, 3], array_column($rows, 'orderPos'));
        $this->assertSame(['ourCode' => 'NAJEM', 'name' => 'Nájem kanceláře'], $rows[0]['item']);
        $this->assertSame('sale.services', $rows[0]['operation']);
        $this->assertSame('měs', $rows[0]['unit']);
        $this->assertSame(12000.0, $rows[0]['unitPrice']);
        $this->assertSame(['code' => 'cz-110'], $rows[0]['vat']);
        $this->assertSame([['index' => 0, 'item' => ['userAction' => 'useExisting:42']]], $built->canonical['_resolve']['rows']);
        $this->assertArrayNotHasKey('item', $rows[1]);
        $this->assertArrayNotHasKey('operation', $rows[1]);
        $this->assertSame(2500.0, $rows[1]['unitPrice']);
        // Řádek přispěvatele vzniká s množstvím 0 (D10).
        $this->assertSame(0.0, $rows[2]['quantity']);
        $this->assertArrayNotHasKey('unit', $rows[2]);

        // Září: řádek 2 (stará cena), řádek 3 ještě ne.
        $september = $this->builder()->build($this->workOrder(), self::KIND, new Period('2026-09-01', '2026-09-30'), self::ROWS);
        $this->assertSame([1, 2, 4], array_column($september->rows, 'id'));
    }

    public function testWorkOrderOverridesKindAndPeriodicityAndTimingShapeDatesAndText(): void
    {
        $workOrder = $this->workOrder([
            'inv_doc_type' => 'invpo', 'inv_number_series' => 2, 'inv_due_days' => 10, 'inv_timing' => 'end',
            'inv_vat_mode' => 2, 'inv_payment_method' => 0, 'inv_bank_account' => 9, 'inv_periodicity' => 'quarter',
            'inv_doc_text' => 'Záloha na nájem — {období}', 'payment_reference' => null, 'cost_center' => null,
        ]);
        $built = $this->builder()->build($workOrder, self::KIND, new Period('2026-07-01', '2026-09-30'), self::ROWS);
        $c = $built->canonical;
        $this->assertValidCanonical($c);

        $this->assertSame('proformaIssued', $c['docType']);
        $this->assertSame('2026-09-30', $c['dates']['issueDate']);
        $this->assertSame('2026-10-10', $c['dates']['dueDate']);
        $this->assertSame('fromTotal', $c['vat']['mode']);
        $this->assertSame(['method' => 'cash'], $c['payment']);
        $this->assertSame(['workOrder' => 'S260001'], $c['dimensions']);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesId' => 2, 'importOwnBankAccount' => 9], $c['applyOptions']);
        $this->assertSame('Záloha na nájem — 3. čtvrtletí 2026', $c['docText']);
        // Konec září: řádek 2 ještě platí, řádek 3 ne.
        $this->assertSame([1, 2, 4], array_column($built->rows, 'id'));
    }

    public function testLanguageFollowsCustomerAndVatModeNoneDropsVatCodes(): void
    {
        $built = $this->builder(personLanguage: 'de', partyCountry: 'cz')->build($this->workOrder(), self::KIND, $this->october(), self::ROWS);
        $this->assertSame('de', $built->language);
        $this->assertSame('Nájem kanceláře Oktober 2026', $built->canonical['docText']);

        $built = $this->builder(personLanguage: null, partyCountry: 'sk')->build($this->workOrder(), self::KIND, $this->october(), self::ROWS);
        $this->assertSame('sk', $built->language);
        $this->assertSame('október 2026', $built->periodLabel);

        $built = $this->builder()->build($this->workOrder(['inv_vat_mode' => 0]), self::KIND, $this->october(), self::ROWS);
        $this->assertSame('none', $built->canonical['vat']['mode']);
        $this->assertArrayNotHasKey('vat', $built->canonical['rows'][0]);
        $this->assertValidCanonical($built->canonical);
    }

    public function testBankAccountFallsBackToDefaultAndMayBeAbsent(): void
    {
        $built = $this->builder(defaultBank: null)->build($this->workOrder(), self::KIND, $this->october(), self::ROWS);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesId' => 1], $built->canonical['applyOptions']);

        $kind = self::KIND + [];
        $kind['inv_bank_account'] = 5;
        $built = $this->builder(defaultBank: 3)->build($this->workOrder(), $kind, $this->october(), self::ROWS);
        $this->assertSame(5, $built->canonical['applyOptions']['importOwnBankAccount']);
    }

    public function testMissingPrescriptionPartsAreReportedAsBuildReasons(): void
    {
        $cases = [
            [InvoiceBuildException::NO_DOC_TYPE, $this->workOrder(), ['id' => 4, 'type' => 'periodic'], self::ROWS, true],
            [InvoiceBuildException::NO_SERIES, $this->workOrder(['inv_doc_type' => 'invno']), ['id' => 4, 'type' => 'periodic'], self::ROWS, true],
            [InvoiceBuildException::NO_CUSTOMER, $this->workOrder(['customer' => null]), self::KIND, self::ROWS, true],
            [InvoiceBuildException::NO_CUSTOMER, $this->workOrder(), self::KIND, self::ROWS, false],
            [InvoiceBuildException::NO_ROWS, $this->workOrder(), self::KIND, new Period('2025-01-01', '2025-01-31'), true],
        ];
        foreach ($cases as [$reason, $workOrder, $kind, $rowsOrPeriod, $customerExists]) {
            $period = $rowsOrPeriod instanceof Period ? $rowsOrPeriod : $this->october();
            $rows = $rowsOrPeriod instanceof Period
                ? [['id' => 9, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'valid_from' => '2026-01-01', 'valid_to' => null]]
                : $rowsOrPeriod;
            try {
                $this->builder(customerExists: $customerExists)->build($workOrder, $kind, $period, $rows);
                $this->fail("Expected {$reason}");
            } catch (InvoiceBuildException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function testRowsValidAtTreatsNullAsUnbounded(): void
    {
        $rows = [
            ['id' => 1, 'valid_from' => null, 'valid_to' => null],
            ['id' => 2, 'valid_from' => '2026-10-01', 'valid_to' => '2026-10-01'],
            ['id' => 3, 'valid_from' => '2026-10-02', 'valid_to' => null],
            ['id' => 4, 'valid_from' => null, 'valid_to' => '2026-09-30'],
        ];
        $this->assertSame([1, 2], array_column(InvoiceBuilder::rowsValidAt($rows, '2026-10-01'), 'id'));
        $this->assertSame([1, 3], array_column(InvoiceBuilder::rowsValidAt($rows, '2026-10-02'), 'id'));
    }
}
