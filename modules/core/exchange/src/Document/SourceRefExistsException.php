<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Doklad se stejným `source.kind` + `source.ref` vznikl mezi prvním
 * dohledáním a zámkem uvnitř transakce uložení (#166 D46, U3) — souběžný
 * pokyn. Výjimka vrátí vlastní práci transakce a {@see
 * DocumentApplier::apply()} odpoví existujícím dokladem, stejně jako kdyby
 * ho našel hned.
 */
final class SourceRefExistsException extends \RuntimeException
{
    /**
     * @param array{id: int, docType: string, docNumber: ?string} $existing
     */
    public function __construct(public readonly array $existing)
    {
        parent::__construct("Doklad #{$existing['id']} se stejným identifikátorem ve zdroji už existuje.");
    }
}
