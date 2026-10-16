<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Soubory variant evaluace `tests/eval/variants/<název>.jsonc` (editor
 * variant, tasks/testing-phase6b.md § 7, D62). Identita varianty pro API je
 * **název souboru bez přípony**; vybírá se jen z výpisu, z parametru URL se
 * nikdy neskládá cesta. Uložení projde stejnou validací jako načtení
 * ({@see EvalVariant::fromArray()}), název nové varianty jen `[a-z0-9-]`,
 * existující soubor se přepíše jen s potvrzením
 * ({@see EvalVariantExistsException}). Zápis je atomický (tmp + rename).
 */
final class EvalVariantStore
{
    public const string DIR = 'tests/eval/variants';
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    private const string EXTENSION = '.jsonc';
    private const array DECLARED_KEYS = ['description', 'profile', 'promptTemplateFile', 'call', 'contentTags'];

    public function __construct(private readonly string $dir) {}

    public static function defaultDir(string $repoRoot): string
    {
        return rtrim($repoRoot, '/') . '/' . self::DIR;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Varianty podle názvu souboru; nevalidní soubor zůstává ve výpisu
     * s `valid: false` a chybou, aby šel v editoru opravit.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = [];
        $files = glob($this->dir . '/*' . self::EXTENSION) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $file = basename($path);
            $entry = ['name' => substr($file, 0, -strlen(self::EXTENSION)), 'file' => $file];
            try {
                $variant = EvalVariant::load($path);
                $out[] = $entry + ['valid' => true, 'error' => null] + $variant->toArray() + ['declaredName' => $variant->name];
            } catch (EvalException $e) {
                $out[] = $entry + ['valid' => false, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** Cesta k souboru varianty podle názvu — jen přesná shoda s výpisem. */
    public function pathFor(string $name): ?string
    {
        foreach ($this->list() as $entry) {
            if ($entry['name'] === $name) {
                return $this->dir . '/' . $entry['file'];
            }
        }
        return null;
    }

    /** @throws EvalException soubor existuje, ale není platná varianta */
    public function find(string $name): ?EvalVariant
    {
        $path = $this->pathFor($name);
        return $path === null ? null : EvalVariant::load($path);
    }

    /**
     * Uloží variantu `<název>.jsonc` z deklarovaných hodnot (`description`,
     * `profile`, `promptTemplateFile`, `call`, `contentTags`; `name` i `format`
     * doplní). Vrací načtenou variantu.
     *
     * @param array<string, mixed> $declared
     * @throws EvalVariantExistsException soubor existuje a `$overwrite` je false
     * @throws EvalException             neplatný název nebo obsah, nejde zapsat
     */
    public function save(string $name, array $declared, bool $overwrite): EvalVariant
    {
        if (!self::isValidName($name)) {
            throw new EvalException('název varianty musí odpovídat [a-z0-9][a-z0-9-]* (max 64 znaků)');
        }
        $unknown = array_diff(array_keys($declared), self::DECLARED_KEYS);
        if ($unknown !== []) {
            throw new EvalException('neznámé klíče varianty: ' . implode(', ', array_map('strval', $unknown)));
        }
        $path = $this->dir . '/' . $name . self::EXTENSION;
        if (is_file($path) && !$overwrite) {
            throw new EvalVariantExistsException("varianta {$name} už existuje — přepsání vyžaduje potvrzení");
        }
        $raw = ['format' => EvalVariant::FORMAT, 'name' => $name];
        foreach (self::DECLARED_KEYS as $key) {
            $raw[$key] = $declared[$key] ?? null;
        }
        $raw['call'] = is_array($raw['call']) ? self::nullsFor($raw['call'], EvalVariant::CALL_KEYS) : $raw['call'];
        $raw['contentTags'] = is_array($raw['contentTags']) ? self::nullsFor($raw['contentTags'], EvalVariant::CONTENT_TAG_KEYS) : $raw['contentTags'];
        // Validace stejná jako při načtení (promptTemplateFile se hledá relativně k variants/).
        EvalVariant::fromArray($raw, $path);

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new EvalException("nejde založit složku variant {$this->dir}");
        }
        $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new EvalException('variantu nejde zapsat jako JSON');
        }
        $body = "// Varianta evaluace AI analýzy (" . EvalVariant::FORMAT . ") — uloženo editorem variant vývojářského dashboardu\n"
            . "// (docs/test-corpus.md § 18). null = jak je nastaveno ve zdroji dat; `connection` = kód připojení nebo `server`.\n"
            . $json . "\n";
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            throw new EvalException("nejde zapsat {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new EvalException("nejde zapsat {$path}");
        }
        return EvalVariant::load($path);
    }

    /**
     * Soubory použitelné jako `promptTemplateFile` — vše mimo `.jsonc`
     * v `variants/` a v podsložce `prompts/`, relativně k `variants/`.
     *
     * @return list<string>
     */
    public function promptTemplates(): array
    {
        $out = [];
        foreach ([$this->dir, $this->dir . '/prompts'] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (!is_file($path) || str_ends_with($path, self::EXTENSION) || str_ends_with($path, '.tmp')) {
                    continue;
                }
                $out[] = substr($path, strlen($this->dir) + 1);
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Sekce s úplnou sadou klíčů (chybějící = null), prázdný řetězec = null.
     *
     * @param array<string, mixed> $section
     * @param list<string>         $keys
     * @return array<string, mixed>
     */
    private static function nullsFor(array $section, array $keys): array
    {
        $out = [];
        foreach ($section as $k => $v) {
            $out[$k] = is_string($v) && trim($v) === '' ? null : $v;
        }
        foreach ($keys as $key) {
            $out[$key] ??= null;
        }
        return $out;
    }
}
