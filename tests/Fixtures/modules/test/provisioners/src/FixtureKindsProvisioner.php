<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Provisioners;

use Shipard\Core\Module\ModuleProvisionContext;
use Shipard\Core\Module\ModuleProvisioner;

/** Fixture: provisioner, který si pamatuje volání a hlásí dvě skupiny záznamů (#174 D28). */
class FixtureKindsProvisioner implements ModuleProvisioner
{
    /** @var list<array{class: string, context: ModuleProvisionContext}> */
    public static array $calls = [];

    /** Zpráva výjimky, kterou má příští volání vyhodit; null = projde. */
    public static ?string $failWith = null;

    public function provision(ModuleProvisionContext $context): array
    {
        self::$calls[] = ['class' => static::class, 'context' => $context];
        if (self::$failWith !== null) {
            throw new \DomainException(self::$failWith);
        }

        return [
            'fixture kinds' => ['created' => 2, 'existing' => 1],
            'fixture extras' => ['created' => 0, 'existing' => 4],
        ];
    }
}
