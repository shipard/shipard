<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;

/** Výběr kola (D130, D131): vrstvy v kanonickém pořadí, filtry, evaluace jen se stropem, round-trip přes params úlohy. */
final class RoundSelectionTest extends TestCase
{
    public function testParseLayersCanonicalOrderAndValidation(): void
    {
        $this->assertSame(['code', 'datasets', 'videos'], RoundSelection::parseLayers('videos, code,datasets'));
        $this->assertSame(['datasets'], RoundSelection::parseLayers('datasets'));
        $this->assertSame([], RoundSelection::parseLayers(' , '));

        $this->expectException(\InvalidArgumentException::class);
        RoundSelection::parseLayers('code,tests');
    }

    public function testRoundTripAndDefaults(): void
    {
        $selection = RoundSelection::fromArray([]);
        $this->assertSame(RoundSelection::LAYERS, $selection->layers);
        $this->assertSame([], $selection->datasets);
        $this->assertNull($selection->eval);
        $this->assertSame('http://127.0.0.1', $selection->baseUrl);
        $this->assertTrue($selection->has('code'));

        $full = new RoundSelection(['datasets'], ['cz-non-payer'], ['s1'], new RoundEvalOptions('baseline', 2, 1.5), 'http://10.0.0.1');
        $copy = RoundSelection::fromArray($full->toArray());
        $this->assertSame($full->toArray(), $copy->toArray());
        $this->assertSame('baseline', $copy->eval?->variant);
        $this->assertSame(2, $copy->eval?->repeat);
        $this->assertSame(1.5, $copy->eval?->maxCostUsd);
        $this->assertFalse($copy->has('code'));
        $this->assertSame(['layers' => ['datasets'], 'datasets' => ['cz-non-payer'], 'scenarios' => ['s1'], 'eval' => ['variant' => 'baseline', 'repeat' => 2, 'maxCostUsd' => 1.5], 'baseUrl' => 'http://10.0.0.1'], $full->toArray());
    }

    public function testRejectsEmptyLayersUnknownLayerAndBadFilters(): void
    {
        try {
            new RoundSelection([], [], [], null, 'http://127.0.0.1');
            $this->fail('empty layers');
        } catch (\InvalidArgumentException) {
        }
        try {
            new RoundSelection(['code', 'code'], [], [], null, 'http://127.0.0.1');
            $this->fail('repeated layer');
        } catch (\InvalidArgumentException) {
        }
        try {
            new RoundSelection(['code'], [''], [], null, 'http://127.0.0.1');
            $this->fail('empty dataset filter');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        RoundSelection::fromArray(['layers' => ['deploy']]);
    }

    public function testEvalOptionsRequirePositiveCapAndSaneRepeat(): void
    {
        $this->assertSame(['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 0.25], RoundEvalOptions::fromArray(['variant' => 'baseline', 'repeat' => '1', 'maxCostUsd' => '0.25'])->toArray());
        foreach ([['variant' => 'baseline', 'repeat' => 1], ['variant' => 'baseline', 'repeat' => 0, 'maxCostUsd' => 1], ['variant' => 'baseline', 'repeat' => 6, 'maxCostUsd' => 1], ['variant' => 'Bad Name', 'repeat' => 1, 'maxCostUsd' => 1], ['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => -1]] as $raw) {
            try {
                RoundEvalOptions::fromArray($raw);
                $this->fail('expected rejection: ' . json_encode($raw));
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
