<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Video;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources;

/** Zdroj dat pro videa per sada (tasks/testing-phase7b.md D126): jen přesná shoda účelu, třída free, aktivní; první podle id. */
final class VideoDataSourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_video_ds_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function ds(string $id, array $config): void
    {
        mkdir($this->dir . '/' . $id . '/config', 0700, true);
        file_put_contents($this->dir . '/' . $id . '/config/main.json', json_encode(['id' => $id, 'name' => 'DS ' . $id, 'database_name' => 'x', 'country' => 'cz'] + $config));
    }

    public function testPurposeAndLookup(): void
    {
        $this->assertSame('videa — it-servis', VideoDataSources::purposeFor('it-servis'));
        $inventory = new DataSourceInventory($this->dir);
        $this->assertNull(VideoDataSources::find($inventory, 'it-servis'), 'prázdný adresář');

        $this->ds('bbbb-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'videa — it-servis']);
        $this->ds('aaaa-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'videa — it-servis-old']);
        $this->ds('cccc-0000-0000-0000', ['dataClass' => 'realCopy', 'purpose' => 'videa — it-servis']);
        $this->ds('dddd-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'evaluace AI — it-servis']);
        $this->ds('eeee-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'videa — other']);
        $this->ds('ffff-0000-0000-0000', ['purpose' => 'videa — it-servis']);

        $found = VideoDataSources::find($inventory, 'it-servis');
        $this->assertNotNull($found);
        $this->assertSame('bbbb-0000-0000-0000', $found->id, 'realCopy, bez třídy ani jiný účel nesedí');
        $this->assertSame('eeee-0000-0000-0000', VideoDataSources::find($inventory, 'other')?->id);
        $this->assertNull(VideoDataSources::find($inventory, 'nope'));
        $this->assertTrue(VideoDataSources::isVideoDataSource($found));
        $this->assertFalse(VideoDataSources::isVideoDataSource($inventory->read($this->dir . '/dddd-0000-0000-0000') ?? throw new \LogicException('ds')));

        // Neaktivní zdroj dat (state.json) se přeskočí.
        file_put_contents($this->dir . '/bbbb-0000-0000-0000/config/state.json', json_encode(['version' => 1, 'state' => 'suspended']));
        $this->ds('gggg-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'videa — it-servis']);
        $this->assertSame('gggg-0000-0000-0000', VideoDataSources::find($inventory, 'it-servis')?->id);
    }
}
