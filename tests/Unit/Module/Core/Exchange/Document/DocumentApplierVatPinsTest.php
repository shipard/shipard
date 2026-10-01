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
use Shipard\Module\Docs\Core\DocsHeadsDocument;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Ruční volba kódu DPH řádku, místa plnění a režimu DPH přijatého dokladu
 * (tasks/exchange-preview-vat-choices.md D8–D14, #87 task B). Volby přijdou
 * v `_resolve.*.userAction` (`useCode:` / `useValue:`), uplatní se ve
 * `vatContext()` — rekapitulace, `_resolve.computed` i doklad je následují.
 * Skutečný `vat-cz.jsonc`, skutečný `DocsHeadsDocument` v heads gatewayi;
 * fiktivní dodavatelé a částky z happy fixture.
 */
class DocumentApplierVatPinsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../..';

    private string $tmpDir;

    /** Payload posledního `saveDocument()` po `beforeSave()`. */
    private ?array $savedHeadsData = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_vat_pins_test_' . uniqid();
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
        return ['invno' => ['trade_dir' => 1], 'invni' => ['trade_dir' => 2]];
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

    private function headDocument(): Document
    {
        $doc = new DocsHeadsDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    private function buildApplier(): DocumentApplier
    {
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('createDocument')->willReturnCallback(fn (array $data): Document => $this->headDocument());
        $this->savedHeadsData = null;
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->headDocument()->beforeSave($data);
            $this->savedHeadsData = $data;
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

        return new TestableDocumentApplier(
            db: $this->db(),
            config: $this->applierConfig(),
            headsGateway: $heads,
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
     * Služby od dodavatele z jiného státu EU: „reverse charge“, DPH 0 na
     * dokladu, kód null; `$pct` je sazba, kterou dodavatel u řádku uvádí
     * (0 = standardní případ; 12 = snížená sazba, kterou derivace neumí).
     *
     * @return array<string, mixed>
     */
    private function euServicesPayload(float $pct = 0.0): array
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
        $payload['rows'][0]['quantity'] = 1;
        $payload['rows'][0]['unitPrice'] = 10330.58;
        $payload['rows'][0]['vat'] = [
            'code'              => null,
            'pct'               => $pct,
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

    /** Vystavená faktura (selfParty supplier) — mimo větev derive. @return array<string, mixed> */
    private function issuedPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['docType'] = 'invoiceIssued';
        $payload['selfParty'] = 'supplier';
        $payload['customer'] = $payload['supplier'];
        $payload['supplier'] = null;
        return $payload;
    }

    /** @param array<string, string> $flat plochá mapa {cesta: akce} jako z MessageProposalApplier */
    private function withPins(array $payload, array $flat): array
    {
        foreach ($flat as $path => $action) {
            if (preg_match('/^rows\[(\d+)\]\.vatCode$/', $path, $m) === 1) {
                $payload['_resolve']['rows'][(int) $m[1]]['vatCode']['userAction'] = $action;
            } elseif (preg_match('/^vat\.(place|mode)$/', $path, $m) === 1) {
                $payload['_resolve']['vat'][$m[1]]['userAction'] = $action;
            } else {
                throw new \InvalidArgumentException($path);
            }
        }
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

    /** @return list<array<string, mixed>> */
    private function issuesOf(array $canonical, string $code): array
    {
        return array_values(array_filter(
            $canonical['_resolve']['issues'] ?? [],
            static fn (array $i): bool => ($i['code'] ?? null) === $code,
        ));
    }

    /** @return list<string> */
    private function optionCodes(array $canonical): array
    {
        return array_map(static fn (array $o): string => $o['code'], $canonical['_resolve']['vatCodeOptions'] ?? []);
    }

    // ── Kód DPH řádku (D10–D13) ─────────────────────────────────────────────

    public function testPinnedCodeFixesReducedRateEuServiceThatDerivationGivesStandard(): void
    {
        $payload = $this->euServicesPayload(12.0);
        $applier = $this->buildApplier();

        // Bez volby: samovyměření se odvozuje jen v základní sazbě (D4 z #86)
        // — dodavatelova 12 % se ignoruje, řádek dostane cz-217 / 21 %.
        $plain = $applier->preview($payload)->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('derived', $plain['matchedBy']);
        $this->assertSame('cz-217', $plain['createPayload']['code']);
        $this->assertSame(21.0, $plain['createPayload']['pct']);

        $pinned = $applier->preview($this->withPins($payload, ['rows[0].vatCode' => 'useCode:cz-218']))->canonical;
        $codes = $this->issueCodes($pinned);
        $this->assertNotContains('vat_code_unknown', $codes);
        $this->assertNotContains('vat_code_pin_invalid', $codes);
        $this->assertNotContains('vat_code_pin_conflict', $codes);

        $vatCode = $pinned['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('matched', $vatCode['status']);
        $this->assertSame('user', $vatCode['matchedBy']);
        $this->assertSame('cz-218', $vatCode['createPayload']['code']);
        // Sazba z číselníku k DUZP 2026, ne 0 % z dokladu dodavatele.
        $this->assertSame(12.0, $vatCode['createPayload']['pct']);

        // Rekapitulace (task A) volbu následuje: nárok + oddaňovací pár.
        $recap = $pinned['_resolve']['computed']['vatRecap'];
        $this->assertSame(['cz-218', 'cz-208'], array_column($recap, 'vatCode'));
        $this->assertSame(1239.67, $recap[0]['tax']);
        $this->assertTrue($recap[1]['isReversePair']);
        $this->assertSame(10330.58, $pinned['_resolve']['computed']['totals']['totalAmount']);
    }

    public function testPinOverridesDerivedCodeAndReportsConflict(): void
    {
        // Služby z EU → derivace cz-217; uživatel zvolí zboží cz-215.
        $payload = $this->withPins($this->euServicesPayload(), ['rows[0].vatCode' => 'useCode:cz-215']);

        $result = $this->buildApplier()->preview($payload)->canonical;

        $vatCode = $result['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('matched', $vatCode['status']);
        $this->assertSame('user', $vatCode['matchedBy']);
        $this->assertSame('cz-215', $vatCode['createPayload']['code']);
        $conflicts = $this->issuesOf($result, 'vat_code_pin_conflict');
        $this->assertCount(1, $conflicts);
        $this->assertSame('warning', $conflicts[0]['severity']);
        $this->assertSame('rows.0.vat.code', $conflicts[0]['path']);
        $this->assertStringContainsString('cz-217', $conflicts[0]['message'], 'zpráva nese odvozený kód');
        $this->assertNotContains('vat_code_derived', $this->issueCodes($result));
        $this->assertSame('cz-215', $result['_resolve']['computed']['vatRecap'][0]['vatCode']);
    }

    public function testReducedDeductionPinOnDomesticInvoiceIsAcceptedWithoutConflict(): void
    {
        $payload = $this->withPins($this->happyPayload(), ['rows[0].vatCode' => 'useCode:cz-118']);

        $result = $this->buildApplier()->preview($payload)->canonical;

        $vatCode = $result['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('user', $vatCode['matchedBy']);
        $this->assertSame('cz-118', $vatCode['createPayload']['code']);
        $this->assertSame(21.0, $vatCode['createPayload']['pct']);
        $codes = $this->issueCodes($result);
        $this->assertNotContains('vat_code_pin_conflict', $codes);
        $this->assertNotContains('vat_code_pin_invalid', $codes);
    }

    public function testPinOutsideOptionsIsInvalidAndBlocksApply(): void
    {
        $applier = $this->buildApplier();
        foreach (['cz-217' => 'kód jiného místa', 'cz-301' => 'kód bez sazby k datu', 'xx-1' => 'neexistující klíč'] as $code => $why) {
            $payload = $this->withPins($this->happyPayload(), ['rows[0].vatCode' => "useCode:{$code}"]);

            $preview = $applier->preview($payload)->canonical;
            $invalid = $this->issuesOf($preview, 'vat_code_pin_invalid');
            $this->assertCount(1, $invalid, $why);
            $this->assertSame('error', $invalid[0]['severity'], $why);
            $this->assertSame('rows.0.vat.code', $invalid[0]['path'], $why);
            $this->assertSame('notFound', $preview['_resolve']['rows'][0]['vatCode']['status'], $why);

            $applied = $applier->apply($payload);
            $this->assertFalse($applied->success, $why);
            $this->assertSame('validation_failed', $applied->errorCode, $why);
        }
    }

    public function testVatCodeOptionsFollowEffectivePlace(): void
    {
        $applier = $this->buildApplier();

        // intracom k DUZP 2026: 8 vstupních kódů − 4 bez platné sazby (cz-390–393).
        $eu = $applier->preview($this->euServicesPayload())->canonical;
        $this->assertSame(['cz-215', 'cz-216', 'cz-217', 'cz-218'], $this->optionCodes($eu));
        $option = $eu['_resolve']['vatCodeOptions'][2];
        $this->assertSame('EU/Vstup/Služby/Základní', $option['label']);
        $this->assertSame(21.0, $option['pct']);
        $this->assertTrue($option['reverseCharge']);
        $this->assertFalse($option['reducedDeduction']);

        // tuzemsko: včetně kráceného odpočtu, bez hidden a bez kódů bez sazby.
        $domestic = $this->optionCodes($applier->preview($this->happyPayload())->canonical);
        $this->assertContains('cz-118', $domestic);
        $this->assertContains('cz-110', $domestic);
        $this->assertNotContains('cz-301', $domestic);
        $this->assertNotContains('cz-203', $domestic);
        $this->assertNotContains('cz-217', $domestic);
    }

    // ── Místo plnění a režim (D10, D11, D14) ────────────────────────────────

    public function testPlacePinOverridesVatIdDerivation(): void
    {
        $payload = $this->withPins($this->euServicesPayload(), ['vat.place' => 'useValue:domestic']);
        $applier = $this->buildApplier();

        $preview = $applier->preview($payload)->canonical;
        // `auto` = místo bez volby (z DIČ) — select ukazuje „Automaticky (EU)“ i po volbě.
        $this->assertSame(['value' => 'domestic', 'source' => 'user', 'auto' => 'intracom'], $preview['_resolve']['vat']['place']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($preview));
        $this->assertContains('cz-110', $this->optionCodes($preview), 'nabídka je pro zvolené místo');
        $this->assertNotContains('cz-217', $this->optionCodes($preview));

        // Bez volby by DIČ DE dalo intracom.
        $plain = $applier->preview($this->euServicesPayload())->canonical;
        $this->assertSame(['value' => 'intracom', 'source' => 'vatId', 'auto' => 'intracom'], $plain['_resolve']['vat']['place']);

        // Doklad dostane tuzemsko; kód řádku si uživatel dovolí tuzemský.
        $applied = $applier->apply($this->withPins($payload, ['rows[0].vatCode' => 'useCode:cz-110']));
        $this->assertTrue($applied->success, (string) $applied->errorMessage);
        $this->assertSame(0, $this->savedHeadsData['vat_place']);
        $this->assertSame('cz-110', $this->savedHeadsData['rows'][0]['vat_code']);
    }

    public function testModePinFromTotalAppliesWithoutDerivedOrSuspectIssue(): void
    {
        $payload = $this->happyPayload();
        unset($payload['vatRecap']);
        $payload = $this->withPins($payload, ['vat.mode' => 'useValue:fromTotal']);
        $applier = $this->buildApplier();

        $preview = $applier->preview($payload)->canonical;
        $this->assertSame(['value' => 'fromTotal', 'source' => 'user', 'auto' => 'fromBase'], $preview['_resolve']['vat']['mode']);
        $codes = $this->issueCodes($preview);
        $this->assertNotContains('vat_mode_derived', $codes);
        $this->assertNotContains('vat_mode_suspect', $codes);
        // Ceny řádků jsou teď s DPH: 10 × 1 033,06 = celkem k úhradě.
        $this->assertSame(10330.60, $preview['_resolve']['computed']['totals']['totalAmount']);

        $this->assertTrue($applier->apply($payload)->success);
        $this->assertSame(2, $this->savedHeadsData['vat_mode']);
    }

    public function testModePinNoneWithReverseChargeSilentlyFallsBackToFromBase(): void
    {
        $payload = $this->withPins($this->euServicesPayload(), ['vat.mode' => 'useValue:none']);
        $applier = $this->buildApplier();

        $preview = $applier->preview($payload)->canonical;
        $this->assertSame(['value' => 'fromBase', 'source' => 'derived', 'auto' => 'fromBase'], $preview['_resolve']['vat']['mode']);
        $this->assertNotContains('vat_mode_derived', $this->issueCodes($preview), 'proti volbě tiše (D11)');

        $this->assertTrue($applier->apply($payload)->success);
        $this->assertSame(1, $this->savedHeadsData['vat_mode']);
    }

    public function testHeaderWithoutPinsReportsEffectiveSources(): void
    {
        $preview = $this->buildApplier()->preview($this->happyPayload())->canonical;

        $this->assertSame(['value' => 'domestic', 'source' => 'vatId', 'auto' => 'domestic'], $preview['_resolve']['vat']['place']);
        $this->assertSame('fromBase', $preview['_resolve']['vat']['mode']['value']);
        $this->assertSame($preview['_resolve']['vat']['mode']['value'], $preview['_resolve']['vat']['mode']['auto']);
        $this->assertContains($preview['_resolve']['vat']['mode']['source'], ['ai', 'derived']);
    }

    // ── Rozsah a validace (D9, D11) ─────────────────────────────────────────

    public function testPinsOutsideDeriveBranchAreIgnoredWithInfo(): void
    {
        $payload = $this->withPins($this->issuedPayload(), [
            'vat.place'        => 'useValue:intracom',
            'vat.mode'         => 'useValue:fromTotal',
            'rows[0].vatCode'  => 'useCode:cz-118',
        ]);

        $preview = $this->buildApplier()->preview($payload)->canonical;

        $ignored = $this->issuesOf($preview, 'vat_pin_ignored');
        $this->assertSame(['vat.place', 'vat.mode', 'rows.0.vat.code'], array_column($ignored, 'path'));
        $this->assertSame(['info', 'info', 'info'], array_column($ignored, 'severity'));
        // Chování beze změny: hodnoty z canonicalu, kód řádku z canonicalu.
        $this->assertSame(['value' => 'domestic', 'source' => 'ai', 'auto' => 'domestic'], $preview['_resolve']['vat']['place']);
        $this->assertSame('cz-110', $preview['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertArrayNotHasKey('vatCodeOptions', $preview['_resolve']);
    }

    public function testInvalidPinValuesAreReportedAndIgnored(): void
    {
        $payload = $this->withPins($this->happyPayload(), [
            'vat.place'       => 'useValue:eu',
            'vat.mode'        => 'create',
            'rows[0].vatCode' => 'useCode:',
        ]);

        $preview = $this->buildApplier()->preview($payload)->canonical;

        $invalid = $this->issuesOf($preview, 'vat_pin_invalid');
        $this->assertSame(['vat.place', 'vat.mode', 'rows.0.vat.code'], array_column($invalid, 'path'));
        $this->assertSame(['error', 'error', 'error'], array_column($invalid, 'severity'));
        // Neplatná volba se neuplatní — kód řádku zůstává z canonicalu.
        $this->assertSame('cz-110', $preview['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame('cfgItem', $preview['_resolve']['rows'][0]['vatCode']['matchedBy']);
    }

    // ── Apply ───────────────────────────────────────────────────────────────

    public function testApplyPersistsPinnedCodeAndRateAndMatchesPreview(): void
    {
        $payload = $this->withPins($this->euServicesPayload(12.0), ['rows[0].vatCode' => 'useCode:cz-218']);
        $applier = $this->buildApplier();

        $computed = $applier->preview($payload)->canonical['_resolve']['computed'];
        $applied = $applier->apply($payload);

        $this->assertTrue($applied->success, (string) $applied->errorMessage);
        $saved = $this->savedHeadsData;
        $this->assertSame('cz-218', $saved['rows'][0]['vat_code']);
        $this->assertSame(12.0, $saved['rows'][0]['vat_pct']);
        $this->assertSame(['cz-218', 'cz-208'], array_column($saved['vatRecap'], 'vat_code'));
        $this->assertSame($computed['totals']['totalAmount'], (float) $saved['total_amount']);
        $this->assertSame('user', $applied->canonical['_resolve']['rows'][0]['vatCode']['matchedBy']);
    }
}
