<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Provisioners;

use Shipard\Core\Module\ModuleProvisionContext;
use Shipard\Core\Module\ModuleProvisioner;

/** Fixture: provisioner, který nejde vytvořit bez argumentů (#174 D28). */
class NeedsArgumentsProvisioner implements ModuleProvisioner
{
    public function __construct(private readonly string $label)
    {
    }

    public function provision(ModuleProvisionContext $context): array
    {
        return [$this->label => ['created' => 0, 'existing' => 0]];
    }
}
