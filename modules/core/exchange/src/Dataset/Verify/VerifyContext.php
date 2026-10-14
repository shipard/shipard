<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Vstup kontrol: wiring zdroje dat, sada a její očekávání, zprávy sady
 * s doklady (po apply), přeskočené zprávy, výsledek apply, domácí měna
 * a cíl zápisu snímku deníku (`--write-ledger-snapshot`).
 */
final class VerifyContext
{
    /**
     * @param list<SetDocument>     $documents
     * @param array<string, string> $skipped messageId → důvod
     */
    public function __construct(
        public readonly VerifyWiring $wiring,
        public readonly DatasetReader $reader,
        public readonly VerifyExpectations $expectations,
        public readonly array $documents,
        public readonly array $skipped,
        public readonly ApplyReport $apply,
        public readonly string $homeCurrency,
        public readonly ?string $ledgerSnapshotOut = null,
    ) {}

    /** @return list<SetDocument> doklady sady ve zdroji dat (mimo Koš), i z dřívějšího běhu */
    public function appliedDocuments(): array
    {
        return array_values(array_filter($this->documents, static fn (SetDocument $d): bool => $d->isApplied()));
    }

    /** @return list<int> */
    public function appliedDocIds(): array
    {
        return array_map(static fn (SetDocument $d): int => (int) $d->docId, $this->appliedDocuments());
    }

    public function language(): string
    {
        return $this->wiring->dsConfig->getDefaultLanguage();
    }
}
