<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Shadow;

use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;

/**
 * Gateway hlaviček dokladů, který si pamatuje data předaná k uložení —
 * výstup `DocumentApplier::transform()` před `beforeSave()` — a ukládá dál
 * jako obvykle. Stínový test (#166 U5) jimi porovnává dvě cesty.
 */
final class RecordingGateway extends TransactionlessTableGateway
{
    /** @var list<array<string, mixed>> */
    public array $recorded = [];

    public function saveDocument(array $inputData): DocumentResult
    {
        $this->recorded[] = $inputData;
        return parent::saveDocument($inputData);
    }
}
