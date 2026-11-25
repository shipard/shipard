#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rozcestník modulů `modules/README.md` (#170 D7).
 *
 * Přepíše blok mezi značkami MODULY:BEGIN / MODULY:END seznamem všech modulů
 * hlavního stromu po druzích (instalační, jádro po skupinách, doplňky).
 * Zdroj pravdy jsou `module.jsonc` modulů — `name:cs`, `description:cs`
 * a `kind`; PHP kvůli JSONC. Ruční text mimo blok se nemění. Logiku nese
 * `Shipard\Core\Module\ModulesIndex`, shodu hlídá i `ModulesIndexTest`.
 *
 *   php scripts/modules-index.php           přepíše blok
 *   php scripts/modules-index.php --check   jen ověří (exit 1 = liší se)
 *
 * Volá se z git hooku (pre-commit) v režimu --check.
 */

use Shipard\Core\Module\ModulesIndex;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$readmePath = $root . '/modules/README.md';
$check = in_array('--check', array_slice($argv, 1), true);

$original = @file_get_contents($readmePath);
if ($original === false) {
    fwrite(STDERR, "modules-index: modules/README.md nenalezen\n");
    exit(2);
}

try {
    $updated = ModulesIndex::apply($original, ModulesIndex::block(ModulesIndex::collect($root . '/modules')));
} catch (\Throwable $e) {
    fwrite(STDERR, 'modules-index: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($updated === $original) {
    if (!$check) {
        echo "modules/README.md je aktuální.\n";
    }
    exit(0);
}

if ($check) {
    fwrite(STDERR, "modules/README.md není aktuální — rozcestník modulů neodpovídá module.jsonc.\n");
    exit(1);
}

file_put_contents($readmePath, $updated);
echo "modules/README.md přepsán.\n";
