<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Pole dialogu akce záznamu (#172 D13). Vzniká pojmenovanou továrnou podle
 * typu — `text()`, `textarea()`, `password()`, `number()`, `date()`,
 * `datetime()`, `checkbox()`, `select()`, `file()`, `notice()`; tvar hlídá
 * konstruktor, takže pole mimo kontrakt je výjimka z `describe()` (akce se
 * v detailu vynechá, #172 D19).
 *
 * Výchozí hodnota `"now"` u `date` / `datetime` znamená „teď“ — doplní ji
 * frontend při otevření dialogu. `notice` je jen text bez hodnoty.
 */
final class RecordActionField
{
    public const string TEXT = 'text';
    public const string TEXTAREA = 'textarea';
    public const string PASSWORD = 'password';
    public const string NUMBER = 'number';
    public const string DATE = 'date';
    public const string DATETIME = 'datetime';
    public const string CHECKBOX = 'checkbox';
    public const string SELECT = 'select';
    public const string FILE = 'file';
    public const string NOTICE = 'notice';

    public const array TYPES = [
        self::TEXT, self::TEXTAREA, self::PASSWORD, self::NUMBER, self::DATE,
        self::DATETIME, self::CHECKBOX, self::SELECT, self::FILE, self::NOTICE,
    ];

    /** Výchozí hodnota `date` / `datetime`: okamžik otevření dialogu. */
    public const string NOW = 'now';

    /** Strop velikosti souboru, který deklarace `maxBytes` nesmí překročit. */
    public const int MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const string DATE_FORMAT = 'Y-m-d';
    /** Tvar hodnoty `<input type="datetime-local">`. */
    public const string DATETIME_FORMAT = 'Y-m-d\TH:i';

    /**
     * @param list<array{value: string|int, label: string}>|null $options nabídka pole `select`
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly string|int|float|bool|null $default = null,
        public readonly ?string $hint = null,
        public readonly ?array $options = null,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly int|float|null $step = null,
        public readonly ?string $accept = null,
        public readonly ?int $maxBytes = null,
    ) {
        if (preg_match('/^[a-z][a-zA-Z0-9_]*$/', $id) !== 1) {
            throw new \InvalidArgumentException("Record action field id '{$id}' must match [a-z][a-zA-Z0-9_]*");
        }
        if (trim($label) === '') {
            throw new \InvalidArgumentException("Record action field '{$id}' needs a label");
        }
        if ($hint !== null && trim($hint) === '') {
            throw new \InvalidArgumentException("Record action field '{$id}': hint must not be empty");
        }
    }

    public static function text(
        string $id,
        string $label,
        bool $required = false,
        ?string $default = null,
        ?string $hint = null,
    ): self {
        return new self($id, self::TEXT, $label, $required, $default, $hint);
    }

    public static function textarea(
        string $id,
        string $label,
        bool $required = false,
        ?string $default = null,
        ?string $hint = null,
    ): self {
        return new self($id, self::TEXTAREA, $label, $required, $default, $hint);
    }

    /** Heslo nemá výchozí hodnotu — do prohlížeče se žádné tajemství neposílá. */
    public static function password(string $id, string $label, bool $required = false, ?string $hint = null): self
    {
        return new self($id, self::PASSWORD, $label, $required, null, $hint);
    }

    public static function number(
        string $id,
        string $label,
        bool $required = false,
        int|float|null $default = null,
        ?string $hint = null,
        int|float|null $min = null,
        int|float|null $max = null,
        int|float|null $step = null,
    ): self {
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException("Record action field '{$id}': min is greater than max");
        }
        if ($step !== null && $step <= 0) {
            throw new \InvalidArgumentException("Record action field '{$id}': step must be positive");
        }
        return new self($id, self::NUMBER, $label, $required, $default, $hint, min: $min, max: $max, step: $step);
    }

    /** @param string|null $default `YYYY-MM-DD`, nebo {@see self::NOW} */
    public static function date(
        string $id,
        string $label,
        bool $required = false,
        ?string $default = null,
        ?string $hint = null,
    ): self {
        if ($default !== null && $default !== self::NOW && self::parseDate($default) === null) {
            throw new \InvalidArgumentException("Record action field '{$id}': default must be YYYY-MM-DD or 'now'");
        }
        return new self($id, self::DATE, $label, $required, $default, $hint);
    }

    /** @param string|null $default `YYYY-MM-DDTHH:MM`, nebo {@see self::NOW} */
    public static function datetime(
        string $id,
        string $label,
        bool $required = false,
        ?string $default = null,
        ?string $hint = null,
    ): self {
        if ($default !== null && $default !== self::NOW && self::parseDateTime($default) === null) {
            throw new \InvalidArgumentException(
                "Record action field '{$id}': default must be YYYY-MM-DDTHH:MM or 'now'",
            );
        }
        return new self($id, self::DATETIME, $label, $required, $default, $hint);
    }

    public static function checkbox(string $id, string $label, bool $default = false, ?string $hint = null): self
    {
        return new self($id, self::CHECKBOX, $label, false, $default, $hint);
    }

    /**
     * Výběr z pevné nabídky.
     *
     * @param list<array{value: string|int, label: string}> $options
     */
    public static function select(
        string $id,
        string $label,
        array $options,
        bool $required = false,
        string|int|null $default = null,
        ?string $hint = null,
    ): self {
        if ($options === [] || !array_is_list($options)) {
            throw new \InvalidArgumentException("Record action field '{$id}': options must be a non-empty list");
        }
        $checked = [];
        $seen = [];
        foreach ($options as $idx => $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;
            $optionLabel = is_array($option) ? ($option['label'] ?? null) : null;
            if ((!is_string($value) && !is_int($value)) || !is_string($optionLabel) || trim($optionLabel) === '') {
                throw new \InvalidArgumentException(
                    "Record action field '{$id}': options[{$idx}] must be {value: string|int, label: string}",
                );
            }
            if (isset($seen[(string) $value])) {
                throw new \InvalidArgumentException("Record action field '{$id}': option '{$value}' is listed twice");
            }
            $seen[(string) $value] = true;
            $checked[] = ['value' => $value, 'label' => $optionLabel];
        }
        if ($default !== null && !isset($seen[(string) $default])) {
            throw new \InvalidArgumentException("Record action field '{$id}': default is not one of the options");
        }
        return new self($id, self::SELECT, $label, $required, $default, $hint, options: $checked);
    }

    /**
     * Jeden soubor.
     *
     * @param int $maxBytes limit velikosti, nejvýš {@see self::MAX_FILE_BYTES}
     * @param string|null $accept hodnota atributu `accept` (`.p12,.pfx`)
     */
    public static function file(
        string $id,
        string $label,
        int $maxBytes,
        ?string $accept = null,
        bool $required = false,
        ?string $hint = null,
    ): self {
        if ($maxBytes < 1 || $maxBytes > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException(
                "Record action field '{$id}': maxBytes must be between 1 and " . self::MAX_FILE_BYTES,
            );
        }
        if ($accept !== null && trim($accept) === '') {
            throw new \InvalidArgumentException("Record action field '{$id}': accept must not be empty");
        }
        return new self($id, self::FILE, $label, $required, null, $hint, accept: $accept, maxBytes: $maxBytes);
    }

    /** Text v dialogu bez hodnoty (upozornění, co akce udělá). */
    public static function notice(string $id, string $text): self
    {
        return new self($id, self::NOTICE, $text);
    }

    /** Nese pole hodnotu? `notice` ne. */
    public function hasValue(): bool
    {
        return $this->type !== self::NOTICE;
    }

    /**
     * Hodnota z nabídky pole `select` v typu, v jakém ji nabídka deklaruje;
     * `null`, když v nabídce není. Porovnává se textová podoba (z JSON
     * může přijít `"2"` i `2`).
     */
    public function optionValue(string|int $value): string|int|null
    {
        foreach ($this->options ?? [] as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['value'];
            }
        }
        return null;
    }

    /** `YYYY-MM-DD` → datum o půlnoci; cokoli jiného (i 30. únor) `null`. */
    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);
        return $date !== false && $date->format(self::DATE_FORMAT) === $value ? $date : null;
    }

    /**
     * `YYYY-MM-DDTHH:MM` (hodnota `datetime-local`, volitelně se sekundami)
     * → okamžik v místním čase serveru; cokoli jiného `null`.
     */
    public static function parseDateTime(string $value): ?\DateTimeImmutable
    {
        foreach ([self::DATETIME_FORMAT, 'Y-m-d\TH:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }

    /**
     * Tvar pro frontend: `{id, type, label, required?, default?, hint?}`
     * a klíče podle typu; `notice` je `{id, type, text}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === self::NOTICE) {
            return ['id' => $this->id, 'type' => $this->type, 'text' => $this->label];
        }

        $field = ['id' => $this->id, 'type' => $this->type, 'label' => $this->label, 'required' => $this->required];
        foreach ([
            'default' => $this->default,
            'hint' => $this->hint,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'accept' => $this->accept,
            'maxBytes' => $this->maxBytes,
        ] as $key => $value) {
            if ($value !== null) {
                $field[$key] = $value;
            }
        }
        return $field;
    }
}
