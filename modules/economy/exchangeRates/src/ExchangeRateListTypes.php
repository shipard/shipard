<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;

/**
 * Typy kurzovních lístků země zdroje dat (#121 D1): jediné čtení cfgItem
 * `world.<země>.exchangeRateLists`. Pořadí v konfiguraci = priorita
 * dohledání (U2). Chybějící cfgItem (jiná země, nezkompilovaná konfigurace)
 * = žádné typy: importer odmítne každý lístek, resolver kurz nenajde.
 * Klíč `download` (task B) se parsuje shovívavě: neplatný = typ zůstává,
 * jen se nestahuje (`downloadIssue`).
 */
final class ExchangeRateListTypes
{
    /** @param list<ExchangeRateListType> $types */
    public function __construct(private readonly array $types)
    {
    }

    public static function cfgItemId(string $country): string
    {
        return 'world.' . strtolower($country) . '.exchangeRateLists';
    }

    public static function fromConfig(?ConfigRuntime $config, string $country): self
    {
        $cfg = $config?->cfgItem(self::cfgItemId($country));
        $types = [];
        if (is_array($cfg)) {
            foreach ($cfg as $id => $entry) {
                if (!is_string($id) || $id === '' || !is_array($entry)) {
                    continue;
                }
                $home = strtolower(trim((string) ($entry['homeCurrency'] ?? '')));
                if ($home === '') {
                    continue;
                }
                $maxAge = $entry['maxAgeDays'] ?? null;
                [$download, $issue] = self::parseDownload($entry['download'] ?? null);
                $types[] = new ExchangeRateListType(
                    id: $id,
                    name: (string) ($entry['name'] ?? $id),
                    homeCurrency: $home,
                    maxAgeDays: is_int($maxAge) && $maxAge > 0 ? $maxAge : null,
                    download: $download,
                    downloadIssue: $issue,
                );
            }
        }
        return new self($types);
    }

    /** @return array{0: ?ListDownloadConfig, 1: ?string} */
    private static function parseDownload(mixed $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }
        if (!is_array($raw)) {
            return [null, 'download musí být objekt'];
        }
        try {
            return [ListDownloadConfig::fromArray($raw), null];
        } catch (\InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }
    }

    /** @return list<ExchangeRateListType> */
    public function all(): array
    {
        return $this->types;
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }

    public function get(string $id): ?ExchangeRateListType
    {
        foreach ($this->types as $type) {
            if ($type->id === $id) {
                return $type;
            }
        }
        return null;
    }

    /** Typy vyhlašující kurzy v dané měně, v pořadí priority. @return list<ExchangeRateListType> */
    public function forHomeCurrency(string $currency): array
    {
        $currency = strtolower($currency);
        return array_values(array_filter($this->types, static fn(ExchangeRateListType $t): bool => $t->homeCurrency === $currency));
    }

    /** Lokalizovaný název typu, u neznámého typu jeho id. */
    public function labelOf(string $id): string
    {
        return $this->get($id)?->name ?? $id;
    }
}
