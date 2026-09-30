<?php

declare(strict_types=1);

namespace Shipard\Module\World\Trade;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Čtení cfgItem `world.trade.unions` (`config/tradeUnions.jsonc`): členství
 * zemí v uniích k datu a záznamy daňových prefixů (prefix DIČ → země,
 * platnost, omezení na druh plnění).
 *
 * Jen čtení dat — rozhodování o místě plnění dokladu patří do
 * `Shipard\Module\Core\Exchange\Document\VatPlaceDerivation`
 * (tasks/exchange-received-vat-place.md D1/D2). Vzor VatRateResolver:
 * ConfigRuntime v konstruktoru, cfgItem načtená jednou a cachovaná.
 * Chybějící cfgItem (modul neaktivní, holý test) = žádné unie, ne výjimka.
 */
final class TradeUnionResolver
{
    /** @var array<string, array<string, mixed>>|null cache cfgItem (klíč = id unie) */
    private ?array $unions = null;

    public function __construct(
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * Klíče unií, jejichž členem je země k datu (`members[země]`,
     * `joinedAt` ≤ datum, `leftAt` null nebo ≥ datum). Země malými písmeny
     * (ISO 3166-1 alpha-2), datum `Y-m-d`.
     *
     * @return list<string>
     */
    public function unionsOf(string $country, string $date): array
    {
        $country = strtolower(trim($country));
        $out = [];
        foreach ($this->loadUnions() as $key => $union) {
            $member = $union['members'][$country] ?? null;
            if (!is_array($member)) {
                continue;
            }
            if (self::withinValidity($member['joinedAt'] ?? null, $member['leftAt'] ?? null, $date)) {
                $out[] = (string) $key;
            }
        }
        return $out;
    }

    /**
     * Záznam daňového prefixu unie (`country`, `validFrom`, `validTo`,
     * volitelně `supplyKinds`, `region`, `note`) bez vyhodnocení data;
     * null = unie prefix nezná. Prefix velkými písmeny (`CZ`, `EL`, `XI`).
     *
     * @return array<string, mixed>|null
     */
    public function taxPrefix(string $union, string $prefix): ?array
    {
        $entry = $this->loadUnions()[$union]['taxPrefixes'][strtoupper(trim($prefix))] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /**
     * Platí záznam prefixu ({@see taxPrefix()}) k datu?
     * (`validFrom` ≤ datum, `validTo` null nebo ≥ datum.)
     *
     * @param array<string, mixed> $entry
     */
    public function isPrefixValid(array $entry, string $date): bool
    {
        return self::withinValidity($entry['validFrom'] ?? null, $entry['validTo'] ?? null, $date);
    }

    /**
     * Interval s otevřenými konci: null = bez omezení. Porovnání
     * řetězců `Y-m-d` (stejně jako VatRateResolver::resolveVatPct).
     */
    private static function withinValidity(mixed $from, mixed $to, string $date): bool
    {
        if (is_string($from) && $from !== '' && $date < $from) {
            return false;
        }
        if (is_string($to) && $to !== '' && $date > $to) {
            return false;
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadUnions(): array
    {
        if ($this->unions !== null) {
            return $this->unions;
        }
        $data = $this->config->cfgItem('world.trade.unions');
        $this->unions = [];
        if (is_array($data)) {
            foreach ($data as $key => $union) {
                if (is_array($union)) {
                    $this->unions[(string) $key] = $union;
                }
            }
        }
        return $this->unions;
    }
}
