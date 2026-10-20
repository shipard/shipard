<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Service;

use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceTransport;

/**
 * Transport s uloženými odpověďmi služby (#121 task B U7): fronta
 * odpovědí v pořadí volání, zachycené URL. Fixtury vedle této třídy jsou
 * skutečné odpovědi `data.shipard.app` z 20. 10. 2026 (kurzy ČNB jsou
 * veřejná data): denní říjen 1.–20. 10. (13 lístků), měsíční říjen,
 * `valid: 0` a `error`.
 */
final class FakeExchangeRateServiceTransport implements ExchangeRateServiceTransport
{
    public const string FIXTURES = __DIR__;

    /** @var list<array{statusCode: int, body: string, error: ?string}> */
    private array $queue = [];

    /** @var list<string> */
    public array $urls = [];

    public static function fixture(string $name): string
    {
        $body = file_get_contents(self::FIXTURES . '/' . $name);
        if ($body === false) {
            throw new \RuntimeException("Fixture {$name} not found");
        }
        return $body;
    }

    /** @return list<array<string, mixed>> lístky z fixtury `valid: 1` */
    public static function fixtureLists(string $name): array
    {
        $data = json_decode(self::fixture($name), true, 512, JSON_THROW_ON_ERROR);
        return $data['data'];
    }

    public function queueFixture(string $name, int $statusCode = 200): self
    {
        return $this->queueBody(self::fixture($name), $statusCode);
    }

    public function queueBody(string $body, int $statusCode = 200): self
    {
        $this->queue[] = ['statusCode' => $statusCode, 'body' => $body, 'error' => null];
        return $this;
    }

    public function queueNetworkError(string $error): self
    {
        $this->queue[] = ['statusCode' => 0, 'body' => '', 'error' => $error];
        return $this;
    }

    public function get(string $url): array
    {
        $this->urls[] = $url;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException("FakeExchangeRateServiceTransport: no response queued for {$url}");
        }
        return $next;
    }

    public function pending(): int
    {
        return count($this->queue);
    }
}
