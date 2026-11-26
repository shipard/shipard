<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;

/**
 * Registrace k evidenci tržeb (#171 D3, D10, D16): typ z registru, maska
 * identifikátoru, výchozí jednotka jen vlastní, unikátní (typ, poplatník)
 * mezi živými registracemi — a víc registrací různých typů vedle sebe.
 */
class RegistrationDocumentTest extends TestCase
{
    use SalesRegistrationConfig;

    /**
     * @param array<string, mixed>|null $stored uložený řádek
     * @param array<int, int> $unitOwners id jednotky → id registrace
     * @param list<array{type: string, taxpayer_id: string, id: int, docState: int}> $others jiné registrace v databázi
     * @param array<string, mixed> $configOverride
     */
    private function doc(?array $stored = null, array $unitOwners = [], array $others = [], array $configOverride = []): RegistrationDocument
    {
        $doc = new class extends RegistrationDocument {
            /** @var array<string, mixed>|null */
            public ?array $stored = null;
            /** @var array<int, int> */
            public array $unitOwners = [];
            /** @var list<array<string, mixed>> */
            public array $others = [];

            protected function loadRegistrationRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function unitRegistration(int $unitId): ?int
            {
                return $this->unitOwners[$unitId] ?? null;
            }

            protected function countLiveRegistrations(string $type, string $taxpayerId, ?int $exceptId): int
            {
                return count(array_filter(
                    $this->others,
                    static fn(array $r): bool => $r['type'] === $type && $r['taxpayer_id'] === $taxpayerId
                        && $r['id'] !== ($exceptId ?? 0) && in_array($r['docState'], RegistrationDocument::LIVE_STATES, true),
                ));
            }

            /** @return array{old: int, new: int}|null */
            public function transitionAfterSave(array $data, ?array $original): ?array
            {
                $this->beforeSave($data, $original);
                return $this->getStateTransition();
            }
        };
        $doc->stored = $stored;
        $doc->unitOwners = $unitOwners;
        $doc->others = $others;
        $doc->setConfig($this->salesRegistrationConfig($configOverride));
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function codes(RegistrationDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testNameAndKnownTypeAreRequired(): void
    {
        $doc = $this->doc();

        $this->assertSame(['name:required', 'type:required'], $this->codes($doc, ['name' => ' ', 'type' => '']));
        $this->assertSame(['type:invalid'], $this->codes($doc, ['name' => 'Prodejna', 'type' => 'cz.eet1']));
        $this->assertSame([], $this->codes($doc, ['name' => 'Prodejna', 'type' => 'cz.eet2']));
    }

    public function testTypeWithoutEngineCannotBeChosen(): void
    {
        $doc = $this->doc(configOverride: ['economy.salesRegistration.engines' => []]);

        $this->assertSame(['type:invalid'], $this->codes($doc, ['name' => 'Prodejna', 'type' => 'cz.eet2']));
    }

    public function testModeMustBeKnownAndDefaultsToVerification(): void
    {
        $doc = $this->doc();

        $this->assertSame([], $this->codes($doc, ['name' => 'a', 'type' => 'cz.eet2']), 'bez režimu platí výchozí ověřovací');
        foreach (RegistrationDocument::MODES as $mode) {
            $this->assertSame([], $this->codes($doc, ['name' => 'a', 'type' => 'cz.eet2', 'mode' => $mode]));
        }
        $this->assertSame(['mode:invalid'], $this->codes($doc, ['name' => 'a', 'type' => 'cz.eet2', 'mode' => 'live']));
    }

    public function testTaxpayerIdFollowsTheMaskOfTheType(): void
    {
        $doc = $this->doc();
        $base = ['name' => 'a', 'type' => 'cz.eet2'];

        $this->assertSame([], $this->codes($doc, $base + ['taxpayer_id' => 'CZ00000019']));
        $this->assertSame(['_form:invalid_format'], $this->codes($doc, $base + ['taxpayer_id' => '00000019']));

        $data = $base + ['taxpayer_id' => 'X1'];
        $message = $doc->validate($data)->toArray()[0]['message'];
        $this->assertStringContainsString('EIČ', $message, 'hláška jmenuje identifikátor tak, jak mu říká typ');
    }

    public function testValidityRange(): void
    {
        $doc = $this->doc();
        $base = ['name' => 'a', 'type' => 'cz.eet2'];

        $this->assertSame([], $this->codes($doc, $base + ['valid_from' => '2027-01-01', 'valid_to' => '2027-01-01']));
        $this->assertSame(['valid_to:invalid_range'], $this->codes($doc, $base + ['valid_from' => '2027-02-01', 'valid_to' => '2027-01-31']));
        $this->assertSame([], $this->codes($doc, $base + ['valid_from' => '2027-02-01', 'valid_to' => '']));
    }

    public function testValidityRangeUsesStoredValueOnPartialUpdate(): void
    {
        $stored = ['id' => 7, 'name' => 'a', 'type' => 'cz.eet2', 'valid_from' => new \DateTimeImmutable('2027-03-01'), 'docState' => 10];

        $this->assertSame(
            ['valid_to:invalid_range'],
            $this->codes($this->doc($stored), ['id' => 7, 'valid_to' => '2027-02-01']),
        );
    }

    public function testDefaultUnitMustBelongToTheRegistration(): void
    {
        $stored = ['id' => 7, 'name' => 'a', 'type' => 'cz.eet2', 'docState' => 10];
        $doc = $this->doc($stored, unitOwners: [3 => 7, 4 => 8]);

        $this->assertSame([], $this->codes($doc, ['id' => 7, 'default_unit' => 3]));
        $this->assertSame(['default_unit:invalid'], $this->codes($doc, ['id' => 7, 'default_unit' => 4]), 'jednotka jiné registrace');
        $this->assertSame(['default_unit:invalid'], $this->codes($doc, ['id' => 7, 'default_unit' => 99]), 'neexistující jednotka');
        $this->assertSame([], $this->codes($doc, ['id' => 7, 'default_unit' => null]));
        $this->assertSame(
            ['default_unit:invalid'],
            $this->codes($this->doc(unitOwners: [3 => 7]), ['name' => 'a', 'type' => 'cz.eet2', 'default_unit' => 3]),
            'nová registrace ještě žádnou jednotku mít nemůže',
        );
    }

    public function testTypeAndTaxpayerAreUniqueAmongLiveRegistrations(): void
    {
        $others = [['type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'id' => 1, 'docState' => 40]];
        $data = ['name' => 'Druhá', 'type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019'];

        $this->assertSame(['_form:duplicate'], $this->codes($this->doc(others: $others), $data));
        $this->assertSame([], $this->codes($this->doc(others: $others), ['taxpayer_id' => 'CZ683555118'] + $data), 'jiný poplatník');
        $this->assertSame([], $this->codes($this->doc(others: $others), ['name' => 'Bez certifikátu', 'type' => 'cz.eet2']), 'bez identifikátoru se nehlídá');

        $archived = [['type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'id' => 1, 'docState' => 70]];
        $this->assertSame([], $this->codes($this->doc(others: $archived), $data), 'archivovaná registrace neblokuje');
    }

    public function testRegistrationItselfAndArchivingAreNotDuplicates(): void
    {
        $stored = ['id' => 1, 'name' => 'a', 'type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'docState' => 40];
        $others = [
            ['type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'id' => 1, 'docState' => 40],
            ['type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'id' => 2, 'docState' => 40],
        ];

        $this->assertSame([], $this->codes($this->doc($stored, others: [$others[0]]), ['id' => 1, 'name' => 'b']), 'sama sebe nepočítá');
        $this->assertSame(['_form:duplicate'], $this->codes($this->doc($stored, others: $others), ['id' => 1, 'name' => 'b']));
        $this->assertSame([], $this->codes($this->doc($stored, others: $others), ['id' => 1, 'docState' => 70]), 'do archivu smí vždy');
    }

    /** #171 D16 — česká EET a evidence jiné země se stejným identifikátorem vedle sebe. */
    public function testRegistrationsOfDifferentTypesLiveSideBySide(): void
    {
        $sk = self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc')['cz.eet2'];
        $sk['taxpayerId'] = ['label' => 'DIČ', 'pattern' => '^CZ[0-9]{8,10}$'];
        $doc = $this->doc(
            others: [['type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'id' => 1, 'docState' => 40]],
            configOverride: [
                'economy.salesRegistration.engines' => ['cz.eet2' => self::TEST_ENGINE, 'sk.ekasa' => self::TEST_ENGINE],
                'world.sk.salesRegistrationTypes'   => ['sk.ekasa' => $sk],
            ],
        );

        $this->assertSame([], $this->codes($doc, ['name' => 'Slovensko', 'type' => 'sk.ekasa', 'taxpayer_id' => 'CZ00000019']));
    }

    public function testTypeIsLockedOnceTheCertificateIsUploaded(): void
    {
        $withCertificate = ['id' => 1, 'name' => 'a', 'type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'docState' => 10];
        $without = ['id' => 1, 'name' => 'a', 'type' => 'cz.eet2', 'taxpayer_id' => null, 'docState' => 10];
        $config = [
            'economy.salesRegistration.engines' => ['cz.eet2' => self::TEST_ENGINE, 'cz.other' => self::TEST_ENGINE],
            'world.cz.salesRegistrationTypes'   => [
                'cz.eet2'  => self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc')['cz.eet2'],
                'cz.other' => self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc')['cz.eet2'],
            ],
        ];

        $this->assertSame(['type:typeLocked'], $this->codes($this->doc($withCertificate, configOverride: $config), ['id' => 1, 'type' => 'cz.other']));
        $this->assertSame([], $this->codes($this->doc($without, configOverride: $config), ['id' => 1, 'type' => 'cz.other']));
    }

    public function testStoredTypeMissingFromTheRegistryDoesNotBlockOtherEdits(): void
    {
        // Modul země byl ve zdroji dat vypnut — typ z registru zmizel.
        $stored = ['id' => 1, 'name' => 'a', 'type' => 'cz.eet2', 'taxpayer_id' => 'CZ00000019', 'docState' => 40];
        $doc = $this->doc($stored, configOverride: ['world.cz.salesRegistrationTypes' => null]);

        $this->assertSame([], $this->codes($doc, ['id' => 1, 'name' => 'Nový název']));
    }

    public function testBeforeSaveNormalizesEmptyValuesAndTracksStateChange(): void
    {
        $doc = $this->doc();
        $data = ['name' => '  Prodejna ', 'type' => ' cz.eet2', 'valid_from' => '', 'valid_to' => '2027-12-31', 'default_unit' => '0', 'notice' => ''];
        $doc->beforeSave($data, null);

        $this->assertSame('Prodejna', $data['name']);
        $this->assertSame('cz.eet2', $data['type']);
        $this->assertNull($data['valid_from']);
        $this->assertSame('2027-12-31', $data['valid_to']);
        $this->assertNull($data['default_unit']);
        $this->assertNull($data['notice']);

        $this->assertSame(
            ['old' => 10, 'new' => 40],
            $doc->transitionAfterSave(['id' => 1, 'docState' => 40], ['id' => 1, 'docState' => 10]),
            'přechod stavu se hlásí posluchačům (fáze 2)',
        );
        $this->assertNull($doc->transitionAfterSave(['id' => 1, 'name' => 'x', 'docState' => 40], ['id' => 1, 'docState' => 40]));
    }

    public function testDateOfAcceptsTextAndDateObjects(): void
    {
        $this->assertSame('2027-01-05', RegistrationDocument::dateOf('2027-01-05'));
        $this->assertSame('2027-01-05', RegistrationDocument::dateOf('2027-01-05 00:00:00'));
        $this->assertSame('2027-01-05', RegistrationDocument::dateOf(new \DateTimeImmutable('2027-01-05 13:00')));
        $this->assertSame('', RegistrationDocument::dateOf(null));
        $this->assertSame('', RegistrationDocument::dateOf(''));
    }
}
