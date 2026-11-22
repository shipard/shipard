<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * Režim **pokyn** (#166 D18–D20, tasks/doc-apply-instruction-mode.md):
 * applier ukládá, co payload říká, a nic neodvozuje ze signálů. Každý test
 * drží jednu položku D19 / D20; kde to jde, ověřuje nad týmž payloadem
 * i návrh, aby bylo vidět, že payload odvození opravdu spouští.
 *
 * Harness {@see OutOfScopeHarness}: skutečný číselník DPH, skutečný
 * `DocsHeadsDocument::beforeSave()`, zdroj s registrací DPH (cz, id 5).
 */
final class DocumentApplierInstructionTest extends TestCase
{
    use OutOfScopeHarness;

    private const HEURISTIC_CODES = ['totals_mismatch', 'rows_recap_mismatch', 'vat_mode_suspect', 'vat_recap_inconsistent'];

    /** Typy s příznakem automatického zaokrouhlení (#73) — harness ho sám nenese. */
    private function docTypes(): array
    {
        return [
            'invno' => ['trade_dir' => 1, 'total_rounding_auto' => true],
            'invni' => ['trade_dir' => 2],
        ];
    }

    private function instruction(array $payload, ?Connection $db = null): ApplyResult
    {
        return $this->buildApplier($db)->apply($payload, ApplyMode::Instruction);
    }

    /** @return array<string, mixed> hlavička posledního uložení po `beforeSave()` */
    private function savedAfter(ApplyResult $result): array
    {
        $this->assertTrue($result->success, (string) $result->errorMessage . ' ' . json_encode($this->issueCodes($result->canonical)));
        $this->assertIsArray($this->savedHeadsData);
        return $this->savedHeadsData;
    }

    // ── D18: výchozí režim ──────────────────────────────────────────────────

    public function testDefaultModeIsInstruction(): void
    {
        $payload = $this->hotelPayload();

        $default = $this->buildApplier()->preview($payload)->canonical['_resolve']['vat'];
        $explicit = $this->buildApplier()->preview($payload, ApplyMode::Instruction)->canonical['_resolve']['vat'];
        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical['_resolve']['vat'];

        $this->assertSame($explicit, $default);
        $this->assertNotSame($proposal['mode'], $default['mode'], 'návrh hotel mimo české DPH přepíná na Bez DPH');
    }

    /** Past U1: jeden applier, týž canonical — náhled návrhu nesmí dostat kontext pokynu a naopak. */
    public function testVatContextCacheIsKeyedByMode(): void
    {
        $applier = $this->buildApplier();
        $payload = $this->hotelPayload();

        $instruction = $applier->preview($payload, ApplyMode::Instruction)->canonical['_resolve']['vat']['mode'];
        $proposal = $applier->preview($payload, ApplyMode::Proposal)->canonical['_resolve']['vat']['mode'];
        $again = $applier->preview($payload, ApplyMode::Instruction)->canonical['_resolve']['vat']['mode'];

        $this->assertSame('fromBase', $instruction['value']);
        $this->assertSame('outOfScope', $proposal['source']);
        $this->assertSame($instruction, $again);
    }

    // ── D19: co v pokynu neběží ─────────────────────────────────────────────

    public function testPlaceIsNotDerivedFromSupplierVatId(): void
    {
        $payload = $this->hotelPayload();
        $payload['vat']['place'] = 'domestic';

        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertSame('vatId', $proposal['_resolve']['vat']['place']['source']);

        $result = $this->instruction($payload);
        $this->assertSame(0, $this->savedAfter($result)['vat_place']);
        $this->assertSame('domestic', $result->canonical['_resolve']['vat']['place']['value']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result->canonical));
    }

    public function testRowWithVatObjectWithoutCodeStaysUnfilled(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['vat'] = ['pct' => 21];
        unset($payload['vatRecap']);

        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertSame('cz-110', $proposal['_resolve']['rows'][0]['vatCode']['createPayload']['code'] ?? null);

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);
        $this->assertArrayNotHasKey('vat_code', $saved['rows'][0]);
        $this->assertArrayNotHasKey('vatCode', $result->canonical['_resolve']['rows'][0]);
        $this->assertSame([], array_intersect(['vat_code_unknown', 'vat_code_derived'], $this->issueCodes($result->canonical)));
    }

    /** Kód v rozporu se signály (tuzemský kód u dodavatele z EU) návrh nahradí, pokyn nechá. */
    public function testRowCodeIsNotReplaced(): void
    {
        $payload = $this->hotelPayload();
        foreach ($payload['rows'] as &$row) {
            $row['vat'] = ['code' => 'cz-110', 'supplyKind' => 'services'];
        }
        unset($row, $payload['vatRecap']);

        $saved = $this->savedAfter($this->instruction($payload));

        $this->assertSame(['cz-110', 'cz-110'], array_column($saved['rows'], 'vat_code'));
        $this->assertSame(1, $saved['vat_place'], 'místo plnění z payloadu (intracom)');
    }

    public function testReceivedDocumentOnSourceWithoutRegistrationIsNotTurnedIntoNonPayerDocument(): void
    {
        $payload = $this->happyPayload();
        $db = $this->dbWithoutVatRegistration();

        $proposal = $this->buildApplier($db)->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertSame('nonPayer', $proposal['_resolve']['vat']['mode']['source']);

        $result = $this->instruction($payload, $db);
        $saved = $this->savedAfter($result);
        $this->assertSame(1, $saved['vat_mode']);
        $this->assertSame('cz-110', $saved['rows'][0]['vat_code']);
        // 10 × 1033,06 — cena zůstává bez daně dodavatele (návrh by ji u neplátce převedl na cenu s daní).
        $this->assertEqualsWithDelta(10330.60, (float) $saved['rows'][0]['total_price'], 0.001);
        $this->assertArrayNotHasKey('vat_registration', $saved);
        $this->assertNotContains('vat_non_payer', $this->issueCodes($result->canonical));
    }

    public function testDocumentWithForeignVatIsNotTurnedIntoDocumentOutsideCzechVat(): void
    {
        $payload = $this->hotelPayload();

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);

        $this->assertSame(1, $saved['vat_mode']);
        $this->assertSame(1, $saved['vat_place']);
        $this->assertEqualsWithDelta(200.00, (float) $saved['rows'][0]['total_price'], 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $saved['rows'][1]['total_price'], 0.001);
        $this->assertSame(0, $saved['vat_recap_source'], 'rekapitulace dodavatele se bez declared nepřebírá');
        $this->assertSame([], array_intersect(
            ['vat_foreign_tax', 'vat_special_place', 'vat_supplier_non_payer', 'recap_source_computed_fallback'],
            $this->issueCodes($result->canonical),
        ));
    }

    public function testVatModeIsNotCorrectedFromTotals(): void
    {
        // Řádek v ceně s daní při fromBase: součet řádků = částka k úhradě.
        $payload = $this->happyPayload();
        $payload['rows'][0]['unitPrice'] = 1250.00;
        $payload['rows'][0]['totalPrice'] = 12500.00;
        unset($payload['rows'][0]['computed']);

        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertContains('vat_mode_derived', $this->issueCodes($proposal));
        $this->assertSame('fromTotal', $proposal['_resolve']['vat']['mode']['value']);

        $result = $this->instruction($payload);
        $this->assertSame(1, $this->savedAfter($result)['vat_mode']);
        $this->assertSame(
            ['value' => 'fromBase', 'source' => 'ai', 'auto' => 'fromBase'],
            $result->canonical['_resolve']['vat']['mode'],
        );
        $this->assertNotContains('vat_mode_derived', $this->issueCodes($result->canonical));
    }

    public function testNoVatDocumentWithReverseChargeIsNotSwitchedToFromBase(): void
    {
        $payload = $this->happyPayload();
        $payload['vat'] = ['mode' => 'none', 'place' => 'domestic', 'reverseCharge' => true];
        $payload['rows'][0]['vat'] = ['pct' => 0, 'reverseChargeCode' => '4'];
        unset($payload['vatRecap'], $payload['rows'][0]['computed']);

        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertSame('fromBase', $proposal['_resolve']['vat']['mode']['value']);

        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame(0, $saved['vat_mode']);
        $this->assertArrayNotHasKey('vat_code', $saved['rows'][0]);
    }

    public function testSupplyKindIsNotReadFromTags(): void
    {
        $payload = $this->coveredPayload();
        $db = $this->db(self::TAGGED_ITEMS);

        $this->buildApplier($db)->preview($payload, ApplyMode::Proposal);
        $this->assertGreaterThan(0, $this->itemTagQueries, 'návrh štítky položek čte');

        $this->itemTagQueries = 0;
        $result = $this->buildApplier($db)->preview($payload, ApplyMode::Instruction);
        $this->assertSame(0, $this->itemTagQueries);
        $this->assertSame([], array_intersect(
            ['supply_kind_derived', 'vat_special_place', 'vat_special_place_ambiguous'],
            $this->issueCodes($result->canonical),
        ));
    }

    public function testRecapSourceIsNotInferredForReceivedDocument(): void
    {
        $payload = $this->happyPayload();

        $proposal = $this->buildApplier()->apply($payload, ApplyMode::Proposal);
        $this->assertSame(1, $this->savedAfter($proposal)['vat_recap_source'], 'návrh rekapitulaci přijatého dokladu přebírá');

        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame(0, $saved['vat_recap_source']);
    }

    /** Rekapitulace se od řádků liší o haléř — převezme se, jak je; žádná kontrola aritmetiky. */
    public function testDeclaredRecapIsTakenOverAsWritten(): void
    {
        $payload = $this->happyPayload();
        $payload['vat']['recapSource'] = 'declared';
        $payload['vatRecap'][0] = ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.43, 'total' => 12500.01];

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);

        $this->assertSame(1, $saved['vat_recap_source']);
        $this->assertNotContains('recap_source_computed_fallback', $this->issueCodes($result->canonical));
    }

    public function testDeclaredRecapWithoutCodeIsAnErrorAndCodeIsNotLookedUpFromRows(): void
    {
        $payload = $this->happyPayload();
        $payload['vat']['recapSource'] = 'declared';
        $payload['vatRecap'][0]['vatCode'] = null;

        $proposal = $this->buildApplier()->apply($payload, ApplyMode::Proposal);
        $this->assertSame(1, $this->savedAfter($proposal)['vat_recap_source'], 'návrh kód dohledá z řádků');

        $this->savedHeadsData = null;
        $result = $this->instruction($payload);
        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $issue = $this->issue($result->canonical, 'vat_recap_code_missing');
        $this->assertSame(['error', 'vatRecap.0.vatCode'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
        $this->assertNull($this->savedHeadsData, 'nic se neuloží');
    }

    public function testDeclaredRecapWithUnknownCodeOrWithoutRowsIsAnError(): void
    {
        $unknown = $this->happyPayload();
        $unknown['vat']['recapSource'] = 'declared';
        $unknown['vatRecap'][0]['vatCode'] = 'cz-999';
        $result = $this->instruction($unknown);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame('vatRecap.0.vatCode', $this->issue($result->canonical, 'vat_code_unknown')['path'] ?? null);

        $empty = $this->happyPayload();
        $empty['vat']['recapSource'] = 'declared';
        $empty['vatRecap'] = [];
        $result = $this->instruction($empty);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame('vatRecap', $this->issue($result->canonical, 'vat_recap_missing')['path'] ?? null);
    }

    public function testTotalRoundingModeIsNotEstimatedFromAmounts(): void
    {
        $payload = $this->roundedReceivedPayload();

        $proposal = $this->savedAfter($this->buildApplier()->apply($payload, ApplyMode::Proposal));
        $this->assertSame(1, $proposal['total_rounding_mode'], 'návrh mód odhadne z částek');

        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame(0, $saved['total_rounding_auto']);
        $this->assertSame(0, $saved['total_rounding_mode']);
        $this->assertSame([12499.66, 0.0], [$saved['total_amount'], $saved['total_rounding']]);
    }

    /** #166 D25, D26: při vypnuté automatice platí mód z payloadu — každý název módu. */
    public function testExplicitTotalRoundingModeIsSavedWhenAutomaticIsOff(): void
    {
        // 12 499,66 → mód: [kód, částka, zaokrouhlení]
        $expected = [
            'cent'     => [0, 12499.66, 0.0],
            'unit'     => [1, 12500.00, 0.34],
            'unitUp'   => [3, 12500.00, 0.34],
            'unitDown' => [4, 12499.00, -0.66],
            'fiveCent' => [5, 12499.65, -0.01],
        ];
        foreach ($expected as $name => [$code, $amount, $rounding]) {
            $payload = $this->roundedReceivedPayload();
            $payload['totals']['totalRoundingMode'] = $name;

            $saved = $this->savedAfter($this->instruction($payload));
            $this->assertSame(
                [0, $code, $amount, $rounding],
                [$saved['total_rounding_auto'], $saved['total_rounding_mode'], $saved['total_amount'], $saved['total_rounding']],
                $name,
            );
        }
    }

    /** #166 D26: automatika zapnutá a mód uvedený → platí automatika, mód dopočítá doklad. */
    public function testExplicitTotalRoundingModeYieldsToAutomatic(): void
    {
        $payload = $this->issuedPayload();
        $payload['totals']['totalRoundingMode'] = 'unitUp';

        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame(1, $saved['total_rounding_auto']);
        $this->assertNotSame(3, $saved['total_rounding_mode'] ?? 0, 'mód z payloadu se při automatice na doklad nedostane');

        $payload['totals']['totalRoundingAuto'] = false;
        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame([0, 3], [$saved['total_rounding_auto'], $saved['total_rounding_mode']]);
    }

    /** #166 D26: `vat.roundingMode` z payloadu, chybí = na haléře. */
    public function testVatRoundingModeComesFromPayload(): void
    {
        $payload = $this->roundedReceivedPayload();
        unset($payload['vatRecap']);
        $this->assertSame(0, $this->savedAfter($this->instruction($payload))['vat_rounding_mode'] ?? 0);

        $payload['vat']['roundingMode'] = 'unit';
        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame(1, $saved['vat_rounding_mode']);
        // 10 330,30 × 21 % = 2 169,36 → daň na koruny 2 169,00.
        $this->assertSame([2169.0, 12499.30], [$saved['total_vat'], $saved['total_amount']]);

        $payload['vat']['roundingMode'] = 'cent';
        $this->assertSame(0, $this->savedAfter($this->instruction($payload))['vat_rounding_mode']);
    }

    /** #166 D28: návrh odhaduje jen bez uvedeného módu; uvedený má přednost i proti částkám. */
    public function testExplicitRoundingModesWinOverEstimateInProposal(): void
    {
        $payload = $this->roundedReceivedPayload();
        $applier = $this->buildApplier();

        $payload['totals']['totalRoundingMode'] = 'unitUp';
        $this->assertSame(3, $this->savedAfter($applier->apply($payload, ApplyMode::Proposal))['total_rounding_mode']);

        $payload['totals']['totalRoundingMode'] = 'cent';
        $saved = $this->savedAfter($applier->apply($payload, ApplyMode::Proposal));
        $this->assertSame([0, 12499.66], [$saved['total_rounding_mode'], $saved['total_amount']], 'odhad z částek by dal mód 1');

        $payload['totals']['totalRoundingMode'] = null;
        $payload['vat']['roundingMode'] = 'unit';
        $saved = $this->savedAfter($applier->apply($payload, ApplyMode::Proposal));
        $this->assertSame([1, 1], [$saved['total_rounding_mode'], $saved['vat_rounding_mode']], 'null = neuvedeno, odhad zůstává');
    }

    /** U4: automatika nezávisí na `totals` — chybí = zapnuto tam, kde platí; výslovná hodnota vyhrává. */
    public function testTotalRoundingAutoDoesNotDependOnDeclaredTotals(): void
    {
        $payload = $this->issuedPayload();

        $proposal = $this->savedAfter($this->buildApplier()->apply($payload, ApplyMode::Proposal));
        $this->assertSame(0, $proposal['total_rounding_auto'], 'návrh s deklarovanou částkou automatiku vypíná');

        $this->assertSame(1, $this->savedAfter($this->instruction($payload))['total_rounding_auto']);

        $payload['totals']['totalRoundingAuto'] = false;
        $saved = $this->savedAfter($this->instruction($payload));
        $this->assertSame([0, 0], [$saved['total_rounding_auto'], $saved['total_rounding_mode']]);
    }

    public function testRowSkipFromResolveIsIgnored(): void
    {
        $payload = $this->hotelPayload();
        $payload['_resolve']['rows'][0]['userAction'] = 'skip';

        $this->assertCount(1, $this->savedAfter($this->buildApplier()->apply($payload, ApplyMode::Proposal))['rows']);
        $this->assertCount(2, $this->savedAfter($this->instruction($payload))['rows']);
    }

    public function testHeuristicValidatorWarningsDoNotRun(): void
    {
        $payload = $this->happyPayload();
        $payload['totals']['totalAmount'] = 99999.00;
        $payload['vatRecap'][0]['tax'] = 1.00;
        $payload['vatRecap'][0]['base'] = 5.00;
        $applier = $this->buildApplier();

        $proposal = $this->issueCodes($applier->validate($payload, ApplyMode::Proposal)->canonical);
        $this->assertGreaterThanOrEqual(2, count(array_intersect(self::HEURISTIC_CODES, $proposal)));

        foreach ([$applier->validate($payload), $applier->preview($payload), $applier->apply($payload)] as $result) {
            $this->assertSame([], array_intersect(self::HEURISTIC_CODES, $this->issueCodes($result->canonical)));
        }
    }

    /** U5: tvar hodnot se normalizuje, bankovní spojení se neopravuje; nález tvaru zůstává varováním. */
    public function testBankAccountIsNotRepairedButShapesAreNormalized(): void
    {
        $payload = $this->happyPayload();
        $payload['supplier']['bankAccount'] = [
            'accountNumber' => '2308417557/2010', 'iban' => 'CZ8520100000002308417558', 'bic' => 'FIOBCZPP', 'currency' => 'CZK',
        ];

        $proposal = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertSame('CZ8520100000002308417557', $proposal['supplier']['bankAccount']['iban']);

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Instruction)->canonical;
        $this->assertSame('CZ8520100000002308417558', $canonical['supplier']['bankAccount']['iban']);
        $this->assertArrayNotHasKey('valueRepairs', $canonical['_resolve']);
        $this->assertNotContains('bank_account_repaired', $this->issueCodes($canonical));
        $this->assertSame('warning', $this->issue($canonical, 'iban_invalid')['severity'] ?? null);
        $this->assertSame('cz', $canonical['supplier']['country'], 'tvar hodnot se normalizuje i v pokynu');
    }

    /** D6: učení dodavatelských kódů patří interpretaci. */
    public function testSupplierCodeMappingIsNotLearned(): void
    {
        $learned = static fn (DocumentApplier $applier): array => array_values(array_filter(
            $applier->sqlCalls,
            static fn (array $call): bool => str_contains((string) ($call[0] ?? ''), 'economy_items_supplier_codes'),
        ));

        $proposal = $this->buildApplier();
        $this->assertTrue($proposal->apply($this->happyPayload(), ApplyMode::Proposal)->success);
        $this->assertNotSame([], $learned($proposal), 'návrh mapování kódu dodavatele zapisuje');

        $instruction = $this->buildApplier();
        $this->assertTrue($instruction->apply($this->happyPayload())->success);
        $this->assertSame([], $learned($instruction));
    }

    public function testVatPinsFromResolveAreIgnoredWithInfo(): void
    {
        $payload = $this->withPins($this->hotelPayload(), [
            'vat.place'       => 'useValue:domestic',
            'vat.mode'        => 'useValue:none',
            'rows[0].vatCode' => 'useCode:cz-218',
        ]);
        $payload['_resolve']['rows'][1]['reverseChargeCode']['userAction'] = 'useValue:4';
        $payload['_resolve']['rows'][1]['vatCode']['userAction'] = 'nesmysl';

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Instruction)->canonical;

        $ignored = array_values(array_filter(
            $canonical['_resolve']['issues'],
            static fn (array $issue): bool => $issue['code'] === 'vat_pin_ignored',
        ));
        $this->assertSame(['info'], array_values(array_unique(array_column($ignored, 'severity'))));
        $paths = array_column($ignored, 'path');
        sort($paths);
        $this->assertSame(
            ['rows.0.vat.code', 'rows.1.vat.code', 'rows.1.vat.reverseChargeCode', 'vat.mode', 'vat.place'],
            $paths,
        );
        $this->assertNotContains('vat_pin_invalid', $this->issueCodes($canonical), 'neplatná volba se v pokynu nevaliduje');
        $this->assertSame('intracom', $canonical['_resolve']['vat']['place']['value']);
        $this->assertSame('fromBase', $canonical['_resolve']['vat']['mode']['value']);
        $this->assertArrayNotHasKey('vatCodeOptions', $canonical['_resolve']);
    }

    // ── D20: hodnoty DPH jsou záměr ─────────────────────────────────────────

    public function testMissingPlaceAndModeDefaultToDomesticFromBase(): void
    {
        $payload = $this->hotelPayload();
        unset($payload['vat'], $payload['vatRecap']);

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);

        $this->assertSame([0, 1], [$saved['vat_place'], $saved['vat_mode']]);
        $vat = $result->canonical['_resolve']['vat'];
        $this->assertSame(['domestic', 'default'], [$vat['place']['value'], $vat['place']['source']]);
        $this->assertSame(['fromBase', 'default'], [$vat['mode']['value'], $vat['mode']['source']]);
    }

    public function testUnknownRowCodeIsAnError(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['vat'] = ['code' => 'cz-999', 'pct' => 21];
        unset($payload['vatRecap']);

        $result = $this->instruction($payload);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $issue = $this->issue($result->canonical, 'vat_code_unknown');
        $this->assertSame(['error', 'rows.0.vat.code'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
        $this->assertNull($this->savedHeadsData);
    }

    public function testRowPctFromPayloadIsNotUsed(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['vat'] = ['code' => 'cz-110', 'pct' => 5];
        unset($payload['vatRecap']);

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);

        $this->assertEqualsWithDelta(21.0, (float) $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['pct'], 0.001);
        $this->assertEqualsWithDelta(21.0, (float) $saved['rows'][0]['vat_pct'], 0.001);
    }

    /** U3: doklad, který říká Bez DPH, kódy řádků nenese — ani se neověřují. */
    public function testNoVatDocumentDoesNotCarryRowCodes(): void
    {
        $payload = $this->happyPayload();
        $payload['vat'] = ['mode' => 'none'];
        $payload['rows'][0]['vat'] = ['code' => 'cz-999', 'pct' => 0];
        unset($payload['vatRecap'], $payload['rows'][0]['computed']);

        $result = $this->instruction($payload);
        $saved = $this->savedAfter($result);

        $this->assertSame(0, $saved['vat_mode']);
        $this->assertArrayNotHasKey('vat_code', $saved['rows'][0]);
        $this->assertArrayNotHasKey('vat_registration', $saved, 'bez vat.registrationCountry doklad Bez DPH registraci nedostane');
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($result->canonical));
    }

    public function testRegistrationFollowsDeclaredCountryValidAtDocumentDate(): void
    {
        [$db, $queries] = $this->registrationDb();
        $payload = $this->happyPayload();

        $ctx = $this->vatContextOf($this->buildApplier($db), $payload);

        $this->assertSame([5, 'cz'], [$ctx['ownRegistrationId'], $ctx['ownCountry']]);
        $this->assertCount(1, $queries->list);
        $this->assertStringContainsString('[country] = %s', $queries->list[0][0]);
        $this->assertStringContainsString('[valid_from]', $queries->list[0][0]);
        $this->assertSame('cz', $queries->list[0][1]);
        $this->assertSame('2026-04-15', $queries->list[0][5]);
    }

    public function testRegistrationDefaultsToFirstRegistrationLikeTheForm(): void
    {
        [$db, $queries] = $this->registrationDb();
        $payload = $this->happyPayload();
        unset($payload['vat']['registrationCountry']);

        $ctx = $this->vatContextOf($this->buildApplier($db), $payload);

        $this->assertSame(5, $ctx['ownRegistrationId']);
        $this->assertStringContainsString('ORDER BY [country], [id]', $queries->list[0][0]);
        $this->assertStringContainsString('[valid_from]', $queries->list[0][0]);
    }

    /** D22: importní mód platnost registrace k datu nezkoumá. */
    public function testImportModeTakesRegistrationRegardlessOfValidity(): void
    {
        [$db, $queries] = $this->registrationDb();
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]];

        $declared = $this->vatContextOf($this->buildApplier($db), $payload);
        unset($payload['vat']['registrationCountry']);
        $default = $this->vatContextOf($this->buildApplier($db), $payload);

        $this->assertSame([5, 5], [$declared['ownRegistrationId'], $default['ownRegistrationId']]);
        $this->assertCount(2, $queries->list);
        foreach ($queries->list as $query) {
            $this->assertStringNotContainsString('[valid_from]', $query[0]);
        }
    }

    /** D22: `importNumber` v režimu návrh je chyba validace — nic se neuloží ani nenahlédne jako import. */
    public function testImportNumberInProposalModeIsRejected(): void
    {
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]];
        $applier = $this->buildApplier();

        foreach ([$applier->validate($payload, ApplyMode::Proposal), $applier->apply($payload, ApplyMode::Proposal)] as $result) {
            $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
            $issue = $this->issue($result->canonical, 'import_requires_instruction');
            $this->assertSame(['error', 'applyOptions.importNumber'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
        }
        $this->assertNull($this->savedHeadsData);
        $this->assertContains(
            'import_requires_instruction',
            $this->issueCodes($applier->preview($payload, ApplyMode::Proposal)->canonical),
        );
        $this->assertNotContains(
            'import_requires_instruction',
            $this->issueCodes($applier->validate($payload)->canonical),
        );
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /**
     * Vydaná faktura z happy fixture: strany prohozené, částka deklarovaná.
     *
     * @return array<string, mixed>
     */
    /**
     * Přijatá faktura zaokrouhlená na koruny (12 499,66 → 12 500,00);
     * automatika u přijatého dokladu neplatí.
     *
     * @return array<string, mixed>
     */
    private function roundedReceivedPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['unitPrice'] = 1033.03;
        $payload['rows'][0]['totalPrice'] = 10330.30;
        unset($payload['rows'][0]['computed']);
        $payload['vatRecap'][0] = ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 10330.30, 'tax' => 2169.36, 'total' => 12499.66];
        $payload['totals'] = ['totalBase' => 10330.30, 'totalVat' => 2169.36, 'totalAmount' => 12500.00, 'totalRounding' => 0.34];
        return $payload;
    }

    private function issuedPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['docType'] = 'invoiceIssued';
        $payload['selfParty'] = 'supplier';
        $payload['customer'] = $payload['supplier'];
        unset($payload['customer']['bankAccount'], $payload['customer']['paymentTermDays']);
        $payload['supplier'] = null;
        unset($payload['docNumber'], $payload['vatRecap']);
        return $payload;
    }

    /**
     * DB, která zapisuje dotazy na registrace DPH; vrací registraci cz, id 5.
     *
     * @return array{0: Connection, 1: object{list: list<array<int, mixed>>}}
     */
    private function registrationDb(): array
    {
        $queries = new class {
            /** @var list<array<int, mixed>> */
            public array $list = [];
        };
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args) use ($queries): ?Row {
            if (!str_contains((string) ($args[0] ?? ''), 'economy_codebooks_vat_registrations')) {
                return null;
            }
            $queries->list[] = $args;
            return new Row(['id' => 5, 'country' => 'cz']);
        });
        $db->method('fetchAll')->willReturn([]);
        return [$db, $queries];
    }

    /** @return array<string, mixed> */
    private function vatContextOf(DocumentApplier $applier, array $payload): array
    {
        return (new \ReflectionMethod($applier, 'vatContext'))->invoke($applier, $payload, ApplyMode::Instruction);
    }
}
