<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Čtení cfgItem `core.prints.printerDrivers` (#177 U28). Bez zkompilované
 * konfigurace není znám žádný ovladač — `get()` pak končí výjimkou
 * s návodem, kodér bez ovladače nic nesloží.
 */
final class EscPosDrivers
{
    public const CFG_ITEM = 'core.prints.printerDrivers';

    /** @var array<string, EscPosDriver> */
    private array $drivers = [];

    /** @param array<string, mixed> $definitions klíč ovladače → definice */
    public function __construct(private readonly array $definitions)
    {
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $cfg = $config?->cfgItem(self::CFG_ITEM);
        return new self(is_array($cfg) ? $cfg : []);
    }

    public function has(string $id): bool
    {
        return is_array($this->definitions[$id] ?? null);
    }

    public function get(string $id): EscPosDriver
    {
        if (isset($this->drivers[$id])) {
            return $this->drivers[$id];
        }
        $definition = $this->definitions[$id] ?? null;
        if (!is_array($definition)) {
            throw new EscPosConfigException(
                "Printer driver '{$id}' is not defined (cfgItem " . self::CFG_ITEM . ' — run ds-upgrade)',
            );
        }
        return $this->drivers[$id] = EscPosDriver::fromArray($id, $definition);
    }
}
