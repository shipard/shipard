<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Skládá data tisku nad jedním záznamem (#90 D3). Neví nic o šabloně ani
 * o PDF — vrací pole, obálku `PrintData` doplní `PrintRunner`.
 *
 * Instancuje se bez argumentů (třída z deklarace `builder`); všechno, co
 * potřebuje, nese `PrintRequest`.
 */
interface PrintBuilder
{
    /**
     * @throws PrintBuildException Záznam nejde vytisknout (chybí data, která
     *         tisk nesmí nahradit jiným zdrojem — typicky snapshot stran).
     */
    public function build(PrintRequest $request): PrintBuildResult;
}
