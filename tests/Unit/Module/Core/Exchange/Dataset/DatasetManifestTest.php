<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;

class DatasetManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'format'      => 'shpd.dataset.v1',
            'name'        => 'web-demo',
            'title'       => 'Demo webu — den účetní',
            'description' => 'Popis',
            'dateMode'    => 'fixed',
            'created'     => '2026-08-26T10:00:00Z',
            'counts'      => ['persons' => 12, 'docs' => 0],
        ];
    }

    public function testFromArrayParsesAllFields(): void
    {
        $m = DatasetManifest::fromArray($this->valid());

        $this->assertSame('web-demo', $m->name);
        $this->assertSame('Demo webu — den účetní', $m->title);
        $this->assertSame('Popis', $m->description);
        $this->assertSame('fixed', $m->dateMode);
        $this->assertSame('2026-08-26T10:00:00Z', $m->created);
        $this->assertSame(['persons' => 12, 'docs' => 0], $m->counts);
    }

    public function testToArrayKeepsDeterministicOrderAndFormatFirst(): void
    {
        $arr = DatasetManifest::fromArray($this->valid())->toArray();

        $this->assertSame(
            ['format', 'name', 'title', 'description', 'dateMode', 'created', 'counts'],
            array_keys($arr),
        );
        $this->assertSame('shpd.dataset.v1', $arr['format']);
    }

    public function testEmptyCountsSerializeAsObject(): void
    {
        $data = $this->valid();
        unset($data['counts'], $data['description']);
        $arr = DatasetManifest::fromArray($data)->toArray();

        $this->assertInstanceOf(\stdClass::class, $arr['counts']);
        $this->assertArrayNotHasKey('description', $arr);
        $this->assertStringContainsString('"counts": {}', json_encode($arr, JSON_PRETTY_PRINT));
    }

    public function testWrongFormatIsRejected(): void
    {
        $data = $this->valid();
        $data['format'] = 'shpd.dataset.v2';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage("format must be 'shpd.dataset.v1'");
        DatasetManifest::fromArray($data);
    }

    public function testMissingFormatIsRejected(): void
    {
        $data = $this->valid();
        unset($data['format']);

        $this->expectException(DatasetException::class);
        DatasetManifest::fromArray($data);
    }

    public function testRelativeDateModeIsNotImplemented(): void
    {
        $data = $this->valid();
        $data['dateMode'] = 'relative';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage("Not implemented: dateMode 'relative'");
        DatasetManifest::fromArray($data);
    }

    public function testInvalidNameIsRejected(): void
    {
        $data = $this->valid();
        $data['name'] = 'Web Demo';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('name');
        DatasetManifest::fromArray($data);
    }

    public function testMissingTitleIsRejected(): void
    {
        $data = $this->valid();
        unset($data['title']);

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('title is required');
        DatasetManifest::fromArray($data);
    }

    public function testInvalidCreatedIsRejected(): void
    {
        $data = $this->valid();
        $data['created'] = 'včera';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('not a valid timestamp');
        DatasetManifest::fromArray($data);
    }

    public function testNegativeCountIsRejected(): void
    {
        $data = $this->valid();
        $data['counts'] = ['persons' => -1];

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('counts.persons');
        DatasetManifest::fromArray($data);
    }

    public function testNonIntegerCountIsRejected(): void
    {
        $data = $this->valid();
        $data['counts'] = ['persons' => '12'];

        $this->expectException(DatasetException::class);
        DatasetManifest::fromArray($data);
    }

    public function testWithCountsReplacesCountsOnly(): void
    {
        $m = DatasetManifest::fromArray($this->valid())->withCounts(['mail' => 3]);

        $this->assertSame(['mail' => 3], $m->counts);
        $this->assertSame('web-demo', $m->name);
    }

    public function testSectionsOrderIsSeedOrder(): void
    {
        $this->assertSame(
            ['setup', 'exchangeRates', 'persons', 'items', 'docs', 'registry', 'mail'],
            DatasetManifest::SECTIONS,
        );
    }

    // ── #103 F2: country / profile / provision ──────────────────────────────

    /** @return array<string, mixed> */
    private function corpus(): array
    {
        return $this->valid() + [
            'country'   => 'cz',
            'profile'   => 'vat-payer',
            'provision' => ['accountingItems' => 'all'],
        ];
    }

    public function testLegacyManifestHasNoCountryProfileOrProvision(): void
    {
        $m = DatasetManifest::fromArray($this->valid());

        $this->assertNull($m->country);
        $this->assertNull($m->profile);
        $this->assertSame([], $m->provision);
        $this->assertNull($m->accountingItemsRequest());
        $this->assertArrayNotHasKey('country', $m->toArray());
        $this->assertArrayNotHasKey('provision', $m->toArray());
    }

    public function testCorpusFieldsAreParsedAndSerializedInOrder(): void
    {
        $m = DatasetManifest::fromArray($this->corpus());

        $this->assertSame('cz', $m->country);
        $this->assertSame('vat-payer', $m->profile);
        $this->assertSame('all', $m->accountingItemsRequest());
        $this->assertSame(
            ['format', 'name', 'title', 'description', 'dateMode', 'created', 'country', 'profile', 'provision', 'counts'],
            array_keys($m->toArray()),
        );
    }

    public function testAccountingItemsListIsNormalised(): void
    {
        $data = $this->corpus();
        $data['provision'] = ['accountingItems' => ['568201', '548100Z']];

        $this->assertSame(['568201', '548100Z'], DatasetManifest::fromArray($data)->accountingItemsRequest());
    }

    public function testEmptyProvisionObjectMeansNoActions(): void
    {
        $data = $this->corpus();
        $data['provision'] = []; // `{}` z JsoncParseru

        $m = DatasetManifest::fromArray($data);
        $this->assertNull($m->accountingItemsRequest());
        $this->assertArrayNotHasKey('provision', $m->toArray());
    }

    public function testWithCountsKeepsCorpusFields(): void
    {
        $m = DatasetManifest::fromArray($this->corpus())->withCounts(['mail' => 20]);

        $this->assertSame('cz', $m->country);
        $this->assertSame('vat-payer', $m->profile);
        $this->assertSame(['accountingItems' => 'all'], $m->provision);
        $this->assertSame(['mail' => 20], $m->counts);
    }

    public function testServerAiProvisionIsNoLongerAKnownAction(): void
    {
        // #126 D9: serverové AI připojení plyne z třídy dat, kterou seed nastaví po resetu.
        $this->assertSame(['accountingItems'], DatasetManifest::PROVISION_KEYS);

        $data = $this->corpus();
        $data['provision'] = ['accountingItems' => 'all', 'serverAi' => true];
        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('provision.serverAi is not a known provisioning action');
        DatasetManifest::fromArray($data);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCorpusFields(): iterable
    {
        yield 'upper-case country' => [['country' => 'CZ'], 'country'];
        yield 'three-letter country' => [['country' => 'cze'], 'country'];
        yield 'numeric country' => [['country' => 12], 'country must be a string'];
        yield 'empty profile' => [['profile' => ''], 'profile'];
        yield 'provision string' => [['provision' => 'all'], 'provision must be an object'];
        yield 'unknown action' => [['provision' => ['foo' => 1]], 'provision.foo'];
        yield 'accountingItems none' => [['provision' => ['accountingItems' => 'none']], 'accountingItems'];
        yield 'accountingItems empty list' => [['provision' => ['accountingItems' => []]], 'accountingItems'];
        yield 'accountingItems mixed list' => [['provision' => ['accountingItems' => ['568201', 5]]], 'accountingItems'];
        yield 'accountingItems map' => [['provision' => ['accountingItems' => ['a' => 'b']]], 'accountingItems'];
        yield 'serverAi gone' => [['provision' => ['serverAi' => false]], 'provision.serverAi'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCorpusFields')]
    public function testInvalidCorpusFieldsAreRejected(array $override, string $message): void
    {
        $data = array_merge($this->corpus(), $override);

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage($message);
        DatasetManifest::fromArray($data);
    }
}
