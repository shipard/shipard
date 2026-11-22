<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
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
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Opravný daňový doklad ve výměnném formátu (#92 D10, tasks/corrective-doc-exchange.md
 * U2–U4): blok `correction` → sloupce skupiny `correction`; původní doklad
 * referencí `correction.originalDoc` (#166 D30), v návrhu i volbou
 * `_resolve.correction` — podle čísla se nic nedohledává; sazba DPH k datu původního
 * plnění; kurz původního dokladu místo lístku. Skutečný VatCodeResolver nad
 * vat-cz.jsonc, aby sazba závisela na datu (cz-111: 15 % do 2014, 12 % od 2024).
 */
class DocumentApplierCorrectionTest extends TestCase
{
    use ReferenceFixture;

    /** Doklady, které zná resolver referencí: číslo původního dokladu → id. */
    private const KNOWN_DOCS = ['doc_number=FV26-00123' => 77];

    private const ORIGINAL = [
        'id' => 77, 'doc_type' => 'invno', 'doc_number' => 'FV26-00123',
        'vat_duzp' => '2014-06-10', 'issue_date' => '2014-06-01', 'exchange_rate' => null, 'doc_currency' => 'czk',
    ];

    private static ?array $vatCz = null;
    private static ?array $tradeUnions = null;

    /** @var array<string, mixed>|null data hlavičky, jak šla do gateway */
    private ?array $saved = null;

    /** @var list<string> dotazy applieru nad docs_core_heads (načtení původního dokladu podle id) */
    private array $headQueries = [];

    /**
     * @param array<string, mixed>|null         $original řádek původního dokladu pro dotaz podle id (null = neexistuje)
     * @param array<string, int|list<int>>|null $known    doklady resolveru referencí (null = {@see KNOWN_DOCS})
     */
    private function applier(
        ?array $original = null,
        ?FakeExchangeRateResolver $rates = null,
        ?array $known = null,
    ): DocumentApplier {
        $pinned = $original;
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $this->saved = null;
        $saved = &$this->saved;
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')->willReturnCallback(static function (array $data) use (&$saved): DocumentResult {
            $saved = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        $this->headQueries = [];
        $queries = &$this->headQueries;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args) use ($pinned, &$queries): ?Row {
            $sql = (string) ($args[0] ?? '');
            if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                return new Row(['id' => 5, 'country' => 'cz']);
            }
            if (str_contains($sql, 'FROM [docs_core_heads] WHERE [id]')) {
                $queries[] = 'pin:' . json_encode(array_slice($args, 1));
                return $pinned !== null ? new Row($pinned) : null;
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(static function (mixed ...$args) use (&$queries): array {
            $sql = (string) ($args[0] ?? '');
            if (str_contains($sql, 'FROM [docs_core_heads]')) {
                // Applier podle čísla nehledá (#166 D30) — hledá resolver referencí.
                $queries[] = 'number:' . json_encode(array_slice($args, 1));
            }
            return [];
        });
        $db->method('getInsertId')->willReturn(0);

        $docTypes = [
            'invno'  => ['trade_dir' => 1, 'corrective' => true],
            'invni'  => ['trade_dir' => 2],
            'cmnbkp' => ['trade_dir' => 0],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null);

        self::$vatCz ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/vat/config/vat-cz.jsonc');
        $vatConfig = $this->createMock(ConfigRuntime::class);
        $vatConfig->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null);
        self::$tradeUnions ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/trade/config/tradeUnions.jsonc');
        $tradeConfig = $this->createMock(ConfigRuntime::class);
        $tradeConfig->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null);
        $vatRates = new VatRateResolver($vatConfig);

        return new class(
            db: $db,
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
            accountResolver: $this->createMock(AccountResolver::class),
            vatCodeDerivation: new VatCodeDerivation($vatRates),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($tradeConfig)),
            exchangeRateResolver: $rates,
            references: $this->referenceResolver(self::REFERENCE_RECORDS + ['docs_core_heads' => $known ?? self::KNOWN_DOCS]),
        ) extends DocumentApplier {
            protected function executeSql(mixed ...$args): void
            {
            }
        };
    }

    /** Faktura vydaná (typ s příznakem), jeden řádek se sníženou sazbou cz-111, rok 2026 (12 %). */
    private static function issued(array $override = []): array
    {
        $payload = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceIssued',
            'selfParty'     => 'supplier',
            'customer'      => ['name' => 'Odběratel s.r.o.', 'country' => 'CZ', 'companyId' => '12345678'],
            'dates'         => ['issueDate' => '2026-04-15', 'dueDate' => '2026-04-29', 'taxPointDate' => '2026-04-15'],
            'currency'      => 'CZK',
            'vat'           => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'CZ'],
            'payment'       => ['method' => 'bankTransfer'],
            'rows'          => [[
                'rowKind' => 'item', 'orderPos' => 1, 'description' => 'Služba',
                'quantity' => -1.0, 'unitPrice' => 1000.0, 'priceCalcMode' => 'fromUnitPrice',
                'vat' => ['code' => 'cz-111'],
            ]],
        ];
        return array_replace($payload, $override);
    }

    /** Blok bez odkazu — číslo původního dokladu je jen text. */
    private static function block(?string $number = 'FV26-00123', ?string $date = '2026-03-15', ?string $reason = 'Sleva za vadu'): array
    {
        return ['originalDocNumber' => $number, 'originalTaxPointDate' => $date, 'reason' => $reason];
    }

    /** Blok s odkazem na původní doklad referencí (číslo dokladu, nebo id). */
    private static function linked(int|string $reference = '@FV26-00123'): array
    {
        return ['originalDoc' => $reference] + self::block();
    }

    private static function describe(ApplyResult $result): string
    {
        return json_encode([$result->errorCode, $result->canonical['_resolve']['issues'] ?? null], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, array{severity: string, path: string, code: string, message: string}> */
    private static function issuesByCode(array $canonical): array
    {
        return array_column($canonical['_resolve']['issues'] ?? [], null, 'code');
    }

    // ── Blok a dohledání ────────────────────────────────────────────────────

    public function testReferenceLinksOriginalAndTakesItsNumberAndDate(): void
    {
        $applier = $this->applier(original: self::ORIGINAL);
        $result = $applier->apply(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(1, $this->saved['corrective']);
        $this->assertSame(77, $this->saved['corrected_doc']);
        $this->assertSame('FV26-00123', $this->saved['corrected_doc_number']);
        $this->assertSame('2014-06-10', $this->saved['corrected_doc_date'], 'datum plnění z odkazu přepíše hodnotu bloku');
        $this->assertSame('Sleva za vadu', $this->saved['correction_reason']);
        $this->assertSame(['pin:[77]'], $this->headQueries, 'applier načte jen doklad, který našel resolver');

        $resolve = $result->canonical['_resolve']['correction'];
        $this->assertSame(['status' => 'matched', 'matchedId' => 77, 'matchedBy' => 'ref', 'docNumber' => 'FV26-00123', 'taxPointDate' => '2014-06-10'], $resolve);
        $issues = self::issuesByCode($result->canonical);
        $this->assertArrayNotHasKey('reference_not_found', $issues);
        $this->assertArrayNotHasKey('correction_not_supported', $issues);
    }

    public function testReferenceByIdLinksTheSameDocument(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued(['correction' => self::linked(77)]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(77, $this->saved['corrected_doc']);
    }

    public function testPreviewReturnsCorrectionResolveBlock(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->preview(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame('matched', $result->canonical['_resolve']['correction']['status']);
        $this->assertSame(77, $result->canonical['_resolve']['correction']['matchedId']);
    }

    public function testBlockWithOnlyNullsSetsFlagWithoutLookup(): void
    {
        $result = $this->applier()->apply(self::issued(['correction' => self::block(null, null, null)]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(1, $this->saved['corrective']);
        $this->assertArrayNotHasKey('corrected_doc', $this->saved);
        $this->assertArrayNotHasKey('corrected_doc_number', $this->saved);
        $this->assertArrayNotHasKey('corrected_doc_date', $this->saved);
        $this->assertArrayNotHasKey('correction_reason', $this->saved);
        $this->assertSame([], $this->headQueries, 'bez čísla ani pinu se nic nedohledává');
        $this->assertSame(['status' => 'none'], $result->canonical['_resolve']['correction']);
        $this->assertSame([], array_intersect_key(self::issuesByCode($result->canonical), array_flip([
            'correction_original_not_found', 'correction_original_ambiguous', 'correction_not_supported', 'pin_target_missing',
        ])));
    }

    public function testWithoutBlockNothingIsWritten(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued(), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        foreach (['corrective', 'corrected_doc', 'corrected_doc_number', 'corrected_doc_date', 'correction_reason'] as $column) {
            $this->assertArrayNotHasKey($column, $this->saved, $column);
        }
        $this->assertArrayNotHasKey('correction', $result->canonical['_resolve']);
        $this->assertSame([], $this->headQueries);
    }

    public function testImportModeLinksByReferenceAndKeepsTargetState(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::asInstruction(self::issued([
            'correction'   => self::linked(),
            'applyOptions' => ['targetDocState' => 40, 'importNumber' => ['docNumber' => 'FV26-00200', 'sequenceNumber' => 200]],
        ])), ApplyMode::Instruction);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(40, $this->saved['docState']);
        $this->assertSame(1, $this->saved['corrective']);
        $this->assertSame(77, $this->saved['corrected_doc'], 'seed datové sady obnoví odkaz referencí i v import módu');
        $this->assertSame('2014-06-10', $this->saved['corrected_doc_date']);
    }

    /** D30: `originalDocNumber` je jen text — podle čísla se nic nedohledává, ani v návrhu. */
    public function testNumberAloneIsOnlyText(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued(['correction' => self::block()]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(1, $this->saved['corrective']);
        $this->assertArrayNotHasKey('corrected_doc', $this->saved);
        $this->assertSame('FV26-00123', $this->saved['corrected_doc_number'], 'číslo zůstane textem');
        $this->assertSame('2026-03-15', $this->saved['corrected_doc_date'], 'datum plnění z bloku');
        $this->assertSame([], $this->headQueries);
        $this->assertSame(['status' => 'none'], $result->canonical['_resolve']['correction']);
        $this->assertSame([], array_intersect_key(self::issuesByCode($result->canonical), array_flip([
            'reference_not_found', 'correction_original_not_found', 'correction_original_ambiguous',
        ])));
    }

    public function testUnknownReferenceIsAnErrorInBothModes(): void
    {
        foreach ([ApplyMode::Proposal, ApplyMode::Instruction] as $mode) {
            $payload = self::issued(['correction' => self::linked('@FV99-00001')]);
            $result = $this->applier()->apply($mode === ApplyMode::Instruction ? self::asInstruction($payload) : $payload, $mode);

            $this->assertFalse($result->success, $mode->value);
            $this->assertSame('validation_failed', $result->errorCode);
            $this->assertNull($this->saved);
            $issue = self::issuesByCode($result->canonical)['reference_not_found'] ?? null;
            $this->assertSame(['error', 'correction.originalDoc'], [$issue['severity'] ?? null, $issue['path'] ?? null], $mode->value);
        }
    }

    /** Číslo dokladu není unikátní napříč typy — víc dokladů je chyba, ne první nalezený (D10). */
    public function testAmbiguousReferenceIsAnError(): void
    {
        $result = $this->applier(original: self::ORIGINAL, known: ['doc_number=FV26-00123' => [77, 78]])
            ->apply(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);

        $this->assertFalse($result->success);
        $this->assertNull($this->saved);
        $issue = self::issuesByCode($result->canonical)['reference_ambiguous'] ?? null;
        $this->assertSame(['error', 'correction.originalDoc'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
    }

    public function testReferenceToDocumentOfOtherTypeIsAnError(): void
    {
        $received = self::ORIGINAL;
        $received['doc_type'] = 'invni';
        $result = $this->applier(original: $received)->apply(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);

        $this->assertFalse($result->success);
        $issue = self::issuesByCode($result->canonical)['correction_original_not_supported'] ?? null;
        $this->assertSame(['error', 'correction.originalDoc'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
    }

    public function testPinLinksDirectlyAndIgnoresNumberLookup(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued([
            'correction' => self::block('jiné číslo'),
            '_resolve'   => ['correction' => ['userAction' => 'useExisting:77']],
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(77, $this->saved['corrected_doc']);
        $this->assertSame('FV26-00123', $this->saved['corrected_doc_number'], 'číslo z odkazu, ne z bloku');
        $this->assertSame(['pin:[77]'], $this->headQueries);
        $this->assertSame('pin', $result->canonical['_resolve']['correction']['matchedBy']);
        $this->assertArrayNotHasKey('pin_target_missing', self::issuesByCode($result->canonical));
    }

    public function testMissingPinWarnsWithoutLink(): void
    {
        $result = $this->applier(original: null)->apply(self::issued([
            'correction' => self::block(),
            '_resolve'   => ['correction' => ['userAction' => 'useExisting:999']],
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('corrected_doc', $this->saved);
        $this->assertSame('FV26-00123', $this->saved['corrected_doc_number'], 'číslo z bloku zůstává textem');
        $this->assertSame(['pin:[999]'], $this->headQueries);
        $issues = self::issuesByCode($result->canonical);
        $this->assertSame('warning', $issues['pin_target_missing']['severity'] ?? null);
        $this->assertSame('_resolve.correction', $issues['pin_target_missing']['path']);
    }

    /** D32: pokyn volby `_resolve` nečte — odkaz určuje jen `correction.originalDoc`. */
    public function testInstructionIgnoresPin(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::asInstruction(self::issued([
            'correction' => self::block(),
            '_resolve'   => ['correction' => ['userAction' => 'useExisting:77']],
        ])), ApplyMode::Instruction);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('corrected_doc', $this->saved);
        $this->assertSame([], $this->headQueries);
    }

    public function testReferenceWinsOverPin(): void
    {
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued([
            'correction' => self::linked(),
            '_resolve'   => ['correction' => ['userAction' => 'useExisting:999']],
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(77, $this->saved['corrected_doc']);
        $this->assertSame('ref', $result->canonical['_resolve']['correction']['matchedBy']);
        $this->assertArrayNotHasKey('pin_target_missing', self::issuesByCode($result->canonical));
    }

    public function testPinOnDocumentOfOtherTypeIsMissing(): void
    {
        $received = self::ORIGINAL;
        $received['doc_type'] = 'invni';
        $result = $this->applier(original: $received)->apply(self::issued([
            'correction' => self::block(null),
            '_resolve'   => ['correction' => ['userAction' => 'useExisting:77']],
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('corrected_doc', $this->saved);
        $this->assertArrayHasKey('pin_target_missing', self::issuesByCode($result->canonical));
    }

    public function testBlockOnReceivedInvoiceIsIgnoredWithWarning(): void
    {
        $payload = json_decode((string) file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Exchange/invoiceReceived_happy.json'), true);
        $payload['correction'] = self::block();

        $result = $this->applier(original: self::ORIGINAL)->apply($payload, ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        foreach (['corrective', 'corrected_doc', 'corrected_doc_number', 'corrected_doc_date', 'correction_reason'] as $column) {
            $this->assertArrayNotHasKey($column, $this->saved, $column);
        }
        $issues = self::issuesByCode($result->canonical);
        $this->assertSame('warning', $issues['correction_not_supported']['severity'] ?? null);
        $this->assertSame('correction', $issues['correction_not_supported']['path']);
        $this->assertSame([], $this->headQueries, 'u typu bez příznaku se nic nedohledává');
        $this->assertArrayNotHasKey('correction', $result->canonical['_resolve']);
    }

    // ── Sazba DPH k datu původního plnění (D4, U4) ──────────────────────────

    public function testVatRateFollowsOriginalTaxPointDate(): void
    {
        // Bez opravy: sazba k DUZP dokladu (2026 → 12 %).
        $this->applier()->apply(self::issued(), ApplyMode::Proposal);
        $this->assertSame(12.0, $this->saved['rows'][0]['vat_pct']);

        // S odkazem: datum plnění původního dokladu (2014 → 15 %).
        $result = $this->applier(original: self::ORIGINAL)->apply(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);
        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(15.0, $this->saved['rows'][0]['vat_pct']);
        $this->assertSame('2026-04-15', $this->saved['vat_duzp'], 'DUZP opravného dokladu se nemění');

        // Bez odkazu: datum plnění z bloku.
        $this->applier()->apply(self::issued(['correction' => self::block(null, '2014-06-10')]), ApplyMode::Proposal);
        $this->assertSame(15.0, $this->saved['rows'][0]['vat_pct']);

        // Blok bez data: dnešní pravidlo.
        $this->applier()->apply(self::issued(['correction' => self::block(null, null)]), ApplyMode::Proposal);
        $this->assertSame(12.0, $this->saved['rows'][0]['vat_pct']);
    }

    public function testVatRateDateAppliesInImportMode(): void
    {
        $this->applier(original: self::ORIGINAL)->apply(self::asInstruction(self::issued([
            'correction'   => self::linked(),
            'applyOptions' => ['targetDocState' => 40, 'importNumber' => ['docNumber' => 'FV26-00200', 'sequenceNumber' => 200]],
        ])), ApplyMode::Instruction);

        $this->assertSame(15.0, $this->saved['rows'][0]['vat_pct']);
    }

    // ── Kurz (D4, U4) ───────────────────────────────────────────────────────

    public function testExchangeRateTakenFromLinkedOriginalInsteadOfList(): void
    {
        $original = self::ORIGINAL;
        $original['exchange_rate'] = '24.100000';
        $original['doc_currency'] = 'eur';
        $rates = new FakeExchangeRateResolver(['eur' => 25.0]);

        $result = $this->applier(original: $original, rates: $rates)->apply(self::issued([
            'currency' => 'EUR', 'correction' => self::linked(),
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(24.1, $this->saved['exchange_rate']);
        $this->assertSame([], $rates->lookups, 'lístek se u opravného dokladu nečte');
        $issues = self::issuesByCode($result->canonical);
        $this->assertSame('info', $issues['exchange_rate_from_original']['severity'] ?? null);
        $this->assertSame('Kurz 24,1 převzat z původního dokladu FV26-00123.', $issues['exchange_rate_from_original']['message']);
        $this->assertArrayNotHasKey('exchange_rate_from_list', $issues);
    }

    public function testExchangeRateWithoutLinkStaysEmptyWithInfo(): void
    {
        $rates = new FakeExchangeRateResolver(['eur' => 25.0]);

        $result = $this->applier(rates: $rates)->apply(self::issued([
            'currency' => 'EUR', 'correction' => self::block(),
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('exchange_rate', $this->saved);
        $this->assertSame([], $rates->lookups);
        $issues = self::issuesByCode($result->canonical);
        $this->assertSame('info', $issues['exchange_rate_corrective_manual']['severity'] ?? null);
        $this->assertArrayNotHasKey('exchange_rate_missing', $issues);
        $this->assertArrayNotHasKey('exchange_rate_from_list', $issues);
    }

    public function testOriginalInOtherCurrencyDoesNotLendItsRate(): void
    {
        $rates = new FakeExchangeRateResolver(['eur' => 25.0]);

        $result = $this->applier(original: self::ORIGINAL, rates: $rates)->apply(self::issued([
            'currency' => 'EUR', 'correction' => self::linked(),
        ]), ApplyMode::Proposal);

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('exchange_rate', $this->saved, 'původní doklad v CZK kurz pro EUR nedá');
        $this->assertArrayHasKey('exchange_rate_corrective_manual', self::issuesByCode($result->canonical));
    }

    public function testExplicitExchangeRateWinsAndHomeCurrencyIsSilent(): void
    {
        $original = self::ORIGINAL;
        $original['exchange_rate'] = '24.100000';
        $original['doc_currency'] = 'eur';
        $rates = new FakeExchangeRateResolver(['eur' => 25.0]);

        $result = $this->applier(original: $original, rates: $rates)->apply(self::issued([
            'currency' => 'EUR', 'exchangeRate' => 23.9, 'correction' => self::linked(),
        ]), ApplyMode::Proposal);
        $this->assertSame(23.9, $this->saved['exchange_rate']);
        $this->assertArrayNotHasKey('exchange_rate_from_original', self::issuesByCode($result->canonical));

        $result = $this->applier(original: self::ORIGINAL, rates: $rates)->apply(self::issued(['correction' => self::linked()]), ApplyMode::Proposal);
        $this->assertArrayNotHasKey('exchange_rate', $this->saved);
        $issues = self::issuesByCode($result->canonical);
        $this->assertArrayNotHasKey('exchange_rate_corrective_manual', $issues);
        $this->assertArrayNotHasKey('exchange_rate_from_original', $issues);
    }

    public function testLinkedOriginalLendsRateEvenWithoutRateLists(): void
    {
        $original = self::ORIGINAL;
        $original['exchange_rate'] = '24.100000';
        $original['doc_currency'] = 'eur';

        $result = $this->applier(original: $original, rates: null)->apply(self::issued([
            'currency' => 'EUR', 'correction' => self::linked(),
        ]), ApplyMode::Proposal);
        $this->assertSame(24.1, $this->saved['exchange_rate']);

        // Bez resolveru není známá domácí měna — chybějící kurz se nehlásí.
        $result = $this->applier(rates: null)->apply(self::issued([
            'currency' => 'EUR', 'correction' => self::block(),
        ]), ApplyMode::Proposal);
        $this->assertArrayNotHasKey('exchange_rate', $this->saved);
        $this->assertArrayNotHasKey('exchange_rate_corrective_manual', self::issuesByCode($result->canonical));
    }

    // ── Přegenerování konceptu ──────────────────────────────────────────────

    public function testReplaceResetClearsCorrectionColumns(): void
    {
        $reset = (new \ReflectionClassConstant(DocumentApplier::class, 'REPLACE_RESET_HEAD'))->getValue();

        $this->assertSame(0, $reset['corrective']);
        foreach (['corrected_doc', 'corrected_doc_number', 'corrected_doc_date', 'correction_reason'] as $column) {
            $this->assertArrayHasKey($column, $reset, $column);
            $this->assertNull($reset[$column]);
        }
    }
}
