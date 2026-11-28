<?php

declare(strict_types=1);

/**
 * Přegenerování společných vzorů ESC/POS (#177 U33).
 *
 *   php tests/Fixtures/EscPos/regenerate.php
 *
 * Jen vědomě: vzory hlídají, že se renderer a náhled ve frontendu nerozešly.
 * Po změně šablony účtenky, sazby, ovladače nebo profilu vzory přegeneruj,
 * projdi `git diff` (náhledy `*.preview.json` jsou čitelné) a pusť oba testy:
 *
 *   vendor/bin/phpunit --filter EscPosFixturesTest
 *   cd frontend && npm test
 */

use Shipard\Tests\Fixtures\EscPos\EscPosFixtures;

require dirname(__DIR__, 2) . '/bootstrap.php';

$dir      = EscPosFixtures::directory();
$expected = [];

foreach (EscPosFixtures::build() as $name => $fixture) {
    foreach ([
        "{$name}.bin"          => $fixture['bytes'],
        "{$name}.preview.json" => EscPosFixtures::previewJson($fixture['preview']),
    ] as $file => $content) {
        $expected[] = $file;
        $path       = $dir . '/' . $file;
        $state      = !is_file($path) ? 'new' : (file_get_contents($path) === $content ? 'same' : 'changed');
        if ($state !== 'same') {
            file_put_contents($path, $content);
        }
        printf("%-8s %s\n", $state, $file);
    }
}

// Vzor, který už nikdo nevyrábí, by testy dál porovnávaly s ničím.
foreach (glob($dir . '/*.{bin,json}', GLOB_BRACE) ?: [] as $path) {
    if (!in_array(basename($path), $expected, true)) {
        unlink($path);
        printf("%-8s %s\n", 'removed', basename($path));
    }
}
