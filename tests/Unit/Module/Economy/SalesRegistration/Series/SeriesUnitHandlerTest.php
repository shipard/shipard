<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Series;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Series\SeriesUnitHandler;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Kód číselné řady s evidenční jednotkou proti masce označení pokladního
 * zařízení (#171 D21): kontrola při přiřazení jednotky i při změně kódu,
 * chyba jako `\DomainException` (formulář ji ukáže, 422).
 */
class SeriesUnitHandlerTest extends TestCase
{
    use SalesRegistrationConfig;

    /** @param array<int, string> $unitTypes id jednotky → id typu její registrace */
    private function handler(array $unitTypes = [5 => 'cz.eet2']): SeriesUnitHandler
    {
        $handler = new class extends SeriesUnitHandler {
            /** @var array<int, string> */
            public array $unitTypes = [];
            public int $lookups = 0;

            protected function unitTypeId(int $unitId): ?string
            {
                $this->lookups++;
                return $this->unitTypes[$unitId] ?? null;
            }
        };
        $handler->unitTypes = $unitTypes;
        $handler->setConfig($this->salesRegistrationConfig());
        return $handler;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $original
     */
    private function error(SeriesUnitHandler $handler, array &$data, ?array $original = null): ?string
    {
        try {
            $handler->onBeforeSave(SeriesUnitHandler::TABLE, $data, $original);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
        return null;
    }

    public function testSeriesWithoutUnitIsNotChecked(): void
    {
        $handler = $this->handler();
        $data = ['code' => 'řada s diakritikou a hodně dlouhým kódem', 'sales_registration_unit' => ''];

        $this->assertNull($this->error($handler, $data));
        $this->assertNull($data['sales_registration_unit'], 'prázdný výběr = bez jednotky');
        $this->assertSame(0, $handler->lookups);
    }

    public function testAssigningUnitChecksTheSeriesCode(): void
    {
        $handler = $this->handler();

        $ok = ['code' => 'cash-P1', 'sales_registration_unit' => 5];
        $this->assertNull($this->error($handler, $ok));

        $long = ['code' => 'prodejky-hlavni-provozovna', 'sales_registration_unit' => 5];
        $message = $this->error($handler, $long);
        $this->assertNotNull($message);
        $this->assertStringContainsString('prodejky-hlavni-provozovna', $message);
        $this->assertStringContainsString('EET 2.0', $message);
        $this->assertStringContainsString('nejvýš 20 znaků', $message, 'popis masky z dat typu');
    }

    public function testCodeIsTakenFromTheStoredRowWhenOnlyTheUnitIsSaved(): void
    {
        $handler = $this->handler();
        $original = ['id' => 3, 'code' => 'invno-hlavni-rada-2027-dlouha', 'sales_registration_unit' => null];

        $data = ['id' => 3, 'sales_registration_unit' => 5];
        $this->assertNotNull($this->error($handler, $data, $original));

        $original['code'] = 'invno';
        $data = ['id' => 3, 'sales_registration_unit' => 5];
        $this->assertNull($this->error($handler, $data, $original));
    }

    public function testChangingTheCodeOfASeriesWithUnitIsChecked(): void
    {
        $handler = $this->handler();
        $original = ['id' => 3, 'code' => 'invno', 'sales_registration_unit' => 5];

        $data = ['id' => 3, 'code' => 'invno-příliš'];
        $this->assertNotNull($this->error($handler, $data, $original), 'diakritika v označení pokladny neprojde');

        $data = ['id' => 3, 'code' => 'invno-2'];
        $this->assertNull($this->error($handler, $data, $original));
    }

    public function testUnchangedSeriesIsNotCheckedAgain(): void
    {
        // Řada, která neplatný kód už má (starší data), jde dál ukládat —
        // kontrola hlídá změnu, ne stav.
        $handler = $this->handler();
        $original = ['id' => 3, 'code' => 'kód s diakritikou', 'sales_registration_unit' => 5];
        $data = ['id' => 3, 'name' => 'Nový název'];

        $this->assertNull($this->error($handler, $data, $original));
        $this->assertSame(0, $handler->lookups);
    }

    public function testUnitMustExistAndSeriesMustHaveACode(): void
    {
        $handler = $this->handler();

        $data = ['code' => 'invno', 'sales_registration_unit' => 99];
        $this->assertSame('Vybraná evidenční jednotka neexistuje.', $this->error($handler, $data));

        $data = ['code' => ' ', 'sales_registration_unit' => 5];
        $this->assertStringContainsString('musí mít kód', (string) $this->error($handler, $data));
    }

    public function testUnitOfUnknownTypeDoesNotBlockTheSave(): void
    {
        // Typ zmizel ze zdroje dat (vypnutý modul země) — masku není čím ověřit.
        $handler = $this->handler([5 => 'xx.gone']);
        $data = ['code' => 'cokoli dlouhého a s diakritikou č', 'sales_registration_unit' => 5];

        $this->assertNull($this->error($handler, $data));
    }

    public function testOtherTablesAreIgnored(): void
    {
        $handler = $this->handler();
        $data = ['code' => 'ž', 'sales_registration_unit' => 5];

        $handler->onBeforeSave('docs_core_heads', $data, null);

        $this->assertSame(0, $handler->lookups);
    }
}
