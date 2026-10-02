<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Jeden blok `data` tisku dokladu (#90 D12). Vrací klíče, které do `data`
 * přidá — tisky dokladů se skládají z bloků, další tisk blok přidá nebo
 * vynechá bez zásahu do ostatních.
 */
interface DocPrintBlock
{
    /** @return array<string, mixed> klíč v `data` → hodnota */
    public function build(DocPrintContext $context): array;
}
