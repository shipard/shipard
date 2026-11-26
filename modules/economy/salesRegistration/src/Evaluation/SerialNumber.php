<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

/**
 * Pořadové číslo tržby (`porad_cis`, #171 D19): první tržba dokladu nese
 * číslo dokladu, každá další tržba téhož dokladu (storno, rozdíl po opravě)
 * číslo dokladu s příponou `-2`, `-3`, … Tržby tak jdou dohledat k dokladu
 * a storno má vlastní pořadové číslo, jak žádá metodika.
 */
final class SerialNumber
{
    /** @param int $sequence pořadí tržby dokladu od 1 */
    public static function of(string $docNumber, int $sequence): string
    {
        $docNumber = trim($docNumber);
        return $sequence <= 1 ? $docNumber : $docNumber . '-' . $sequence;
    }
}
