<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Dataset;

use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;

/**
 * Sekce `exchangeRates/` datové sady (#121 D10, docs/datasets.md §3): každý
 * soubor = jeden lístek ve formátu shpd.exchangeRates.list.v1 přes importer
 * se zdrojem „datová sada“. `created` i `skipped` (shodný lístek už je)
 * = ok; `conflict` = failed — seed nic nepřepisuje, jiný obsah téhož
 * lístku je chyba sady nebo zdroje dat. Běží před doklady a zprávami, aby
 * applier měl z čeho doplnit kurz návrhů v cizí měně.
 */
class ExchangeRatesSeeder implements SectionSeeder
{
    public const SECTION = 'exchangeRates';

    public function __construct(private readonly ExchangeRateListImporter $importer)
    {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        foreach ($ctx->reader->listFiles(self::SECTION) as $rel) {
            try {
                $list = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }
            $result = $this->importer->import($list, ExchangeRateListImporter::SOURCE_DATASET);
            if ($result->ok()) {
                $report->ok(self::SECTION);
                continue;
            }
            $detail = $result->errors !== [] ? implode(' ', $result->errors) : $result->outcome;
            if ($result->outcome === ExchangeRateListImportResult::CONFLICT) {
                $detail = 'lístek ' . $result->label() . ' už ve zdroji dat je s jiným obsahem — seed nepřepisuje';
            }
            $report->failed(self::SECTION, "{$rel}: {$detail}");
        }
    }
}
