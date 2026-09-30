<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\NumberSeriesNotFoundException;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Testable applier with `executeSql` no-op'd. `Dibi\Connection::query()` is
 * final and cannot be mocked, but the applier wraps it in a protected
 * method so subclasses can intercept.
 */
class TestableDocumentApplier extends DocumentApplier
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class DocumentApplierTest extends TestCase
{
    /**
     * Výchozí config: jen typy dokladů (směr obchodu pro snapshot partnera
     * v import módu jde přes DocDocument::resolveTradeDir), ostatní cfgItems
     * null jako u holého mocku.
     */
    private function defaultConfig(): ConfigRuntime
    {
        $docTypes = [
            'invno'   => ['trade_dir' => 1],
            'invpo'   => ['trade_dir' => 1, 'tax_document' => false],
            'invni'   => ['trade_dir' => 2],
            'cmnbkp'  => ['trade_dir' => 0],
            'cash'    => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            'cashreg' => ['trade_dir' => 1, 'series_binding' => 'cash_desk'],
        ];
        // Názvy míst plnění pro zprávu vat_place_derived (skutečný číselník).
        self::$vatPlaces ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/docs/core/config/vatPlaces.jsonc');
        $vatPlaces = self::$vatPlaces;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docTypes'  => $docTypes,
                'docs.core.vatPlaces' => $vatPlaces,
                default               => null,
            },
        );
        return $config;
    }

    private static ?array $vatPlaces = null;

    private function buildApplier(
        ?Connection $db = null,
        ?PartyResolver $party = null,
        ?ItemResolver $item = null,
        ?UnitResolver $unit = null,
        ?VatCodeResolver $vat = null,
        ?BankAccountResolver $bank = null,
        ?TransactionlessTableGateway $heads = null,
        ?TransactionlessTableGateway $persons = null,
        ?TransactionlessTableGateway $items = null,
        ?AccountResolver $account = null,
        ?ConfigRuntime $config = null,
        ?VatCodeDerivation $derivation = null,
        ?VatPlaceDerivation $placeDerivation = null,
    ): DocumentApplier {
        $db ??= $this->createMock(Connection::class);
        $party ??= $this->createMock(PartyResolver::class);
        $item ??= $this->createMock(ItemResolver::class);
        $unit ??= $this->createMock(UnitResolver::class);
        if ($vat === null) {
            // Default: kódy s prefixem cz- jsou v číselníku, cokoli jiného ne.
            // Rekapitulace jde přes resolver i v transform() (I7), takže
            // nekonfigurovaný mock by vrátil neinicializovaný ResolveResult.
            $vat = $this->createMock(VatCodeResolver::class);
            $vat->method('resolve')->willReturnCallback(
                static fn (?string $code): ResolveResult => str_starts_with((string) $code, 'cz-')
                    ? new ResolveResult(
                        ResolveStatus::Matched,
                        matchedId: 0,
                        matchedBy: 'cfgItem',
                        createPayload: ['code' => $code, 'pct' => null, 'reverseVatCode' => null, 'noPayTax' => false],
                    )
                    : ResolveResult::notFound(),
            );
        }
        $bank ??= $this->createMock(BankAccountResolver::class);
        $heads ??= $this->createMock(TransactionlessTableGateway::class);
        $persons ??= $this->createMock(TransactionlessTableGateway::class);
        $items ??= $this->createMock(TransactionlessTableGateway::class);
        $account ??= $this->createMock(AccountResolver::class);

        return new TestableDocumentApplier(
            db: $db,
            config: $config ?? $this->defaultConfig(),
            headsGateway: $heads,
            personsGateway: $persons,
            itemsGateway: $items,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: $vat,
            bankAccountResolver: $bank,
            accountResolver: $account,
            // Skutečná derivace nad vat-cz.jsonc; uplatní se jen u přijatého
            // dokladu, když DB mock vrátí registraci DPH (dbWithVatRegistration).
            vatCodeDerivation: $derivation ?? new VatCodeDerivation($this->vatCzRateResolver()),
            // Skutečné unie (tradeUnions.jsonc) — místo plnění z prefixu DIČ
            // dodavatele, tasks/exchange-received-vat-place.md D1/D2.
            vatPlaceDerivation: $placeDerivation ?? new VatPlaceDerivation($this->tradeUnionResolver()),
        );
    }

    private static ?array $tradeUnions = null;

    /** Skutečný cfgItem world.trade.unions (tradeUnions.jsonc). */
    private function tradeUnionResolver(): TradeUnionResolver
    {
        self::$tradeUnions ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/trade/config/tradeUnions.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null,
        );
        return new TradeUnionResolver($config);
    }

    private static ?array $vatCz = null;

    /**
     * Skutečný číselník world.vat.cz (vat-cz.jsonc) — derivace kódu a sazba
     * k datu (D4) běží nad reálnými daty, ne nad kopií v testu.
     */
    private function vatCzRateResolver(): VatRateResolver
    {
        self::$vatCz ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/vat/config/vat-cz.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null,
        );
        return new VatRateResolver($config);
    }

    /** DB mock zdroje s jednou aktivní registrací DPH (cz, id 5); ostatní dotazy null. */
    private function dbWithVatRegistration(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            return str_contains($sql, 'economy_codebooks_vat_registrations')
                ? new Row(['id' => 5, 'country' => 'cz'])
                : null;
        });
        return $db;
    }

    /**
     * Applier pro testy derivace kódu DPH: skutečný VatCodeResolver nad
     * vat-cz.jsonc (výchozí mock vrací pct null a reverseVatCode null),
     * strany / položky / jednotky / banka matched.
     */
    private function buildVatDerivingApplier(?Connection $db = null): DocumentApplier
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

        return $this->buildApplier(
            db: $db ?? $this->dbWithVatRegistration(),
            party: $party,
            item: $item,
            unit: $unit,
            vat: new VatCodeResolver($this->vatCzRateResolver()),
            bank: $bank,
        );
    }

    /** @return array<string, mixed> */
    private function happyPayload(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * Přijatá faktura za služby od dodavatele z jiného státu EU, na dokladu
     * „reverse charge“ a DPH 0, kódy DPH null (tvar promptu v4.6.0).
     * Fiktivní dodavatel, částky z happy fixture.
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

    /** @return array<string, mixed>|null */
    private function issueByCode(ApplyResult $result, string $code): ?array
    {
        foreach ($result->canonical['_resolve']['issues'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === $code) {
                return $issue;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function issueCodes(ApplyResult $result): array
    {
        return array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
    }

    public function testValidateRejectsSchemaInvalidPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            // missing formatVersion + docType
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testValidateRejectsMissingRequiredFields(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            // missing supplier + dates + rows
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $this->assertNotEmpty($issues);
    }

    public function testValidateAcceptsHappyFixture(): void
    {
        $applier = $this->buildApplier();
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->validate($payload);
        $this->assertTrue($result->success, 'Errors: ' . json_encode($result->canonical['_resolve']['issues'] ?? []));
    }

    public function testPreviewPopulatesResolveBlock(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $resolve = $result->canonical['_resolve'];
        $this->assertSame('matched', $resolve['supplier']['status']);
        $this->assertSame(42, $resolve['supplier']['matchedId']);
        $this->assertSame('matched', $resolve['supplierBank']['status']);
        $this->assertSame(7, $resolve['supplierBank']['matchedId']);
        $this->assertSame('matched', $resolve['rows'][0]['item']['status']);
        $this->assertSame(18, $resolve['rows'][0]['item']['matchedId']);
        // Text řádku pro review modal = to, co applier zapíše (#84 D2).
        $this->assertSame(
            \Shipard\Module\Core\Exchange\Document\CanonicalRowText::compose($payload['rows'][0]),
            $resolve['rows'][0]['rowText'],
        );
        $this->assertSame('Konzultace — Hodinová sazba senior konzultanta', $resolve['rows'][0]['rowText']);
        $this->assertSame('ok', $resolve['summary']['status']);
        // supplier + supplierBank + row[0].item + row[0].unit + row[0].vatCode = 5
        $this->assertSame(5, $resolve['summary']['matchedCount']);
    }

    /**
     * `rowText` je u každého řádku — i u kontačního bez `item` (text
     * z řádkové úrovně) a u řádku bez jakéhokoli textu (null, ne chybějící
     * klíč, aby frontend nemusel rozlišovat).
     */
    public function testPreviewRowTextForRowsWithoutItem(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->preview([
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'accountingDocument',
            'dates'         => ['issueDate' => '2026-06-10'],
            'rows'          => [
                ['rowKind' => 'item', 'operation' => 'acc.record', 'accSide' => 'debit', 'account' => '568001', 'totalPrice' => 120.0, 'description' => 'Poplatek za vedení účtu'],
                ['rowKind' => 'item', 'operation' => 'acc.record', 'accSide' => 'credit', 'account' => '221001', 'totalPrice' => 120.0],
            ],
        ]);

        $rows = $result->canonical['_resolve']['rows'];
        $this->assertSame('Poplatek za vedení účtu', $rows[0]['rowText']);
        $this->assertArrayHasKey('rowText', $rows[1]);
        $this->assertNull($rows[1]['rowText']);
    }

    public function testPreviewMarksCanCreateAsUnresolved(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Brand New s.r.o.']));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::canCreate(['iban' => 'CZ...']));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('canCreate', $resolve['supplier']['status']);
        $this->assertSame('canCreate', $resolve['supplierBank']['status']);
        $this->assertSame('needsAttention', $resolve['summary']['status']);
        $this->assertGreaterThan(0, $resolve['summary']['unresolvedCount']);
    }

    public function testApplyFailsWithUnresolvedRequiredWhenUserActionMissing(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'X']));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
    }

    public function testApplyFailsWithConflictWhenUseExistingTargetGone(): void
    {
        $db = $this->createMock(Connection::class);
        // Reconcile probes whether base_persons_persons #99 exists → returns null
        $db->method('fetch')->willReturn(null);

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::ambiguous([
            ['id' => 99, 'name' => 'Maybe Acme', 'companyId' => '123'],
        ]));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(db: $db, party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['_resolve'] = [
            'supplier' => ['userAction' => 'useExisting:99'],
        ];
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('conflict', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    public function testResolveOneAcceptsUseExistingOnArchivedTarget(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 2468]); // záznam existuje — byť v archívu (70)
        });
        $applier = $this->buildApplier(db: $db);

        $plan = ['errorCode' => null, 'errorMessage' => null];
        $issues = [];
        $ref = new \ReflectionMethod($applier, 'resolveOne');
        $res = $ref->invokeArgs($applier, [
            'customer',
            ['status' => 'notFound'],
            'useExisting:2468',
            'base_persons_persons',
            &$plan,
            &$issues,
        ]);

        $this->assertSame(2468, $res['id']);
        $this->assertNull($plan['errorCode']);
        // Pin smí mířit i na archiv (70); odmítá se jen Smazáno (90).
        $this->assertContains(70, $captured);
        $this->assertNotContains(90, $captured);
    }

    public function testResolvePinAcceptsLinkableTargetAndWarnsWhenMissing(): void
    {
        // a) linkable cíl (např. archivovaná osoba) projde bez issues
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 77]));
        $applier = $this->buildApplier(db: $db);

        $issues = [];
        $ref = new \ReflectionMethod($applier, 'resolvePin');
        $this->assertSame(77, $ref->invokeArgs($applier, ['partner', 'useExisting:77', &$issues]));
        $this->assertSame([], $issues);

        // b) smazaný/neexistující cíl → null + warning (žádná tichá ztráta)
        $db2 = $this->createMock(Connection::class);
        $db2->method('fetch')->willReturn(null);
        $applier2 = $this->buildApplier(db: $db2);

        $issues2 = [];
        $ref2 = new \ReflectionMethod($applier2, 'resolvePin');
        $id = $ref2->invokeArgs($applier2, ['rows.0.partner', 'useExisting:99', &$issues2]);
        $this->assertNull($id);
        $this->assertCount(1, $issues2);
        $this->assertSame('warning', $issues2[0]['severity']);
        $this->assertSame('pin_target_missing', $issues2[0]['code']);
        $this->assertSame('rows.0.partner', $issues2[0]['path']);
    }

    public function testApplyFailsWithSchemaInvalidOnBrokenStructure(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->apply([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            // missing docType
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testApplyFailsWithValidationFailedOnMissingIssueDate(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat);

        $result = $applier->apply([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor', 'companyId' => '12345678'],
            // dates.issueDate is missing → validation_failed
            'rows' => [['rowKind' => 'item', 'item' => ['name' => 'X']]],
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
    }

    // ── autoCreateMode (Phase 2) ────────────────────────────────────────────

    /**
     * Helper to build a fully-stubbed canonical payload where every reference
     * is matched except `supplier` which is canCreate with a configurable
     * payload. Used for autoCreateMode tests.
     *
     * @param array<string, mixed> $supplierCreatePayload
     * @param array<string, mixed>|null $applyOptions
     * @return array<string, mixed>
     */
    private function payloadWithCanCreateSupplier(array $supplierCreatePayload, ?array $applyOptions = null): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        if ($applyOptions !== null) {
            $payload['applyOptions'] = $applyOptions;
        }
        return $payload;
    }

    /**
     * Build resolvers where supplier=canCreate with given payload, everything
     * else matched. Used across autoCreateMode tests.
     *
     * @param array<string, mixed> $supplierCreatePayload
     * @param array<string, mixed>|null $itemCreatePayload  if non-null, item is canCreate too
     */
    private function buildAutoCreateResolvers(
        array $supplierCreatePayload,
        ?array $itemCreatePayload = null,
    ): array {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate($supplierCreatePayload));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        if ($itemCreatePayload === null) {
            $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        } else {
            $item->method('resolve')->willReturn(ResolveResult::canCreate($itemCreatePayload));
        }

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        return ['party' => $party, 'item' => $item, 'unit' => $unit, 'vat' => $vat, 'bank' => $bank];
    }

    public function testStrictModeIsDefaultAndRejectsAutoCreate(): void
    {
        $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X', 'company_id' => '12345678']);
        $applier = $this->buildApplier(
            party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );

        // No applyOptions → defaults to strict
        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testSafeModeAutoCreatesPartyWithCompanyId(): void
    {
        $resolvers = $this->buildAutoCreateResolvers([
            'person_type' => 2,
            'full_name'   => 'Brand New s.r.o.',
            'company_id'  => '12345678',
        ]);
        // Provide a stubbed personsGateway that returns the new id on saveDocument
        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->expects($this->once())
            ->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 99]));

        // headsGateway succeeds too (mocking what would normally need DocDocument flow)
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 1234]));

        // db: number_series / vat_registration lookups → null; query (supplier-code
        // mapping + lineage UPDATE) → no-op; begin/commit no-op via mock defaults.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);

        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
            heads: $heads, persons: $persons,
        );

        $payload = $this->payloadWithCanCreateSupplier(
            ['full_name' => 'X', 'company_id' => '12345678'],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
        $this->assertSame(1234, $result->savedId);
    }

    /**
     * Ruční zařazení do KH (#77): canonical `vat.controlStatementMode` →
     * `cs_mode`; `auto` a chybějící hodnota klíč do payloadu nedají
     * (default sloupce 0, na DS bez economy.vat sloupec ani neexistuje).
     */
    public function testControlStatementModeMapsToCsModeOnlyWhenManual(): void
    {
        $cases = [['exclude', 3], ['detail', 1], ['aggregate', 2], ['auto', null], [null, null]];
        foreach ($cases as [$mode, $expected]) {
            $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X', 'company_id' => '12345678']);
            $persons   = $this->createMock(TransactionlessTableGateway::class);
            $persons->method('saveDocument')->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 99]));

            $saved = null;
            $heads = $this->createMock(TransactionlessTableGateway::class);
            $heads->method('saveDocument')->willReturnCallback(static function (array $data) use (&$saved) {
                $saved = $data;
                return \Shipard\Core\Document\DocumentResult::ok(['id' => 1234]);
            });

            $db = $this->createMock(Connection::class);
            $db->method('fetch')->willReturn(null);
            $db->method('getInsertId')->willReturn(0);

            $applier = $this->buildApplier(
                db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
                vat: $resolvers['vat'], bank: $resolvers['bank'],
                heads: $heads, persons: $persons,
            );

            $payload = $this->payloadWithCanCreateSupplier([], applyOptions: ['autoCreateMode' => 'safe']);
            if ($mode !== null) {
                $payload['vat']['controlStatementMode'] = $mode;
            }
            $result = $applier->apply($payload);

            $this->assertTrue($result->success, "mode '{$mode}': {$result->errorCode} {$result->errorMessage}");
            $this->assertIsArray($saved);
            if ($expected === null) {
                $this->assertArrayNotHasKey('cs_mode', $saved, "mode '{$mode}' nemá do payloadu dávat cs_mode");
            } else {
                $this->assertSame($expected, $saved['cs_mode'] ?? null, "mode '{$mode}'");
            }
        }
    }

    public function testSafeModeRejectsPartyWithoutCompanyId(): void
    {
        $resolvers = $this->buildAutoCreateResolvers([
            'person_type' => 2,
            'full_name'   => 'Brand New s.r.o.',
            // company_id missing → guard fails
        ]);
        $applier = $this->buildApplier(
            party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );

        $payload = $this->payloadWithCanCreateSupplier(
            ['full_name' => 'X'],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testSafeModeRejectsItemWithoutName(): void
    {
        // Supplier matched, item canCreate without name → safe guard fails on item
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::canCreate(['code' => 'X-001']));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched, matchedId: 0, matchedBy: 'cfgItem',
            createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = $this->payloadWithCanCreateSupplier(
            [],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testLiberalModeAutoCreatesEverything(): void
    {
        $resolvers = $this->buildAutoCreateResolvers(
            ['full_name' => 'X'], // no company_id — would fail safe guard
            itemCreatePayload: ['code' => 'X-001'], // no name — would fail safe guard
        );

        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 99]));
        $items = $this->createMock(TransactionlessTableGateway::class);
        $items->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 50]));
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 1234]));

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);
        // Liberal autocreate populates supplier-code mapping; mock db->query.

        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
            heads: $heads, persons: $persons, items: $items,
        );

        $payload = $this->payloadWithCanCreateSupplier(
            [],
            applyOptions: ['autoCreateMode' => 'liberal'],
        );
        $result = $applier->apply($payload);

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
    }

    public function testIdempotentReplayReturnsExistingSavedDocId(): void
    {
        // Zpráva už nese docs target (target_table_id + target_row) —
        // opakovaný apply vrací existující savedDocId bez ukládání.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('core_mail_incoming_messages'), 678)
            ->willReturn(new Row(['target_table_id' => 'docs_core_heads', 'target_row' => 1234]));

        // Heads gateway must NEVER be called — idempotent fast-path
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->expects($this->never())->method('saveDocument');

        $applier = $this->buildApplier(db: $db, heads: $heads);

        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $payload['source']['message'] = 678;
        $result = $applier->apply($payload);

        $this->assertTrue($result->success);
        $this->assertSame(1234, $result->savedId);
        $this->assertSame('alreadyApplied', $result->canonical['_resolve']['summary']['status']);
    }

    public function testIdempotentSkippedWhenMessageHasNoDocsTarget(): void
    {
        // Zpráva bez targetu (target_row NULL) — applier NESMÍ short-circuitnout,
        // pokračuje normální cestou.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['target_table_id' => null, 'target_row' => null]));

        $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X']);
        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );
        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $payload['source']['message'] = 678;
        // No applyOptions → strict mode → expected to fail with unresolved_required
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testIdempotentSkippedWhenTargetIsDifferentTable(): void
    {
        // Target míří jinam (registry) — docs apply se nesmí tvářit jako
        // už aplikovaný.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(
            new Row(['target_table_id' => 'base_registry_documents', 'target_row' => 55]),
        );

        $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X']);
        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );
        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $payload['source']['message'] = 678;
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    // ── transform(): import-mode virtual fields ─────────────────────────────

    /**
     * Invoke the private transform() with a minimal plan/sideIds via reflection.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function invokeTransform(DocumentApplier $applier, array $canonical): array
    {
        $plan = [
            'resolvedSupplier' => 5, 'resolvedCustomer' => null, 'resolvedSupplierBank' => null,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
        ];
        $sideIds = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];

        $ref = new \ReflectionMethod($applier, 'transform');
        return $ref->invoke($applier, $canonical, $plan, $sideIds, null);
    }

    public function testTransformPassesImportNumberAsVirtualField(): void
    {
        $applier = $this->buildApplier(); // db mock → resolveNumberSeries/Vat return null
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber'        => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
                'importOwnBankAccount' => 17,
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            $data['_importNumber'],
        );
        $this->assertSame(17, $data['bank_account']);
    }

    public function testTransformMapsFiscalPeriodTypeOnlyInImportMode(): void
    {
        // #69 D20: uzávěrkový / otevírací doklad zařadí do period_type 0/2
        // jen import; AI extrakce ani ruční apply pole nesmí použít.
        $applier = $this->buildApplier();
        $base = [
            'docType'          => 'accountingDocument',
            'dates'            => ['issueDate' => '2024-12-31'],
            'fiscalPeriodType' => 'closing',
        ];

        $imported = $this->invokeTransform($applier, $base + [
            'applyOptions' => ['importNumber' => ['docNumber' => '2024-9001', 'sequenceNumber' => 9001]],
        ]);
        $this->assertSame('closing', $imported['fiscal_period_type']);

        $manual = $this->invokeTransform($applier, $base);
        $this->assertArrayNotHasKey('fiscal_period_type', $manual, 'mimo import mód se ignoruje');

        $bogus = $this->invokeTransform($applier, ['fiscalPeriodType' => 'monthly'] + $base + [
            'applyOptions' => ['importNumber' => ['docNumber' => '2024-9002', 'sequenceNumber' => 9002]],
        ]);
        $this->assertArrayNotHasKey('fiscal_period_type', $bogus, 'neznámá hodnota se nepropíše');
    }

    public function testTransformPreservesExplicitNullSequenceNumber(): void
    {
        // Migrated duplicate keys: number outside the series formula travels
        // as sequenceNumber = null and must NOT be coerced to 0 (which would
        // trigger DocDocument's malformed-payload fallback).
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber' => ['docNumber' => '2024-0042-2', 'sequenceNumber' => null],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042-2', 'sequenceNumber' => null],
            $data['_importNumber'],
        );
    }

    public function testTransformCoercesMissingSequenceNumberToZero(): void
    {
        // Absent key (as opposed to explicit null) keeps the legacy behavior:
        // 0 → DocDocument falls back to normal number assignment.
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber' => ['docNumber' => '2024-0042'],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042', 'sequenceNumber' => 0],
            $data['_importNumber'],
        );
    }

    /** #72: ruční plátce z `balanceParty` → partner_balance + partner_balance_manual = 1. */
    public function testTransformWritesBalancePartyAsManualPayer(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'      => 'invoiceIssued',
            'selfParty'    => 'supplier',
            'dates'        => ['issueDate' => '2024-06-01'],
            'balanceParty' => ['name' => 'Platební brána s.r.o.', 'companyId' => '12345678'],
        ];
        $plan = [
            'resolvedSupplier' => null, 'resolvedCustomer' => 5, 'resolvedSupplierBank' => null,
            'resolvedBalanceParty' => 77,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
        ];
        $sideIds = ['supplier' => null, 'customer' => null, 'balanceParty' => null, 'supplierBank' => null, 'rowItems' => []];
        $data = (new \ReflectionMethod($applier, 'transform'))->invoke($applier, $canonical, $plan, $sideIds, null);

        $this->assertSame(77, $data['partner_balance']);
        $this->assertSame(1, $data['partner_balance_manual']);

        // Side-create má přednost před plánem (nově založená osoba).
        $sideIds['balanceParty'] = 78;
        $data = (new \ReflectionMethod($applier, 'transform'))->invoke($applier, $canonical, $plan, $sideIds, null);
        $this->assertSame(78, $data['partner_balance']);

        // Bez plátce klíče vypadnou — odvození je na DocDocument.
        $data = $this->invokeTransform($applier, ['docType' => 'invoiceIssued', 'selfParty' => 'supplier', 'dates' => ['issueDate' => '2024-06-01']]);
        $this->assertArrayNotHasKey('partner_balance', $data);
        $this->assertArrayNotHasKey('partner_balance_manual', $data);
    }

    public function testTransformOmitsImportFieldsWhenNotRequested(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        // array_filter drops null _importNumber and null bank_account.
        $this->assertArrayNotHasKey('_importNumber', $data);
        $this->assertArrayNotHasKey('bank_account', $data);
    }

    public function testTransformBuildsImportPartnerSnapshotFromSupplier(): void
    {
        // Import mód + selfParty=customer: partnerská strana = supplier.
        // Kanonická strana se překládá do tvaru PersonSnapshotBuilder.
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'supplier'  => [
                'name'              => 'Dodavatel s.r.o.',
                'country'           => 'cz',
                'companyId'         => '12345678',
                'taxId'             => 'CZ12345678',
                'vatId'             => 'CZ99999999', // dobové DIČ ze staré hlavičky
                'courtRegistration' => 'MS v Praze, C 123',
                'contact'           => ['email' => 'a@b.cz', 'phone' => '+420 111', 'web' => 'https://b.cz'],
                'address'           => [
                    'street' => 'Hlavní', 'houseNumber' => '1', 'city' => 'Praha',
                    'cityPart' => 'Nové Město', 'zip' => '11000', 'country' => 'CZ',
                    'registryCode' => '123', 'displayLine' => 'Hlavní 1, 110 00 Praha',
                ],
                'bankAccount'       => [
                    'accountNumber' => '123/0300', 'iban' => 'CZ6503000000000123',
                    'bic' => 'CEKOCZPP', 'currency' => 'CZK',
                ],
            ],
            'applyOptions' => ['importNumber' => ['docNumber' => 'X-1', 'sequenceNumber' => 1]],
        ];

        $data = $this->invokeTransform($applier, $canonical);
        $snap = $data['_importPartnerSnapshot'];

        $this->assertSame('Dodavatel s.r.o.', $snap['name']);
        $this->assertSame('12345678', $snap['company_id']);
        $this->assertSame('CZ12345678', $snap['tax_id']);
        $this->assertSame('CZ99999999', $snap['vat_id']);
        $this->assertSame('MS v Praze, C 123', $snap['court_registration']);
        $this->assertSame('a@b.cz', $snap['contact']['email']);
        $this->assertSame('+420 111', $snap['contact']['phone']);
        $this->assertSame('Hlavní', $snap['address']['street']);
        $this->assertSame('1', $snap['address']['house_number']);
        $this->assertSame('Nové Město', $snap['address']['city_part']);
        $this->assertSame('cz', $snap['address']['country']);
        $this->assertSame('Hlavní 1, 110 00 Praha', $snap['address']['display_line']);
        $this->assertArrayNotHasKey('registryCode', $snap['address']);
        $this->assertSame('123/0300', $snap['bank_account']['account_number']);
        $this->assertSame('czk', $snap['bank_account']['currency']);
        $this->assertNull($snap['bank_account']['name']);
    }

    public function testTransformImportSnapshotOmitsMissingSections(): void
    {
        // Bez adresy a banky sekce chybí úplně; prázdné vatId → null (jako
        // PersonSnapshotBuilder u prázdných DB sloupců).
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'supplier'  => ['name' => 'Beta s.r.o.', 'companyId' => '111', 'vatId' => ''],
            'applyOptions' => ['importNumber' => ['docNumber' => 'X-1', 'sequenceNumber' => 1]],
        ];

        $snap = $this->invokeTransform($applier, $canonical)['_importPartnerSnapshot'];

        $this->assertSame('Beta s.r.o.', $snap['name']);
        $this->assertNull($snap['vat_id']);
        $this->assertNull($snap['tax_id']);
        $this->assertArrayNotHasKey('address', $snap);
        $this->assertArrayNotHasKey('bank_account', $snap);
        $this->assertNull($snap['contact']['email']);
    }

    public function testTransformImportSnapshotUsesCustomerWhenSelfSupplier(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceIssued',
            'selfParty' => 'supplier',
            'dates'     => ['issueDate' => '2024-06-01'],
            'supplier'  => ['name' => 'Naše firma s.r.o.'],
            'customer'  => ['name' => 'Odběratel a.s.', 'vatId' => 'CZ11122233'],
            'applyOptions' => ['importNumber' => ['docNumber' => 'X-1', 'sequenceNumber' => 1]],
        ];

        $snap = $this->invokeTransform($applier, $canonical)['_importPartnerSnapshot'];

        $this->assertSame('Odběratel a.s.', $snap['name']);
        $this->assertSame('CZ11122233', $snap['vat_id']);
    }

    /** Zálohová faktura vydaná (#79 D1): import vytvoří invpo bez DUZP/DPPD. */
    public function testTransformProformaIssuedInImportModeDropsTaxDates(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'proformaIssued',
            'selfParty' => 'supplier',
            'dates'     => [
                'issueDate'         => '2024-06-01',
                'dueDate'           => '2024-06-15',
                'taxPointDate'      => '2024-06-01',
                'vatObligationDate' => '2024-06-01',
            ],
            'supplier'  => ['name' => 'Naše firma s.r.o.'],
            'customer'  => ['name' => 'Odběratel a.s.', 'vatId' => 'CZ11122233'],
            'applyOptions' => ['importNumber' => ['docNumber' => '122400001', 'sequenceNumber' => 1]],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame('invpo', $data['doc_type']);
        $this->assertArrayNotHasKey('vat_duzp', $data, 'nedaňový doklad DUZP nenese');
        $this->assertArrayNotHasKey('vat_dppd', $data);
        $this->assertSame('2024-06-01', $data['issue_date']);
        $this->assertSame('2024-06-15', $data['due_date']);
        $this->assertSame(['docNumber' => '122400001', 'sequenceNumber' => 1], $data['_importNumber']);
        $this->assertSame('Odběratel a.s.', $data['_importPartnerSnapshot']['name']);
    }

    /** Regrese: faktura vydaná DUZP/DPPD z kanonického dokladu přebírá. */
    public function testTransformInvoiceIssuedKeepsTaxDates(): void
    {
        $applier = $this->buildApplier();
        $data = $this->invokeTransform($applier, [
            'docType'   => 'invoiceIssued',
            'selfParty' => 'supplier',
            'dates'     => ['issueDate' => '2024-06-01', 'taxPointDate' => '2024-06-02', 'vatObligationDate' => '2024-06-03'],
            'supplier'  => ['name' => 'Naše firma s.r.o.'],
            'customer'  => ['name' => 'Odběratel a.s.'],
        ]);

        $this->assertSame('2024-06-02', $data['vat_duzp']);
        $this->assertSame('2024-06-03', $data['vat_dppd']);
    }

    public function testTransformImportSnapshotAbsentWithoutImportNumber(): void
    {
        // Mimo import mód se snapshot payload nestaví vůbec.
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'supplier'  => ['name' => 'Dodavatel s.r.o.', 'vatId' => 'CZ99999999'],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertArrayNotHasKey('_importPartnerSnapshot', $data);
    }

    public function testTransformImportSnapshotAbsentForAccountingDocument(): void
    {
        // cmnbkp nemá strany — snapshot payload se nestaví ani v import módu.
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'accountingDocument',
            'dates'     => ['issueDate' => '2024-06-01'],
            'supplier'  => ['name' => 'Nemá tu co dělat'],
            'applyOptions' => ['importNumber' => ['docNumber' => 'X-1', 'sequenceNumber' => 1]],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertArrayNotHasKey('_importPartnerSnapshot', $data);
    }

    public function testTransformToleratesNullVat(): void
    {
        // Top-level `vat` je od schema fixes nullable — null se musí chovat
        // stejně jako chybějící objekt (defaulty fromBase/domestic).
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'vat'       => null,
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(1, $data['vat_mode']);  // fromBase
        $this->assertSame(0, $data['vat_place']); // domestic
    }

    // ── transform(): text řádku (#84 D1/D2) ─────────────────────────────────

    /**
     * Řádek z AI návrhu s `item.name` i `item.description` nese na dokladu
     * složený text (CanonicalRowText) — stejný, jaký ukázal review modal.
     * Dřív vyhrál samotný `item.description` („DPHM Množství" místo
     * „Natural 95").
     */
    public function testTransformWritesComposedRowText(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-09-01'],
            'rows'      => [
                ['item' => ['name' => 'Natural 95', 'description' => 'DPHM Množství'], 'quantity' => 40, 'unitPrice' => 38.8],
                ['item' => ['name' => 'Natural 95', 'description' => 'natural 95'], 'quantity' => 1, 'unitPrice' => 1.0],
                ['item' => ['name' => 'Doprava'], 'quantity' => 1, 'unitPrice' => 500.0],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame('Natural 95 — DPHM Množství', $data['rows'][0]['description']);
        // Popis obsažený v názvu (case-insensitive) se nepřilepí.
        $this->assertSame('Natural 95', $data['rows'][1]['description']);
        $this->assertSame('Doprava', $data['rows'][2]['description']);
    }

    /**
     * Účetní doklad / export: řádek bez `item` nese text na řádkové úrovni
     * a zůstává beze změny; top-level description má přednost i vedle
     * vyplněného item fragmentu (dataset round-trip nesmí řádky obohacovat).
     */
    public function testTransformKeepsTopLevelRowDescription(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'accountingDocument',
            'dates'     => ['issueDate' => '2026-09-01'],
            'rows'      => [
                ['description' => 'Poplatek za vedení účtu', 'operation' => 'acc.record', 'accSide' => 'debit', 'account' => '568001', 'totalPrice' => 120.0],
                ['description' => 'Konzultace', 'item' => ['name' => 'Konzultace', 'description' => 'Hodinová sazba'], 'quantity' => 1, 'unitPrice' => 1000.0],
                ['operation' => 'acc.record', 'accSide' => 'credit', 'account' => '221001', 'totalPrice' => 120.0],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame('Poplatek za vedení účtu', $data['rows'][0]['description']);
        $this->assertSame('Konzultace', $data['rows'][1]['description']);
        // Bez textu → klíč chybí (array_filter), ne prázdný řetězec.
        $this->assertArrayNotHasKey('description', $data['rows'][2]);
    }

    // ── transform(): autorita rekapitulace DPH (#75, R3/I4/I7) ─────────────

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function transformWithRecap(array $extra): array
    {
        $applier = $this->buildApplier();
        $canonical = array_merge([
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-07-01'],
        ], $extra);

        return $this->invokeTransform($applier, $canonical);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<int, array<string, mixed>>
     */
    private function recapIssues(array $extra): array
    {
        $applier = $this->buildApplier();
        $canonical = array_merge([
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-07-01'],
        ], $extra);

        $issues = [];
        $ref = new \ReflectionMethod($applier, 'appendRecapSourceIssue');
        $ref->invokeArgs($applier, [$canonical, &$issues]);
        return $issues;
    }

    /** Deklarovaná rekapitulace se uloží 1:1 — je to fakt z dokladu. */
    public function testDeclaredRecapIsPassedThroughUnchanged(): void
    {
        $data = $this->transformWithRecap([
            'vat'      => ['mode' => 'fromTotal', 'recapSource' => 'declared'],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 90.91, 'tax' => 19.09, 'total' => 110.00],
            ],
        ]);

        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertSame([[
            'vat_code' => 'cz-110', 'vat_pct' => 21.0,
            'base' => 90.91, 'tax' => 19.09, 'total' => 110.00,
            'is_reverse_pair' => 0,
        ]], $data['vatRecap']);
        $this->assertSame([], $this->recapIssues([
            'vat'      => ['recapSource' => 'declared'],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 90.91, 'tax' => 19.09, 'total' => 110.00],
            ],
        ]));
    }

    /**
     * Deklarovaná rekapitulace s přenesením daňové povinnosti (`base + tax
     * ≠ total`) se **nesmí** přepočítat — u PDP je to správně a import ze
     * starého Shipardu na tom stojí.
     */
    public function testDeclaredRecapSurvivesReverseChargeArithmetic(): void
    {
        $data = $this->transformWithRecap([
            'vat'      => ['recapSource' => 'declared'],
            'vatRecap' => [
                ['vatCode' => 'cz-115', 'vatPct' => 21, 'base' => 1000.00, 'tax' => 210.00, 'total' => 1000.00],
            ],
        ]);

        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertSame(1000.00, $data['vatRecap'][0]['total']);
    }

    /** I4: přijatý doklad z AI s konzistentní rekapitulací → převzatá. */
    public function testReceivedDocumentWithConsistentRecapBecomesDeclared(): void
    {
        $data = $this->transformWithRecap([
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]);

        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertCount(1, $data['vatRecap']);
    }

    /** I4: nekonzistentní rekapitulace → přepočítaná + info issue. */
    public function testReceivedDocumentWithInconsistentRecapFallsBackToComputed(): void
    {
        $recap = [
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 100.00, 'tax' => 12.00, 'total' => 121.00],
            ],
        ];
        $data = $this->transformWithRecap($recap);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertArrayNotHasKey('vatRecap', $data, 'přepočítanou si DocDocument spočítá z řádků');

        $issues = $this->recapIssues($recap);
        $this->assertCount(1, $issues);
        $this->assertSame('info', $issues[0]['severity']);
        $this->assertSame('recap_source_computed_fallback', $issues[0]['code']);
        $this->assertSame('vat.recapSource', $issues[0]['path']);
    }

    /**
     * I7: kód, který resolver v číselníku země nenajde, znamená přepočítanou
     * + info issue s důvodem — ne DomainException z DocDocument a 500.
     */
    public function testReceivedDocumentWithUnknownRecapCodeFallsBackToComputed(): void
    {
        $recap = [
            'vatRecap' => [
                ['vatCode' => 'xx-999', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ];
        $data = $this->transformWithRecap($recap);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertArrayNotHasKey('vatRecap', $data);

        $issues = $this->recapIssues($recap);
        $this->assertCount(1, $issues);
        $this->assertSame('recap_source_computed_fallback', $issues[0]['code']);
        $this->assertStringContainsString('xx-999', $issues[0]['message']);
        $this->assertStringContainsString('země XX', $issues[0]['message']);
    }

    /** I7 platí i pro explicitní `declared` — bez kódu nejdou určit flagy sčítání. */
    public function testDeclaredRecapWithUnknownCodeFallsBackToComputed(): void
    {
        $recap = [
            'vat'      => ['recapSource' => 'declared'],
            'vatRecap' => [
                ['vatCode' => 'highEU', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
            'supplier' => ['country' => 'CZ'],
        ];
        $data = $this->transformWithRecap($recap);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertArrayNotHasKey('vatRecap', $data);
        $this->assertStringContainsString('highEU', $this->recapIssues($recap)[0]['message']);
    }

    /** Kaskáda země pro kód rekapitulace: bez prefixu v kódu země dodavatele. */
    public function testRecapCodeResolvesWithSupplierCountryWhenNoPrefix(): void
    {
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->expects($this->once())->method('resolve')
            ->with('special110', 'sk', '2026-07-01', 21.0)
            ->willReturn(new ResolveResult(
                ResolveStatus::Matched,
                matchedId: 0,
                matchedBy: 'cfgItem',
                createPayload: ['code' => 'special110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
            ));
        $applier = $this->buildApplier(vat: $vat);

        $data = $this->invokeTransform($applier, [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-07-01'],
            'supplier'  => ['country' => 'SK'],
            'vatRecap'  => [
                ['vatCode' => 'special110', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]);

        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertSame('special110', $data['vatRecap'][0]['vat_code']);
    }

    /** Vystavený doklad rekapitulaci nepřebírá — počítáme ji my. */
    public function testIssuedDocumentKeepsComputedRecap(): void
    {
        $data = $this->transformWithRecap([
            'docType'   => 'invoiceIssued',
            'selfParty' => 'supplier',
            'vatRecap'  => [
                ['vatCode' => 'cz-210', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertArrayNotHasKey('vatRecap', $data);
    }

    /**
     * I7: ISDOC rekapitulace kódy nenese — dohledají se z řádků, když je
     * pro sazbu jednoznačný.
     */
    public function testRecapWithoutCodeDerivesItFromRows(): void
    {
        $data = $this->transformWithRecap([
            'vat'      => ['recapSource' => 'declared'],
            'rows'     => [
                ['rowKind' => 'item', 'totalPrice' => 100.0, 'vat' => ['code' => 'cz-110', 'pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]);

        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertSame('cz-110', $data['vatRecap'][0]['vat_code']);
    }

    /** Dvě různé kódy v téže sazbě → kód nejde odvodit, přepočítaná. */
    public function testRecapWithoutCodeAndAmbiguousRowsFallsBack(): void
    {
        $payload = [
            'vat'      => ['recapSource' => 'declared'],
            'rows'     => [
                ['rowKind' => 'item', 'totalPrice' => 100.0, 'vat' => ['code' => 'cz-110', 'pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 100.0, 'vat' => ['code' => 'cz-115', 'pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 200.00, 'tax' => 42.00, 'total' => 242.00],
            ],
        ];
        $data = $this->transformWithRecap($payload);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertSame('recap_source_computed_fallback', $this->recapIssues($payload)[0]['code']);
    }

    /** Explicitní `computed` rekapitulaci nepřebírá ani u přijatého dokladu. */
    public function testExplicitComputedWins(): void
    {
        $data = $this->transformWithRecap([
            'vat'      => ['recapSource' => 'computed'],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]);

        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertSame([], $this->recapIssues([
            'vat'      => ['recapSource' => 'computed'],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
            ],
        ]));
    }

    public function testCalcSourceMapsToHeadColumn(): void
    {
        $this->assertSame(1, $this->transformWithRecap([
            'vat' => ['calcSource' => 'rows'],
        ])['vat_calc_source']);
        $this->assertSame(0, $this->transformWithRecap([
            'vat' => ['calcSource' => 'header'],
        ])['vat_calc_source']);
        $this->assertSame(0, $this->transformWithRecap([])['vat_calc_source'], 'default = norma');
    }

    // ── transform(): derivace total_rounding_mode ────────────────────────────

    /**
     * @param array<string, mixed> $extra Merged over the minimal canonical.
     * @return array<string, mixed>
     */
    private function transformWithTotals(array $extra): array
    {
        $applier = $this->buildApplier();
        $canonical = array_merge([
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-07-01'],
        ], $extra);

        return $this->invokeTransform($applier, $canonical);
    }

    public function testDeriveRoundingModeFromRecapFeinkost(): void
    {
        // Reálný scénář z alfy (extracted doc 42): recap 45.00 + 1664.05
        // = 1709.05, deklarováno 1709.00 → matematické zaokrouhlení (mode 1).
        $data = $this->transformWithTotals([
            'vatRecap' => [
                ['vatCode' => 'cz-120', 'vatPct' => 12, 'total' => 45.00],
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'total' => 1664.05],
            ],
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00, 'totalRounding' => -0.05,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeCeil(): void
    {
        // computed 1708.40, declared 1709.00 → round dá 1708, ceil sedí → 3.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1708.40]],
            'totals'   => ['totalAmount' => 1709.00],
        ]);

        $this->assertSame(3, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeFloor(): void
    {
        // computed 1709.55, declared 1709.00 → round dá 1710, ceil 1710,
        // floor sedí → 4.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.55]],
            'totals'   => ['totalAmount' => 1709.00],
        ]);

        $this->assertSame(4, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModePrefersMathOverCeil(): void
    {
        // computed X.50: round half-up i ceil dají týž výsledek — mode 1
        // je konvence (D4).
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.50]],
            'totals'   => ['totalAmount' => 1710.00],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeSkippedWhenDiffTooLarge(): void
    {
        // Rozdíl 1.95 není zaokrouhlení — mode se nenastaví, warning
        // z validátoru zůstává.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.05]],
            'totals'   => ['totalAmount' => 1711.00],
        ]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeSkippedWhenWithinTolerance(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.05]],
            'totals'   => ['totalAmount' => 1709.05],
        ]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    /** Zaokrouhlení o haléř (starý Shipard: 69,99 + 0,01 = 70,00) je platný mod 1. */
    public function testDeriveRoundingModeOneHellerIsStillRounding(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 69.99]],
            'totals'   => ['totalAmount' => 70.00],
        ]);

        $this->assertSame(1, $data['total_rounding_mode'] ?? null);
    }

    /** Mód 5: slovenská hotovostní účtenka — recap 12,34, deklarováno 12,35. */
    public function testDeriveRoundingModeFiveCents(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 20, 'total' => 12.34]],
            'totals'   => ['totalAmount' => 12.35],
        ]);

        $this->assertSame(5, $data['total_rounding_mode']);
    }

    /** Akceptace #63/6: rozdíl 0,02 dolů — round5(12,33) = 12,35. */
    public function testDeriveRoundingModeFiveCentsTwoCentDiff(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 20, 'total' => 12.33]],
            'totals'   => ['totalAmount' => 12.35],
        ]);

        $this->assertSame(5, $data['total_rounding_mode']);
    }

    /** P2: celá declared je násobek 0,05 taky — mód 1 se zkouší první. */
    public function testDeriveRoundingModeWholePrefersMathOverFiveCents(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 69.99]],
            'totals'   => ['totalAmount' => 70.00],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    /** Declared mimo násobky 0,05 žádný mód nereprodukuje — klíč chybí. */
    public function testDeriveRoundingModeFiveCentsNotForOddCents(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 20, 'total' => 12.34]],
            'totals'   => ['totalAmount' => 12.36],
        ]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeSkippedWithoutTotals(): void
    {
        $data = $this->transformWithTotals([]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeFallsBackToBasePlusVat(): void
    {
        // Bez recapu se computed bere z totalBase + totalVat.
        $data = $this->transformWithTotals([
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeIncompleteRecapFallsBackToBasePlusVat(): void
    {
        // Recap s řádkem bez numeric total se nepoužije — nastupuje
        // totalBase + totalVat.
        $data = $this->transformWithTotals([
            'vatRecap' => [
                ['vatPct' => 12, 'total' => 45.00],
                ['vatPct' => 21], // total chybí
            ],
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeFallsBackToRows(): void
    {
        // Bez recapu i totalBase/totalVat se computed sčítá z řádků
        // s DPH per řádek: 999.67 × 1.21 = 1209.60 → declared 1209.00
        // je floor → mode 4.
        $data = $this->transformWithTotals([
            'rows'   => [['totalPrice' => 999.67, 'vat' => ['pct' => 21]]],
            'totals' => ['totalAmount' => 1209.00],
        ]);

        $this->assertSame(4, $data['total_rounding_mode']);
    }

    // ── transform() + preview(): derivace vat_mode ───────────────────────────

    /**
     * Řádky v koncových cenách (účtenka PHM): Σ řádků sedí na recap total.
     *
     * @return array<string, mixed>
     */
    private function receiptVatFragment(): array
    {
        return [
            'vat'  => ['mode' => 'fromBase', 'place' => 'domestic'],
            'rows' => [
                [
                    'rowKind'    => 'item',
                    'quantity'   => 45,
                    'unitPrice'  => 38.80,
                    'totalPrice' => 1746.00,
                    'vat'        => ['code' => 'cz-110', 'pct' => 21],
                ],
            ],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 1442.98, 'tax' => 303.02, 'total' => 1746.00],
            ],
            'totals' => ['totalBase' => 1442.98, 'totalVat' => 303.02, 'totalAmount' => 1746.00],
        ];
    }

    public function testTransformDerivesVatModeFromTotalOnReceipt(): void
    {
        $data = $this->transformWithTotals($this->receiptVatFragment());
        $this->assertSame(2, $data['vat_mode']);
    }

    public function testTransformKeepsDeclaredModeWhenRowsMatchBase(): void
    {
        // Korektní „zdola" faktura — derivace potvrdí deklarovaný mode.
        $data = $this->transformWithTotals([
            'vat'      => ['mode' => 'fromBase'],
            'rows'     => [['rowKind' => 'item', 'totalPrice' => 10330.58, 'vat' => ['pct' => 21]]],
            'vatRecap' => [['vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00]],
            'totals'   => ['totalBase' => 10330.58, 'totalVat' => 2169.42, 'totalAmount' => 12500.00],
        ]);
        $this->assertSame(1, $data['vat_mode']);
    }

    public function testTransformDerivesVatModeFromBaseWhenDeclaredFromTotal(): void
    {
        // Opačný směr: deklarováno shora, ale řádky sedí na base → mode 1.
        $data = $this->transformWithTotals([
            'vat'      => ['mode' => 'fromTotal'],
            'rows'     => [['rowKind' => 'item', 'totalPrice' => 10330.58, 'vat' => ['pct' => 21]]],
            'vatRecap' => [['vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00]],
        ]);
        $this->assertSame(1, $data['vat_mode']);
    }

    public function testTransformKeepsVatModeNoneUntouched(): void
    {
        // Deklarovaný mode none (bez DPH) derivace nikdy nepřebíjí.
        $fragment = $this->receiptVatFragment();
        $fragment['vat']['mode'] = 'none';
        $data = $this->transformWithTotals($fragment);
        $this->assertSame(0, $data['vat_mode']);
    }

    /**
     * @return array{0: \PHPUnit\Framework\MockObject\MockObject&PartyResolver, 1: \PHPUnit\Framework\MockObject\MockObject&ItemResolver, 2: \PHPUnit\Framework\MockObject\MockObject&UnitResolver, 3: \PHPUnit\Framework\MockObject\MockObject&VatCodeResolver, 4: \PHPUnit\Framework\MockObject\MockObject&BankAccountResolver}
     */
    private function buildMatchedResolvers(): array
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));
        return [$party, $item, $unit, $vat, $bank];
    }

    public function testPreviewAddsVatModeDerivedIssueOnReceipt(): void
    {
        [$party, $item, $unit, $vat, $bank] = $this->buildMatchedResolvers();
        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload = array_merge($payload, $this->receiptVatFragment());

        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $derived = array_values(array_filter($issues, static fn($i) => $i['code'] === 'vat_mode_derived'));
        $this->assertCount(1, $derived);
        $this->assertSame('warning', $derived[0]['severity']);
        $this->assertSame('vat.mode', $derived[0]['path']);
        $this->assertStringContainsString('fromTotal', $derived[0]['message']);
        // Korekce nesmí spustit duplicitní vat_mode_suspect z validátoru.
        $this->assertNull($this->findIssueByCode($issues, 'vat_mode_suspect'));
    }

    public function testPreviewHasNoVatModeIssueOnHappyFixture(): void
    {
        [$party, $item, $unit, $vat, $bank] = $this->buildMatchedResolvers();
        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $this->assertNull($this->findIssueByCode($issues, 'vat_mode_derived'));
        $this->assertNull($this->findIssueByCode($issues, 'vat_mode_suspect'));
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    private function findIssueByCode(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if (($issue['code'] ?? null) === $code) {
                return $issue;
            }
        }
        return null;
    }

    // ── resolveNumberSeriesFor(): code selection + error path ───────────────

    private function invokeResolveSeries(DocumentApplier $applier, string $docType, ?string $seriesCode): ?int
    {
        $ref = new \ReflectionMethod($applier, 'resolveNumberSeriesFor');
        return $ref->invoke($applier, $docType, $seriesCode);
    }

    public function testResolveNumberSeriesByCodeMatches(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 55]);
        });
        $applier = $this->buildApplier(db: $db);

        // kód 5 u invni → konkrétní řada (např. „Ostatní závazky")
        $this->assertSame(55, $this->invokeResolveSeries($applier, 'invni', '5'));
        // SQL filtruje podle doc_number_code a váže docType i kód
        $this->assertStringContainsString('doc_number_code', (string) $captured[0]);
        $this->assertContains('invni', $captured);
        $this->assertContains('5', $captured);
    }

    public function testResolveNumberSeriesByUnknownCodeThrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // nic nematchuje
        $applier = $this->buildApplier(db: $db);

        $this->expectException(NumberSeriesNotFoundException::class);
        $this->invokeResolveSeries($applier, 'invni', '999');
    }

    public function testResolveNumberSeriesWithoutCodeFallsBackToFirstActive(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 1]);
        });
        $applier = $this->buildApplier(db: $db);

        $this->assertSame(1, $this->invokeResolveSeries($applier, 'invni', null));
        // Stará cesta NESMÍ filtrovat podle doc_number_code (zpětná kompatibilita)
        $this->assertStringNotContainsString('doc_number_code', (string) $captured[0]);
    }

    // ── Účetní doklad (accountingDocument → cmnbkp) ─────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function invokeTransformWithPlan(DocumentApplier $applier, array $canonical, array $plan): array
    {
        $sideIds = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];
        $ref = new \ReflectionMethod($applier, 'transform');
        return $ref->invoke($applier, $canonical, $plan, $sideIds, null);
    }

    public function testValidateAcceptsAccountingDocumentWithoutParty(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit', 'totalPrice' => 1000.0],
                ['operation' => 'acc.record', 'account' => '321100', 'accSide' => 'credit', 'totalPrice' => 1000.0],
            ],
        ]);
        // Žádný požadavek na partnera/selfParty — accountingDocument je
        // party-agnostický (DocumentValidator switch default).
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? []));
    }

    public function testTransformAccountingDocumentRowsCarryContation(): void
    {
        $applier = $this->buildApplier(); // db mock → series/vat reg null
        $canonical = [
            'docType' => 'accountingDocument',
            'dates'   => ['issueDate' => '2026-06-10', 'accountingDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit',
                 'totalPrice' => 1000.0],
                ['operation' => 'acc.record', 'account' => '321100', 'accSide' => 'credit',
                 'totalPrice' => 1000.0, 'paymentReference' => 'VS123', 'dueDate' => '2026-07-10'],
            ],
        ];
        $plan = [
            'resolvedHeadPartner' => null,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [0 => 195, 1 => 207],
            'resolvedRowPartners' => [1 => 42],
        ];

        $data = $this->invokeTransformWithPlan($applier, $canonical, $plan);

        $this->assertSame('cmnbkp', $data['doc_type']);
        $this->assertArrayNotHasKey('partner', $data); // hlavička bez partnera → null → array_filter

        $rows = $data['rows'];
        $this->assertCount(2, $rows);

        // MD řádek: účet 195, strana 0, částka přímo (price_calc_mode 1).
        $this->assertSame(195, $rows[0]['account']);
        $this->assertSame(0, $rows[0]['acc_side']);
        $this->assertSame(1000.0, $rows[0]['total_price']);
        $this->assertSame(1, $rows[0]['price_calc_mode']);
        $this->assertArrayNotHasKey('partner', $rows[0]);

        // DAL řádek: účet 207, strana 1, per-řádkový partner + VS + splatnost.
        $this->assertSame(207, $rows[1]['account']);
        $this->assertSame(1, $rows[1]['acc_side']);
        $this->assertSame(42, $rows[1]['partner']);
        $this->assertSame('VS123', $rows[1]['payment_reference']);
        $this->assertSame('2026-07-10', $rows[1]['due_date']);
    }

    public function testTransformAccountingDocumentUsesHeadPartnerPin(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType' => 'accountingDocument',
            'dates'   => ['issueDate' => '2026-06-10'],
            'rows'    => [['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit', 'totalPrice' => 50.0]],
        ];
        $plan = [
            'resolvedHeadPartner' => 77,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [0 => 195], 'resolvedRowPartners' => [],
        ];

        $data = $this->invokeTransformWithPlan($applier, $canonical, $plan);
        $this->assertSame(77, $data['partner']);
    }

    public function testMapDocTypeValueTranslatesAliasAndPassesThrough(): void
    {
        $this->assertSame('invni', DocumentApplier::mapDocTypeValue('invoiceReceived'));
        $this->assertSame('invno', DocumentApplier::mapDocTypeValue('invoiceIssued'));
        $this->assertSame('invpo', DocumentApplier::mapDocTypeValue('proformaIssued'));
        $this->assertSame('cmnbkp', DocumentApplier::mapDocTypeValue('accountingDocument'));
        $this->assertSame('invni', DocumentApplier::mapDocTypeValue('invni'));
        $this->assertSame('xyz', DocumentApplier::mapDocTypeValue('xyz'));
    }

    public function testPreviewCarriesOverRowEnrichment(): void
    {
        // withResolve staví _resolve z fresh resolvu — enrichment audit
        // (RowHistoryEnricher) z příchozího canonical musí přežít per index.
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $enrichment = [
            'matchedBy'   => 'historyExactNorm',
            'confidence'  => 'high',
            'sourceDocId' => 1001,
            'suggested'   => ['ourCode' => 'NET500'],
        ];
        $payload['_resolve'] = ['rows' => [['index' => 0, 'enrichment' => $enrichment]]];

        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $rowResolve = $result->canonical['_resolve']['rows'][0];
        $this->assertSame(0, $rowResolve['index']);
        $this->assertSame($enrichment, $rowResolve['enrichment']);
        // Fresh resolve zůstává nedotčený vedle přeneseného auditu.
        $this->assertSame('matched', $rowResolve['item']['status']);
    }

    /**
     * Kaskáda země pro resolve DPH kódu řádku, když model top-level "vat"
     * vynechá (nullable od promptu v2.3.0): prefix z kódu „{země}-{číslo}“.
     * Bez fallbacku by každý řádek skončil vat_code_unknown (reálný případ:
     * účtenka OMV, extracted doc 9 na alfě).
     */
    /**
     * Kaskáda země (registrationCountry → prefix kódu → dodavatel) — tři
     * testy níže jedou nad přijatým dokladem na zdroji BEZ registrace DPH
     * (DB mock fetch → null), kde D2 ani derivace neplatí. Se známou
     * registrací je země vždy naše (testReceivedRegistrationCountryOfSupplierIsIgnored).
     */
    public function testRowVatCountryFallsBackToCodePrefixWhenVatObjectMissing(): void
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

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->expects($this->atLeastOnce())->method('resolve')
            ->with('cz-110', 'cz', $this->anything(), $this->anything())
            ->willReturn(new ResolveResult(
                ResolveStatus::Matched,
                matchedId: 0,
                matchedBy: 'cfgItem',
                createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
            ));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        unset($payload['vat']);
        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $resolve = $result->canonical['_resolve'];
        $this->assertSame('matched', $resolve['rows'][0]['vatCode']['status']);
        $codes = array_column($resolve['issues'] ?? [], 'code');
        $this->assertNotContains('vat_code_unknown', $codes);
    }

    /** Bez prefixu v kódu se použije země dodavatele. */
    public function testRowVatCountryFallsBackToSupplierCountryWithoutPrefix(): void
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

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->expects($this->atLeastOnce())->method('resolve')
            ->with('special110', 'sk', $this->anything(), $this->anything())
            ->willReturn(ResolveResult::notFound());

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        unset($payload['vat']);
        $payload['supplier']['country'] = 'SK';
        $payload['rows'][0]['vat']['code'] = 'special110';
        // Rekapitulace jde stejnou kaskádou — bez prefixu také země dodavatele.
        $payload['vatRecap'][0]['vatCode'] = 'special110';
        $applier->preview($payload);
    }

    /** Explicitní vat.registrationCountry má přednost před prefixem kódu (zdroj bez registrace DPH). */
    public function testExplicitVatRegistrationCountryBeatsCodePrefix(): void
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

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->expects($this->atLeastOnce())->method('resolve')
            ->with('cz-110', 'de', $this->anything(), $this->anything())
            ->willReturn(ResolveResult::notFound());

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['vat']['registrationCountry'] = 'DE';
        $applier->preview($payload);
    }

    // ── Kód DPH přijatého dokladu ze signálů (VatCodeDerivation, D1–D5) ────

    public function testReceivedEuServicesDerivesReverseChargeCode(): void
    {
        $result = $this->buildVatDerivingApplier()->preview($this->euServicesPayload());

        $this->assertTrue($result->success);
        $vatCode = $result->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('matched', $vatCode['status']);
        $this->assertSame('derived', $vatCode['matchedBy']);
        $this->assertSame('cz-217', $vatCode['createPayload']['code']);
        // D4: sazba z našeho číselníku k DUZP, ne 0 z dokladu dodavatele.
        $this->assertSame(21.0, $vatCode['createPayload']['pct']);
        $this->assertSame('cz-207', $vatCode['createPayload']['reverseVatCode']);

        $codes = $this->issueCodes($result);
        $this->assertNotContains('vat_code_unknown', $codes);
        // Prázdný kód → derivace bez issue (jinak šum u každé faktury).
        $this->assertNotContains('vat_code_derived', $codes);
        // D3: rekapitulace dodavatele (0 %) se nepřebírá.
        $recap = $this->issueByCode($result, 'recap_source_computed_fallback');
        $this->assertNotNull($recap);
        $this->assertStringContainsString('přenesení daňové povinnosti', $recap['message']);
    }

    public function testReceivedEuServicesTransformUsesOwnRegistrationAndComputedRecap(): void
    {
        $data = $this->invokeTransform($this->buildVatDerivingApplier(), $this->euServicesPayload());

        $this->assertSame(1, $data['vat_place']);
        $this->assertSame(5, $data['vat_registration']);
        $this->assertSame(0, $data['vat_recap_source']);
        $this->assertArrayNotHasKey('vatRecap', $data);
    }

    public function testReceivedInventedVatCodeIsReplacedByDerived(): void
    {
        $payload = $this->euServicesPayload();
        $payload['rows'][0]['vat']['code'] = 'eu-reverse';
        $result = $this->buildVatDerivingApplier()->preview($payload);

        $vatCode = $result->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('cz-217', $vatCode['createPayload']['code']);
        $this->assertSame('derived', $vatCode['matchedBy']);
        $issue = $this->issueByCode($result, 'vat_code_derived');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('rows.0.vat.code', $issue['path']);
        $this->assertStringContainsString('eu-reverse', $issue['message']);
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($result));
    }

    /** Kód z historie řádků (RowHistoryEnricher) v rozporu se signály dokladu — derivace ho přebije. */
    public function testReceivedHistoryCodeInConflictWithSignalsIsReplaced(): void
    {
        $payload = $this->euServicesPayload();
        $payload['rows'][0]['vat']['code'] = 'cz-110';
        $result = $this->buildVatDerivingApplier()->preview($payload);

        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $issue = $this->issueByCode($result, 'vat_code_derived');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('cz-110', $issue['message']);
        $this->assertStringContainsString('místo plnění', $issue['message']);
    }

    /** Platný kód v souladu se signály zůstává — dnešní chování, i pro krácený odpočet z historie. */
    public function testReceivedConsistentCodeIsKept(): void
    {
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($this->happyPayload());
        $vatCode = $result->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('cz-110', $vatCode['createPayload']['code']);
        $this->assertSame('cfgItem', $vatCode['matchedBy']);
        $this->assertSame([], array_intersect(['vat_code_derived', 'vat_code_unknown'], $this->issueCodes($result)));

        $payload = $this->happyPayload();
        $payload['rows'][0]['vat']['code'] = 'cz-118';
        $result = $applier->preview($payload);
        $this->assertSame('cz-118', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_code_derived', $this->issueCodes($result));
    }

    /** Domácí faktura bez kódů (prompt v4.6.0): kód ze sazby a rekapitulace dál převzatá (#75). */
    public function testReceivedDomesticWithoutCodeDerivesFromRateAndKeepsDeclaredRecap(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'][0]['vat'] = ['code' => null, 'pct' => 21];
        $payload['vatRecap'][0]['vatCode'] = null;
        $payload['vat']['reverseCharge'] = false;
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        $vatCode = $result->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('cz-110', $vatCode['createPayload']['code']);
        $this->assertSame('derived', $vatCode['matchedBy']);
        $this->assertNotContains('recap_source_computed_fallback', $this->issueCodes($result));

        $data = $this->invokeTransform($applier, $payload);
        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertSame('cz-110', $data['vatRecap'][0]['vat_code']);
    }

    /** D2: registrationCountry z AI / ISDOC (země dodavatele) se u přijatého dokladu nepoužije. */
    public function testReceivedRegistrationCountryOfSupplierIsIgnored(): void
    {
        $payload = $this->happyPayload();
        $payload['vat']['registrationCountry'] = 'DE';
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        // cz-110 se hledá v našem číselníku (cz), ne v DE → matched.
        $this->assertSame('matched', $result->canonical['_resolve']['rows'][0]['vatCode']['status']);
        $issue = $this->issueByCode($result, 'vat_registration_country_derived');
        $this->assertNotNull($issue);
        $this->assertSame('info', $issue['severity']);
        $this->assertSame('vat.registrationCountry', $issue['path']);

        $data = $this->invokeTransform($applier, $payload);
        $this->assertSame(5, $data['vat_registration']);
    }

    public function testReceivedDomesticReverseChargeDerivesPdpCode(): void
    {
        $payload = $this->happyPayload();
        $payload['vat'] = ['mode' => 'fromBase', 'place' => 'domestic', 'reverseCharge' => true, 'registrationCountry' => null];
        $payload['rows'][0]['vat'] = ['code' => null, 'pct' => 0, 'supplyKind' => null, 'reverseChargeCode' => '4'];
        $payload['vatRecap'] = [['vatCode' => null, 'vatPct' => 0, 'base' => 10330.58, 'tax' => 0, 'total' => 10330.58]];
        $payload['totals'] = ['totalBase' => 10330.58, 'totalVat' => 0, 'totalAmount' => 10330.58, 'totalRounding' => 0];
        $result = $this->buildVatDerivingApplier()->preview($payload);

        $vatCode = $result->canonical['_resolve']['rows'][0]['vatCode'];
        $this->assertSame('cz-115', $vatCode['createPayload']['code']);
        $this->assertSame(21.0, $vatCode['createPayload']['pct']);
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($result));
        $this->assertStringContainsString(
            'přenesení daňové povinnosti',
            (string) ($this->issueByCode($result, 'recap_source_computed_fallback')['message'] ?? ''),
        );
    }

    public function testReceivedUnsupportedCaseReportsReasonAndBlocksApply(): void
    {
        $payload = $this->euServicesPayload();
        $payload['rows'][0]['vat']['supplyKind'] = null;
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $this->assertSame('notFound', $result->canonical['_resolve']['rows'][0]['vatCode']['status']);
        $issue = $this->issueByCode($result, 'vat_code_unknown');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertStringContainsString('druh plnění', $issue['message']);
        $this->assertStringContainsString('ručně', $issue['message']);

        $applied = $applier->apply($payload);
        $this->assertFalse($applied->success);
        $this->assertSame('validation_failed', $applied->errorCode);
    }

    /** Model u faktury s DPH 0 vrací vat.mode „none“ — doklad Bez DPH by rekapitulaci nestavěl a samovyměření ztratil. */
    public function testReceivedReverseChargeWithModeNoneIsForcedToFromBase(): void
    {
        $payload = $this->euServicesPayload();
        $payload['vat']['mode'] = 'none';
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        $issue = $this->issueByCode($result, 'vat_mode_derived');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('bez DPH', $issue['message']);
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);

        $data = $this->invokeTransform($applier, $payload);
        $this->assertSame(1, $data['vat_mode']);
        $this->assertSame(0, $data['vat_recap_source']);
    }

    /** Bez samovyměření zůstává „none“ nedotčené (dodavatel neplátce). */
    public function testReceivedModeNoneWithoutReverseChargeStaysNone(): void
    {
        $payload = $this->happyPayload();
        $payload['vat'] = ['mode' => 'none', 'place' => 'domestic', 'reverseCharge' => false, 'registrationCountry' => null];
        $payload['rows'][0]['vat'] = ['code' => null, 'pct' => 0];
        $payload['vatRecap'] = [];
        $payload['totals'] = ['totalBase' => 10330.58, 'totalVat' => 0, 'totalAmount' => 10330.58, 'totalRounding' => 0];
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        $this->assertNotContains('vat_mode_derived', $this->issueCodes($result));
        $this->assertSame(0, $this->invokeTransform($applier, $payload)['vat_mode']);
    }

    /** Zahraniční DPH naúčtovaná dodavatelem z EU není samovyměření (D7 otevřené) — bez tichého cz-217. */
    public function testReceivedForeignVatChargedBySupplierIsNotSelfAssessed(): void
    {
        $payload = $this->euServicesPayload();
        $payload['vat']['reverseCharge'] = false;
        $payload['rows'][0]['vat']['pct'] = 19;
        $result = $this->buildVatDerivingApplier()->preview($payload);

        $issue = $this->issueByCode($result, 'vat_code_unknown');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('zahraniční DPH', $issue['message']);
    }

    /** Explicitní declared (import ze starého Shipardu) s oddaňovacím párem zůstává převzatá. */
    public function testExplicitDeclaredRecapWithReversePairIsKept(): void
    {
        $payload = $this->euServicesPayload();
        $payload['vat']['recapSource'] = 'declared';
        $payload['rows'][0]['vat']['code'] = 'cz-217';
        $payload['vatRecap'] = [
            ['vatCode' => 'cz-217', 'vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 10330.58, 'isReversePair' => false],
            ['vatCode' => 'cz-207', 'vatPct' => 21, 'base' => 10330.58, 'tax' => -2169.42, 'total' => 0, 'isReversePair' => true],
        ];
        $applier = $this->buildVatDerivingApplier();

        $result = $applier->preview($payload);
        $this->assertNotContains('recap_source_computed_fallback', $this->issueCodes($result));
        $this->assertNotContains('vat_code_derived', $this->issueCodes($result));

        $data = $this->invokeTransform($applier, $payload);
        $this->assertSame(1, $data['vat_recap_source']);
        $this->assertCount(2, $data['vatRecap']);
        $this->assertSame(1, $data['vatRecap'][1]['is_reverse_pair']);
    }

    /** Zdroj bez registrace DPH (neplátce): derivace ani D2 se neuplatní, chování jako dřív. */
    public function testNonVatPayerDataSourceKeepsLegacyBehaviour(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $applier = $this->buildVatDerivingApplier($db);

        $result = $applier->preview($this->euServicesPayload());
        $this->assertTrue($result->success);
        $this->assertArrayNotHasKey('vatCode', $result->canonical['_resolve']['rows'][0]);
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($result));
        $this->assertNotContains('vat_registration_country_derived', $this->issueCodes($result));

        $data = $this->invokeTransform($applier, $this->euServicesPayload());
        $this->assertArrayNotHasKey('vat_registration', $data);
    }

    /** D5: neznámé vat.place / vat.mode → warning; schéma (enum) ale takový payload zachytí dřív. */
    public function testUnknownVatPlaceAndModeGetWarnings(): void
    {
        $applier = $this->buildApplier();
        $issues = [];
        $ref = new \ReflectionMethod($applier, 'appendVatHeaderIssues');
        $ref->invokeArgs($applier, [['selfParty' => 'customer', 'vat' => ['place' => 'eu', 'mode' => 'reverseCharge']], &$issues]);

        $byCode = array_column($issues, null, 'code');
        $this->assertSame('warning', $byCode['vat_place_unknown']['severity']);
        $this->assertSame('vat.place', $byCode['vat_place_unknown']['path']);
        $this->assertStringContainsString('eu', $byCode['vat_place_unknown']['message']);
        $this->assertSame('warning', $byCode['vat_mode_unknown']['severity']);

        $payload = $this->euServicesPayload();
        $payload['vat']['place'] = 'eu';
        $result = $this->buildVatDerivingApplier()->preview($payload);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
    }

    // ── Místo plnění z prefixu DIČ dodavatele (VatPlaceDerivation, D1) ─────
    // tasks/exchange-received-vat-place.md — fiktivní dodavatelé, DIČ IE1234567X.

    /**
     * Přijatá faktura za služby: dodavatel se sídlem mimo EU fakturuje pod
     * DIČ jiného členského státu; AI podle adresy řekla thirdCountry.
     *
     * @return array<string, mixed>
     */
    private function nonEuSupplierWithEuVatIdPayload(): array
    {
        $payload = $this->euServicesPayload();
        $payload['supplier']['country'] = 'us';
        $payload['supplier']['address']['country'] = 'us';
        $payload['supplier']['vatId'] = 'IE1234567X';
        $payload['vat']['place'] = 'thirdCountry';
        return $payload;
    }

    /** Scénář z diagnostiky: IE prefix přebije thirdCountry → cz-217 (ř. 5), warning. */
    public function testReceivedNonEuSupplierWithEuVatIdIsIntracom(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->nonEuSupplierWithEuVatIdPayload();

        $result = $applier->preview($payload);
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? null));
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_code_derived', $this->issueCodes($result));
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($result));

        $issue = $this->issueByCode($result, 'vat_place_derived');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('vat.place', $issue['path']);
        $this->assertStringContainsString('„Zahraničí“', $issue['message']);
        $this->assertStringContainsString('„Intrakomunitární plnění“', $issue['message']);
        $this->assertStringContainsString('prefix IE', $issue['message']);

        // Canonical se nemění — korekce jen v _resolve a v uloženém dokladu.
        $this->assertSame('thirdCountry', $result->canonical['vat']['place']);
        $this->assertSame('us', $result->canonical['supplier']['country']);

        $data = $this->invokeTransform($applier, $payload);
        $this->assertSame(1, $data['vat_place']);
    }

    /** AI místo nedala (null) → odvozené bez issue. */
    public function testReceivedNonEuSupplierWithEuVatIdAndNullPlaceHasNoIssue(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->nonEuSupplierWithEuVatIdPayload();
        $payload['vat']['place'] = null;

        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
        $this->assertSame(1, $this->invokeTransform($applier, $payload)['vat_place']);
    }

    /** Bez DIČ dodavatele platí hodnota z AI: thirdCountry → cz-417 (ř. 12). */
    public function testReceivedSupplierWithoutVatIdKeepsAiPlace(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->nonEuSupplierWithEuVatIdPayload();
        $payload['supplier']['vatId'] = null;

        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $this->assertSame('cz-417', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
        $this->assertSame(2, $this->invokeTransform($applier, $payload)['vat_place']);
    }

    /** Prefix, který unie nezná (US), derivaci vypne — platí AI. */
    public function testReceivedSupplierWithNonUnionVatIdKeepsAiPlace(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->nonEuSupplierWithEuVatIdPayload();
        $payload['supplier']['vatId'] = 'US12-3456789';

        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $this->assertSame('cz-417', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
    }

    /** ISDOC nedává vat.place: DE prefix → intracom bez issue. */
    public function testReceivedIsdocWithoutPlaceDerivesIntracomFromVatId(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->euServicesPayload();
        unset($payload['vat']['place']);

        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
        $this->assertSame(1, $this->invokeTransform($applier, $payload)['vat_place']);
    }

    /** Regrese: dodavatel z EU s DIČ své země — jako dřív, bez vat_place_derived. */
    public function testReceivedEuSupplierWithMatchingVatIdHasNoPlaceIssue(): void
    {
        $result = $this->buildVatDerivingApplier()->preview($this->euServicesPayload());
        $this->assertTrue($result->success);
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
    }

    /** Neznámá hodnota z AI mimo schema validaci: derivace ji nahradí, vat_place_unknown se nehlásí. */
    public function testReceivedUnknownPlaceReplacedByDerivedWithoutUnknownWarning(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $issues = [];
        $ref = new \ReflectionMethod($applier, 'appendVatHeaderIssues');
        $ref->invokeArgs($applier, [[
            'selfParty' => 'customer',
            'vat'       => ['place' => 'eu'],
            'supplier'  => ['name' => 'Fiktivní dodavatel', 'vatId' => 'IE1234567X'],
            'dates'     => ['issueDate' => '2026-04-15'],
            'rows'      => [],
        ], &$issues]);

        $codes = array_column($issues, 'code');
        $this->assertContains('vat_place_derived', $codes);
        $this->assertNotContains('vat_place_unknown', $codes);
        $byCode = array_column($issues, null, 'code');
        // Neznámou hodnotu uvést, jak přišla.
        $this->assertStringContainsString('„eu“', $byCode['vat_place_derived']['message']);
    }

    /** Vystavený doklad (selfParty supplier): žádná derivace, místo z AI. */
    public function testIssuedDocumentKeepsAiPlaceUntouched(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $ref = new \ReflectionMethod($applier, 'vatContext');
        $ctx = $ref->invoke($applier, [
            'selfParty' => 'supplier',
            'vat'       => ['place' => 'thirdCountry'],
            'supplier'  => ['name' => 'My', 'vatId' => 'IE1234567X'],
            'customer'  => ['name' => 'Odběratel', 'vatId' => 'US12-3456789'],
            'dates'     => ['issueDate' => '2026-04-15'],
            'rows'      => [],
        ]);

        $this->assertFalse($ctx['derive']);
        $this->assertSame('thirdCountry', $ctx['place']);
        $this->assertSame('ai', $ctx['placeSource']);
        $this->assertNull($ctx['placePrefix']);
    }

    /** Prohozené strany (naše DIČ u dodavatele) → bez derivace, platí AI. */
    public function testReceivedSwappedVatIdsSkipPlaceDerivation(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $payload = $this->euServicesPayload();
        $payload['supplier']['vatId'] = 'CZ12345678';
        $payload['customer'] = ['name' => 'Naše firma s.r.o.', 'country' => 'cz', 'vatId' => 'CZ 12345678'];

        $result = $applier->preview($payload);
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? null));
        $this->assertSame('cz-217', $result->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($result));
        $this->assertSame(1, $this->invokeTransform($applier, $payload)['vat_place']);
    }

    /** Klíč cache vatContext() nese DIČ stran — jinak by druhý doklad dostal místo prvního. */
    public function testVatContextCacheDistinguishesSupplierVatId(): void
    {
        $applier = $this->buildVatDerivingApplier();
        $withVatId = $this->nonEuSupplierWithEuVatIdPayload();
        $withoutVatId = $withVatId;
        $withoutVatId['supplier']['vatId'] = null;

        $first = $applier->preview($withVatId);
        $this->assertSame('cz-217', $first->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $second = $applier->preview($withoutVatId);
        $this->assertSame('cz-417', $second->canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_place_derived', $this->issueCodes($second));
    }

    // ── Doplnění pohybu (operation) na item řádcích při apply ───────────────

    /**
     * ConfigRuntime mock s reálnými jsonc konfiguracemi pohybů — testy
     * doplňování běží nad skutečnými mapami, ne nad kopií v testu.
     *
     * @param array<string, mixed>|null $applyOverride náhrada docs.core.applyRowOperations
     */
    private function buildRowOperationConfig(?array $applyOverride = null): ConfigRuntime
    {
        $root = dirname(__DIR__, 6);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.rowOperations',
             JsoncParser::parseFile($root . '/modules/docs/core/config/rowOperations.jsonc')],
            ['docs.core.applyRowOperations',
             $applyOverride ?? JsoncParser::parseFile($root . '/modules/docs/core/config/applyRowOperations.jsonc')],
        ]);
        return $config;
    }

    /**
     * Connection mock, jehož fetchAll vrací item_type per ID (kryje batch
     * fetch nad economy_items i economy_items_kinds — mapuje se přes ID).
     *
     * @param array<int, int> $types ID → item_type
     */
    private function buildItemTypesDb(array $types): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn(int $id, int $type) => ['id' => $id, 'item_type' => $type],
            array_keys($types),
            array_values($types),
        ));
        return $db;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<int, string>
     */
    private function invokeDefaultRowOps(DocumentApplier $applier, array $canonical, array $plan, array &$issues, array $rowItems = []): array
    {
        $sideIds = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => $rowItems];
        $ref = new \ReflectionMethod($applier, 'defaultRowOperationsForApply');
        $args = [$canonical, $plan, $sideIds, &$issues];
        return $ref->invokeArgs($applier, $args);
    }

    public function testRowOperationDefaultedByItemType(): void
    {
        $applier = $this->buildApplier(
            db: $this->buildItemTypesDb([11 => 0, 12 => 2, 99 => 1]),
            config: $this->buildRowOperationConfig(),
        );
        $canonical = ['docType' => 'invoiceReceived', 'rows' => [
            ['item' => ['name' => 'Konzultace']],
            ['item' => ['name' => 'Správní poplatek']],
            ['item' => ['name' => 'Kancelářský papír']],
        ]];
        // Řádky 0+1 matched, řádek 2 side-created — item_type se čte jednotně.
        $plan = ['rowSkips' => [], 'resolvedRowItems' => [0 => 11, 1 => 12]];
        $issues = [];
        $out = $this->invokeDefaultRowOps($applier, $canonical, $plan, $issues, rowItems: [2 => 99]);

        $this->assertSame([0 => 'purchase.services', 1 => 'acc.entry', 2 => 'purchase.goods'], $out);
        // Rutinní doplnění je tiché — žádné info issue (dodatek tasku).
        $this->assertSame([], $issues);
    }

    public function testRowOperationFallsBackToDocTypeDefault(): void
    {
        $applier = $this->buildApplier(config: $this->buildRowOperationConfig());
        $canonical = ['docType' => 'invoiceReceived', 'rows' => [
            ['description' => 'Řádek bez položky'],
        ]];
        $issues = [];
        $out = $this->invokeDefaultRowOps($applier, $canonical, ['rowSkips' => [], 'resolvedRowItems' => []], $issues);

        $this->assertSame([0 => 'acc.entry'], $out);
        $this->assertSame([], $issues);
    }

    public function testRowOperationInvnoMapsTypeAndFallsBackOnUnmapped(): void
    {
        // Typ 0 → sale.services (mapou), typ 3 v invno mapě není → default.
        $applier = $this->buildApplier(
            db: $this->buildItemTypesDb([21 => 0, 22 => 3]),
            config: $this->buildRowOperationConfig(),
        );
        $canonical = ['docType' => 'invoiceIssued', 'rows' => [
            ['item' => ['name' => 'Vývoj']],
            ['item' => ['name' => 'Ostatní služby']],
        ]];
        $issues = [];
        $out = $this->invokeDefaultRowOps(
            $applier, $canonical,
            ['rowSkips' => [], 'resolvedRowItems' => [0 => 21, 1 => 22]],
            $issues,
        );

        $this->assertSame([0 => 'sale.services', 1 => 'sale.services'], $out);
        $this->assertSame([], $issues);
    }

    public function testRowOperationSkipsPassthroughContationTextAndSkippedRows(): void
    {
        $applier = $this->buildApplier(config: $this->buildRowOperationConfig());
        $canonical = ['docType' => 'invoiceReceived', 'rows' => [
            ['operation' => 'purchase.other', 'item' => ['name' => 'Explicitní pohyb']],
            ['account' => '518100', 'accSide' => 'debit', 'totalPrice' => 100.0],
            ['rowKind' => 'text', 'description' => 'Textový řádek'],
            ['item' => ['name' => 'Přeskočený řádek']],
        ]];
        $issues = [];
        $out = $this->invokeDefaultRowOps(
            $applier, $canonical,
            ['rowSkips' => [3], 'resolvedRowItems' => []],
            $issues,
        );

        $this->assertSame([], $out);
        $this->assertSame([], $issues);
    }

    public function testRowOperationDocTypeWithoutConfigEntryUnchanged(): void
    {
        $applier = $this->buildApplier(config: $this->buildRowOperationConfig());
        $canonical = ['docType' => 'accountingDocument', 'rows' => [
            ['item' => ['name' => 'Položka']],
        ]];
        $issues = [];
        $out = $this->invokeDefaultRowOps($applier, $canonical, ['rowSkips' => [], 'resolvedRowItems' => []], $issues);

        $this->assertSame([], $out);
        $this->assertSame([], $issues);
    }

    public function testRowOperationUnknownCodeInConfigWarnsAndSkips(): void
    {
        $applier = $this->buildApplier(config: $this->buildRowOperationConfig([
            'invni' => ['byItemType' => [], 'default' => 'nonexistent.op'],
        ]));
        $canonical = ['docType' => 'invoiceReceived', 'rows' => [
            ['item' => ['name' => 'Položka']],
        ]];
        $issues = [];
        $out = $this->invokeDefaultRowOps($applier, $canonical, ['rowSkips' => [], 'resolvedRowItems' => []], $issues);

        $this->assertSame([], $out);
        $this->assertCount(1, $issues);
        $this->assertSame('row_operation_config_invalid', $issues[0]['code']);
        $this->assertSame('warning', $issues[0]['severity']);
    }

    public function testTransformUsesRowOperationDefaultsAndPassthroughWins(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType' => 'invoiceReceived',
            'dates'   => ['issueDate' => '2026-06-10'],
            'rows' => [
                ['quantity' => 1, 'unitPrice' => 100.0, 'totalPrice' => 100.0],
                ['operation' => 'purchase.other', 'quantity' => 1, 'unitPrice' => 50.0, 'totalPrice' => 50.0],
            ],
        ];
        $plan = [
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [],
            'resolvedRowVatCodes' => [], 'resolvedRowAccounts' => [], 'resolvedRowPartners' => [],
            'rowOperationDefaults' => [0 => 'purchase.services', 1 => 'acc.entry'],
        ];

        $data = $this->invokeTransformWithPlan($applier, $canonical, $plan);

        $this->assertSame('purchase.services', $data['rows'][0]['operation']);
        // Explicitní canonical operation má přednost před defaultem.
        $this->assertSame('purchase.other', $data['rows'][1]['operation']);
    }

    /**
     * Rutinní doplnění pohybu je tiché i na preview — dřívější info
     * issue `row_operation_defaulted` svítilo u každého apply a učilo
     * uživatele Upozornění přeskakovat (dodatek tasku
     * mail-apply-row-operation).
     */
    public function testPreviewEmitsNoRowOperationIssues(): void
    {
        [$party, $item, $unit, $vat, $bank] = $this->buildMatchedResolvers();
        $applier = $this->buildApplier(
            db: $this->buildItemTypesDb([18 => 0]),
            party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank,
            config: $this->buildRowOperationConfig(),
        );

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $this->assertNull($this->findIssueByCode($issues, 'row_operation_defaulted'));
        $this->assertNull($this->findIssueByCode($issues, 'row_operation_config_invalid'));
    }

    // ── applyOptions.importOwnBankAccount jako kód číselníku (datasety) ──

    /** Matched resolvery, aby apply došel až k 5c (bez side-creates). */
    private function buildApplierForOwnBankTests(Connection $db, ?TransactionlessTableGateway $heads = null): DocumentApplier
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(5, 'companyId'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched, matchedId: 0, matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        return $this->buildApplier(db: $db, party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank, heads: $heads);
    }

    public function testApplyRejectsUnknownOwnBankAccountCode(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // series default → null, bank code → not found
        $db->expects($this->never())->method('begin');

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['applyOptions'] = ['importOwnBankAccount' => 'MAIN'];

        $result = $this->buildApplierForOwnBankTests($db)->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('own_bank_account_not_found', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $this->assertStringContainsString("'MAIN'", (string) $result->errorMessage);
    }

    public function testApplyResolvesOwnBankAccountCodeToId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql): ?Row {
            if (str_contains($sql, '[economy_codebooks_bank_accounts]')) {
                return new Row(['id' => 17]);
            }
            return null;
        });

        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(static fn(array $data): bool => ($data['bank_account'] ?? null) === 17))
            ->willReturn(DocumentResult::ok(['id' => 100]));

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['applyOptions'] = ['importOwnBankAccount' => 'MAIN'];

        $result = $this->buildApplierForOwnBankTests($db, $heads)->apply($payload);

        $this->assertTrue($result->success, "Expected success; got {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame(100, $result->savedId);
        $this->assertSame(17, $result->canonical['applyOptions']['importOwnBankAccount']);
    }

    public function testValidateAcceptsStringOwnBankAccountInSchema(): void
    {
        $result = $this->buildApplier()->validate([
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived',
            'selfParty' => 'customer', 'supplier' => ['name' => 'X'], 'dates' => ['issueDate' => '2026-06-01'],
            'applyOptions' => ['importOwnBankAccount' => 'MAIN'],
        ]);
        $this->assertNotSame('schema_invalid', $result->errorCode);
    }
}
