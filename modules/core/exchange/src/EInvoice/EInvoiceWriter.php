<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

/**
 * Generátor strukturované e-faktury z canonicalu `shpd.docs.document.v1`
 * (#119 D1). Čistá funkce: žádná databáze ani konfigurace zdroje dat —
 * vše, co formát potřebuje navíc (UUID, vystavující systém, názvy zemí),
 * dodá volající v {@see EInvoiceOptions}.
 *
 * Vstupem je canonical pro e-fakturu (D2): papírová projekce dokladu
 * + `rows[].computed`. Generátor nepočítá nic, co jde do DPH nebo součtů —
 * částky jen formátuje; chybějící povinná hodnota je
 * {@see EInvoiceInputException} s cestou k poli, ne prázdný element.
 *
 * Formáty registruje {@see EInvoiceFormats}; `isdoc` je první.
 */
interface EInvoiceWriter
{
    /** Identifikátor formátu (`isdoc`, …). */
    public function format(): string;

    /**
     * @param array<string, mixed> $canonical `shpd.docs.document.v1` s `rows[].computed`
     * @return string Obsah souboru (XML); stejný vstup dává stejné bajty.
     * @throws EInvoiceInputException chybějící nebo nepodporovaná hodnota vstupu
     */
    public function write(array $canonical, EInvoiceOptions $options): string;
}
