<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

/**
 * Obsah přiznání k DPH přečtený z původního XML (#55 D33): podané hodnoty
 * řádků ve tvaru `FilingSnapshotLoader::filedRows()`, aby se daly rovnou
 * porovnat a zapsat do `*_filed` sloupců snapshotu.
 */
final readonly class Dp3XmlData
{
    /**
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $rows
     *        řádek → hodnoty; sloty mimo mapování řádku jsou nula
     * @param array<int, list<string>> $slots řádek → sloty, které formulář na
     *        řádku má (`base` / `full` / `reduced`) — jen ty se porovnávají
     * @param list<array{veta: string, attribute: string, value: string}> $unmapped
     *        nenulové atributy hodnotových vět, které mapování nezná
     * @param array<string, string> $vetaD atributy věty D
     * @param array<string, string> $vetaP atributy věty P
     * @param ?string $forma     kód formy podání (`dapdph_forma`)
     * @param ?string $dateFound `d_zjist` jako ISO datum
     */
    public function __construct(
        public array $rows,
        public array $slots,
        public array $unmapped,
        public array $vetaD,
        public array $vetaP,
        public ?string $forma,
        public ?string $dateFound,
    ) {}
}
