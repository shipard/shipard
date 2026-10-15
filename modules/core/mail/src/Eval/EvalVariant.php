<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Utils\JsoncParser;

/**
 * Varianta evaluace (tasks/testing-phase4.md D35): soubor
 * `tests/eval/variants/<název>.jsonc` (`shpd.eval.variant.v1`) — profil
 * pošty, volitelná šablona promptu ze souboru (nahradí `prompt_template`
 * profilu jen v paměti běhu), přepsání volání funkce `mail.extraction`
 * (`connection` = kód připojení nebo `server`, model, thinking, effort,
 * maxTokens) a přepsání funkce `exchange.contentTags`. `null` = jak je
 * nastaveno ve zdroji dat.
 */
final readonly class EvalVariant
{
    public const string FORMAT = 'shpd.eval.variant.v1';
    public const array CALL_KEYS = ['connection', 'model', 'thinking', 'effort', 'maxTokens'];
    public const array CONTENT_TAG_KEYS = ['model', 'thinking', 'effort'];
    private const array TOP_KEYS = ['format', 'name', 'description', 'profile', 'promptTemplateFile', 'call', 'contentTags'];
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /**
     * @param array<string, mixed> $call        deklarované hodnoty (klíče CALL_KEYS)
     * @param array<string, mixed> $contentTags deklarované hodnoty (klíče CONTENT_TAG_KEYS)
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $profile,
        public ?string $promptTemplateFile,
        public ?string $promptTemplate,
        public array $call,
        public array $contentTags,
        public string $path,
    ) {}

    /** @throws EvalException */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new EvalException("varianta {$path} neexistuje");
        }
        try {
            $raw = JsoncParser::parseFile($path);
        } catch (\Throwable $e) {
            throw new EvalException("varianta {$path}: " . $e->getMessage(), $e);
        }
        return self::fromArray($raw, $path);
    }

    /**
     * @param array<string, mixed> $raw
     * @throws EvalException
     */
    public static function fromArray(array $raw, string $path): self
    {
        $problems = [];
        if (($raw['format'] ?? null) !== self::FORMAT) {
            $problems[] = 'format musí být ' . self::FORMAT;
        }
        $name = $raw['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $problems[] = 'name musí odpovídat [a-z0-9][a-z0-9._-]* (max 64 znaků)';
            $name = '';
        }
        $description = $raw['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            $problems[] = 'description musí být řetězec nebo null';
            $description = null;
        }
        $profile = $raw['profile'] ?? null;
        if (!is_string($profile) || trim($profile) === '') {
            $problems[] = 'profile (kód profilu pošty) je povinný';
            $profile = '';
        }

        $file = $raw['promptTemplateFile'] ?? null;
        $template = null;
        if ($file !== null) {
            if (!is_string($file) || trim($file) === '') {
                $problems[] = 'promptTemplateFile musí být cesta k souboru nebo null';
            } else {
                $resolved = str_starts_with($file, '/') ? $file : dirname($path) . '/' . $file;
                if (!is_file($resolved)) {
                    $problems[] = "promptTemplateFile {$resolved} neexistuje";
                } else {
                    $template = (string) file_get_contents($resolved);
                    if (trim($template) === '') {
                        $problems[] = "promptTemplateFile {$resolved} je prázdný";
                    }
                }
            }
        }

        $call = self::section($raw, 'call', self::CALL_KEYS, $problems);
        $contentTags = self::section($raw, 'contentTags', self::CONTENT_TAG_KEYS, $problems);

        $unknown = array_diff(array_keys($raw), self::TOP_KEYS);
        if ($unknown !== []) {
            $problems[] = 'neznámé klíče: ' . implode(', ', array_map('strval', $unknown));
        }
        if ($problems !== []) {
            throw new EvalException('varianta ' . basename($path) . ': ' . implode('; ', $problems));
        }
        return new self($name, $description, trim($profile), is_string($file) ? $file : null, $template, $call, $contentTags, $path);
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $keys
     * @param list<string>         $problems
     * @return array<string, mixed>
     */
    private static function section(array $raw, string $key, array $keys, array &$problems): array
    {
        $section = $raw[$key] ?? [];
        if (!is_array($section) || ($section !== [] && array_is_list($section))) {
            $problems[] = "{$key} musí být objekt";
            return array_fill_keys($keys, null);
        }
        $out = array_fill_keys($keys, null);
        foreach ($section as $k => $value) {
            if (!in_array($k, $keys, true)) {
                $problems[] = "{$key}.{$k}: neznámý klíč (známé: " . implode(', ', $keys) . ')';
                continue;
            }
            if ($value === null) {
                continue;
            }
            if ($k === 'maxTokens') {
                if (!is_int($value) || $value <= 0) {
                    $problems[] = "{$key}.maxTokens musí být kladné celé číslo nebo null";
                    continue;
                }
            } elseif (!is_string($value) || trim($value) === '') {
                $problems[] = "{$key}.{$k} musí být neprázdný řetězec nebo null";
                continue;
            }
            $out[$k] = is_string($value) ? trim($value) : $value;
        }
        return $out;
    }

    /** Přepsání volání `mail.extraction` — skládá se nad přepsání z profilu ({@see AiCallOverride::merge()}). */
    public function callOverride(): AiCallOverride
    {
        return new AiCallOverride(
            model: $this->call['model'],
            thinking: $this->call['thinking'],
            effort: $this->call['effort'],
            maxTokens: $this->call['maxTokens'],
            connectionCode: $this->call['connection'],
        );
    }

    /** Přepsání funkce `exchange.contentTags`; null = nic nepřepisovat. */
    public function contentTagsOverride(): ?AiCallOverride
    {
        if ($this->contentTags['model'] === null && $this->contentTags['thinking'] === null && $this->contentTags['effort'] === null) {
            return null;
        }
        return new AiCallOverride(
            model: $this->contentTags['model'],
            thinking: $this->contentTags['thinking'],
            effort: $this->contentTags['effort'],
        );
    }

    /** Hash šablony promptu ze souboru; null = šablona profilu. */
    public function promptHash(): ?string
    {
        return $this->promptTemplate !== null ? hash('sha256', $this->promptTemplate) : null;
    }

    /** @return array<string, mixed> deklarované hodnoty do souboru výsledků */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'profile' => $this->profile,
            'promptTemplateFile' => $this->promptTemplateFile,
            'call' => $this->call,
            'contentTags' => $this->contentTags,
        ];
    }
}
