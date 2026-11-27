<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlaceTypes;
use Shipard\Module\Facility\Buildings\ContractTypes;

/**
 * Konfigurace pro testy doplňku Budovy: skutečné cfgItemy typů smluv a typů
 * míst s českými názvy (jako kompilát `compiled.cs.json`).
 */
trait BuildingsConfig
{
    private static function modulesDir(): string
    {
        return dirname(__DIR__, 5) . '/modules';
    }

    /**
     * cfgItem ze souboru modulu s `name` v češtině.
     *
     * @return array<string, mixed>
     */
    private static function czech(string $file): array
    {
        $localize = static function (array $node) use (&$localize): array {
            $out = [];
            foreach ($node as $key => $value) {
                if (is_string($key) && str_contains($key, ':')) {
                    continue;
                }
                $out[$key] = is_array($value) ? $localize($value) : $value;
                if (is_string($key) && isset($node[$key . ':cs'])) {
                    $out[$key] = $node[$key . ':cs'];
                }
            }
            return $out;
        };
        return $localize(JsoncParser::parseFile(self::modulesDir() . '/' . $file));
    }

    /** @param array<string, mixed> $extra další cfgItemy (id → obsah) */
    private function buildingsConfig(array $extra = []): ConfigRuntime
    {
        $items = $extra + [
            ContractTypes::CFG_ITEM => self::czech('facility/buildings/config/contractTypes.jsonc'),
            PlaceTypes::CFG_ITEM => self::czech('base/places/config/types.jsonc'),
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $config;
    }
}
