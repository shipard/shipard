<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Rozdílové zasílání (#171 D6, D19, D20, U7): tržby dokladu se srovnají
 * s částkou, která do evidence spadá. Součet tržeb dokladu = ta částka.
 */
class SaleSyncTest extends TestCase
{
    use SalesRegistrationConfig;

    private const NOW = '2027-02-12 08:00:00';

    private function type(): SalesRegistrationType
    {
        return SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
    }

    private function sync(): FakeSaleSync
    {
        $sync = new FakeSaleSync();
        $sync->registrationList = [new RegistrationFacts(1, $this->type(), '2027-01-01', null, 2, [2 => '181', 3 => '182'])];
        $sync->registrationTypes = [1 => $this->type()];
        return $sync;
    }

    /** @param array<string, mixed> $head */
    private function doc(array $head = [], ?array $series = ['code' => 'cashreg-P1']): DocFacts
    {
        return DocFacts::fromRows(
            $head + [
                'id' => 10, 'doc_type' => 'cashreg', 'docState' => 40, 'doc_number' => 'PR1270001',
                'issue_date' => '2027-02-10', 'payment_method' => 0, 'cash_dir' => 0,
                'total_amount_dom' => '121.00', 'home_currency' => 'czk', 'imported' => 0,
                'doc_state_changed_at' => '2027-02-10 09:30:00',
            ],
            [],
            $series,
            'cz',
        );
    }

    private function syncDoc(FakeSaleSync $sync, bool $dryRun = false): SyncResult
    {
        return $sync->sync(10, new \DateTimeImmutable(self::NOW), $dryRun);
    }

    /** @return list<string> částky tržeb dokladu v pořadí */
    private function amounts(FakeSaleSync $sync): array
    {
        return array_column($sync->sales, 'amount');
    }

    public function testConfirmedDocumentGetsItsFirstSale(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();

        $result = $this->syncDoc($sync);

        $this->assertSame(SyncResult::CREATED, $result->status);
        $this->assertSame(1, $result->saleId);
        $this->assertSame(
            [
                'doc_head'         => 10,
                'doc_sequence'     => 1,
                'registration'     => 1,
                'unit'             => 2,
                'unit_id'          => '181',
                'cash_register_id' => 'cashreg-P1',
                'currency'         => 'czk',
                'serial_number'    => 'PR1270001',
                'sold_at'          => '2027-02-10 09:30:00',
                'amount'           => '121.00',
                'created'          => self::NOW,
                'state'            => 'pending',
                'attempt_count'    => 0,
                'next_attempt'     => self::NOW,
                'id'               => 1,
            ],
            $sync->sales[0],
        );
        $this->assertSame(['lock 10', 'unlock 10'], $sync->lockLog);
    }

    /** D20: čas tržby je čas přechodu dokladu — i když ji záchranná síť najde později. */
    public function testSaleTimeIsTheStateChangeOfTheDocumentNotTheTimeOfDiscovery(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc(['doc_state_changed_at' => '2027-02-10 09:30:00']);

        $this->syncDoc($sync);

        $this->assertSame('2027-02-10 09:30:00', $sync->sales[0]['sold_at']);
        $this->assertSame(self::NOW, $sync->sales[0]['created']);
    }

    public function testRepeatedSyncCreatesNothing(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);

        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status, 'oprava beze změny částky nic');
        $this->assertCount(1, $sync->sales);
    }

    public function testCancellationCreatesNegativeSaleWithSuffixTwo(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);

        $sync->docs[10] = $this->doc(['docState' => 30, 'doc_state_changed_at' => '2027-02-11 15:00:00']);
        $result = $this->syncDoc($sync);

        $this->assertSame(SyncResult::CREATED, $result->status);
        $this->assertSame(['121.00', '-121.00'], $this->amounts($sync));
        $this->assertSame('PR1270001-2', $sync->sales[1]['serial_number']);
        $this->assertSame(2, $sync->sales[1]['doc_sequence']);
        $this->assertSame('2027-02-11 15:00:00', $sync->sales[1]['sold_at'], 'čas storna = čas vrácení peněz');
        $this->assertSame('181', $sync->sales[1]['unit_id']);
        $this->assertSame('cashreg-P1', $sync->sales[1]['cash_register_id']);
        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);
    }

    public function testCorrectionCreatesTheDifference(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);

        // V opravě se nic nepočítá …
        $sync->docs[10] = $this->doc(['docState' => 80, 'total_amount_dom' => '150.00']);
        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);

        // … návrat do V pořádku s jinou částkou dá rozdíl, další oprava další.
        $sync->docs[10] = $this->doc(['total_amount_dom' => '150.00']);
        $this->syncDoc($sync);
        $sync->docs[10] = $this->doc(['total_amount_dom' => '100.50']);
        $this->syncDoc($sync);

        $this->assertSame(['121.00', '29.00', '-49.50'], $this->amounts($sync));
        $this->assertSame(['PR1270001', 'PR1270001-2', 'PR1270001-3'], array_column($sync->sales, 'serial_number'));
    }

    public function testDocumentThatNoLongerFallsIsCompensatedFromItsLastSale(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc(series: ['code' => 'cashreg-P1', 'sales_registration_unit' => 3]);
        $this->syncDoc($sync);

        // Oprava: úhrada převodem → do evidence už nespadá; řada mezitím o jednotku přišla.
        $sync->docs[10] = $this->doc(['payment_method' => 1], ['code' => 'jiny-kod']);
        $this->syncDoc($sync);

        $this->assertSame(['121.00', '-121.00'], $this->amounts($sync));
        $this->assertSame(3, $sync->sales[1]['unit']);
        $this->assertSame('182', $sync->sales[1]['unit_id']);
        $this->assertSame('cashreg-P1', $sync->sales[1]['cash_register_id'], 'vyrovnání jde pod označením původní tržby');
    }

    /** Doklad s tržbou zůstává u své registrace, i když k času opravy už neplatí. */
    public function testDocumentStaysWithTheRegistrationOfItsSales(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);

        $old = new RegistrationFacts(1, $this->type(), '2027-01-01', '2027-06-30', 2, [2 => '181']);
        $new = new RegistrationFacts(2, $this->type(), '2027-07-01', null, 9, [9 => '500']);
        $sync->registrationList = [$old, $new];

        // Oprava v červenci beze změny částky nesmí poslat storno platné tržby …
        $sync->docs[10] = $this->doc(['doc_state_changed_at' => '2027-07-05 10:00:00']);
        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);

        // … a rozdíl jde pod původní registrací.
        $sync->docs[10] = $this->doc(['total_amount_dom' => '130.00', 'doc_state_changed_at' => '2027-07-06 10:00:00']);
        $this->syncDoc($sync);
        $this->assertSame(1, $sync->sales[1]['registration']);
        $this->assertSame('9.00', $sync->sales[1]['amount']);
    }

    public function testStornoIsCompensatedEvenWhenTheRegistrationIsGone(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);
        $sync->registrationList = [];

        $sync->docs[10] = $this->doc(['total_amount_dom' => '130.00']);
        $gone = $this->syncDoc($sync);
        $this->assertSame(SyncResult::UNREGISTRABLE, $gone->status);
        $this->assertSame(DocEvaluation::REASON_REGISTRATION_GONE, $gone->reason);

        $sync->docs[10] = $this->doc(['docState' => 30]);
        $this->assertSame(SyncResult::CREATED, $this->syncDoc($sync)->status);
        $this->assertSame(['121.00', '-121.00'], $this->amounts($sync));
    }

    public function testDocumentOutsideTheEvidenceTakesNoLockAndCreatesNothing(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc(['doc_type' => 'invni']);

        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);
        $this->assertSame([], $sync->lockLog, 'většina dokladů — bez zámku');

        // Storno dokladu bez tržeb a neexistující doklad taky nic.
        $sync->docs[10] = $this->doc(['docState' => 30]);
        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);
        unset($sync->docs[10]);
        $this->assertSame(SyncResult::NONE, $this->syncDoc($sync)->status);
        $this->assertSame([], $sync->sales);
    }

    public function testUnregistrableDocumentCreatesNothingAndSaysWhy(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc(series: ['code' => 'prodejky-hlavni-provozovna']);

        $result = $this->syncDoc($sync);

        $this->assertSame(SyncResult::UNREGISTRABLE, $result->status);
        $this->assertSame(DocEvaluation::REASON_CASH_REGISTER_ID, $result->reason);
        $this->assertSame(1, $result->registration);
        $this->assertSame([], $sync->sales);

        // U9: po opravě nastavení tržbu dorovná záchranná síť s původním časem.
        $sync->docs[10] = $this->doc();
        $this->syncDoc($sync);
        $this->assertSame('2027-02-10 09:30:00', $sync->sales[0]['sold_at']);
    }

    public function testSerialNumberOutsideTheMaskMakesTheSaleUnregistrable(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc(['doc_number' => str_repeat('9', 24)]);
        $this->assertSame(SyncResult::CREATED, $this->syncDoc($sync)->status, '24 znaků se vejde');

        $sync->docs[10] = $this->doc(['doc_number' => str_repeat('9', 24), 'docState' => 30]);
        $result = $this->syncDoc($sync);
        $this->assertSame(SyncResult::UNREGISTRABLE, $result->status, 's příponou -2 už ne');
        $this->assertSame(DocEvaluation::REASON_SERIAL_NUMBER, $result->reason);
    }

    public function testDryRunSaysWhatWouldBeCreatedWithoutWriting(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();

        $result = $this->syncDoc($sync, dryRun: true);

        $this->assertSame(SyncResult::WOULD_CREATE, $result->status);
        $this->assertSame('121.00', $result->sale['amount']);
        $this->assertSame('PR1270001', $result->sale['serial_number']);
        $this->assertSame([], $sync->sales);
    }

    /** U7: tržbu mezitím vytvořil jiný proces — duplicita na unikátním indexu = přepočítat. */
    public function testDuplicateOnTheUniqueIndexIsRecomputedNotDoubled(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $sync->concurrentSale = [
            'doc_head' => 10, 'doc_sequence' => 1, 'amount' => '121.00', 'registration' => 1,
            'unit' => 2, 'unit_id' => '181', 'cash_register_id' => 'cashreg-P1', 'currency' => 'czk',
        ];

        $result = $this->syncDoc($sync);

        $this->assertSame(SyncResult::NONE, $result->status, 'po přepočtu už rozdíl není');
        $this->assertCount(1, $sync->sales, 'žádná druhá tržba');
    }

    public function testBusyDocumentIsLeftForTheNextRun(): void
    {
        $sync = $this->sync();
        $sync->docs[10] = $this->doc();
        $sync->lockAvailable = false;

        $this->assertSame(SyncResult::BUSY, $this->syncDoc($sync)->status);
        $this->assertSame([], $sync->sales);
    }

    public function testLockKeyIsPerDocument(): void
    {
        $this->assertSame(':salesreg:doc:10', SaleSync::lockKey(10));
        $this->assertNotSame(SaleSync::lockKey(10), SaleSync::lockKey(11));
    }
}
