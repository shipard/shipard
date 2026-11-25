<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Module\ModuleUiExtensions;

/**
 * Záložky formuláře z modulů (`formTabs`, #172 D17) — sběr pro
 * `FormController`. Poskytovatele volá v pořadí registru a jeho záložku
 * **kontroluje**:
 *
 *  - záložka je typu `fields` (žádná sub-tabulka ani přílohy);
 *  - prvek s hodnotou (`input`, `select`, `multiselect`, i uvnitř `inline`)
 *    nese sloupec tabulky, který propustí běžné uložení formuláře — ne
 *    systémový, ne primární klíč, ne citlivý (`sensitive`);
 *  - prvky bez hodnoty jsou jen oddělovač a `content`; `lookup`
 *    a pojmenovaná `component` v 1. fázi nejsou povolené;
 *  - id záložky se prefixuje id modulu (`<modul>.<id>`).
 *
 * `null` z poskytovatele = záložka se pro záznam nezobrazí (není to chyba).
 * Porušení kontraktu je selhání poskytovatele jako výjimka: zaloguje se
 * a formulář se vykreslí bez záložky — viz `ModuleUiExtensions::call()`.
 */
final class ModuleFormTabs
{
    /** Prvky, které nesou hodnotu sloupce tabulky. */
    private const VALUE_TYPES = ['input', 'select', 'multiselect'];

    /** Sloupce, které uložení formuláře nikdy nepřijme (`FormController::filterWritableFields`). */
    private const NEVER_WRITABLE = ['id', 'created', 'modified'];

    /** Prvky bez hodnoty. */
    private const STATIC_TYPES = ['separator', 'content'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data data formuláře
     * @return list<FormTab>
     */
    public static function collect(
        ModuleUiExtensions $extensions,
        ModuleExtensionContext $context,
        array $data,
        bool $isNew,
    ): array {
        $tabs = [];
        foreach ($extensions->for(ModuleUiExtensions::FORM_TABS, $context->table) as $extension) {
            $tab = $extensions->call(
                $extension,
                static function (ModuleFormTab $provider) use ($extension, $context, $data, $isNew): ?FormTab {
                    $tab = $provider->build($data, $isNew, $context);
                    if ($tab === null) {
                        return null;
                    }
                    self::check($tab, $context);
                    return $tab->withId($extension->moduleId . '.' . $tab->id);
                },
            );
            if ($tab !== null) {
                $tabs[] = $tab;
            }
        }
        return $tabs;
    }

    private static function check(FormTab $tab, ModuleExtensionContext $context): void
    {
        if ($tab->type !== 'fields') {
            throw new \UnexpectedValueException("Tab '{$tab->id}' has type '{$tab->type}'; a module form tab must be of type 'fields'");
        }

        $writable = [];
        foreach ($context->tableDef?->columns ?? [] as $column) {
            if (!$column->system && !$column->primaryKey && !$column->sensitive
                && !in_array($column->id, self::NEVER_WRITABLE, true)
            ) {
                $writable[$column->id] = true;
            }
        }

        foreach ($tab->sections as $section) {
            foreach ($section->columns as $column) {
                foreach ($column->elements as $element) {
                    self::checkElement($tab, $element, $writable, $context->table);
                }
            }
        }
    }

    /** @param array<string, true> $writable */
    private static function checkElement(FormTab $tab, FormElement $element, array $writable, string $table): void
    {
        if ($element->type === 'inline') {
            foreach ($element->elements ?? [] as $inner) {
                self::checkElement($tab, $inner, $writable, $table);
            }
            return;
        }
        if (in_array($element->type, self::STATIC_TYPES, true)) {
            return;
        }
        if (!in_array($element->type, self::VALUE_TYPES, true)) {
            throw new \UnexpectedValueException(
                "Tab '{$tab->id}' contains element '{$element->type}'; a module form tab may hold only "
                . implode(', ', [...self::VALUE_TYPES, 'inline', ...self::STATIC_TYPES]),
            );
        }

        $column = $element->column;
        if ($column === null || !isset($writable[$column])) {
            throw new \UnexpectedValueException(
                "Tab '{$tab->id}' edits '" . ($column ?? '') . "', which is not a writable column of '{$table}'",
            );
        }
    }
}
