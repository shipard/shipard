<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;
use Shipard\Module\Base\Persons\Registry\RegistryInvalidResponseException;
use Shipard\Module\Base\Persons\Registry\RegistryNotFoundException;
use Shipard\Module\Base\Persons\Registry\RegistryUnavailableException;
use Shipard\Module\Base\Persons\Registry\SearchResultRow;

/**
 * Covers HTTP error mapping and response parsing for
 * {@see PersonsRegistryClient}. Uses an in-memory subclass to bypass
 * cURL — the client's `performHttpGet` is protected for exactly this
 * reason. No real network calls.
 */
class PersonsRegistryClientTest extends TestCase
{
    // ── Construction ────────────────────────────────────────────────────────

    public function testConstructorRejectsEmptyBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/baseUrl.*non-empty/');
        new PersonsRegistryClient('');
    }

    public function testConstructorRejectsNonHttpScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/baseUrl.*http/');
        new PersonsRegistryClient('ftp://example.org/persons');
    }

    public function testConstructorRejectsZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds/');
        new PersonsRegistryClient('https://example.org/persons', 0);
    }

    // ── search() happy paths ────────────────────────────────────────────────

    public function testSearchEmptyQueryReturnsEmptyWithoutHttpCall(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('SHOULD_NOT_BE_CALLED', 500);

        $this->assertSame([], $client->search('   '));
        $this->assertSame([], $client->capturedUrls, 'empty query must not perform HTTP');
    }

    public function testSearchParsesRegistryResponse(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            'status' => 1,
            'queryText' => 'Výkupna',
            'results' => [
                [
                    'country' => 'cz',
                    'oid' => '01883399',
                    'fullName' => 'Výkupna s.r.o.',
                    'vatID' => 'CZ01883399',
                    'valid' => 1,
                    'validFrom' => '2013-07-18',
                    'validTo' => null,
                    'primaryAddressText' => 'Máchova 2180/15, 12000',
                ],
                [
                    'country' => 'cz',
                    'oid' => '12878553',
                    'fullName' => 'Šetková - výkupna',
                    'vatID' => '',
                    'valid' => 0,
                    'validFrom' => '1991-04-25',
                    'validTo' => '1993-12-20',
                    'primaryAddressText' => 'Radhostice 13, Radhostice',
                ],
            ],
        ]));

        $results = $client->search('Výkupna');

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(SearchResultRow::class, $results);

        $this->assertSame('cz', $results[0]->country);
        $this->assertSame('01883399', $results[0]->companyId);
        $this->assertSame('Výkupna s.r.o.', $results[0]->fullName);
        $this->assertSame('CZ01883399', $results[0]->vatId);
        $this->assertTrue($results[0]->isValid);
        $this->assertNull($results[0]->validTo);

        $this->assertFalse($results[1]->isValid);
        $this->assertSame('1993-12-20', $results[1]->validTo);
        $this->assertNull($results[1]->vatId, 'empty string vatID must normalize to null');

        $this->assertCount(1, $client->capturedUrls);
        $url = $client->capturedUrls[0];
        $this->assertStringStartsWith('https://example.org/persons?', $url);
        $this->assertStringContainsString('q=V%C3%BDkupna', $url);
        $this->assertStringContainsString('formatMode=ns', $url);
        $this->assertStringContainsString('showAs=json', $url);
    }

    public function testSearchSkipsMalformedRows(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            'status' => 1,
            'results' => [
                ['country' => 'cz', 'oid' => '12345678', 'fullName' => 'OK'],
                ['oid' => '00000000'],                // missing country/fullName
                'not-an-array-at-all',                // wrong type
                ['country' => 'cz', 'oid' => '', 'fullName' => 'Empty oid'],
            ],
        ]));

        $rows = $client->search('test');
        $this->assertCount(1, $rows);
        $this->assertSame('12345678', $rows[0]->companyId);
    }

    // ── search() not found (#103 D100) ──────────────────────────────────────

    public function testSearchStatusZeroWithoutErrorsIsAnEmptyResult(): void
    {
        // Ověřený tvar registru pro neznámé IČO / text bez shody: 200 + {status: 0, errors: []}.
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode(['status' => 0, 'errors' => []]));
        $this->assertSame([], $client->search('99999999'));

        $client->scriptResponse(json_encode(['status' => 0]));
        $this->assertSame([], $client->search('Fiktivní název'), 'bez klíče errors totéž');

        $client->scriptResponse(json_encode(['status' => 0, 'errors' => null]));
        $this->assertSame([], $client->search('x'));
    }

    // ── search() error paths ────────────────────────────────────────────────

    public function testSearchRejectsStatusZeroResponse(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            'status' => 0,
            'errors' => [['msg' => 'Bad query']],
        ]));

        $this->expectException(RegistryInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Bad query/');
        $client->search('whatever');
    }

    public function testSearchMissingResultsKey(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode(['status' => 1]));

        $this->expectException(RegistryInvalidResponseException::class);
        $this->expectExceptionMessageMatches("/missing 'results'/");
        $client->search('x');
    }

    public function testSearchNetworkError(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptError('Could not resolve host');

        $this->expectException(RegistryUnavailableException::class);
        $this->expectExceptionMessageMatches('/Could not resolve host/');
        $client->search('x');
    }

    public function testSearchServerError(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('Internal Server Error', 503);

        $this->expectException(RegistryUnavailableException::class);
        $this->expectExceptionMessageMatches('/503/');
        $client->search('x');
    }

    public function testSearchNonJsonBody(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('<html>oops</html>');

        $this->expectException(RegistryInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $client->search('x');
    }

    // ── fetchPerson() happy path ────────────────────────────────────────────

    public function testFetchPersonReturnsCanonical(): void
    {
        $canonical = [
            'format' => 'shpd.persons.person',
            'formatVersion' => '1.0',
            'personType' => 'company',
            'country' => 'cz',
            'companyId' => '12345678',
            'name' => ['fullName' => 'Zkušební firma s.r.o.'],
            'addresses' => [],
            'bankAccounts' => [],
            'contacts' => [],
        ];
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode($canonical));

        $result = $client->fetchPerson('cz', '12345678');

        $this->assertSame($canonical, $result);
        $this->assertCount(1, $client->capturedUrls);
        $this->assertSame(
            'https://example.org/persons/cz/12345678/json?formatMode=ns',
            $client->capturedUrls[0],
        );
    }

    public function testFetchPersonNormalizesCountryCase(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            'format' => 'shpd.persons.person',
            'formatVersion' => '1.0',
            'personType' => 'company',
            'country' => 'cz',
            'name' => ['fullName' => 'X'],
        ]));

        $client->fetchPerson('CZ', '12345678');

        $this->assertStringContainsString('/cz/12345678/', $client->capturedUrls[0]);
    }

    // ── fetchPerson() error paths ───────────────────────────────────────────

    public function testFetchPersonRejectsEmptyCountry(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $this->expectException(\InvalidArgumentException::class);
        $client->fetchPerson('', '12345678');
    }

    public function testFetchPersonRejectsEmptyCompanyId(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $this->expectException(\InvalidArgumentException::class);
        $client->fetchPerson('cz', '   ');
    }

    public function testFetchPersonRejectsBadCountryFormat(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ISO 3166-1/');
        $client->fetchPerson('cze', '12345678');
    }

    public function testFetchPersonStatusZeroMapsToNotFound(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            'status' => 0,
            'errors' => [['msg' => "IČ `99999999` není platné..."]],
        ]));

        $this->expectException(RegistryNotFoundException::class);
        $this->expectExceptionMessageMatches('/99999999/');
        $client->fetchPerson('cz', '99999999');
    }

    public function testFetchPersonHttp404MapsToNotFound(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('Not Found', 404);

        $this->expectException(RegistryNotFoundException::class);
        $client->fetchPerson('cz', '00000000');
    }

    public function testFetchPersonMissingFormatKeyIsInvalid(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse(json_encode([
            // No top-level `format` — registry side bug.
            'personType' => 'company',
            'country' => 'cz',
            'name' => ['fullName' => 'X'],
        ]));

        $this->expectException(RegistryInvalidResponseException::class);
        $this->expectExceptionMessageMatches("/shpd\\.persons\\.person/");
        $client->fetchPerson('cz', '12345678');
    }

    public function testFetchPersonHttp500MapsToUnavailable(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('boom', 500);

        $this->expectException(RegistryUnavailableException::class);
        $this->expectExceptionMessageMatches('/500/');
        $client->fetchPerson('cz', '12345678');
    }

    public function testFetchPersonHttp400MapsToInvalid(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptResponse('Bad Request', 400);

        $this->expectException(RegistryInvalidResponseException::class);
        $this->expectExceptionMessageMatches('/400/');
        $client->fetchPerson('cz', '12345678');
    }

    public function testFetchPersonNetworkErrorMapsToUnavailable(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        $client->scriptError('Operation timed out after 10000 ms');

        $this->expectException(RegistryUnavailableException::class);
        $this->expectExceptionMessageMatches('/timed out/');
        $client->fetchPerson('cz', '12345678');
    }

    public function testFetchPersonNoHttpResponseMapsToUnavailable(): void
    {
        $client = new FakeRegistryClient('https://example.org/persons');
        // statusCode=0 with no error message simulates a refused / DNS
        // failure that some cURL builds report this way.
        $client->scriptResponse('', 0);

        $this->expectException(RegistryUnavailableException::class);
        $this->expectExceptionMessageMatches('/no HTTP response/');
        $client->fetchPerson('cz', '12345678');
    }
}

/**
 * Test double: scripts one HTTP response (or one error) and records
 * the URL the client tried to hit. One scripted response per test
 * keeps assertions simple — the production client never makes more
 * than one HTTP call per public method.
 */
final class FakeRegistryClient extends PersonsRegistryClient
{
    /** @var list<string> */
    public array $capturedUrls = [];

    private string $body = '';
    private int $statusCode = 200;
    private ?string $error = null;

    public function scriptResponse(string $body, int $statusCode = 200): void
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->error = null;
    }

    public function scriptError(string $message): void
    {
        $this->body = '';
        $this->statusCode = 0;
        $this->error = $message;
    }

    protected function performHttpGet(string $url): array
    {
        $this->capturedUrls[] = $url;
        return [
            'statusCode' => $this->statusCode,
            'body'       => $this->body,
            'error'      => $this->error,
        ];
    }
}
