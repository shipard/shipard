<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Provisioners;

use Shipard\Core\Module\ModuleProvisionContext;
use Shipard\Core\Module\ModuleProvisioner;

/** Fixture: druhý provisioner modulu s výchozím `order` — běží až po prvním (#174 D28). */
class FixtureLateProvisioner implements ModuleProvisioner
{
    public function provision(ModuleProvisionContext $context): array
    {
        FixtureKindsProvisioner::$calls[] = ['class' => static::class, 'context' => $context];

        return ['fixture late' => ['created' => 1, 'existing' => 0]];
    }
}
