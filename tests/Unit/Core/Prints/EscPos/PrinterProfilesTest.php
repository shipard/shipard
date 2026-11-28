<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterProfiles;

final class PrinterProfilesTest extends TestCase
{
    use EscPosConfig;

    private static function catalog(): PrinterProfiles
    {
        return new PrinterProfiles(self::printsJsonc('printerProfiles.jsonc'));
    }

    public function testCatalogHasWideAndNarrowProfile(): void
    {
        $profiles = self::catalog()->all();

        $this->assertSame(['escpos-80-cp852', 'escpos-58-cp852'], array_map(
            static fn(PrinterProfile $p): string => $p->id,
            $profiles,
        ));

        [$wide, $narrow] = $profiles;
        $this->assertSame([42, 56, 512], [$wide->columns, $wide->columnsSmall, $wide->dotsPerLine]);
        $this->assertSame([32, 42, 384], [$narrow->columns, $narrow->columnsSmall, $narrow->dotsPerLine]);

        foreach ($profiles as $profile) {
            $this->assertSame('cp852', $profile->codePage, $profile->id);
            $this->assertTrue($profile->hasFeature('cut'), $profile->id);
            $this->assertTrue($profile->hasFeature('drawer'), $profile->id);
            $this->assertFalse($profile->hasFeature('qr'), $profile->id);
            // Prázdné, dokud profil někdo na konkrétním modelu neověří.
            $this->assertSame([], $profile->models, $profile->id);
        }
    }

    public function testEveryProfileRefersToAnExistingDriverWithItsCodePage(): void
    {
        $drivers = self::drivers();

        foreach (self::catalog()->all() as $profile) {
            $this->assertTrue($drivers->has($profile->driver), $profile->id);
            $this->assertTrue($drivers->get($profile->driver)->hasCodePage($profile->codePage), $profile->id);
        }
    }

    public function testContractShapeDoesNotExposeTheDriver(): void
    {
        $this->assertSame(
            ['id', 'name', 'columns', 'columnsSmall', 'dotsPerLine', 'codePage', 'features', 'models'],
            array_keys(self::catalog()->all()[0]->toArray()),
        );
    }

    public function testKnownProfileHasNoWarning(): void
    {
        $result = self::catalog()->get('escpos-80-cp852');

        $this->assertSame('escpos-80-cp852', $result['profile']->id);
        $this->assertNull($result['warning']);
    }

    public function testUnknownProfileFallsBackToTheNarrowestWithWarning(): void
    {
        $result = self::catalog()->get('epson-tm-t88');

        $this->assertSame(PrinterProfiles::FALLBACK_ID, $result['profile']->id);
        $this->assertSame(PrinterProfiles::WARNING_UNKNOWN_PROFILE, $result['warning']?->code);
        $this->assertStringContainsString('epson-tm-t88', (string) $result['warning']?->text);

        // Záložní profil je nejužší z katalogu — vejde se na každou tiskárnu.
        $narrowest = min(array_map(static fn(PrinterProfile $p): int => $p->columns, self::catalog()->all()));
        $this->assertSame($narrowest, $result['profile']->columns);
    }

    public function testMissingProfileFallsBackWithOwnWarning(): void
    {
        foreach ([null, ''] as $id) {
            $result = self::catalog()->get($id);

            $this->assertSame(PrinterProfiles::FALLBACK_ID, $result['profile']->id);
            $this->assertSame(PrinterProfiles::WARNING_NO_PROFILE, $result['warning']?->code);
        }
    }

    public function testEmptyCatalogDegradesForListingAndFailsForRendering(): void
    {
        $empty = PrinterProfiles::fromConfig(null);

        $this->assertSame([], $empty->all());
        $this->assertNull($empty->find('escpos-80-cp852'));

        $this->expectException(EscPosConfigException::class);
        $this->expectExceptionMessage('ds-upgrade');
        $empty->get('escpos-80-cp852');
    }

    public function testCatalogReadsItsConfigItem(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === PrinterProfiles::CFG_ITEM
                ? self::printsJsonc('printerProfiles.jsonc')
                : null,
        );

        $this->assertCount(2, PrinterProfiles::fromConfig($config)->all());
    }

    public function testLineWidthFollowsFontAndCharacterSize(): void
    {
        $profile = self::profile();

        $this->assertSame(42, $profile->lineWidth(false));
        $this->assertSame(56, $profile->lineWidth(true));
        $this->assertSame(21, $profile->lineWidth(false, 2));
        $this->assertSame(18, $profile->lineWidth(true, 3));
        $this->assertSame(5, $profile->lineWidth(false, 8));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidProfiles(): array
    {
        return [
            'no driver'           => [['driver' => null], "'driver' is required"],
            'columns as string'   => [['columns' => '42'], "'columns' must be a positive integer"],
            'zero columns'        => [['columns' => 0], "'columns' must be a positive integer"],
            'no small columns'    => [['columnsSmall' => null], "'columnsSmall' must be a positive integer"],
            'small narrower'      => [['columnsSmall' => 30], "must not be lower than 'columns'"],
            'no dots'             => [['dotsPerLine' => null], "'dotsPerLine' must be a positive integer"],
            'unsupported page'    => [['codePage' => 'windows-1250'], "'codePage' is not supported"],
            'unknown feature'     => [['features' => ['cut', 'beep']], "unknown feature 'beep'"],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidProfiles')]
    public function testInvalidProfileIsRejected(array $override, string $message): void
    {
        $this->expectException(EscPosConfigException::class);
        $this->expectExceptionMessage($message);

        self::profile($override);
    }
}
