<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Contributor;

/**
 * Přispěvatel obsahu faktury periodické fakturace (docs/work-orders.md D7,
 * D10; #175 D5 — společný pro všechny zdroje): jiný modul doplní nebo upraví řádky faktury za období podle
 * podkladů (první případ: přefakturace spotřeby). Registrace v
 * `module.jsonc` → `periodicInvoicingContributors: [{id, class, name}]`;
 * zapíná se řádkem předpisu s `contributor = id`.
 *
 * Builder vloží řádky přispěvatele do kanonického dokladu s množstvím 0
 * a zavolá `contribute()`: *Ready* = řádky přispěvatele se nahradí
 * vrácenými kanonickými řádky, *Waiting* = koncept vznikne hned a období
 * čeká na podklady (další běhy volají znovu), *Failed* = období se
 * nevystaví a důvod jde do evidence a upozornění.
 */
interface InvoiceContributor
{
    /** Id z registrace (`periodicInvoicingContributors[].id`). */
    public function id(): string;

    public function contribute(ContributionContext $context): ContributionResult;
}
