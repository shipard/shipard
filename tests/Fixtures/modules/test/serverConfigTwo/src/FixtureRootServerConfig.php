<?php

declare(strict_types=1);

namespace Shipard\Module\Test\ServerConfigTwo;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/** Fixture: cesta, která je prefixem sekcí modulu test.serverConfig (#172 D7, D10). */
final readonly class FixtureRootServerConfig implements ServerConfigSection
{
    private function __construct(public mixed $raw)
    {
    }

    public static function key(): string
    {
        return 'fixture';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        return new self($config->getSection(self::key()));
    }
}
