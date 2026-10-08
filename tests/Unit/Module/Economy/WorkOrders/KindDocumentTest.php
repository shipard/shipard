<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\WorkOrders\KindDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Druh zakázky (D14): název a známý typ povinné; typ je po prvním
 * potvrzení druhu jen ke čtení.
 */
class KindDocumentTest extends TestCase
{
    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true],
    ];

    /** @param array<string, mixed>|null $stored uložený řádek druhu */
    private function doc(?array $stored = null): KindDocument
    {
        $doc = new class($stored) extends KindDocument {
            public function __construct(private readonly ?array $stored)
            {
            }

            protected function loadKindRow(int $id): ?array
            {
                return $this->stored;
            }
        };
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([[WorkOrderTypes::CFG_ITEM, self::TYPES]]);
        $doc->setConfig($config);
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        return $doc;
    }

    /** @return list<string> column:code */
    private function codes(KindDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testNameAndKnownTypeRequired(): void
    {
        $this->assertSame(['name:required', 'type:required'], $this->codes($this->doc(), ['name' => ' ']));
        $this->assertSame(['type:invalid'], $this->codes($this->doc(), ['name' => 'Servis', 'type' => 'leasing']));
        $this->assertSame([], $this->codes($this->doc(), ['name' => 'Servis', 'type' => 'project']));
    }

    public function testTypeIsLockedOnceTheKindLeftDraft(): void
    {
        $confirmed = ['id' => 5, 'type' => 'project', 'docState' => 40];
        $this->assertSame(
            ['type:typeLocked'],
            $this->codes($this->doc($confirmed), ['id' => 5, 'name' => 'Servis', 'type' => 'periodic', 'docState' => 80]),
        );
        // Stejný typ projde, i v opravě.
        $this->assertSame([], $this->codes($this->doc($confirmed), ['id' => 5, 'name' => 'Servis', 'type' => 'project', 'docState' => 80]));
        // Koncept typ změnit smí.
        $draft = ['id' => 5, 'type' => 'project', 'docState' => 10];
        $this->assertSame([], $this->codes($this->doc($draft), ['id' => 5, 'name' => 'Servis', 'type' => 'periodic', 'docState' => 10]));
    }

    public function testBeforeSaveTrimsAndNullsEmptyNotice(): void
    {
        $data = ['name' => ' Servis ', 'type' => 'project', 'notice' => '  '];
        $this->doc()->beforeSave($data, null);
        $this->assertSame('Servis', $data['name']);
        $this->assertNull($data['notice']);
    }
}
