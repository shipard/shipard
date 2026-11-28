<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Facility;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Facility\Buildings\ContractDocument;
use Shipard\Module\Facility\Buildings\ContractInvoicingSource;
use Shipard\Module\Facility\Buildings\ContractKindDocument;
use Shipard\Module\Facility\Buildings\ContractKindRowDocument;
use Shipard\Module\Facility\Buildings\ContractRowDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Fakturační model smluv cestou formuláře — skutečný `TableGateway` nad
 * zdrojem dat se zapnutým doplňkem Budovy (#174 D30, D32, D36): kopie
 * šablony řádků druhu v transakci uložení (vložení, změna druhu, ne po
 * smazání řádků), potvrzení smlouvy s periodicitou a zámek řádků a šablony
 * podle stavu rodiče přes `documentLockProviders`.
 *
 * Unit testy volají hooky napřímo a kopii šablony jen zaznamenávají; tady
 * běží skutečný `INSERT … SELECT` a providery volá gateway.
 */
class ContractInvoicingGatewayTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-BLI';
    private const CODE_A = 'ITI';
    private const CODE_B = 'ITJ';

    private TableGateway $contracts;
    private TableGateway $rows;
    private TableGateway $templateRows;
    private int $kindA;
    private int $kindB;
    private int $customerId;
    private int $seriesId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[ContractDocument::ROWS_TABLE])) {
            $this->markTestSkipped('Zdroj dat nemá zapnutý doplněk facility.buildings s fakturací smluv.');
        }
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test zapisuje a maže smlouvy — jen nad zdrojem dat s třídou `free`.');
        }
        $series = $this->db->fetchSingle(
            'SELECT [id] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [docState] = 40 ORDER BY [id] LIMIT 1',
            'invno',
        );
        if ($series === null) {
            $this->markTestSkipped('Zdroj dat nemá řadu faktur vydaných.');
        }
        $this->seriesId = (int) $series;

        $this->purge();

        $kind = fn (string $code, array $inv): int => $this->db->insertRow(ContractKindDocument::TABLE, $inv + [
            'name' => self::PREFIX . ' ' . $code, 'type' => 'other', 'number_code' => $code,
            'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40, 'docStateMain' => 3,
        ]);
        $this->kindA = $kind(self::CODE_A, ['inv_doc_type' => 'invno', 'inv_number_series' => $this->seriesId]);
        $this->kindB = $kind(self::CODE_B, []);
        foreach ([
            [$this->kindA, 1, 'Nájem', '1.0000', '12000.0000', 'cz-121'],
            [$this->kindA, 2, 'Služby', '1.0000', '2500.0000', 'cz-121'],
            [$this->kindB, 1, 'Paušál', '2.0000', '900.0000', null],
        ] as [$kindId, $pos, $text, $quantity, $price, $vatCode]) {
            $this->db->insertRow(ContractKindRowDocument::TABLE, [
                'kind' => $kindId, 'order_pos' => $pos, 'description' => self::PREFIX . ' ' . $text,
                'quantity' => $quantity, 'unit_price' => $price, 'vat_code' => $vatCode,
            ]);
        }
        $this->customerId = $this->db->insertRow('base_persons_persons', [
            'person_id' => self::PREFIX . '-1', 'person_type' => 2,
            'full_name' => self::PREFIX . ' nájemce', 'last_name' => self::PREFIX . ' nájemce', 'first_name' => '',
            'docState' => 40, 'docStateMain' => 3,
        ]);

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $dibi = $this->db->getDibiConnection();
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $handlers = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $config);
        $gateway = fn (string $table): TableGateway => new TableGateway(
            $table,
            $dibi,
            $registry,
            $this->tables[$table]->childTables,
            $config,
            $this->dsConfig,
            $handlers,
            $this->tables[$table]->docStates,
            $this->tables[$table],
        );
        $this->contracts = $gateway(ContractDocument::TABLE);
        $this->rows = $gateway(ContractRowDocument::TABLE);
        $this->templateRows = $gateway(ContractKindRowDocument::TABLE);
    }

    protected function onTearDown(): void
    {
        if (isset($this->tables[ContractDocument::ROWS_TABLE]) && $this->dsConfig->isFree()) {
            $this->purge();
        }
    }

    /** Smaže vše, co test založil (i zbytky po spadlém běhu). */
    private function purge(): void
    {
        $dibi = $this->db->getDibiConnection();
        $kinds = $dibi->fetchPairs(
            'SELECT [id], [id] FROM [' . ContractKindDocument::TABLE . '] WHERE [number_code] IN %in',
            [self::CODE_A, self::CODE_B],
        );
        if ($kinds !== []) {
            $contracts = $dibi->fetchPairs('SELECT [id], [id] FROM [' . ContractDocument::TABLE . '] WHERE [kind] IN %in', $kinds);
            if ($contracts !== []) {
                $dibi->query('DELETE FROM [' . ContractDocument::ROWS_TABLE . '] WHERE [contract] IN %in', $contracts);
                $dibi->query('DELETE FROM [' . ContractInvoicingSource::PERIODS_TABLE . '] WHERE [contract] IN %in', $contracts);
                $dibi->query('DELETE FROM [' . ContractDocument::PLACES_TABLE . '] WHERE [contract] IN %in', $contracts);
                $dibi->query('DELETE FROM [' . ContractDocument::TABLE . '] WHERE [id] IN %in', $contracts);
            }
            $dibi->query('DELETE FROM [' . ContractDocument::KIND_ROWS_TABLE . '] WHERE [kind] IN %in', $kinds);
            $dibi->query('DELETE FROM [' . ContractDocument::COUNTERS_TABLE . '] WHERE [kind] IN %in', $kinds);
            $dibi->query('DELETE FROM [' . ContractKindDocument::TABLE . '] WHERE [id] IN %in', $kinds);
        }
        $dibi->query('DELETE FROM [base_persons_persons] WHERE [person_id] = %s', self::PREFIX . '-1');
    }

    /** @return array<string, mixed> */
    private function draft(string $title, array $overrides = []): array
    {
        return $overrides + [
            'kind' => $this->kindA, 'title' => self::PREFIX . ' ' . $title, 'customer' => $this->customerId,
            'date_start' => '2027-03-01', 'docState' => 10, 'docStateMain' => 1,
        ];
    }

    /** @return list<array<string, mixed>> řádky předpisu smlouvy v pořadí */
    private function rowsOf(int $contractId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM [' . ContractDocument::ROWS_TABLE . '] WHERE [contract] = %i ORDER BY [order_pos], [id]',
            $contractId,
        );
    }

    /** @return list<string> column:code */
    private static function codes(\Shipard\Core\Document\DocumentResult $result): array
    {
        return array_map(
            static fn ($e): string => $e->column . ':' . $e->code,
            $result->getValidation()?->getErrors() ?? [],
        );
    }

    private function assertSaved(\Shipard\Core\Document\DocumentResult $result): array
    {
        $this->assertTrue(
            $result->isSuccess(),
            json_encode($result->getValidation()?->toArray() ?? $result->getErrorMessage(), JSON_UNESCAPED_UNICODE),
        );
        return $result->getData();
    }

    public function testTemplateIsCopiedOnInsertAndKindChangeOnly(): void
    {
        // Vložení: řádky šablony druhu A v pořadí, bez platnosti.
        $id = (int) $this->assertSaved($this->contracts->saveDocument($this->draft('šablona')))['id'];
        $rows = $this->rowsOf($id);
        $this->assertSame(
            [self::PREFIX . ' Nájem', self::PREFIX . ' Služby'],
            array_column($rows, 'description'),
        );
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'order_pos')));
        $this->assertSame(12000.0, (float) $rows[0]['unit_price']);
        $this->assertSame('cz-121', $rows[0]['vat_code']);
        $this->assertNull($rows[0]['valid_from']);
        $this->assertNull($rows[0]['valid_to']);

        // Smlouva s řádky: změna druhu řádky nemění.
        $loaded = $this->contracts->loadDocument($id);
        $loaded['kind'] = $this->kindB;
        $this->assertSaved($this->contracts->saveDocument($loaded));
        $this->assertCount(2, $this->rowsOf($id));

        // Uživatel řádky smaže a smlouvu uloží — šablona se nevrací.
        foreach ($this->rowsOf($id) as $row) {
            $this->assertTrue($this->rows->deleteDocument((int) $row['id'])->isSuccess());
        }
        $loaded = $this->contracts->loadDocument($id);
        $loaded['title'] = self::PREFIX . ' přejmenovaná';
        $this->assertSaved($this->contracts->saveDocument($loaded));
        $this->assertSame([], $this->rowsOf($id));

        // Změna druhu u konceptu bez řádků přinese šablonu nového druhu.
        $loaded = $this->contracts->loadDocument($id);
        $loaded['kind'] = $this->kindA;
        $this->assertSaved($this->contracts->saveDocument($loaded));
        $this->assertCount(2, $this->rowsOf($id));

        // Smazání konceptu odnese i řádky předpisu.
        $this->assertTrue($this->contracts->deleteDocument($id)->isSuccess());
        $this->assertSame([], $this->rowsOf($id));
    }

    public function testConfirmationWithPeriodicityAndRowLocks(): void
    {
        // Druh B nemá typ dokladu ani řadu: potvrzení s periodicitou neprojde.
        $bare = $this->draft('bez předpisu', ['kind' => $this->kindB, 'inv_periodicity' => 'month']);
        $bareId = (int) $this->assertSaved($this->contracts->saveDocument($bare))['id'];
        $loaded = $this->contracts->loadDocument($bareId);
        $loaded['docState'] = 40;
        $loaded['docStateMain'] = 3;
        $this->assertSame(
            ['inv_doc_type:required', 'inv_number_series:required'],
            self::codes($this->contracts->saveDocument($loaded)),
        );

        // Druh A: „fakturovat od“ a měnu doplní dokument, potvrzení projde.
        $id = (int) $this->assertSaved($this->contracts->saveDocument($this->draft('s předpisem', ['inv_periodicity' => 'month'])))['id'];
        $draft = $this->contracts->loadDocument($id);
        $this->assertSame('2027-03-01', substr((string) $draft['inv_from'], 0, 10));
        $this->assertNotSame('', (string) $draft['currency']);

        // Řádek s platností před zahájením smlouvy dialog řádku odmítne.
        $outside = $this->rows->saveDocument([
            'contract' => $id, 'description' => self::PREFIX . ' Starý nájem', 'quantity' => 1, 'unit_price' => 100,
            'valid_from' => '2026-12-01',
        ]);
        $this->assertSame(['valid_from:outside_contract'], self::codes($outside));

        $draft['docState'] = 40;
        $draft['docStateMain'] = 3;
        $this->assertSaved($this->contracts->saveDocument($draft));
        $confirmed = $this->contracts->loadDocument($id);
        $this->assertSame(self::CODE_A . '-2027-0001', $confirmed['number']);

        // Potvrzená smlouva: řádky jsou zamčené (uložení i mazání), V opravě ne.
        $row = $this->rowsOf($id)[0];
        $locked = $this->rows->saveDocument(['id' => (int) $row['id'], 'contract' => $id, 'description' => 'Změna', 'quantity' => 1, 'unit_price' => 1]);
        $this->assertSame(['_form:locked'], self::codes($locked));
        try {
            $deleted = $this->rows->deleteDocument((int) $row['id']);
            $this->assertFalse($deleted->isSuccess());
        } catch (\DomainException) {
            // zámek smí mazání odmítnout i výjimkou
        }
        $this->assertCount(2, $this->rowsOf($id));

        $confirmed['docState'] = 80;
        $confirmed['docStateMain'] = 2;
        $this->assertSaved($this->contracts->saveDocument($confirmed));
        $this->assertSaved($this->rows->saveDocument([
            'id' => (int) $row['id'], 'contract' => $id, 'description' => self::PREFIX . ' Nájem 2027',
            'quantity' => 1, 'unit_price' => 12500, 'order_pos' => 1,
        ]));
        $this->assertSame(self::PREFIX . ' Nájem 2027', $this->rowsOf($id)[0]['description']);

        // Šablona potvrzeného druhu je zamčená stejně.
        $template = $this->templateRows->saveDocument([
            'kind' => $this->kindA, 'description' => self::PREFIX . ' Další', 'quantity' => 1, 'unit_price' => 1,
        ]);
        $this->assertSame(['_form:locked'], self::codes($template));
    }
}
