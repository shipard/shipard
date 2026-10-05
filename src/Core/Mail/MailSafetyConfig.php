<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Config\ServerConfig;

/**
 * Pojistka odchozí pošty (#95) — klíč `mail.safety` v
 * `/etc/shipard/server.json`, vedle `mail.relay`. Na dev a testovacích
 * serverech brání tomu, aby pošta z kopie ostrých dat došla skutečným
 * příjemcům. Nastavuje se jen na úrovni serveru (D1) — zdroj dat ji
 * přepsat nemůže.
 *
 * Režimy: `off` (pošta odchází beze změny), `redirect` (všechno na
 * `redirectTo`), `allowlist` (povolené adresy projdou, ostatní na
 * `redirectTo` nebo pryč), `drop` (nic neodejde).
 *
 * Konfigurace nikdy nevyhazuje výjimku: chybná nebo nečitelná znamená
 * `drop` se `source: invalid` (fail-closed, D2) a problém ukáže
 * `shpd-server doctor`.
 */
final readonly class MailSafetyConfig
{
    public const MODE_OFF       = 'off';
    public const MODE_REDIRECT  = 'redirect';
    public const MODE_ALLOWLIST = 'allowlist';
    public const MODE_DROP      = 'drop';

    public const MODES = [self::MODE_REDIRECT, self::MODE_ALLOWLIST, self::MODE_DROP, self::MODE_OFF];

    /** Režim je ze sekce `mail.safety`. */
    public const SOURCE_CONFIGURED = 'configured';
    /** Sekce chybí — režim podle režimu serveru. */
    public const SOURCE_DEFAULT = 'default';
    /** Sekce je chybná nebo `server.json` nejde načíst — `drop`. */
    public const SOURCE_INVALID = 'invalid';

    /**
     * @param list<string> $allow Povolené adresy a domény (`@doména`), malými písmeny.
     * @param ?string $problem Co je na konfiguraci špatně (jen `source: invalid`).
     */
    private function __construct(
        public string $mode,
        public ?string $redirectTo = null,
        public array $allow = [],
        public string $source = self::SOURCE_CONFIGURED,
        public ?string $problem = null,
    ) {
    }

    /** Vypnutá pojistka — pro testy a volající bez serveru. */
    public static function off(): self
    {
        return new self(self::MODE_OFF);
    }

    /** Konfiguraci nejde použít — nic neodejde. */
    public static function failClosed(string $problem): self
    {
        return new self(self::MODE_DROP, source: self::SOURCE_INVALID, problem: $problem);
    }

    /**
     * Pojistka serveru; `null` = načte `server.json` sama. Chyba načtení
     * je `drop` — na rozdíl od relay, kde se chyba načtení polyká.
     */
    public static function forServer(?ServerConfig $serverConfig = null): self
    {
        try {
            if ($serverConfig === null) {
                $serverConfig = new ServerConfig();
                $serverConfig->load();
            }
            return $serverConfig->getMailSafety();
        } catch (\Throwable $e) {
            return self::failClosed('server.json cannot be read: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $serverData Dekódovaný `server.json`
     *        (čte se `mode` a `mail.safety`).
     */
    public static function fromServerData(array $serverData): self
    {
        $mail = $serverData['mail'] ?? null;
        if ($mail !== null && !is_array($mail)) {
            return self::failClosed("'mail' must be an object");
        }

        $section = $mail['safety'] ?? null;
        if ($section === null) {
            // Jen server výslovně označený jako produkční posílá bez pojistky.
            return ($serverData['mode'] ?? null) === 'production'
                ? new self(self::MODE_OFF, source: self::SOURCE_DEFAULT)
                : new self(self::MODE_DROP, source: self::SOURCE_DEFAULT);
        }
        if (!is_array($section)) {
            return self::failClosed("'mail.safety' must be an object");
        }

        $mode = $section['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            return self::failClosed("'mail.safety.mode' must be one of: " . implode(', ', self::MODES));
        }

        $redirectTo = $section['redirectTo'] ?? null;
        if ($redirectTo !== null) {
            if (!is_string($redirectTo) || !AddressList::isValid(trim($redirectTo))) {
                return self::failClosed("'mail.safety.redirectTo' is not a valid e-mail address");
            }
            $redirectTo = trim($redirectTo);
        }

        $allow = [];
        $rawAllow = $section['allow'] ?? [];
        if (!is_array($rawAllow) || !array_is_list($rawAllow)) {
            return self::failClosed("'mail.safety.allow' must be a list of addresses and @domains");
        }
        foreach ($rawAllow as $entry) {
            $normalized = is_string($entry) ? mb_strtolower(trim($entry)) : '';
            if (!self::isAllowEntry($normalized)) {
                return self::failClosed(sprintf(
                    "'mail.safety.allow' entry '%s' is neither an e-mail address nor an @domain",
                    is_scalar($entry) ? (string) $entry : gettype($entry),
                ));
            }
            $allow[] = $normalized;
        }

        if ($mode === self::MODE_REDIRECT && $redirectTo === null) {
            return self::failClosed("'mail.safety.redirectTo' is required for mode 'redirect'");
        }
        if ($mode === self::MODE_ALLOWLIST && $allow === []) {
            return self::failClosed("'mail.safety.allow' is required for mode 'allowlist'");
        }

        return new self($mode, $redirectTo, array_values(array_unique($allow)));
    }

    /** Pojistka do odchozí pošty zasahuje. */
    public function isActive(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /** Adresa je mezi povolenými — přesně, nebo doménou; bez ohledu na velikost písmen. */
    public function allows(string $address): bool
    {
        $address = mb_strtolower(trim($address));
        if (in_array($address, $this->allow, true)) {
            return true;
        }
        $at = strrpos($address, '@');
        return $at !== false && in_array(substr($address, $at), $this->allow, true);
    }

    /** Celá adresa, nebo `@doména`. */
    private static function isAllowEntry(string $entry): bool
    {
        if ($entry === '') {
            return false;
        }
        return str_starts_with($entry, '@')
            ? AddressList::isValid('x' . $entry)
            : AddressList::isValid($entry);
    }
}
