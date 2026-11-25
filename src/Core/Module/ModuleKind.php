<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Druh modulu (#170 D1, D4).
 *
 *  - `Install` — instalační modul (skupina `install`), určuje složení zdroje dat;
 *  - `Core`    — jádro: přichází s instalačním modulem, samo se nezapíná;
 *  - `Addon`   — doplněk (`"kind": "addon"` v `module.jsonc`): není součástí
 *                `install.base` a ve zdroji dat se zapíná (`shpd-ds ds-module`).
 *
 * Druh říká jen deklarace modulu — kde modul leží (hlavní strom × další
 * kořen z `extraModulesPath`), je jiná osa ({@see ModulePathResolver::isInMainRoot()}).
 * Pravidla mezi druhy hlídá {@see ModuleKindRules}.
 */
enum ModuleKind: string
{
    case Core = 'core';
    case Addon = 'addon';
    case Install = 'install';
}
