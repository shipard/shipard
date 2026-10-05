<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintMessage;

/**
 * Obálka `PrintData` z JSON (`print-run --data`, #90 D28): roundtrip
 * s `toArray()` a odmítnutí obálky, která nemá očekávaný tvar.
 */
class PrintDataTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function envelope(): array
    {
        return [
            'printId'     => 'docs.invoicesOut.invoice',
            'version'     => 1,
            'language'    => 'cs',
            'record'      => ['table' => 'docs_core_heads', 'id' => 123, 'docState' => 40],
            'generatedAt' => '2026-10-02T10:30:00+02:00',
            'meta'        => [
                'title' => 'Faktura 2026000123', 'fileName' => 'faktura-2026000123.pdf', 'watermark' => null,
            ],
            'branding'    => ['logo' => 'logo.png', 'logoPlacement' => 'right', 'accentColor' => '#0a5c8f'],
            'texts'       => [],
            'messages'    => [['severity' => 'warning', 'code' => 'payment.qrNoAccount', 'text' => 'QR nevznikl']],
            'data'        => ['document' => ['number' => '2026000123'], 'rows' => []],
        ];
    }

    public function testRoundtripWithToArray(): void
    {
        $data = PrintData::fromArray(self::envelope());

        $this->assertSame(self::envelope(), $data->toArray());
        $this->assertSame(123, $data->recordId);
        $this->assertSame('logo.png', $data->logo);
        $this->assertEquals([PrintMessage::warning('payment.qrNoAccount', 'QR nevznikl')], $data->messages);
    }

    public function testRoundtripThroughJson(): void
    {
        $json = (string) json_encode(PrintData::fromArray(self::envelope()));

        $this->assertSame(
            self::envelope(),
            PrintData::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toArray(),
        );
    }

    public function testOptionalKeysHaveDefaults(): void
    {
        $envelope = self::envelope();
        unset($envelope['generatedAt'], $envelope['branding'], $envelope['texts'], $envelope['messages']);

        $data = PrintData::fromArray($envelope);

        $this->assertNull($data->logo);
        $this->assertSame([], $data->messages);
        $this->assertSame('left', $data->logoPlacement);
        $this->assertSame(PrintData::DEFAULT_ACCENT_COLOR, $data->accentColor);
    }

    public function testBrandingFromBeforeAppearanceSettingsGetsDefaults(): void
    {
        $envelope = self::envelope();
        $envelope['branding'] = ['logo' => 'logo.png'];

        $this->assertSame(
            ['logo' => 'logo.png', 'logoPlacement' => 'left', 'accentColor' => '#c8c8c8'],
            PrintData::fromArray($envelope)->toArray()['branding'],
        );
    }

    public function testAccentColorIsNormalizedToLowercase(): void
    {
        $envelope = self::envelope();
        $envelope['branding']['accentColor'] = '#0A5C8F';

        $this->assertSame('#0a5c8f', PrintData::fromArray($envelope)->accentColor);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidBranding(): array
    {
        return [
            // Barva jde do stylů záhlaví — nic než `#rrggbb` obálka nepřijme.
            'název barvy'        => ['accentColor', 'red'],
            'zkrácený zápis'     => ['accentColor', '#abc'],
            'CSS za barvou'      => ['accentColor', '#c8c8c8; background: url(x)'],
            'barva není řetězec' => ['accentColor', 13158600],
            'neznámé umístění'   => ['logoPlacement', 'center'],
            'umístění je pole'   => ['logoPlacement', ['left']],
        ];
    }

    #[DataProvider('invalidBranding')]
    public function testInvalidBrandingThrows(string $key, mixed $value): void
    {
        $envelope = self::envelope();
        $envelope['branding'][$key] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'branding.");
        PrintData::fromArray($envelope);
    }

    public function testWatermarkIsOptionalPartOfMeta(): void
    {
        $envelope = self::envelope();
        $envelope['meta']['watermark'] = 'STORNO';
        $data = PrintData::fromArray($envelope);
        $this->assertSame('STORNO', $data->watermark);
        $this->assertSame('STORNO', $data->toArray()['meta']['watermark']);
        $this->assertSame('STORNO', $data->withLanguage('en')->watermark);

        // JSON z doby před vodoznakem klíč nemá.
        unset($envelope['meta']['watermark']);
        $this->assertNull(PrintData::fromArray($envelope)->watermark);

        $envelope['meta']['watermark'] = ['STORNO'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'meta.watermark'");
        PrintData::fromArray($envelope);
    }

    /** @return array<string, array{string}> */
    public static function requiredKeys(): array
    {
        return [
            'printId'  => ['printId'],
            'version'  => ['version'],
            'language' => ['language'],
            'record'   => ['record'],
            'meta'     => ['meta'],
            'data'     => ['data'],
        ];
    }

    #[DataProvider('requiredKeys')]
    public function testMissingRequiredKeyThrows(string $key): void
    {
        $envelope = self::envelope();
        unset($envelope[$key]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing '{$key}'");
        PrintData::fromArray($envelope);
    }

    public function testBareDataSectionIsNotAnEnvelope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PrintData::fromArray(self::envelope()['data']);
    }

    public function testRecordAndMetaMustBeComplete(): void
    {
        $envelope = self::envelope();
        unset($envelope['record']['docState']);
        try {
            PrintData::fromArray($envelope);
            $this->fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'record'", $e->getMessage());
        }

        $envelope = self::envelope();
        unset($envelope['meta']['fileName']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'meta'");
        PrintData::fromArray($envelope);
    }

    public function testVersionNewerThanBuilderThrows(): void
    {
        $this->assertSame(1, PrintData::fromArray(self::envelope(), maxVersion: 1)->version);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version 2 is newer than version 1');
        PrintData::fromArray(['version' => 2] + self::envelope(), maxVersion: 1);
    }

    public function testWithLanguageKeepsEverythingElse(): void
    {
        $data = PrintData::fromArray(self::envelope())->withLanguage('en');

        $this->assertSame(array_replace(self::envelope(), ['language' => 'en']), $data->toArray());
    }
}
