<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Režimy registrovaného zařízení (#177 D13) — cfgItem `core.devices.modes`.
 * Hodnoty jsou součást kontraktu mostu (docs/native-bridge.md §1), proto má
 * třída pevný seznam jako zálohu pro běh bez zkompilované konfigurace
 * (testy, CLI): validace režimu nesmí bez configu pustit cokoli.
 */
final class DeviceModes
{
    public const CFG_ITEM = 'core.devices.modes';

    public const string PERSONAL = 'personal';
    public const string SHARED = 'shared';

    private const array CONTRACT_MODES = [self::PERSONAL, self::SHARED];

    /** @param array<string, string> $labels režim → název */
    private function __construct(private readonly array $labels)
    {
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $cfg = $config?->cfgItem(self::CFG_ITEM);
        $labels = [];
        foreach (is_array($cfg) ? $cfg : [] as $key => $entry) {
            $labels[(string) $key] = is_array($entry) ? (string) ($entry['name'] ?? $key) : (string) $key;
        }
        if ($labels === []) {
            $labels = array_combine(self::CONTRACT_MODES, self::CONTRACT_MODES);
        }
        return new self($labels);
    }

    public function isValid(string $mode): bool
    {
        return isset($this->labels[$mode]);
    }

    public function label(string $mode): string
    {
        return $this->labels[$mode] ?? $mode;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->labels);
    }
}
