<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\RegistryDocumentsViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Metadata dokumentu v tabu Obsah (#133): popisky polí z katalogu
 * `viewerDetailLabels.fields`, hodnoty ve tvaru ISO data jako `j. n. Y`,
 * ostatní hodnoty beze změny; neznámý klíč a chybějící katalog dají klíč.
 */
class RegistryDocumentsViewerMetadataTest extends TestCase
{
    private const RECORD = [
        'doc_kind' => 'insurance',
        'metadata' => '{"legacyKind":"pojistky","validTo":"2026-01-31","insurer":"Pojišťovna A",'
            . '"annualPremium":12500.5,"policyNumber":"2026-01-31/7"}',
    ];

    /**
     * @param array<string, mixed>|null $items cfgItemy; null = viewer bez konfigurace
     * @return list<array{label: string, value: string}>
     */
    private function items(?array $items): array
    {
        $viewer = new RegistryDocumentsViewer($this->createMock(DataSourceConnection::class), 'base_registry_documents');
        if ($items !== null) {
            $viewer->setConfig(ConfigRuntimeFactory::fromItems($items));
        }
        return (new \ReflectionMethod($viewer, 'buildMetadataItems'))->invoke($viewer, self::RECORD);
    }

    public function testLabelsComeFromCatalogAndIsoDatesAreFormatted(): void
    {
        $items = $this->items([
            'base.registry.docKinds' => [
                'insurance' => ['fields' => ['insurer', 'policyNumber', 'validTo', 'annualPremium']],
            ],
            'base.registry.viewerDetailLabels' => ['fields' => [
                'insurer'       => ['name' => 'Pojišťovna'],
                'policyNumber'  => ['name' => 'Číslo pojistky'],
                'validTo'       => ['name' => 'Platnost do'],
                'annualPremium' => ['name' => 'Roční pojistné'],
            ]],
        ]);

        $this->assertSame([
            ['label' => 'Pojišťovna', 'value' => 'Pojišťovna A'],
            // Datum jen jako celá hodnota — číslo pojistky s datem uvnitř zůstává.
            ['label' => 'Číslo pojistky', 'value' => '2026-01-31/7'],
            ['label' => 'Platnost do', 'value' => '31. 1. 2026'],
            ['label' => 'Roční pojistné', 'value' => '12500.5'],
            // Klíč mimo druh (import) nemá popisek — ukáže se klíč.
            ['label' => 'legacyKind', 'value' => 'pojistky'],
        ], $items);
    }

    public function testCatalogWithoutFieldsSectionGivesKeys(): void
    {
        // Zdroj dat před `ds-upgrade`: katalog ještě nemá sekci `fields`.
        $items = $this->items([
            'base.registry.docKinds'           => ['insurance' => ['fields' => ['insurer', 'validTo']]],
            'base.registry.viewerDetailLabels' => ['tabs' => ['content' => ['name' => 'Obsah']]],
        ]);

        $this->assertSame(['label' => 'insurer', 'value' => 'Pojišťovna A'], $items[0]);
        $this->assertSame(['label' => 'validTo', 'value' => '31. 1. 2026'], $items[1]);
    }

    public function testWithoutConfigGivesKeysInMetadataOrder(): void
    {
        $items = $this->items(null);

        $this->assertSame(
            ['legacyKind', 'validTo', 'insurer', 'annualPremium', 'policyNumber'],
            array_column($items, 'label'),
        );
        $this->assertSame('31. 1. 2026', $items[1]['value']);
    }
}
