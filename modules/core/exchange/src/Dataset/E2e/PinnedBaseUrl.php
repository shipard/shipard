<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

/**
 * Ověřená adresa serveru pro proklikání (#103 D97, {@see LocalBaseUrl}):
 * normalizovaný origin a adresa, na kterou se host připíchne — curl
 * (`CURLOPT_RESOLVE`) i Chromium (`--host-resolver-rules`) ji dostanou
 * hotovou, takže jméno nejde mezi ověřením a během přesměrovat jinam.
 * IP literál se nepřipichuje (není co přeložit): obě pravidla jsou null.
 */
final readonly class PinnedBaseUrl
{
    /**
     * @param string $origin `scheme://host[:port]` bez cesty (host malými písmeny, IPv6 v závorkách)
     * @param string $host host bez hranatých závorek
     * @param string $ip ověřená adresa (normalizovaná, bez `%scope`)
     * @param bool $isLiteral host je IP literál
     */
    public function __construct(
        public string $origin,
        public string $host,
        public int $port,
        public string $ip,
        public bool $isLiteral,
    ) {
    }

    /**
     * Cíl je https (#183 U13, U17). Vývojový server má na IP self-signed
     * certifikát, takže běh nad ověřenou a připíchnutou adresou smí chyby
     * certifikátu ignorovat — spojení jinam než na tento server jít nemůže.
     */
    public function isHttps(): bool
    {
        return str_starts_with($this->origin, 'https://');
    }

    /** Záznam pro `CURLOPT_RESOLVE` (`host:port:ip`), null pro IP literál. */
    public function curlResolve(): ?string
    {
        if ($this->isLiteral) {
            return null;
        }
        return "{$this->host}:{$this->port}:" . $this->ipLiteral();
    }

    /** Pravidlo pro Chromium `--host-resolver-rules` (`MAP host ip`), null pro IP literál. */
    public function chromiumRule(): ?string
    {
        if ($this->isLiteral) {
            return null;
        }
        return "MAP {$this->host} " . $this->ipLiteral();
    }

    private function ipLiteral(): string
    {
        return str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;
    }
}
