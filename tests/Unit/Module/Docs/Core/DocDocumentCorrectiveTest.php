<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Opravný daňový doklad v dokumentu (#92 D1–D5, tasks/corrective-doc-core.md
 * U2–U5): vynulování sloupců opravy na typu bez příznaku a při vypnutém
 * příznaku, denormalizace čísla a data plnění z původního dokladu, validace
 * odkazu (vždy) a důvodu opravy (při potvrzení mimo import mód).
 */
class DocDocumentCorrectiveTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../..';

    /** Typ dokladu, který mock DB vrací pro číselnou řadu 1 (validate ho denormalizuje z řady). */
    private string $seriesDocType = 'invno';

    /** Původní doklad, který mock DB vrací pro `corrected_doc`; null = neexistuje. */
    private ?array $original = [
        'id'         => 50,
        'doc_type'   => 'invno',
        'docState'   => 40,
        'partner'    => 5,
        'doc_number' => '126A0007',
        'vat_duzp'   => '2025-12-20',
        'issue_date' => '2025-12-22',
    ];

    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes' => JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/docTypes.jsonc'),
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    /** Mock DB: řada → typ, původní doklad podle sloupců dotazu, vlastní firma; zápisy chytá fixture. */
    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql): ?Row {
            if (str_contains($sql, 'docs_core_number_series')) {
                return new Row(['doc_type' => $this->seriesDocType, 'cash_desk' => null, 'warehouse' => null]);
            }
            if (str_contains($sql, '[doc_type], [docState], [partner]')) {
                return $this->original !== null ? new Row($this->original) : null;
            }
            return new Row(['id' => 1]);
        });
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function doc(bool $withConfig = true, bool $withDb = true): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        if ($withDb) {
            $doc->setDb($this->db());
        }
        if ($withConfig) {
            $doc->setConfig($this->config());
        }
        return $doc;
    }

    /** Opravný doklad s odkazem na původní doklad a ručně vyplněnými hodnotami, které má dokument přepsat. */
    private function data(array $extra = []): array
    {
        return array_merge([
            'doc_type'             => 'invno',
            'number_series'        => 1,
            'issue_date'           => '2026-05-06',
            'accounting_date'      => '2026-05-06',
            'vat_duzp'             => '2026-05-06',
            'vat_mode'             => 0,
            'vat_recap_source'     => 0,
            'partner'              => 5,
            'doc_currency'         => 'czk',
            'home_currency'        => 'czk',
            'exchange_rate'        => 1.0,
            'payment_method'       => 1,
            'total_rounding_auto'  => 1,
            'corrective'           => 1,
            'corrected_doc'        => 50,
            'corrected_doc_number' => 'ručně',
            'corrected_doc_date'   => '2020-01-01',
            'correction_reason'    => 'Vrácené zboží',
            'docState'             => 10,
            'rows'                 => [[
                'row_kind' => 1, 'quantity' => -1, 'unit_price' => 100.0, 'price_calc_mode' => 0,
            ]],
        ], $extra);
    }

    /** `validate()` bere data referencí — obal pro přímé předání výrazu. */
    private function validate(TestableDocsHeadsDocument $doc, array $data): ValidationResult
    {
        return $doc->validate($data);
    }

    /** @return list<string> kódy chyb opravy (`correct…`) */
    private function errorCodes(ValidationResult $result): array
    {
        return array_values(array_filter(
            array_map(static fn ($e) => $e->toArray()['code'], $result->getErrors()),
            static fn (string $code) => str_starts_with($code, 'correct'),
        ));
    }

    /** @return list<string> kódy varování opravy */
    private function warningCodes(ValidationResult $result): array
    {
        return array_values(array_filter(
            array_map(static fn ($w) => $w->toArray()['code'], $result->getWarnings()),
            static fn (string $code) => str_starts_with($code, 'correct'),
        ));
    }

    // ── applyCorrectiveColumns() — U2, U3 ───────────────────────────────

    public function testLinkedOriginalOverwritesNumberAndTaxPointDate(): void
    {
        $data = $this->data();
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame(1, $data['corrective']);
        $this->assertSame('126A0007', $data['corrected_doc_number'], 'číslo z původního dokladu přepíše ruční');
        $this->assertSame('2025-12-20', $data['corrected_doc_date'], 'datum plnění = DUZP původního dokladu');
        $this->assertSame('Vrácené zboží', $data['correction_reason']);
    }

    public function testOriginalWithoutDuzpUsesIssueDateAndDibiDatesAreFormatted(): void
    {
        $this->original['vat_duzp'] = null;
        $this->original['issue_date'] = new \DateTimeImmutable('2025-12-22 00:00:00');
        $data = $this->data();
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame('2025-12-22', $data['corrected_doc_date']);
    }

    public function testWithoutLinkManualValuesStay(): void
    {
        $data = $this->data(['corrected_doc' => null]);
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame('ručně', $data['corrected_doc_number'], 'souhrnný opravný doklad: ruční číslo');
        $this->assertSame('2020-01-01', $data['corrected_doc_date']);
    }

    public function testMissingOriginalLeavesValuesUntouched(): void
    {
        $this->original = null;
        $data = $this->data();
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame('ručně', $data['corrected_doc_number'], 'neexistující odkaz hlásí validate, hodnoty se nemění');
        $this->assertSame(50, $data['corrected_doc']);
    }

    public function testFlagOffClearsCorrectionColumns(): void
    {
        foreach ([0, false, '', null] as $off) {
            $data = $this->data(['corrective' => $off]);
            $this->doc()->applyCorrectiveColumnsPub($data);

            $this->assertSame(0, $data['corrective'], var_export($off, true));
            foreach (['corrected_doc', 'corrected_doc_number', 'corrected_doc_date', 'correction_reason'] as $column) {
                $this->assertNull($data[$column], "{$column} při vypnutém příznaku");
            }
        }
    }

    public function testMissingFlagKeyChangesNothing(): void
    {
        $data = $this->data();
        unset($data['corrective']);
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertArrayNotHasKey('corrective', $data, 'uložení bez formuláře klíč nedoplňuje');
        $this->assertSame('ručně', $data['corrected_doc_number']);
        $this->assertSame(50, $data['corrected_doc']);
    }

    public function testTypeWithoutFlagResetsEverything(): void
    {
        $data = $this->data(['doc_type' => 'invni']);
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame(0, $data['corrective']);
        $this->assertNull($data['corrected_doc']);
        $this->assertNull($data['corrected_doc_number']);
        $this->assertNull($data['corrected_doc_date']);
        $this->assertNull($data['correction_reason']);

        // Zálohová faktura vydaná příznak nemá (#92 D1: jen invno).
        $data = $this->data(['doc_type' => 'invpo']);
        $this->doc()->applyCorrectiveColumnsPub($data);
        $this->assertSame(0, $data['corrective']);
    }

    public function testTypeWithoutFlagEvenWithoutFlagKey(): void
    {
        $data = $this->data(['doc_type' => 'invni']);
        unset($data['corrective']);
        $this->doc()->applyCorrectiveColumnsPub($data);

        $this->assertSame(0, $data['corrective']);
        $this->assertNull($data['corrected_doc']);
    }

    public function testWithoutConfigurationTypeIsNotCheckedButLinkStillDenormalizes(): void
    {
        $data = $this->data(['doc_type' => 'invni']);
        $this->doc(withConfig: false)->applyCorrectiveColumnsPub($data);

        $this->assertSame(1, $data['corrective'], 'bez konfigurace se o typu nic neví — příznak zůstává');
        $this->assertSame('126A0007', $data['corrected_doc_number']);
    }

    public function testWithoutDatabaseLinkedValuesStay(): void
    {
        $data = $this->data();
        $this->doc(withDb: false)->applyCorrectiveColumnsPub($data);

        $this->assertSame('ručně', $data['corrected_doc_number']);
        $this->assertSame('2020-01-01', $data['corrected_doc_date']);
    }

    public function testBeforeSaveRunsTheRule(): void
    {
        $data = $this->data(['doc_type' => 'invni', 'number_series' => null]);
        $this->doc()->beforeSavePub($data);

        $this->assertSame(0, $data['corrective'], 'beforeSave bez řady: typ z dat');
        $this->assertNull($data['correction_reason']);

        $data = $this->data(['number_series' => null]);
        $this->doc()->beforeSavePub($data);
        $this->assertSame('126A0007', $data['corrected_doc_number']);
        $this->assertSame('2025-12-20', $data['corrected_doc_date']);
    }

    // ── validate() — U4 odkaz ────────────────────────────────────────────

    public function testConceptWithValidLinkAndWithoutReasonPasses(): void
    {
        $result = $this->validate($this->doc(), $this->data(['correction_reason' => null]));

        $this->assertSame([], $this->errorCodes($result), 'koncept důvod nevyžaduje');
        $this->assertSame([], $this->warningCodes($result));
    }

    public function testMissingOriginalIsInvalidEvenInConcept(): void
    {
        $this->original = null;
        $result = $this->validate($this->doc(), $this->data());

        $this->assertSame(['corrective_doc_invalid'], $this->errorCodes($result));
        $this->assertSame('corrected_doc', $result->getErrors()[0]->toArray()['column']);
    }

    public function testSelfReferenceIsInvalid(): void
    {
        $result = $this->validate($this->doc(), $this->data(['id' => 50]));

        $this->assertSame(['corrective_doc_invalid'], $this->errorCodes($result));
    }

    public function testOriginalOfTypeWithoutFlagIsInvalid(): void
    {
        $this->original['doc_type'] = 'invni';
        $result = $this->validate($this->doc(), $this->data());

        $this->assertSame(['corrective_doc_invalid'], $this->errorCodes($result));
    }

    public function testCorrectiveOriginalIsAllowed(): void
    {
        // Opravný doklad k opravnému dokladu (U4) — typ s příznakem stačí.
        $this->original['corrective'] = 1;
        $result = $this->validate($this->doc(), $this->data());

        $this->assertSame([], $this->errorCodes($result));
    }

    public function testConfirmRequiresOriginalInDoneStateWithSamePartner(): void
    {
        $this->original['docState'] = 10;
        $result = $this->validate($this->doc(), $this->data(['docState' => 40]));
        $this->assertSame(['corrective_doc_state'], $this->errorCodes($result));

        $this->original['docState'] = 40;
        $this->original['partner'] = 6;
        $result = $this->validate($this->doc(), $this->data(['docState' => 40]));
        $this->assertSame(['corrective_doc_partner_mismatch'], $this->errorCodes($result));

        $this->original['partner'] = 5;
        $result = $this->validate($this->doc(), $this->data(['docState' => 40]));
        $this->assertSame([], $this->errorCodes($result));
        $this->assertSame([], $this->warningCodes($result));
    }

    public function testStateAndPartnerAreNotCheckedInConcept(): void
    {
        $this->original['docState'] = 10;
        $this->original['partner'] = 6;
        $result = $this->validate($this->doc(), $this->data());

        $this->assertSame([], $this->errorCodes($result));
    }

    public function testWithoutDatabaseLinkIsNotChecked(): void
    {
        $this->original = null;
        $result = $this->validate($this->doc(withDb: false), $this->data(['number_series' => null, 'docState' => 10]));

        $this->assertSame([], $this->errorCodes($result));
    }

    // ── validate() — U5 důvod a souhrnný doklad ──────────────────────────

    public function testConfirmWithoutReasonFails(): void
    {
        foreach ([null, '', '   '] as $reason) {
            $result = $this->validate($this->doc(), $this->data(['docState' => 40, 'correction_reason' => $reason]));

            $this->assertSame(['correction_reason_required'], $this->errorCodes($result), var_export($reason, true));
            $error = array_values(array_filter(
                $result->getErrors(),
                static fn ($e) => $e->toArray()['code'] === 'correction_reason_required',
            ))[0]->toArray();
            $this->assertSame('correction_reason', $error['column']);
        }
    }

    public function testSummaryCorrectionWithReasonOnlyPassesWithWarning(): void
    {
        $result = $this->validate($this->doc(), $this->data([
            'docState'             => 40,
            'corrected_doc'        => null,
            'corrected_doc_number' => '',
        ]));

        $this->assertSame([], $this->errorCodes($result), 'souhrnný opravný doklad jen s důvodem projde');
        $this->assertSame(['correction_original_missing'], $this->warningCodes($result));
        $warning = $result->getWarnings()[0]->toArray();
        $this->assertSame('corrected_doc_number', $warning['column']);
        $this->assertStringContainsString('souhrnné opravy', $warning['message']);
    }

    public function testManualNumberWithoutLinkDoesNotWarn(): void
    {
        $result = $this->validate($this->doc(), $this->data(['docState' => 40, 'corrected_doc' => null]));

        $this->assertSame([], $this->errorCodes($result));
        $this->assertSame([], $this->warningCodes($result));
    }

    public function testImportModeRequiresNothingButStillChecksTheLink(): void
    {
        $import = ['_importNumber' => ['docNumber' => 'X1', 'sequenceNumber' => null]];
        $result = $this->validate($this->doc(), $this->data($import + [
            'docState'             => 40,
            'correction_reason'    => null,
            'corrected_doc'        => null,
            'corrected_doc_number' => null,
        ]));
        $this->assertSame([], $this->errorCodes($result), 'import mód důvod nevyžaduje');
        $this->assertSame([], $this->warningCodes($result), 'import mód nevaruje');

        $this->original = null;
        $result = $this->validate($this->doc(), $this->data($import + ['docState' => 40, 'correction_reason' => null]));
        $this->assertSame(['corrective_doc_invalid'], $this->errorCodes($result), 'odkaz se kontroluje vždy');
    }

    public function testFlagOffSkipsAllChecks(): void
    {
        $this->original = null;
        $result = $this->validate($this->doc(), $this->data([
            'docState'          => 40,
            'corrective'        => 0,
            'correction_reason' => null,
        ]));

        $this->assertSame([], $this->errorCodes($result));
        $this->assertSame([], $this->warningCodes($result));
    }

    public function testTypeWithoutFlagSkipsAllChecks(): void
    {
        // Přijatá faktura s podstrčeným příznakem: uložení ho zahodí, validace mlčí.
        $this->seriesDocType = 'invni';
        $this->original = null;
        $result = $this->validate($this->doc(), $this->data([
            'doc_type'          => 'invni',
            'docState'          => 40,
            'correction_reason' => null,
        ]));

        $this->assertSame([], $this->errorCodes($result));
    }
}
