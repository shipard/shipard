<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Facility;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Facility\Buildings\ContractDocument;
use Shipard\Module\Facility\Buildings\ContractKindDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Smlouva cestou formuláře — skutečný `TableGateway` nad zdrojem dat se
 * zapnutým doplňkem Budovy (#174 D20–D22): uložení míst child tabulkou,
 * přechod stavu přes dokument (číslo z čítače druhu a roku zahájení),
 * varování o překryvu v odpovědi uložení, datum ukončení a mazání konceptu.
 *
 * Unit testy dokumentu volají hooky napřímo; tady se ověřuje, že je gateway
 * skutečně volá (přechod stavu, child set, transakce čítače).
 */
class ContractGatewayTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-BLD';
    private const CODE = 'ITB';

    private TableGateway $gateway;
    private int $kindId;
    private int $customerId;
    /** @var list<int> */
    private array $placeIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[ContractDocument::TABLE])) {
            $this->markTestSkipped('Zdroj dat nemá zapnutý doplněk facility.buildings.');
        }
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test zapisuje a maže smlouvy — jen nad zdrojem dat s třídou `free`.');
        }
        $roomKind = $this->db->fetchSingle('SELECT [id] FROM [base_places_kinds] WHERE [type] = %s AND [docState] = 40 LIMIT 1', 'room');
        if ($roomKind === null) {
            $this->markTestSkipped('Zdroj dat nemá druh místa typu místnost.');
        }

        $this->purge();

        $this->kindId = $this->db->insertRow(ContractKindDocument::TABLE, [
            'name' => self::PREFIX . ' kancelář', 'type' => 'office', 'number_code' => self::CODE,
            'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40, 'docStateMain' => 3,
        ]);
        foreach (['201', '202'] as $pos => $name) {
            $this->placeIds[] = $this->db->insertRow('base_places_places', [
                'name' => self::PREFIX . ' ' . $name, 'kind' => (int) $roomKind, 'order_pos' => $pos,
                'docState' => 40, 'docStateMain' => 3,
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
        $def = $this->tables[ContractDocument::TABLE];
        $this->gateway = new TableGateway(
            ContractDocument::TABLE,
            $dibi,
            DocumentLoader::load($this->dsConfig, $resolver),
            $def->childTables,
            $config,
            $this->dsConfig,
            DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $config),
            $def->docStates,
            $def,
        );
    }

    protected function onTearDown(): void
    {
        if (isset($this->tables[ContractDocument::TABLE]) && $this->dsConfig->isFree()) {
            $this->purge();
        }
    }

    /** Smaže vše, co test založil (i zbytky po spadlém běhu). */
    private function purge(): void
    {
        $dibi = $this->db->getDibiConnection();
        $kinds = $dibi->fetchPairs('SELECT [id], [id] FROM [' . ContractKindDocument::TABLE . '] WHERE [number_code] = %s', self::CODE);
        if ($kinds !== []) {
            $contracts = $dibi->fetchPairs('SELECT [id], [id] FROM [' . ContractDocument::TABLE . '] WHERE [kind] IN %in', $kinds);
            if ($contracts !== []) {
                $dibi->query('DELETE FROM [' . ContractDocument::PLACES_TABLE . '] WHERE [contract] IN %in', $contracts);
                $dibi->query('DELETE FROM [' . ContractDocument::TABLE . '] WHERE [id] IN %in', $contracts);
            }
            $dibi->query('DELETE FROM [' . ContractDocument::COUNTERS_TABLE . '] WHERE [kind] IN %in', $kinds);
            $dibi->query('DELETE FROM [' . ContractKindDocument::TABLE . '] WHERE [id] IN %in', $kinds);
        }
        $dibi->query('DELETE FROM [base_places_places] WHERE [name] LIKE %like~', self::PREFIX . ' ');
        $dibi->query('DELETE FROM [base_persons_persons] WHERE [person_id] = %s', self::PREFIX . '-1');
    }

    /**
     * @param list<int> $places
     * @return array<string, mixed>
     */
    private function draft(string $title, array $places, string $start = '2027-03-01'): array
    {
        $rows = [];
        foreach ($places as $pos => $placeId) {
            $rows[] = ['place' => $placeId, 'order_pos' => $pos];
        }
        return [
            'kind' => $this->kindId, 'title' => self::PREFIX . ' ' . $title, 'customer' => $this->customerId,
            'date_start' => $start, 'docState' => 10, 'docStateMain' => 1, 'places' => $rows,
        ];
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): array
    {
        $result = $this->gateway->saveDocument($data);
        $this->assertTrue(
            $result->isSuccess(),
            json_encode($result->getValidation()?->toArray() ?? $result->getErrorMessage(), JSON_UNESCAPED_UNICODE),
        );
        return ['data' => $result->getData(), 'warnings' => $result->getValidation()?->getWarnings() ?? []];
    }

    public function testContractLifecycleThroughTheGateway(): void
    {
        // Koncept s místy: typ z druhu, vazby v child tabulce v pořadí.
        $saved = $this->save($this->draft('první', [$this->placeIds[1], $this->placeIds[0]]))['data'];
        $id = (int) $saved['id'];
        $loaded = $this->gateway->loadDocument($id);

        $this->assertSame('office', $loaded['type']);
        $this->assertNull($loaded['number']);
        $this->assertSame([$this->placeIds[1], $this->placeIds[0]], array_map('intval', array_column($loaded['places'], 'place')));

        // Potvrzení jen změnou stavu (jako tlačítko stavu): číslo z čítače.
        $loaded['docState'] = 40;
        $loaded['docStateMain'] = 3;
        $this->save($loaded);
        $confirmed = $this->gateway->loadDocument($id);

        $this->assertSame(self::CODE . '-2027-0001', $confirmed['number']);
        $this->assertSame(1, (int) $confirmed['sequence_number']);
        $this->assertSame(2027, (int) $confirmed['number_year']);
        $this->assertCount(2, $confirmed['places'], 'přechod stavu vazby míst nemění');
        $this->assertSame(
            1,
            (int) $this->db->fetchSingle(
                'SELECT [last_assigned] FROM [' . ContractDocument::COUNTERS_TABLE . '] WHERE [kind] = %i AND [year] = %i',
                $this->kindId,
                2027,
            ),
        );

        // Druhá smlouva nad stejným místem: uloží se a potvrdí, s varováním.
        $second = $this->save($this->draft('druhá', [$this->placeIds[0]], '2027-06-01'));
        $this->assertCount(1, $second['warnings']);
        $this->assertSame('places.0.place', $second['warnings'][0]->column);
        $this->assertSame(
            self::PREFIX . ' 201 je i ve smlouvě ' . self::CODE . '-2027-0001 (od 01.03.2027)',
            $second['warnings'][0]->message,
        );
        $secondId = (int) $second['data']['id'];
        $secondLoaded = $this->gateway->loadDocument($secondId);
        $secondLoaded['docState'] = 40;
        $secondLoaded['docStateMain'] = 3;
        $this->save($secondLoaded);
        $this->assertSame(self::CODE . '-2027-0002', $this->gateway->loadDocument($secondId)['number']);

        // Rok zahájení má vlastní čítač.
        $next = $this->draft('další rok', [$this->placeIds[1]], '2028-01-01');
        $next['docState'] = 40;
        $next['docStateMain'] = 3;
        $nextId = (int) $this->save($next)['data']['id'];
        $this->assertSame(self::CODE . '-2028-0001', $this->gateway->loadDocument($nextId)['number']);

        // Ukončení doplní datum; ukončená smlouva se do překryvu nepočítá.
        $confirmed['docState'] = 70;
        $confirmed['docStateMain'] = 4;
        $this->save($confirmed);
        $this->assertSame(date('Y-m-d'), substr((string) $this->gateway->loadDocument($id)['date_end'], 0, 10));

        // Potvrzenou smlouvu smazat nejde, koncept ano — i s vazbami míst.
        try {
            $this->gateway->deleteDocument($secondId);
            $this->fail('potvrzená smlouva smazat nejde');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Smazat jde jen koncept smlouvy', $e->getMessage());
        }
        $this->assertNotNull($this->gateway->loadDocument($secondId));
        $draftId = (int) $this->save($this->draft('koncept', [$this->placeIds[0]], '2029-01-01'))['data']['id'];
        $this->assertTrue($this->gateway->deleteDocument($draftId)->isSuccess());
        $this->assertSame(
            0,
            (int) $this->db->fetchSingle('SELECT COUNT(*) FROM [' . ContractDocument::PLACES_TABLE . '] WHERE [contract] = %i', $draftId),
        );
    }

    public function testConfirmationWithoutPlacesIsRejected(): void
    {
        $draft = $this->draft('bez míst', []);
        $draft['docState'] = 40;
        $draft['docStateMain'] = 3;

        $result = $this->gateway->saveDocument($draft);

        $this->assertFalse($result->isSuccess());
        $codes = array_map(static fn ($e): string => $e->column . ':' . $e->code, $result->getValidation()?->getErrors() ?? []);
        $this->assertSame(['places:required'], $codes);
        $this->assertNull(
            $this->db->fetchSingle('SELECT [last_assigned] FROM [' . ContractDocument::COUNTERS_TABLE . '] WHERE [kind] = %i', $this->kindId),
            'zamítnuté potvrzení čítač nečerpá',
        );
    }
}
