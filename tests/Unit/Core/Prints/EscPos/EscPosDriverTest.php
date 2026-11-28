<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\EscPosDriver;
use Shipard\Core\Prints\EscPos\EscPosDrivers;

final class EscPosDriverTest extends TestCase
{
    use EscPosConfig;

    private const ESC = "\x1B";
    private const GS  = "\x1D";
    private const FS  = "\x1C";

    public function testDefaultDriverByteByByte(): void
    {
        $driver = self::driver();

        $this->assertSame('escpos-generic', $driver->id);
        $this->assertSame('ESC/POS (obecný)', $driver->name);

        $this->assertSame(self::ESC . '@', $driver->init());
        $this->assertSame(self::FS . '.' . self::ESC . "t\x12", $driver->codePage('cp852'));
        $this->assertSame("\n", $driver->lineFeed());
        $this->assertSame(self::ESC . "E\x01", $driver->bold(true));
        $this->assertSame(self::ESC . "E\x00", $driver->bold(false));
        $this->assertSame(self::ESC . "!\x01", $driver->font(true));
        $this->assertSame(self::ESC . "!\x00", $driver->font(false));
        $this->assertSame(self::GS . "!\x00", $driver->size(1, 1));
        $this->assertSame(self::GS . "!\x11", $driver->size(2, 2));
        $this->assertSame(self::GS . "!\x10", $driver->size(2, 1));
        $this->assertSame(self::GS . "!\x77", $driver->size(8, 8));
        $this->assertSame(self::ESC . "a\x00", $driver->align('left'));
        $this->assertSame(self::ESC . "a\x01", $driver->align('center'));
        $this->assertSame(self::ESC . "a\x02", $driver->align('right'));
        $this->assertSame(self::ESC . "d\x03", $driver->feed(3));
        $this->assertSame(self::GS . "VA\x05", $driver->cut());
        $this->assertSame(self::ESC . "p\x30\x37\x79", $driver->openCashDrawer());
    }

    public function testQrCodeIsSequenceOfFunctionsWithLength(): void
    {
        $k = self::GS . '(k';

        $this->assertSame(
            $k . "\x04\x00" . "1A2\x00"
            . $k . "\x03\x00" . "1C\x06"
            . $k . "\x03\x00" . '1E1'
            . $k . "\x08\x00" . '1P0' . 'Ahoj!'
            . $k . "\x03\x00" . '1Q0',
            self::driver()->qr('Ahoj!'),
        );

        // Délka přes 255 bajtů jde do druhého bajtu (pL, pH).
        $long = self::driver()->qr(str_repeat('x', 300));
        $this->assertStringContainsString($k . "\x2F\x01" . '1P0' . str_repeat('x', 300), $long);
    }

    /**
     * Náhled ve frontendu (decode.js) čte jen tyhle příkazy (#177 U13, U30);
     * neznámý by ukázal jako značku `unknown` a jeho parametry jako text.
     *
     * @return array<string, int|null> předpona příkazu → délka celého příkazu,
     *         `null` = proměnná délka (GS ( k)
     */
    private static function previewSubset(): array
    {
        return [
            "\n"             => 1,
            self::ESC . '@'  => 2,
            self::ESC . 'd'  => 3,
            self::ESC . 'a'  => 3,
            self::ESC . 'E'  => 3,
            self::ESC . 't'  => 3,
            self::ESC . '!'  => 3,
            self::ESC . 'p'  => 5,
            self::FS . '.'   => 2,
            self::GS . '!'   => 3,
            self::GS . 'VA'  => 4,
            self::GS . 'VB'  => 4,
            self::GS . 'V'   => 3,
            self::GS . '(k'  => null,
        ];
    }

    public function testDefaultDriverStaysInThePreviewSubset(): void
    {
        $driver = self::driver();
        $all = $driver->init() . $driver->codePage('cp852') . $driver->lineFeed()
            . $driver->bold(true) . $driver->bold(false)
            . $driver->font(true) . $driver->font(false)
            . $driver->size(2, 2) . $driver->size(1, 1)
            . $driver->align('left') . $driver->align('center') . $driver->align('right')
            . $driver->feed(2) . $driver->cut() . $driver->openCashDrawer()
            . $driver->qr('https://example.com/');

        $offset = 0;
        $length = strlen($all);
        while ($offset < $length) {
            $offset = $this->skipKnownCommand($all, $offset);
        }
        $this->assertSame($length, $offset);
    }

    private function skipKnownCommand(string $bytes, int $offset): int
    {
        foreach (self::previewSubset() as $prefix => $commandLength) {
            if (substr($bytes, $offset, strlen($prefix)) !== $prefix) {
                continue;
            }
            if ($commandLength !== null) {
                return $offset + $commandLength;
            }
            $bodyLength = ord($bytes[$offset + 3]) + (ord($bytes[$offset + 4]) << 8);
            return $offset + 5 + $bodyLength;
        }
        $this->fail(sprintf(
            'Command at offset %d (%s) is outside the preview subset',
            $offset,
            bin2hex(substr($bytes, $offset, 4)),
        ));
    }

    public function testCodePageNumberMatchesThePreview(): void
    {
        // codePages.js: cp852 → ESC t 18.
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/frontend/src/native/escpos/codePages.js',
        );
        $this->assertSame(1, preg_match('/cp852:\s*\{\s*escpos:\s*(\d+)/', $source, $match));

        $this->assertStringEndsWith(self::ESC . 't' . chr((int) $match[1]), self::driver()->codePage('cp852'));
    }

    public function testParametersOutOfRangeAreRejected(): void
    {
        $driver = self::driver();

        foreach ([
            static fn() => $driver->size(0, 1),
            static fn() => $driver->size(1, 9),
            static fn() => $driver->feed(256),
            static fn() => $driver->align('justify'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnknownCodePageOfDriverIsConfigError(): void
    {
        $this->assertTrue(self::driver()->hasCodePage('cp852'));
        $this->assertFalse(self::driver()->hasCodePage('windows-1250'));

        $this->expectException(EscPosConfigException::class);
        self::driver()->codePage('windows-1250');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidDefinitions(): array
    {
        $valid = self::printsJsonc('printerDrivers.jsonc')['escpos-generic'];

        $without = static function (string $section, string $key) use ($valid): array {
            unset($valid[$section][$key]);
            return $valid;
        };
        $with = static function (string $section, string $key, mixed $value) use ($valid): array {
            $valid[$section][$key] = $value;
            return $valid;
        };

        return [
            'missing command'   => [$without('commands', 'cut'), 'commands.cut'],
            'empty command'     => [$with('commands', 'init', []), 'commands.init'],
            'byte out of range' => [$with('commands', 'boldOn', [27, 69, 256]), 'commands.boldOn'],
            'byte as string'    => [$with('commands', 'feed', ['27', 100]), 'commands.feed'],
            'not a list'        => [$with('commands', 'size', ['a' => 29]), 'commands.size'],
            'missing qr part'   => [$without('qr', 'print'), 'qr.print'],
            'no code page'      => [['codePages' => []] + $valid, 'no code page'],
        ];
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionIsRejected(array $definition, string $message): void
    {
        $this->expectException(EscPosConfigException::class);
        $this->expectExceptionMessage($message);

        EscPosDriver::fromArray('broken', $definition);
    }

    public function testDriversReadConfigItemAndFailWithoutIt(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === EscPosDrivers::CFG_ITEM
                ? self::printsJsonc('printerDrivers.jsonc')
                : null,
        );

        $drivers = EscPosDrivers::fromConfig($config);
        $this->assertTrue($drivers->has('escpos-generic'));
        $this->assertSame($drivers->get('escpos-generic'), $drivers->get('escpos-generic'));

        $empty = EscPosDrivers::fromConfig(null);
        $this->assertFalse($empty->has('escpos-generic'));

        $this->expectException(EscPosConfigException::class);
        $this->expectExceptionMessage('ds-upgrade');
        $empty->get('escpos-generic');
    }
}
