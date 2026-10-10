<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Jedna AI funkce (tasks/ai-functions.md, #85 D36): co modul volá na
 * jazykovém modelu — `mail.extraction`, `exchange.contentTags`,
 * `dashboard.summary`, `chat.default`. Deklaruje ji modul v `module.jsonc`
 * (`aiFunctions`), ConfigCompiler ji složí do cfgItem `core.ai.functions`.
 *
 * Nese úroveň modelu (`tier`, model určuje mapa úrovní `core.ai.tiers`),
 * výchozí ladění (`maxTokens`, volitelná `temperature`) a požadavky na
 * model (`requires`: `tools` = tool smyčka chatu, `pdf` = přílohy PDF).
 * `thinking` a `effort` deklarace nemá — výchozí stav je „neposílat“.
 *
 * Readonly hodnotový objekt, továrna {@see fromArray()}, validace tvaru
 * v konstruktoru; o pořadí zdrojů rozhoduje {@see AiFunctionResolver}.
 */
final readonly class AiFunction
{
    /** Úrovně modelu sdílené s katalogem ({@see ModelSpec::TIERS}); mapu úrovní nese cfgItem `core.ai.tiers`. */
    public const array TIERS = ModelSpec::TIERS;

    public const string REQUIRES_TOOLS = 'tools';
    public const string REQUIRES_PDF   = 'pdf';
    public const array REQUIREMENTS = [self::REQUIRES_TOOLS, self::REQUIRES_PDF];

    /** @param list<string> $requires */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $tier,
        public int $maxTokens,
        public ?float $temperature,
        public array $requires,
    ) {
        if ($id === '' || preg_match('/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/', $id) !== 1) {
            throw new \InvalidArgumentException("AiFunction: invalid function id '{$id}'");
        }
        if ($name === '') {
            throw new \InvalidArgumentException("AiFunction {$id}: name is required");
        }
        if (!in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException("AiFunction {$id}: unknown tier '{$tier}'");
        }
        if ($maxTokens <= 0) {
            throw new \InvalidArgumentException("AiFunction {$id}: maxTokens must be positive");
        }
        foreach ($requires as $requirement) {
            if (!is_string($requirement) || !in_array($requirement, self::REQUIREMENTS, true)) {
                throw new \InvalidArgumentException("AiFunction {$id}: unknown requirement '" . (is_scalar($requirement) ? $requirement : gettype($requirement)) . "'");
            }
        }
    }

    /**
     * Položka cfgItemu `core.ai.functions` (klíč = id, lokalizovaný název).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
        $requires = is_array($data['requires'] ?? null) ? array_values($data['requires']) : [];
        $temperature = $defaults['temperature'] ?? null;

        return new self(
            id: $id,
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            tier: (string) ($data['tier'] ?? ''),
            maxTokens: is_numeric($defaults['maxTokens'] ?? null) ? (int) $defaults['maxTokens'] : 0,
            temperature: is_numeric($temperature) ? (float) $temperature : null,
            requires: $requires,
        );
    }

    /** Funkce potřebuje tool smyčku (chat) — model musí mít `chatToolLoop` (pojistka D35). */
    public function requiresTools(): bool
    {
        return in_array(self::REQUIRES_TOOLS, $this->requires, true);
    }

    /** Funkce posílá PDF přílohy. */
    public function requiresPdf(): bool
    {
        return in_array(self::REQUIRES_PDF, $this->requires, true);
    }
}
