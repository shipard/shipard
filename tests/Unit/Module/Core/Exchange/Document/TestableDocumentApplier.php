<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Module\Core\Exchange\Document\Interpret\PendingRecords;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalDefaults;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalReport;
use Shipard\Module\Core\Exchange\Document\Interpret\RecapInterpretation;
use Shipard\Module\Core\Exchange\Document\Interpret\RecordMatching;
use Shipard\Module\Core\Exchange\Document\Interpret\VatInterpretation;
use Shipard\Module\Core\Exchange\Document\PayloadContext;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Document\SourceRef;
use Shipard\Module\Core\Exchange\Document\SourceRefLookup;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Applier pro testy — **pokyn** i **vstup návrhu** nad týmiž mocky:
 *
 *  - pokyn je sám `DocumentApplier` (resolvery a gateway hlaviček z testu);
 *  - z resolverů testu si navíc staví třídy interpretace a vstup návrhu
 *    ({@see $proposal}), aby obě cesty četly stejný „zdroj dat“ — produkce
 *    je skládá stejně (`DocumentProposal::create()`), jen nad databází;
 *  - zachytává SQL vstupu návrhu (učení dodavatelských kódů, vazba na
 *    zprávu) a volání zámku podle `source.ref`.
 *
 * Konstruktor bere, co potřebují obě cesty; pokyn dostane jen svou část.
 */
class TestableDocumentApplier extends DocumentApplier
{
    /** @var list<array> SQL zapsané vstupem návrhu */
    public array $sqlCalls = [];

    /** @var list<string> pořadí volání zámku podle `source.ref` */
    public array $lockEvents = [];

    /** false = zámek drží jiný pokyn (GET_LOCK vypršel). */
    public bool $lockAvailable = true;

    public readonly PayloadContext $proposalPayload;
    public readonly PayloadResolve $proposalResolve;
    public readonly VatInterpretation $vatInterpretation;
    public readonly RecapInterpretation $recapInterpretation;
    public readonly RecordMatching $recordMatching;
    public readonly PendingRecords $pendingRecords;
    public readonly ProposalDefaults $proposalDefaults;
    public readonly ProposalReport $proposalReport;
    public readonly DocumentInterpreter $interpreter;
    public readonly DocumentProposal $proposal;

    /**
     * @param array<string, array<string, mixed>> $contentTags
     */
    public function __construct(
        Connection $db,
        ConfigRuntime $config,
        TransactionlessTableGateway $headsGateway,
        TransactionlessTableGateway $personsGateway,
        TransactionlessTableGateway $itemsGateway,
        SchemaValidator $schemaValidator,
        DocumentValidator $documentValidator,
        PartyResolver $partyResolver,
        ItemResolver $itemResolver,
        UnitResolver $unitResolver,
        VatCodeResolver $vatCodeResolver,
        BankAccountResolver $bankAccountResolver,
        AccountResolver $accountResolver,
        VatCodeDerivation $vatCodeDerivation,
        VatPlaceDerivation $vatPlaceDerivation,
        array $contentTags = [],
        ?DimensionResolver $dimensionResolver = null,
        bool $itemsHaveAccountingAccount = false,
        ?ExchangeRateResolver $exchangeRateResolver = null,
        ?ReferenceResolver $references = null,
    ) {
        parent::__construct(
            db: $db,
            config: $config,
            headsGateway: $headsGateway,
            schemaValidator: $schemaValidator,
            documentValidator: $documentValidator,
            partyResolver: $partyResolver,
            unitResolver: $unitResolver,
            vatCodeResolver: $vatCodeResolver,
            bankAccountResolver: $bankAccountResolver,
            vatCodeDerivation: $vatCodeDerivation,
            dimensionResolver: $dimensionResolver,
            exchangeRateResolver: $exchangeRateResolver,
            references: $references,
        );

        // Interpretace nad týmiž resolvery — vlastní kontext payloadu, jako
        // v `DocumentInterpreter::create()`.
        $this->proposalPayload = new PayloadContext($db, $config, $references, $exchangeRateResolver);
        $this->proposalResolve = new PayloadResolve($this->proposalPayload, $vatCodeResolver, $vatCodeDerivation, $dimensionResolver);
        $this->vatInterpretation = new VatInterpretation(
            $db,
            $config,
            $this->proposalPayload,
            $this->proposalResolve,
            $vatCodeResolver,
            $vatCodeDerivation,
            $vatPlaceDerivation,
            $contentTags,
        );
        $this->recapInterpretation = new RecapInterpretation($this->proposalPayload, $this->proposalResolve, $this->vatInterpretation, $vatCodeResolver);
        $this->recordMatching = new RecordMatching(
            $db,
            $this->proposalPayload,
            $this->proposalResolve,
            $this->vatInterpretation,
            $partyResolver,
            $itemResolver,
            $unitResolver,
            $bankAccountResolver,
            $accountResolver,
        );
        $this->pendingRecords = new PendingRecords($db, $personsGateway, $itemsGateway);
        $this->proposalDefaults = new ProposalDefaults($db, $config, $this->proposalPayload);
        $this->proposalReport = new ProposalReport($db, $itemsHaveAccountingAccount);
        $this->interpreter = new DocumentInterpreter(
            $db,
            $config,
            $documentValidator,
            $this->proposalPayload,
            $this->proposalResolve,
            $this->vatInterpretation,
            $this->recapInterpretation,
            $this->recordMatching,
            $this->proposalDefaults,
            $this->proposalReport,
            $unitResolver,
            new SourceRefLookup($db),
        );
        $this->proposal = new DocumentProposal(
            $db,
            $schemaValidator,
            $this->interpreter,
            $this,
            $this->pendingRecords,
            function (mixed ...$args): void {
                $this->sqlCalls[] = $args;
            },
        );
    }

    protected function acquireSourceLock(SourceRef $key): bool
    {
        $this->lockEvents[] = 'acquire';
        return $this->lockAvailable;
    }

    protected function releaseSourceLock(SourceRef $key): void
    {
        $this->lockEvents[] = 'release';
    }
}
