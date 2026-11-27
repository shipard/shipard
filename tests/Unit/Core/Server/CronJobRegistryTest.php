<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\CronJob;
use Shipard\Core\Server\CronJobRegistry;
use Shipard\Core\Server\CronProvisioner;

/**
 * Registr úloh cronu z `module.jsonc` (#172 D6) — řazení na syntetických
 * definicích a shoda skutečného stromu `modules/` se zapsanou tabulkou.
 */
class CronJobRegistryTest extends TestCase
{
    /**
     * Úlohy slotů: `[příkaz, modul, stavy]` v pořadí běhu. Do #172 je nesly
     * konstanty `CronCommand::SLOT_JOBS` / `JOB_MODULES` /
     * `JOB_ALLOWED_STATES`; test vznikl proti nim, než zanikly. Změna
     * `cronJobs` v modulu = vědomá změna této tabulky.
     */
    private const EXPECTED = [
        'minute' => [
            ['mail-outbox-run', 'core.mail', ['active']],
            ['mail-analysis-reap', 'core.mail', ['active']],
            ['mail-preprocess --sweep', 'core.mail', ['active']],
            ['mail-analyze --sweep', 'core.mail', ['active']],
            // fronta evidence tržeb (#171 D7) — doplněk, jen kde je zapnutý
            ['sales-registration-run', 'economy.salesRegistration', ['active']],
        ],
        'two-minutes' => [],
        'five-minutes' => [
            // úklid výzev zařízení (#177 U20) — i v režimu jen pro čtení,
            // výzvy tam vznikají také
            ['device-challenges-prune', 'core.devices', ['active', 'read_only']],
            // stahování kurzů před upozorněními — čerstvý zdroj dat nedostane
            // nález dřív, než poprvé stáhne
            ['exchange-rates-download', 'economy.exchangeRates', ['active']],
            // záchranná síť evidence tržeb před upozorněními — chybějící
            // tržbu nejdřív založí, pak teprve hlásí
            ['sales-registration-reconcile', 'economy.salesRegistration', ['active']],
            ['alerts-run', 'core.alerts', ['active']],
        ],
        'daily' => [
            ['mail-idempotency-prune', 'core.mail', ['active', 'read_only']],
            ['vat-periods-ensure', 'economy.vat', ['active']],
            ['work-orders-invoice-run', 'economy.workOrders', ['active']],
        ],
        'weekly' => [
            ['alerts-prune', 'core.alerts', ['active', 'read_only']],
        ],
    ];

    /** @param list<array<string, mixed>> $cronJobs */
    private function module(string $id, array $cronJobs): ModuleDefinition
    {
        return ModuleDefinition::fromArray(['id' => $id, 'name' => $id, 'cronJobs' => $cronJobs]);
    }

    /** @return list<string> */
    private function commands(CronJobRegistry $registry, string $slot): array
    {
        return array_map(static fn(CronJob $job): string => $job->command, $registry->forSlot($slot));
    }

    public function testRealModuleTreeMatchesTheExpectedTable(): void
    {
        $registry = new CronJobRegistry(ModuleLoader::loadAllModules(
            new ModulePathResolver([dirname(__DIR__, 4) . '/modules']),
        ));

        $this->assertSame(CronProvisioner::SLOTS, array_keys(self::EXPECTED), 'tabulka pokrývá všechny sloty');
        foreach (self::EXPECTED as $slot => $expected) {
            $this->assertSame(
                $expected,
                array_map(
                    static fn(CronJob $job): array => [$job->command, $job->module, $job->states],
                    $registry->forSlot($slot),
                ),
                $slot,
            );
        }
    }

    public function testJobsAreOrderedByOrderThenModuleThenDeclaration(): void
    {
        $registry = new CronJobRegistry([
            // záměrně mimo abecední pořadí modulů
            $this->module('economy.vat', [
                ['command' => 'vat-b', 'slot' => 'daily'],
                ['command' => 'vat-a', 'slot' => 'daily'],
                ['command' => 'vat-first', 'slot' => 'daily', 'order' => 100],
            ]),
            $this->module('core.mail', [
                ['command' => 'mail-last', 'slot' => 'daily', 'order' => 900],
                ['command' => 'mail-default', 'slot' => 'daily'],
                ['command' => 'mail-weekly', 'slot' => 'weekly'],
            ]),
        ]);

        $this->assertSame(
            ['vat-first', 'mail-default', 'vat-b', 'vat-a', 'mail-last'],
            $this->commands($registry, 'daily'),
            'order vzestupně, pak id modulu, pak pořadí deklarace',
        );
        $this->assertSame(['mail-weekly'], $this->commands($registry, 'weekly'));
        $this->assertSame([], $this->commands($registry, 'minute'));
    }

    public function testJobCarriesModuleSlotOrderAndStates(): void
    {
        $registry = new CronJobRegistry([
            $this->module('core.mail', [
                ['command' => 'mail-idempotency-prune', 'slot' => 'daily', 'order' => 700, 'states' => ['active', 'read_only']],
            ]),
        ]);

        $job = $registry->forSlot('daily')[0];
        $this->assertSame('core.mail', $job->module);
        $this->assertSame('daily', $job->slot);
        $this->assertSame(700, $job->order);
        $this->assertTrue($job->runsIn('active'));
        $this->assertTrue($job->runsIn('read_only'));
        $this->assertFalse($job->runsIn('suspended'));
    }

    public function testModulesWithoutCronJobsGiveAnEmptyRegistry(): void
    {
        $registry = new CronJobRegistry([$this->module('core.system', [])]);

        foreach (CronProvisioner::SLOTS as $slot) {
            $this->assertSame([], $registry->forSlot($slot), $slot);
        }
    }
}
