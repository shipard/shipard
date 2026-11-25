<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Soubor nahraný v dialogu akce záznamu (#172 D13). `path` je **dočasná**
 * cesta — po skončení požadavku ji PHP smaže; co chce akce zachovat, musí
 * si uložit sama. `mimeType` je zjištěný z obsahu, ne z hlavičky prohlížeče;
 * `name` je původní jméno souboru od uživatele (jen pro zobrazení nebo
 * uložení jako metadata, ne jako cesta).
 */
final class RecordActionFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly int $size,
        public readonly string $mimeType,
    ) {
    }
}
