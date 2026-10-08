<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\WorkOrderSeriesDocument;

/**
 * Číselná řada zakázek (D17, P3): druh povinný, existující a po založení
 * neměnný; vzorec přes jádro číslování a navíc s povinným pořadím.
 */
class WorkOrderSeriesDocumentTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $stored uložený řádek řady
     * @param array<string, mixed>|null $kind řádek druhu (null = neexistuje)
     */
    private function doc(?array $stored = null, ?array $kind = ['id' => 3, 'docState' => 40], bool $withDb = true): WorkOrderSeriesDocument
    {
        $doc = new class($stored, $kind) extends WorkOrderSeriesDocument {
            public function __construct(private readonly ?array $stored, private readonly ?array $kind)
            {
            }

            protected function loadRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function loadKindRow(int $kindId): ?array
            {
                return $this->kind;
            }
        };
        if ($withDb) {
            $doc->setDb($this->createMock(\Dibi\Connection::class));
        }
        return $doc;
    }

    /** @return array<string, mixed> */
    private function series(array $overrides = []): array
    {
        return array_merge([
            'kind'           => 3,
            'name'           => 'Zakázky',
            'number_code'    => 'Z',
            'number_pattern' => '%C%y%4',
            'reset_scope'    => 'fiscal_year',
        ], $overrides);
    }

    /** @return list<string> column:code */
    private function codes(WorkOrderSeriesDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testValidSeriesPasses(): void
    {
        $this->assertSame([], $this->codes($this->doc(), $this->series()));
        $this->assertSame([], $this->codes($this->doc(), $this->series(['number_pattern' => 'ZAK-%Y-%5', 'number_code' => '', 'reset_scope' => 'none'])));
    }

    public function testKindRequiredExistingAndNotDeleted(): void
    {
        $this->assertSame(['kind:required'], $this->codes($this->doc(), $this->series(['kind' => 0])));
        $this->assertSame(['kind:not_found'], $this->codes($this->doc(kind: null), $this->series()));
        $this->assertSame(['kind:invalid_state'], $this->codes($this->doc(kind: ['id' => 3, 'docState' => 90]), $this->series()));
        // Bez DB se existence druhu neověřuje (degradace, ne crash).
        $this->assertSame([], $this->codes($this->doc(kind: null, withDb: false), $this->series()));
    }

    public function testKindIsLockedAfterCreation(): void
    {
        $doc = $this->doc(stored: ['id' => 7, 'kind' => 3]);
        $this->assertSame(['kind:kindLocked'], $this->codes($doc, $this->series(['id' => 7, 'kind' => 4])));
        $this->assertSame([], $this->codes($doc, $this->series(['id' => 7, 'kind' => 3])));
    }

    public function testPatternNeedsSequenceAndKnownPlaceholders(): void
    {
        $this->assertSame(['number_pattern:required'], $this->codes($this->doc(), $this->series(['number_pattern' => ' '])));
        // P3: bez pořadí by se čísla opakovala.
        $this->assertSame(['number_pattern:sequence_required'], $this->codes($this->doc(), $this->series(['number_pattern' => '%C%y'])));
        // %C bez kódu řady — chyba jde ke kódu, ne ke vzorci.
        $this->assertSame(['number_code:required_for_pattern'], $this->codes($this->doc(), $this->series(['number_code' => null])));
        // Doménový placeholder dokladů (%D) zakázky nemají.
        $this->assertSame(['number_pattern:unknown_placeholder'], $this->codes($this->doc(), $this->series(['number_pattern' => '%D%4'])));
    }

    public function testResetScopeAndValidityRange(): void
    {
        $this->assertSame(['reset_scope:invalid_value'], $this->codes($this->doc(), $this->series(['reset_scope' => 'month'])));
        $this->assertSame(
            ['valid_to:invalid_range'],
            $this->codes($this->doc(), $this->series(['valid_from' => '2026-12-31', 'valid_to' => '2026-01-01'])),
        );
        $this->assertSame(['name:required'], $this->codes($this->doc(), $this->series(['name' => ''])));
    }

    public function testBeforeSaveTrimsAndDefaultsResetScope(): void
    {
        $data = $this->series(['name' => ' Zakázky ', 'number_code' => ' ', 'number_pattern' => ' %C%4 ', 'reset_scope' => '', 'notice' => '']);
        $this->doc()->beforeSave($data, null);

        $this->assertSame('Zakázky', $data['name']);
        $this->assertNull($data['number_code']);
        $this->assertSame('%C%4', $data['number_pattern']);
        $this->assertSame(WorkOrderSeriesDocument::DEFAULT_RESET_SCOPE, $data['reset_scope']);
        $this->assertNull($data['notice']);
    }
}
