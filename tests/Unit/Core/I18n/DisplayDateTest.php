<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\DisplayDate;

/** Jeden formát data na obrazovce podle jazyka (#133 D73). */
class DisplayDateTest extends TestCase
{
    public function testCzechDateHasFixedLength(): void
    {
        $this->assertSame('01.01.2026', DisplayDate::date('2026-01-01', 'cs'));
        $this->assertSame('31.12.2026', DisplayDate::date('2026-12-31', 'cs'));
    }

    public function testEnglishDateIsIso(): void
    {
        $this->assertSame('2026-01-01', DisplayDate::date('2026-01-01', 'en'));
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        $this->assertSame('2026-01-01', DisplayDate::date('2026-01-01', 'de'));
        $this->assertSame('2026-01-01 14:05', DisplayDate::dateTime('2026-01-01 14:05:33', 'sk'));
        $this->assertSame('2026-01-01', DisplayDate::date('2026-01-01', ''));
    }

    public function testDateTime(): void
    {
        $this->assertSame('01.01.2026 14:05', DisplayDate::dateTime('2026-01-01 14:05:33', 'cs'));
        $this->assertSame('2026-01-01 14:05', DisplayDate::dateTime('2026-01-01 14:05:33', 'en'));
    }

    /** @return array<string, array{0: string}> */
    public static function dateTimeShapes(): array
    {
        return [
            'mezera se sekundami' => ['2026-03-09 07:04:59'],
            'mezera bez sekund'   => ['2026-03-09 07:04'],
            'ISO s T'             => ['2026-03-09T07:04:59'],
            'ISO se zlomkem'      => ['2026-03-09T07:04:59.123456'],
            'ISO se Z'            => ['2026-03-09T07:04:59Z'],
            'ISO s posunem'       => ['2026-03-09T07:04:59+02:00'],
            'posun bez dvojtečky' => ['2026-03-09T07:04:59-0500'],
            'mezery kolem'        => ['  2026-03-09 07:04:59 '],
        ];
    }

    #[DataProvider('dateTimeShapes')]
    public function testAcceptedStringShapes(string $value): void
    {
        $this->assertSame('09.03.2026', DisplayDate::date($value, 'cs'));
        $this->assertSame('09.03.2026 07:04', DisplayDate::dateTime($value, 'cs'));
        $this->assertSame('2026-03-09 07:04', DisplayDate::dateTime($value, 'en'));
    }

    public function testTimeZoneIsNotConverted(): void
    {
        // Zapíše se čas, jak v hodnotě stojí — den se neposune.
        $this->assertSame('01.01.2026 00:30', DisplayDate::dateTime('2026-01-01T00:30:00+05:00', 'cs'));
        $this->assertSame('31.12.2026 23:30', DisplayDate::dateTime('2026-12-31T23:30:00-08:00', 'cs'));

        $inTokyo = new \DateTimeImmutable('2026-01-01 00:30:00', new \DateTimeZone('Asia/Tokyo'));
        $this->assertSame('01.01.2026 00:30', DisplayDate::dateTime($inTokyo, 'cs'));
        $this->assertSame('2026-01-01', DisplayDate::date($inTokyo, 'en'));
    }

    public function testDateTimeInterface(): void
    {
        $value = new \DateTime('2026-02-03 04:05:06');
        $this->assertSame('03.02.2026', DisplayDate::date($value, 'cs'));
        $this->assertSame('2026-02-03', DisplayDate::date($value, 'en'));
        $this->assertSame('03.02.2026 04:05', DisplayDate::dateTime($value, 'cs'));
        $this->assertSame('2026-02-03 04:05', DisplayDate::dateTime(\DateTimeImmutable::createFromMutable($value), 'en'));
    }

    public function testDateWithoutTimeGetsMidnight(): void
    {
        $this->assertSame('01.01.2026 00:00', DisplayDate::dateTime('2026-01-01', 'cs'));
        $this->assertSame('2026-01-01 00:00', DisplayDate::dateTime('2026-01-01', 'en'));
    }

    public function testEmptyIsNull(): void
    {
        foreach (['cs', 'en'] as $language) {
            $this->assertNull(DisplayDate::date(null, $language));
            $this->assertNull(DisplayDate::date('', $language));
            $this->assertNull(DisplayDate::dateTime(null, $language));
            $this->assertNull(DisplayDate::dateTime('', $language));
        }
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function notDates(): array
    {
        return [
            'text'              => ['bez data', 'bez data'],
            'neexistující den'  => ['2026-02-30', '2026-02-30'],
            'třináctý měsíc'    => ['2026-13-01', '2026-13-01'],
            'neplatná hodina'   => ['2026-01-01 25:00:00', '2026-01-01 25:00:00'],
            'český zápis'       => ['1. 1. 2026', '1. 1. 2026'],
            'zkrácený rok'      => ['26-01-01', '26-01-01'],
            'číslo'             => [20260101, '20260101'],
            'nulové datum'      => ['0000-00-00', '0000-00-00'],
        ];
    }

    #[DataProvider('notDates')]
    public function testUnparsableValueIsReturnedUnchanged(mixed $value, string $expected): void
    {
        $this->assertSame($expected, DisplayDate::date($value, 'cs'));
        $this->assertSame($expected, DisplayDate::dateTime($value, 'en'));
    }

    public function testNonScalarIsNull(): void
    {
        $this->assertNull(DisplayDate::date(['2026-01-01'], 'cs'));
    }
}
