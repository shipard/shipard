<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\ContactPayment;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\CashPaymentLookup;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales\FakeSaleSync;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Varování D8c (#171): fakturu už hradí pokladní doklad, který do evidence
 * tržeb spadá — páruje se klíčem saldokonta nad daty dokladů (variabilní
 * symbol, osoba pro saldokonto, specifický symbol, měna).
 */
class CashPaymentLookupTest extends TestCase
{
    use SalesRegistrationConfig;

    private FakeSaleSync $sync;

    protected function setUp(): void
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $this->sync = new FakeSaleSync();
        $this->sync->registrationList = [new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])];
    }

    /**
     * @param list<array{id: int, doc_number: ?string}> $candidates
     * @param list<int> $withSales
     */
    private function lookup(array $candidates, array $withSales = []): CashPaymentLookup
    {
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $lookup = new class($db, new DocFactsLoader($db, null, null), $this->sync) extends CashPaymentLookup {
            /** @var list<array{id: int, doc_number: ?string}> */
            public array $rows = [];
            /** @var list<int> */
            public array $withSales = [];
            /** @var array<int, DocFacts> */
            public array $docs = [];
            /** @var list<array<int, mixed>> */
            public array $queries = [];

            protected function candidates(int $invoiceId, string $reference, int $partner, array $head): array
            {
                $this->queries[] = [$invoiceId, $reference, $partner];
                return $this->rows;
            }

            protected function loadDoc(int $docId): ?DocFacts
            {
                return $this->docs[$docId] ?? null;
            }

            protected function hasSales(int $docId): bool
            {
                return in_array($docId, $this->withSales, true);
            }
        };
        $lookup->rows = $candidates;
        $lookup->withSales = $withSales;
        return $lookup;
    }

    /** @param list<array{0: string, 1: string}> $rows pohyb a částka */
    private function cashDoc(int $id, array $rows, int $cashDir = 1): DocFacts
    {
        return DocFacts::fromRows(
            [
                'id' => $id, 'doc_type' => 'cash', 'docState' => 40, 'doc_number' => 'PP' . $id, 'issue_date' => '2027-02-10',
                'payment_method' => 0, 'cash_dir' => $cashDir, 'total_amount_dom' => '2420.00', 'home_currency' => 'czk',
                'doc_state_changed_at' => '2027-02-10 09:30:00',
            ],
            array_map(static fn(array $row): array => ['operation' => $row[0], 'vat_total_dom' => $row[1]], $rows),
            ['code' => 'cash-P1'],
            'cz',
        );
    }

    /** @param array<string, mixed> $override */
    private function invoice(array $override = []): array
    {
        return $override + [
            'id' => 10, 'payment_reference' => '1270001', 'partner' => 55, 'partner_balance' => 0,
            'specific_symbol' => null, 'doc_currency' => 'czk',
        ];
    }

    public function testCashReceiptThatFallsUnderRegistrationIsReported(): void
    {
        $lookup = $this->lookup([['id' => 20, 'doc_number' => 'PP1270003'], ['id' => 21, 'doc_number' => 'PP1270004']]);
        $lookup->docs[20] = $this->cashDoc(20, [['payment.receivable', '2420.00']]);
        // Příjem evidovaný jen jako převod peněz do evidence nespadá.
        $lookup->docs[21] = $this->cashDoc(21, [['transfer.in', '2420.00']]);

        $this->assertSame(['PP1270003'], $lookup->payingDocuments($this->invoice()));
        $this->assertSame([[10, '1270001', 55]], $lookup->queries);
    }

    public function testDocumentThatAlreadyHasASaleIsReportedWithoutEvaluation(): void
    {
        $lookup = $this->lookup([['id' => 20, 'doc_number' => null]], withSales: [20]);
        // Po skončení registrace by vyhodnocení neprošlo — tržbu ale doklad má.
        $this->sync->registrationList = [];
        $lookup->docs[20] = $this->cashDoc(20, [['payment.receivable', '2420.00']]);

        $this->assertSame(['#20'], $lookup->payingDocuments($this->invoice()));
    }

    /** Klíč saldokonta: osoba pro saldokonto má přednost před partnerem dokladu. */
    public function testBalancePartnerIsUsedWhenTheInvoiceHasOne(): void
    {
        $lookup = $this->lookup([]);

        $lookup->payingDocuments($this->invoice(['partner_balance' => 77]));

        $this->assertSame([[10, '1270001', 77]], $lookup->queries);
    }

    public function testInvoiceWithoutSymbolOrPartnerIsNotSearched(): void
    {
        $lookup = $this->lookup([['id' => 20, 'doc_number' => 'PP1']]);

        $this->assertSame([], $lookup->payingDocuments($this->invoice(['payment_reference' => '  '])));
        $this->assertSame([], $lookup->payingDocuments($this->invoice(['partner' => null])));
        $this->assertSame([], $lookup->queries, 'bez klíče se řádky dokladů vůbec nečtou');
    }

    public function testDeletedCandidateIsSkipped(): void
    {
        $lookup = $this->lookup([['id' => 20, 'doc_number' => 'PP1']]);

        $this->assertSame([], $lookup->payingDocuments($this->invoice()));
    }
}
