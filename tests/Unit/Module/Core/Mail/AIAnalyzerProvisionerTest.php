<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;

/**
 * AIAnalyzerProvisioner musí být idempotentní (každý ensure*() je ok volat
 * opakovaně) a respektovat jediné default omezení per DS.
 */
class AIAnalyzerProvisionerTest extends TestCase
{
    private ?string $tempTemplate = null;

    protected function tearDown(): void
    {
        if ($this->tempTemplate !== null) {
            @unlink($this->tempTemplate);
            $this->tempTemplate = null;
        }
    }

    private function writeTemplate(string $version, string $promptBody = 'New prompt body'): string
    {
        $this->tempTemplate = sys_get_temp_dir() . '/shpd_ai_provisioner_' . uniqid() . '.jsonc';
        file_put_contents($this->tempTemplate, json_encode([
            'profile_id' => 'czech_general',
            'name' => 'Obecná analýza pošty (česky)',
            'language' => 'cs',
            'prompt_version' => $version,
            'supported_doc_types' => ['invoiceReceived', 'creditNote', 'other'],
            'confidence_thresholds' => ['ready' => 0.9, 'review' => 0.6],
            'prompt_template' => $promptBody,
            'output_schema' => ['type' => 'object'],
        ], JSON_PRETTY_PRINT));
        return $this->tempTemplate;
    }

    public function testProvisionsAllOnFreshDs(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // 4× fetchRow: user (null), backend (null), backend default (null),
        //              profile (null), profile default (null)
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            null, // user lookup
            null, // backend by backend_id
            null, // backend any default
            null, // profile by profile_id
            null, // profile any default
        );
        $db->method('insertRow')->willReturnOnConsecutiveCalls(
            42, // user id
            17, // backend id
            33, // profile id
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['id' => 42, 'created' => true], $result['user']);
        $this->assertSame(['id' => 17, 'created' => true], $result['backend']);
        $this->assertSame(['id' => 33, 'profile_id' => 'czech_general', 'created' => true], $result['profile']);
    }

    public function testIsIdempotentWhenAllExist(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],   // user
            ['id' => 2],   // backend by backend_id
            ['id' => 3],   // profile by profile_id
        );
        $db->expects($this->never())->method('insertRow');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['id' => 1, 'created' => false], $result['user']);
        $this->assertSame(['id' => 2, 'created' => false], $result['backend']);
        $this->assertSame(['id' => 3, 'profile_id' => 'czech_general', 'created' => false], $result['profile']);
    }

    public function testSkipsBackendWhenAnotherIsDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],                                     // user exists
            null,                                            // backend 'default' missing
            ['id' => 99, 'backend_id' => 'claude-opus'],     // ALE jiný backend je default
            null,                                            // profile lookup
            null,                                            // profile any default
        );
        $db->method('insertRow')->willReturn(50); // pro profile

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(99, $result['backend']['id']);
        $this->assertFalse($result['backend']['created']);
        $this->assertArrayHasKey('skipped_reason', $result['backend']);
        $this->assertStringContainsString('claude-opus', $result['backend']['skipped_reason']);
        // profile by měl odkazovat na existující default backend (id=99)
        $this->assertTrue($result['profile']['created']);
    }

    public function testSkipsProfileWhenAnotherIsDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],                                                // user
            ['id' => 17],                                               // backend default exists
            null,                                                       // profile 'czech_general' missing
            ['id' => 88, 'profile_id' => 'english_invoices'],           // ALE jiný profil je default
        );
        $db->expects($this->never())->method('insertRow');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(88, $result['profile']['id']);
        $this->assertFalse($result['profile']['created']);
        $this->assertArrayHasKey('skipped_reason', $result['profile']);
        $this->assertStringContainsString('english_invoices', $result['profile']['skipped_reason']);
    }

    public function testProvisionedBackendHasNoApiKeyAndIsInactive(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $insertedRows = [];
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$insertedRows): int {
                $insertedRows[$table] = $data;
                return count($insertedRows);
            },
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $provisioner->provision();

        $this->assertArrayHasKey('core_ai_backends', $insertedRows);
        $backend = $insertedRows['core_ai_backends'];
        $this->assertNull($backend['api_key']);
        $this->assertSame(0, $backend['is_active']);
        $this->assertSame(1, $backend['is_default']);
        $this->assertSame('anthropic', $backend['provider']);
        // 0 = nenastaveno; skutečný default žije v provideru analyzéru.
        $this->assertSame(0, $backend['max_tokens']);
        // Přemostění (F0-D8) + teplota NULL = neposílat (F0-D1); thinking
        // a effort nechává na DB defaultu `auto`.
        $this->assertSame('claude-sonnet-4-6', $backend['model']);
        $this->assertNull($backend['temperature']);
        $this->assertArrayNotHasKey('thinking', $backend);
        $this->assertArrayNotHasKey('effort', $backend);
    }

    // --- přepis vyřazených modelů (F0-D8) ------------------------------------

    public function testRetiredReplacementMatchesExactAndDatedIdsOnly(): void
    {
        $this->assertSame('claude-sonnet-4-6', AIAnalyzerProvisioner::retiredReplacement('claude-sonnet-4-5'));
        $this->assertSame('claude-sonnet-4-6', AIAnalyzerProvisioner::retiredReplacement('claude-sonnet-4-5-20250929'));
        $this->assertNull(AIAnalyzerProvisioner::retiredReplacement('claude-sonnet-4-6'));
        $this->assertNull(AIAnalyzerProvisioner::retiredReplacement('claude-sonnet-4-50'));
        $this->assertNull(AIAnalyzerProvisioner::retiredReplacement('claude-opus-4-8'));
        // Prefix platformy (Bedrock) — partnerské platformy mají vlastní termíny.
        $this->assertNull(AIAnalyzerProvisioner::retiredReplacement('anthropic.claude-sonnet-4-5'));
        $this->assertNull(AIAnalyzerProvisioner::retiredReplacement(''));
    }

    public function testRetireModelsRewritesEveryRetiredBackendAndLeavesOthers(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'backend_id' => 'default', 'model' => 'claude-sonnet-4-5'],
            ['id' => 2, 'backend_id' => 'dated', 'model' => 'claude-sonnet-4-5-20250929'],
            ['id' => 3, 'backend_id' => 'bedrock', 'model' => 'anthropic.claude-sonnet-4-5'],
            ['id' => 4, 'backend_id' => 'opus', 'model' => 'claude-opus-4-8'],
            ['id' => 5, 'backend_id' => 'gateway', 'model' => 'claude-sonnet-4-6'],
        ]);
        $updates = [];
        $db->expects($this->exactly(2))->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
                $this->assertSame('core_ai_backends', $table);
                $this->assertSame('id = %i', $where);
                $this->assertArrayHasKey('modified', $data);
                $updates[] = [$params[0], $data['model']];
            },
        );
        $db->expects($this->never())->method('execute');

        $changed = (new AIAnalyzerProvisioner($db))->retireModels();

        $this->assertSame([[1, 'claude-sonnet-4-6'], [2, 'claude-sonnet-4-6']], $updates);
        $this->assertSame([
            ['id' => 1, 'backend_id' => 'default', 'from' => 'claude-sonnet-4-5', 'to' => 'claude-sonnet-4-6'],
            ['id' => 2, 'backend_id' => 'dated', 'from' => 'claude-sonnet-4-5-20250929', 'to' => 'claude-sonnet-4-6'],
        ], $changed);
    }

    public function testRetireModelsSecondRunChangesNothing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'backend_id' => 'default', 'model' => 'claude-sonnet-4-6'],
            ['id' => 2, 'backend_id' => 'dated', 'model' => 'claude-sonnet-4-6'],
        ]);
        $db->expects($this->never())->method('updateWhere');

        $this->assertSame([], (new AIAnalyzerProvisioner($db))->retireModels());
    }

    public function testProvisionReportsRetiredModels(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],   // user
            ['id' => 2],   // backend by backend_id
            ['id' => 3],   // profile by profile_id
        );
        $db->method('fetchAll')->willReturn([
            ['id' => 2, 'backend_id' => 'default', 'model' => 'claude-sonnet-4-5'],
        ]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->expects($this->never())->method('insertRow');

        $result = (new AIAnalyzerProvisioner($db))->provision();

        $this->assertSame(
            [['id' => 2, 'backend_id' => 'default', 'from' => 'claude-sonnet-4-5', 'to' => 'claude-sonnet-4-6']],
            $result['retired'],
        );
        $this->assertSame(['id' => 2, 'created' => false], $result['backend']);
    }

    public function testProvisionedProfileLoadsTemplateAndJsonEncodes(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $insertedRows = [];
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$insertedRows): int {
                $insertedRows[$table] = $data;
                return count($insertedRows);
            },
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $provisioner->provision();

        $this->assertArrayHasKey('core_mail_ai_profiles', $insertedRows);
        $profile = $insertedRows['core_mail_ai_profiles'];

        $this->assertSame('czech_general', $profile['profile_id']);
        $this->assertSame('cs', $profile['language']);
        $this->assertSame('v4.7.1', $profile['prompt_version']);

        // JSON pole musí být validní serializace
        $supportedTypes = json_decode($profile['supported_doc_types'], true);
        $this->assertIsArray($supportedTypes);
        $this->assertContains('invoiceReceived', $supportedTypes);

        $thresholds = json_decode($profile['confidence_thresholds'], true);
        $this->assertSame(0.9, $thresholds['ready']);
        $this->assertSame(0.6, $thresholds['review']);

        $schema = json_decode($profile['output_schema'], true);
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type']);
    }

    public function testProvisionFixesQueuedArchivedMessages(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],   // user
            ['id' => 2],   // backend by backend_id
            ['id' => 3],   // profile by profile_id
        );

        // provision() volá execute 2×: rename legacy profilu + queue fix
        $capturedSqls = [];
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (mixed ...$args) use (&$capturedSqls): void {
                $capturedSqls[] = (string) $args[0];
            });
        $db->method('getAffectedRows')->willReturnOnConsecutiveCalls(
            0,   // rename — žádný legacy profil
            268, // queue fix
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['fixed' => 268], $result['queue_fix']);
        $queueSql = $capturedSqls[1];
        $this->assertStringContainsString('UPDATE core_mail_incoming_messages', $queueSql);
        $this->assertStringContainsString('docState IN %in', $queueSql);
    }

    public function testRenameLegacyProfileRenamesIdAndName(): void
    {
        $db = $this->createMock(DataSourceConnection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (mixed ...$args) use (&$captured): void {
                $captured = $args;
            });
        $db->method('getAffectedRows')->willReturn(1);

        $provisioner = new AIAnalyzerProvisioner($db);
        $renamed = $provisioner->renameLegacyProfile();

        $this->assertSame(1, $renamed);
        $this->assertStringContainsString('UPDATE core_mail_ai_profiles', (string) $captured[0]);
        // params: nové id, nový name, modified, staré id
        $this->assertSame('czech_general', $captured[1]);
        $this->assertSame('Obecná analýza pošty (česky)', $captured[2]);
        $this->assertSame('czech_invoices', $captured[4]);
    }

    public function testProvisionReportsRenameAndSecondRunIsNoop(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],   // user
            ['id' => 2],   // backend by backend_id
            ['id' => 3],   // profile by profile_id (po renamu už existuje)
        );
        $db->method('getAffectedRows')->willReturnOnConsecutiveCalls(
            1, // rename — legacy profil přejmenován
            0, // queue fix
        );
        $db->expects($this->never())->method('insertRow');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['renamed' => 1], $result['profile_rename']);
        $this->assertSame(['id' => 3, 'profile_id' => 'czech_general', 'created' => false], $result['profile']);

        // Druhý běh: rename už nic nematchne.
        $db2 = $this->createMock(DataSourceConnection::class);
        $db2->method('getAffectedRows')->willReturn(0);

        $this->assertSame(0, (new AIAnalyzerProvisioner($db2))->renameLegacyProfile());
    }

    public function testProvisionQueueFixIsNoopOnCleanDs(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        );
        $db->method('getAffectedRows')->willReturn(0);

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['fixed' => 0], $result['queue_fix']);
    }

    public function testSyncUpdatesWhenTemplateIsNewer(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 33, 'prompt_version' => 'v1.0.0']);

        $captured = null;
        $db->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = ['table' => $table, 'data' => $data];
            });

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'));

        $this->assertSame('updated', $result['status']);
        $this->assertSame('czech_general', $result['profile_id']);
        $this->assertSame(33, $result['id']);
        $this->assertSame('v1.0.0', $result['old_version']);
        $this->assertSame('v1.2.0', $result['new_version']);

        $this->assertSame('core_mail_ai_profiles', $captured['table']);
        $data = $captured['data'];
        $this->assertSame('v1.2.0', $data['prompt_version']);
        $this->assertSame('New prompt body', $data['prompt_template']);
        $this->assertSame('cs', $data['language']);
        $this->assertContains('invoiceReceived', json_decode($data['supported_doc_types'], true));
        $this->assertSame('object', json_decode($data['output_schema'], true)['type']);
        $this->assertSame(0.9, json_decode($data['confidence_thresholds'], true)['ready']);
        $this->assertArrayHasKey('modified', $data);

        // Admin-controlled pole se NESMÍ přepisovat.
        $this->assertArrayNotHasKey('name', $data);
        $this->assertArrayNotHasKey('is_default', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('backend', $data);
        $this->assertArrayNotHasKey('max_tokens', $data);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('created', $data);
    }

    public function testSyncIsNoopWhenVersionsMatch(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 33, 'prompt_version' => 'v1.2.0']);
        $db->expects($this->never())->method('updateWhere');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'));

        $this->assertSame('up_to_date', $result['status']);
        $this->assertSame('v1.2.0', $result['old_version']);
    }

    public function testSyncRefusesDowngradeWhenDbIsNewer(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 33, 'prompt_version' => 'v2.0.0']);
        $db->expects($this->never())->method('updateWhere');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'));

        $this->assertSame('db_newer', $result['status']);
        $this->assertSame('v2.0.0', $result['old_version']);
        $this->assertSame('v1.2.0', $result['new_version']);
    }

    public function testSyncReturnsNotFoundWithoutCreating(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->never())->method('updateWhere');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'));

        $this->assertSame(['status' => 'not_found', 'profile_id' => 'czech_general'], $result);
    }

    public function testSyncToleratesVersionPrefixMismatch(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // DB bez "v" prefixu, šablona s ním — pořád jde o upgrade 1.1.0 → 1.2.0
        $db->method('fetchRow')->willReturn(['id' => 33, 'prompt_version' => '1.1.0']);
        $db->expects($this->once())->method('updateWhere');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'));

        $this->assertSame('updated', $result['status']);
    }

    public function testSyncForceWritesEvenAtSameVersion(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 33, 'prompt_version' => 'v1.2.0']);
        $db->expects($this->once())->method('updateWhere');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->syncProfileFromTemplate($this->writeTemplate('v1.2.0'), force: true);

        $this->assertSame('updated', $result['status']);
    }
}
