<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Jeden krok vrstvy kódu testovacího kola (tasks/testing-phase8a.md § 1,
 * D130): argv bez shellu, pracovní adresář a parser výstupu
 * ({@see CodeStepParser}). `problem` ≠ null = krok nejde spustit (chybí
 * nástroj nebo závislosti) a skončí `failed` s přesnou větou, co doinstalovat.
 */
final readonly class CodeStep
{
    public const string PARSER_PHPUNIT = 'phpunit';
    public const string PARSER_NODE_TEST = 'nodeTest';
    public const string PARSER_UNITTEST = 'unittest';
    public const string PARSER_EXIT_CODE = 'exitCode';

    /** @param list<string> $argv */
    public function __construct(
        public string $id,
        public string $label,
        public array $argv,
        public string $cwd,
        public string $parser,
        public ?string $problem = null,
    ) {
        if ($problem === null && $argv === []) {
            throw new \InvalidArgumentException("Code step '{$id}' has no argv");
        }
    }

    /** @return array<string, mixed> pro souhrn a dashboard (bez prostředí) */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'argv' => $this->argv, 'cwd' => $this->cwd, 'parser' => $this->parser, 'problem' => $this->problem];
    }
}
