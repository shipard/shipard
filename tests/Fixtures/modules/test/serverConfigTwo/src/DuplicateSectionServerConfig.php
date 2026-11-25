<?php

declare(strict_types=1);

namespace Shipard\Module\Test\ServerConfigTwo;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/** Fixture: stejná cesta jako sekce modulu test.serverConfig (#172 D7, D10). */
final readonly class DuplicateSectionServerConfig implements ServerConfigSection
{
    private function __construct(public mixed $raw)
    {
    }

    public static function key(): string
    {
        return 'fixture.section';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        return new self($config->getSection(self::key()));
    }
}
