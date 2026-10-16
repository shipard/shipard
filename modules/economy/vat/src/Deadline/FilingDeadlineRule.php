<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Deadline;

/**
 * Pravidlo termínu podání přiznání k DPH podle země (#120 D7, D17).
 * Vstup i výstup ISO `YYYY-MM-DD`; termín už posunutý na pracovní den.
 */
interface FilingDeadlineRule
{
    public function returnDeadline(string $periodEnd): string;
}
