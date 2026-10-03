<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Person;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Person\MergeStrategy;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Person\PersonResolveResult;
use Shipard\Module\Core\Exchange\Person\PersonResolver;
use Shipard\Module\Core\Exchange\Person\PersonValidator;
use Shipard\Module\Core\Exchange\Resolve\AddressResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Testable applier — Dibi\Connection::query() is final and cannot be
 * mocked directly. PersonApplier wraps it in protected executeSql() /
 * lastInsertId() so subclasses can intercept.
 */
class TestablePersonApplier extends PersonApplier
{
    /** @var list<array> */
    public array $sqlCalls = [];
    public int $nextInsertId = 1000;

    protected function executeSql(mixed ...$args): mixed
    {
        $this->sqlCalls[] = $args;
        return null;
    }

    protected function lastInsertId(): int
    {
        return $this->nextInsertId++;
    }
}

class PersonApplierTest extends TestCase
{
    // ── validate() ─────────────────────────────────────────────────────────

    public function testValidateRejectsSchemaInvalidPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.persons.person',
            // missing formatVersion, personType, country
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testValidateRejectsMissingFullNameForCompany(): void
    {
        $applier = $this->buildApplier();
        // Empty string fullName — passes schema (string type) but fails
        // PersonValidator (required for company).
        $result = $applier->validate($this->validCompanyPayload([
            'name' => ['fullName' => ''],
        ]));
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
    }

    public function testValidateAcceptsCompleteCompanyPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate($this->validCompanyPayload());
        $this->assertTrue($result->success);
    }

    // ── apply() — happy path create ───────────────────────────────────────

    public function testApplyCreatesNewCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme s.r.o.']),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->willReturn(DocumentResult::ok(['id' => 42]));

        // person_id collision probe (none) + no sub-record inserts (empty
        // sub-collections in payload).
        $db->method('fetch')->willReturn(null);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $applier = $this->buildApplier(
            db: $db, resolver: $resolver, gateway: $gateway,
        );

        $result = $applier->apply($this->validCompanyPayload());
        $this->assertTrue($result->success, "Expected success; got {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame(42, $result->savedId);
        $this->assertSame(42, $result->canonical['savedPersonId']);
    }

    // ── apply() — createOnly + matched → 409 person_exists ────────────────

    public function testApplyCreateOnlyMatchedRejectsWith409(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(99, 'companyId'),
        );

        // Critically — no DB transaction should start when the header
        // reconcile blocks the apply upfront.
        $db->expects($this->never())->method('begin');

        $applier = $this->buildApplier(db: $db, resolver: $resolver);

        $payload = $this->validCompanyPayload([
            'applyOptions' => ['mergeStrategy' => 'createOnly'],
        ]);
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('person_exists', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── apply() — updateHeader uses existing id, leaves subs ──────────────

    public function testApplyUpdateHeaderUpdatesMatchedHeaderAndSkipsSubs(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'companyId'),
        );

        $db->method('fetch')->willReturn(new Row([
            'id' => 50, 'person_type' => 2, 'full_name' => 'Old Name',
            'company_id' => '12345678', 'email' => '',
        ]));

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(function ($payload) {
                return ($payload['id'] ?? null) === 50
                    && ($payload['full_name'] ?? null) === 'Acme s.r.o.';
            }))
            ->willReturn(DocumentResult::ok(['id' => 50]));

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload([
            'applyOptions' => ['mergeStrategy' => 'updateHeader'],
            'addresses'    => [['addressType' => 1, 'street' => 'Hlavní', 'isStandardized' => true]],
        ]));

        $this->assertTrue($result->success, "Expected success; got {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame(50, $result->savedId);
        // No sub-record SQL — sub-collections untouched by updateHeader.
        $this->assertSubRecordSqlAbsent($applier->sqlCalls, 'base_persons_addresses');
        $this->assertSubRecordSqlAbsent($applier->sqlCalls, 'base_persons_contacts');
        $this->assertSubRecordSqlAbsent($applier->sqlCalls, 'base_persons_bank_accounts');
    }

    // ── apply() — mergeAdd + matched provozovna ─→ authoritativeRefresh ────

    public function testApplyMergeAddWithAuthoritativeRefreshUpdatesAddress(): void
    {
        $db = $this->createMock(Connection::class);
        $addrMatch = ResolveResult::matched(100, 'placeRegId', authoritativeRefresh: true);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'companyId'),
            addresses: [$addrMatch],
        );

        $db->method('fetch')->willReturn(new Row(['id' => 50, 'person_type' => 2, 'full_name' => '']));
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 50]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload([
            'applyOptions' => ['mergeStrategy' => 'mergeAdd'],
            'addresses'    => [[
                'addressType' => 3,
                'placeRegType' => 'ICP',
                'placeRegId'   => '1234567890',
                'street'       => 'Nová ulice',
                'city'         => 'Praha',
            ]],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlContains($applier->sqlCalls, 'UPDATE', 'base_persons_addresses');
    }

    // ── apply() — mergeAdd + matched (no authRefresh) → leaves matched ─────

    public function testApplyMergeAddLeavesNonAuthoritativeMatchUntouched(): void
    {
        $db = $this->createMock(Connection::class);
        $addrMatch = ResolveResult::matched(101, 'registryCode');
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'companyId'),
            addresses: [$addrMatch],
        );

        $db->method('fetch')->willReturn(new Row(['id' => 50, 'person_type' => 2, 'full_name' => '']));
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 50]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload([
            'applyOptions' => ['mergeStrategy' => 'mergeAdd'],
            'addresses'    => [[
                'addressType'  => 1,
                'registryCode' => '21794160',
                'street'       => 'Should NOT propagate',
            ]],
        ]));

        $this->assertTrue($result->success);
        $this->assertSubRecordSqlAbsent($applier->sqlCalls, 'base_persons_addresses');
    }

    // ── apply() — fullSync + closingExisting ──────────────────────────────

    public function testApplyFullSyncClosesMissingSubRecords(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'companyId'),
            closingExisting: [
                'addresses'    => [['id' => 200], ['id' => 201]],
                'bankAccounts' => [['id' => 300]],
                'contacts'     => [],
            ],
        );

        $db->method('fetch')->willReturn(new Row(['id' => 50, 'person_type' => 2, 'full_name' => '']));
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 50]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload([
            'applyOptions' => ['mergeStrategy' => 'fullSync'],
        ]));

        $this->assertTrue($result->success);
        $closingCalls = array_filter($applier->sqlCalls, function ($call) {
            $sql = implode(' ', array_filter($call, 'is_string'));
            return str_contains($sql, 'valid_to');
        });
        $this->assertCount(3, $closingCalls, 'Expected one valid_to UPDATE per closing id');
    }

    // ── apply() — person_id_conflict → 409 ────────────────────────────────

    public function testApplyRejectsPersonIdCollisionWith409(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme s.r.o.']),
        );

        // person_id collision probe hits an existing row.
        $db->method('fetch')->willReturn(new Row(['id' => 7]));

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->never())->method('saveDocument');

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('rollback');

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);

        $result = $applier->apply($this->validCompanyPayload([
            'personId' => 'F00042',  // collides with row id=7
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('person_id_conflict', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── apply() — lineage write iff source.kind supplied ──────────────────

    public function testApplyWritesLineageWhenSourceKindSupplied(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme']),
        );

        $db->method('fetch')->willReturn(null);
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload([
            'source' => ['kind' => 'import.ares', 'registryRef' => '12345678'],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlContains($applier->sqlCalls, 'source_kind');
    }

    public function testApplySkipsLineageWhenSourceKindMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme']),
        );

        $db->method('fetch')->willReturn(null);
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, gateway: $gateway);
        $result = $applier->apply($this->validCompanyPayload());  // no `source` block

        $this->assertTrue($result->success);
        foreach ($applier->sqlCalls as $call) {
            $sql = implode(' ', array_filter($call, 'is_string'));
            if (str_contains($sql, 'source_kind')) {
                $this->fail('Lineage UPDATE must not run when source.kind is absent');
            }
        }
    }

    // ── SQL assert helpers ────────────────────────────────────────────────

    /**
     * Serialize an executeSql() invocation into a single string so
     * assertions can match both raw SQL fragments AND column-name keys
     * passed inside an associative-array argument.
     *
     * @param list<array> $sqlCalls
     */
    private function assertSqlContains(array $sqlCalls, string ...$needles): void
    {
        foreach ($sqlCalls as $call) {
            $sql = $this->flattenSql($call);
            $hit = true;
            foreach ($needles as $needle) {
                if (!str_contains($sql, $needle)) { $hit = false; break; }
            }
            if ($hit) return;
        }
        $needleList = implode(' + ', $needles);
        $this->fail("Expected SQL call containing all of: {$needleList}");
    }

    private function flattenSql(array $call): string
    {
        $parts = [];
        foreach ($call as $arg) {
            if (is_string($arg)) {
                $parts[] = $arg;
            } elseif (is_array($arg)) {
                foreach ($arg as $k => $v) {
                    $parts[] = (string) $k;
                    if (is_scalar($v)) $parts[] = (string) $v;
                }
            }
        }
        return implode(' ', $parts);
    }

    /**
     * @param list<array> $sqlCalls
     */
    private function assertSubRecordSqlAbsent(array $sqlCalls, string $table): void
    {
        foreach ($sqlCalls as $call) {
            $sql = implode(' ', array_filter($call, 'is_string'));
            if (str_contains($sql, $table)) {
                $this->fail("Unexpected SQL call touching `{$table}`: {$sql}");
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    // ── blok documents (#94 D9) ────────────────────────────────────────────

    /**
     * Applier nad jednou hlavičkou: `$existing` null = zakládá se nová,
     * jinak se aktualizuje nalezená osoba 50. Vrací payload, který došel
     * do gateway (null = hlavička se neukládala).
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $payload
     * @return array{0: \Shipard\Module\Core\Exchange\Common\ApplyResult, 1: ?array}
     */
    private function applyDocuments(
        ?array $existing,
        array $payload,
        ?PersonValidator $validator = null,
        bool $hasSendAttachmentsMerged = true,
    ): array {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn($existing === null ? null : new Row($existing + [
            'id' => 50, 'person_type' => 2, 'full_name' => 'Acme s.r.o.', 'company_id' => '12345678',
        ]));
        $resolver = $this->stubResolver(
            header: $existing === null
                ? ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme s.r.o.'])
                : ResolveResult::matched(50, 'companyId'),
        );

        $saved = null;
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturnCallback(function (array $data) use (&$saved, $existing) {
            $saved = $data;
            return DocumentResult::ok(['id' => $existing === null ? 42 : 50]);
        });

        $applier = $this->buildApplier(
            db: $db, resolver: $resolver, gateway: $gateway,
            validator: $validator, hasSendAttachmentsMerged: $hasSendAttachmentsMerged,
        );

        return [$applier->apply($this->validCompanyPayload($payload)), $saved];
    }

    public function testCreateWritesDocumentsBlock(): void
    {
        [$result, $saved] = $this->applyDocuments(null, [
            'documents' => ['language' => 'de', 'sendAttachmentsMerged' => true],
        ]);

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertSame('de', $saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);
    }

    public function testCreateWithoutDocumentsBlockLeavesColumnsToDefaults(): void
    {
        [$result, $saved] = $this->applyDocuments(null, []);

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertArrayNotHasKey('language', $saved);
        $this->assertArrayNotHasKey('send_attachments_merged', $saved);
    }

    public function testUpdateWithoutDocumentsBlockKeepsManualSettings(): void
    {
        // Obnova z registru / starší payload: blok chybí → nic se nemění.
        [$result, $saved] = $this->applyDocuments(
            ['language' => 'en', 'send_attachments_merged' => 1],
            ['applyOptions' => ['mergeStrategy' => 'updateHeader']],
        );

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertSame('en', $saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);
    }

    public function testUpdateHeaderWithNullValuesClearsDocumentsSettings(): void
    {
        [$result, $saved] = $this->applyDocuments(
            ['language' => 'en', 'send_attachments_merged' => 1],
            [
                'applyOptions' => ['mergeStrategy' => 'updateHeader'],
                'documents'    => ['language' => null, 'sendAttachmentsMerged' => null],
            ],
        );

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertNull($saved['language']);
        $this->assertSame(0, $saved['send_attachments_merged']);
    }

    public function testFullSyncTreatsMissingLanguageKeyAsNull(): void
    {
        // Exportér prázdný jazyk prořezává — v bloku pak klíč chybí.
        [$result, $saved] = $this->applyDocuments(
            ['language' => 'sk', 'send_attachments_merged' => 0],
            [
                'applyOptions' => ['mergeStrategy' => 'fullSync'],
                'documents'    => ['sendAttachmentsMerged' => true],
            ],
        );

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertNull($saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);
    }

    public function testMergeAddFillsOnlyUnsetDocumentsSettings(): void
    {
        $block = [
            'applyOptions' => ['mergeStrategy' => 'mergeAdd'],
            'documents'    => ['language' => 'de', 'sendAttachmentsMerged' => true],
        ];

        // Jazyk v databázi je → zůstává; spojování vypnuté → zapne se.
        [$result, $saved] = $this->applyDocuments(['language' => 'en', 'send_attachments_merged' => 0], $block);
        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertSame('en', $saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);

        // Jazyk v databázi není → doplní se.
        [, $saved] = $this->applyDocuments(['language' => null, 'send_attachments_merged' => 1], $block);
        $this->assertSame('de', $saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);

        // mergeAdd nic nemaže ani nevypíná.
        [, $saved] = $this->applyDocuments(
            ['language' => 'en', 'send_attachments_merged' => 1],
            ['applyOptions' => ['mergeStrategy' => 'mergeAdd'], 'documents' => ['language' => null, 'sendAttachmentsMerged' => false]],
        );
        $this->assertSame('en', $saved['language']);
        $this->assertSame(1, $saved['send_attachments_merged']);
    }

    public function testLanguageOutsideDocumentLanguagesBlocksApply(): void
    {
        [$result, $saved] = $this->applyDocuments(
            null,
            ['documents' => ['language' => 'fr']],
            validator: new PersonValidator(['cs', 'en', 'sk', 'de']),
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertNull($saved);
        $this->assertStringContainsString('invalid_document_language', (string) json_encode($result->canonical));
    }

    public function testMissingMergeColumnIsIgnoredWithWarning(): void
    {
        // Zdroj dat bez extension docs.core: jazyk se uloží, spojování ne.
        [$result, $saved] = $this->applyDocuments(
            null,
            ['documents' => ['language' => 'sk', 'sendAttachmentsMerged' => true]],
            hasSendAttachmentsMerged: false,
        );

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertSame('sk', $saved['language']);
        $this->assertArrayNotHasKey('send_attachments_merged', $saved);
        $this->assertStringContainsString('column_unavailable', (string) json_encode($result->canonical));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validCompanyPayload(array $overrides = []): array
    {
        return $overrides + [
            'format'        => 'shpd.persons.person',
            'formatVersion' => '1.0',
            'personType'    => 'company',
            'country'       => 'cz',
            'companyId'     => '12345678',
            'name'          => ['fullName' => 'Acme s.r.o.'],
        ];
    }

    /**
     * @param array<int, ResolveResult> $addresses
     * @param array<int, ResolveResult> $bankAccounts
     * @param array<int, ResolveResult> $contacts
     * @param array{addresses: array, bankAccounts: array, contacts: array} $closingExisting
     */
    private function stubResolver(
        ResolveResult $header,
        array $addresses = [],
        array $bankAccounts = [],
        array $contacts = [],
        array $closingExisting = ['addresses' => [], 'bankAccounts' => [], 'contacts' => []],
        array $issues = [],
    ): PersonResolver {
        $resolver = $this->createMock(PersonResolver::class);
        $resolver->method('resolve')->willReturn(new PersonResolveResult(
            header: $header,
            addresses: $addresses,
            bankAccounts: $bankAccounts,
            contacts: $contacts,
            closingExisting: $closingExisting,
            issues: $issues,
        ));
        return $resolver;
    }

    private function buildApplier(
        ?Connection $db = null,
        ?PersonResolver $resolver = null,
        ?TransactionlessTableGateway $gateway = null,
        ?AddressResolver $addressResolver = null,
        ?PersonValidator $validator = null,
        bool $hasSendAttachmentsMerged = true,
    ): PersonApplier {
        $db ??= $this->createMock(Connection::class);
        $resolver ??= $this->stubResolver(ResolveResult::canCreate(['person_type' => 2, 'full_name' => 'Acme']));
        $gateway ??= $this->createMock(TransactionlessTableGateway::class);
        $addressResolver ??= new AddressResolver($db);

        return new TestablePersonApplier(
            db: $db,
            config: $this->createMock(ConfigRuntime::class),
            personsGateway: $gateway,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            personValidator: $validator ?? new PersonValidator(),
            personResolver: $resolver,
            addressResolver: $addressResolver,
            hasSendAttachmentsMerged: $hasSendAttachmentsMerged,
        );
    }
}
