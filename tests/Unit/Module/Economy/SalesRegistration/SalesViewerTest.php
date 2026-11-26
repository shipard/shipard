<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Economy\SalesRegistration\SalesViewer;

/**
 * Agenda Evidence tržeb (#171 D25, U12): řádek seznamu, filtry, detail
 * s pokusy a odkazem na doklad. Těla zpráv viewer nečte.
 */
class SalesViewerTest extends TestCase
{
    use SalesRegistrationConfig;

    private const SALES = 'economy_sales_registration_sales';
    private const ATTEMPTS = 'economy_sales_registration_attempts';

    private const RECORD = [
        'id' => 12, 'doc_head' => 1059, 'doc_sequence' => 2, 'registration' => 1, 'unit' => 2, 'unit_id' => '181',
        'cash_register_id' => 'cashreg-POK', 'serial_number' => '14POK2600054-2', 'sold_at' => '2027-02-11 15:00:00',
        'amount' => '-1210.50', 'currency' => 'czk', 'created' => '2027-02-11 15:00:01', 'state' => 'confirmed',
        'attempt_count' => 2, 'next_attempt' => null, 'claimed_at' => null,
        'confirmation_code' => 'b6f0a3c1-7d2e-4a51-9c97-c9750-ff', 'confirmed_at' => '2027-02-11 15:01:10',
        'environment' => 'playground', 'test' => 1, 'last_problem' => null, 'last_error' => null,
        'unit_name' => 'Prodejna', 'registration_name' => 'Playground EET',
    ];

    /**
     * @param list<array> $calls
     * @param list<array<string, mixed>> $attempts
     */
    private function viewer(array &$calls, ?array $record = null, array $attempts = [], string $language = 'cs'): SalesViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$calls): array {
            $calls[] = $args;
            return [];
        });
        $db->method('fetchRow')->willReturnCallback(function (...$args) use (&$calls, $record): ?array {
            $calls[] = $args;
            return $record;
        });

        $viewer = new class($db, self::SALES) extends SalesViewer {
            /** @var list<array<string, mixed>> */
            public array $attempts = [];
            /** @var array<string, list<array<string, mixed>>> úryvek SQL → řádky */
            public array $options = [];
            /** @var array<int, array{0: string, 1: string}> */
            public array $periods = [];
            public ?string $attemptsSql = null;

            protected function loadAttempts(int $saleId): array
            {
                return $this->attempts;
            }

            protected function optionRows(string $sql): array
            {
                foreach ($this->options as $needle => $rows) {
                    if (str_contains($sql, $needle)) {
                        return $rows;
                    }
                }
                return [];
            }

            protected function periodRange(int $fiscalMonthId): ?array
            {
                return $this->periods[$fiscalMonthId] ?? null;
            }
        };
        $viewer->attempts = $attempts;
        $viewer->setConfig($this->salesRegistrationConfig($language === 'cs' ? [] : [
            'economy.salesRegistration.saleStates' => self::repoJsonc('modules/economy/salesRegistration/config/saleStates.jsonc', 'en'),
        ]));
        $viewer->setLanguage($language);
        $viewer->setTables([
            self::SALES    => TableDefinition::fromArray(self::repoJsonc('modules/economy/salesRegistration/tables/' . self::SALES . '.jsonc', $language)),
            self::ATTEMPTS => TableDefinition::fromArray(self::repoJsonc('modules/economy/salesRegistration/tables/' . self::ATTEMPTS . '.jsonc', $language)),
        ]);
        return $viewer;
    }

    public function testRowShowsSerialNumberAmountUnitStateAndCode(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(self::RECORD);

        $this->assertSame(12, $row['id']);
        $this->assertSame('14POK2600054-2', $row['t1']);
        $this->assertSame('-1 210,50 CZK', $row['i1']);
        $this->assertSame(
            [['text' => '181 Prodejna', 'class' => 'muted'], ['text' => 'Potvrzeno', 'class' => 'success'], ['text' => 'test', 'class' => 'warning']],
            $row['t2'],
        );
        $this->assertSame('11.02.2027 15:00 • kód …c9750-ff', $row['t3'], 'kód potvrzení zkrácený');
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testRowOfEveryStateHasItsLabelAndStyle(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);
        $expected = [
            'pending'   => ['Čeká na odeslání', 'warning', 'edit'],
            'sending'   => ['Odesílá se', 'warning', 'edit'],
            'confirmed' => ['Potvrzeno', 'success', 'done'],
            'verified'  => ['Ověřeno', 'muted', 'archive'],
            'dropped'   => ['Zahozeno', 'muted', 'archive'],
            'failed'    => ['Selhalo', 'danger', 'cancelled'],
        ];
        foreach ($expected as $state => [$label, $class, $style]) {
            $row = $viewer->renderRow(['state' => $state, 'test' => 0, 'confirmation_code' => null] + self::RECORD);
            $this->assertSame(['text' => $label, 'class' => $class], $row['t2'][1], $state);
            $this->assertSame($style, $row['stateStyle'], $state);
            $this->assertCount(2, $row['t2'], $state);
            $this->assertSame('11.02.2027 15:00', $row['t3']);
        }
    }

    public function testEnglishRowUsesEnglishNumberFormatAndTexts(): void
    {
        $calls = [];
        $row = $this->viewer($calls, language: 'en')->renderRow(self::RECORD);

        $this->assertSame('-1,210.50 CZK', $row['i1']);
        $this->assertSame('Confirmed', $row['t2'][1]['text']);
    }

    public function testSelectRowsAppliesFiltersAndSearch(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);
        $viewer->periods = [5 => ['2027-02-01 00:00:00', '2027-03-01 00:00:00']];

        $viewer->selectRows('POK26', [
            ['id' => 'state', 'value' => 'open'],
            ['id' => 'registration', 'value' => '1'],
            ['id' => 'unit', 'value' => '2'],
            ['id' => 'period', 'value' => '5'],
            ['id' => 'period', 'value' => ''],
        ], 0);

        $sql = (string) $calls[0][0];
        $params = array_slice($calls[0], 1);
        $this->assertStringContainsString('s.`state` IN %in', $sql);
        $this->assertStringContainsString('s.`registration` = %i', $sql);
        $this->assertStringContainsString('s.`unit` = %i', $sql);
        $this->assertStringContainsString('s.`sold_at` >= %s AND s.`sold_at` < %s', $sql);
        $this->assertStringContainsString('s.`serial_number`', $sql);
        $this->assertStringContainsString('ORDER BY s.`sold_at` DESC, s.`id` DESC', $sql);
        $this->assertSame(['pending', 'sending', 'failed'], $params[0], 'nevyřízené = čeká, odesílá se, selhalo');
        $this->assertSame([1, 2, '2027-02-01 00:00:00', '2027-03-01 00:00:00'], array_slice($params, 1, 4));

        $calls = [];
        $viewer->selectRows(null, [['id' => 'state', 'value' => 'failed']], 0);
        $this->assertStringContainsString('s.`state` = %s', (string) $calls[0][0]);
        $this->assertSame('failed', $calls[0][1]);
    }

    public function testFiltersOfferStatesAndOnlyUsefulChoices(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);
        $viewer->options = [
            'economy_sales_registration_registrations' => [['id' => 1, 'name' => 'Playground EET']],
            'economy_sales_registration_units'         => [['id' => 2, 'unit_id' => '181', 'name' => 'Prodejna'], ['id' => 3, 'unit_id' => '182', 'name' => null]],
            'economy_codebooks_fiscal_months'          => [['id' => 5, 'calendar_year' => 2027, 'calendar_month' => 2]],
        ];

        $filters = array_column($viewer->getFilters(), null, 'id');

        $this->assertSame(['state', 'unit', 'period'], array_keys($filters), 'jediná registrace filtr nedostane');
        $this->assertSame(
            ['open' => 'Nevyřízené', 'pending' => 'Čeká na odeslání', 'sending' => 'Odesílá se', 'confirmed' => 'Potvrzeno', 'verified' => 'Ověřeno', 'dropped' => 'Zahozeno', 'failed' => 'Selhalo'],
            array_column($filters['state']['options'], 'label', 'value'),
        );
        $this->assertSame('Stav', $filters['state']['label']);
        $this->assertSame([2 => '181 Prodejna', 3 => '182'], array_column($filters['unit']['options'], 'label', 'value'));
        $this->assertSame('Období', $filters['period']['label']);
        $this->assertSame([5 => '2/2027'], array_column($filters['period']['options'], 'label', 'value'));
    }

    public function testThereIsNoAddOrOpenAction(): void
    {
        $calls = [];
        $this->assertSame([], $this->viewer($calls)->getToolbarActions(null));
        $this->assertSame([], $this->viewer($calls)->getToolbarActions(self::RECORD));
    }

    public function testDetailShowsSaleSendingAttemptsAndLinkToTheDocument(): void
    {
        $calls = [];
        $attempts = [
            [
                'id' => 31, 'started_at' => '2027-02-11 15:01:10', 'first_sending' => 0, 'verification' => 0,
                'environment' => 'playground', 'test_identity' => 1, 'status' => 'confirmed', 'sent' => 1, 'problem' => null,
                'error_code' => null, 'error_text' => null, 'warnings' => '[{"code": 3, "text": "Čas tržby je starší"}]',
                'transaction_id' => 'tx-77', 'duration_ms' => 42,
            ],
            [
                'id' => 30, 'started_at' => '2027-02-11 15:00:01', 'first_sending' => 1, 'verification' => 0,
                'environment' => 'playground', 'test_identity' => 1, 'status' => 'retryLater', 'sent' => 1, 'problem' => null,
                'error_code' => -1, 'error_text' => 'Dočasná technická chyba', 'warnings' => null,
                'transaction_id' => null, 'duration_ms' => 2500,
            ],
        ];
        $detail = $this->viewer($calls, self::RECORD, $attempts)->renderDetail(12);

        $this->assertSame('14POK2600054-2', $detail['title']);
        $this->assertSame('-1 210,50 CZK', $detail['subtitle']);
        $this->assertSame(
            [['label' => 'Potvrzeno', 'style' => 'done'], ['label' => 'Testovací potvrzení', 'style' => 'edit']],
            $detail['badges'],
        );

        [$overview, $attemptsTab] = $detail['tabs'];
        $groups = $overview['content']['groups'];
        $this->assertSame(['Tržba', 'Odeslání'], array_column($groups, 'title'));
        $sale = array_column($groups[0]['items'], 'value', 'label');
        $this->assertSame('11.02.2027 15:00', $sale['Čas tržby']);
        $this->assertSame('181 Prodejna', $sale['Evidenční jednotka']);
        $this->assertSame('cashreg-POK', $sale['Pokladní zařízení']);
        $this->assertSame('Playground EET', $sale['Registrace']);
        $this->assertSame('2', $sale['Pořadí tržby dokladu']);
        $sending = array_column($groups[1]['items'], 'value', 'label');
        $this->assertSame('2', $sending['Počet pokusů']);
        $this->assertSame('b6f0a3c1-7d2e-4a51-9c97-c9750-ff', $sending['Kód potvrzení'], 'v detailu celý');
        $this->assertSame('playground', $sending['Prostředí']);
        $this->assertArrayNotHasKey('Další pokus', $sending);

        // Přehled neopakuje hlavičku: pořadové číslo, částku ani stav.
        $values = array_merge(array_values($sale), array_values($sending));
        $this->assertNotContains('14POK2600054-2', $values);
        $this->assertNotContains('-1 210,50 CZK', $values);
        $this->assertNotContains('Potvrzeno', $values);

        $this->assertSame('attempts', $attemptsTab['id']);
        $this->assertSame('Pokusy', $attemptsTab['label']);
        $table = $attemptsTab['content'];
        $this->assertSame('table', $table['type']);
        $this->assertSame(
            ['Čas pokusu', 'Prostředí', 'Výsledek', 'Kódy a varování', 'ID transakce', 'Doba (ms)'],
            array_column($table['columns'], 'label'),
        );
        $this->assertSame(
            [
                'started_at' => '11.02.2027 15:01', 'environment' => 'playground · testovací identita',
                'status' => 'Potvrzeno · opakované zaslání', 'detail' => 'varování: 3 Čas tržby je starší',
                'transaction_id' => 'tx-77', 'duration_ms' => '42',
            ],
            $table['rows'][0],
        );
        $this->assertSame('Služba nedostupná', $table['rows'][1]['status']);
        $this->assertSame('kód -1 · Dočasná technická chyba', $table['rows'][1]['detail']);
        $this->assertSame('error', $table['rows'][1]['_class']);

        $this->assertSame(
            [[
                'id' => 'openSaleDocument', 'label' => 'Otevřít doklad', 'kind' => 'open_detail', 'variant' => 'secondary',
                'target' => ['viewerId' => 'docs.core.heads', 'recordId' => 1059],
            ]],
            $detail['actions'],
        );
    }

    /** F2: pokus, který osiřel pádem procesu, má vlastní popisek a je zvýrazněný. */
    public function testInterruptedAttemptHasItsOwnLabel(): void
    {
        $calls = [];
        $attempts = [[
            'id' => 32, 'started_at' => '2027-02-11 15:02:00', 'first_sending' => 1, 'verification' => 0,
            'environment' => null, 'test_identity' => 0, 'status' => 'interrupted', 'sent' => 0, 'problem' => null,
            'error_code' => null, 'error_text' => null, 'warnings' => null, 'transaction_id' => null, 'duration_ms' => null,
        ]];

        $row = $this->viewer($calls, self::RECORD, $attempts)->renderDetail(12)['tabs'][1]['content']['rows'][0];

        $this->assertSame('Přerušeno', $row['status']);
        $this->assertSame('error', $row['_class']);

        $english = self::repoJsonc('modules/economy/salesRegistration/config/attemptStatuses.jsonc', 'en');
        $this->assertSame('Interrupted', $english['interrupted']['name']);
    }

    public function testDetailOfASaleThatWasNotSentYetAndWasStoppedOnOurSide(): void
    {
        $calls = [];
        $record = [
            'state' => 'pending', 'test' => 0, 'confirmation_code' => null, 'confirmed_at' => null, 'environment' => null,
            'attempt_count' => 0, 'next_attempt' => '2027-02-11 15:05:00', 'last_problem' => 'noCertificate',
        ] + self::RECORD;

        $detail = $this->viewer($calls, $record)->renderDetail(12);

        $this->assertSame([['label' => 'Čeká na odeslání', 'style' => 'edit']], $detail['badges']);
        $sending = array_column($detail['tabs'][0]['content']['groups'][1]['items'], 'value', 'label');
        $this->assertSame('11.02.2027 15:05', $sending['Další pokus']);
        $this->assertSame('registrace nemá certifikát', $sending['Poslední problém']);
        $this->assertSame(['type' => 'empty', 'text' => 'Tržba se zatím neodesílala.'], $detail['tabs'][1]['content']);
    }

    public function testAttemptsAreReadWithoutMessageBodies(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../../modules/economy/salesRegistration/src/SalesViewer.php');

        $this->assertStringNotContainsString('request_body', $source, 'celá XML v detailu nejsou');
        $this->assertStringNotContainsString('response_body', $source);
    }
}
