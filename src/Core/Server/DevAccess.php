<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Net\LocalAddresses;

/**
 * Vstupy vývojového serveru (#183 D3, U2) — jediný výklad sekce `devAccess`
 * v `server.json`:
 *
 *     "devAccess": { "httpsIp": true, "host": "shpd.example.com",
 *                    "preferred": "https-host", "ip": "10.0.0.7", "ipv6": false }
 *
 * - `httpsIp` — https na IP (self-signed certifikát vyrábí `dev-https`);
 *   chybějící sekce = `false`, stávající servery se bez zásahu nemění;
 * - `host` — volitelné jméno pro `https://<jméno>/`;
 * - `preferred` — `http-ip` | `https-ip` | `https-host`; výchozí je nejvyšší
 *   zapnutý vstup;
 * - `ip` — volitelný přepis adresy pro odkazy (server s víc rozhraními);
 *   jinak první dosažitelná adresa rozhraní ({@see LocalAddresses::reachable()});
 * - `ipv6` — https i na IPv6 (#183 D8); výchozí `false`: https bloky
 *   poslouchají jen na IPv4 a adresy serveru ({@see addresses()}) jsou jen
 *   IPv4. Vývojářský dashboard nemá přihlášení a globální IPv6 adresa bývá
 *   dosažitelná z internetu — proto jen na výslovné vyžádání.
 *
 * Třída **nikdy nehází**: chybná hodnota se nepoužije a skončí
 * v {@see $problems} (hlásí `doctor`). V režimu `production` se sekce
 * nepoužije — žádné vstupy, {@see isIgnoredInProduction()}.
 *
 * `server.json` nese do #183 D7 tajemství, proto ven (API dashboardu) smí
 * jen {@see publicEntries()} — nikdy surová konfigurace.
 */
final class DevAccess
{
    public const string HTTP_IP = 'http-ip';
    public const string HTTPS_IP = 'https-ip';
    public const string HTTPS_HOST = 'https-host';

    /** Od nejvyššího — pořadí výchozího preferovaného vstupu. */
    private const array KINDS_BY_PREFERENCE = [self::HTTPS_HOST, self::HTTPS_IP, self::HTTP_IP];

    /**
     * @param list<string> $addresses adresy serveru pro vstupy (kanonické, IPv4 první; IPv6 jen s `ipv6`)
     * @param list<string> $problems věty o chybných hodnotách sekce
     */
    private function __construct(
        public readonly bool $development,
        public readonly bool $configured,
        public readonly bool $httpsIp,
        public readonly bool $ipv6,
        public readonly ?string $host,
        public readonly ?string $ipOverride,
        private readonly ?string $preferredConfigured,
        private readonly array $addresses,
        public readonly array $problems,
    ) {
    }

    public static function fromServerConfig(ServerConfig $config, ?LocalAddresses $local = null): self
    {
        return self::fromSection($config->getDevAccess(), $config->getMode(), $local);
    }

    /**
     * @param mixed $section surová hodnota `devAccess` (null = sekce chybí)
     */
    public static function fromSection(mixed $section, string $mode, ?LocalAddresses $local = null): self
    {
        $development = $mode === 'development';
        $problems = [];
        $httpsIp = false;
        $ipv6 = false;
        $host = null;
        $ip = null;
        $preferred = null;

        if ($section !== null && (!is_array($section) || ($section !== [] && array_is_list($section)))) {
            $problems[] = "'devAccess' must be an object";
            $section = [];
        }
        $configured = $section !== null;
        $section ??= [];

        if (array_key_exists('httpsIp', $section)) {
            if (is_bool($section['httpsIp'])) {
                $httpsIp = $section['httpsIp'];
            } else {
                $problems[] = "'devAccess.httpsIp' must be true or false";
            }
        }

        if (array_key_exists('ipv6', $section)) {
            if (is_bool($section['ipv6'])) {
                $ipv6 = $section['ipv6'];
            } else {
                $problems[] = "'devAccess.ipv6' must be true or false";
            }
        }

        if (isset($section['host'])) {
            $candidate = is_string($section['host']) ? strtolower(trim($section['host'])) : null;
            if ($candidate !== null && self::isHostName($candidate)) {
                $host = $candidate;
            } elseif ($candidate !== '') {
                $problems[] = "'devAccess.host' must be a host name (letters, digits, hyphens and dots; not an IP address)";
            }
        }

        if (isset($section['ip'])) {
            $candidate = is_string($section['ip']) ? LocalAddresses::normalizeIp($section['ip']) : null;
            if ($candidate === null) {
                $problems[] = "'devAccess.ip' must be an IPv4 or IPv6 address";
            } elseif (!$ipv6 && !LocalAddresses::isIpv4($candidate)) {
                // Bez `ipv6` Shipard na IPv6 neposlouchá — odkaz by nikam nevedl (U25).
                $problems[] = "'devAccess.ip' is an IPv6 address, but 'devAccess.ipv6' is not true";
            } else {
                $ip = $candidate;
            }
        }

        if (isset($section['preferred'])) {
            if (is_string($section['preferred']) && in_array($section['preferred'], self::KINDS_BY_PREFERENCE, true)) {
                $preferred = $section['preferred'];
            } else {
                $problems[] = "'devAccess.preferred' must be one of: http-ip, https-ip, https-host";
            }
        }

        $addresses = [];
        if ($development) {
            $addresses = ($local ?? new LocalAddresses())->reachable();
            if (!$ipv6) {
                $addresses = array_values(array_filter($addresses, LocalAddresses::isIpv4(...)));
            }
            if ($ip !== null && !in_array($ip, $addresses, true)) {
                // Přepis může být adresa, kterou rozhraní nenese (NAT) — do
                // certifikátu patří, odkazy na ni vedou.
                array_unshift($addresses, $ip);
            }
        }

        $self = new self($development, $configured, $httpsIp, $ipv6, $host, $ip, $preferred, $addresses, $problems);
        if ($preferred !== null && $development && !in_array($preferred, $self->enabledKinds(), true)) {
            $problems[] = "'devAccess.preferred' is '{$preferred}', but that entry is not enabled";
            $self = new self($development, $configured, $httpsIp, $ipv6, $host, $ip, null, $addresses, $problems);
        }
        return $self;
    }

    /** Sekce je v `server.json`, ale server běží v produkci — nepoužije se. */
    public function isIgnoredInProduction(): bool
    {
        return $this->configured && !$this->development;
    }

    /** https na IP je zapnuté (jen ve vývoji). */
    public function wantsHttpsIp(): bool
    {
        return $this->development && $this->httpsIp;
    }

    /** https má poslouchat i na IPv6 (jen ve vývoji, #183 D8). */
    public function wantsIpv6(): bool
    {
        return $this->development && $this->ipv6;
    }

    /** Jméno pro https vstup (jen ve vývoji), jinak null. */
    public function httpsHost(): ?string
    {
        return $this->development ? $this->host : null;
    }

    public function hasHttpsEntry(): bool
    {
        return $this->wantsHttpsIp() || $this->httpsHost() !== null;
    }

    /**
     * Adresy, které má nést certifikát pro IP: dosažitelné adresy rozhraní
     * a přepis `ip` — bez `ipv6` jen IPv4 (#183 U25).
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->addresses;
    }

    /** Adresa pro odkazy: přepis `ip`, jinak první dosažitelná (IPv4 má přednost). */
    public function linkAddress(): ?string
    {
        return $this->ipOverride ?? ($this->addresses[0] ?? null);
    }

    /**
     * Zapnuté druhy vstupu v pořadí http-ip, https-ip, https-host.
     *
     * @return list<string>
     */
    public function enabledKinds(): array
    {
        if (!$this->development) {
            return [];
        }
        $kinds = [];
        if ($this->linkAddress() !== null) {
            $kinds[] = self::HTTP_IP;
            if ($this->httpsIp) {
                $kinds[] = self::HTTPS_IP;
            }
        }
        if ($this->host !== null) {
            $kinds[] = self::HTTPS_HOST;
        }
        return $kinds;
    }

    /** Origin vstupu (`scheme://host`, bez cesty), null = vstup není zapnutý. */
    public function origin(string $kind): ?string
    {
        if (!in_array($kind, $this->enabledKinds(), true)) {
            return null;
        }
        if ($kind === self::HTTPS_HOST) {
            return 'https://' . $this->host;
        }
        $address = LocalAddresses::originHost((string) $this->linkAddress());
        return ($kind === self::HTTPS_IP ? 'https://' : 'http://') . $address;
    }

    /** Preferovaný vstup: nastavený, jinak nejvyšší zapnutý; null = žádný vstup. */
    public function preferredKind(): ?string
    {
        $enabled = $this->enabledKinds();
        if ($this->preferredConfigured !== null && in_array($this->preferredConfigured, $enabled, true)) {
            return $this->preferredConfigured;
        }
        foreach (self::KINDS_BY_PREFERENCE as $kind) {
            if (in_array($kind, $enabled, true)) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * Origin preferovaného vstupu — adresa serveru tam, kde není požadavek
     * (CLI, cron), a cíl odkazu „přejdi na https“ v dashboardu.
     */
    public function preferredOrigin(): ?string
    {
        $kind = $this->preferredKind();
        return $kind === null ? null : $this->origin($kind);
    }

    /**
     * Allowlist pro API dashboardu: `{kind, origin, preferred, current}` za
     * každý zapnutý vstup; `current` podle originu požadavku (schéma + host,
     * bez ohledu na velikost písmen). Nic jiného ze `server.json` ven nesmí.
     *
     * @return list<array{kind: string, origin: string, preferred: bool, current: bool}>
     */
    public function publicEntries(?string $currentOrigin = null): array
    {
        $current = $currentOrigin === null ? null : strtolower(rtrim($currentOrigin, '/'));
        $preferred = $this->preferredKind();
        $out = [];
        foreach ($this->enabledKinds() as $kind) {
            $origin = (string) $this->origin($kind);
            $out[] = [
                'kind' => $kind,
                'origin' => $origin,
                'preferred' => $kind === $preferred,
                'current' => $current !== null && $current === strtolower($origin),
            ];
        }
        return $out;
    }

    /**
     * Jméno hostu podle RFC 1123 (štítky z písmen, číslic a pomlček, nejvýš
     * 253 znaků), ne IP adresa. Hodnota jde do konfigurace nginx
     * (`server_name`), proto přísně — žádné mezery, středníky ani zástupné znaky.
     */
    public static function isHostName(string $value): bool
    {
        if ($value === '' || strlen($value) > 253 || LocalAddresses::normalizeIp($value) !== null) {
            return false;
        }
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $value) === 1
            && preg_match('/^[0-9.]+$/', $value) !== 1;
    }
}
