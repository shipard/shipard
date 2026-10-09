<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmRetry;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Opakování jen přechodných chyb (D18): pauzy z `$delays`, `beforeAttempt`
 * před každým pokusem (i prvním) a možnost běh ukončit, trvalá chyba
 * a vyčerpané pokusy propadají, strop útraty se neopakuje.
 */
final class LlmRetryTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->slept = [];
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->slept[] = $seconds;
        };
    }

    public function testReturnsResultOnFirstSuccessWithoutSleeping(): void
    {
        $result = LlmRetry::run(static fn(int $attempt): string => "ok{$attempt}", [10, 60], null, $this->sleeper());

        $this->assertSame('ok1', $result);
        $this->assertSame([], $this->slept);
    }

    public function testRetriesTransientErrorsWithConfiguredDelays(): void
    {
        $calls = 0;
        $result = LlmRetry::run(
            static function (int $attempt) use (&$calls): string {
                $calls++;
                if ($attempt < 3) {
                    throw new LlmApiException(529, 'overloaded_error', 'Overloaded');
                }
                return 'done';
            },
            [10, 60],
            null,
            $this->sleeper(),
        );

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
        $this->assertSame([10, 60], $this->slept);
    }

    public function testGivesUpAfterLastAttempt(): void
    {
        $calls = 0;
        try {
            LlmRetry::run(
                static function () use (&$calls): never {
                    $calls++;
                    throw new LlmApiException(0, 'transport_error', 'timeout');
                },
                [10, 60],
                null,
                $this->sleeper(),
            );
            $this->fail('expected exception');
        } catch (LlmApiException $e) {
            $this->assertSame('timeout', $e->getMessage());
        }

        $this->assertSame(3, $calls);
        $this->assertSame([10, 60], $this->slept);
    }

    public function testPermanentErrorIsNotRetried(): void
    {
        $calls = 0;
        $this->expectException(LlmApiException::class);
        try {
            LlmRetry::run(
                static function () use (&$calls): never {
                    $calls++;
                    throw new LlmApiException(400, 'invalid_request_error', 'bad request');
                },
                [10, 60],
                null,
                $this->sleeper(),
            );
        } finally {
            $this->assertSame(1, $calls);
            $this->assertSame([], $this->slept);
        }
    }

    public function testSpendLimitIsNotRetriedEvenAs429(): void
    {
        $calls = 0;
        try {
            LlmRetry::run(
                static function () use (&$calls): never {
                    $calls++;
                    throw new LlmApiException(429, 'rate_limit_error', 'spend limit', LlmApiException::ERROR_CODE_SPEND_LIMIT);
                },
                [10, 60],
                null,
                $this->sleeper(),
            );
            $this->fail('expected exception');
        } catch (LlmApiException $e) {
            $this->assertTrue($e->isSpendLimitReached());
        }

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
    }

    public function testBeforeAttemptRunsBeforeEveryAttemptAndMayAbort(): void
    {
        $seen = [];
        try {
            LlmRetry::run(
                static fn(): never => throw new LlmApiException(503, 'api_error', 'down'),
                [10, 60],
                static function (int $attempt) use (&$seen): void {
                    $seen[] = $attempt;
                    if ($attempt === 2) {
                        throw new \RuntimeException('lease lost');
                    }
                },
                $this->sleeper(),
            );
            $this->fail('expected abort');
        } catch (\RuntimeException $e) {
            $this->assertSame('lease lost', $e->getMessage());
        }

        $this->assertSame([1, 2], $seen);
        $this->assertSame([10], $this->slept);
    }

    public function testNoDelaysMeansSingleAttempt(): void
    {
        $calls = 0;
        $this->expectException(LlmApiException::class);
        try {
            LlmRetry::run(
                static function () use (&$calls): never {
                    $calls++;
                    throw new LlmApiException(500, 'api_error', 'x');
                },
                [],
                null,
                $this->sleeper(),
            );
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
