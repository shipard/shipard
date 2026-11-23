<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Shipard\Module\Core\Exchange\Common\ApplyResult;

/**
 * Uložení návrhu skončilo uvnitř transakce bez dokladu ({@see DocumentProposal}):
 * pokyn nad hotovým dokladem selhal, nebo doklad se stejným `source.ref`
 * mezitím vznikl. Výjimka vrátí transakci — i záznamy založené z `pending`
 * — a nese výsledek, ze kterého vstup návrhu složí odpověď.
 */
final class ProposalStoppedException extends \RuntimeException
{
    /**
     * @param bool $existing true = `$result` je hotová odpověď za existující
     *        doklad; false = výsledek pokynu, který selhal
     */
    public function __construct(
        public readonly ApplyResult $result,
        public readonly bool $existing = false,
    ) {
        parent::__construct($result->errorMessage ?? 'Uložení návrhu skončilo bez dokladu.');
    }
}
