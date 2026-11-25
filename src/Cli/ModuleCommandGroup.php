<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Symfony\Component\Console\Command\Command;

/** Příkazy jednoho modulu pro registraci a nápovědu (#172 D2, D5). */
final readonly class ModuleCommandGroup
{
    /** @param list<Command> $commands v pořadí deklarace v `module.jsonc` */
    public function __construct(
        public string $moduleId,
        public string $moduleName,
        public array $commands,
    ) {}
}
