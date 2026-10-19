<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;

/**
 * Kurz k datu (#121 D4, D5, U1, U2; docs/exchange-rates.md §4):
 *
 *  1. měna = domácí měna zdroje dat → kurz 1 bez lístku;
 *  2. typy z `world.<země>.exchangeRateLists` s domácí měnou zdroje dat,
 *     v pořadí konfigurace;
 *  3. v typu POSLEDNÍ lístek s valid_from ≤ D bez ohledu na měnu — chybí-li
 *     v něm měna, jde se na další typ, nikdy na starší lístek téhož typu;
 *  4. lístek s valid_to platí jen do něj, lístek bez něj nejvýš maxAgeDays
 *     typu; starý / propadlý lístek = v tomto typu nenalezeno, další typ;
 *  5. žádný kalendář: dopoledne před vyhlášením platí včerejší lístek.
 *
 * Memoizace per instance (měna, datum) — formulář i applier se ptají
 * opakovaně. DB přístup jde přes dvě chráněné metody, aby testy mohly
 * podstrčit lístky v paměti.
 */
class ExchangeRateResolver
{
    public const string SETTING_HOME_CURRENCY = 'economy.homeCurrency';
    public const string TABLE_LISTS = 'economy_exchange_rates_lists';
    public const string TABLE_VALUES = 'economy_exchange_rates_values';

    /** @var array<string, ExchangeRateLookup> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ExchangeRateListTypes $types,
        private readonly string $homeCurrency,
    ) {
    }

    /** Produkční wiring: domácí měna z nastavení, typy podle země zdroje dat. */
    public static function create(Connection $db, ?ConfigRuntime $config, DataSourceConfig $dsConfig): self
    {
        return new self(
            $db,
            ExchangeRateListTypes::fromConfig($config, $dsConfig->getCountry()),
            self::homeCurrencyOf(new SettingsStore(new DataSourceConnection($db))),
        );
    }

    public static function homeCurrencyOf(SettingsStore $settings): string
    {
        $value = $settings->get(self::SETTING_HOME_CURRENCY);
        return is_string($value) && $value !== '' ? strtolower($value) : 'czk';
    }

    public function homeCurrency(): string
    {
        return $this->homeCurrency;
    }

    public function types(): ExchangeRateListTypes
    {
        return $this->types;
    }

    public function rateFor(string $currency, string|\DateTimeInterface $date): ?ExchangeRate
    {
        return $this->lookup($currency, $date)->rate;
    }

    public function lookup(string $currency, string|\DateTimeInterface $date): ExchangeRateLookup
    {
        $currency = strtolower(trim($currency));
        $raw = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : trim($date);
        $key = $currency . '|' . $raw;
        return $this->cache[$key] ??= $this->resolve($currency, self::normalizeDate($raw), $raw);
    }

    private function resolve(string $currency, ?string $date, string $raw): ExchangeRateLookup
    {
        if ($date === null) {
            return new ExchangeRateLookup($currency, $raw, null, [self::reason(null, ExchangeRateLookup::REASON_INVALID_DATE)]);
        }
        if ($currency === $this->homeCurrency) {
            return new ExchangeRateLookup($currency, $date, ExchangeRate::home());
        }
        $types = $this->types->forHomeCurrency($this->homeCurrency);
        if ($types === []) {
            return new ExchangeRateLookup($currency, $date, null, [self::reason(null, ExchangeRateLookup::REASON_NO_TYPES)]);
        }

        $reasons = [];
        foreach ($types as $type) {
            $list = $this->latestList($type->id, $date);
            if ($list === null) {
                $reasons[] = self::reason($type->id, ExchangeRateLookup::REASON_NO_LIST);
                continue;
            }
            $validity = $this->validity($type, $list, $date);
            if ($validity !== null) {
                $reasons[] = self::reason($type->id, $validity);
                continue;
            }
            $value = $this->listValue((int) $list['id'], $currency);
            if ($value === null) {
                $reasons[] = self::reason($type->id, ExchangeRateLookup::REASON_CURRENCY_MISSING);
                continue;
            }
            $rate = new ExchangeRate(
                rate: ExchangeRate::perUnit($value['rate'], (int) $value['amount']),
                listType: $type->id,
                validFrom: (string) $list['valid_from'],
                listNumber: $list['list_number'] !== null ? (string) $list['list_number'] : null,
                listId: (int) $list['id'],
            );
            return new ExchangeRateLookup($currency, $date, $rate, $reasons);
        }
        return new ExchangeRateLookup($currency, $date, null, $reasons);
    }

    /** null = lístek platí; jinak důvod (U1). */
    private function validity(ExchangeRateListType $type, array $list, string $date): ?string
    {
        $validTo = $list['valid_to'] ?? null;
        if ($validTo !== null && $validTo !== '') {
            return $date <= (string) $validTo ? null : ExchangeRateLookup::REASON_LIST_EXPIRED;
        }
        if ($type->maxAgeDays === null) {
            return ExchangeRateLookup::REASON_LIST_STALE;
        }
        $from = new \DateTimeImmutable((string) $list['valid_from']);
        $age = (int) $from->diff(new \DateTimeImmutable($date))->days;
        return $age <= $type->maxAgeDays ? null : ExchangeRateLookup::REASON_LIST_STALE;
    }

    /**
     * Poslední lístek typu s valid_from ≤ datum (bez ohledu na měnu).
     *
     * @return array{id: int, valid_from: string, valid_to: ?string, list_number: ?string}|null
     */
    protected function latestList(string $listType, string $date): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [valid_from], [valid_to], [list_number] FROM %n'
            . ' WHERE [list_type] = %s AND [valid_from] <= %d'
            . ' ORDER BY [valid_from] DESC, [id] DESC LIMIT 1',
            self::TABLE_LISTS,
            $listType,
            new \DateTimeImmutable($date),
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'valid_from'  => (string) self::dateString($row['valid_from']),
            'valid_to'    => self::dateString($row['valid_to']),
            'list_number' => $row['list_number'] !== null && $row['list_number'] !== '' ? (string) $row['list_number'] : null,
        ];
    }

    /**
     * Hodnota měny v lístku.
     *
     * @return array{amount: int, rate: float}|null
     */
    protected function listValue(int $listId, string $currency): ?array
    {
        $row = $this->db->fetch(
            'SELECT [amount], [rate] FROM %n WHERE [list] = %i AND [currency] = %s',
            self::TABLE_VALUES,
            $listId,
            $currency,
        );
        if ($row === null) {
            return null;
        }
        return ['amount' => (int) $row['amount'], 'rate' => (float) $row['rate']];
    }

    /** @return array{listType: ?string, reason: string} */
    private static function reason(?string $listType, string $reason): array
    {
        return ['listType' => $listType, 'reason' => $reason];
    }

    public static function normalizeDate(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    public static function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }
        return null;
    }
}
