<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Utils\JsoncParser;
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
use Shipard\Module\Docs\AccountingDocs\AccountingDocument;
use Shipard\Module\Docs\Core\DocsHeadsDocument;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Náhled návrhu počítá rekapitulaci DPH a součty stejným kódem jako doklad
 * (`_resolve.computed`, tasks/exchange-preview-vat-recompute.md D2–D4, D6,
 * D7). Heads gateway je mock, ale `createDocument()` vrací skutečný
 * `DocsHeadsDocument` nad reálným `vat-cz.jsonc`, takže výpočet jede
 * naostro; `saveDocument()` v testu parity pouští `beforeSave()` jako
 * gateway. Fiktivní dodavatelé a částky z happy fixture.
 */
class DocumentApplierPreviewComputedTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../..';

    private string $tmpDir;

    /** Payload posledního `saveDocument()` po `beforeSave()` (parita). */
    private ?array $savedHeadsData = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_preview_computed_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => [
                    'world.vat.cz'       => JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc'),
                    'docs.core.docTypes' => $this->docTypes(),
                ],
            ]),
        );
        // D6 loguje výjimku výpočtu — do souboru, ne do výstupu testů.
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->tmpDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    // ── Harness ─────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function docTypes(): array
    {
        return [
            'invno'  => ['trade_dir' => 1],
            'invni'  => ['trade_dir' => 2],
            'cmnbkp' => ['trade_dir' => 0],
        ];
    }

    private static ?array $vatCz = null;
    private static ?array $vatPlaces = null;
    private static ?array $tradeUnions = null;

    private function vatCzRateResolver(): VatRateResolver
    {
        self::$vatCz ??= JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null,
        );
        return new VatRateResolver($config);
    }

    private function tradeUnionResolver(): TradeUnionResolver
    {
        self::$tradeUnions ??= JsoncParser::parseFile(self::ROOT . '/modules/world/trade/config/tradeUnions.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null,
        );
        return new TradeUnionResolver($config);
    }

    private function applierConfig(): ConfigRuntime
    {
        self::$vatPlaces ??= JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/vatPlaces.jsonc');
        $docTypes = $this->docTypes();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docTypes'  => $docTypes,
                'docs.core.vatPlaces' => self::$vatPlaces,
                default               => null,
            },
        );
        return $config;
    }

    /** DB zdroje: jedna aktivní registrace DPH (cz, id 5); ostatní dotazy prázdné. */
    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            return str_contains($sql, 'economy_codebooks_vat_registrations')
                ? new Row(['id' => 5, 'country' => 'cz'])
                : null;
        });
        $db->method('fetchAll')->willReturn([]);
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    /** Skutečný dokument podle typu — to, co by vrátil `DocumentRegistry`. */
    private function headDocument(array $data): Document
    {
        $doc = ($data['doc_type'] ?? null) === 'cmnbkp' ? new AccountingDocument() : new DocsHeadsDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    private function headsGateway(?\Throwable $createFails = null, bool $plainDocument = false): TransactionlessTableGateway
    {
        $heads = $this->createMock(TransactionlessTableGateway::class);
        if ($createFails !== null) {
            $heads->method('createDocument')->willThrowException($createFails);
        } elseif ($plainDocument) {
            $heads->method('createDocument')->willReturn(new \Shipard\Core\Document\DefaultDocument());
        } else {
            $heads->method('createDocument')->willReturnCallback(fn (array $data): Document => $this->headDocument($data));
        }
        // Parita: gateway by nad payloadem pustila beforeSave() — uděláme totéž
        // a zachytíme, s čím by šel INSERT.
        $this->savedHeadsData = null;
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->headDocument($data)->beforeSave($data);
            $this->savedHeadsData = $data;
            return DocumentResult::ok(['id' => 1234]);
        });
        return $heads;
    }

    private function buildApplier(?\Throwable $createFails = null, bool $plainDocument = false): DocumentApplier
    {
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

        return new TestableDocumentApplier(
            db: $this->db(),
            config: $this->applierConfig(),
            headsGateway: $this->headsGateway($createFails, $plainDocument),
            personsGateway: $this->createMock(TransactionlessTableGateway::class),
            itemsGateway: $this->createMock(TransactionlessTableGateway::class),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: new VatCodeResolver($this->vatCzRateResolver()),
            bankAccountResolver: $bank,
            accountResolver: $account,
            vatCodeDerivation: new VatCodeDerivation($this->vatCzRateResolver()),
            vatPlaceDerivation: new VatPlaceDerivation($this->tradeUnionResolver()),
        );
    }

    // ── Payloady ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function happyPayload(): array
    {
        return json_decode(
            (string) file_get_contents(self::ROOT . '/tests/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * Služby od dodavatele z jiného státu EU: na dokladu „reverse charge“,
     * DPH 0, kód DPH null (tvar promptu v4.6.0) — kód určí derivace.
     *
     * @return array<string, mixed>
     */
    private function euServicesPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['supplier']['country'] = 'DE';
        $payload['supplier']['vatId'] = 'DE123456789';
        $payload['supplier']['address']['country'] = 'DE';
        $payload['vat'] = [
            'mode'                => 'fromBase',
            'place'               => 'intracom',
            'reverseCharge'       => true,
            'registrationCountry' => null,
        ];
        // Jeden řádek 1 × 10 330,58 — happy fixture má 10 × 1 033,06 (= 10 330,60),
        // s přepočítanou rekapitulací by haléře z řádků rozhodily srovnání.
        $payload['rows'][0]['quantity'] = 1;
        $payload['rows'][0]['unitPrice'] = 10330.58;
        $payload['rows'][0]['vat'] = [
            'code'              => null,
            'pct'               => 0,
            'supplyKind'        => 'services',
            'reverseChargeCode' => null,
        ];
        $payload['rows'][0]['computed'] = ['vatBase' => 10330.58, 'vatAmount' => 0, 'vatTotal' => 10330.58];
        $payload['vatRecap'] = [
            ['vatCode' => null, 'vatPct' => 0, 'base' => 10330.58, 'tax' => 0, 'total' => 10330.58],
        ];
        $payload['totals'] = ['totalBase' => 10330.58, 'totalVat' => 0, 'totalAmount' => 10330.58, 'totalRounding' => 0];
        return $payload;
    }

    /** AI přečetla jen polovinu množství; rekapitulaci neopsala. @return array<string, mixed> */
    private function incompleteRowsPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['quantity'] = 5;
        $payload['rows'][0]['totalPrice'] = 5165.30;
        unset($payload['vatRecap']);
        return $payload;
    }

    /** @return list<string> */
    private function issueCodes(array $canonical): array
    {
        return array_values(array_map(
            static fn (array $i): string => (string) $i['code'],
            $canonical['_resolve']['issues'] ?? [],
        ));
    }

    private function issue(array $canonical, string $code): ?array
    {
        foreach ($canonical['_resolve']['issues'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === $code) {
                return $issue;
            }
        }
        return null;
    }

    // ── Testy ───────────────────────────────────────────────────────────────

    public function testDomesticConsistentRecapIsDeclaredAndMatchesSupplier(): void
    {
        $result = $this->buildApplier()->preview($this->happyPayload());

        $this->assertTrue($result->success);
        $computed = $result->canonical['_resolve']['computed'];
        $this->assertSame('declared', $computed['recapSource']);
        $this->assertNull($computed['recapFallback']);
        $this->assertSame([[
            'vatCode' => 'cz-110', 'vatPct' => 21.0,
            'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00,
            'isReversePair' => false,
        ]], $computed['vatRecap']);
        $this->assertSame(
            ['totalBase' => 10330.58, 'totalVat' => 2169.42, 'totalAmount' => 12500.00, 'totalRounding' => 0.0],
            $computed['totals'],
        );
        $codes = $this->issueCodes($result->canonical);
        $this->assertNotContains('computed_total_mismatch', $codes);
        $this->assertNotContains('totals_mismatch', $codes);
        $this->assertNotContains('computed_unavailable', $codes);
    }

    public function testEuServicesReverseChargeIsComputedWithPairAndTotalEqualsBase(): void
    {
        $result = $this->buildApplier()->preview($this->euServicesPayload());

        $resolve = $result->canonical['_resolve'];
        $computed = $resolve['computed'];
        $this->assertSame('computed', $computed['recapSource']);
        $this->assertStringContainsString('přenesení daňové povinnosti', (string) $computed['recapFallback']);

        // Nárok na odpočet + oddaňovací pár; dodavatel má 0 % a daň 0.
        $this->assertCount(2, $computed['vatRecap']);
        $this->assertSame('cz-217', $computed['vatRecap'][0]['vatCode']);
        $this->assertSame(21.0, $computed['vatRecap'][0]['vatPct']);
        $this->assertSame(2169.42, $computed['vatRecap'][0]['tax']);
        $this->assertFalse($computed['vatRecap'][0]['isReversePair']);
        $this->assertSame('cz-207', $computed['vatRecap'][1]['vatCode']);
        $this->assertTrue($computed['vatRecap'][1]['isReversePair']);

        // K úhradě jen základ — částka sedí s dokladem, warning nepadne.
        $this->assertSame(10330.58, $computed['totals']['totalAmount']);
        $this->assertSame(0.0, $computed['totals']['totalVat']);
        $this->assertNotContains('computed_total_mismatch', $this->issueCodes($result->canonical));

        // Sloupec sazby řádku v náhledu: efektivní kód a sazba z resolve, ne 0 % z canonicalu.
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame(21.0, $resolve['rows'][0]['vatCode']['createPayload']['pct']);
    }

    public function testInconsistentRecapFallsBackToComputedWithReason(): void
    {
        $payload = $this->happyPayload();
        $payload['vatRecap'][0]['tax'] = 12.00;

        $result = $this->buildApplier()->preview($payload);

        $computed = $result->canonical['_resolve']['computed'];
        $this->assertSame('computed', $computed['recapSource']);
        $this->assertNotNull($computed['recapFallback']);
        $this->assertCount(1, $computed['vatRecap']);
        // Z řádků (10 × 1033,06), ne z rekapitulace dodavatele.
        $this->assertSame(10330.60, $computed['vatRecap'][0]['base']);
        $this->assertSame(2169.43, $computed['vatRecap'][0]['tax']);
        $this->assertSame(12500.03, $computed['totals']['totalAmount']);
        $this->assertContains('recap_source_computed_fallback', $this->issueCodes($result->canonical));
        // Rozdíl 0,03 proti dokladu → upozornění (D4).
        $this->assertContains('computed_total_mismatch', $this->issueCodes($result->canonical));
    }

    public function testTotalMismatchWarningReplacesValidatorHeuristic(): void
    {
        $result = $this->buildApplier()->preview($this->incompleteRowsPayload());

        $codes = $this->issueCodes($result->canonical);
        $this->assertContains('computed_total_mismatch', $codes);
        $this->assertNotContains('totals_mismatch', $codes, 'skutečný výpočet nahrazuje heuristiku');

        $issue = $this->issue($result->canonical, 'computed_total_mismatch');
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('totals.totalAmount', $issue['path']);
        $this->assertSame(12500.00, $issue['declared']);
        $this->assertSame(6250.01, $issue['computed']);
        $this->assertSame(6250.01, $result->canonical['_resolve']['computed']['totals']['totalAmount']);
    }

    public function testMissingDeclaredTotalSkipsComparison(): void
    {
        $payload = $this->incompleteRowsPayload();
        unset($payload['totals']);

        $result = $this->buildApplier()->preview($payload);

        $this->assertNotNull($result->canonical['_resolve']['computed']);
        $this->assertNotContains('computed_total_mismatch', $this->issueCodes($result->canonical));
    }

    public function testGatewayFailureDegradesToNullAndKeepsHeuristic(): void
    {
        $applier = $this->buildApplier(new \RuntimeException('registry broken'));

        $result = $applier->preview($this->incompleteRowsPayload());

        $this->assertTrue($result->success, 'náhled přežije i selhání výpočtu (D6)');
        $resolve = $result->canonical['_resolve'];
        $this->assertArrayHasKey('computed', $resolve);
        $this->assertNull($resolve['computed']);
        $codes = $this->issueCodes($result->canonical);
        $this->assertContains('computed_unavailable', $codes);
        $this->assertSame('info', $this->issue($result->canonical, 'computed_unavailable')['severity']);
        $this->assertContains('totals_mismatch', $codes, 'bez výpočtu zůstává heuristika validátoru');
        $this->assertNotContains('computed_total_mismatch', $codes);
        $this->assertStringContainsString('registry broken', (string) file_get_contents($this->tmpDir . '/test.log'));
    }

    /** Registr bez DocDocument pro typ (holý mock gatewaye) = tichá degradace, bez logu výjimky. */
    public function testNonDocDocumentInstanceDegradesQuietly(): void
    {
        $applier = $this->buildApplier(plainDocument: true);

        $result = $applier->preview($this->happyPayload());

        $this->assertNull($result->canonical['_resolve']['computed']);
        $this->assertContains('computed_unavailable', $this->issueCodes($result->canonical));
        $this->assertFalse(file_exists($this->tmpDir . '/test.log'), 'stav, ne pád — nic se neloguje');
    }

    public function testAccountingDocumentSumsDebitRowsWithoutRecap(): void
    {
        $result = $this->buildApplier()->preview([
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'accountingDocument',
            'dates'         => ['issueDate' => '2026-06-10'],
            'rows'          => [
                ['rowKind' => 'item', 'operation' => 'acc.record', 'accSide' => 'debit', 'account' => '568001', 'totalPrice' => 120.0, 'description' => 'Poplatek'],
                ['rowKind' => 'item', 'operation' => 'acc.record', 'accSide' => 'credit', 'account' => '221001', 'totalPrice' => 120.0],
            ],
        ]);

        $computed = $result->canonical['_resolve']['computed'];
        $this->assertNotNull($computed);
        $this->assertSame([], $computed['vatRecap']);
        $this->assertSame(120.0, $computed['totals']['totalAmount']);
        $this->assertSame(0.0, $computed['totals']['totalVat']);
        $this->assertNotContains('computed_unavailable', $this->issueCodes($result->canonical));
    }

    /**
     * Parita: `_resolve.computed` z náhledu = částky, se kterými by apply()
     * uložil doklad nad stejným canonicalem (payload saveDocument po
     * beforeSave). Pro převzatou i přepočítanou rekapitulaci.
     */
    public function testComputedMatchesWhatApplyWouldSave(): void
    {
        foreach (['happy' => $this->happyPayload(), 'euServices' => $this->euServicesPayload()] as $label => $payload) {
            $applier = $this->buildApplier();
            $computed = $applier->preview($payload)->canonical['_resolve']['computed'];

            $applied = $applier->apply($payload);
            $this->assertTrue($applied->success, "{$label}: {$applied->errorCode} {$applied->errorMessage}");
            $saved = $this->savedHeadsData;
            $this->assertIsArray($saved, $label);

            $this->assertSame([
                'totalBase'     => (float) $saved['total_base'],
                'totalVat'      => (float) $saved['total_vat'],
                'totalAmount'   => (float) $saved['total_amount'],
                'totalRounding' => (float) $saved['total_rounding'],
            ], $computed['totals'], $label);
            $this->assertSame(
                array_map(static fn (array $r): array => [
                    'vatCode'       => (string) $r['vat_code'],
                    'vatPct'        => (float) $r['vat_pct'],
                    'base'          => (float) $r['base'],
                    'tax'           => (float) $r['tax'],
                    'total'         => (float) $r['total'],
                    'isReversePair' => !empty($r['is_reverse_pair']),
                ], array_values($saved['vatRecap'])),
                $computed['vatRecap'],
                $label,
            );
        }
    }
}
