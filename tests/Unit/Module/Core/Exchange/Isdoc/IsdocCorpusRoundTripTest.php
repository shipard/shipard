<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Isdoc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\EInvoice\CountryNames;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceUuid;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Isdoc\IsdocWriter;
use Shipard\Module\Docs\Core\Prints\PaymentQr\CzIbanCalculator;

/**
 * Round-trip generátor → čtečka (#119 D8): každý `.isdoc` korpusu
 * (vyrobený buildem z očekávaného výsledku + `rows[].computed`) přečtený
 * `IsdocReader` se od očekávaného výsledku liší jen v polích, která
 * ISDOC nenese ({@see IGNORE}, stejný seznam v docs/einvoice.md). Totéž
 * nad fixturami generátoru (vydané doklady — role se prohodí).
 */
class IsdocCorpusRoundTripTest extends TestCase
{
    private const CORPUS = __DIR__ . '/../../../../../corpus';
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/Exchange/einvoice';
    private const COUNTRIES = __DIR__ . '/../../../../../../modules/world/base/config/countries.jsonc';

    /**
     * Cesty projekce, které ISDOC nenese — ověřeno proti XSD 6.0.1 / 6.0.2.
     * Pole, které ISDOC nese a čtečka ho nevrací, sem nepatří (patří do
     * čtečky).
     *
     * @var array<string, string> cesta uzlu → důvod
     */
    public const IGNORE = [
        'vat.place'               => 'ISDOC nemá místo plnění; applier ho odvodí z prefixu DIČ dodavatele (docs/exchange-format.md § 8.4)',
        'rows[].vat.supplyKind'   => 'ISDOC nemá druh plnění (zboží / služba)',
        'rows[].priceCalcMode'    => 'ISDOC neříká, zda je autoritativní jednotková, nebo celková cena — čtečka dává fromUnitPrice',
        'supplier.contact.web'    => 'Contact má jen Name / Telephone / ElectronicMail',
        'customer.contact.web'    => 'Contact má jen Name / Telephone / ElectronicMail',
        'dates.periodFrom'        => 'hlavička ISDOC nemá období plnění',
        'dates.periodTo'          => 'hlavička ISDOC nemá období plnění',
        'rows[].discountPct'      => 'ISDOC nese jen částku před slevou (LineExtensionAmountBeforeDiscount), ne procento — částku hlídá testDiscountSurvivesAsAmount',
        'rows[].discountAmount'   => 'korpus vyjadřuje slevu procentem (discountAmount null), čtečka vrací částku z LineExtensionAmountBeforeDiscount — hlídá testDiscountSurvivesAsAmount',
        '_resolve'                => 'štítek obsahu je výsledek obohacení, ne pole dokladu',
    ];

    private static ?CanonicalComparator $comparator = null;

    private static function comparator(): CanonicalComparator
    {
        return self::$comparator ??= new CanonicalComparator(Projection::load(self::CORPUS . '/projection.jsonc'));
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function differences(array $expected, array $actual): array
    {
        return array_map(
            static fn (object $d): string => (string) $d,
            self::comparator()->compare($expected, $actual, array_keys(self::IGNORE)),
        );
    }

    /** Sleva v korpusu je procentem; čtečka vrací částku — musí odpovídat množství × cena × procento. */
    private function assertDiscountSurvivesAsAmount(array $expected, array $actual, string $label): void
    {
        foreach ($expected['rows'] as $i => $row) {
            $pct = $row['discountPct'] ?? null;
            $amount = $row['discountAmount'] ?? null;
            if ($pct === null && $amount === null) {
                $this->assertArrayNotHasKey('discountAmount', $actual['rows'][$i] ?? [], "{$label} rows[{$i}]: sleva navíc");
                continue;
            }
            $wanted = $amount ?? round((float) $row['quantity'] * (float) $row['unitPrice'] * (float) $pct / 100, 2);
            $this->assertEqualsWithDelta($wanted, $actual['rows'][$i]['discountAmount'] ?? null, 0.011, "{$label} rows[{$i}]: sleva");
        }
    }

    // ── Korpus ──────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function corpusIsdocFiles(): iterable
    {
        foreach (glob(self::CORPUS . '/*/out/*/*.isdoc') ?: [] as $file) {
            $profile = basename(dirname($file));
            $country = basename(dirname($file, 3));
            $stem = basename($file, '.isdoc');
            yield "{$country}/{$profile}/{$stem}" => [$file, self::CORPUS . "/{$country}/expected/{$profile}/{$stem}.json"];
        }
    }

    public function testCorpusHasIsdocForEveryIsdocCell(): void
    {
        $files = iterator_to_array(self::corpusIsdocFiles());
        $this->assertGreaterThanOrEqual(18, count($files), '6 dokladů (varianty 1–4, 7, 10) × 3 profily (D10)');
        foreach (array_keys($files) as $key) {
            $this->assertMatchesRegularExpression('~/(0[1-4]|07|10)02-~', $key, 'ISDOC jen druhé doklady variant 1–4, 7 a 10');
        }
    }

    #[DataProvider('corpusIsdocFiles')]
    public function testCorpusIsdocRoundTrips(string $isdoc, string $expectedFile): void
    {
        $this->assertFileExists($expectedFile);
        $expected = self::json($expectedFile);
        $actual = (new IsdocReader())->fromFile($isdoc, basename($isdoc));

        $this->assertSame('invoiceReceived', $actual['docType']);
        $this->assertSame([], self::differences($expected, $actual), basename($isdoc));
        $this->assertDiscountSurvivesAsAmount($expected, $actual, basename($isdoc));
    }

    // ── Fixtury generátoru (vydané doklady) ─────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixtureRoundTrips(string $file): void
    {
        $canonical = self::json($file);
        if (str_starts_with($canonical['docType'], 'proforma')) {
            // Čtečka typ 4 zatím nepřijímá (jen 1 a 2) — proforma se ověří jen schématem v IsdocWriterTest.
            $this->markTestSkipped('IsdocReader nečte DocumentType 4');
        }
        $options = new EInvoiceOptions(EInvoiceUuid::fromName('fixtures/' . basename($file)), CountryNames::fromModuleFile(self::COUNTRIES));
        $xml = (new IsdocWriter())->write($canonical, $options);
        $actual = (new IsdocReader())->fromXmlString($xml);

        // Čtečka vidí doklad z druhé strany: přijatý, vlastní strana odběratel;
        // textové řádky a sekce do ISDOC nejdou; IBAN generátor dopočítá;
        // splatnost nese jen převod (PaymentDueDate je v Details).
        $expected = $canonical;
        $expected['docType'] = 'invoiceReceived';
        $expected['selfParty'] = 'customer';
        $expected['rows'] = array_values(array_filter($canonical['rows'], static fn (array $r): bool => ($r['rowKind'] ?? 'item') === 'item'));
        $account = $canonical['supplier']['bankAccount']['accountNumber'] ?? null;
        if (($expected['supplier']['bankAccount']['iban'] ?? null) === null && $account !== null) {
            $expected['supplier']['bankAccount']['iban'] = CzIbanCalculator::fromAccountNumber($account);
        }
        if (($canonical['payment']['method'] ?? null) !== 'bankTransfer') {
            $expected['dates']['dueDate'] = null;
        }

        $this->assertSame([], self::differences($expected, $actual), basename($file));
        $this->assertDiscountSurvivesAsAmount($expected, $actual, basename($file));
    }
}
