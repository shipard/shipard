<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceClient;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceResponse;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Service\FakeExchangeRateServiceTransport;

/** Klient služby kurzovních lístků (#121 task B §3): URL, lístky, `valid: 0`, `error`, HTTP ≠ 200, nevalidní JSON, síť. */
class ExchangeRateServiceClientTest extends TestCase
{
    private const URL = 'https://data.shipard.app/feed/money-exchange-rates';

    private FakeExchangeRateServiceTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeExchangeRateServiceTransport();
    }

    private function client(string $url = self::URL): ExchangeRateServiceClient
    {
        return new ExchangeRateServiceClient($url, $this->transport);
    }

    public function testRejectsNonHttpUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client('ftp://example.org/feed');
    }

    public function testBuildsRangeUrlWithListType(): void
    {
        $this->assertSame(self::URL . '?dateFrom=2026-10-01&dateTo=2026-10-20&listType=1', $this->client()->url(1, '2026-10-01', '2026-10-20'));
        $this->assertSame('https://x.test/feed?a=1&dateFrom=2026-10-01&dateTo=2026-10-01&listType=2', $this->client('https://x.test/feed?a=1')->url(2, '2026-10-01', '2026-10-01'));
    }

    public function testDailyFixtureGivesThirteenLists(): void
    {
        $this->transport->queueFixture('daily-2026-10.json');
        $response = $this->client()->fetch(1, '2026-10-01', '2026-10-20');

        $this->assertSame(ExchangeRateServiceResponse::LISTS, $response->kind);
        $this->assertFalse($response->isError());
        $this->assertCount(13, $response->lists, 'jen pracovní dny');
        $this->assertSame('2026-10-01', $response->lists[0]['validFrom']);
        $this->assertSame('2026-10-19', $response->lists[12]['validFrom']);
        $this->assertSame('cz.cnb.daily', $response->lists[0]['listType']);
        $this->assertSame([self::URL . '?dateFrom=2026-10-01&dateTo=2026-10-20&listType=1'], $this->transport->urls);
    }

    public function testMonthlyFixtureCarriesValidTo(): void
    {
        $this->transport->queueFixture('monthly-2026-10.json');
        $response = $this->client()->fetch(2, '2026-10-01', '2026-10-01');

        $this->assertCount(1, $response->lists);
        $this->assertSame(['cz.cnb.other', '2026-10-01', '2026-10-31'], [$response->lists[0]['listType'], $response->lists[0]['validFrom'], $response->lists[0]['validTo']]);
    }

    public function testValidZeroWithoutErrorIsNone(): void
    {
        $this->transport->queueFixture('none.json');
        $response = $this->client()->fetch(1, '2026-10-18', '2026-10-18');

        $this->assertTrue($response->isNone());
        $this->assertSame([], $response->lists);
        $this->assertNull($response->error);
    }

    public function testValidZeroWithErrorIsError(): void
    {
        $this->transport->queueFixture('error.json');
        $response = $this->client()->fetch(1, '2026-10-18', '2026-10-18');

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('služba: missing', (string) $response->error);
    }

    public function testHttpErrorInvalidJsonMissingDataAndNetworkAreErrors(): void
    {
        $this->transport->queueBody('server down', 500);
        $this->assertSame('HTTP 500', $this->client()->fetch(1, '2026-10-01', '2026-10-01')->error);

        $this->transport->queueBody('<html>', 200);
        $this->assertSame('odpověď není JSON', $this->client()->fetch(1, '2026-10-01', '2026-10-01')->error);

        $this->transport->queueBody('{"valid": 1}', 200);
        $this->assertSame('odpověď bez pole data', $this->client()->fetch(1, '2026-10-01', '2026-10-01')->error);

        $this->transport->queueBody('{"data": []}', 200);
        $this->assertSame('odpověď bez klíče valid', $this->client()->fetch(1, '2026-10-01', '2026-10-01')->error);

        $this->transport->queueNetworkError('Connection timed out after 15001 milliseconds');
        $response = $this->client()->fetch(1, '2026-10-01', '2026-10-01');
        $this->assertTrue($response->isError());
        $this->assertSame('síť: Connection timed out after 15001 milliseconds', $response->error);
    }

    public function testNonArrayItemsInDataAreDropped(): void
    {
        $this->transport->queueBody('{"valid": 1, "data": [{"listType": "cz.cnb.daily"}, "x", null]}', 200);
        $response = $this->client()->fetch(1, '2026-10-01', '2026-10-01');

        $this->assertSame([['listType' => 'cz.cnb.daily']], $response->lists);
    }
}
