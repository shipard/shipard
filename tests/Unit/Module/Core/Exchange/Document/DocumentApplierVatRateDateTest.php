<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\DocsHeadsDocument;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Datum sazby DPH ve výměnném formátu (#159 D11, tasks/vat-rate-date-exchange.md
 * U2–U6): `vat.rateSource`, `dates.vatRateDate`, `rows[].vat.rateDate`
 * a `vatRecap[].rateDate`. Applier odvozuje kód, kontroluje ho a dosazuje
 * procento k datu sazby řádku podle `VatRateDates` — ne k DUZP.
 *
 * Skutečný `vat-cz.jsonc` (sazby závisejí na datu) a skutečný
 * `DocsHeadsDocument` v heads gatewayi: `validate()` a `beforeSave()` běží
 * jako při uložení, takže test vidí i to, co z hodnot applieru udělá dokument.
 */
class DocumentApplierVatRateDateTest extends TestCase
{
    use ReferenceFixture;

    private const ROOT = __DIR__ . '/../../../../../..';

    private const IMPORT = ['targetDocState' => 10, 'importNumber' => ['docNumber' => 'FV14-00077', 'sequenceNumber' => 77]];

    private static ?array $vatCz = null;
    private static ?array $tradeUnions = null;

    private string $tmpDir;

    /** Payload posledního `saveDocument()` po `beforeSave()`; null = doklad neprošel validací. */
    private ?array $saved = null;

    /** @var list<string> kódy chyb validace dokumentu z posledního `saveDocument()` */
    private array $validationCodes = [];

    protected function setUp(): void
    {
        self::$vatCz ??= JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc');
        self::$tradeUnions ??= JsoncParser::parseFile(self::ROOT . '/modules/world/trade/config/tradeUnions.jsonc');
        $this->tmpDir = sys_get_temp_dir() . '/shpd_vat_rate_date_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => ['world.vat.cz' => self::$vatCz, 'docs.core.docTypes' => self::docTypes()],
            ]),
        );
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->tmpDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach (['/config/configuration/compiled.cs.json', '/test.log'] as $file) {
            if (is_file($this->tmpDir . $file)) {
                unlink($this->tmpDir . $file);
            }
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    // ── Harness ─────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private static function docTypes(): array
    {
        return [
            'invno' => ['trade_dir' => 1],
            'invni' => ['trade_dir' => 2],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
    }

    /** DB zdroje: jedna aktivní registrace DPH (cz, id 5); ostatní dotazy prázdné. */
    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            return str_contains((string) ($args[0] ?? ''), 'economy_codebooks_vat_registrations')
                ? new Row(['id' => 5, 'country' => 'cz'])
                : null;
        });
        $db->method('fetchAll')->willReturn([]);
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    private function headDocument(): Document
    {
        $doc = new DocsHeadsDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    private function applier(): DocumentApplier
    {
        $this->saved = null;
        $this->validationCodes = [];
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('createDocument')->willReturnCallback(fn (array $data): Document => $this->headDocument());
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            // Jen nálezy data sazby: plná validace hlavičky potřebuje řadu,
            // partnera a registraci z databáze, kterou test nemá.
            $validation = $this->headDocument()->validate($data, ApplyMode::Proposal);
            $rateErrors = array_values(array_filter(
                $validation->getErrors(),
                static fn ($e): bool => str_starts_with((string) $e->code, 'vat_rate_'),
            ));
            $this->validationCodes = array_map(static fn ($e): string => (string) $e->code, $rateErrors);
            if ($rateErrors !== []) {
                return DocumentResult::error(implode('; ', array_map(
                    static fn ($e): string => $e->column . ': ' . $e->message,
                    $rateErrors,
                )));
            }
            $this->headDocument()->beforeSave($data);
            $this->saved = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));
        $account = $this->createMock(AccountResolver::class);
        $account->method('resolve')->willReturn(null);

        $docTypes = self::docTypes();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        $vatConfig = $this->createMock(ConfigRuntime::class);
        $vatConfig->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null,
        );
        $tradeConfig = $this->createMock(ConfigRuntime::class);
        $tradeConfig->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null,
        );
        $vatRates = new VatRateResolver($vatConfig);

        return new class(
            db: $this->db(),
            config: $config,
            headsGateway: $heads,
            personsGateway: $this->createMock(TransactionlessTableGateway::class),
            itemsGateway: $this->createMock(TransactionlessTableGateway::class),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: new VatCodeResolver($vatRates),
            bankAccountResolver: $bank,
            accountResolver: $account,
            vatCodeDerivation: new VatCodeDerivation($vatRates),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($tradeConfig)),
            references: $this->referenceResolver(self::REFERENCE_RECORDS),
        ) extends DocumentApplier {
            protected function executeSql(mixed ...$args): void
            {
            }
        };
    }

    // ── Payloady ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $vat `rows[].vat`
     * @return array<string, mixed>
     */
    private static function row(array $vat, float $quantity = 1.0, float $unitPrice = 1000.0, int $pos = 1): array
    {
        return [
            'rowKind' => 'item', 'orderPos' => $pos, 'description' => "Řádek {$pos}",
            'quantity' => $quantity, 'unitPrice' => $unitPrice, 'priceCalcMode' => 'fromUnitPrice',
            'vat' => $vat,
        ];
    }

    /**
     * Přijatá tuzemská faktura (větev derive — zdroj má registraci DPH).
     *
     * @param array<string, mixed> $dates
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $vat přepis bloku `vat`
     * @return array<string, mixed>
     */
    private static function received(array $dates, array $rows, array $vat = []): array
    {
        return [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceReceived',
            'docNumber'     => '2013000045',
            'selfParty'     => 'customer',
            'supplier'      => ['name' => 'Dodavatel s.r.o.', 'country' => 'CZ', 'companyId' => '12345678', 'vatId' => 'CZ12345678'],
            'dates'         => $dates,
            'currency'      => 'CZK',
            'vat'           => $vat + ['mode' => 'fromBase', 'place' => 'domestic'],
            'payment'       => ['method' => 'bankTransfer'],
            'rows'          => $rows,
        ];
    }

    /**
     * Faktura vydaná (mimo větev derive).
     *
     * @param array<string, mixed> $dates
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $vat přepis bloku `vat`
     * @return array<string, mixed>
     */
    private static function issued(array $dates, array $rows, array $vat = [], string $docType = 'invoiceIssued'): array
    {
        return [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => $docType,
            'selfParty'     => 'supplier',
            'customer'      => ['name' => 'Odběratel s.r.o.', 'country' => 'CZ', 'companyId' => '12345678'],
            'dates'         => $dates,
            'currency'      => 'CZK',
            'vat'           => $vat + ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'CZ'],
            'payment'       => ['method' => 'bankTransfer'],
            'rows'          => $rows,
        ];
    }

    private static function describe(ApplyResult $result): string
    {
        return json_encode(
            [$result->errorCode, $result->errorMessage, $result->canonical['_resolve']['issues'] ?? null],
            JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return list<string> */
    private static function issueCodes(ApplyResult $result): array
    {
        return array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
    }

    /** @return list<array<string, mixed>> */
    private static function issuesOf(ApplyResult $result, string $code): array
    {
        return array_values(array_filter(
            $result->canonical['_resolve']['issues'] ?? [],
            static fn (array $issue): bool => ($issue['code'] ?? null) === $code,
        ));
    }

    // ── Automatika: DPPD před DUZP ──────────────────────────────────────────

    public function testReceivedInvoiceAcrossRateChangeKeepsCodeByObligationDate(): void
    {
        // DUZP u nás 4. 1. 2013 (21 %), plnění dodavatele 31. 12. 2012 (20 %).
        $payload = self::received(
            ['issueDate' => '2013-01-04', 'taxPointDate' => '2013-01-04', 'vatObligationDate' => '2012-12-31'],
            [self::row(['code' => 'cz-110', 'pct' => 20])],
        );
        $applier = $this->applier();

        $preview = $applier->preview($payload, ApplyMode::Proposal);
        $this->assertSame(200.0, $preview->canonical['_resolve']['computed']['totals']['totalVat'], 'náhled počítá stejnou sazbou');

        $result = $applier->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($result->success, self::describe($result));
        $codes = self::issueCodes($result);
        $this->assertNotContains('vat_code_unknown', $codes);
        $this->assertNotContains('vat_code_derived', $codes);
        $this->assertSame('cfgItem', $result->canonical['_resolve']['rows'][0]['vatCode']['matchedBy'], 'kód z dokladu zůstal');
        $this->assertSame('cz-110', $this->saved['rows'][0]['vat_code']);
        $this->assertSame(20.0, (float) $this->saved['rows'][0]['vat_pct']);
        $this->assertSame([20.0], array_map('floatval', array_column($this->saved['vatRecap'], 'vat_pct')));
        $this->assertSame(0, $this->saved['vat_rate_source']);
        $this->assertSame('2013-01-04', $this->saved['vat_duzp'], 'DUZP zůstává — řídí období');
    }

    public function testWithoutObligationDateTheRateFollowsTaxPointDate(): void
    {
        $result = $this->applier()->apply(self::received(
            ['issueDate' => '2013-01-04', 'taxPointDate' => '2013-01-04'],
            [self::row(['code' => 'cz-110', 'pct' => 20])],
        ), ApplyMode::Proposal);

        $this->assertFalse($result->success);
        $this->assertContains('vat_code_unknown', self::issueCodes($result), 'sazba 20 % k lednu 2013 neexistuje');
        $this->assertNull($this->saved);
    }

    // ── Vlastní datum dokladu ───────────────────────────────────────────────

    public function testDocumentRateDateDrivesCodeAndRate(): void
    {
        $result = $this->applier()->apply(self::received(
            ['issueDate' => '2013-01-04', 'taxPointDate' => '2013-01-04', 'vatRateDate' => '2012-12-31'],
            [self::row(['code' => 'cz-110', 'pct' => 20])],
            ['rateSource' => 'document'],
        ), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertNotContains('vat_rate_date_ignored', self::issueCodes($result));
        $this->assertSame(20.0, (float) $this->saved['rows'][0]['vat_pct']);
        $this->assertSame([20.0], array_map('floatval', array_column($this->saved['vatRecap'], 'vat_pct')));
        $this->assertSame(1, $this->saved['vat_rate_source']);
        $this->assertSame('2012-12-31', $this->saved['vat_rate_date']);
    }

    // ── Vlastní datum na řádcích ────────────────────────────────────────────

    public function testImportedIssuedInvoiceCarriesRowAndRecapRateDates(): void
    {
        // Závěrečná faktura 2014: běžný řádek 21 %, odpočet zálohy z roku
        // 2012 ve 20 %; převzatá rekapitulace má dvě skupiny téhož kódu.
        $payload = self::issued(
            ['issueDate' => '2014-03-10', 'taxPointDate' => '2014-03-10'],
            [
                self::row(['code' => 'cz-120', 'pct' => 21], 1.0, 10000.0, 1),
                self::row(['code' => 'cz-120', 'pct' => 20, 'rateDate' => '2012-06-30'], -1.0, 5000.0, 2),
            ],
            ['rateSource' => 'rows', 'recapSource' => 'declared'],
        );
        $payload['vatRecap'] = [
            ['vatCode' => 'cz-120', 'vatPct' => 21, 'base' => 10000.0, 'tax' => 2100.0, 'total' => 12100.0],
            ['vatCode' => 'cz-120', 'vatPct' => 20, 'base' => -5000.0, 'tax' => -1000.0, 'total' => -6000.0, 'rateDate' => '2012-06-30'],
        ];
        $payload['applyOptions'] = self::IMPORT;

        $result = $this->applier()->apply(self::asInstruction($payload), ApplyMode::Instruction);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertNotContains('recap_source_computed_fallback', self::issueCodes($result));
        $this->assertNotContains('vat_rate_date_ignored', self::issueCodes($result));
        $this->assertSame(2, $this->saved['vat_rate_source']);
        $this->assertSame([21.0, 20.0], array_map('floatval', array_column($this->saved['rows'], 'vat_pct')));
        $this->assertNull($this->saved['rows'][0]['vat_rate_date'] ?? null);
        $this->assertSame('2012-06-30', $this->saved['rows'][1]['vat_rate_date']);
        $this->assertSame(1, $this->saved['vat_recap_source']);
        $this->assertSame([21.0, 20.0], array_map('floatval', array_column($this->saved['vatRecap'], 'vat_pct')));
        $this->assertNull($this->saved['vatRecap'][0]['vat_rate_date'] ?? null);
        $this->assertSame('2012-06-30', $this->saved['vatRecap'][1]['vat_rate_date']);
        $this->assertSame(6100.0, (float) $this->saved['total_amount']);
    }

    public function testRowRateDateGivesRateOfCodeValidOnlyInThePast(): void
    {
        // cz-311 (druhá snížená) má sazbu jen 2015–2023.
        $result = $this->applier()->apply(self::asInstruction(self::issued(
            ['issueDate' => '2024-02-10', 'taxPointDate' => '2024-02-10'],
            [self::row(['code' => 'cz-311', 'rateDate' => '2023-12-15'])],
            ['rateSource' => 'rows'],
        ) + ['applyOptions' => self::IMPORT]), ApplyMode::Instruction);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame('cz-311', $this->saved['rows'][0]['vat_code']);
        $this->assertSame(10.0, (float) $this->saved['rows'][0]['vat_pct']);
        $this->assertSame('2023-12-15', $this->saved['rows'][0]['vat_rate_date']);
    }

    public function testImportDoesNotRepairCodeWithoutRateAtRowDate(): void
    {
        // Tentýž řádek bez data: kód k únoru 2024 sazbu nemá. Applier ho
        // nesmí tiše nahradit — odmítne ho validace dokumentu (D15).
        $result = $this->applier()->apply(self::asInstruction(self::issued(
            ['issueDate' => '2024-02-10', 'taxPointDate' => '2024-02-10'],
            [self::row(['code' => 'cz-311', 'pct' => 10])],
            ['rateSource' => 'rows'],
        ) + ['applyOptions' => self::IMPORT]), ApplyMode::Instruction);

        $this->assertFalse($result->success);
        $this->assertSame(['vat_rate_missing'], $this->validationCodes);
        $this->assertNull($this->saved);
        $this->assertStringContainsString('cz-311', (string) $result->errorMessage);
    }

    public function testCodeIsDerivedAtRowRateDate(): void
    {
        // Přijatý doklad bez kódu: 15 % k prosinci 2023 je první snížená
        // (cz-301); k lednu 2024 by sazba 15 % neexistovala.
        $result = $this->applier()->apply(self::received(
            ['issueDate' => '2024-01-10', 'taxPointDate' => '2024-01-10'],
            [self::row(['code' => null, 'pct' => 15, 'rateDate' => '2023-12-20'])],
            ['rateSource' => 'rows'],
        ), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame('derived', $result->canonical['_resolve']['rows'][0]['vatCode']['matchedBy']);
        $this->assertSame('cz-301', $this->saved['rows'][0]['vat_code']);
        $this->assertSame(15.0, (float) $this->saved['rows'][0]['vat_pct']);
    }

    // ── Volba kódu (pin) k datu řádku ───────────────────────────────────────

    public function testPinIsValidatedAgainstOptionsAtRowRateDate(): void
    {
        $payload = self::received(
            ['issueDate' => '2024-01-10', 'taxPointDate' => '2024-01-10'],
            [self::row(['code' => null, 'pct' => 15, 'rateDate' => '2023-12-20'])],
            ['rateSource' => 'rows'],
        );

        // Kód se sazbou jen k datu řádku: v nabídce dokladu není, pin projde.
        $payload['_resolve']['rows'][0]['vatCode']['userAction'] = 'useCode:cz-302';
        $result = $this->applier()->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($result->success, self::describe($result));
        $this->assertNotContains('cz-302', array_column($result->canonical['_resolve']['vatCodeOptions'], 'code'), 'nabídka klienta je k datu dokladu');
        $this->assertSame('user', $result->canonical['_resolve']['rows'][0]['vatCode']['matchedBy']);
        $this->assertSame('cz-302', $this->saved['rows'][0]['vat_code']);
        $this->assertSame(10.0, (float) $this->saved['rows'][0]['vat_pct']);

        // Kód platný jen k datu dokladu (cz-111: 12 % od 2024, v roce 2023 bez sazby).
        $payload['_resolve']['rows'][0]['vatCode']['userAction'] = 'useCode:cz-111';
        $result = $this->applier()->apply($payload, ApplyMode::Proposal);
        $this->assertFalse($result->success);
        $this->assertContains('cz-111', array_column($result->canonical['_resolve']['vatCodeOptions'], 'code'));
        $this->assertCount(1, self::issuesOf($result, 'vat_code_pin_invalid'));
        $this->assertNull($this->saved);
    }

    // ── Data mimo režim (U5) ────────────────────────────────────────────────

    public function testRowRateDateOutsideItsModeIsIgnoredWithInfo(): void
    {
        $result = $this->applier()->apply(self::issued(
            ['issueDate' => '2014-03-10', 'taxPointDate' => '2014-03-10'],
            [
                self::row(['code' => 'cz-120'], 1.0, 1000.0, 1),
                self::row(['code' => 'cz-120', 'rateDate' => '2012-06-30'], 1.0, 1000.0, 2),
            ],
        ), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $ignored = self::issuesOf($result, 'vat_rate_date_ignored');
        $this->assertCount(1, $ignored);
        $this->assertSame('info', $ignored[0]['severity']);
        $this->assertSame('rows.1.vat.rateDate', $ignored[0]['path']);
        $this->assertSame([21.0, 21.0], array_map('floatval', array_column($this->saved['rows'], 'vat_pct')), 'sazba podle automatiky');
        $this->assertSame(0, $this->saved['vat_rate_source']);
        $this->assertNull($this->saved['rows'][1]['vat_rate_date'], 'datum mimo režim dokument smaže');
    }

    public function testDocumentRateDateOutsideItsModeIsReportedOncePerDocument(): void
    {
        $payload = self::issued(
            ['issueDate' => '2014-03-10', 'taxPointDate' => '2014-03-10', 'vatRateDate' => '2012-06-30'],
            [self::row(['code' => 'cz-120'])],
            ['rateSource' => 'rows', 'recapSource' => 'declared'],
        );
        $payload['vatRecap'] = [['vatCode' => 'cz-120', 'vatPct' => 21, 'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0]];

        $result = $this->applier()->preview($payload, ApplyMode::Proposal);

        $ignored = self::issuesOf($result, 'vat_rate_date_ignored');
        $this->assertCount(1, $ignored);
        $this->assertSame('dates.vatRateDate', $ignored[0]['path']);

        // Datum řádku rekapitulace při zdroji „dokladu“.
        $payload['dates']['vatRateDate'] = '2014-03-10';
        $payload['vat']['rateSource'] = 'document';
        $payload['vatRecap'][0]['rateDate'] = '2012-06-30';
        $ignored = self::issuesOf($this->applier()->preview($payload, ApplyMode::Proposal), 'vat_rate_date_ignored');
        $this->assertSame(['vatRecap.0.rateDate'], array_column($ignored, 'path'));
    }

    // ── Nedaňový doklad ─────────────────────────────────────────────────────

    public function testNonTaxDocumentAlwaysUsesIssueDateAndDoesNotShareContextWithInvoice(): void
    {
        // Stejná data i řádky, jiný typ: zálohová faktura nemá DUZP ani
        // přepínač — sazba k datu vystavení (21 %); faktura k vlastnímu
        // datu dokladu (20 %). Jedna instance applieru — kontext se nesmí sdílet.
        $dates = ['issueDate' => '2013-01-04', 'taxPointDate' => '2013-01-04', 'vatRateDate' => '2012-12-31'];
        $rows = [self::row(['code' => 'cz-120'])];
        $applier = $this->applier();

        $result = $applier->apply(self::issued($dates, $rows, ['rateSource' => 'document'], 'proformaIssued'), ApplyMode::Proposal);
        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(21.0, (float) $this->saved['rows'][0]['vat_pct']);
        $this->assertSame(0, $this->saved['vat_rate_source'], 'nedaňový doklad má vždy automatiku');
        $this->assertNull($this->saved['vat_rate_date']);

        $result = $applier->apply(self::issued($dates, $rows, ['rateSource' => 'document']), ApplyMode::Proposal);
        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(20.0, (float) $this->saved['rows'][0]['vat_pct']);
        $this->assertSame(1, $this->saved['vat_rate_source']);
    }
}
