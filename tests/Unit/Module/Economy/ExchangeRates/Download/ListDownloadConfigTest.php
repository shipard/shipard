<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;

/** Klíč `download` typu lístku (#121 task B U2): validace a čas vyhlášení v zóně typu, ne serveru. */
class ListDownloadConfigTest extends TestCase
{
    private const DAILY = ['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague'];

    public function testParsesWorkingDaysAndMonthly(): void
    {
        $daily = ListDownloadConfig::fromArray(self::DAILY);
        $this->assertSame([1, 'workingDays', 'cz', '14:35', 'Europe/Prague'], [$daily->serviceListType, $daily->frequency, $daily->calendar, $daily->publishAfter, $daily->timezone]);
        $this->assertTrue($daily->isWorkingDays());
        $this->assertFalse($daily->isMonthly());

        $monthly = ListDownloadConfig::fromArray(['serviceListType' => 2, 'frequency' => 'monthly', 'calendar' => 'ignored', 'publishAfter' => '99:99']);
        $this->assertTrue($monthly->isMonthly());
        $this->assertNull($monthly->calendar, 'u měsíčního typu se kalendář ani čas nečtou');
        $this->assertNull($monthly->publishAfter);
        $this->assertSame(ListDownloadConfig::DEFAULT_TIMEZONE, $monthly->timezone);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidProvider(): iterable
    {
        yield 'serviceListType chybí' => [['frequency' => 'monthly'], 'serviceListType'];
        yield 'serviceListType 0' => [['serviceListType' => 0, 'frequency' => 'monthly'], 'serviceListType'];
        yield 'serviceListType řetězec' => [['serviceListType' => '1', 'frequency' => 'monthly'], 'serviceListType'];
        yield 'frequency' => [['serviceListType' => 1, 'frequency' => 'daily'], 'frequency'];
        yield 'timezone neplatná' => [['serviceListType' => 1, 'frequency' => 'monthly', 'timezone' => 'Mars/Olympus'], 'timezone'];
        yield 'timezone prázdná' => [['serviceListType' => 1, 'frequency' => 'monthly', 'timezone' => ''], 'timezone'];
        yield 'calendar chybí' => [['serviceListType' => 1, 'frequency' => 'workingDays', 'publishAfter' => '14:35'], 'calendar'];
        yield 'publishAfter chybí' => [['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz'], 'publishAfter'];
        yield 'publishAfter 25:00' => [['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '25:00'], 'publishAfter'];
        yield 'publishAfter 9:05' => [['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '9:05'], 'publishAfter'];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidConfigThrowsWithReason(array $raw, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);
        ListDownloadConfig::fromArray($raw);
    }

    public function testPublicationAndTodayFollowTheTypeTimezoneNotTheServer(): void
    {
        $daily = ListDownloadConfig::fromArray(self::DAILY);

        // 12:40 UTC = 14:40 v Praze (letní čas) — po vyhlášení; 12:30 UTC = 14:30 — před ním.
        $this->assertTrue($daily->isPublished(new \DateTimeImmutable('2026-10-20 12:40:00', new \DateTimeZone('UTC'))));
        $this->assertFalse($daily->isPublished(new \DateTimeImmutable('2026-10-20 12:30:00', new \DateTimeZone('UTC'))));
        $this->assertTrue($daily->isPublished(new \DateTimeImmutable('2026-10-20 14:35:00', new \DateTimeZone('Europe/Prague'))), 'hranice včetně');
        // Zimní čas: 13:34 UTC = 14:34 v Praze.
        $this->assertFalse($daily->isPublished(new \DateTimeImmutable('2026-12-01 13:34:00', new \DateTimeZone('UTC'))));
        $this->assertTrue($daily->isPublished(new \DateTimeImmutable('2026-12-01 13:35:00', new \DateTimeZone('UTC'))));

        // 23:30 UTC je v Praze už další den.
        $this->assertSame('2026-10-21', $daily->today(new \DateTimeImmutable('2026-10-20 23:30:00', new \DateTimeZone('UTC'))));
        $this->assertSame('2026-10-20', $daily->today(new \DateTimeImmutable('2026-10-20 21:30:00', new \DateTimeZone('UTC'))));

        $monthly = ListDownloadConfig::fromArray(['serviceListType' => 2, 'frequency' => 'monthly']);
        $this->assertTrue($monthly->isPublished(new \DateTimeImmutable('2026-10-01 00:00:01', new \DateTimeZone('UTC'))), 'měsíční typ čas vyhlášení nemá');
    }
}
