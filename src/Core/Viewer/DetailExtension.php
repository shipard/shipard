<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Module\ModuleExtensionContext;

/**
 * Taby a štítky detailu záznamu z modulu (`detailExtensions` v
 * `module.jsonc`, #172 D16) — bod rozšíření pro detail tabulky, kterou
 * modul nevlastní. Builder detailu (`TableViewer::renderDetail()`) je přidá
 * za taby a štítky vieweru.
 *
 * Třída vzniká bez argumentů konstruktoru. Záznam je ten, který dostávají
 * háčky vieweru (`loadDetailRecord()`). Registrace je per tabulka: jestli
 * se rozšíření záznamu týká (typ dokladu, stav), rozhoduje poskytovatel sám
 * a vrátí prázdný seznam.
 *
 * Výjimka i výsledek mimo kontrakt znamená zalogování a přeskočení
 * poskytovatele — detail se vykreslí bez něj (D19).
 */
interface DetailExtension
{
    /**
     * Taby `{id, label, content}` jako `TableViewer::detailTabs()`; obsah
     * jen ze sdílených typů (`DetailContent::SHARED_TYPES`). `id` stačí
     * jedinečné v rámci poskytovatele — builder ho prefixuje id modulu.
     *
     * @param array<string, mixed> $record
     * @return list<array{id: string, label: string, content: array<string, mixed>}>
     */
    public function tabs(string $table, array $record, ModuleExtensionContext $context): array;

    /**
     * Štítky hlavičky `{label, style}` jako `TableViewer::detailBadges()`.
     *
     * @param array<string, mixed> $record
     * @return list<array{label: string, style: string}>
     */
    public function badges(string $table, array $record, ModuleExtensionContext $context): array;
}
