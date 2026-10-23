<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\PersonsRegistryController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;
use Shipard\Module\Base\Persons\Registry\RegistryImportException;
use Shipard\Module\Base\Persons\Registry\RegistryInvalidResponseException;
use Shipard\Module\Base\Persons\Registry\RegistryNotFoundException;
use Shipard\Module\Base\Persons\Registry\RegistryPersonImporter;
use Shipard\Module\Base\Persons\Registry\RegistryUnavailableException;
use Shipard\Module\Base\Persons\Registry\SearchResultRow;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Person\PersonApplier;

/**
 * Controller-level coverage for `/api/v1/persons/registry`. The
 * registry client and importer are tested separately — these tests
 * focus on request parsing, response shape, and exception → HTTP
 * status mapping.
 *
 * `RegistryPersonImporter` is `final`, so it cannot be a PHPUnit mock;
 * we construct a real instance from a mocked client + applier instead.
 */
class PersonsRegistryControllerTest extends TestCase
{
    private function buildRequest(string $method, string $path, ?array $body = null, array $query = []): Request
    {
        return Request::fromArray(
            $method,
            $path,
            $query,
            $body !== null ? (string) json_encode($body) : '',
            ['HTTP_HOST' => 'localhost'],
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return $prop->getValue($response);
    }

    /**
     * Build a controller wired with a mock registry client + applier
     * plus a real importer over them. Callers configure the mocks
     * before invoking the controller methods.
     */
    private function makeController(
        PersonsRegistryClient $client,
        PersonApplier $applier,
        DataSourceConnection $db,
    ): PersonsRegistryController {
        $importer = new RegistryPersonImporter($client, $applier);
        return new PersonsRegistryController($client, $importer, $db);
    }

    private function makeSearchRow(string $companyId, string $fullName = 'Acme s.r.o.'): SearchResultRow
    {
        return new SearchResultRow(
            country: 'cz',
            companyId: $companyId,
            fullName: $fullName,
            vatId: 'CZ' . $companyId,
            isValid: true,
            validFrom: '2010-01-01',
            validTo: null,
            primaryAddressText: 'Praha 1',
        );
    }

    // ── search() ────────────────────────────────────────────────────────────

    public function testSearchEmptyQueryReturnsEmptyResultsWithoutCallingRegistry(): void
    {
        $client  = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->never())->method('search');
        $applier = $this->createMock(PersonApplier::class);
        $db      = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchSingle');

        $ctrl = $this->makeController($client, $applier, $db);

        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry'));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame(['results' => []], $payload['data']);
    }

    public function testSearchWhitespaceOnlyQueryReturnsEmptyResults(): void
    {
        $client  = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->never())->method('search');
        $applier = $this->createMock(PersonApplier::class);
        $db      = $this->createMock(DataSourceConnection::class);

        $ctrl = $this->makeController($client, $applier, $db);

        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry', null, ['q' => '   ']));

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame(['results' => []], $response->getPayload()['data']);
    }

    public function testSearchUnknownCompanyIsAnEmptyResult(): void
    {
        // #103 D100: neznámé IČO = prázdný výsledek klienta → 200 {results: []}, žádné 5xx.
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->once())->method('search')->with('99999999')->willReturn([]);
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchSingle');

        $ctrl = $this->makeController($client, $this->createMock(PersonApplier::class), $db);
        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry', null, ['q' => '99999999']));

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame(['results' => []], $response->getPayload()['data']);
    }

    public function testSearchDecoratesResultsWithExistsInDb(): void
    {
        $rows = [
            $this->makeSearchRow('12345678', 'Foo a.s.'),
            $this->makeSearchRow('11111111', 'Bar s.r.o.'),
        ];
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->once())
            ->method('search')
            ->with('Acme')
            ->willReturn($rows);

        $applier = $this->createMock(PersonApplier::class);

        $db = $this->createMock(DataSourceConnection::class);
        // First companyId exists, second does not.
        $db->expects($this->exactly(2))
            ->method('fetchSingle')
            ->willReturnOnConsecutiveCalls(42, null);

        $ctrl = $this->makeController($client, $applier, $db);
        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry', null, ['q' => 'Acme']));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertCount(2, $data['results']);
        $this->assertSame('12345678', $data['results'][0]['companyId']);
        $this->assertTrue($data['results'][0]['existsInDb']);
        $this->assertSame('11111111', $data['results'][1]['companyId']);
        $this->assertFalse($data['results'][1]['existsInDb']);
    }

    public function testSearchMapsRegistryUnavailableTo503(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('search')->willThrowException(
            new RegistryUnavailableException('Registry down'),
        );
        $applier = $this->createMock(PersonApplier::class);
        $db = $this->createMock(DataSourceConnection::class);

        $ctrl = $this->makeController($client, $applier, $db);
        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry', null, ['q' => 'x']));

        $this->assertSame(503, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('REGISTRY_UNAVAILABLE', $payload['error']['code']);
    }

    public function testSearchMapsRegistryInvalidResponseTo502(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('search')->willThrowException(
            new RegistryInvalidResponseException('Bad payload'),
        );
        $applier = $this->createMock(PersonApplier::class);
        $db = $this->createMock(DataSourceConnection::class);

        $ctrl = $this->makeController($client, $applier, $db);
        $response = $ctrl->search($this->buildRequest('GET', '/api/v1/persons/registry', null, ['q' => 'x']));

        $this->assertSame(502, $this->getStatus($response));
        $this->assertSame('REGISTRY_INVALID_RESPONSE', $response->getPayload()['error']['code']);
    }

    // ── fetchPerson() ───────────────────────────────────────────────────────

    public function testFetchPersonReturnsCanonical(): void
    {
        $canonical = [
            'format'    => 'shpd.persons.person',
            'version'   => 1,
            'companyId' => '12345678',
        ];
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->once())
            ->method('fetchPerson')
            ->with('cz', '12345678')
            ->willReturn($canonical);

        $applier = $this->createMock(PersonApplier::class);
        $db = $this->createMock(DataSourceConnection::class);

        $ctrl = $this->makeController($client, $applier, $db);
        $response = $ctrl->fetchPerson('cz', '12345678');

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame($canonical, $response->getPayload()['data']);
    }

    public function testFetchPersonMapsNotFoundTo404(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryNotFoundException('not in registry'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->fetchPerson('cz', '99999999');

        $this->assertSame(404, $this->getStatus($response));
        $this->assertSame('REGISTRY_NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testFetchPersonMapsUnavailableTo503(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryUnavailableException('timeout'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->fetchPerson('cz', '12345678');

        $this->assertSame(503, $this->getStatus($response));
        $this->assertSame('REGISTRY_UNAVAILABLE', $response->getPayload()['error']['code']);
    }

    public function testFetchPersonMapsInvalidResponseTo502(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryInvalidResponseException('malformed'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->fetchPerson('cz', '12345678');

        $this->assertSame(502, $this->getStatus($response));
        $this->assertSame('REGISTRY_INVALID_RESPONSE', $response->getPayload()['error']['code']);
    }

    public function testFetchPersonMapsInvalidArgumentTo400(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new \InvalidArgumentException('country must be ISO 3166-1 alpha-2'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->fetchPerson('xx', '12345678');

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    // ── import() ────────────────────────────────────────────────────────────

    public function testImportMissingBodyReturns400(): void
    {
        $client  = $this->createMock(PersonsRegistryClient::class);
        $client->expects($this->never())->method('fetchPerson');
        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->never())->method('apply');

        $ctrl = $this->makeController($client, $applier, $this->createMock(DataSourceConnection::class));
        $response = $ctrl->import($this->buildRequest('POST', '/api/v1/persons/registry/import'));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testImportMissingCompanyIdReturns400(): void
    {
        $client  = $this->createMock(PersonsRegistryClient::class);
        $applier = $this->createMock(PersonApplier::class);

        $ctrl = $this->makeController($client, $applier, $this->createMock(DataSourceConnection::class));
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import', ['country' => 'cz'],
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testImportSuccessReturnsPersonIdAndCreatedTrue(): void
    {
        $canonical = ['format' => 'shpd.persons.person', 'companyId' => '12345678'];
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willReturn($canonical);

        $applier = $this->createMock(PersonApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturn(ApplyResult::ok($canonical, savedId: 123));

        $ctrl = $this->makeController($client, $applier, $this->createMock(DataSourceConnection::class));
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '12345678'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(123, $data['personId']);
        $this->assertTrue($data['created']);
    }

    public function testImportPersonExistsReturnsExistingIdAndCreatedFalse(): void
    {
        $canonical = ['format' => 'shpd.persons.person', 'companyId' => '12345678'];
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willReturn($canonical);

        $applier = $this->createMock(PersonApplier::class);
        // Importer recognises person_exists and reads matchedId from
        // the enriched canonical.
        $enriched = $canonical;
        $enriched['_resolve'] = ['header' => ['matchedId' => 77]];
        $applier->method('apply')->willReturn(
            ApplyResult::error('person_exists', 'Person exists', $enriched, statusCode: 409),
        );

        $ctrl = $this->makeController($client, $applier, $this->createMock(DataSourceConnection::class));
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '12345678'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(77, $data['personId']);
        $this->assertFalse($data['created']);
    }

    public function testImportRegistryNotFoundReturns404(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryNotFoundException('not in registry'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '99999999'],
        ));

        $this->assertSame(404, $this->getStatus($response));
        $this->assertSame('REGISTRY_NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testImportRegistryUnavailableReturns503(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryUnavailableException('5xx'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '12345678'],
        ));

        $this->assertSame(503, $this->getStatus($response));
        $this->assertSame('REGISTRY_UNAVAILABLE', $response->getPayload()['error']['code']);
    }

    public function testImportRegistryInvalidResponseReturns502(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new RegistryInvalidResponseException('malformed'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '12345678'],
        ));

        $this->assertSame(502, $this->getStatus($response));
        $this->assertSame('REGISTRY_INVALID_RESPONSE', $response->getPayload()['error']['code']);
    }

    public function testImportApplyFailureReturns422WithDetails(): void
    {
        $canonical = ['format' => 'shpd.persons.person', 'companyId' => '12345678'];
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willReturn($canonical);

        $enriched = $canonical;
        $enriched['_resolve'] = ['issues' => [['code' => 'something_bad']]];

        $applier = $this->createMock(PersonApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('validation_failed', 'Boom', $enriched, statusCode: 422),
        );

        $ctrl = $this->makeController($client, $applier, $this->createMock(DataSourceConnection::class));
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'cz', 'companyId' => '12345678'],
        ));

        $this->assertSame(422, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertSame('REGISTRY_IMPORT_FAILED', $payload['error']['code']);
        $this->assertArrayHasKey('details', $payload['error']);
        $this->assertSame('validation_failed', $payload['error']['details'][0]['applierErrorCode']);
        $this->assertSame($enriched, $payload['error']['details'][0]['canonical']);
    }

    public function testImportBadCountryReturns400(): void
    {
        $client = $this->createMock(PersonsRegistryClient::class);
        $client->method('fetchPerson')->willThrowException(
            new \InvalidArgumentException('country must be ISO 3166-1 alpha-2, got "xx"'),
        );

        $ctrl = $this->makeController(
            $client, $this->createMock(PersonApplier::class), $this->createMock(DataSourceConnection::class),
        );
        $response = $ctrl->import($this->buildRequest(
            'POST', '/api/v1/persons/registry/import',
            ['country' => 'xx', 'companyId' => '12345678'],
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }
}
