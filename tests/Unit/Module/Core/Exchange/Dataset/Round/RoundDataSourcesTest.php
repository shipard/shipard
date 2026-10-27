<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\PurposeDataSources;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDataSources;

/**
 * Vyhrazený zdroj dat kola per sada (tasks/testing-phase8a.md D132, O1):
 * jen přesná shoda účelu `testovací kolo — <sada>`, třída free, aktivní;
 * evaluační, korpus ani pokus se nikdy nevyberou.
 */
final class RoundDataSourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_round_ds_' . bin2hex(random_bytes(6));
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

    public function testPurposeNameAndLookup(): void
    {
        $this->assertSame('testovací kolo — cz-vat-payer', RoundDataSources::purposeFor('cz-vat-payer'));
        $this->assertSame('Testovací kolo — Plátce DPH', RoundDataSources::nameFor('Plátce DPH'));
        $inventory = new DataSourceInventory($this->dir);
        $this->assertNull(RoundDataSources::find($inventory, 'cz-vat-payer'), 'prázdný adresář');

        $this->ds('aaaa-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'evaluace AI — cz-vat-payer', 'dataset' => ['name' => 'cz-vat-payer', 'hash' => 'h']]);
        $this->ds('bbbb-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'korpus', 'origin' => 'dataset-seed cz-vat-payer']);
        $this->ds('cccc-0000-0000-0000', ['dataClass' => 'realCopy', 'purpose' => 'testovací kolo — cz-vat-payer']);
        $this->ds('dddd-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'testovací kolo — cz-vat-payer']);
        $this->ds('eeee-0000-0000-0000', ['dataClass' => 'free', 'purpose' => 'testovací kolo — cz-non-payer']);
        $this->ds('ffff-0000-0000-0000', ['purpose' => 'testovací kolo — cz-vat-payer']);

        $this->assertSame('dddd-0000-0000-0000', RoundDataSources::find($inventory, 'cz-vat-payer')?->id, 'evaluační, korpus, realCopy ani bez třídy nesedí');
        $this->assertSame('eeee-0000-0000-0000', RoundDataSources::find($inventory, 'cz-non-payer')?->id);
        $this->assertNull(RoundDataSources::find($inventory, 'cz-association'));
        $this->assertSame('dddd-0000-0000-0000', PurposeDataSources::find($inventory, 'testovací kolo — cz-vat-payer')?->id);

        // Neaktivní zdroj dat (state.json) se přeskočí.
        file_put_contents($this->dir . '/dddd-0000-0000-0000/config/state.json', json_encode(['version' => 1, 'state' => 'suspended']));
        $this->assertNull(RoundDataSources::find($inventory, 'cz-vat-payer'));
    }
}
