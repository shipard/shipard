<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Jedna položka katalogu modelů (`core.ai.models`, tasks/ai-model-catalog.md
 * D30, D31, D33): identita a platformové ID, schopnosti, podle kterých se
 * tvaruje požadavek, ceny a životní cyklus.
 *
 * Schopnosti jsou nullable záměrně: `null` = nevíme → `RequestShaper`
 * hodnotu neomezuje a pošle ji beze změny. Položka smí nést jen ceny
 * (modely, které nenabízíme) — pak jsou všechny schopnosti null.
 *
 * Readonly hodnotový objekt, továrna {@see fromArray()}, validace tvaru
 * v konstruktoru; žádná business logika (ta je v {@see ModelCatalog}).
 */
final readonly class ModelSpec
{
    public const string TIER_FAST     = 'fast';
    public const string TIER_BALANCED = 'balanced';
    public const string TIER_STRONG   = 'strong';
    public const array TIERS = [self::TIER_FAST, self::TIER_BALANCED, self::TIER_STRONG];

    public const string STATUS_ACTIVE     = 'active';
    public const string STATUS_DEPRECATED = 'deprecated';
    public const string STATUS_RETIRED    = 'retired';
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_DEPRECATED, self::STATUS_RETIRED];

    public const string THINKING_DEFAULT_OFF      = 'off';
    public const string THINKING_DEFAULT_ADAPTIVE = 'adaptive';

    /**
     * @param array<string, string>                               $platformIds  platforma → ID modelu
     * @param list<string>|null                                   $thinkingModes
     * @param list<string>|null                                   $effortLevels  [] = parametr nezná
     * @param array{over: int, input: float, output: float}|null  $longPrompt    dražší pásmo nad `over` vstupních tokenů
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $vendor,
        public string $dialect,
        public int $order,
        public ?string $tier,
        public array $platformIds,
        public ?bool $temperature,
        public ?array $thinkingModes,
        public ?string $thinkingDefault,
        public ?array $effortLevels,
        public ?string $thinkingOffMaxEffort,
        public ?int $maxOutputTokens,
        public ?bool $chatToolLoop,
        public ?float $priceInput,
        public ?float $priceOutput,
        public ?array $longPrompt,
        public string $status,
        public ?string $retiresAt,
        public ?string $retiresNotBefore,
        public ?string $replacedBy,
    ) {
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9.-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException("ModelSpec: invalid model id '{$id}'");
        }
        if ($name === '') {
            throw new \InvalidArgumentException("ModelSpec {$id}: name is required");
        }
        if ($tier !== null && !in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException("ModelSpec {$id}: unknown tier '{$tier}'");
        }
        foreach ($platformIds as $platform => $platformId) {
            if (!is_string($platform) || $platform === '' || !is_string($platformId) || $platformId === '') {
                throw new \InvalidArgumentException("ModelSpec {$id}: platformIds must map platform → model id");
            }
        }
        if ($thinkingDefault !== null
            && !in_array($thinkingDefault, [self::THINKING_DEFAULT_OFF, self::THINKING_DEFAULT_ADAPTIVE], true)
        ) {
            throw new \InvalidArgumentException("ModelSpec {$id}: unknown thinkingDefault '{$thinkingDefault}'");
        }
        self::assertStringList($id, 'thinkingModes', $thinkingModes);
        self::assertStringList($id, 'effortLevels', $effortLevels);
        if ($thinkingOffMaxEffort !== null && $effortLevels !== null
            && !in_array($thinkingOffMaxEffort, $effortLevels, true)
        ) {
            throw new \InvalidArgumentException("ModelSpec {$id}: thinkingOffMaxEffort '{$thinkingOffMaxEffort}' is not an accepted effort level");
        }
        if ($maxOutputTokens !== null && $maxOutputTokens <= 0) {
            throw new \InvalidArgumentException("ModelSpec {$id}: maxOutputTokens must be positive");
        }
        if (($priceInput === null) !== ($priceOutput === null)) {
            throw new \InvalidArgumentException("ModelSpec {$id}: prices need both input and output");
        }
        if (($priceInput !== null && $priceInput < 0) || ($priceOutput !== null && $priceOutput < 0)) {
            throw new \InvalidArgumentException("ModelSpec {$id}: prices must not be negative");
        }
        if ($longPrompt !== null) {
            if ($priceInput === null) {
                throw new \InvalidArgumentException("ModelSpec {$id}: longPrompt requires base prices");
            }
            if (!isset($longPrompt['over'], $longPrompt['input'], $longPrompt['output'])
                || !is_int($longPrompt['over']) || $longPrompt['over'] <= 0
                || !is_float($longPrompt['input']) || !is_float($longPrompt['output'])
                || $longPrompt['input'] < 0 || $longPrompt['output'] < 0
            ) {
                throw new \InvalidArgumentException("ModelSpec {$id}: longPrompt needs {over: int > 0, input, output}");
            }
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("ModelSpec {$id}: unknown lifecycle status '{$status}'");
        }
        foreach (['retiresAt' => $retiresAt, 'retiresNotBefore' => $retiresNotBefore] as $field => $date) {
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new \InvalidArgumentException("ModelSpec {$id}: {$field} must be YYYY-MM-DD");
            }
        }
        if ($replacedBy !== null && $replacedBy === $id) {
            throw new \InvalidArgumentException("ModelSpec {$id}: replacedBy must point to another model");
        }
    }

    /**
     * Položka katalogu z JSONC (`core.ai.models`): klíč = ID modelu,
     * hodnota = pole polí popsaných v hlavičce `config/models.jsonc`.
     * Chybějící `capabilities` / jednotlivá pole = null (nevíme).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        $caps = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
        $prices = is_array($data['prices'] ?? null) ? $data['prices'] : [];
        $lifecycle = is_array($data['lifecycle'] ?? null) ? $data['lifecycle'] : [];
        $platformIds = is_array($data['platformIds'] ?? null) ? $data['platformIds'] : [];

        $longPrompt = null;
        if (is_array($prices['longPrompt'] ?? null)) {
            $lp = $prices['longPrompt'];
            $longPrompt = [
                'over'   => is_numeric($lp['over'] ?? null) ? (int) $lp['over'] : 0,
                'input'  => is_numeric($lp['input'] ?? null) ? (float) $lp['input'] : -1.0,
                'output' => is_numeric($lp['output'] ?? null) ? (float) $lp['output'] : -1.0,
            ];
        }

        return new self(
            id: $id,
            name: (string) ($data['name'] ?? ''),
            vendor: (string) ($data['vendor'] ?? 'anthropic'),
            dialect: (string) ($data['dialect'] ?? 'anthropic-messages'),
            order: is_numeric($data['order'] ?? null) ? (int) $data['order'] : 1000,
            tier: self::optString($data['tier'] ?? null),
            platformIds: $platformIds,
            temperature: self::optBool($caps['temperature'] ?? null),
            thinkingModes: self::optList($caps['thinkingModes'] ?? null),
            thinkingDefault: self::optString($caps['thinkingDefault'] ?? null),
            effortLevels: self::optList($caps['effortLevels'] ?? null),
            thinkingOffMaxEffort: self::optString($caps['thinkingOffMaxEffort'] ?? null),
            maxOutputTokens: is_numeric($caps['maxOutputTokens'] ?? null) ? (int) $caps['maxOutputTokens'] : null,
            chatToolLoop: self::optBool($caps['chatToolLoop'] ?? null),
            priceInput: is_numeric($prices['input'] ?? null) ? (float) $prices['input'] : null,
            priceOutput: is_numeric($prices['output'] ?? null) ? (float) $prices['output'] : null,
            longPrompt: $longPrompt,
            status: (string) ($lifecycle['status'] ?? self::STATUS_ACTIVE),
            retiresAt: self::optString($lifecycle['retiresAt'] ?? null),
            retiresNotBefore: self::optString($lifecycle['retiresNotBefore'] ?? null),
            replacedBy: self::optString($lifecycle['replacedBy'] ?? null),
        );
    }

    public function hasPrices(): bool
    {
        return $this->priceInput !== null && $this->priceOutput !== null;
    }

    /**
     * Sazby `[vstup, výstup]` v USD za milion tokenů pro daný počet
     * vstupních tokenů (dražší pásmo nad `longPrompt.over`), null bez cen.
     *
     * @return array{0: float, 1: float}|null
     */
    public function ratesFor(int $tokensInput): ?array
    {
        if (!$this->hasPrices()) {
            return null;
        }
        if ($this->longPrompt !== null && $tokensInput > $this->longPrompt['over']) {
            return [$this->longPrompt['input'], $this->longPrompt['output']];
        }
        return [(float) $this->priceInput, (float) $this->priceOutput];
    }

    /** Deprecated nebo retired — kandidát na přepis při `ds-upgrade`. */
    public function isRetiring(): bool
    {
        return $this->status !== self::STATUS_ACTIVE;
    }

    private static function optString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function optBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** @return list<string>|null */
    private static function optList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = $item;
        }
        return $out;
    }

    /** @param list<string>|null $list */
    private static function assertStringList(string $id, string $field, ?array $list): void
    {
        if ($list === null) {
            return;
        }
        foreach ($list as $item) {
            if (!is_string($item) || $item === '') {
                throw new \InvalidArgumentException("ModelSpec {$id}: {$field} must be a list of strings");
            }
        }
    }
}
