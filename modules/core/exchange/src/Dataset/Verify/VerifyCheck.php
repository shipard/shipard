<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/**
 * Jedna kontrola `dataset-verify` (tasks/testing-phase3.md). Implementace
 * žijí u modulů, kterým patří data (vzor `SectionSeeder`): věrnost apply
 * v core.exchange, položky a účty v economy.items, náklady a deník
 * v economy.accounting, DPH v economy.vat. Příkaz je skládá podle
 * aktivních tabulek. Kontrola běží nad všemi doklady sady — i z dřívějšího
 * běhu — a nikdy nemění data.
 */
interface VerifyCheck
{
    public function id(): string;

    public function label(): string;

    public function run(VerifyContext $ctx): CheckResult;
}
