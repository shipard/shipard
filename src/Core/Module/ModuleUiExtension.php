<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Jedna registrace rozšíření UI z `module.jsonc` (#172 D16–D19): druh
 * (`ModuleUiExtensions::DETAIL` / `FORM_TABS` / `ROW_DECORATORS` /
 * `RECORD_ACTIONS`), modul, který ji deklaruje, tabulka, třída
 * poskytovatele a pořadí. Akce záznamu (`recordActions`, #172 D12) nese
 * navíc `id` akce a příznak `admin`; ostatní druhy je nemají.
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
        public readonly ?string $id = null,
        public readonly bool $admin = false,
    ) {
    }

    /**
     * Globální id akce záznamu `<id modulu>.<id akce>` — jde do detailu
     * (`detail.actions[].id`) i do URL endpointu.
     */
    public function globalId(): string
    {
        if ($this->id === null) {
            throw new \LogicException("Extension of kind '{$this->kind}' has no action id");
        }
        return $this->moduleId . '.' . $this->id;
    }
}
