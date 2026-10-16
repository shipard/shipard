<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Výsledek {@see DataSourceSecretsMigrator::migrate()} — jen názvy přesunutých
 * souborů a varování, nikdy hodnoty.
 */
final readonly class SecretsMigrationResult
{
    /**
     * @param list<string> $moved    názvy souborů v `secrets/` zapsané z `main.json`
     * @param list<string> $warnings věty pro `[WARN]` (jiná hodnota v souboru,
     *                               neplatné id providera, vlastnictví)
     */
    public function __construct(
        public array $moved = [],
        public array $warnings = [],
    ) {
    }

    public function isNoop(): bool
    {
        return $this->moved === [] && $this->warnings === [];
    }
}
