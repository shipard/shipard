<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlaceKindDocument;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Druh místa (#169 D14, D15, D18): povinná pole, ikona ze sady, zámek typu
 * po potvrzení, použitý druh nejde do koše.
 */
class PlaceKindDocumentTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../../../modules/base/places/config';

    /**
     * @param array<string, mixed>|null $stored uložený řádek druhu
     */
    private function doc(?array $stored = null, int $placesUsing = 0): PlaceKindDocument
    {
        $doc = new class extends PlaceKindDocument {
            /** @var array<string, mixed>|null */
            public ?array $stored = null;
            public int $placesUsing = 0;

            protected function loadKindRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function countPlacesUsingKind(int $kindId): int
            {
                return $this->placesUsing;
            }
        };
        $doc->stored = $stored;
        $doc->placesUsing = $placesUsing;

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::CONFIG . '/types.jsonc')],
            [PlaceKindDocument::ICONS_CFG_ITEM, JsoncParser::parseFile(self::CONFIG . '/icons.jsonc')],
        ]);
        $doc->setConfig($config);
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function codes(PlaceKindDocument $doc, array $data): array
    {
        return array_map(
            static fn (array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testNameAndKnownTypeAreRequired(): void
    {
        $doc = $this->doc();

        $this->assertSame(['name:required', 'type:required'], $this->codes($doc, ['name' => ' ', 'type' => '']));
        $this->assertSame(['type:invalid'], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'castle']));
        $this->assertSame([], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'room']));
    }

    public function testIconMustComeFromThePlaceIconSet(): void
    {
        $doc = $this->doc();

        $this->assertSame([], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'room', 'icon' => 'place-office']));
        $this->assertSame([], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'room', 'icon' => null]));
        $this->assertSame([], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'room', 'icon' => '']));
        $this->assertSame(['icon:invalid'], $this->codes($doc, ['name' => 'Kancelář', 'type' => 'room', 'icon' => 'briefcase']));
    }

    public function testEmptyIconIsStoredAsNull(): void
    {
        $data = ['name' => ' Kancelář ', 'type' => 'room', 'icon' => ''];
        $this->doc()->beforeSave($data, null);

        $this->assertNull($data['icon']);
        $this->assertSame('Kancelář', $data['name']);
    }

    public function testTypeIsLockedAfterFirstConfirmation(): void
    {
        $change = ['id' => 5, 'name' => 'Kancelář', 'type' => 'floor', 'docState' => 40];

        // Koncept typ měnit smí — i když ho právě potvrzujeme.
        $this->assertSame([], $this->codes($this->doc(['id' => 5, 'type' => 'room', 'docState' => 10]), $change));
        // Jednou potvrzený ne, ani ve stavu V opravě nebo v archivu.
        foreach ([40, 80, 70] as $state) {
            $this->assertSame(
                ['type:typeLocked'],
                $this->codes($this->doc(['id' => 5, 'type' => 'room', 'docState' => $state]), $change),
                "stav {$state}",
            );
        }
        // Beze změny typu se nic nehlásí.
        $this->assertSame(
            [],
            $this->codes($this->doc(['id' => 5, 'type' => 'room', 'docState' => 40]), ['type' => 'room'] + $change),
        );
    }

    public function testUsedKindCannotGoToTrashButCanBeArchived(): void
    {
        $stored = ['id' => 5, 'type' => 'room', 'docState' => 40];
        $payload = static fn (int $state): array => ['id' => 5, 'name' => 'Kancelář', 'type' => 'room', 'docState' => $state];

        $used = $this->doc($stored, placesUsing: 3);
        $this->assertSame(['_form:in_use'], $this->codes($used, $payload(90)));
        $this->assertSame([], $this->codes($used, $payload(70)), 'do archivu smí (D18)');
        $this->assertSame([], $this->codes($used, $payload(80)));

        $this->assertSame([], $this->codes($this->doc($stored, placesUsing: 0), $payload(90)), 'nepoužitý druh do koše smí');
    }

    public function testStateOnlyPayloadOfUsedKindIsCheckedToo(): void
    {
        // Gateway doplní docState; název a typ ale v částečném payloadu být
        // nemusí — D18 se hlídá i tak.
        $doc = $this->doc(['id' => 5, 'type' => 'room', 'docState' => 40], placesUsing: 1);

        $this->assertContains('_form:in_use', $this->codes($doc, ['id' => 5, 'docState' => 90]));
    }
}
