<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Module\ModuleExtensionContext;

/**
 * Záložka formuláře z modulu (`formTabs` v `module.jsonc`, #172 D17) — bod
 * rozšíření pro formulář tabulky, kterou modul nevlastní. Záložka se přidá
 * na konec formuláře (PHP třída, JSONC i automatický formulář) a ukládá se
 * běžným uložením: sloupce z rozšíření tabulky (`extensions`) jsou sloupce
 * tabulky jako každé jiné.
 *
 * Třída vzniká bez argumentů konstruktoru. Registrace je per tabulka:
 * jestli se záložka záznamu týká (typ dokladu), rozhoduje poskytovatel sám
 * a vrátí `null`.
 *
 * Záložka smí nést jen sloupce tabulky a prvky bez hodnoty (oddělovač,
 * `content`); sub-tabulky, lookupy a pojmenované komponenty ne. Výjimka
 * i záložka mimo kontrakt znamená zalogování a přeskočení poskytovatele —
 * formulář se vykreslí bez ní (D19).
 */
abstract class ModuleFormTab
{
    /**
     * @param array<string, mixed> $data data formuláře (uložený záznam, nebo výchozí hodnoty nového)
     * @return FormTab|null `null` = záložka se pro tento záznam nezobrazí
     */
    abstract public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab;

    /**
     * Builder záložky nad tabulkou z kontextu — popisky sloupců (včetně
     * sloupců z rozšíření) jako u `TableForm::tab()`. `$id` stačí jedinečné
     * v rámci modulu; sběr ho prefixuje id modulu.
     */
    protected function tab(ModuleExtensionContext $context, string $id, string $label, ?string $icon = null): TabBuilder
    {
        return TabBuilder::forTable($id, $label, $context->tableDef, $icon);
    }
}
