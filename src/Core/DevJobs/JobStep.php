<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Jeden krok úlohy: argv (program a argumenty — žádný shell, žádné skládání
 * příkazové řádky z požadavku), pracovní adresář a zda stdout kroku patří
 * do `result.json` (`captureStdout`; jinak do `output.log` spolu se stderr).
 * Nejvýš jeden krok úlohy smí stdout zachytávat.
 */
final readonly class JobStep
{
    /** @param list<string> $argv */
    public function __construct(
        public string $label,
        public array $argv,
        public string $cwd,
        public bool $captureStdout = false,
    ) {
        if ($argv === []) {
            throw new \InvalidArgumentException('Job step argv must not be empty');
        }
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('Job step argv must be a list of strings');
            }
        }
        if ($cwd === '') {
            throw new \InvalidArgumentException('Job step cwd must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $argv = $raw['argv'] ?? null;
        if (!is_array($argv)) {
            throw new \InvalidArgumentException('Job step argv missing');
        }
        return new self(
            (string) ($raw['label'] ?? ''),
            array_values(array_map(static fn (mixed $a): string => (string) $a, $argv)),
            (string) ($raw['cwd'] ?? ''),
            (bool) ($raw['captureStdout'] ?? false),
        );
    }

    /** @return array{label: string, argv: list<string>, cwd: string, captureStdout: bool} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'argv' => $this->argv,
            'cwd' => $this->cwd,
            'captureStdout' => $this->captureStdout,
        ];
    }
}
