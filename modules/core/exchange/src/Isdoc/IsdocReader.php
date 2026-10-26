<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;

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
 *     totalPrice + priceCalcMode 'fromTotal',
 *   - řádky nesou `computed` (základ, daň, cena s daní), jak je spočítal
 *     dodavatel. Zdroj dat, který není plátcem DPH, bere daň dodavatele
 *     jako součást ceny pořízení — applier pak na řádek dosadí cenu s daní
 *     přímo z dokladu a nedopočítává ji ze sazby (#97 D6),
 *   - co píše generátor {@see IsdocWriter}, čtečka čte zpět (#119 krok 3,
 *     docs/einvoice.md): `VATApplicable` false = doklad neplátce (`vat.mode`
 *     none, řádky bez sazby, bez rekapitulace, základ a daň null), jinak
 *     `vat.mode` ze shodné `VATCalculationMethod` všech řádků (u cen s DPH
 *     jsou ceny řádku s daní); tuzemské přenesení z `LocalReverseCharge`
 *     řádku nebo `LocalReverseChargeFlag` rekapitulace → `vat.reverseCharge`
 *     true; sleva z `…BeforeDiscount`; `Note` → `docText`
 *     i `notes.onDocument`; způsob úhrady i bez bankovních detailů,
 *   - ISDOC umí vyjádřit jen tuzemské přenesení; přenesení u plnění
 *     z jiného členského státu (čl. 196 směrnice) prvek nemá, takže
 *     chybějící prvek ho nevyvrací. `vat.reverseCharge: false` proto jen
 *     u plnění podle stran zjevně tuzemského — země dodavatele (prefix DIČ,
 *     bez DIČ země adresy) = země odběratele (adresa); jinak null a kód
 *     DPH určí historie nebo uživatel (tasks/einvoice-reader-reverse-charge.md).
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

    /** ISDOC PaymentMeansCode → canonical payment.method (jen mapované kódy; 20 šek, 31 převod, 49 inkaso nemají protějšek). */
    private const PAYMENT_METHOD_MAP = [
        10 => 'cash',
        42 => 'bankTransfer',
        48 => 'card',
        50 => 'cashOnDelivery',
        97 => 'setOff',
    ];

    /** ISDOC VATCalculationMethod → canonical vat.mode. */
    private const VAT_MODE_MAP = [
        0 => 'fromBase',
        1 => 'fromTotal',
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
        $paymentCode = $this->text($root, 'PaymentMeans', 'Payment', 'PaymentMeansCode');

        // Režim DPH: VATApplicable false = doklad neplátce (tvar podle
        // docs/exchange-format.md § 8.4 — bez sazeb, rekapitulace, základu
        // a daně); jinak podle metody výpočtu shodné na všech řádcích.
        $vatApplicable = $this->bool($root, 'VATApplicable');
        $noVat = $vatApplicable === false;
        $mode = $noVat ? 'none' : $this->calculationMode($root);
        // Přenesení: tuzemské z prvků ISDOC; false jen u zjevně tuzemského
        // plnění (ISDOC přenesení z jiného členského státu vyjádřit neumí).
        $reverseCharge = $this->hasLocalReverseCharge($root)
            ? true
            : ($vatApplicable !== null && $this->isDomesticSupply($supplier, $customer) ? false : null);
        $note = $this->text($root, 'Note');

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
            // Note = text dodavatele na dokladu: notes.onDocument (doc_notice)
            // i docText (titulek seznamu a náhledu) — #119 krok 3.
            'docText'   => $note,
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

            // Zemi registrace DPH ISDOC nenese — dřív se plnila zemí
            // dodavatele, což u přijatého dokladu ze zahraničí hledalo kód
            // v cizím číselníku. U přijatých dokladů ji odvozuje applier
            // z naší registrace (tasks/exchange-received-reverse-charge.md D2).
            // Místo plnění ISDOC nemá — applier ho odvodí z prefixu DIČ.
            'vat' => [
                'mode'                => $mode,
                'reverseCharge'       => $reverseCharge,
                'registrationCountry' => null,
            ],

            'notes' => [
                'onDocument' => $note,
            ],

            // Způsob úhrady nese PaymentMeansCode i bez Details (karta,
            // hotově); symboly a splatnost jen s Details (převod).
            'payment' => [
                'method'           => $paymentCode !== null ? (self::PAYMENT_METHOD_MAP[(int) $paymentCode] ?? null) : null,
                'paymentReference' => $paymentDetails !== null ? $this->text($paymentDetails, 'VariableSymbol') : null,
                'constantSymbol'   => $paymentDetails !== null ? $this->text($paymentDetails, 'ConstantSymbol') : null,
                'specificSymbol'   => $paymentDetails !== null ? $this->text($paymentDetails, 'SpecificSymbol') : null,
            ],

            'rows'     => $this->mapRows($root, $isForeign, $mode === 'fromTotal', $noVat),
            'vatRecap' => $noVat ? [] : $this->mapVatRecap($root, $isForeign),

            'totals' => [
                'totalBase'     => $noVat ? null : $this->num($root, 'LegalMonetaryTotal', $isForeign ? 'TaxExclusiveAmountCurr' : 'TaxExclusiveAmount'),
                'totalVat'      => $noVat ? null : $this->num($root, 'TaxTotal', $isForeign ? 'TaxAmountCurr' : 'TaxAmount'),
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

        // DIČ: PartyTaxScheme s TaxScheme = VAT; TIN = národní daňové číslo.
        $vatNumber = null;
        $tin = null;
        foreach ($this->all($party, 'PartyTaxScheme') as $taxScheme) {
            $scheme = strtoupper((string) $this->text($taxScheme, 'TaxScheme'));
            if ($scheme === 'VAT' && $vatNumber === null) {
                $vatNumber = $this->text($taxScheme, 'CompanyID');
            } elseif ($scheme === 'TIN' && $tin === null) {
                $tin = $this->text($taxScheme, 'CompanyID');
            }
        }
        // taxId = národní daňové číslo (#103 D115): u české strany je DIČ totéž co VAT ID,
        // u zahraniční jen TIN — bez něj null, vatId zůstává. Země bez adresy podle prefixu DIČ.
        $czech = $country === 'cz' || ($country === null && str_starts_with((string) $vatNumber, 'CZ'));
        $taxId = $tin ?? ($czech ? $vatNumber : null);

        return [
            'name'              => $this->text($party, 'PartyName', 'Name'),
            'country'           => $country,
            'companyId'         => $this->text($party, 'PartyIdentification', 'ID'),
            'taxId'             => $taxId,
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
     * @param bool $pricesWithVat `vat.mode` fromTotal — ceny řádku (jednotková i celková) s daní
     * @param bool $noVat doklad neplátce — řádky bez sazby
     * @return list<array<string, mixed>>
     */
    private function mapRows(\DOMElement $root, bool $isForeign, bool $pricesWithVat, bool $noVat): array
    {
        $rows = [];
        $suffix = $isForeign ? 'Curr' : '';
        $amountEl = $pricesWithVat ? 'LineExtensionAmountTaxInclusive' : 'LineExtensionAmount';
        foreach ($this->all($root, 'InvoiceLines', 'InvoiceLine') as $index => $line) {
            $quantityEl = $this->el($line, 'InvoicedQuantity');

            $row = [
                'rowKind'  => 'item',
                'orderPos' => $index + 1,
                'item' => [
                    'name'         => $this->text($line, 'Item', 'Description'),
                    'description'  => $this->text($line, 'Note'),
                    'supplierCode' => $this->text($line, 'Item', 'SellersItemIdentification', 'ID'),
                    'ean'          => $this->text($line, 'Item', 'CatalogueItemIdentification', 'ID'),
                ],
                'unit'     => $quantityEl?->getAttribute('unitCode') ?: null,
                'quantity' => $this->num($line, 'InvoicedQuantity'),
                // Sleva: ISDOC nese jen částku před slevou, ne procento.
                'discountAmount' => $this->discountAmount($line, $amountEl . $suffix),
                'vat' => [
                    // vat.code se nemapuje — doplní RowHistoryEnricher
                    // z historie, případně uživatel při review.
                    'pct'               => $noVat ? null : $this->num($line, 'ClassifiedTaxCategory', 'Percent'),
                    'reverseChargeCode' => $this->text($line, 'ClassifiedTaxCategory', 'LocalReverseCharge', 'LocalReverseChargeCode'),
                ],
                // Částky řádku od dodavatele; chybějící element prune()
                // vypustí, řádek bez jediné hodnoty blok nenese (#97 D6).
                'computed' => [
                    'vatBase'   => $this->num($line, 'LineExtensionAmount' . $suffix),
                    'vatAmount' => $this->num($line, 'LineExtensionTaxAmount' . $suffix),
                    'vatTotal'  => $this->num($line, 'LineExtensionAmountTaxInclusive' . $suffix),
                ],
            ];

            if ($isForeign) {
                $row['totalPrice'] = $this->num($line, $amountEl . 'Curr');
                $row['priceCalcMode'] = 'fromTotal';
            } else {
                $row['unitPrice'] = $this->num($line, $pricesWithVat ? 'UnitPriceTaxInclusive' : 'UnitPrice');
                $row['totalPrice'] = $this->num($line, $amountEl);
                $row['priceCalcMode'] = 'fromUnitPrice';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** Kladný rozdíl částky před slevou a po slevě (ve stejném základu — bez daně, u cen s DPH s daní). */
    private function discountAmount(\DOMElement $line, string $amountElement): ?float
    {
        $before = $this->num($line, str_replace('Curr', '', $amountElement) . 'BeforeDiscount' . (str_ends_with($amountElement, 'Curr') ? 'Curr' : ''));
        $after = $this->num($line, $amountElement);
        if ($before === null || $after === null) {
            return null;
        }
        $discount = round($before - $after, 2);
        return $discount > 0.005 ? $discount : null;
    }

    /**
     * `vat.mode` z metody výpočtu DPH: shodná na všech řádcích → fromBase /
     * fromTotal; rozdílná nebo žádná → null (odvodí applier).
     */
    private function calculationMode(\DOMElement $root): ?string
    {
        $methods = [];
        foreach ($this->all($root, 'InvoiceLines', 'InvoiceLine') as $line) {
            $method = $this->text($line, 'ClassifiedTaxCategory', 'VATCalculationMethod');
            if ($method !== null) {
                $methods[(int) $method] = true;
            }
        }
        if (count($methods) !== 1) {
            return null;
        }
        return self::VAT_MODE_MAP[(int) array_key_first($methods)] ?? null;
    }

    /** Tuzemské přenesení daňové povinnosti: kód na řádku nebo příznak v rekapitulaci. */
    private function hasLocalReverseCharge(\DOMElement $root): bool
    {
        foreach ($this->all($root, 'InvoiceLines', 'InvoiceLine') as $line) {
            if ($this->text($line, 'ClassifiedTaxCategory', 'LocalReverseCharge', 'LocalReverseChargeCode') !== null) {
                return true;
            }
        }
        foreach ($this->all($root, 'TaxTotal', 'TaxSubTotal') as $subTotal) {
            if ($this->bool($subTotal, 'TaxCategory', 'LocalReverseChargeFlag') === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plnění podle stran zjevně tuzemské: země dodavatele = země odběratele.
     * Země dodavatele je prefix jeho DIČ (normalizace jako
     * {@see VatPlaceDerivation::normalize()}, první dva znaky, jen písmena),
     * bez DIČ země jeho adresy; země odběratele je země jeho adresy.
     * Chybí-li kterákoli, nebo prefix DIČ není písmenný, false — čtečka
     * pak přenesení neurčí (null). Zvláštní prefix (řecké `EL`) dá
     * nerovnost a tedy null, což je bezpečné.
     *
     * @param array<string, mixed>|null $supplier mapovaná strana ({@see mapParty()})
     * @param array<string, mixed>|null $customer mapovaná strana ({@see mapParty()})
     */
    private function isDomesticSupply(?array $supplier, ?array $customer): bool
    {
        if ($supplier === null || $customer === null) {
            return false;
        }

        $vatId = VatPlaceDerivation::normalize(is_string($supplier['vatId'] ?? null) ? $supplier['vatId'] : null);
        if ($vatId !== '') {
            $prefix = substr($vatId, 0, 2);
            if (strlen($prefix) !== 2 || !ctype_alpha($prefix)) {
                return false;
            }
            $supplierCountry = strtolower($prefix);
        } else {
            $supplierCountry = is_string($supplier['country'] ?? null) ? $supplier['country'] : null;
        }
        $customerCountry = is_string($customer['country'] ?? null) ? $customer['country'] : null;

        return $supplierCountry !== null && $supplierCountry !== '' && $supplierCountry === $customerCountry;
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

    /** XSD boolean (`true` / `false`, tolerantně i `1` / `0`); chybějící element = null. */
    private function bool(\DOMElement $context, string ...$path): ?bool
    {
        $value = $this->text($context, ...$path);
        return match (strtolower((string) $value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
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
