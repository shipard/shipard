<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\PrintMessage;

/**
 * Katalog profilů účtenkových tiskáren — čtení cfgItem
 * `core.prints.printerProfiles` (#177 U5, U29).
 *
 * Bez zkompilované konfigurace je katalog prázdný (degradace, ne pád):
 * endpoint vrátí prázdný seznam. Skládat účtenku bez profilu ale nejde —
 * `get()` pak končí výjimkou s návodem.
 */
final class PrinterProfiles
{
    public const CFG_ITEM = 'core.prints.printerProfiles';

    /** Záložní profil: nejužší, vejde se na každou tiskárnu. */
    public const FALLBACK_ID = 'escpos-58-cp852';

    public const WARNING_NO_PROFILE      = 'ESCPOS_PROFILE_MISSING';
    public const WARNING_UNKNOWN_PROFILE = 'ESCPOS_PROFILE_UNKNOWN';

    /** @var array<string, PrinterProfile> */
    private array $profiles = [];

    /** @param array<string, mixed> $definitions id profilu → definice */
    public function __construct(private readonly array $definitions)
    {
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $cfg = $config?->cfgItem(self::CFG_ITEM);
        return new self(is_array($cfg) ? $cfg : []);
    }

    /**
     * @return list<PrinterProfile> v pořadí definice
     * @throws EscPosConfigException Definice profilu nemá očekávaný tvar.
     */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->definitions) as $id) {
            $profile = $this->find((string) $id);
            if ($profile !== null) {
                $all[] = $profile;
            }
        }
        return $all;
    }

    public function find(string $id): ?PrinterProfile
    {
        if (isset($this->profiles[$id])) {
            return $this->profiles[$id];
        }
        $definition = $this->definitions[$id] ?? null;
        if (!is_array($definition)) {
            return null;
        }
        return $this->profiles[$id] = PrinterProfile::fromArray($id, $definition);
    }

    /**
     * Profil pro skládání účtenky. Neznámé nebo neuvedené id dostane záložní
     * profil a varování — tiskárna něco vytiskne a úloha řekne proč takhle.
     *
     * @return array{profile: PrinterProfile, warning: ?PrintMessage}
     * @throws EscPosConfigException Katalog nemá ani záložní profil.
     */
    public function get(?string $id): array
    {
        if ($id !== null && $id !== '') {
            $profile = $this->find($id);
            if ($profile !== null) {
                return ['profile' => $profile, 'warning' => null];
            }
        }

        $fallback = $this->find(self::FALLBACK_ID);
        if ($fallback === null) {
            throw new EscPosConfigException(
                "Printer profile '" . self::FALLBACK_ID . "' is not defined (cfgItem "
                . self::CFG_ITEM . ' — run ds-upgrade)',
            );
        }

        $warning = $id === null || $id === ''
            ? PrintMessage::warning(
                self::WARNING_NO_PROFILE,
                "No printer profile given, using '" . self::FALLBACK_ID . "'",
            )
            : PrintMessage::warning(
                self::WARNING_UNKNOWN_PROFILE,
                "Unknown printer profile '{$id}', using '" . self::FALLBACK_ID . "'",
            );

        return ['profile' => $fallback, 'warning' => $warning];
    }
}
