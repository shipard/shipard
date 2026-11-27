<?php

declare(strict_types=1);

namespace Shipard\Core\Auth;

/**
 * Politika PINu zdroje dat (#177 U7, U21) — klíč `auth.pin` v
 * `config/main.json`. PIN odemyká profil na sdíleném pracovišti
 * (docs/native-bridge.md §8.5); politiku vrací `GET /_devices/self`
 * a nativní aplikace podle ní staví dialog PINu.
 *
 * Konfigurace nikdy nevyhazuje výjimku — auth politika se čte i ve veřejném
 * `/_app/info` a rozbitý klíč nesmí shodit přihlášení. Neplatná sekce
 * znamená **celé výchozí hodnoty** (PIN vypnutý, fail-closed) se
 * `source: invalid` a problém ukáže `shpd-server doctor`.
 */
final readonly class PinPolicy
{
    public const int DEFAULT_MIN_LENGTH = 4;
    public const int DEFAULT_MAX_LENGTH = 8;
    public const int DEFAULT_MAX_ATTEMPTS = 5;

    /** Meze, které konfigurace nesmí překročit. */
    public const int MIN_LENGTH_FLOOR = 4;
    public const int MAX_LENGTH_CEILING = 8;

    /** Sekce `auth.pin` v main.json je a je platná. */
    public const string SOURCE_CONFIGURED = 'configured';
    /** Sekce chybí — výchozí hodnoty. */
    public const string SOURCE_DEFAULT = 'default';
    /** Sekce je chybná — výchozí hodnoty, PIN vypnutý. */
    public const string SOURCE_INVALID = 'invalid';

    private const array KEYS = ['enabled', 'minLength', 'maxLength', 'maxAttempts'];

    /** @param ?string $problem Co je na konfiguraci špatně (jen `source: invalid`). */
    private function __construct(
        public bool $enabled = false,
        public int $minLength = self::DEFAULT_MIN_LENGTH,
        public int $maxLength = self::DEFAULT_MAX_LENGTH,
        public int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        public string $source = self::SOURCE_DEFAULT,
        public ?string $problem = null,
    ) {
    }

    /** Výchozí politika — PIN vypnutý. */
    public static function defaults(): self
    {
        return new self();
    }

    /** @param mixed $section hodnota klíče `auth.pin` (null = klíč chybí) */
    public static function fromConfig(mixed $section): self
    {
        if ($section === null) {
            return self::defaults();
        }
        if (!is_array($section) || ($section !== [] && array_is_list($section))) {
            return self::invalid("'auth.pin' must be an object");
        }

        foreach (array_keys($section) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                return self::invalid("'auth.pin.{$key}' is not a known key");
            }
        }

        $enabled = $section['enabled'] ?? false;
        if (!is_bool($enabled)) {
            return self::invalid("'auth.pin.enabled' must be true or false");
        }

        $numbers = [];
        foreach ([
            'minLength'   => self::DEFAULT_MIN_LENGTH,
            'maxLength'   => self::DEFAULT_MAX_LENGTH,
            'maxAttempts' => self::DEFAULT_MAX_ATTEMPTS,
        ] as $key => $default) {
            $value = $section[$key] ?? $default;
            if (!is_int($value)) {
                return self::invalid("'auth.pin.{$key}' must be a whole number");
            }
            $numbers[$key] = $value;
        }

        if ($numbers['minLength'] < self::MIN_LENGTH_FLOOR) {
            return self::invalid("'auth.pin.minLength' must be at least " . self::MIN_LENGTH_FLOOR);
        }
        if ($numbers['maxLength'] > self::MAX_LENGTH_CEILING) {
            return self::invalid("'auth.pin.maxLength' must be at most " . self::MAX_LENGTH_CEILING);
        }
        if ($numbers['minLength'] > $numbers['maxLength']) {
            return self::invalid("'auth.pin.minLength' must not be greater than 'auth.pin.maxLength'");
        }
        if ($numbers['maxAttempts'] < 1) {
            return self::invalid("'auth.pin.maxAttempts' must be at least 1");
        }

        return new self(
            enabled: $enabled,
            minLength: $numbers['minLength'],
            maxLength: $numbers['maxLength'],
            maxAttempts: $numbers['maxAttempts'],
            source: self::SOURCE_CONFIGURED,
        );
    }

    public function isValid(): bool
    {
        return $this->source !== self::SOURCE_INVALID;
    }

    /**
     * Tvar pro klienta (kontrakt §7.2 `pinPolicy`).
     *
     * @return array{enabled: bool, minLength: int, maxLength: int, maxAttempts: int}
     */
    public function toArray(): array
    {
        return [
            'enabled'     => $this->enabled,
            'minLength'   => $this->minLength,
            'maxLength'   => $this->maxLength,
            'maxAttempts' => $this->maxAttempts,
        ];
    }

    private static function invalid(string $problem): self
    {
        return new self(source: self::SOURCE_INVALID, problem: $problem);
    }
}
