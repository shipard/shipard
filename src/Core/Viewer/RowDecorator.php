<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Module\ModuleExtensionContext;

/**
 * Štítky řádku seznamu z modulu (`rowDecorators` v `module.jsonc`,
 * #172 D18) — bod rozšíření pro seznam tabulky, kterou modul nevlastní.
 * Spany se přidají na konec pole `t2` řádku (jen layout `list`).
 *
 * Třída vzniká bez argumentů konstruktoru a volá se **jednou na stránku
 * řádků** — co potřebuje z databáze, načte jedním dotazem pro všechna id
 * stránky, ne dotazem na řádek.
 *
 * Výjimka i výsledek mimo kontrakt znamená zalogování a přeskočení
 * poskytovatele — seznam se vykreslí bez něj (D19).
 */
interface RowDecorator
{
    /**
     * @param list<array<string, mixed>> $rawRows řádky stránky, jak je vrátil `selectRows()` vieweru
     * @return array<int, list<array{text: string, class?: string, badge?: string}>>
     *         id řádku → spany; řádek bez štítku se neuvádí
     */
    public function decorate(string $table, array $rawRows, ModuleExtensionContext $context): array;
}
