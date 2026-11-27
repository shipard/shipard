<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Facility\Buildings\ContractKindDocument;

/** Spy: uložené druhy, počty smluv a kódy jiných druhů z polí. */
class TestableContractKindDocument extends ContractKindDocument
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var array<int, int> id druhu → počet smluv mimo koš */
    public array $used = [];
    /** @var array<string, int> kód → id druhu, který ho má */
    public array $codes = [];

    protected function loadKindRow(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    protected function countContractsUsingKind(int $kindId): int
    {
        return $this->used[$kindId] ?? 0;
    }

    protected function findCodeOwner(string $code, ?int $excludeId): ?int
    {
        $owner = $this->codes[$code] ?? null;
        return $owner !== null && $owner !== $excludeId ? $owner : null;
    }
}

/**
 * Druh smlouvy (#174 D18): typ, vzorec čísla, zámek typu a koš použitého
 * druhu.
 */
class ContractKindDocumentTest extends TestCase
{
    use BuildingsConfig;

    private function document(): TestableContractKindDocument
    {
        $document = new TestableContractKindDocument();
        $document->setConfig($this->buildingsConfig());
        return $document;
    }

    /** @return array<string, mixed> */
    private static function kind(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Kancelář', 'type' => 'office', 'number_code' => 'KA',
            'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 10,
        ];
    }

    /** @return list<string> */
    private static function codes(ValidationResult $result): array
    {
        return array_map(static fn ($e): string => $e->column . ':' . $e->code, $result->getErrors());
    }

    public function testValidKindPasses(): void
    {
        $data = self::kind();
        $this->assertSame([], self::codes($this->document()->validate($data)));
    }

    public function testNameAndTypeAreRequiredAndTypeMustBeKnown(): void
    {
        $data = self::kind(['name' => '  ', 'type' => '']);
        $this->assertSame(['name:required', 'type:required'], self::codes($this->document()->validate($data)));

        $data = self::kind(['type' => 'cellar']);
        $this->assertSame(['type:invalid'], self::codes($this->document()->validate($data)));
    }

    public function testPatternWithCodePlaceholderNeedsTheKindCode(): void
    {
        $data = self::kind(['number_code' => '']);
        $this->assertSame(['number_code:required_for_pattern'], self::codes($this->document()->validate($data)));

        $data = self::kind(['number_code' => null, 'number_pattern' => '%Y-%4']);
        $this->assertSame([], self::codes($this->document()->validate($data)), 'vzorec bez %C kód nepotřebuje');
    }

    public function testPatternMustBeFilledKnownAndCarryASequence(): void
    {
        $data = self::kind(['number_pattern' => '']);
        $this->assertSame(['number_pattern:required'], self::codes($this->document()->validate($data)));

        $data = self::kind(['number_pattern' => '%C-%Y-%Q']);
        $this->assertSame(['number_pattern:unknown_placeholder'], self::codes($this->document()->validate($data)));

        $data = self::kind(['number_pattern' => '%C-%Y']);
        $this->assertSame(['number_pattern:sequence_required'], self::codes($this->document()->validate($data)));
    }

    public function testYearlyResetNeedsTheYearInThePattern(): void
    {
        // Bez roku by se čísla po roce opakovala — číslo smlouvy je unikátní.
        $data = self::kind(['number_pattern' => '%C-%4']);
        $this->assertSame(['number_pattern:year_required'], self::codes($this->document()->validate($data)));

        $data = self::kind(['number_pattern' => '%C-%4', 'reset_scope' => 'continuous']);
        $this->assertSame([], self::codes($this->document()->validate($data)));

        $data = self::kind(['number_pattern' => '%C%y%4']);
        $this->assertSame([], self::codes($this->document()->validate($data)));
    }

    public function testUnknownResetScopeIsAnError(): void
    {
        $data = self::kind(['reset_scope' => 'fiscal_year']);
        $this->assertContains('reset_scope:invalid', self::codes($this->document()->validate($data)));
    }

    public function testCodeOfAnotherKindIsADuplicate(): void
    {
        $document = $this->document();
        $document->codes = ['KA' => 3];

        $data = self::kind();
        $this->assertSame(['number_code:duplicate'], self::codes($document->validate($data)));

        $data = self::kind(['id' => 3]);
        $document->rows[3] = ['id' => 3, 'type' => 'office', 'docState' => 10];
        $this->assertSame([], self::codes($document->validate($data)), 'vlastní kód není duplicita');
    }

    public function testTypeIsLockedOnceTheKindLeftDraft(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'type' => 'office', 'docState' => 40];

        $data = self::kind(['id' => 5, 'type' => 'flat', 'docState' => 40]);
        $this->assertSame(['type:typeLocked'], self::codes($document->validate($data)));

        // Ani v opravě — smlouvy druhu si typ nesou denormalizovaný.
        $document->rows[5]['docState'] = 80;
        $data = self::kind(['id' => 5, 'type' => 'flat', 'docState' => 80]);
        $this->assertSame(['type:typeLocked'], self::codes($document->validate($data)));

        $data = self::kind(['id' => 5, 'type' => 'office', 'docState' => 80, 'name' => 'Kanceláře']);
        $this->assertSame([], self::codes($document->validate($data)), 'beze změny typu jde druh upravit');
    }

    public function testTypeOfADraftKindMayChangeUnlessAContractUsesIt(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'type' => 'office', 'docState' => 10];

        $data = self::kind(['id' => 5, 'type' => 'flat']);
        $this->assertSame([], self::codes($document->validate($data)));

        $document->used[5] = 1;
        $data = self::kind(['id' => 5, 'type' => 'flat']);
        $this->assertSame(['type:typeLocked'], self::codes($document->validate($data)));
    }

    public function testUsedKindCannotGoToTrashButCanBeArchived(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'type' => 'office', 'docState' => 40];
        $document->used[5] = 2;

        $data = self::kind(['id' => 5, 'docState' => 90]);
        $this->assertSame(['_form:in_use'], self::codes($document->validate($data)));

        $data = self::kind(['id' => 5, 'docState' => 70]);
        $this->assertSame([], self::codes($document->validate($data)), 'archivovat jde vždy');

        $document->used[5] = 0;
        $data = self::kind(['id' => 5, 'docState' => 90]);
        $this->assertSame([], self::codes($document->validate($data)), 'nepoužitý druh do koše jde');
    }

    public function testBeforeSaveTrimsAndEmptiesOptionalTexts(): void
    {
        $data = self::kind(['name' => '  Kancelář ', 'number_code' => '  ', 'notice' => ' ', 'number_pattern' => ' %Y-%4 ']);
        $this->document()->beforeSave($data);

        $this->assertSame('Kancelář', $data['name']);
        $this->assertSame('%Y-%4', $data['number_pattern']);
        $this->assertNull($data['number_code']);
        $this->assertNull($data['notice']);
    }
}
