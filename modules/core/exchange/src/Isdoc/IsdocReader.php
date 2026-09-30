<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

/**
 * Deterministická konverze ISDOC 6.x (český standard e-fakturace, XML) na
 * canonical `shpd.docs.document.v1`. Čistá funkce soubor/XML → array, žádné
 * DB závislosti — vazby na zprávu/přílohu (source.message, attachments)
 * doplňuje volající (IsdocImportService).
 *
 * Zásady (tasks/mail-isdoc-import.md):
 *   - mapuje se jen to, co v ISDOC opravdu je; chybějící pole se vynechávají
 *     (canonical je má nullable),
 *   - namespace se matchuje prefixem `http://isdoc.cz/` — verze namespace
 *     (2013, …) se může lišit,
 *   - číselné hodnoty jako float; zaokrouhlování je věc DocDocument
 *     (applier nesestupuje pod Document — docs/exchange-format.md §3),
 *   - cizí měna: existuje-li ForeignCurrencyCode, doklad je v cizí měně —
 *     částky se berou z `*Curr` elementů. ISDOC nemá UnitPriceCurr
 *     (UnitPrice je v lokální měně), proto řádky v cizí měně nesou jen
 *     totalPrice + priceCalcMode 'fromTotal'.
 */
final class IsdocReader
{
    private const NAMESPACE_PREFIX = 'http://isdoc.cz/';

    /**
     * ISDOC 6.0.1 číselník DocumentType (isdoc.cz): 1 faktura, 2 dobropis,
     * 3 vrubopis, 4 zálohová (proforma) faktura, 5 daňový doklad k přijaté
     * platbě, 6 dobropis dokladu k přijaté platbě. MVP zpracovává jen 1 a 2;
     * ostatní typy = IsdocParseException a zpráva jde do AI fronty.
     */
    private const DOC_TYPE_MAP = [
        1 => 'invoiceReceived',
        2 => 'creditNote',
    ];

    /** ISDOC PaymentMeansCode → canonical payment.method (jen mapované kódy). */
    private const PAYMENT_METHOD_MAP = [
        42 => 'bankTransfer',
    ];

    /**
     * Načte ISDOC z disku. `.isdocx` (case-insensitive, dle $filename) je ZIP
     * obal — rozbalí se první `*.isdoc` entry; vše ostatní se čte jako XML.
     *
     * @return array<string, mixed> Canonical `shpd.docs.document.v1`.
     * @throws IsdocParseException
     */
    public function fromFile(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $xml = $extension === 'isdocx'
            ? $this->extractFromIsdocx($path)
            : (string) @file_get_contents($path);

        return $this->fromXmlString($xml);
    }

    /**
     * @return array<string, mixed> Canonical `shpd.docs.document.v1`.
     * @throws IsdocParseException
     */
    public function fromXmlString(string $xml): array
    {
        $root = $this->parseRoot($xml);

        $docTypeRaw = $this->text($root, 'DocumentType');
        if ($docTypeRaw === null) {
            throw IsdocParseException::missingElement('DocumentType');
        }
        $docType = self::DOC_TYPE_MAP[(int) $docTypeRaw] ?? null;
        if ($docType === null) {
            throw IsdocParseException::unsupportedDocumentType($docTypeRaw);
        }

        $docNumber = $this->text($root, 'ID');
        if ($docNumber === null) {
            throw IsdocParseException::missingElement('ID');
        }

        $localCurrency = $this->text($root, 'LocalCurrencyCode');
        $foreignCurrency = $this->text($root, 'ForeignCurrencyCode');
        $isForeign = $foreignCurrency !== null;
        $currency = $foreignCurrency ?? $localCurrency;

        $supplierParty = $this->el($root, 'AccountingSupplierParty', 'Party');
        $supplier = $supplierParty !== null ? $this->mapParty($supplierParty) : null;
        $customerParty = $this->el($root, 'AccountingCustomerParty', 'Party');
        $customer = $customerParty !== null ? $this->mapParty($customerParty) : null;

        // Bankovní spojení dodavatele žije v ISDOC mimo Party — v platebních
        // instrukcích (PaymentMeans/Payment/Details), bere se první Payment.
        $paymentDetails = $this->el($root, 'PaymentMeans', 'Payment', 'Details');
        if ($supplier !== null && $paymentDetails !== null) {
            $supplier['bankAccount'] = $this->mapBankAccount($paymentDetails);
        }

        $canonical = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',

            'source' => [
                'kind'        => 'isdoc',
                'extractedAt' => date(DATE_ATOM),
                'confidence'  => 1.0,
                // Audit + budoucí dedup podle UUID (zatím se nekontroluje).
                'raw' => [
                    'version'      => $this->attr($root, 'version'),
                    'documentType' => (int) $docTypeRaw,
                    'uuid'         => $this->text($root, 'UUID'),
                    'id'           => $docNumber,
                ],
            ],

            'docType'   => $docType,
            'docNumber' => $docNumber,
            'docText'   => $this->text($root, 'Note'),
            'selfParty' => 'customer',

            'supplier' => $supplier,
            'customer' => $customer,

            'dates' => [
                'issueDate'    => $this->text($root, 'IssueDate'),
                'taxPointDate' => $this->text($root, 'TaxPointDate'),
                'dueDate'      => $paymentDetails !== null
                    ? $this->text($paymentDetails, 'PaymentDueDate')
                    : null,
            ],

            'currency'     => $currency !== null ? strtoupper($currency) : null,
            'exchangeRate' => $isForeign ? $this->exchangeRate($root) : null,

            'vat' => [
                'registrationCountry' => is_array($supplier)
                    ? ($supplier['country'] ?? null)
                    : null,
            ],

            'payment' => $paymentDetails !== null ? [
                'method' => self::PAYMENT_METHOD_MAP[
                    (int) ($this->text($root, 'PaymentMeans', 'Payment', 'PaymentMeansCode') ?? -1)
                ] ?? null,
                'paymentReference' => $this->text($paymentDetails, 'VariableSymbol'),
                'constantSymbol'   => $this->text($paymentDetails, 'ConstantSymbol'),
                'specificSymbol'   => $this->text($paymentDetails, 'SpecificSymbol'),
            ] : null,

            'rows'     => $this->mapRows($root, $isForeign),
            'vatRecap' => $this->mapVatRecap($root, $isForeign),

            'totals' => [
                'totalBase'     => $this->num($root, 'LegalMonetaryTotal', $isForeign ? 'TaxExclusiveAmountCurr' : 'TaxExclusiveAmount'),
                'totalVat'      => $this->num($root, 'TaxTotal', $isForeign ? 'TaxAmountCurr' : 'TaxAmount'),
                'totalAmount'   => $this->num($root, 'LegalMonetaryTotal', $isForeign ? 'PayableAmountCurr' : 'PayableAmount'),
                'totalRounding' => $this->num($root, 'LegalMonetaryTotal', $isForeign ? 'PayableRoundingAmountCurr' : 'PayableRoundingAmount'),
            ],
        ];

        return self::prune($canonical);
    }

    // ── ZIP obal (.isdocx) ──────────────────────────────────────────────────

    private function extractFromIsdocx(string $path): string
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::RDONLY);
        if ($result !== true) {
            throw IsdocParseException::invalidZip("cannot open archive (code {$result})");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if (preg_match('/\.isdoc$/i', $entryName) !== 1) {
                    continue;
                }
                $contents = $zip->getFromIndex($i);
                if ($contents === false || $contents === '') {
                    throw IsdocParseException::invalidZip("entry '{$entryName}' is empty or unreadable");
                }
                return $contents;
            }
        } finally {
            $zip->close();
        }

        throw IsdocParseException::invalidZip('no *.isdoc entry found');
    }

    // ── XML parsing ─────────────────────────────────────────────────────────

    private function parseRoot(string $xml): \DOMElement
    {
        if (trim($xml) === '') {
            throw IsdocParseException::invalidXml('empty document');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET + žádné LIBXML_NOENT (nesubstituovat entity) — XXE.
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
            if (!$loaded) {
                $error = libxml_get_last_error();
                throw IsdocParseException::invalidXml(
                    $error !== false ? trim($error->message) : 'parse failed',
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // DTD odmítáme úplně (interní subset = entity expansion vektor).
        if ($dom->doctype !== null) {
            throw IsdocParseException::invalidXml('DTD is not allowed');
        }

        $root = $dom->documentElement;
        if ($root === null) {
            throw IsdocParseException::invalidXml('missing root element');
        }
        if ($root->localName !== 'Invoice' || !$this->isIsdocNamespace($root)) {
            throw IsdocParseException::foreignRoot(
                '{' . (string) $root->namespaceURI . '}' . (string) $root->localName,
            );
        }

        return $root;
    }

    private function isIsdocNamespace(\DOMElement $element): bool
    {
        return str_starts_with((string) $element->namespaceURI, self::NAMESPACE_PREFIX);
    }

    // ── Mapování částí ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function mapParty(\DOMElement $party): array
    {
        $country = $this->text($party, 'PostalAddress', 'Country', 'IdentificationCode');
        $country = $country !== null ? strtolower($country) : null;

        // DIČ: PartyTaxScheme s TaxScheme = VAT (druhá varianta bývá TIN).
        $vatNumber = null;
        foreach ($this->all($party, 'PartyTaxScheme') as $taxScheme) {
            if (strtoupper((string) $this->text($taxScheme, 'TaxScheme')) === 'VAT') {
                $vatNumber = $this->text($taxScheme, 'CompanyID');
                break;
            }
        }

        return [
            'name'              => $this->text($party, 'PartyName', 'Name'),
            'country'           => $country,
            'companyId'         => $this->text($party, 'PartyIdentification', 'ID'),
            'taxId'             => $vatNumber,
            'vatId'             => $vatNumber,
            'courtRegistration' => $this->text($party, 'RegisterIdentification', 'Preformatted'),
            'address' => [
                'street'      => $this->text($party, 'PostalAddress', 'StreetName'),
                'houseNumber' => $this->text($party, 'PostalAddress', 'BuildingNumber'),
                'city'        => $this->text($party, 'PostalAddress', 'CityName'),
                'zip'         => $this->text($party, 'PostalAddress', 'PostalZone'),
                'country'     => $country,
            ],
            'contact' => [
                'name'  => $this->text($party, 'Contact', 'Name'),
                'email' => $this->text($party, 'Contact', 'ElectronicMail'),
                'phone' => $this->text($party, 'Contact', 'Telephone'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBankAccount(\DOMElement $paymentDetails): array
    {
        $accountId = $this->text($paymentDetails, 'ID');
        $bankCode = $this->text($paymentDetails, 'BankCode');
        $accountNumber = $accountId !== null && $bankCode !== null
            ? "{$accountId}/{$bankCode}"
            : $accountId;

        return [
            'accountNumber' => $accountNumber,
            'iban'          => $this->text($paymentDetails, 'IBAN'),
            'bic'           => $this->text($paymentDetails, 'BIC'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapRows(\DOMElement $root, bool $isForeign): array
    {
        $rows = [];
        foreach ($this->all($root, 'InvoiceLines', 'InvoiceLine') as $index => $line) {
            $quantityEl = $this->el($line, 'InvoicedQuantity');

            $row = [
                'rowKind'  => 'item',
                'orderPos' => $index + 1,
                'item' => [
                    'name'         => $this->text($line, 'Item', 'Description'),
                    'supplierCode' => $this->text($line, 'Item', 'SellersItemIdentification', 'ID'),
                ],
                'unit'     => $quantityEl?->getAttribute('unitCode') ?: null,
                'quantity' => $this->num($line, 'InvoicedQuantity'),
                'vat' => [
                    // vat.code se nemapuje — doplní RowHistoryEnricher
                    // z historie, případně uživatel při review.
                    'pct' => $this->num($line, 'ClassifiedTaxCategory', 'Percent'),
                ],
            ];

            if ($isForeign) {
                $row['totalPrice'] = $this->num($line, 'LineExtensionAmountCurr');
                $row['priceCalcMode'] = 'fromTotal';
            } else {
                $row['unitPrice'] = $this->num($line, 'UnitPrice');
                $row['totalPrice'] = $this->num($line, 'LineExtensionAmount');
                $row['priceCalcMode'] = 'fromUnitPrice';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapVatRecap(\DOMElement $root, bool $isForeign): array
    {
        $recap = [];
        foreach ($this->all($root, 'TaxTotal', 'TaxSubTotal') as $subTotal) {
            $recap[] = [
                'vatPct' => $this->num($subTotal, 'TaxCategory', 'Percent'),
                'base'   => $this->num($subTotal, $isForeign ? 'TaxableAmountCurr' : 'TaxableAmount'),
                'tax'    => $this->num($subTotal, $isForeign ? 'TaxAmountCurr' : 'TaxAmount'),
                'total'  => $this->num($subTotal, $isForeign ? 'TaxInclusiveAmountCurr' : 'TaxInclusiveAmount'),
            ];
        }

        return $recap;
    }

    /**
     * Kurz na 1 jednotku cizí měny: CurrRate / RefCurrRate (RefCurrRate
     * bývá 1, u množstevních kurzů — HUF, JPY — 100/1000).
     */
    private function exchangeRate(\DOMElement $root): ?float
    {
        $currRate = $this->num($root, 'CurrRate');
        if ($currRate === null) {
            return null;
        }
        $refCurrRate = $this->num($root, 'RefCurrRate');
        if ($refCurrRate !== null && $refCurrRate > 0) {
            return $currRate / $refCurrRate;
        }
        return $currRate;
    }

    // ── DOM helpery (namespace-aware, prefix http://isdoc.cz/) ─────────────

    private function el(\DOMElement $context, string ...$path): ?\DOMElement
    {
        $current = $context;
        foreach ($path as $localName) {
            $current = $this->firstChild($current, $localName);
            if ($current === null) {
                return null;
            }
        }
        return $current;
    }

    /**
     * Všechny elementy na cestě — poslední segment vrací kolekci, mezikroky
     * první match.
     *
     * @return list<\DOMElement>
     */
    private function all(\DOMElement $context, string ...$path): array
    {
        $last = array_pop($path);
        $parent = $path === [] ? $context : $this->el($context, ...$path);
        if ($parent === null || $last === null) {
            return [];
        }

        $out = [];
        for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement && $node->localName === $last && $this->isIsdocNamespace($node)) {
                $out[] = $node;
            }
        }
        return $out;
    }

    private function firstChild(\DOMElement $parent, string $localName): ?\DOMElement
    {
        for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement && $node->localName === $localName && $this->isIsdocNamespace($node)) {
                return $node;
            }
        }
        return null;
    }

    private function text(\DOMElement $context, string ...$path): ?string
    {
        $element = $this->el($context, ...$path);
        if ($element === null) {
            return null;
        }
        $value = trim($element->textContent);
        return $value !== '' ? $value : null;
    }

    private function num(\DOMElement $context, string ...$path): ?float
    {
        $value = $this->text($context, ...$path);
        if ($value === null) {
            return null;
        }
        return (float) str_replace(',', '.', $value);
    }

    private function attr(\DOMElement $element, string $name): ?string
    {
        $value = trim($element->getAttribute($name));
        return $value !== '' ? $value : null;
    }

    // ── Úklid ───────────────────────────────────────────────────────────────

    /**
     * Rekurzivně odstraní null hodnoty a prázdné pod-objekty — chybějící pole
     * ISDOC se v canonical vynechávají místo explicitních null.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function prune(array $value): array
    {
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::prune($item);
                if ($item === []) {
                    continue;
                }
            }
            if ($item === null) {
                continue;
            }
            $out[$key] = $item;
        }
        return $isList ? array_values($out) : $out;
    }
}
