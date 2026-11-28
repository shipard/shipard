<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/** Applier: každé apply = nový doklad 1001, 1002, …; selhání pro vybraná období. */
class FakeApplier extends DocumentApplier
{
    public int $applied = 0;
    /** @var list<string> začátky období, jejichž apply selže */
    public array $failFor = [];
    /** @var list<array<string, mixed>> */
    public array $canonicals = [];

    public function __construct()
    {
    }

    public function apply(array $canonical): ApplyResult
    {
        $this->canonicals[] = $canonical;
        if (in_array($canonical['dates']['periodFrom'] ?? '', $this->failFor, true)) {
            return ApplyResult::error('validation_failed', 'Doklad neprošel validací', $canonical, statusCode: 422);
        }
        $this->applied++;
        $replace = $canonical['applyOptions']['replaceConcept'] ?? null;
        return ApplyResult::ok($canonical, is_int($replace) ? $replace : 1000 + $this->applied);
    }
}
