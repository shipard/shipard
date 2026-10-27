<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Jobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;

/**
 * Recepty úloh nad zdroji dat sad (tasks/testing-phase8a.md § 1): argv
 * a popisky kroků shodné s dashboardem (controllery je volají), grace per
 * typ, `parent` u dílčích úloh kola, id úlohy předem u cest do jejího adresáře.
 */
final class DatasetJobRecipesTest extends TestCase
{
    private const array SRV = ['/usr/bin/php', '/repo/bin/shpd-server'];
    private const array DS = ['/usr/bin/php', '/repo/bin/shpd-ds'];

    private function recipes(?string $parent = null): DatasetJobRecipes
    {
        return new DatasetJobRecipes(
            self::SRV,
            self::DS,
            '/repo',
            '/opt/shipard/data-sources/',
            new JobStore('/run/dev-jobs'),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27T12:00:00Z'),
            '10.0.0.5',
            $parent,
        );
    }

    public function testReseedVerifyAndEvalArgv(): void
    {
        $reseed = $this->recipes()->reseed('aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', '/repo/tests/datasets/cz/vat-payer', ['replaceDataset' => true]);
        $this->assertSame(JobRecord::TYPE_RESEED, $reseed->type);
        $this->assertSame('dataset-seed cz-vat-payer', $reseed->steps[0]->label);
        $this->assertSame([...self::DS, 'dataset-seed', '/repo/tests/datasets/cz/vat-payer', '--yes'], $reseed->steps[0]->argv);
        $this->assertSame('/opt/shipard/data-sources/aaaa-bbbb-cccc-dddd', $reseed->steps[0]->cwd);
        $this->assertFalse($reseed->steps[0]->captureStdout);
        $this->assertSame(['replaceDataset' => true], $reseed->params);
        $this->assertSame(60, $reseed->cancelGraceSeconds);
        $this->assertSame('2026-10-27T12:00:00Z', $reseed->createdAt);
        $this->assertSame('10.0.0.5', $reseed->clientIp);
        $this->assertNull($reseed->parentJobId);
        $this->assertStringStartsWith('20261027-120000-', $reseed->id);

        $verify = $this->recipes()->verify('aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', '/d', false);
        $this->assertSame(JobRecord::TYPE_VERIFY, $verify->type);
        $this->assertSame([...self::DS, 'dataset-verify', '/d', '--json', '--no-apply'], $verify->steps[0]->argv);
        $this->assertTrue($verify->steps[0]->captureStdout);
        $this->assertSame(['apply' => false], $verify->params);
        $this->assertSame([...self::DS, 'dataset-verify', '/d', '--json'], $this->recipes()->verify('aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', '/d', true)->steps[0]->argv);

        $eval = $this->recipes()->eval('aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', '/d', '/repo/tests/eval/variants/baseline.jsonc', 2, ['0V01', '0V02'], 1.5);
        $this->assertSame(JobRecord::TYPE_EVAL, $eval->type);
        $this->assertSame('baseline', $eval->variant);
        $this->assertSame('ai-eval cz-vat-payer / baseline', $eval->steps[0]->label);
        $this->assertSame([
            ...self::DS, 'ai-eval', '/d', '--variant', '/repo/tests/eval/variants/baseline.jsonc', '--repeat', '2', '--only', '0V01,0V02',
            '--yes', '--json', '--max-cost', '1.5', '--progress-file', '/run/dev-jobs/' . $eval->id . '/progress.jsonl',
        ], $eval->steps[0]->argv);
        $this->assertSame(['repeat' => 2, 'only' => ['0V01', '0V02'], 'maxCostUsd' => 1.5], $eval->params);
        $this->assertSame(900, $eval->cancelGraceSeconds);
    }

    public function testCreateRecipesDifferInCronAndSeed(): void
    {
        $eval = $this->recipes()->createSeeded('nnnn-0000-0000-0000', 'Evaluace — Plátce', 'evaluace AI — cz-vat-payer', 'cz-vat-payer', '/d', 'cz');
        $this->assertSame(JobRecord::TYPE_CREATE, $eval->type);
        $this->assertSame('nnnn-0000-0000-0000', $eval->dataSourceId);
        $this->assertSame('Evaluace — Plátce', $eval->dataSourceName);
        $this->assertSame(['ds-create nnnn-0000-0000-0000', 'dataset-seed cz-vat-payer'], array_map(static fn ($s) => $s->label, $eval->steps));
        $this->assertSame([
            ...self::SRV, 'ds-create', '--name', 'Evaluace — Plátce', '--language', 'cs', '--country', 'cz', '--module', 'install.base',
            '--no-cron', '--ds-id', 'nnnn-0000-0000-0000', '--purpose', 'evaluace AI — cz-vat-payer',
        ], $eval->steps[0]->argv);
        $this->assertSame('/repo', $eval->steps[0]->cwd);
        $this->assertSame('/opt/shipard/data-sources/nnnn-0000-0000-0000', $eval->steps[1]->cwd);
        $this->assertSame([], $eval->params);

        $video = $this->recipes()->createVideo('vvvv-0000-0000-0000', 'Videa — Demo', 'videa — it-servis', 'it-servis', null);
        $this->assertSame(['ds-create vvvv-0000-0000-0000', 'ds-upgrade vvvv-0000-0000-0000'], array_map(static fn ($s) => $s->label, $video->steps));
        $this->assertSame([
            ...self::SRV, 'ds-create', '--name', 'Videa — Demo', '--language', 'cs', '--country', 'cz', '--module', 'install.base',
            '--ds-id', 'vvvv-0000-0000-0000', '--purpose', 'videa — it-servis',
        ], $video->steps[0]->argv, 's cronem (bez --no-cron)');
        $this->assertSame([...self::DS, 'ds-upgrade'], $video->steps[1]->argv);
        $this->assertSame(['purpose' => 'videa — it-servis', 'videos' => true], $video->params);

        $this->assertSame('evaluace AI — cz-vat-payer', DatasetJobRecipes::evalPurpose('cz-vat-payer'));
        $this->assertSame('Evaluace — Plátce', DatasetJobRecipes::evalDataSourceName('Plátce'));
        $this->assertSame('Videa — Demo', DatasetJobRecipes::videoDataSourceName('Demo'));
    }

    public function testE2eAndVideoUseTheirOwnJobDirectoryAndCarryTheParent(): void
    {
        $recipes = $this->recipes('20261027-115900-roundx');
        $e2e = $recipes->e2e('aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', '/d', 'http://127.0.0.1');
        $this->assertSame(JobRecord::TYPE_E2E, $e2e->type);
        $this->assertSame('20261027-115900-roundx', $e2e->parentJobId);
        $this->assertSame('20261027-115900-roundx', $e2e->toArray()['parent']);
        $this->assertSame([
            ...self::DS, 'dataset-e2e', '/d', '--json',
            '--progress-file', '/run/dev-jobs/' . $e2e->id . '/progress.jsonl',
            '--output-dir', '/run/dev-jobs/' . $e2e->id . '/e2e',
            '--base-url', 'http://127.0.0.1',
        ], $e2e->steps[0]->argv);
        $this->assertSame(['baseUrl' => 'http://127.0.0.1'], $e2e->params);
        $this->assertSame(120, $e2e->cancelGraceSeconds);

        $video = $recipes->video('vvvv-0000-0000-0000', 'Videa', 'it-servis', 's1-prijata-faktura', '/repo/demo/scenarios/s1-prijata-faktura.jsonc', 'check', 'http://127.0.0.1');
        $this->assertSame(JobRecord::TYPE_VIDEO, $video->type);
        $this->assertSame('video-run s1-prijata-faktura (check)', $video->steps[0]->label);
        $this->assertSame([
            ...self::DS, 'video-run', '/repo/demo/scenarios/s1-prijata-faktura.jsonc', '--verb', 'check', '--json',
            '--progress-file', '/run/dev-jobs/' . $video->id . '/progress.jsonl',
            '--base-url', 'http://127.0.0.1',
        ], $video->steps[0]->argv);
        $this->assertSame(['scenario' => 's1-prijata-faktura', 'verb' => 'check', 'baseUrl' => 'http://127.0.0.1'], $video->params);
        $this->assertSame(180, $video->cancelGraceSeconds);
        $this->assertSame('20261027-115900-roundx', $video->parentJobId);
    }

    public function testHelpers(): void
    {
        $this->assertSame('0.5', DatasetJobRecipes::formatUsd(0.5));
        $this->assertSame('1.275', DatasetJobRecipes::formatUsd(1.275));
        $this->assertSame('2', DatasetJobRecipes::formatUsd(2.0));
        $this->assertSame('0', DatasetJobRecipes::formatUsd(0.0));
        $this->assertSame([...self::DS, 'ai-eval', '/d', '--variant', '/v.jsonc', '--repeat', '1'], DatasetJobRecipes::aiEvalArgv(self::DS, '/d', '/v.jsonc', 1, []));
    }
}
