<?php

declare(strict_types=1);

namespace Shipard\Api;

/**
 * Soubory z `multipart/form-data` pole ve tvaru `name[]` (`$_FILES`), jak je
 * posílá runner pošty i import Odeslané pošty: v pořadí z požadavku, jen
 * ty, které se nahrály bez chyby. Název souboru z multipartu je název
 * přílohy.
 */
final class MultipartFiles
{
    /**
     * @param array<string, mixed>|null $files `$_FILES` (testy); null = superglobál.
     * @return list<array{name: string, tmp_name: string}>
     */
    public static function collect(string $field, ?array $files = null): array
    {
        $files ??= $_FILES;
        $entry = $files[$field] ?? null;
        if (!is_array($entry) || !isset($entry['name']) || !is_array($entry['name'])) {
            return [];
        }

        $out = [];
        foreach ($entry['name'] as $i => $name) {
            if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'tmp_name' => (string) ($entry['tmp_name'][$i] ?? ''),
            ];
        }
        return $out;
    }
}
