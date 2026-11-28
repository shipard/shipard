<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Shipard\Module\Economy\PeriodicInvoicing\Contributor\AbstractInvoiceContributor;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\ContributionContext;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\ContributionResult;

/** Přispěvatel s pevným výsledkem; zachytí kontexty volání. */
class FakeContributor extends AbstractInvoiceContributor
{
    /** @var list<ContributionContext> */
    public array $contexts = [];

    public function __construct(public ContributionResult $result)
    {
    }

    public function id(): string
    {
        return 'energy.consumption';
    }

    public function contribute(ContributionContext $context): ContributionResult
    {
        $this->contexts[] = $context;
        return $this->result;
    }
}
