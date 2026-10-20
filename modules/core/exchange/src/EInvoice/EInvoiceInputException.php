<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

/**
 * Vstup generátoru e-faktury není úplný nebo není podporovaný (#119 D2,
 * D10). Nese všechny nálezy najednou — cesta jako v canonicalu
 * (`supplier.companyId`, `rows[2].computed.vatAmount`) + zpráva — aby
 * volající (CLI, tisk) vypsal celý seznam, ne první chybu.
 */
final class EInvoiceInputException extends \InvalidArgumentException
{
    /**
     * @param list<array{path: string, message: string}> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Vstup e-faktury není úplný: ' . implode('; ', $this->lines()));
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_values(array_unique(array_map(static fn (array $p): string => $p['path'], $this->problems)));
    }

    /** @return list<string> `cesta: zpráva`, jeden nález na řádek */
    public function lines(): array
    {
        return array_map(static fn (array $p): string => "{$p['path']}: {$p['message']}", $this->problems);
    }
}
