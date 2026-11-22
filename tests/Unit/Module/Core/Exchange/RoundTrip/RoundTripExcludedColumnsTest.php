<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\RoundTrip;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripColumns;

/**
 * Seznam sloupců vyloučených z porovnání round-tripu nesmí zastarat
 * (tasks/doc-saving-safety-net.md U3): každý vyloučený sloupec musí
 * v definici tabulky existovat a nést důvod. Sloupec, který z definice
 * zmizel nebo se přejmenoval, tu jinak zůstane viset a nový sloupec téhož
 * významu se začne porovnávat bez povšimnutí.
 */
class RoundTripExcludedColumnsTest extends TestCase
{
    public function testExcludedColumnsExistInTableDefinitions(): void
    {
        $tables = DocTableDefinitions::load();

        $this->assertSame(
            RoundTripColumns::TABLES,
            array_keys(RoundTripColumns::EXCLUDED),
            'Výjimky se vedou právě pro tři tabulky dokladu.',
        );
        foreach (RoundTripColumns::EXCLUDED as $table => $columns) {
            $defined = array_map(static fn ($column): string => $column->id, $tables[$table]->columns);
            foreach ($columns as $column => $reason) {
                $this->assertContains($column, $defined, "{$table}.{$column} v definici tabulky není — výjimka zastarala.");
                $this->assertNotSame('', trim($reason), "{$table}.{$column}: výjimka musí nést důvod.");
            }
        }
    }

    public function testEveryOtherColumnIsCompared(): void
    {
        $tables = DocTableDefinitions::load();

        foreach (RoundTripColumns::TABLES as $table) {
            $compared = array_map(
                static fn ($column): string => $column->id,
                RoundTripColumns::compared($table, $tables[$table]),
            );
            $this->assertSame(
                count($tables[$table]->columns) - count(RoundTripColumns::EXCLUDED[$table]),
                count($compared),
                "{$table}: porovnává se vše mimo výjimky.",
            );
            $this->assertSame([], array_intersect($compared, array_keys(RoundTripColumns::EXCLUDED[$table])));
        }
        // Sloupce z rozšíření jiných modulů se porovnávají samy.
        $heads = array_map(
            static fn ($column): string => $column->id,
            RoundTripColumns::compared(RoundTripColumns::HEADS, $tables[RoundTripColumns::HEADS]),
        );
        $this->assertContains('accounting_state', $heads);
        $this->assertContains('vat_period', $heads);
    }
}
