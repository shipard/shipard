<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Module\Docs\Core\Prints\Blocks\DocCashDeskBlock;

/**
 * Data tisku prodejky (`cashreg`, #90 D26): jako faktura a navíc pokladna.
 * Partner je nepovinný — bez něj je odběratel `null` (D24). Prodejka
 * převodem má platební blok s účtem a QR jako faktura; vratka jsou záporné
 * částky v datech a vlastní varianta titulku.
 */
class CashRegisterPrintBuilder extends DocPrintBuilder
{
    protected function blocks(): array
    {
        return [...parent::blocks(), new DocCashDeskBlock()];
    }
}
