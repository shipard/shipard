<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download;

use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;

/**
 * Importer bez databáze pro testy stahovače: výsledek podle mapy
 * `"<listType> <validFrom>" → outcome` (výchozí created), přeskočené
 * měny per lístek, `failFirst` = kolikrát má lístek nejdřív selhat
 * (souběh na unikátním klíči).
 */
final class FakeExchangeRateListImporter extends ExchangeRateListImporter
{
    /** @var list<array{listType: string, validFrom: string, source: int}> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $outcomes = [];

    /** @var array<string, list<string>> */
    public array $skippedCurrencies = [];

    /** @var array<string, int> */
    public array $failFirst = [];

    public function __construct()
    {
    }

    public function import(array $list, int $source, bool $replace = false): ExchangeRateListImportResult
    {
        $type = is_string($list['listType'] ?? null) ? $list['listType'] : '';
        $from = is_string($list['validFrom'] ?? null) ? $list['validFrom'] : '';
        $key = trim($type . ' ' . $from);
        $this->calls[] = ['listType' => $type, 'validFrom' => $from, 'source' => $source];
        if (($this->failFirst[$key] ?? 0) > 0) {
            $this->failFirst[$key]--;
            return new ExchangeRateListImportResult(ExchangeRateListImportResult::FAILED, $type, $from, null, ['Zápis selhal: Duplicate entry']);
        }
        $outcome = $this->outcomes[$key] ?? ExchangeRateListImportResult::CREATED;
        $errors = match ($outcome) {
            ExchangeRateListImportResult::CONFLICT => ['Lístek už existuje s jiným obsahem — nahradí ho jen volba replace.'],
            ExchangeRateListImportResult::INVALID  => ['Neznámý typ lístku „' . $type . '“'],
            ExchangeRateListImportResult::FAILED   => ['Zápis selhal: neznámá chyba'],
            default                                => [],
        };
        return new ExchangeRateListImportResult($outcome, $type, $from, 1, $errors, $this->skippedCurrencies[$key] ?? []);
    }
}
