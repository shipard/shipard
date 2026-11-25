<?php

declare(strict_types=1);

namespace Shipard\Module\Test\ServerConfig;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/**
 * Fixture: sekce `fixture.section` s jednou hodnotou `label` — výchozí
 * hodnota a chybová hláška jsou věc třídy (#172 D7, D10).
 */
final readonly class FixtureServerConfig implements ServerConfigSection
{
    public const DEFAULT_LABEL = 'default';

    private function __construct(public string $label)
    {
    }

    public static function key(): string
    {
        return 'fixture.section';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        $section = $config->getSection(self::key());
        $label = is_array($section) ? ($section['label'] ?? self::DEFAULT_LABEL) : self::DEFAULT_LABEL;
        if (!is_string($label)) {
            throw new \RuntimeException("Server config 'fixture.section.label' must be a string");
        }
        return new self($label);
    }
}
