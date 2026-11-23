<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\Interpret\InterpretedDocument;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalReport;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\InterpreterFixture;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\OutOfScopeHarness;

/**
 * Vstup návrhu (#166 D53, U1, U5): interpretace → založení chybějících
 * záznamů → pokyn → učení v jedné transakci; odpověď = papírová podoba
 * + `_resolve` + `savedDocId`, cesty do původního payloadu. Že obsah
 * dokladu odpovídá dřívější cestě návrhu, hlídají testy návrhu
 * a `dataset-verify`.
 */
final class DocumentProposalTest extends TestCase
{
    use OutOfScopeHarness;
    use InterpreterFixture;

    /** @var list<string> pořadí volání transakce (begin / commit / rollback) */
    private array $transaction = [];

    /**
     * DB zdroje s registrací DPH `cz` a řadou přijatých faktur; vlastní
     * firma nenastavená — doklad ve stavu V pořádku neprojde validací.
     * `$message` = řádek zprávy pošty (`target_*`), na kterou payload odkazuje.
     *
     * @param array<string, mixed>|null $message
     * @param list<Row> $sourceRows doklady se stejným `source.ref`
     */
    private function proposalDb(?array $message = null, array $sourceRows = []): Connection
    {
        $this->transaction = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args) use ($message): ?Row {
            $sql = (string) ($args[0] ?? '');
            return match (true) {
                str_contains($sql, 'core_mail_incoming_messages') => $message !== null ? new Row($message) : null,
                str_contains($sql, 'economy_codebooks_vat_registrations') => new Row(['id' => 5, 'country' => 'cz']),
                str_contains($sql, 'docs_core_number_series') => new Row(['id' => 7, 'doc_type' => 'invni', 'cash_desk' => null, 'warehouse' => null]),
                default => null,
            };
        });
        $db->method('fetchAll')->willReturnCallback(
            static fn (mixed ...$args): array => str_contains((string) $args[0], '[source_ref]') ? $sourceRows : [],
        );
        $db->method('fetchSingle')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);
        foreach (['begin', 'commit', 'rollback'] as $step) {
            $db->method($step)->willReturnCallback(function () use ($step): void {
                $this->transaction[] = $step;
            });
        }
        return $db;
    }

    /** Dodavatel, kterého adresář nezná — jde k založení. */
    private function unknownSupplier(): PartyResolver
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Dodavatel s.r.o.', 'company_id' => '12345678']));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        return $party;
    }

    /**
     * Gateway osob, který „založí“ osobu 501.
     *
     * @param list<array<string, mixed>> $created data předaná k založení
     */
    private function personsGateway(array &$created): TransactionlessTableGateway
    {
        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->method('saveDocument')->willReturnCallback(function (array $data) use (&$created): DocumentResult {
            $created[] = $data;
            return DocumentResult::ok(['id' => 501] + $data);
        });
        return $persons;
    }

    /** Záznamy resolveru referencí včetně osoby 501, kterou gateway „založí“. */
    private static function recordsWithCreatedPerson(): array
    {
        return array_replace_recursive(self::REFERENCE_RECORDS, ['base_persons_persons' => ['person_id=F00501' => 501]]);
    }

    /** @return list<string> */
    private static function codes(ApplyResult $result): array
    {
        return array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
    }

    // ── Náhled ──────────────────────────────────────────────────────────────

    public function testPreviewAnswersWithThePaperFormAndTheReport(): void
    {
        $result = self::proposalOf($this->buildApplier())->preview($this->happyPayload());

        $this->assertTrue($result->success);
        // Odpověď je papírová podoba, ne hotový doklad.
        $this->assertArrayNotHasKey('ref', $result->canonical['supplier']);
        $this->assertSame('KONZ-001', $result->canonical['rows'][0]['item']['supplierCode']);
        $this->assertArrayNotHasKey('savedDocId', $result->canonical);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame(42, $resolve['supplier']['matchedId']);
        // Částky, které skončí na dokladu, počítá pokyn nad hotovým dokladem.
        $this->assertSame('declared', $resolve['computed']['recapSource']);
        $this->assertSame(0, $resolve['computed']['rows'][0]['index']);
        // Zpráva návrhu pro review.
        $this->assertArrayHasKey('effectiveAccount', $resolve['rows'][0]);
        // Co patří jen pokynu, odpověď návrhu nenese.
        $this->assertArrayNotHasKey('defaults', $resolve);
        $this->assertNotContains('resolve_ignored', self::codes($result));
        $this->assertNull($this->savedHeadsData, 'náhled neukládá');
    }

    public function testSchemaErrorIsReportedBeforeAnythingElse(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'] = 'řádky';
        $proposal = self::proposalOf($this->buildApplier());

        foreach ([$proposal->preview($payload), $proposal->apply($payload)] as $result) {
            $this->assertSame(['schema_invalid', 400], [$result->errorCode, $result->statusCode]);
            $this->assertSame('řádky', $result->canonical['rows'], 'payload, jak přišel');
            $this->assertNotSame([], $result->canonical['_resolve']['issues']);
        }
    }

    /** `/validate` návrhu: schéma a kontroly předlohy, bez hledání záznamů. */
    public function testValidateChecksThePaperFormWithoutLookingAnythingUp(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');
        $proposal = self::proposalOf($this->buildApplier(party: $party));

        $result = $proposal->validate($this->happyPayload());
        $this->assertTrue($result->success, json_encode(self::codes($result)));
        $this->assertSame([], array_diff_key($result->canonical['_resolve'], ['issues' => 1, 'summary' => 1]), 'jen nálezy a souhrn');

        // Předloha bez data vystavení: kontrola papíru, ne výchozí hodnota pokynu.
        $payload = $this->happyPayload();
        unset($payload['dates']['issueDate']);
        $result = $proposal->validate($payload);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertContains('required', self::codes($result));
    }

    // ── Uložení ─────────────────────────────────────────────────────────────

    public function testApplySavesTheFinishedDocumentAsAnInstruction(): void
    {
        $applier = $this->buildApplier($this->proposalDb());
        $payload = $this->happyPayload();
        $payload['source'] = ['kind' => 'aiExtraction', 'message' => 5];

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertSame(1234, $result->savedId);
        $this->assertSame(1234, $result->canonical['savedDocId']);
        $this->assertSame(42, $this->savedHeadsData['partner']);
        $this->assertSame(5, $this->savedHeadsData['source_message']);
        // Odpověď: papírová podoba + zpráva; nic z odpovědi pokynu navíc.
        $this->assertArrayNotHasKey('ref', $result->canonical['supplier']);
        $this->assertArrayNotHasKey('defaults', $result->canonical['_resolve']);
        $this->assertArrayNotHasKey('effectiveAccount', $result->canonical['_resolve']['rows'][0], 'zpráva pro review patří náhledu');
        $this->assertSame(['begin', 'begin', 'commit', 'commit'], $this->transaction, 'pokyn běží uvnitř transakce návrhu');

        // Po uložení: naučený kód dodavatele a vazba zprávy na doklad.
        $sql = array_map(static fn (array $call): string => (string) $call[0], $applier->sqlCalls);
        $this->assertCount(2, $sql);
        $this->assertStringContainsString('INSERT IGNORE INTO [economy_items_supplier_codes]', $sql[0]);
        $this->assertSame([42, 18, 'KONZ-001'], array_slice($applier->sqlCalls[0], 1, 3));
        $this->assertStringContainsString('UPDATE [core_mail_incoming_messages]', $sql[1]);
        $this->assertSame(['docs_core_heads', 1234, 5], array_slice($applier->sqlCalls[1], 1));
    }

    public function testMissingSupplierIsCreatedInsideTheTransactionAndReferenced(): void
    {
        $created = [];
        $applier = $this->buildApplier(
            $this->proposalDb(),
            records: self::recordsWithCreatedPerson(),
            party: $this->unknownSupplier(),
            persons: $this->personsGateway($created),
        );
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal'];

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertCount(1, $created);
        $this->assertSame('12345678', $created[0]['company_id']);
        $this->assertSame(501, $this->savedHeadsData['partner'], 'pokyn dostal referenci založené osoby');
        $this->assertSame(
            ['status' => 'matched', 'matchedId' => 501, 'matchedBy' => 'created'],
            array_intersect_key($result->canonical['_resolve']['supplier'], ['status' => 1, 'matchedId' => 1, 'matchedBy' => 1]),
        );
        // Kód dodavatele se učí k založené osobě.
        $this->assertSame(501, $applier->sqlCalls[0][1]);
    }

    /** `autoCreateMode` strict (výchozí): bez rozhodnutí se nezakládá a neukládá. */
    public function testUndecidedSupplierStopsBeforeTheTransaction(): void
    {
        $created = [];
        $applier = $this->buildApplier(
            $this->proposalDb(),
            records: self::recordsWithCreatedPerson(),
            party: $this->unknownSupplier(),
            persons: $this->personsGateway($created),
        );

        $result = self::proposalOf($applier)->apply($this->happyPayload());

        $this->assertSame(['unresolved_required', 422], [$result->errorCode, $result->statusCode]);
        $this->assertContains('unresolved_required', self::codes($result));
        $this->assertSame('canCreate', $result->canonical['_resolve']['supplier']['status']);
        $this->assertSame([], $created);
        $this->assertSame([], $this->transaction);
        $this->assertNull($this->savedHeadsData);

        // „safe“ zakládá jen s dostatečnými údaji — IČO stačí.
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'safe'];
        $this->assertTrue(self::proposalOf($applier)->apply($payload)->success);
        $this->assertCount(1, $created);
    }

    /** Chyba pokynu vrátí celou transakci — i osobu, kterou návrh založil. */
    public function testInstructionFailureRollsBackCreatedRecords(): void
    {
        $created = [];
        $applier = $this->buildApplier(
            $this->proposalDb(),
            validateOnSave: true,
            records: self::recordsWithCreatedPerson(),
            party: $this->unknownSupplier(),
            persons: $this->personsGateway($created),
        );
        $payload = $this->happyPayload();
        // Cílový stav V pořádku bez vlastní firmy zdroje dat doklad odmítne.
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal', 'targetDocState' => 40];

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertContains('no_own_company', self::codes($result));
        $this->assertCount(1, $created, 'osoba se zakládala uvnitř transakce');
        $this->assertSame('rollback', end($this->transaction));
        $this->assertNotContains('commit', array_slice($this->transaction, -1));
        $this->assertSame(['begin', 'begin', 'rollback', 'rollback'], $this->transaction);
        $this->assertNull($this->savedHeadsData);
        $this->assertSame([], $applier->sqlCalls, 'nic se neučí a zpráva se neváže');
        // Odpověď dál nese papírovou podobu a zprávu; osoba založená není.
        $this->assertSame('canCreate', $result->canonical['_resolve']['supplier']['status']);
        $this->assertArrayNotHasKey('savedDocId', $result->canonical);
    }

    /**
     * Nálezy pokynu nad hotovým dokladem (U1): cesty řádků přes `rowMap`
     * na původní payload; co zpráva návrhu už nese, se neopakuje.
     */
    public function testInstructionIssuesAreMappedThroughRowMapAndNotRepeated(): void
    {
        // Původní řádky 0 a 2 vynechané: hotový doklad má řádky [1, 3].
        $interpreted = new InterpretedDocument(document: [], report: [], pending: [], rowMap: [1, 3], learn: ['supplier' => null, 'codes' => []]);
        $issue = static fn (string $code, string $path, string $severity = 'warning'): array
            => ['severity' => $severity, 'path' => $path, 'code' => $code, 'message' => $code];
        $known = [$issue('partner_doc_number_missing', 'docNumber'), $issue('account_number_checksum', 'supplier.bankAccount.accountNumber')];

        $added = ProposalReport::instructionIssues($interpreted, $known, [
            $issue('partner_doc_number_missing', 'docNumber'),
            $issue('account_number_checksum', 'supplier.bankAccount.accountNumber'),
            $issue('resolve_ignored', '_resolve', 'info'),
            $issue('cash_desk_defaulted', 'cashDesk', 'info'),
            $issue('vat_code_missing', 'rows.0.vat.code'),
            $issue('vat_code_missing', 'rows.1.vat.code'),
            $issue('no_rows', 'rows', 'error'),
        ]);

        $this->assertSame(
            [['cash_desk_defaulted', 'cashDesk'], ['vat_code_missing', 'rows.1.vat.code'], ['vat_code_missing', 'rows.3.vat.code'], ['no_rows', 'rows']],
            array_map(static fn (array $i): array => [$i['code'], $i['path']], $added),
        );
    }

    /** Bloky řádků odpovědi drží původní indexy i s vynechaným řádkem. */
    public function testResponseRowBlocksKeepOriginalIndices(): void
    {
        $applier = $this->buildApplier($this->proposalDb(), validateOnSave: true);
        $payload = $this->hotelPayload();
        // Řádek 0 vynechaný: v hotovém dokladu je původní řádek 1 na pozici 0.
        $payload['_resolve']['rows'][0]['userAction'] = 'skip';
        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success, (string) $result->errorMessage . ' ' . json_encode(self::codes($result)));
        $this->assertCount(1, $this->savedHeadsData['rows']);
        foreach ($result->canonical['_resolve']['issues'] as $issue) {
            $this->assertStringStartsNotWith('rows.0.', (string) $issue['path'], "{$issue['code']} míří na vynechaný řádek");
        }
        $this->assertSame([0, 1], array_column($result->canonical['_resolve']['rows'], 'index'), 'bloky řádků podle původních indexů');
    }

    // ── Idempotence ─────────────────────────────────────────────────────────

    public function testMessageThatAlreadyPointsToADocumentIsNotAppliedAgain(): void
    {
        $applier = $this->buildApplier($this->proposalDb(['target_table_id' => 'docs_core_heads', 'target_row' => 77]));
        $payload = $this->happyPayload();
        $payload['source'] = ['kind' => 'aiExtraction', 'message' => 5];
        // Obsah se neposuzuje — ani schéma.
        $payload['rows'] = 'řádky';

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success);
        $this->assertSame(77, $result->savedId);
        $this->assertSame(77, $result->canonical['savedDocId']);
        $this->assertSame('alreadyApplied', $result->canonical['_resolve']['summary']['status']);
        $this->assertNull($this->savedHeadsData);
        $this->assertSame([], $this->transaction);
    }

    /** Vazba na jiný cíl než doklad (Spisovna) návrh dokladu neblokuje. */
    public function testMessageBoundElsewhereIsApplied(): void
    {
        $applier = $this->buildApplier($this->proposalDb(['target_table_id' => 'base_registry_documents', 'target_row' => 77]));
        $payload = $this->happyPayload();
        $payload['source'] = ['kind' => 'aiExtraction', 'message' => 5];

        $this->assertSame(1234, self::proposalOf($applier)->apply($payload)->savedId);
    }

    public function testExistingSourceRefReturnsTheDocumentWithoutInterpreting(): void
    {
        $existing = new Row(['id' => 77, 'doc_type' => 'invni', 'doc_number' => 'FP-0001', 'source_kind' => 'api', 'source_ref' => 'A-1']);
        $applier = $this->buildApplier($this->proposalDb(sourceRows: [$existing]));
        $payload = $this->happyPayload();
        $payload['source'] = ['kind' => 'api', 'ref' => 'A-1'];
        unset($payload['rows']);

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success);
        $this->assertSame(77, $result->savedId);
        $this->assertSame(['already_applied'], self::codes($result));
        $this->assertSame([], $applier->lockEvents, 'rychlá cesta zámek nebere');
        $this->assertNull($this->savedHeadsData);
    }

    /** Zámek podle `source.ref` drží návrh přes commit celé transakce. */
    public function testSourceLockIsHeldAroundTheWholeTransaction(): void
    {
        $applier = $this->buildApplier($this->proposalDb());
        $payload = $this->happyPayload();
        $payload['source'] = ['kind' => 'api', 'ref' => 'A-1'];

        $result = self::proposalOf($applier)->apply($payload);

        $this->assertTrue($result->success, (string) $result->errorMessage);
        // Vnější dvojice je zámek návrhu, vnitřní zámek pokynu (opakovatelný).
        $this->assertSame(['acquire', 'acquire', 'release', 'release'], $applier->lockEvents);

        $busy = $this->buildApplier($this->proposalDb());
        $busy->lockAvailable = false;
        $result = self::proposalOf($busy)->apply($payload);
        $this->assertSame(['source_ref_busy', 409], [$result->errorCode, $result->statusCode]);
        $this->assertSame(['acquire'], $busy->lockEvents, 'nezískaný zámek se neuvolňuje');
        $this->assertNull($this->savedHeadsData);
    }
}
