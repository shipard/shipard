<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Settings\BrandingStorage;

class BrandingStorageTest extends TestCase
{
    private const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dsPath;
    private BrandingStorage $storage;

    protected function setUp(): void
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_branding_' . uniqid('', true);
        mkdir($this->dsPath, 0755, true);
        $this->storage = new BrandingStorage($this->dsPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsPath . '/branding/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dsPath . '/branding');
        @rmdir($this->dsPath);
    }

    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($path, $content);
        return $path;
    }

    private function pngContent(): string
    {
        return (string) base64_decode(self::PNG_1X1_BASE64);
    }

    public function testIsValidSlot(): void
    {
        $this->assertTrue(BrandingStorage::isValidSlot('icon'));
        $this->assertTrue(BrandingStorage::isValidSlot('companyLogo'));
        $this->assertFalse(BrandingStorage::isValidSlot('evil'));
        $this->assertFalse(BrandingStorage::isValidSlot('../etc'));
    }

    public function testDetectMimePng(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $this->assertSame('image/png', $this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testDetectMimeSvgWithXmlDeclaration(): void
    {
        $tmp = $this->tmpFile('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->assertSame('image/svg+xml', $this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testDetectMimeSvgWithoutXmlDeclaration(): void
    {
        // finfo bez XML deklarace SVG nepozná — musí zafungovat obsahový fallback.
        $tmp = $this->tmpFile('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        $this->assertSame('image/svg+xml', $this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testDetectMimeRejectsPlainText(): void
    {
        $tmp = $this->tmpFile('just some text, definitely not an image');
        $this->assertNull($this->storage->detectMime($tmp));
        unlink($tmp);
    }

    public function testValidateUploadAcceptsPng(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $result = $this->storage->validateUpload('icon', $tmp, (int) filesize($tmp));

        $this->assertIsArray($result);
        $this->assertSame('image/png', $result['mime']);
        $this->assertSame('png', $result['ext']);
        unlink($tmp);
    }

    public function testValidateUploadRejectsOversizedFile(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $result = $this->storage->validateUpload('icon', $tmp, BrandingStorage::MAX_FILE_SIZE + 1);

        $this->assertIsString($result);
        unlink($tmp);
    }

    public function testValidateUploadRejectsUnsupportedMime(): void
    {
        $tmp = $this->tmpFile('%PDF-1.4 fake pdf content');
        $result = $this->storage->validateUpload('companyLogo', $tmp, (int) filesize($tmp));

        $this->assertIsString($result);
        unlink($tmp);
    }

    public function testValidateUploadIcoOnlyForIconSlot(): void
    {
        // ICO hlavička + padding na deklarovanou velikost obrázku — bez něj
        // finfo soubor nepozná.
        $ico = "\x00\x00\x01\x00\x01\x00\x10\x10\x00\x00\x01\x00\x20\x00\x68\x04\x00\x00\x16\x00\x00\x00";
        $ico .= str_repeat("\x00", 0x468);
        $tmp = $this->tmpFile($ico);

        $iconResult = $this->storage->validateUpload('icon', $tmp, (int) filesize($tmp));
        $this->assertIsArray($iconResult);
        $this->assertSame('ico', $iconResult['ext']);

        $logoResult = $this->storage->validateUpload('companyLogo', $tmp, (int) filesize($tmp));
        $this->assertIsString($logoResult);
        unlink($tmp);
    }

    public function testStoreCreatesSlotFileAndReplacesOldExtension(): void
    {
        $tmp1 = $this->tmpFile($this->pngContent());
        $storedAs1 = $this->storage->store('icon', $tmp1, 'png');

        $this->assertSame('icon.png', $storedAs1);
        $this->assertFileExists($this->dsPath . '/branding/icon.png');

        // Nový upload s jinou příponou smaže starý soubor slotu.
        $tmp2 = $this->tmpFile('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $storedAs2 = $this->storage->store('icon', $tmp2, 'svg');

        $this->assertSame('icon.svg', $storedAs2);
        $this->assertFileExists($this->dsPath . '/branding/icon.svg');
        $this->assertFileDoesNotExist($this->dsPath . '/branding/icon.png');
    }

    public function testDeleteSlotFiles(): void
    {
        $tmp = $this->tmpFile($this->pngContent());
        $this->storage->store('companyLogo', $tmp, 'png');
        $this->assertFileExists($this->dsPath . '/branding/companyLogo.png');

        $this->storage->deleteSlotFiles('companyLogo');
        $this->assertFileDoesNotExist($this->dsPath . '/branding/companyLogo.png');
    }

    public function testFindSlotFile(): void
    {
        $this->assertNull($this->storage->findSlotFile('companyLogo'), 'bez adresáře branding');

        $this->storage->store('companyLogo', $this->tmpFile($this->pngContent()), 'png');
        $this->assertSame('companyLogo.png', $this->storage->findSlotFile('companyLogo'));
        $this->assertNull($this->storage->findSlotFile('icon'), 'jiný slot je prázdný');
        $this->assertNull($this->storage->findSlotFile('../companyLogo'), 'neznámý slot');
    }
}
