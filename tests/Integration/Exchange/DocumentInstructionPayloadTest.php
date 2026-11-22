<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripEnvironment;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripFixtures as F;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Případy „payload → uložený doklad“ pro režim pokyn (#166 D24,
 * tasks/doc-apply-instruction-mode.md U8) — tvary, které export nevyrobí,
 * a proto je matice round-tripu ({@see DocumentRoundTripMatrixTest})
 * nepokryje: řádky s objektem `vat` bez kódu (import ze starého Shipardu),
 * místo plnění v rozporu s DIČ dodavatele, převzatá rekapitulace bez kódů,
 * ceny řádků s daní při `fromBase`.
 *
 * Payload vzniká z dokladu pořízeného cestou formuláře (koncept → export)
 * a úpravou toho, co případ zkoumá; po apply se čte uložený doklad
 * z databáze. Fixtury a úklid sdílí s maticí ({@see RoundTripEnvironment}).
 */
class DocumentInstructionPayloadTest extends IntegrationTestCase
{
    use RoundTripEnvironment;

    private int $numbers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRoundTripEnvironment();
    }

    protected function onTearDown(): void
    {
        $this->shutdownRoundTripEnvironment();
    }

    // ── Doklad Bez DPH: nic se neodvozuje ───────────────────────────────────

    public function testNoVatDocumentFromForeignSupplierInImportModeIsSavedAsWritten(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('bez DPH ze zahraničí', [
            'partner'  => $f->person('euSupplier'),
            'vat_mode' => 0,
        ]) + ['rows' => [$f->row(false, 'služby', 1, 300.00, null), $f->row(false, 'doprava', 1, 50.00, null)]]);
        // Tvar importu ze starého Shipardu: objekt `vat` bez kódu na každém řádku.
        $payload = self::withRowVat($payload, ['pct' => 0]);
        $this->assertSame('none', $payload['vat']['mode']);
        $this->assertSame('domestic', $payload['vat']['place']);

        $saved = $this->saved($this->applier->apply($this->options($payload, 40, import: true)));

        $this->assertSame(0, (int) $saved->head['vat_mode']);
        $this->assertSame(0, (int) $saved->head['vat_place'], 'místo plnění z payloadu, ne z DIČ dodavatele');
        $this->assertSame(40, (int) $saved->head['docState']);
        $this->assertSame([null, null], array_column($saved->rows, 'vat_code'));
        $this->assertSame([], $saved->recap);
    }

    public function testNoVatDocumentFromDomesticSupplierGetsNoCodeAndNoRecap(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('bez DPH od neplátce', [
            'partner'  => $f->person('nonPayer'),
            'vat_mode' => 0,
        ]) + ['rows' => [$f->row(false, 'služby', 2, 750.00, null)]]);
        $payload = self::withRowVat($payload, ['pct' => 0]);

        $result = $this->applier->apply($this->options($payload, 40));
        $saved = $this->saved($result);

        $this->assertSame(0, (int) $saved->head['vat_mode']);
        $this->assertSame([null], array_column($saved->rows, 'vat_code'), 'žádný odvozený kód „Osvobozeno“');
        $this->assertSame([], $saved->recap, 'žádný řádek rekapitulace navíc');
        $this->assertSame([], array_intersect(['vat_code_unknown', 'vat_code_derived'], self::codes($result)));
    }

    public function testDomesticPlaceAndCodesAreKeptForSupplierWithForeignVatId(): void
    {
        $f = $this->fixtures;
        $code = $f->vatCode('input', 'domestic', 'standard');
        $payload = $this->payload($f->receivedHead('tuzemské plnění od zahraničního dodavatele', [
            'partner' => $f->person('euSupplier'),
        ]) + ['rows' => [$f->row(false, 'služby', 1, 1000.00, $code)]]);
        $this->assertSame('domestic', $payload['vat']['place']);

        $result = $this->applier->apply($this->options($payload, 40));
        $saved = $this->saved($result);

        $this->assertSame(0, (int) $saved->head['vat_place']);
        $this->assertSame(1, (int) $saved->head['vat_mode']);
        $this->assertSame([$code], array_column($saved->rows, 'vat_code'));
        $this->assertSame([$code], array_column($saved->recap, 'vat_code'));
        $this->assertSame([], array_intersect(['vat_place_derived', 'vat_code_derived'], self::codes($result)));
    }

    // ── Řádek bez kódu DPH: rozhoduje DocDocument ───────────────────────────

    public function testRowWithoutCodeIsRejectedOnConfirmationAndPreviewShowsItFirst(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 40);
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $this->assertTrue($preview->success);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($preview, 'vat_code_missing'));

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    public function testRowWithoutCodeIsSavedAsDraftWithWarning(): void
    {
        $result = $this->applier->apply($this->options($this->payloadWithoutRowCode(), 10));
        $saved = $this->saved($result);

        $this->assertSame(10, (int) $saved->head['docState']);
        $this->assertSame([null], array_column($saved->rows, 'vat_code'));
        $this->assertSame([['warning', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
    }

    /** D22: importní mód výjimku z validace nemá. */
    public function testRowWithoutCodeIsRejectedOnConfirmationEvenInImportMode(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 40, import: true);
        $before = $this->documentCount();

        $result = $this->applier->apply($payload);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
        $this->assertSame($before, $this->documentCount());
    }

    public function testCorrectiveDocumentWithoutReasonIsRejectedInImportMode(): void
    {
        $f = $this->fixtures;
        $code = $f->vatCode('output', 'domestic', 'standard');
        $originalId = $f->saveDocument(
            $f->issuedHead('původní doklad k opravě') + ['rows' => [$f->row(true, 'práce', 1, 1000.00, $code)]],
        );
        $payload = $this->payload($f->issuedHead('opravný doklad bez důvodu', [
            'corrective'        => 1,
            'corrected_doc'     => $originalId,
            'correction_reason' => F::PREFIX . ' sleva',
        ]) + ['rows' => [$f->row(true, 'sleva', -1, 200.00, $code)]]);
        $payload['correction']['reason'] = null;
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40, import: true));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'correction.reason']], self::findings($result, 'correction_reason_required'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Převzatá rekapitulace je záměr ──────────────────────────────────────

    public function testDeclaredRecapWithoutCodeIsAnError(): void
    {
        $payload = $this->declaredRecapPayload();
        unset($payload['vatRecap'][0]['vatCode']);
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'vatRecap.0.vatCode']], self::findings($result, 'vat_recap_code_missing'));
        $this->assertSame($before, $this->documentCount(), 'žádný tichý přepočet z řádků');
    }

    public function testDeclaredRecapWithCodesIsTakenOverEvenWhenRowsDifferByAHeller(): void
    {
        $payload = $this->declaredRecapPayload();
        $this->assertSame('declared', $payload['vat']['recapSource']);

        $saved = $this->saved($this->applier->apply($this->options($payload, 40)));

        $this->assertSame(1, (int) $saved->head['vat_recap_source']);
        $this->assertCount(1, $saved->recap);
        // Řádky dávají daň 21,00; dodavatel vykázal 20,99 — platí rekapitulace.
        $this->assertEqualsWithDelta(20.99, (float) $saved->recap[0]['tax'], 0.001);
        $this->assertEqualsWithDelta(120.98, (float) $saved->head['total_amount'], 0.001);
    }

    public function testUnknownVatCodeIsAnError(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('neznámý kód') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $payload['rows'][0]['vat']['code'] = 'cz-999';
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_unknown'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Režim výpočtu DPH je záměr ──────────────────────────────────────────

    public function testGrossRowPricesWithFromBaseKeepTheDeclaredMode(): void
    {
        $payload = $this->grossRowsPayload();

        $result = $this->applier->apply($this->options($payload, 10));
        $saved = $this->saved($result);

        $this->assertSame(1, (int) $saved->head['vat_mode'], 'režim zůstává Ze základu');
        // 121 Kč je základ: daň se počítá z něj, žádná korekce ze součtů.
        $this->assertEqualsWithDelta(121.00, (float) $saved->head['total_base'], 0.001);
        $this->assertEqualsWithDelta(146.41, (float) $saved->head['total_amount'], 0.001);
        $this->assertSame([], array_intersect(['vat_mode_derived', 'vat_mode_suspect', 'totals_mismatch'], self::codes($result)));
    }

    /** Týž payload v režimu návrh se chová jako dnes — režim se odvodí ze součtů. */
    public function testGrossRowPricesWithFromBaseAreCorrectedInProposalMode(): void
    {
        $payload = $this->options($this->grossRowsPayload(), 10);

        $preview = $this->applier->preview($payload, ApplyMode::Proposal);

        $this->assertContains('vat_mode_derived', self::codes($preview));
        $this->assertSame('fromTotal', $preview->canonical['_resolve']['vat']['mode']['value']);
    }

    public function testImportNumberInProposalModeIsAnError(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('import v návrhu') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40, import: true), ApplyMode::Proposal);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'applyOptions.importNumber']], self::findings($result, 'import_requires_instruction'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Payloady ────────────────────────────────────────────────────────────

    /**
     * Canonical dokladu pořízeného cestou formuláře jako koncept; bez
     * `applyOptions.importNumber` (export konceptu nese zástupné číslo)
     * a bez cílového stavu — ty doplní {@see options()}.
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function payload(array $formData): array
    {
        $canonical = $this->export($this->fixtures->saveDocument($formData, 10));
        unset($canonical['applyOptions']['importNumber'], $canonical['applyOptions']['targetDocState'], $canonical['_resolve']);
        return $canonical;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function options(array $payload, int $targetDocState, bool $import = false): array
    {
        $payload['applyOptions']['targetDocState'] = $targetDocState;
        if ($import) {
            $payload['applyOptions']['importNumber'] = [
                'docNumber'      => F::PREFIX . '-PL-' . (++$this->numbers) . '-' . substr(uniqid(), -6),
                'sequenceNumber' => null,
            ];
        }
        return $payload;
    }

    /**
     * Každému položkovému řádku nastaví objekt `vat` (tvar, který export nevyrobí).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $vat
     * @return array<string, mixed>
     */
    private static function withRowVat(array $payload, array $vat): array
    {
        foreach ($payload['rows'] as $i => $row) {
            $payload['rows'][$i]['vat'] = $vat;
        }
        return $payload;
    }

    /** Přijatá faktura s DPH, řádek se sazbou a bez kódu. @return array<string, mixed> */
    private function payloadWithoutRowCode(): array
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('řádek bez kódu DPH') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        return self::withRowVat($payload, ['pct' => 21]);
    }

    /** Převzatá rekapitulace s haléřovým rozdílem proti řádkům. @return array<string, mixed> */
    private function declaredRecapPayload(): array
    {
        $f = $this->fixtures;
        $code = $f->vatCode('input', 'domestic', 'standard');
        return $this->payload($f->receivedHead('převzatá rekapitulace', ['vat_recap_source' => 1]) + [
            'rows'     => [$f->row(false, 'služby', 3, 33.33, $code)],
            'vatRecap' => [['vat_code' => $code, 'base' => 99.99, 'tax' => 20.99, 'total' => 120.98]],
        ]);
    }

    /** Řádek v ceně s daní při `fromBase`: součet řádků = částka k úhradě. @return array<string, mixed> */
    private function grossRowsPayload(): array
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('ceny s daní při fromBase') + ['rows' => [
            $f->row(false, 'služby', 1, 121.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $this->assertSame('fromBase', $payload['vat']['mode']);
        $payload['totals'] = ['totalBase' => 100.00, 'totalVat' => 21.00, 'totalAmount' => 121.00, 'totalRounding' => 0.0];
        unset($payload['vatRecap']);
        return $payload;
    }

    // ── Čtení výsledku ──────────────────────────────────────────────────────

    private function saved(ApplyResult $result): SavedDocument
    {
        $this->assertTrue(
            $result->success,
            "Apply selhal: {$result->errorCode} — {$result->errorMessage}\n"
                . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
        return SavedDocument::load($this->db, (int) $result->savedId);
    }

    /** Počet dokladů testu (texty nesou prefix fixtur). */
    private function documentCount(): int
    {
        return (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS [n] FROM [docs_core_heads] WHERE [doc_text] LIKE %s',
            F::PREFIX . '%',
        )['n'] ?? 0);
    }

    /** @return list<string> */
    private static function codes(ApplyResult $result): array
    {
        return array_values(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            (array) ($result->canonical['_resolve']['issues'] ?? []),
        ));
    }

    /** @return list<array{0: string, 1: string}> dvojice [severity, path] nálezů s kódem */
    private static function findings(ApplyResult $result, string $code): array
    {
        $out = [];
        foreach ((array) ($result->canonical['_resolve']['issues'] ?? []) as $issue) {
            if (is_array($issue) && ($issue['code'] ?? null) === $code) {
                $out[] = [(string) $issue['severity'], (string) $issue['path']];
            }
        }
        return $out;
    }
}
