<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Jedna registrace rozšíření UI z `module.jsonc` (#172 D16–D19): druh
 * (`ModuleUiExtensions::DETAIL` / `FORM_TABS` / `ROW_DECORATORS`), modul,
 * který ji deklaruje, tabulka, třída poskytovatele a pořadí.
 */
final class ModuleUiExtension
{
    /** @param class-string $class */
    public function __construct(
        public readonly string $kind,
        public readonly string $moduleId,
        public readonly string $table,
        public readonly string $class,
        public readonly int $order,
    ) {
    }
}
