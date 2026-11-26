<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Sales\ReconcileScanner;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Záchranná síť (#171 D7, D20, U9): chybějící tržbu dorovná s původním
 * časem, hotové doklady nechá být a nanečisto nic nezapíše.
 */
class ReconcileScannerTest extends TestCase
{
    use SalesRegistrationConfig;

    private function sync(): FakeSaleSync
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $sync = new FakeSaleSync();
        $sync->registrationList = [new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])];
        $sync->registrationTypes = [1 => $type];
        return $sync;
    }

    /** @param array<string, mixed> $head */
    private function doc(int $id, array $head = [], ?array $series = ['code' => 'cashreg-P1']): DocFacts
    {
        return DocFacts::fromRows(
            $head + [
                'id' => $id, 'doc_type' => 'cashreg', 'docState' => 40, 'doc_number' => 'PR' . $id,
                'issue_date' => '2027-02-10', 'payment_method' => 0, 'total_amount_dom' => '100.00',
                'home_currency' => 'czk', 'doc_state_changed_at' => '2027-02-10 09:30:00',
            ],
            [],
            $series,
            'cz',
        );
    }

    /** @param list<int> $candidates */
    private function scanner(FakeSaleSync $sync, array $candidates): ReconcileScanner
    {
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $scanner = new class ($db, new DocFactsLoader($db, null, null), $sync) extends ReconcileScanner {
            /** @var list<int> */
            public array $ids = [];
            public ?\DateTimeImmutable $since = null;

            protected function candidates(\DateTimeImmutable $since): array
            {
                $this->since = $since;
                return $this->ids;
            }
        };
        $scanner->ids = $candidates;
        return $scanner;
    }

    public function testMissingSaleIsCreatedWithTheOriginalTime(): void
    {
        $sync = $this->sync();
        $sync->docs[1] = $this->doc(1);
        $sync->docs[2] = $this->doc(2, ['doc_type' => 'invni']);
        $sync->docs[3] = $this->doc(3, series: ['code' => 'řada']);
        $scanner = $this->scanner($sync, [1, 2, 3]);
        $now = new \DateTimeImmutable('2027-02-12 08:00:00');

        $results = $scanner->run(7, $now);

        $this->assertSame('2027-02-05 08:00:00', $scanner->since?->format('Y-m-d H:i:s'), 'okno 7 dní');
        $this->assertCount(2, $results, 'doklad, který nespadá, se nevypisuje');
        $this->assertSame([SyncResult::CREATED, SyncResult::UNREGISTRABLE], array_map(static fn(SyncResult $r): string => $r->status, $results));
        $this->assertSame(DocEvaluation::REASON_CASH_REGISTER_ID, $results[1]->reason);
        $this->assertSame('2027-02-10 09:30:00', $sync->sales[0]['sold_at'], 'čas přechodu dokladu, ne čas nálezu (D20)');
        $this->assertSame('2027-02-12 08:00:00', $sync->sales[0]['next_attempt'], 'pošle ji nejbližší běh fronty');

        // Druhý běh už nic nevytvoří — výpočet je idempotentní.
        $again = $scanner->run(7, $now);
        $this->assertSame([SyncResult::UNREGISTRABLE], array_map(static fn(SyncResult $r): string => $r->status, $again));
        $this->assertCount(1, $sync->sales);
    }

    public function testDryRunWritesNothing(): void
    {
        $sync = $this->sync();
        $sync->docs[1] = $this->doc(1);
        $scanner = $this->scanner($sync, [1]);

        $results = $scanner->run(7, new \DateTimeImmutable('2027-02-12 08:00:00'), dryRun: true);

        $this->assertSame(SyncResult::WOULD_CREATE, $results[0]->status);
        $this->assertSame('100.00', $results[0]->sale['amount']);
        $this->assertSame([], $sync->sales);
    }
}
