<?php

declare(strict_types=1);

namespace Shipard\Module\Test\ServerConfig;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/** Fixture: třída, jejíž key() se liší od deklarace (#172 D7, D10). */
final readonly class KeyMismatchServerConfig implements ServerConfigSection
{
    private function __construct(public mixed $raw)
    {
    }

    public static function key(): string
    {
        return 'fixture.actual';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        return new self($config->getSection(self::key()));
    }
}
