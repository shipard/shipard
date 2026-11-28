<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\InvoiceBuilder;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;

/**
 * Služba nad zdroji v paměti: `workOrders` / `kinds` / `rows` jsou záznamy
 * prvního zdroje (reference do `fakeSource`), `fakeRepo` jeho evidence
 * období; další zdroje mají evidenci v `repos`.
 */
class TestableInvoicingRunService extends InvoicingRunService
{
    /** @var list<array<string, mixed>> */
    public array $workOrders = [];
    /** @var array<int, array<string, mixed>> */
    public array $kinds = [];
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    /** @var list<string> */
    public array $transactions = [];
    public readonly FakeInvoicingSource $fakeSource;
    public readonly FakePeriodRepository $fakeRepo;

    /**
     * @param non-empty-list<FakeInvoicingSource> $fakeSources
     * @param array<string, FakePeriodRepository> $repos id zdroje → evidence období
     */
    public function __construct(
        Connection $db,
        ?ConfigRuntime $config,
        array $fakeSources,
        public array $repos,
        InvoiceBuilder $builder,
        public readonly FakeApplier $fakeApplier,
        ?InvoiceContributorRegistry $contributors = null,
    ) {
        $this->fakeSource = $fakeSources[0];
        $this->fakeRepo = $repos[$this->fakeSource->id()];
        $this->workOrders = &$this->fakeSource->records;
        $this->kinds = &$this->fakeSource->kinds;
        $this->rows = &$this->fakeSource->rows;
        parent::__construct(
            $db,
            $config,
            new InvoicingSources($fakeSources),
            $builder,
            $fakeApplier,
            $contributors,
            static fn(PeriodicInvoicingSource $source): PeriodRepository => $repos[$source->id()],
        );
    }

    /** @var array<int, string> doklad → otisk (výchozí hash-<id>) */
    public array $contentHashes = [];

    protected function contentHashOf(int $docId): string
    {
        return $this->contentHashes[$docId] ?? 'hash-' . $docId;
    }
}
