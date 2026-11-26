<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\SalesRegistration;

use Dibi\UniqueConstraintViolationException;
use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocSaleEvaluator;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Tržby z dokladů proti skutečné databázi (#171 fáze 2, „Hotovo když“):
 * prodejka přes `TableGateway` se skutečnými handlery → tržba, oprava →
 * rozdíl, storno → záporná tržba, koš odmítnut, souběh handleru a záchranné
 * sítě bez dvojí tržby.
 *
 * Potřebuje zdroj dat se zapnutým doplňkem `economy.salesRegistration`,
 * řadou prodejek a právě jednou registrací `cz.eet2` ve stavu V pořádku
 * s výchozí jednotkou, platnou dnes — jinak se přeskočí. Odeslání neřeší
 * (to je věc fronty a pojistky serveru); stav tržby proto nekontroluje.
 */
class SalesFromDocumentsTest extends IntegrationTestCase
{
    private const SALES = 'economy_sales_registration_sales';
    private const ATTEMPTS = 'economy_sales_registration_attempts';

    private ?TableGateway $gateway = null;
    private ?ConfigRuntime $configRuntime = null;
    private ?int $headId = null;
    private int $seriesId = 0;
    private int $vatRegistration = 0;
    private ?DataSourceConnection $second = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('economy.salesRegistration', $this->dsConfig->getModules(), true)) {
            $this->markTestSkipped('Zdroj dat nemá zapnutý doplněk economy.salesRegistration');
        }
        $today = date('Y-m-d');
        $registrations = $this->db->fetchAll(
            'SELECT id FROM economy_sales_registration_registrations'
            . ' WHERE docState = 40 AND type = %s AND default_unit IS NOT NULL'
            . ' AND (valid_from IS NULL OR valid_from <= %s) AND (valid_to IS NULL OR valid_to >= %s)',
            'cz.eet2',
            $today,
            $today,
        );
        if (count($registrations) !== 1) {
            $this->markTestSkipped('Zdroj dat nemá právě jednu platnou registraci cz.eet2 s výchozí jednotkou');
        }
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 AND cash_desk IS NOT NULL ORDER BY id LIMIT 1',
            'cashreg',
        );
        $vatRegistration = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState = 40 ORDER BY id LIMIT 1',
            'cz',
        );
        if ($series === null || $vatRegistration === null) {
            $this->markTestSkipped('Zdroj dat nemá řadu prodejek s pokladnou nebo českou registraci DPH');
        }
        $this->seriesId = (int) $series['id'];
        $this->vatRegistration = (int) $vatRegistration['id'];

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->gateway = new TableGateway(
            'docs_core_heads',
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables['docs_core_heads']->childTables,
            $this->configRuntime,
            $this->dsConfig,
            DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $this->db->getDibiConnection(), $this->configRuntime),
        );
    }

    protected function onTearDown(): void
    {
        if ($this->headId !== null) {
            $dibi = $this->db->getDibiConnection();
            $dibi->query(
                'DELETE FROM %n WHERE [sale] IN (SELECT [id] FROM %n WHERE [doc_head] = %i)',
                self::ATTEMPTS,
                self::SALES,
                $this->headId,
            );
            foreach ([self::SALES, 'economy_accounting_journal', 'docs_core_vat_recap', 'docs_core_rows'] as $table) {
                try {
                    $dibi->query('DELETE FROM %n WHERE [doc_head] = %i', $table, $this->headId);
                } catch (\Throwable) {
                    // tabulka ve zdroji dat není (modul vypnutý)
                }
            }
            $dibi->query('DELETE FROM [docs_core_heads] WHERE [id] = %i', $this->headId);
        }
        $this->second?->getDibiConnection()->disconnect();
    }

    private function createReceipt(float $unitPrice): void
    {
        $result = $this->gateway->saveDocument([
            'doc_type'         => 'cashreg',
            'number_series'    => $this->seriesId,
            'payment_method'   => 0,
            'issue_date'       => date('Y-m-d'),
            'accounting_date'  => date('Y-m-d'),
            'vat_registration' => $this->vatRegistration,
            'vat_mode'         => 1,
            'doc_text'         => 'IT evidence tržeb',
            'docState'         => 10,
            'docStateMain'     => 1,
            'rows'             => [
                ['row_kind' => 1, 'operation' => 'sale.goods', 'description' => 'Zboží', 'quantity' => 1,
                 'unit_price' => $unitPrice, 'vat_code' => 'cz-120', 'vat_pct' => 21.0],
            ],
        ]);
        $this->assertTrue($result->isSuccess(), 'Založení prodejky selhalo: ' . $this->failure($result));
        $this->headId = (int) $result->getData()['id'];
    }

    private function failure(\Shipard\Core\Document\DocumentResult $result): string
    {
        return ($result->getErrorMessage() ?? '') . ' ' . json_encode($result->getValidation()?->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $change */
    private function transition(int $state, array $change = []): \Shipard\Core\Document\DocumentResult
    {
        $data = $this->gateway->loadDocument($this->headId);
        $this->assertNotNull($data);
        foreach ($change as $key => $value) {
            if ($key === 'unit_price') {
                $data['rows'][0]['unit_price'] = $value;
            } else {
                $data[$key] = $value;
            }
        }
        $data['docState'] = $state;
        return $this->gateway->saveDocument($data);
    }

    private function transitionTo(int $state, array $change = []): void
    {
        $result = $this->transition($state, $change);
        $this->assertTrue($result->isSuccess(), "Přechod do {$state} selhal: " . $this->failure($result));
    }

    /** @return list<array<string, mixed>> */
    private function sales(): array
    {
        return $this->db->fetchAll('SELECT * FROM %n WHERE [doc_head] = %i ORDER BY [doc_sequence]', self::SALES, $this->headId);
    }

    /** @return array<string, mixed> */
    private function head(): array
    {
        return $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $this->headId);
    }

    private static function stamp(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    public function testReceiptLifecycleKeepsTheSumOfSalesEqualToTheAmount(): void
    {
        $this->createReceipt(100.0);
        $this->assertSame([], $this->sales(), 'Koncept tržbu nemá');

        // 1. V pořádku → první tržba celou částkou dokladu, čas = čas přechodu.
        $this->transitionTo(40);
        $head = $this->head();
        $sales = $this->sales();
        $this->assertCount(1, $sales);
        $this->assertEqualsWithDelta((float) $head['total_amount_dom'], (float) $sales[0]['amount'], 0.001);
        $this->assertGreaterThan(0.0, (float) $sales[0]['amount']);
        $this->assertSame((string) $head['doc_number'], (string) $sales[0]['serial_number']);
        $this->assertSame(self::stamp($head['doc_state_changed_at']), self::stamp($sales[0]['sold_at']));
        $this->assertSame(1, (int) $sales[0]['doc_sequence']);
        $this->assertSame('181', (string) $sales[0]['unit_id']);
        $this->assertSame('czk', (string) $sales[0]['currency']);
        $this->assertSame(0, (int) $head['imported']);
        $seriesCode = $this->db->fetchSingle('SELECT code FROM docs_core_number_series WHERE id = %i', $this->seriesId);
        $this->assertSame((string) $seriesCode, (string) $sales[0]['cash_register_id'], 'označení pokladny = kód řady');
        $first = (float) $sales[0]['amount'];

        // 2. Oprava beze změny částky nic nepřidá.
        $this->transitionTo(80);
        $this->assertCount(1, $this->sales(), 'V opravě se nic nepočítá');
        $this->transitionTo(40);
        $this->assertCount(1, $this->sales(), 'oprava beze změny částky');

        // 3. Oprava s jinou částkou → rozdíl s příponou -2.
        $this->transitionTo(80);
        $this->transitionTo(40, ['unit_price' => 150.0]);
        $head = $this->head();
        $sales = $this->sales();
        $this->assertCount(2, $sales);
        $this->assertEqualsWithDelta((float) $head['total_amount_dom'] - $first, (float) $sales[1]['amount'], 0.001);
        $this->assertSame($head['doc_number'] . '-2', (string) $sales[1]['serial_number']);
        $this->assertSame(self::stamp($head['doc_state_changed_at']), self::stamp($sales[1]['sold_at']));

        // 4. Doklad s tržbou nejde do koše (U12) — hláška, ne výjimka.
        $trash = $this->transition(90);
        $this->assertFalse($trash->isSuccess());
        $this->assertStringContainsString('nejde smazat', (string) $trash->getErrorMessage());
        $this->assertSame(40, (int) $this->head()['docState']);

        // 5. Storno → záporná tržba, součet tržeb = 0.
        $this->transitionTo(30);
        $sales = $this->sales();
        $this->assertCount(3, $sales);
        $this->assertLessThan(0.0, (float) $sales[2]['amount']);
        $this->assertSame($head['doc_number'] . '-3', (string) $sales[2]['serial_number']);
        $this->assertEqualsWithDelta(0.0, array_sum(array_map(static fn(array $sale): float => (float) $sale['amount'], $sales)), 0.001);
    }

    /** U7: handler a záchranná síť ve stejnou chvíli nevytvoří dvě tržby. */
    public function testConcurrentSyncDoesNotCreateTwoSales(): void
    {
        $this->createReceipt(200.0);
        $this->transitionTo(40);
        $this->assertCount(1, $this->sales());
        $dibi = $this->db->getDibiConnection();

        // Tržbu smažeme a doklad „zpracovává jiný proces“: zámek drží druhé spojení.
        $dibi->query('DELETE FROM %n WHERE [sale] IN (SELECT [id] FROM %n WHERE [doc_head] = %i)', self::ATTEMPTS, self::SALES, $this->headId);
        $dibi->query('DELETE FROM %n WHERE [doc_head] = %i', self::SALES, $this->headId);
        $this->second = new DataSourceConnection($this->dsConfig);
        $other = $this->second->getDibiConnection();
        $this->assertSame(1, (int) $other->fetchSingle('SELECT GET_LOCK(CONCAT(DATABASE(), %s), 0)', SaleSync::lockKey($this->headId)));

        $sync = new SaleSync(
            $dibi,
            new DocFactsLoader($dibi, $this->configRuntime, $this->dsConfig),
            $this->configRuntime,
            new DocSaleEvaluator(),
            lockTimeout: 1,
        );
        $this->assertSame(SyncResult::BUSY, $sync->sync($this->headId)->status, 'doklad pod zámkem jiného procesu');
        $this->assertSame([], $this->sales());

        // Jiný proces tržbu mezitím vytvořil a zámek pustil → nic dalšího nevznikne.
        $other->query('SELECT RELEASE_LOCK(CONCAT(DATABASE(), %s))', SaleSync::lockKey($this->headId));
        $created = $sync->sync($this->headId);
        $this->assertSame(SyncResult::CREATED, $created->status);
        $this->assertSame(SyncResult::NONE, $sync->sync($this->headId)->status);
        $this->assertCount(1, $this->sales());

        // Pojistka pod zámkem: druhá tržba se stejným pořadím neprojde unikátním indexem.
        $duplicate = $this->sales()[0];
        unset($duplicate['id']);
        $this->expectException(UniqueConstraintViolationException::class);
        $other->query('INSERT INTO %n %v', self::SALES, $duplicate);
    }

    /** D8e, U11: doklad převzatý importem tržbu nedostane ani záchrannou sítí. */
    public function testImportedDocumentGetsNoSale(): void
    {
        $this->createReceipt(300.0);
        $dibi = $this->db->getDibiConnection();
        $dibi->query('UPDATE [docs_core_heads] SET [imported] = 1 WHERE [id] = %i', $this->headId);

        $this->transitionTo(40);

        $this->assertSame([], $this->sales());
        $this->assertSame(1, (int) $this->head()['imported'], 'úprava příznak nemění');
        $sync = SaleSync::create($dibi, $this->configRuntime, $this->dsConfig);
        $this->assertSame(SyncResult::NONE, $sync->sync($this->headId)->status);
    }
}
