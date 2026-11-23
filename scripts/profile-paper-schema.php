#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vloží papírovou podobu schématu dokladu do profilu pošty (#166 D63).
 *
 * `output_schema` profilu `czech_general` nese ve větvi dokladu
 * (`document.…extracted_json.oneOf[0]`) projekci `PaperSchema` nad
 * `shpd.docs.document.v1.json`. Po změně schématu dokladu nebo značek
 * `x-paper` skript blok v profilu přepíše; shodu hlídá
 * `ProfileSchemaDriftTest`. Změní-li se tím schéma profilu, je potřeba
 * zvednout `prompt_version` (D65) — řekne to tentýž test.
 *
 *   php scripts/profile-paper-schema.php           přepíše blok v profilu
 *   php scripts/profile-paper-schema.php --check   jen ověří (exit 1 = liší se)
 */

use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Schema\PaperSchema;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
ModuleClassLoader::register(new ModulePathResolver([$root . '/modules']));

$profilePath = $root . '/modules/core/mail/profiles/czech_general.jsonc';
$check = in_array('--check', array_slice($argv, 1), true);

$lines = explode("\n", (string) file_get_contents($profilePath));

// Blok = první prvek `oneOf` za `"extracted_json": {`.
$start = null;
foreach ($lines as $i => $line) {
    if (trim($line) === '"extracted_json": {' && trim($lines[$i + 1] ?? '') === '"oneOf": [' && trim($lines[$i + 2] ?? '') === '{') {
        $start = $i + 2;
        break;
    }
}
if ($start === null) {
    fwrite(STDERR, "profile-paper-schema: blok extracted_json.oneOf[0] v profilu nenalezen\n");
    exit(2);
}
$indent = substr($lines[$start], 0, strlen($lines[$start]) - 1);
$end = null;
for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
    if ($lines[$i] === $indent . '},') {
        $end = $i;
        break;
    }
}
if ($end === null) {
    fwrite(STDERR, "profile-paper-schema: konec bloku extracted_json.oneOf[0] nenalezen\n");
    exit(2);
}

$json = (string) json_encode(
    PaperSchema::ofDocument(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
);
$block = array_map(static fn (string $line): string => $indent . $line, explode("\n", $json));
$block[count($block) - 1] .= ',';

if (array_slice($lines, $start, $end - $start + 1) === $block) {
    echo "profile-paper-schema: profil odpovídá papírové podobě schématu dokladu\n";
    exit(0);
}
if ($check) {
    fwrite(STDERR, "profile-paper-schema: profil se liší od papírové podoby schématu dokladu\n");
    exit(1);
}

array_splice($lines, $start, $end - $start + 1, $block);
file_put_contents($profilePath, implode("\n", $lines));
echo "profile-paper-schema: blok v profilu přepsán — spusť ProfileSchemaDriftTest (verze promptu, otisk)\n";
