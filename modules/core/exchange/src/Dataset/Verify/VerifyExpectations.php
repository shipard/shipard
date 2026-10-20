<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Compare\ProjectionException;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Očekávání sady z `expected/` (docs/test-corpus.md § 17): papírová
 * projekce, záznamy DPH a nákladů per zpráva (generátor korpusu), volitelný
 * schválený snímek deníku a volitelné kurzy dokladů v cizí měně
 * (`exchange-rates.json`, #103 D79 — sada před ním ho nemá). Chybějící
 * projekce, `vat.json` nebo `costs.json` = sada bez očekávání — vstupní
 * chyba, ne přeskočená kontrola.
 */
final class VerifyExpectations
{
    public const PROJECTION = 'expected/projection.jsonc';
    public const VAT = 'expected/vat.json';
    public const COSTS = 'expected/costs.json';
    public const LEDGER = 'expected/general-ledger.json';
    public const EXCHANGE_RATES = 'expected/exchange-rates.json';

    /**
     * @param list<array<string, mixed>>      $vat
     * @param list<array<string, mixed>>      $costs
     * @param array<string, mixed>|null       $ledgerSnapshot
     * @param list<array<string, mixed>>|null $exchangeRates null = sada bez souboru
     */
    public function __construct(
        public readonly Projection $projection,
        public readonly array $vat,
        public readonly array $costs,
        public readonly ?array $ledgerSnapshot,
        public readonly ?array $exchangeRates = null,
    ) {}

    public static function load(DatasetReader $reader): self
    {
        foreach ([self::PROJECTION, self::VAT, self::COSTS] as $file) {
            if (!$reader->fileExists($file)) {
                throw new DatasetException("sada nemá {$file} — očekávání pro dataset-verify staví build korpusu (python3 tests/corpus/cz/build.py)");
            }
        }
        try {
            $projection = Projection::load($reader->resolvePath(self::PROJECTION));
        } catch (ProjectionException $e) {
            throw new DatasetException(self::PROJECTION . ': ' . $e->getMessage(), 0, $e);
        }
        return new self(
            $projection,
            self::records($reader, self::VAT),
            self::records($reader, self::COSTS),
            $reader->fileExists(self::LEDGER) ? $reader->readJsonc(self::LEDGER) : null,
            $reader->fileExists(self::EXCHANGE_RATES) ? self::records($reader, self::EXCHANGE_RATES) : null,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function records(DatasetReader $reader, string $file): array
    {
        $list = $reader->readJsonc($file);
        if (!array_is_list($list)) {
            throw new DatasetException("{$file}: očekáváno pole záznamů");
        }
        foreach ($list as $i => $record) {
            if (!is_array($record) || !is_string($record['messageId'] ?? null)) {
                throw new DatasetException("{$file}: záznam {$i} bez messageId");
            }
        }
        /** @var list<array<string, mixed>> $list */
        return $list;
    }

    /** @return array<string, array<string, mixed>> messageId → záznam */
    public function vatByMessage(): array
    {
        return array_column($this->vat, null, 'messageId');
    }

    /** @return array<string, array<string, mixed>> messageId → záznam */
    public function costsByMessage(): array
    {
        return array_column($this->costs, null, 'messageId');
    }

    /** @return array<string, array<string, mixed>>|null messageId → záznam; null = sada bez exchange-rates.json */
    public function exchangeRatesByMessage(): ?array
    {
        return $this->exchangeRates === null ? null : array_column($this->exchangeRates, null, 'messageId');
    }
}
