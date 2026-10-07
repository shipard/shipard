<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Mail\MessagePartnerWriter;

/**
 * Vrstva 1 zápisu partnera zprávy (tasks/mail-message-title-partner.md
 * D5/D8, P5). Guardy proti přepsání ruční volby a autoritativního partnera
 * po Použít jsou WHERE podmínky UPDATE — testují se přes zaznamenané
 * where() volání na Dibi fluentu (vzor AnalysisControllerTest::classification).
 */
final class MessagePartnerWriterTest extends TestCase
{
    private const MESSAGE_NDX = 42;
    private const TABLE = 'core_mail_incoming_messages';

    /** @var list<array{table: string, data: array<string, mixed>, where: list<string>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->updates = [];
    }

    // ── infrastruktura ──────────────────────────────────────────────────────

    private function dibi(): \Dibi\Connection
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $idx = count($this->updates);
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => []];
            $fluent = $this->createMock(\Dibi\Fluent::class);
            $fluent->method('__call')->willReturnCallback(
                function (string $name, array $args) use (&$fluent, $idx): \Dibi\Fluent {
                    if ($name === 'where') {
                        $this->updates[$idx]['where'][] = implode('|', array_map('strval', $args));
                    }
                    return $fluent;
                },
            );
            return $fluent;
        });
        return $dibi;
    }

    /**
     * @param array<string, mixed>|null $captured resolve() argumenty [party, personType, identifiersOnly]
     */
    private function resolver(ResolveResult $result, ?array &$captured = null): PartyResolver
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (array $party, mixed $personType = null, bool $identifiersOnly = false) use (&$captured, $result): ResolveResult {
                $captured = [$party, $personType, $identifiersOnly];
                return $result;
            },
        );
        return $resolver;
    }

    /** @return array<string, mixed> */
    private function docsCanonical(array $supplier = []): array
    {
        return [
            'format' => 'shpd.docs.document',
            'docType' => 'invoiceReceived',
            'selfParty' => 'customer',
            'supplier' => $supplier !== [] ? $supplier : [
                'name' => 'Dodavatel s.r.o.',
                'companyId' => '12345678',
                'taxId' => 'CZ12345678',
            ],
            'customer' => ['name' => 'Naše firma a.s.', 'companyId' => '99999999'],
        ];
    }

    private function registryConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'contract'        => ['target' => 'registry', 'docKind' => 'contract'],
            ]],
        ]);
        return $config;
    }

    // ── docs canonical ──────────────────────────────────────────────────────

    public function testWritesNameAndPersonOnIdentifierMatch(): void
    {
        $captured = null;
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::matched(77, 'companyId'), $captured));

        $writer->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(), 'invoiceReceived');

        $this->assertCount(2, $this->updates);

        [$name, $person] = $this->updates;
        $this->assertSame(self::TABLE, $name['table']);
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $name['data']);
        $this->assertContains('id = %i|42', $name['where']);
        $this->assertContains('target_row IS NULL', $name['where']);
        $this->assertNotContains('partner_person IS NULL', $name['where'], 'jméno se přepisuje i při ručním partnerovi');

        $this->assertSame(['partner_person' => 77], $person['data']);
        $this->assertContains('id = %i|42', $person['where']);
        $this->assertContains('target_row IS NULL', $person['where']);
        $this->assertContains('partner_person IS NULL', $person['where'], 'ruční volba má přednost (D8)');

        // Resolver dostal jen identifikátory (bez jména → žádný canCreate) a identifiersOnly.
        $this->assertSame(['companyId' => '12345678', 'taxId' => 'CZ12345678'], $captured[0]);
        $this->assertTrue($captured[2]);
    }

    public function testNameOnlyPartyWritesNameAndNeverAsksResolver(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->expects($this->never())->method('resolve');

        (new MessagePartnerWriter($resolver))->writeFromCanonical(
            $this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(['name' => 'Jen jméno s.r.o.']), 'invoiceReceived',
        );

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Jen jméno s.r.o.'], $this->updates[0]['data']);
    }

    public function testNotFoundLeavesPersonUntouched(): void
    {
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::notFound()));
        $writer->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(), 'invoiceReceived');

        $this->assertCount(1, $this->updates);
        $this->assertArrayHasKey('partner_name', $this->updates[0]['data']);
    }

    public function testAmbiguousLeavesPersonUntouched(): void
    {
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::ambiguous([['id' => 1], ['id' => 2]])));
        $writer->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(), 'invoiceReceived');

        $this->assertCount(1, $this->updates);
        $this->assertArrayHasKey('partner_name', $this->updates[0]['data']);
    }

    public function testResolverFailureStillWritesName(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->method('resolve')->willThrowException(new \RuntimeException('DB down'));

        (new MessagePartnerWriter($resolver))->writeFromCanonical(
            $this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(), 'invoiceReceived',
        );

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $this->updates[0]['data']);
    }

    public function testWithoutResolverOnlyNameIsWritten(): void
    {
        (new MessagePartnerWriter(null))->writeFromCanonical(
            $this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(), 'invoiceReceived',
        );

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $this->updates[0]['data']);
    }

    public function testSelfPartySupplierTakesCustomerSide(): void
    {
        $canonical = $this->docsCanonical();
        $canonical['selfParty'] = 'supplier';

        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::matched(5, 'companyId')));
        $writer->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $canonical, 'invoiceReceived');

        $this->assertSame(['partner_name' => 'Naše firma a.s.'], $this->updates[0]['data']);
        $this->assertSame(['partner_person' => 5], $this->updates[1]['data']);
    }

    // ── registry canonical ──────────────────────────────────────────────────

    public function testRegistryTargetUsesParty(): void
    {
        $captured = null;
        $writer = new MessagePartnerWriter(
            $this->resolver(ResolveResult::matched(9, 'companyId'), $captured),
            $this->registryConfig(),
        );
        $canonical = [
            'schema' => 'shpd.registry.document.v1',
            'docType' => 'contract',
            'title' => 'Servisní smlouva',
            'party' => ['name' => 'Servis a.s.', 'companyId' => '87654321', 'email' => 'info@servis.example'],
            // supplier klíč v registry canonicalu neexistuje — nesmí se použít
            'supplier' => ['name' => 'Špatná strana'],
        ];

        $writer->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $canonical, 'contract');

        $this->assertSame(['partner_name' => 'Servis a.s.'], $this->updates[0]['data']);
        $this->assertSame(['partner_person' => 9], $this->updates[1]['data']);
        $this->assertSame(['companyId' => '87654321'], $captured[0]);
    }

    // ── guardy vstupu ───────────────────────────────────────────────────────

    public function testNullCanonicalWritesNothing(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->expects($this->never())->method('resolve');

        (new MessagePartnerWriter($resolver))->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, null, 'invoiceReceived');

        $this->assertSame([], $this->updates);
    }

    public function testValidationWrapperWritesNothing(): void
    {
        $wrapped = [
            '_validationError' => 'Canonical schema validation failed',
            '_rawOutput' => $this->docsCanonical(),
        ];

        (new MessagePartnerWriter(null))->writeFromCanonical($this->dibi(), self::MESSAGE_NDX, $wrapped, 'invoiceReceived');

        $this->assertSame([], $this->updates);
    }

    public function testCanonicalWithoutPartyWritesNothing(): void
    {
        (new MessagePartnerWriter(null))->writeFromCanonical(
            $this->dibi(), self::MESSAGE_NDX, ['docType' => 'invoiceReceived', 'supplier' => null], 'invoiceReceived',
        );

        $this->assertSame([], $this->updates);
    }

    public function testEmptyNameSkipsNameWriteButStillResolvesPerson(): void
    {
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::matched(77, 'vatId')));
        $writer->writeFromCanonical(
            $this->dibi(), self::MESSAGE_NDX, $this->docsCanonical(['name' => '   ', 'vatId' => 'CZ12345678']), 'invoiceReceived',
        );

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_person' => 77], $this->updates[0]['data']);
    }

    // ── protistrana z klasifikace (tasks/mail-other-attention.md D7) ────────

    public function testClassificationPartyWritesNameAndPersonWithSameGuards(): void
    {
        $captured = null;
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::matched(77, 'companyId'), $captured));

        $writer->writeFromClassification($this->dibi(), self::MESSAGE_NDX, [
            'name' => "  Registrátor \n a.s. ",
            'companyId' => ' 12345678 ',
            'email' => 'podpora@registrator.example',
        ]);

        $this->assertCount(2, $this->updates);
        [$name, $person] = $this->updates;
        $this->assertSame(['partner_name' => 'Registrátor a.s.'], $name['data']);
        $this->assertContains('target_row IS NULL', $name['where']);
        $this->assertSame(['partner_person' => 77], $person['data']);
        $this->assertContains('target_row IS NULL', $person['where']);
        $this->assertContains('partner_person IS NULL', $person['where'], 'ruční volba má přednost (D8)');

        // Jen identifikátory — e-mail ani jméno se do resolveru nedostanou.
        $this->assertSame(['companyId' => '12345678'], $captured[0]);
        $this->assertTrue($captured[2]);
    }

    public function testClassificationPartyWithoutIdentifierNeverAsksResolver(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->expects($this->never())->method('resolve');

        (new MessagePartnerWriter($resolver))->writeFromClassification(
            $this->dibi(), self::MESSAGE_NDX, ['name' => 'Dodavatel s.r.o.', 'email' => 'news@dodavatel.example'],
        );

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $this->updates[0]['data']);
    }

    public function testClassificationPartyNotFoundWritesOnlyName(): void
    {
        $writer = new MessagePartnerWriter($this->resolver(ResolveResult::notFound()));
        $writer->writeFromClassification($this->dibi(), self::MESSAGE_NDX, ['name' => 'Úřad', 'companyId' => '00000000']);

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Úřad'], $this->updates[0]['data']);
    }

    public function testEmptyClassificationPartyWritesNothing(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->expects($this->never())->method('resolve');

        (new MessagePartnerWriter($resolver))->writeFromClassification($this->dibi(), self::MESSAGE_NDX, []);
        (new MessagePartnerWriter($resolver))->writeFromClassification($this->dibi(), self::MESSAGE_NDX, ['name' => '  ']);

        $this->assertSame([], $this->updates);
    }

    // ── normalizace jména ───────────────────────────────────────────────────

    public function testNormalizeNameCollapsesWhitespaceAndTruncates(): void
    {
        $this->assertSame('Dodavatel s.r.o.', MessagePartnerWriter::normalizeName("  Dodavatel \n\t s.r.o.  "));
        $this->assertNull(MessagePartnerWriter::normalizeName(''));
        $this->assertNull(MessagePartnerWriter::normalizeName("  \n "));
        $this->assertNull(MessagePartnerWriter::normalizeName(123));

        $long = str_repeat('ě', 250);
        $this->assertSame(MessagePartnerWriter::NAME_MAX_LENGTH, mb_strlen((string) MessagePartnerWriter::normalizeName($long)));
    }

    public function testPartyOfPicksSideByTarget(): void
    {
        $docs = $this->docsCanonical();
        $this->assertSame($docs['supplier'], MessagePartnerWriter::partyOf($docs, 'invoiceReceived', null));

        $docs['selfParty'] = 'supplier';
        $this->assertSame($docs['customer'], MessagePartnerWriter::partyOf($docs, 'invoiceReceived', null));

        $registry = ['party' => ['name' => 'X']];
        $this->assertSame(['name' => 'X'], MessagePartnerWriter::partyOf($registry, 'contract', $this->registryConfig()));
        // Bez configu je target docs → party se ignoruje.
        $this->assertNull(MessagePartnerWriter::partyOf($registry, 'contract', null));
    }
}
