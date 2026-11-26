<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Docs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Viewer\Detail\DetailContent;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\Docs\DocSales;
use Shipard\Module\Economy\SalesRegistration\Docs\DocSalesDetailExtension;
use Shipard\Module\Economy\SalesRegistration\Docs\DocSalesRowDecorator;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocSaleEvaluator;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Evidence tržeb na dokladu (#171 D13, U18, U19): záložka detailu
 * s vyhodnocením a tržbami, štítek hlavičky a štítek „EET“ v seznamu —
 * stav dokladu je nejhorší stav jeho tržeb.
 */
class DocSalesExtensionsTest extends TestCase
{
    use SalesRegistrationConfig;

    private const TABLE = 'docs_core_heads';

    /** @return list<RegistrationFacts> */
    private function registrations(): array
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        return [new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])];
    }

    private function context(string $language = 'cs'): ModuleExtensionContext
    {
        return new ModuleExtensionContext(
            self::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(),
            null,
            $language,
        );
    }

    /**
     * @param list<array<string, mixed>> $sales
     * @param list<RegistrationFacts>|null $registrations
     */
    private function extension(array $sales = [], ?array $registrations = null): DocSalesDetailExtension
    {
        $registrations ??= $this->registrations();
        return new class($sales, $registrations, $this->salesRegistrationConfig()) extends DocSalesDetailExtension {
            public int $salesReads = 0;

            /**
             * @param list<array<string, mixed>> $testSales
             * @param list<RegistrationFacts> $registrations
             */
            public function __construct(
                private readonly array $testSales,
                private readonly array $registrations,
                private readonly mixed $config,
            ) {
            }

            protected function docSales(ModuleExtensionContext $context): DocSales
            {
                return new DocSales((new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor(), $this->config);
            }

            protected function loadSales(int $docId, ModuleExtensionContext $context): array
            {
                $this->salesReads++;
                return $this->testSales;
            }

            protected function facts(array $record, ModuleExtensionContext $context): DocFacts
            {
                return DocFacts::fromRows($record, [], ['code' => (string) ($record['_series'] ?? 'invno')], 'cz');
            }

            protected function evaluation(DocFacts $doc, ModuleExtensionContext $context): DocEvaluation
            {
                $pinned = $this->testSales !== [] ? 1 : null;
                return (new DocSaleEvaluator())->evaluate(
                    $doc,
                    $this->registrations,
                    new \DateTimeImmutable((string) $doc->stateChangedAt, new \DateTimeZone('UTC')),
                    $pinned,
                );
            }

            protected function policy(ModuleExtensionContext $context): ContactPaymentPolicy
            {
                return new ContactPaymentPolicy($this->registrations);
            }
        };
    }

    /** @param array<string, mixed> $override */
    private function record(array $override = []): array
    {
        return $override + [
            'id' => 10, 'doc_type' => 'cashreg', 'docState' => 40, 'doc_number' => 'PR1270001', 'issue_date' => '2027-02-10',
            'payment_method' => 0, 'cash_dir' => 0, 'total_amount_dom' => '1234.50', 'home_currency' => 'czk', 'imported' => 0,
            'doc_state_changed_at' => '2027-02-10 09:30:00', 'contact_payment' => 0, 'contact_payment_at' => null,
            '_series' => 'cashreg-P1',
        ];
    }

    /** @param array<string, mixed> $override */
    private function sale(int $id, array $override = []): array
    {
        return $override + [
            'id' => $id, 'doc_sequence' => $id, 'serial_number' => 'PR1270001' . ($id > 1 ? '-' . $id : ''),
            'sold_at' => '2027-02-10 09:30:00', 'amount' => '1234.50', 'currency' => 'czk', 'state' => SaleState::CONFIRMED,
            'confirmation_code' => 'b3a09b52-7c87-4014-a496-4c7a53cf9125-ff', 'attempt_count' => 1, 'test' => 0,
        ];
    }

    /** @return array<string, mixed> obsah jediné záložky */
    private function tabContent(DocSalesDetailExtension $extension, array $record, string $language = 'cs'): array
    {
        $tabs = $extension->tabs(self::TABLE, $record, $this->context($language));
        $this->assertCount(1, $tabs);
        $this->assertSame('sales', $tabs[0]['id']);
        return $tabs[0]['content'];
    }

    /** @return array<string, string> popisek → hodnota napříč skupinami */
    private function properties(array $content): array
    {
        $items = [];
        foreach ($content['blocks'][0]['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $items[$group['title'] . ' / ' . $item['label']] = $item['value'];
            }
        }
        return $items;
    }

    // ── Záložka detailu (U18) ───────────────────────────────────────────────

    public function testDocumentThatFallsShowsTheEvaluationAndItsSales(): void
    {
        $extension = $this->extension([$this->sale(1)]);

        $content = $this->tabContent($extension, $this->record());

        $this->assertSame('Evidence tržeb', $extension->tabs(self::TABLE, $this->record(), $this->context())[0]['label']);
        $this->assertSame('composite', $content['type']);
        $this->assertSame(
            [
                'Vyhodnocení / Evidence tržeb'     => 'Spadá — 1 234,50 CZK',
                'Vyhodnocení / Čím spadá'          => 'typem dokladu a způsobem platby',
                'Vyhodnocení / Evidenční jednotka' => '181',
                'Vyhodnocení / Pokladní zařízení'  => 'cashreg-P1',
            ],
            $this->properties($content),
        );

        $table = $content['blocks'][1];
        $this->assertSame('table', $table['type']);
        $this->assertSame(['serial_number', 'sold_at', 'amount', 'state', 'code', 'attempts'], array_column($table['columns'], 'id'));
        $this->assertTrue($table['columns'][0]['link']);
        $this->assertSame(
            [
                'serial_number' => 'PR1270001',
                'sold_at'       => '10.02.2027 09:30',
                'amount'        => '1 234,50 CZK',
                'state'         => 'Potvrzeno',
                'code'          => '…f9125-ff',
                'attempts'      => '1',
                '_action'       => ['id' => 'openSale', 'kind' => 'open_detail', 'target' => ['viewerId' => 'economy.salesRegistration.sales', 'recordId' => 1]],
            ],
            $table['rows'][0],
        );
        $this->assertCount(1, $table['rows'], 'jedna tržba nemá součtový řádek');
    }

    public function testCancelledDocumentShowsBothSalesAndTheirZeroTotal(): void
    {
        $extension = $this->extension([
            $this->sale(1, ['test' => 1]),
            $this->sale(2, ['amount' => '-1234.50', 'state' => SaleState::FAILED, 'confirmation_code' => null, 'attempt_count' => 3]),
        ]);

        $content = $this->tabContent($extension, $this->record(['docState' => 30]));

        $this->assertSame(
            [
                'Vyhodnocení / Evidence tržeb' => 'Nespadá',
                'Vyhodnocení / Důvod'          => 'Doklad je stornovaný — do evidence tržeb z něj nespadá nic.',
            ],
            $this->properties($content),
        );
        $rows = $content['blocks'][1]['rows'];
        $this->assertSame('Potvrzeno · test', $rows[0]['state'], 'potvrzení z playgroundu je označené');
        $this->assertSame('error', $rows[1]['_class'], 'odmítnutá tržba je zvýrazněná');
        $this->assertSame('', $rows[1]['code']);
        $this->assertSame(['serial_number' => 'Celkem', 'sold_at' => '', 'amount' => '0,00 CZK', 'state' => '', 'code' => '', 'attempts' => '', '_class' => 'total'], $rows[2]);
    }

    public function testUnregistrableDocumentSaysWhyInWords(): void
    {
        $type = $this->registrations()[0]->type;
        $extension = $this->extension([], [new RegistrationFacts(1, $type, '2027-01-01', null, null, [])]);

        $content = $this->tabContent($extension, $this->record());

        $properties = $this->properties($content);
        $this->assertSame('Spadá, ale tržbu nejde založit', $properties['Vyhodnocení / Evidence tržeb']);
        $this->assertStringContainsString('Evidenční jednotku nemá', $properties['Vyhodnocení / Důvod']);
        $this->assertSame(['type' => 'empty', 'text' => 'Doklad zatím nemá žádnou tržbu.'], $content['blocks'][1]);
    }

    public function testInvoiceByTransferOffersTheContactPayment(): void
    {
        $extension = $this->extension();
        $invoice = $this->record(['doc_type' => 'invno', 'payment_method' => 1, 'doc_number' => 'FV1270001', '_series' => 'invno']);

        $content = $this->tabContent($extension, $invoice);

        $this->assertSame(
            [
                'Vyhodnocení / Evidence tržeb'       => 'Nespadá',
                'Vyhodnocení / Důvod'                => 'Typ dokladu a způsob platby do evidence tržeb nespadají.',
                'Kontaktní platba / Kontaktní platba' => 'Ne',
            ],
            $this->properties($content),
        );
        $this->assertSame('notice', $content['blocks'][1]['type']);
        $this->assertSame('info', $content['blocks'][1]['tone']);
        $this->assertStringContainsString('akcí Kontaktní platba', $content['blocks'][1]['text']);
        $this->assertSame('empty', $content['blocks'][2]['type']);
    }

    public function testInvoiceWithContactPaymentShowsTheFlagAndPaymentTime(): void
    {
        $invoice = $this->record([
            'doc_type' => 'invno', 'payment_method' => 1, '_series' => 'invno',
            'contact_payment' => 1, 'contact_payment_at' => '2027-02-10 10:15:00',
        ]);

        $properties = $this->properties($this->tabContent($this->extension([$this->sale(1)]), $invoice));

        $this->assertSame('Spadá — 1 234,50 CZK', $properties['Vyhodnocení / Evidence tržeb']);
        $this->assertSame('kontaktní platbou', $properties['Vyhodnocení / Čím spadá']);
        $this->assertSame('Ano', $properties['Kontaktní platba / Kontaktní platba']);
        $this->assertSame('10.02.2027 10:15', $properties['Kontaktní platba / Čas platby']);

        // Příznak bez času (koncept): čas tržby bude čas přechodu.
        $concept = ['docState' => 10, 'contact_payment_at' => null] + $invoice;
        $properties = $this->properties($this->tabContent($this->extension(), $concept));
        $this->assertSame('Doklad není ve stavu V pořádku.', $properties['Vyhodnocení / Důvod']);
        $this->assertSame('čas přechodu dokladu do stavu V pořádku', $properties['Kontaktní platba / Čas platby']);
    }

    public function testDocumentOutsideTheRegistrationHasNoTabAndNoBadge(): void
    {
        $extension = $this->extension();
        foreach ([
            'přijatá faktura'     => $this->record(['doc_type' => 'invni', 'payment_method' => 1]),
            'faktura bránou'      => $this->record(['doc_type' => 'invno', 'payment_method' => 5]),
            'koncept prodejky'    => $this->record(['docState' => 10]),
            'záznam bez id'       => ['id' => 0],
        ] as $label => $record) {
            $this->assertSame([], $extension->tabs(self::TABLE, $record, $this->context()), $label);
            $this->assertSame([], $extension->badges(self::TABLE, $record, $this->context()), $label);
        }
    }

    public function testWithoutAConfirmedRegistrationOnlyDocumentsWithSalesHaveTheTab(): void
    {
        $this->assertSame([], $this->extension([], [])->tabs(self::TABLE, $this->record(['doc_type' => 'invno', 'payment_method' => 1]), $this->context()));

        $content = $this->tabContent($this->extension([$this->sale(1)], []), $this->record());
        $this->assertStringContainsString('už není V pořádku', $this->properties($content)['Vyhodnocení / Důvod']);
    }

    public function testEnglishRequestGetsEnglishTextsAndNumberFormat(): void
    {
        $extension = $this->extension([$this->sale(1)]);
        $context = new ModuleExtensionContext(
            self::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(['economy.salesRegistration.texts' => null]),
            null,
            'en',
        );

        $content = $extension->tabs(self::TABLE, $this->record(), $context)[0]['content'];

        $this->assertSame('Falls — 1,234.50 CZK', $content['blocks'][0]['groups'][0]['items'][0]['value']);
        $this->assertSame('2027-02-10 09:30', $content['blocks'][1]['rows'][0]['sold_at']);
    }

    public function testContentUsesOnlySharedDetailTypes(): void
    {
        $content = $this->tabContent($this->extension(), $this->record(['doc_type' => 'invno', 'payment_method' => 1]));

        $this->assertContains($content['type'], DetailContent::SHARED_TYPES);
        foreach ($content['blocks'] as $block) {
            $this->assertContains($block['type'], DetailContent::SHARED_TYPES);
        }
    }

    // ── Štítek hlavičky (U18) ───────────────────────────────────────────────

    public function testBadgeIsTheWorstStateOfTheSalesOfTheDocument(): void
    {
        $cases = [
            [[SaleState::CONFIRMED], false, ['label' => 'EET: Potvrzeno', 'style' => 'done']],
            [[SaleState::CONFIRMED, SaleState::PENDING], false, ['label' => 'EET: Čeká na odeslání', 'style' => 'edit']],
            [[SaleState::CONFIRMED, SaleState::PENDING, SaleState::FAILED], false, ['label' => 'EET: Selhalo', 'style' => 'cancelled']],
            [[SaleState::CONFIRMED, SaleState::DROPPED], false, ['label' => 'EET: Zahozeno', 'style' => 'archive']],
            [[SaleState::VERIFIED], false, ['label' => 'EET: Ověřeno', 'style' => 'archive']],
            [[SaleState::CONFIRMED, SaleState::CONFIRMED], true, ['label' => 'EET test: Potvrzeno', 'style' => 'done']],
        ];
        foreach ($cases as $i => [$states, $test, $expected]) {
            $sales = [];
            foreach ($states as $n => $state) {
                $sales[] = $this->sale($n + 1, ['state' => $state, 'test' => $test ? 1 : 0]);
            }
            $this->assertSame([$expected], $this->extension($sales)->badges(self::TABLE, $this->record(), $this->context()), "případ {$i}");
        }
    }

    public function testSalesAreReadOnceForTabAndBadge(): void
    {
        $extension = $this->extension([$this->sale(1)]);

        $extension->badges(self::TABLE, $this->record(), $this->context());
        $extension->tabs(self::TABLE, $this->record(), $this->context());

        $this->assertSame(1, $extension->salesReads);
    }

    // ── Štítek v seznamu (U19) ──────────────────────────────────────────────

    public function testRowDecoratorTagsOnlyDocumentsWithSalesInOneQuery(): void
    {
        $decorator = new class($this->salesRegistrationConfig()) extends DocSalesRowDecorator {
            /** @var list<list<int>> */
            public array $queries = [];

            public function __construct(private readonly mixed $config)
            {
            }

            protected function docSales(ModuleExtensionContext $context): DocSales
            {
                return new DocSales((new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor(), $this->config);
            }

            protected function statesOf(DocSales $sales, array $docIds): array
            {
                $this->queries[] = $docIds;
                return [
                    11 => ['states' => [SaleState::CONFIRMED], 'test' => false],
                    12 => ['states' => [SaleState::CONFIRMED, SaleState::PENDING], 'test' => false],
                    13 => ['states' => [SaleState::CONFIRMED, SaleState::FAILED], 'test' => true],
                    14 => ['states' => [SaleState::DROPPED], 'test' => false],
                ];
            }
        };
        $rows = array_map(static fn(int $id): array => ['id' => $id, 'doc_type' => 'cashreg'], [10, 11, 12, 13, 14]);

        $spans = $decorator->decorate(self::TABLE, $rows, $this->context());

        $this->assertSame(
            [
                11 => [['text' => 'EET', 'class' => 'success']],
                12 => [['text' => 'EET', 'class' => 'warning']],
                13 => [['text' => 'EET test', 'class' => 'danger']],
                14 => [['text' => 'EET', 'class' => 'muted']],
            ],
            $spans,
            'doklad bez tržby (10) štítek nemá',
        );
        $this->assertSame([[10, 11, 12, 13, 14]], $decorator->queries, 'jeden dotaz na stránku řádků');

        $this->assertSame([], $decorator->decorate(self::TABLE, [], $this->context()));
        $this->assertCount(1, $decorator->queries, 'prázdná stránka se neptá vůbec');
    }

    public function testWorstStateOrder(): void
    {
        $this->assertNull(SaleState::worst([]));
        $this->assertSame(SaleState::FAILED, SaleState::worst([SaleState::CONFIRMED, SaleState::FAILED, SaleState::PENDING]));
        $this->assertSame(SaleState::SENDING, SaleState::worst([SaleState::DROPPED, SaleState::SENDING, SaleState::CONFIRMED]));
        $this->assertSame(SaleState::DROPPED, SaleState::worst([SaleState::VERIFIED, SaleState::DROPPED]));
        $this->assertSame(SaleState::CONFIRMED, SaleState::worst([SaleState::CONFIRMED, SaleState::VERIFIED]), 'při shodě dřívější');
    }
}
