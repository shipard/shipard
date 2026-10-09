<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaim;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimException;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimService;

/**
 * Claim zprávy jako služba: kódy chyb v pořadí kontrol, úspěšný claim
 * s dešifrovaným klíčem, `extend()` / `isActive()` nad uvolněným claimem.
 * DsSecretCipher je reálný (tmp secrets.key) — testuje se i stav bez klíče.
 */
class AnalysisClaimServiceTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_claim_svc_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Claim Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    /** @param list<mixed> $dibiFetches Odpovědi `$dibi->fetch()` v pořadí. */
    private function service(array $dibiFetches, array $fetchRows = [], ?\Dibi\Connection &$dibi = null): AnalysisClaimService
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(...$dibiFetches);
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi->method('insert')->willReturn($fluent);
        $dibi->method('update')->willReturn($fluent);
        $dibi->method('getInsertId')->willReturn(77);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        if ($fetchRows !== []) {
            $db->method('fetchRow')->willReturnOnConsecutiveCalls(...$fetchRows);
        }

        return new AnalysisClaimService($db, $this->config);
    }

    private function queuedMessage(int $preprocessState = 0): \Dibi\Row
    {
        return new \Dibi\Row([
            'id' => 42, 'analysis_state' => 10, 'preprocess_state' => $preprocessState, 'profile_override' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function profileRow(): array
    {
        return [
            'id' => 17, 'profile_id' => 'czech', 'backend' => 5,
            'prompt_version' => 'v1', 'prompt_template' => 't',
            'output_schema' => '{}', 'supported_doc_types' => '[]',
            'language' => 'cs', 'confidence_thresholds' => '{}',
        ];
    }

    /** @return array<string, mixed> */
    private function backendRow(?string $apiKey): array
    {
        return [
            'id' => 5, 'backend_id' => 'default', 'provider' => 'anthropic',
            'model' => 'claude', 'api_key' => $apiKey, 'base_url' => null,
            'max_tokens' => 4096, 'temperature' => 0.0, 'is_active' => 1,
        ];
    }

    private function expectClaimError(string $code, int $status, callable $call): void
    {
        try {
            $call();
            $this->fail('expected AnalysisClaimException ' . $code);
        } catch (AnalysisClaimException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function testClampLeaseSeconds(): void
    {
        $this->assertSame(300, AnalysisClaimService::clampLeaseSeconds(null));
        $this->assertSame(60, AnalysisClaimService::clampLeaseSeconds(5));
        $this->assertSame(900, AnalysisClaimService::clampLeaseSeconds(5000));
        $this->assertSame(420, AnalysisClaimService::clampLeaseSeconds(420));
    }

    public function testNotFoundRollsBack(): void
    {
        $service = $this->service([null], [], $dibi);
        $dibi->expects($this->once())->method('rollback');
        $dibi->expects($this->never())->method('commit');

        $this->expectClaimError('NOT_FOUND', 404, fn() => $service->claim(42, 'a', 300));
    }

    public function testWrongStateIsInvalidState(): void
    {
        $service = $this->service([new \Dibi\Row(['id' => 42, 'analysis_state' => 30, 'profile_override' => null])]);

        $this->expectClaimError('INVALID_STATE', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testPreprocessingGateIsInvalidState(): void
    {
        $service = $this->service([$this->queuedMessage(20)]);

        $this->expectClaimError('INVALID_STATE', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testActiveClaimIsAlreadyClaimed(): void
    {
        $service = $this->service([$this->queuedMessage(), new \Dibi\Row(['id' => 99])]);

        $this->expectClaimError('ALREADY_CLAIMED', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testMissingProfileIsNoProfile(): void
    {
        $service = $this->service([$this->queuedMessage(), null], [null]);

        $this->expectClaimError('NO_PROFILE', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testInactiveBackendIsNoBackend(): void
    {
        $service = $this->service([$this->queuedMessage(), null], [$this->profileRow(), null]);

        $this->expectClaimError('NO_BACKEND', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testMissingSecretsKeyIsSecretsUnavailable(): void
    {
        // Bez secrets.key v DS — forConfig() selže ještě před dešifrováním.
        $service = $this->service(
            [$this->queuedMessage(), null],
            [$this->profileRow(), $this->backendRow('whatever')],
        );

        $this->expectClaimError('SECRETS_UNAVAILABLE', 500, fn() => $service->claim(42, 'a', 300));
    }

    public function testEmptyApiKeyIsBackendKeyMissing(): void
    {
        DsSecretCipher::generateKey($this->tmpDir);
        $service = $this->service(
            [$this->queuedMessage(), null],
            [$this->profileRow(), $this->backendRow(null)],
        );

        $this->expectClaimError('BACKEND_KEY_MISSING', 409, fn() => $service->claim(42, 'a', 300));
    }

    public function testCorruptedCiphertextIsBackendKeyCorrupted(): void
    {
        DsSecretCipher::generateKey($this->tmpDir);
        $service = $this->service(
            [$this->queuedMessage(), null],
            [$this->profileRow(), $this->backendRow('not-a-ciphertext')],
        );

        $this->expectClaimError('BACKEND_KEY_CORRUPTED', 500, fn() => $service->claim(42, 'a', 300));
    }

    public function testUnexpectedFailureBecomesInternalErrorWithRollback(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willThrowException(new \RuntimeException('db gone'));
        $dibi->expects($this->once())->method('rollback');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $service = new AnalysisClaimService($db, $this->config);

        try {
            $service->claim(42, 'a', 300);
            $this->fail('expected exception');
        } catch (AnalysisClaimException $e) {
            $this->assertSame('INTERNAL_ERROR', $e->errorCode);
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringNotContainsString('db gone', $e->getMessage());
            $this->assertSame('db gone', $e->getPrevious()?->getMessage());
        }
    }

    public function testSuccessfulClaimReturnsDecryptedKeyAndCommits(): void
    {
        DsSecretCipher::generateKey($this->tmpDir);
        $encrypted = DsSecretCipher::forConfig($this->config)->encrypt('sk-plain');
        $service = $this->service(
            [$this->queuedMessage(), null],
            [$this->profileRow(), $this->backendRow($encrypted)],
            $dibi,
        );
        $dibi->expects($this->once())->method('commit');
        $dibi->expects($this->never())->method('rollback');

        $claim = $service->claim(42, 'internal:host:1', 600);

        $this->assertInstanceOf(AnalysisClaim::class, $claim);
        $this->assertSame(77, $claim->claimId);
        $this->assertSame('sk-plain', $claim->apiKey);
        $this->assertStringStartsWith('ct_', $claim->claimToken);
        $this->assertSame(17, $claim->profileNdx());
        $this->assertSame(5, $claim->backendNdx());
        $this->assertEqualsWithDelta(time() + 600, strtotime($claim->expiresAt), 5);
    }

    public function testRequestedProfileTakesPrecedenceOverOverride(): void
    {
        DsSecretCipher::generateKey($this->tmpDir);
        $encrypted = DsSecretCipher::forConfig($this->config)->encrypt('k');
        $profileArgs = [];
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'preprocess_state' => 0, 'profile_override' => 3]),
            null,
        );
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $dibi->method('insert')->willReturn($fluent);
        $dibi->method('update')->willReturn($fluent);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$args) use (&$profileArgs, $encrypted): ?array {
                if ($args[0] === 'core_mail_ai_profiles') {
                    $profileArgs = $args;
                    return $this->profileRow();
                }
                return $this->backendRow($encrypted);
            },
        );

        new AnalysisClaimService($db, $this->config)->claim(42, 'a', 300, 9);

        $this->assertSame(9, $profileArgs[1], 'vyžádaný profil má přednost před profile_override zprávy');
    }

    public function testExtendReturnsFalseWhenClaimAlreadyReleased(): void
    {
        // Platnost po UPDATE se čte dotazem (isActive), ne z affected rows —
        // MariaDB hlásí 0 i u živého claimu se stejnou expires_at.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(['id' => 77], null);
        $db->expects($this->exactly(2))->method('execute')->with(
            $this->stringContains('AND released = %i'),
            'core_mail_analysis_claims',
            $this->anything(),
            77,
            0,
        );
        $service = new AnalysisClaimService($db, $this->config);

        $this->assertTrue($service->extend(77, 900));
        $this->assertFalse($service->extend(77, 900));
    }

    public function testIsActiveAndFindActive(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(['id' => 77], null, ['id' => 77, 'message' => 42]);
        $service = new AnalysisClaimService($db, $this->config);

        $this->assertTrue($service->isActive(77));
        $this->assertFalse($service->isActive(77));
        $this->assertSame(42, $service->findActive(42)['message']);
    }
}
