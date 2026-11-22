<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * Chyba validace dokladu je chyba apply (#166 D21, U6): 422
 * `validation_failed` s nálezy `DocDocument` v `_resolve.issues`, ne
 * `internal_error`; náhled pokynu ukáže tytéž nálezy před uložením.
 *
 * Uložení v harnessu jede jako `TableGateway::saveDocument()` — skutečný
 * `DocsHeadsDocument::validate()` a po něm `beforeSave()`.
 */
final class DocumentApplierDocumentValidationTest extends TestCase
{
    use OutOfScopeHarness;

    /** DB: registrace DPH cz (id 5), číselná řada přijatých faktur (id 7); vlastní firma nenastavená. */
    private function validationDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            return match (true) {
                str_contains($sql, 'economy_codebooks_vat_registrations') => new Row(['id' => 5, 'country' => 'cz']),
                str_contains($sql, 'docs_core_number_series') => new Row(['id' => 7, 'doc_type' => 'invni', 'cash_desk' => null, 'warehouse' => null]),
                default => null,
            };
        });
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchSingle')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    private function applier(): DocumentApplier
    {
        return $this->buildApplier($this->validationDb(), validateOnSave: true);
    }

    /**
     * Přijatá faktura s DPH, řádek s objektem `vat` bez kódu. Bez číselných
     * `totals` — řádek bez kódu daň nenese, součty dokladu dodavatele by
     * v pokynu neprošly kontrolním součtem (#166 D27).
     *
     * @return array<string, mixed>
     */
    private function payloadWithoutRowCode(int $targetDocState): array
    {
        $payload = self::withoutChecksum($this->happyPayload());
        $payload['rows'][0]['vat'] = ['pct' => 21];
        unset($payload['vatRecap'], $payload['rows'][0]['computed']);
        $payload['applyOptions'] = ['targetDocState' => $targetDocState];
        return $payload;
    }

    /** @return list<array{severity: string, path: string, code: string, message: string}> */
    private function issuesByCode(array $canonical, string $code): array
    {
        return array_values(array_filter(
            $canonical['_resolve']['issues'] ?? [],
            static fn (array $issue): bool => ($issue['code'] ?? null) === $code,
        ));
    }

    public function testInstructionApplyToConfirmedStateWithRowWithoutCodeFailsWithDocumentFindings(): void
    {
        $result = $this->applier()->apply($this->payloadWithoutRowCode(40));

        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $missing = $this->issuesByCode($result->canonical, 'vat_code_missing');
        $this->assertCount(1, $missing);
        $this->assertSame(['error', 'rows.0.vat.code'], [$missing[0]['severity'], $missing[0]['path']]);
        $this->assertNull($this->savedHeadsData, 'nic se neuložilo');
    }

    public function testInstructionPreviewShowsTheSameFindingBeforeSaving(): void
    {
        $payload = $this->payloadWithoutRowCode(40);

        $apply = $this->applier()->apply($payload);
        $preview = $this->applier()->preview($payload);

        $this->assertTrue($preview->success, 'náhled vždy uspěje');
        $missing = $this->issuesByCode($preview->canonical, 'vat_code_missing');
        $this->assertCount(1, $missing);
        $this->assertSame(['error', 'rows.0.vat.code'], [$missing[0]['severity'], $missing[0]['path']]);
        $this->assertNull($this->savedHeadsData);

        // Tytéž nálezy dokladu v náhledu i v odmítnutém apply.
        $documentCodes = static fn (array $canonical): array => array_values(array_intersect(
            array_column($canonical['_resolve']['issues'], 'code'),
            ['vat_code_missing', 'no_own_company', 'required', 'no_rows'],
        ));
        $this->assertSame($documentCodes($apply->canonical), $documentCodes($preview->canonical));
    }

    public function testDraftIsSavedAndFindingIsAWarning(): void
    {
        $payload = $this->payloadWithoutRowCode(10);

        $result = $this->applier()->apply($payload);
        $this->assertTrue($result->success, (string) $result->errorMessage);
        $this->assertIsArray($this->savedHeadsData);
        $missing = $this->issuesByCode($result->canonical, 'vat_code_missing');
        $this->assertSame([['warning', 'rows.0.vat.code']], array_map(
            static fn (array $issue): array => [$issue['severity'], $issue['path']],
            $missing,
        ));

        $preview = $this->issuesByCode($this->applier()->preview($payload)->canonical, 'vat_code_missing');
        $this->assertSame(['warning'], array_column($preview, 'severity'));
    }

    /** D21 platí v obou režimech: i návrh vrací 422 s nálezy, ne `internal_error`. */
    public function testProposalApplyAlsoReportsDocumentValidationAsValidationFailed(): void
    {
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['targetDocState' => 40];

        $result = $this->applier()->apply($payload, ApplyMode::Proposal);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $ownCompany = $this->issuesByCode($result->canonical, 'no_own_company');
        $this->assertSame([['error', '_form']], array_map(
            static fn (array $issue): array => [$issue['severity'], $issue['path']],
            $ownCompany,
        ));
    }

    /** Past: náhled návrhu validaci cílového stavu nedostává — review by dostal nové nálezy. */
    public function testProposalPreviewDoesNotValidateTargetState(): void
    {
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['targetDocState' => 40];

        $canonical = $this->applier()->preview($payload, ApplyMode::Proposal)->canonical;

        $this->assertSame([], $this->issuesByCode($canonical, 'no_own_company'));
        $this->assertSame([], $this->issuesByCode($canonical, 'vat_code_missing'));
    }

    /** Varování z úspěšného uložení jde do issues jen v pokynu — výstup apply návrhu se nemění. */
    public function testWarningsFromSuccessfulSaveAreReportedOnlyInInstruction(): void
    {
        $payload = $this->happyPayload();
        unset($payload['rows'][0]['vat'], $payload['rows'][0]['computed'], $payload['vatRecap']);
        $payload['applyOptions'] = ['targetDocState' => 10];

        // Pokyn bez číselných `totals`: řádek bez DPH by neprošel kontrolním součtem (#166 D27).
        $instruction = $this->applier()->apply(self::withoutChecksum($payload));
        $proposal = $this->applier()->apply($payload, ApplyMode::Proposal);

        $this->assertTrue($instruction->success && $proposal->success);
        $this->assertCount(1, $this->issuesByCode($instruction->canonical, 'vat_code_missing'));
        $this->assertSame([], $this->issuesByCode($proposal->canonical, 'vat_code_missing'));
    }

    /** Index řádku v nálezu je index canonicalu, ne pozice na dokladu (vynechaný řádek návrhu). */
    public function testRowFindingPathUsesCanonicalIndexWhenARowIsSkipped(): void
    {
        $payload = $this->hotelPayload();
        $payload['vat'] = ['mode' => 'fromBase', 'place' => 'domestic'];
        $payload['supplier']['vatId'] = 'CZ12345678';
        unset($payload['rows'][1]['vat'], $payload['vatRecap'], $payload['totals']);
        $payload['rows'][0]['vat'] = ['code' => 'cz-110'];
        $payload['_resolve']['rows'][0]['userAction'] = 'skip';
        $payload['applyOptions'] = ['targetDocState' => 40];

        $result = $this->applier()->apply($payload, ApplyMode::Proposal);

        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(['rows.1.vat.code'], array_column($this->issuesByCode($result->canonical, 'vat_code_missing'), 'path'));
    }

    /** Chyba dohledání řady je v náhledu pokynu nález s týmž kódem, jaký vrátí apply. */
    public function testPreviewReportsSaveTargetErrorWithApplyCode(): void
    {
        $payload = $this->payloadWithoutRowCode(10);
        $payload['applyOptions'] += ['numberSeriesCode' => 'A', 'numberSeriesId' => 7];

        $apply = $this->applier()->apply($payload);
        $preview = $this->applier()->preview($payload);

        $this->assertSame('number_series_conflict', $apply->errorCode);
        $conflict = $this->issuesByCode($preview->canonical, 'number_series_conflict');
        $this->assertSame([['error', 'applyOptions.numberSeriesId']], array_map(
            static fn (array $issue): array => [$issue['severity'], $issue['path']],
            $conflict,
        ));
        $this->assertSame([], array_filter(
            $preview->canonical['_resolve']['issues'],
            static fn (array $issue): bool => $issue['path'] === 'number_series',
        ), 'řada bez id se jako nález dokladu nehlásí podruhé');
    }

    /** Reference, kterou apply teprve založí nebo která čeká na rozhodnutí, nález nedostane. */
    public function testPendingReferencesAreNotReported(): void
    {
        $applier = $this->applier();
        $pending = (new \ReflectionMethod($applier, 'pendingReferenceColumns'))->invoke(
            $applier,
            ['selfParty' => 'customer'],
            ['supplier' => ['status' => 'canCreate'], 'rows' => [
                ['index' => 0, 'item' => ['status' => 'canCreate']],
                ['index' => 1],
                ['index' => 2, 'item' => ['status' => 'matched', 'matchedId' => 18]],
            ]],
            ['resolvedRowItems' => [0 => null, 1 => null, 2 => 18]],
            ['partner' => null],
            null,
            [0, 2],
        );

        $this->assertSame(['number_series', 'partner', 'rows.0.item', 'rows.0.operation'], $pending);

        $none = (new \ReflectionMethod($applier, 'pendingReferenceColumns'))->invoke(
            $applier,
            ['selfParty' => 'customer'],
            ['rows' => [['index' => 0]]],
            ['resolvedRowItems' => [0 => null]],
            ['partner' => null],
            7,
            [0],
        );
        $this->assertSame([], $none, 'partner, kterého payload nenese, je skutečný nález');
    }
}
