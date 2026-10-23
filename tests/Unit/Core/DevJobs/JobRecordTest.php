<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevJobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;

final class JobRecordTest extends TestCase
{
    public function testNewIdIsTimeSortableAndMatchesPattern(): void
    {
        $id = JobRecord::newId(new \DateTimeImmutable('2026-10-16 12:34:56', new \DateTimeZone('UTC')));

        $this->assertStringStartsWith('20261016-123456-', $id);
        $this->assertTrue(JobRecord::isValidId($id));
        $this->assertFalse(JobRecord::isValidId('../etc'));
        $this->assertFalse(JobRecord::isValidId('20261016-123456-ABCDEF'));
    }

    public function testRoundTripThroughArray(): void
    {
        $record = new JobRecord(
            '20261016-123456-abc123',
            JobRecord::TYPE_EVAL,
            'aaaa-bbbb-cccc-dddd',
            'Evaluace',
            'cz-vat-payer',
            'baseline',
            ['repeat' => 2, 'maxCostUsd' => 0.5],
            [new JobStep('ai-eval', ['/usr/bin/php', 'shpd-ds', 'ai-eval', '--yes'], '/opt/ds', true)],
            '2026-10-16T12:34:56Z',
            '10.0.0.5',
            900,
        );

        $copy = JobRecord::fromArray($record->toArray());

        $this->assertSame($record->toArray(), $copy->toArray());
        $this->assertSame('baseline', $copy->variant);
        $this->assertTrue($copy->steps[0]->captureStdout);
        $this->assertSame(900, $copy->cancelGraceSeconds);
    }

    public function testRejectsInvalidInput(): void
    {
        $step = new JobStep('x', ['true'], '/tmp');

        $this->expectException(\InvalidArgumentException::class);
        new JobRecord('bad id', JobRecord::TYPE_EVAL, 'ds', 'n', null, null, [], [$step], '2026-01-01T00:00:00Z', null, 0);
    }

    public function testAcceptsWalkthroughType(): void
    {
        $step = new JobStep('dataset-e2e cz-vat-payer', ['/usr/bin/php', 'shpd-ds', 'dataset-e2e', '/repo/tests/datasets/cz/vat-payer', '--json'], '/opt/ds', true);
        $record = new JobRecord('20261016-123456-abc123', JobRecord::TYPE_E2E, 'aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', null, [], [$step], '2026-10-23T09:00:00Z', null, 120);

        $this->assertFalse($record->isServerWide());
        $this->assertTrue($record->isCancellable());
        $this->assertSame('e2e', JobRecord::fromArray($record->toArray())->type);
    }

    public function testRejectsUnknownType(): void
    {
        $step = new JobStep('x', ['true'], '/tmp');

        $this->expectException(\InvalidArgumentException::class);
        new JobRecord('20261016-123456-abc123', 'deploy', 'ds', 'n', null, null, [], [$step], '2026-01-01T00:00:00Z', null, 0);
    }

    public function testRejectsEmptySteps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobRecord('20261016-123456-abc123', JobRecord::TYPE_EVAL, 'ds', 'n', null, null, [], [], '2026-01-01T00:00:00Z', null, 0);
    }

    public function testStepRejectsEmptyArgv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobStep('x', [], '/tmp');
    }

    public function testServerWideUpdateHasNoDataSourceAndCannotBeCancelled(): void
    {
        $step = new JobStep('dev-update', ['/usr/bin/php', 'shpd-server', 'dev-update', '--yes'], '/repo');
        $record = new JobRecord('20261017-120000-abc123', JobRecord::TYPE_UPDATE, '', '', null, null, [], [$step], '2026-10-17T12:00:00Z', '10.0.0.5', 60);

        $this->assertTrue($record->isServerWide());
        $this->assertFalse($record->isCancellable());
        $this->assertNull($record->toArray()['dataSource']);

        $copy = JobRecord::fromArray($record->toArray());
        $this->assertSame($record->toArray(), $copy->toArray());
        $this->assertSame('', $copy->dataSourceId);

        $delete = new JobRecord('20261017-120000-abc124', JobRecord::TYPE_DELETE, 'aaaa-bbbb-cccc-dddd', 'DS', null, null, [], [$step], '2026-10-17T12:00:00Z', null, 60);
        $this->assertFalse($delete->isServerWide());
        $this->assertTrue($delete->isCancellable());
        $this->assertSame(['id' => 'aaaa-bbbb-cccc-dddd', 'name' => 'DS'], $delete->toArray()['dataSource']);
    }

    public function testOnlyServerTypesMayOmitTheDataSource(): void
    {
        $step = new JobStep('x', ['true'], '/tmp');

        $this->expectException(\InvalidArgumentException::class);
        new JobRecord('20261017-120000-abc123', JobRecord::TYPE_DELETE, '', '', null, null, [], [$step], '2026-10-17T12:00:00Z', null, 60);
    }
}
