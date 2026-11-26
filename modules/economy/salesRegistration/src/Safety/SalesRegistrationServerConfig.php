<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Safety;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/**
 * Pojistka serveru pro evidenci tržeb (#171 D9) — sekce `salesRegistration`
 * v `/etc/shipard/server.json`, klíč `safety.mode`. Na dev a testovacích
 * serverech brání tomu, aby tržby z kopie ostrých dat odešly správci daně
 * pod certifikátem zákazníka. Nastavuje se jen na úrovni serveru — zdroj dat
 * ji přepsat nemůže.
 *
 * Režimy: `off` (rozhoduje režim registrace), `playground` (všechno na
 * neprodukční prostředí se sdílenou testovací identitou, nikdy
 * s certifikátem zdroje dat), `drop` (nic neodejde).
 *
 * Sekce chybí → jen server výslovně označený jako produkční posílá (`off`),
 * jinak `drop`. Konfigurace nikdy nevyhazuje výjimku: chybná nebo nečitelná
 * znamená `drop` se `source: invalid` (vzor `MailSafetyConfig`).
 */
final readonly class SalesRegistrationServerConfig implements ServerConfigSection
{
    public const string KEY = 'salesRegistration';

    public const string MODE_OFF = 'off';
    public const string MODE_PLAYGROUND = 'playground';
    public const string MODE_DROP = 'drop';

    public const array MODES = [self::MODE_OFF, self::MODE_PLAYGROUND, self::MODE_DROP];

    /** Režim je ze sekce `salesRegistration.safety`. */
    public const string SOURCE_CONFIGURED = 'configured';
    /** Sekce chybí — režim podle režimu serveru. */
    public const string SOURCE_DEFAULT = 'default';
    /** Sekce je chybná nebo `server.json` nejde načíst — `drop`. */
    public const string SOURCE_INVALID = 'invalid';

    /** @param ?string $problem co je na konfiguraci špatně (jen `source: invalid`) */
    private function __construct(
        public string $mode,
        public string $source = self::SOURCE_CONFIGURED,
        public ?string $problem = null,
    ) {
    }

    public static function key(): string
    {
        return self::KEY;
    }

    /** Výslovně nastavený režim — pro testy a volající bez serveru. */
    public static function ofMode(string $mode): self
    {
        return in_array($mode, self::MODES, true)
            ? new self($mode)
            : self::failClosed("mode must be one of: " . implode(', ', self::MODES));
    }

    /** Konfiguraci nejde použít — nic neodejde. */
    public static function failClosed(string $problem): self
    {
        return new self(self::MODE_DROP, self::SOURCE_INVALID, $problem);
    }

    /**
     * Pojistka serveru; `null` = načte `server.json` sama (viewer ani
     * upozornění `ServerConfig` nedostávají). Chyba načtení je `drop`.
     */
    public static function forServer(?ServerConfig $serverConfig = null): self
    {
        try {
            if ($serverConfig === null) {
                $serverConfig = new ServerConfig();
                $serverConfig->load();
            }
            return self::fromServerConfig($serverConfig);
        } catch (\Throwable $e) {
            return self::failClosed('server.json cannot be read: ' . $e->getMessage());
        }
    }

    /** Nikdy nevyhazuje — chybná sekce je `drop` s popisem problému. */
    public static function fromServerConfig(ServerConfig $config): static
    {
        // Režim serveru přes getSection(): u nenačteného ServerConfig vrátí
        // null (→ drop), getter by spadl.
        $section = $config->getSection(self::KEY);
        $serverMode = $config->getSection('mode');

        if ($section !== null && !is_array($section)) {
            return self::failClosed("'salesRegistration' must be an object");
        }
        $safety = $section['safety'] ?? null;
        if ($safety === null) {
            // Jen server výslovně označený jako produkční posílá bez pojistky.
            return $serverMode === 'production'
                ? new self(self::MODE_OFF, self::SOURCE_DEFAULT)
                : new self(self::MODE_DROP, self::SOURCE_DEFAULT);
        }
        if (!is_array($safety)) {
            return self::failClosed("'salesRegistration.safety' must be an object");
        }

        $mode = $safety['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            return self::failClosed("'salesRegistration.safety.mode' must be one of: " . implode(', ', self::MODES));
        }

        return new self($mode);
    }

    public function isDrop(): bool
    {
        return $this->mode === self::MODE_DROP;
    }
}
