<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Assets\AssetTypeDocument;
use Shipard\Module\Economy\Assets\AssetTypeGroupDocument;

class AssetTypeDocumentTest extends TestCase
{
    private function doc(): AssetTypeDocument
    {
        $doc = new AssetTypeDocument();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', ['small' => ['name' => 'Drobný'], 'tangible' => ['name' => 'DHM', 'longTerm' => true]]],
        ]);
        $doc->setConfig($config);
        return $doc;
    }

    public function testNameRequired(): void
    {
        $data = ['short_name' => 'NB'];
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('name', $result->toArray()[0]['column']);
    }

    public function testKnownDefaultCategoryPasses(): void
    {
        $data = ['name' => 'Notebooky', 'default_category' => 'tangible'];
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testUnknownDefaultCategoryFails(): void
    {
        $data = ['name' => 'Notebooky', 'default_category' => 'leasing'];
        $result = $this->doc()->validate($data);
        $this->assertSame('default_category', $result->toArray()[0]['column']);
    }

    public function testBeforeSaveNormalizesEmptyCategoryToNull(): void
    {
        $data = ['name' => ' Notebooky ', 'short_name' => '', 'default_category' => ''];
        $this->doc()->beforeSave($data, null);
        $this->assertSame('Notebooky', $data['name']);
        $this->assertNull($data['short_name']);
        $this->assertNull($data['default_category']);
    }

    public function testTypeGroupRequiresName(): void
    {
        $doc = new AssetTypeGroupDocument();
        $data = ['note' => 'x'];
        $this->assertFalse($doc->validate($data)->isValid());
        $data = ['name' => 'Výpočetní technika'];
        $this->assertTrue($doc->validate($data)->isValid());
    }
}
