<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Api;

use Shipard\Api\Controller\PersonsRegistryController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;
use Shipard\Module\Base\Persons\Registry\RegistryPersonImporter;
use Shipard\Module\Base\Persons\Registry\RegistryUnavailableException;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Live-network integration test for `/api/v1/persons/registry`. Hits
 * the public Shipard persons registry (or the URL configured in
 * /etc/shipard/server.json) and exercises the full controller stack
 * against a real DS database.
 *
 * Skipped when:
 *   - SHIPARD_INTEGRATION_DS_PATH is unset (handled by IntegrationTestCase),
 *   - the registry is unreachable from the test host (network sandbox,
 *     air-gapped CI). Each test probes reachability up front and calls
 *     markTestSkipped.
 *
 * Test data: created persons are tagged with the `IT-REG-` company-id
 * prefix so tearDown can purge them without disturbing real records.
 * Rows the import merely matched (probe company already on the DS) are
 * never touched — tearDown deletes only what `created: true` reported.
 */
class PersonsRegistryControllerTest extends IntegrationTestCase
{
    private const TEST_COMPANY_ID_PREFIX = 'IT-REG-';
    /**
     * Known-good CZ company: Shipard s.r.o., the vendor of this software.
     * A real, public IČO — the registry returns HTML (→ 502
     * REGISTRY_INVALID_RESPONSE) for made-up ids, so a placeholder here
     * breaks fetch + import while search may still pass by name match.
     */
    private const PROBE_COUNTRY = 'cz';
    private const PROBE_COMPANY_ID = '63478714';

    private PersonsRegistryController $ctrl;
    private PersonsRegistryClient $client;
    private string $registryBaseUrl;

    /** @var list<int> created person ids for teardown */
    private array $createdPersonIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $serverConfig = new ServerConfig();
        try {
            $serverConfig->load();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('ServerConfig not loadable: ' . $e->getMessage());
        }
        $this->registryBaseUrl = $serverConfig->getRegistryPersonsBaseUrl();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');

        $this->client = PersonsRegistryClient::fromServerConfig($serverConfig);
        $applier = PersonApplier::create(
            $this->db->getDibiConnection(),
            $config,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );
        $importer = new RegistryPersonImporter($this->client, $applier);

        $this->ctrl = new PersonsRegistryController($this->client, $importer, $this->db);

        $this->ensureRegistryReachable();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM base_persons_addresses WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_contacts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        // Belt-and-suspenders: purge any leaked rows created by this test.
        $rows = $dibi->fetchAll(
            'SELECT id FROM base_persons_persons WHERE company_id LIKE %s',
            self::TEST_COMPANY_ID_PREFIX . '%',
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $dibi->query('DELETE FROM base_persons_addresses WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_contacts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
    }

    /**
     * Probe the registry with a small HEAD-like call so we can skip the
     * whole class cleanly when the network blocks outbound HTTP.
     */
    private function ensureRegistryReachable(): void
    {
        try {
            $this->client->fetchPerson(self::PROBE_COUNTRY, self::PROBE_COMPANY_ID);
        } catch (RegistryUnavailableException $e) {
            $this->markTestSkipped(
                "Registry at {$this->registryBaseUrl} not reachable: " . $e->getMessage(),
            );
        } catch (\Throwable) {
            // Any other failure is a real issue — let individual tests surface it.
        }
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    private function buildRequest(string $method, array $body = [], array $query = []): Request
    {
        return Request::fromArray(
            $method,
            '/api/v1/persons/registry',
            $query,
            $body === [] ? '' : (string) json_encode($body),
            ['HTTP_HOST' => 'localhost'],
        );
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testFetchPersonReturnsCanonical(): void
    {
        $response = $this->ctrl->fetchPerson(self::PROBE_COUNTRY, self::PROBE_COMPANY_ID);

        $this->assertSame(200, $this->getStatus($response));
        $canonical = $response->getPayload()['data'];
        $this->assertSame('shpd.persons.person', $canonical['format']);
        $this->assertSame(self::PROBE_COMPANY_ID, $canonical['companyId'] ?? null);
    }

    public function testFetchPersonUnknownReturnsError(): void
    {
        // 99999999 has never been a valid Czech IČO. The registry may
        // signal this either as a not-found (status: 0 → 404) or as an
        // invalid-response shape — we accept both since the exact code
        // is registry-side behaviour, not the controller's contract.
        $response = $this->ctrl->fetchPerson('cz', '99999999');

        $status = $this->getStatus($response);
        $this->assertContains(
            $status,
            [404, 502],
            "Unknown company should map to REGISTRY_NOT_FOUND (404) or REGISTRY_INVALID_RESPONSE (502); got {$status}",
        );
        $code = $response->getPayload()['error']['code'];
        $this->assertContains($code, ['REGISTRY_NOT_FOUND', 'REGISTRY_INVALID_RESPONSE']);
    }

    public function testSearchUnknownCompanyReturnsEmptyResults(): void
    {
        // #103 D100: IČO, které registr nezná, je prázdný výsledek (200),
        // ne 502 — kontrola návrhu ho zkouší automaticky u každé nenapárované
        // strany; fiktivní IČO korpusu dřív shazovala proklikání (N3).
        $response = $this->ctrl->search($this->buildRequest('GET', [], ['q' => '99999999']));

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame(['results' => []], $response->getPayload()['data']);
    }

    public function testSearchReturnsResults(): void
    {
        $request = $this->buildRequest('GET', [], ['q' => self::PROBE_COMPANY_ID]);
        $response = $this->ctrl->search($request);

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $results = $payload['data']['results'];
        $this->assertNotEmpty($results, 'Search by known IČO should return at least one result');
        foreach ($results as $row) {
            $this->assertArrayHasKey('existsInDb', $row);
            $this->assertIsBool($row['existsInDb']);
        }
    }

    public function testImportCreatesPersonThenIdempotent(): void
    {
        // The full apply pipeline touches persons sub-tables; they need
        // the docState column (persons-phase1 migration). Skip cleanly
        // on un-upgraded DS — the fetch + search tests still run.
        // world_divisions has no docState by design (reference data).
        $row = $this->db->fetchRow(
            "SHOW COLUMNS FROM base_persons_addresses LIKE 'docState'",
        );
        if ($row === null) {
            $this->markTestSkipped('DS missing docState on base_persons_addresses — run ds-upgrade.');
        }

        $response = $this->ctrl->import($this->buildRequest('POST', [
            'country'   => self::PROBE_COUNTRY,
            'companyId' => self::PROBE_COMPANY_ID,
        ]));

        $this->assertSame(200, $this->getStatus($response), json_encode($response->getPayload()));
        $data = $response->getPayload()['data'];
        $this->assertIsInt($data['personId']);
        $this->assertGreaterThan(0, $data['personId']);
        // Uklízet jen řádek, který test skutečně založil. Na DS, kde probe
        // firma už existuje (na dev DS je to vlastní firma!), import vrátí
        // matched id — smazat ho by odneslo vlastní firmu i s adresami a účty.
        if ($data['created'] === true) {
            $this->createdPersonIds[] = $data['personId'];
        }

        // Second call should return the same id without creating a new row.
        $response2 = $this->ctrl->import($this->buildRequest('POST', [
            'country'   => self::PROBE_COUNTRY,
            'companyId' => self::PROBE_COMPANY_ID,
        ]));
        $this->assertSame(200, $this->getStatus($response2));
        $data2 = $response2->getPayload()['data'];
        $this->assertSame($data['personId'], $data2['personId']);
        $this->assertFalse($data2['created']);
    }
}
