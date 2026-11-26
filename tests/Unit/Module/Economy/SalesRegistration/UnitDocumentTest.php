<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Evidenční jednotka (#171 D3): maska čísla podle typu nadřízené registrace,
 * číslo u registrace jen jednou, smazaná výchozí jednotka uvolní registraci.
 */
class UnitDocumentTest extends TestCase
{
    use SalesRegistrationConfig;

    /**
     * @param array<string, mixed>|null $stored uložený řádek jednotky
     * @param array<int, string> $registrationTypes id registrace → typ evidence
     * @param list<array{registration: int, unit_id: string, id: int}> $units jiné jednotky v databázi
     */
    private function doc(?array $stored = null, array $registrationTypes = [7 => 'cz.eet2'], array $units = []): UnitDocument
    {
        $doc = new class extends UnitDocument {
            /** @var array<string, mixed>|null */
            public ?array $stored = null;
            /** @var array<int, string> */
            public array $registrationTypes = [];
            /** @var list<array<string, mixed>> */
            public array $units = [];
            /** @var list<array{int, int}> */
            public array $cleared = [];

            protected function loadUnitRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function registrationType(int $registrationId): ?string
            {
                return $this->registrationTypes[$registrationId] ?? null;
            }

            protected function countUnits(int $registrationId, string $unitId, ?int $exceptId): int
            {
                return count(array_filter(
                    $this->units,
                    static fn(array $u): bool => $u['registration'] === $registrationId && $u['unit_id'] === $unitId && $u['id'] !== ($exceptId ?? 0),
                ));
            }

            protected function clearDefaultUnit(int $registrationId, int $unitId): void
            {
                $this->cleared[] = [$registrationId, $unitId];
            }
        };
        $doc->stored = $stored;
        $doc->registrationTypes = $registrationTypes;
        $doc->units = $units;
        $doc->setConfig($this->salesRegistrationConfig());
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function codes(UnitDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testRegistrationAndUnitNumberAreRequired(): void
    {
        $doc = $this->doc();

        $this->assertSame(['registration:required'], $this->codes($doc, ['unit_id' => '181']));
        $this->assertSame(['unit_id:required'], $this->codes($doc, ['registration' => 7, 'unit_id' => ' ']));
        $this->assertSame(['registration:invalid'], $this->codes($doc, ['registration' => 99, 'unit_id' => '181']), 'registrace neexistuje');
        $this->assertSame([], $this->codes($doc, ['registration' => 7, 'unit_id' => '181', 'name' => 'Prodejna']));
    }

    public function testUnitNumberFollowsTheMaskOfTheRegistrationType(): void
    {
        $doc = $this->doc();

        foreach (['1', '181', '999999999'] as $ok) {
            $this->assertSame([], $this->codes($doc, ['registration' => 7, 'unit_id' => $ok]), $ok);
        }
        foreach (['0', '0181', '1234567890', 'A1', '18 1'] as $bad) {
            $this->assertSame(['unit_id:invalid_format'], $this->codes($doc, ['registration' => 7, 'unit_id' => $bad]), $bad);
        }
    }

    public function testUnknownTypeOfTheRegistrationDoesNotRestrictTheNumber(): void
    {
        // Registrace typu, který z registru zmizel — masku není odkud vzít.
        $doc = $this->doc(registrationTypes: [7 => 'xx.gone']);

        $this->assertSame([], $this->codes($doc, ['registration' => 7, 'unit_id' => 'A-1']));
    }

    public function testUnitNumberIsUniqueWithinTheRegistration(): void
    {
        $units = [
            ['registration' => 7, 'unit_id' => '181', 'id' => 1],
            ['registration' => 8, 'unit_id' => '200', 'id' => 2],
        ];
        $types = [7 => 'cz.eet2', 8 => 'cz.eet2'];

        $this->assertSame(['unit_id:duplicate'], $this->codes($this->doc(null, $types, $units), ['registration' => 7, 'unit_id' => '181']));
        $this->assertSame([], $this->codes($this->doc(null, $types, $units), ['registration' => 7, 'unit_id' => '200']), 'číslo jiné registrace nevadí');

        $stored = ['id' => 1, 'registration' => 7, 'unit_id' => '181', 'name' => null];
        $this->assertSame([], $this->codes($this->doc($stored, $types, $units), ['id' => 1, 'name' => 'Přejmenovaná']), 'sama sebe nepočítá');
    }

    public function testUnitCannotMoveToAnotherRegistration(): void
    {
        $stored = ['id' => 1, 'registration' => 7, 'unit_id' => '181'];

        $this->assertSame(
            ['registration:invalid'],
            $this->codes($this->doc($stored, [7 => 'cz.eet2', 8 => 'cz.eet2']), ['id' => 1, 'registration' => 8]),
        );
    }

    public function testBeforeSaveTrimsValues(): void
    {
        $data = ['unit_id' => ' 181 ', 'name' => '  '];
        $this->doc()->beforeSave($data, null);

        $this->assertSame(['unit_id' => '181', 'name' => null], $data);
    }

    public function testDeletingTheUnitReleasesItAsDefaultUnit(): void
    {
        $doc = $this->doc();
        $doc->afterDelete(['id' => 3, 'registration' => 7, 'unit_id' => '181']);

        $this->assertSame([[7, 3]], $doc->cleared);
    }
}
