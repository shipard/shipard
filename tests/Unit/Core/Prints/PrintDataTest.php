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
            'meta'        => ['title' => 'Faktura 2026000123', 'fileName' => 'faktura-2026000123.pdf'],
            'branding'    => ['logo' => 'logo.png'],
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
