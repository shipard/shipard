<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales;

use Dibi\Connection;
use Dibi\UniqueConstraintViolationException;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * SaleSync nad pamětí místo databáze: doklady, registrace a tržby drží
 * v polích, zámek a duplicitu na unikátním indexu umí nasimulovat.
 */
final class FakeSaleSync extends SaleSync
{
    /** @var array<int, DocFacts> */
    public array $docs = [];

    /** @var list<RegistrationFacts> */
    public array $registrationList = [];

    /** @var list<array<string, mixed>> uložené tržby */
    public array $sales = [];

    /** @var array<int, SalesRegistrationType> */
    public array $registrationTypes = [];

    public bool $lockAvailable = true;

    /** @var list<string> */
    public array $lockLog = [];

    /** Tržba, kterou „jiný proces“ vloží těsně před naším insertem. */
    public ?array $concurrentSale = null;

    public function __construct()
    {
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        parent::__construct($db, new DocFactsLoader($db, null, null));
    }

    protected function loadDoc(int $docId): ?DocFacts
    {
        return $this->docs[$docId] ?? null;
    }

    protected function registrations(): array
    {
        return $this->registrationList;
    }

    protected function hasSales(int $docId): bool
    {
        return $this->salesOf($docId) !== [];
    }

    protected function salesOf(int $docId): array
    {
        $sales = array_values(array_filter($this->sales, static fn(array $sale): bool => $sale['doc_head'] === $docId));
        usort($sales, static fn(array $a, array $b): int => $a['doc_sequence'] <=> $b['doc_sequence']);
        return $sales;
    }

    protected function typeOfRegistration(int $registrationId): ?SalesRegistrationType
    {
        return $this->registrationTypes[$registrationId] ?? null;
    }

    protected function insertSale(array $data): int
    {
        if ($this->concurrentSale !== null) {
            $this->sales[] = $this->concurrentSale + ['id' => count($this->sales) + 1];
            $this->concurrentSale = null;
        }
        foreach ($this->sales as $sale) {
            if ($sale['doc_head'] === $data['doc_head'] && $sale['doc_sequence'] === $data['doc_sequence']) {
                throw new UniqueConstraintViolationException('Duplicate entry');
            }
        }
        $id = count($this->sales) + 1;
        $this->sales[] = $data + ['id' => $id];
        return $id;
    }

    protected function lock(int $docId): bool
    {
        $this->lockLog[] = 'lock ' . $docId;
        return $this->lockAvailable;
    }

    protected function unlock(int $docId): void
    {
        $this->lockLog[] = 'unlock ' . $docId;
    }
}
