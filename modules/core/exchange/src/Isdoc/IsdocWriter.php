<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

use Shipard\Module\Core\Exchange\EInvoice\EInvoiceInputException;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceWriter;
use Shipard\Module\Docs\Core\Prints\PaymentQr\CzIbanCalculator;

/**
 * Generátor ISDOC 6.0.1 z canonicalu `shpd.docs.document.v1` (#119 D1–D7,
 * D10; mapování a výjimky round-tripu: docs/einvoice.md). Čistá funkce
 * array → XML, protějšek {@see IsdocReader}.
 *
 * Zásady:
 *   - vstupem je papírová projekce dokladu + `rows[].computed`; částky
 *     z `computed`, `vatRecap` a `totals` se jen formátují. Dopočítávají
 *     se jen informativní povinné prvky: druhá jednotková cena (s daní,
 *     resp. bez daně u cen s DPH), `TaxInclusiveAmount` součtů a částka
 *     řádku před slevou,
 *   - chybějící povinná hodnota je {@see EInvoiceInputException} se všemi
 *     cestami najednou — prázdný element XSD nepozná (D2, D10),
 *   - jen doklady v Kč (D4), typy faktura (1) a zálohová faktura (4) (D5),
 *     obě strany české s IČO a úplnou adresou (D10); prázdné smějí zůstat
 *     jen název banky a BIC,
 *   - žádné časové razítko ani jiná proměnná hodnota — stejný vstup dává
 *     stejné bajty; UUID a vystavující systém dodává {@see EInvoiceOptions},
 *   - pořadí elementů přesně podle `xs:sequence` schématu, booleany
 *     `true` / `false`; výstup musí projít XSD 6.0.1 i 6.0.2
 *     ({@see IsdocSchemaValidator}), `version="6.0.1"` kvůli kompatibilitě.
 */
final class IsdocWriter implements EInvoiceWriter
{
    public const FORMAT = 'isdoc';
    public const NAMESPACE = 'http://isdoc.cz/namespace/2013';
    public const VERSION = '6.0.1';

    /** Canonical docType → ISDOC DocumentType (D5); opravné doklady až s #92. */
    private const DOC_TYPE_MAP = [
        'invoiceIssued'    => 1,
        'invoiceReceived'  => 1,
        'proformaIssued'   => 4,
        'proformaReceived' => 4,
    ];

    /** Canonical payment.method → ISDOC PaymentMeansCode. */
    private const PAYMENT_MEANS_CODE = [
        'bankTransfer'   => 42,
        'paymentGateway' => 42,
        'cash'           => 10,
        'card'           => 48,
        'cashOnDelivery' => 50,
        'setOff'         => 97,
    ];

    private const VAT_MODES = ['fromBase', 'fromTotal', 'none'];
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
    /** `[předčíslí-]číslo/kód` — tvar, který umí i CzIbanCalculator. */
    private const ACCOUNT_RE = '#^(?:\d{1,6}-)?\d{2,10}/\d{4}$#';
    private const ID_MAX_LENGTH = 36;

    /** @var list<array{path: string, message: string}> */
    private array $problems = [];

    public function format(): string
    {
        return self::FORMAT;
    }

    public function write(array $canonical, EInvoiceOptions $options): string
    {
        $this->problems = [];
        $model = $this->check($canonical, $options);
        if ($this->problems !== []) {
            throw new EInvoiceInputException($this->problems);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $invoice = $dom->createElementNS(self::NAMESPACE, 'Invoice');
        $invoice->setAttribute('version', self::VERSION);
        $dom->appendChild($invoice);

        $this->writeHeader($invoice, $model, $options);
        $this->writeParty($invoice, 'AccountingSupplierParty', $model['supplier']);
        $this->writeParty($invoice, 'AccountingCustomerParty', $model['customer']);
        $this->writeLines($invoice, $model);
        $this->writeTaxTotal($invoice, $model);
        $this->writeMonetaryTotal($invoice, $model);
        $this->writePaymentMeans($invoice, $model);

        return (string) $dom->saveXML();
    }

    // ── Kontrola a normalizace vstupu ───────────────────────────────────────

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed> model pro zápis (platný jen bez nálezů)
     */
    private function check(array $c, EInvoiceOptions $options): array
    {
        $docTypeRaw = $c['docType'] ?? null;
        $docType = is_string($docTypeRaw) ? (self::DOC_TYPE_MAP[$docTypeRaw] ?? null) : null;
        if ($docType === null) {
            $this->problem('docType', 'typ dokladu ' . var_export($docTypeRaw, true)
                . ' není podporován (jen faktura a zálohová faktura, D5)');
        }

        $docNumber = $this->reqText($c, 'docNumber');
        if ($docNumber !== null && mb_strlen($docNumber) > self::ID_MAX_LENGTH) {
            $this->problem('docNumber', 'delší než ' . self::ID_MAX_LENGTH . ' znaků');
        }

        $mode = $c['vat']['mode'] ?? null;
        if (!in_array($mode, self::VAT_MODES, true)) {
            $this->problem('vat.mode', 'chybí nebo není fromBase / fromTotal / none');
            $mode = 'fromBase';
        }
        $vatApplicable = $mode !== 'none';
        $reverseCharge = ($c['vat']['reverseCharge'] ?? null) === true;

        $issueDate = $this->reqDate($c, 'dates.issueDate');
        $taxPointDate = null;
        if ($docType === 1 && $vatApplicable) {
            $taxPointDate = $this->reqDate($c, 'dates.taxPointDate');
        }

        if (($c['currency'] ?? null) !== 'CZK') {
            $this->problem('currency', 'ISDOC zatím jen pro doklady v Kč (D4)');
        }
        $rate = $c['exchangeRate'] ?? null;
        if ($rate !== null && (!is_numeric($rate) || abs((float) $rate - 1.0) > 1e-9)) {
            $this->problem('exchangeRate', 'doklad v Kč má kurz 1 nebo žádný (D4)');
        }

        $supplier = $this->checkParty($c, 'supplier', $options);
        $customer = $this->checkParty($c, 'customer', $options);

        [$rows, $reverseChargePcts] = $this->checkRows($c, $mode, $vatApplicable, $reverseCharge);
        if ($reverseCharge && $reverseChargePcts === []) {
            $this->problem('vat.reverseCharge', 'přenesení daňové povinnosti bez reverseChargeCode na řádku');
        }

        $recap = $this->checkRecap($c, $vatApplicable, $reverseChargePcts);
        $totals = $this->checkTotals($c, $vatApplicable);
        $payment = $this->checkPayment($c);

        return [
            'docType'       => $docType,
            'docNumber'     => $docNumber,
            'mode'          => $mode,
            'vatApplicable' => $vatApplicable,
            'issueDate'     => $issueDate,
            'taxPointDate'  => $taxPointDate,
            'note'          => $this->optText($c, 'notes.onDocument'),
            'supplier'      => $supplier,
            'customer'      => $customer,
            'rows'          => $rows,
            'recap'         => $recap,
            'totals'        => $totals,
            'payment'       => $payment,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function checkParty(array $c, string $key, EInvoiceOptions $options): array
    {
        $party = $c[$key] ?? null;
        if (!is_array($party)) {
            $this->problem($key, 'chybí strana dokladu');
            $party = [];
        }
        $countryCode = $this->reqText($party, 'address.country', $key);
        $countryName = null;
        if ($countryCode !== null) {
            $countryName = $options->countryNames->nameFor($countryCode);
            if ($countryName === null) {
                $this->problem("{$key}.address.country", "neznámý kód země '{$countryCode}'");
            }
        }

        $contact = [
            'name'  => $this->optText($party, 'contact.name'),
            'phone' => $this->optText($party, 'contact.phone'),
            'email' => $this->optText($party, 'contact.email'),
        ];

        return [
            'companyId'         => $this->reqText($party, 'companyId', $key),
            'name'              => $this->reqText($party, 'name', $key),
            'street'            => $this->reqText($party, 'address.street', $key),
            'houseNumber'       => $this->reqText($party, 'address.houseNumber', $key),
            'city'              => $this->reqText($party, 'address.city', $key),
            'zip'               => $this->reqText($party, 'address.zip', $key),
            'countryCode'       => $countryCode !== null ? strtoupper($countryCode) : null,
            'countryName'       => $countryName,
            'vatId'             => $this->optText($party, 'vatId'),
            'courtRegistration' => $this->optText($party, 'courtRegistration'),
            'contact'           => array_filter($contact, static fn (?string $v): bool => $v !== null),
        ];
    }

    /**
     * Položkové řádky (text a sekce do ISDOC nejdou) s dopočtenými
     * informativními hodnotami.
     *
     * @param array<string, mixed> $c
     * @return array{0: list<array<string, mixed>>, 1: list<float>} řádky, sazby s přenesením
     */
    private function checkRows(array $c, string $mode, bool $vatApplicable, bool $reverseCharge): array
    {
        $rows = [];
        $reverseChargePcts = [];
        $source = $c['rows'] ?? null;
        if (!is_array($source)) {
            $source = [];
        }
        foreach ($source as $i => $row) {
            $path = "rows[{$i}]";
            if (!is_array($row) || (($row['rowKind'] ?? 'item') !== 'item')) {
                continue;
            }
            $quantity = $this->reqNumber($row, 'quantity', $path);
            $unitPrice = $this->reqNumber($row, 'unitPrice', $path);
            $pct = $this->optNumber($row, 'vat.pct', $path);
            if ($pct === null) {
                if ($vatApplicable) {
                    $this->problem("{$path}.vat.pct", 'chybí sazba DPH');
                }
                $pct = 0.0;
            }
            $vatBase = $this->reqNumber($row, 'computed.vatBase', $path);
            $vatAmount = $this->reqNumber($row, 'computed.vatAmount', $path);
            $vatTotal = $this->reqNumber($row, 'computed.vatTotal', $path);

            $code = $this->optText($row, 'vat.reverseChargeCode');
            if ($code !== null) {
                if (!$reverseCharge) {
                    $this->problem("{$path}.vat.reverseChargeCode", 'kód přenesení bez vat.reverseCharge na hlavičce');
                }
                $reverseChargePcts[] = $pct;
            }

            $discountPct = $this->optNumber($row, 'discountPct', $path);
            $discountAmount = $this->optNumber($row, 'discountAmount', $path);
            $beforeDiscount = null;
            if ($vatBase !== null) {
                if ($discountAmount !== null && $discountAmount > 0) {
                    $beforeDiscount = $vatBase + $discountAmount;
                } elseif ($discountPct !== null && $discountPct > 0 && $discountPct < 100) {
                    $beforeDiscount = round($vatBase / (1 - $discountPct / 100), 2);
                }
            }

            $factor = 1 + $pct / 100;
            $rows[] = [
                'quantity'          => $quantity,
                'unit'              => $this->optText($row, 'unit'),
                'vatBase'           => $vatBase,
                'vatAmount'         => $vatAmount,
                'vatTotal'          => $vatTotal,
                'beforeDiscount'    => $beforeDiscount,
                // Jedna jednotková cena z canonicalu, druhá dopočtená (informativní).
                'unitPrice'         => $mode === 'fromTotal' && $unitPrice !== null
                    ? round($unitPrice / $factor, 2) : $unitPrice,
                'unitPriceTaxIncl'  => $mode === 'fromTotal' || $unitPrice === null
                    ? $unitPrice : round($unitPrice * $factor, 2),
                'pct'               => $pct,
                'reverseChargeCode' => $code,
                'description'       => $this->optText($row, 'item.description'),
                'name'              => $this->optText($row, 'item.name'),
                'ean'               => $this->optText($row, 'item.ean'),
                'supplierCode'      => $this->optText($row, 'item.supplierCode'),
            ];
        }
        if ($rows === []) {
            $this->problem('rows', 'doklad nemá žádný položkový řádek');
        }

        return [$rows, array_values(array_unique($reverseChargePcts))];
    }

    /**
     * @param array<string, mixed> $c
     * @param list<float> $reverseChargePcts
     * @return list<array<string, mixed>>
     */
    private function checkRecap(array $c, bool $vatApplicable, array $reverseChargePcts): array
    {
        if (!$vatApplicable) {
            // Doklad bez DPH: jeden TaxSubTotal 0 % — základ = částka celkem, daň 0.
            $total = $this->optNumber($c, 'totals.totalAmount', '') ?? 0.0;
            return [['pct' => 0.0, 'base' => $total, 'tax' => 0.0, 'total' => $total, 'reverseCharge' => false]];
        }
        $source = $c['vatRecap'] ?? null;
        if (!is_array($source) || $source === []) {
            $this->problem('vatRecap', 'chybí rekapitulace DPH');
            return [];
        }
        $recap = [];
        foreach (array_values($source) as $i => $entry) {
            $path = "vatRecap[{$i}]";
            if (!is_array($entry)) {
                $this->problem($path, 'není objekt');
                continue;
            }
            $pct = $this->reqNumber($entry, 'vatPct', $path) ?? 0.0;
            $recap[] = [
                'pct'           => $pct,
                'base'          => $this->reqNumber($entry, 'base', $path),
                'tax'           => $this->reqNumber($entry, 'tax', $path),
                'total'         => $this->reqNumber($entry, 'total', $path),
                'reverseCharge' => in_array($pct, $reverseChargePcts, true),
            ];
        }
        return $recap;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, float|null>
     */
    private function checkTotals(array $c, bool $vatApplicable): array
    {
        $totalAmount = $this->reqNumber($c, 'totals.totalAmount');
        $totalRounding = $this->optNumber($c, 'totals.totalRounding', '') ?? 0.0;
        if ($vatApplicable) {
            $totalBase = $this->reqNumber($c, 'totals.totalBase');
            $totalVat = $this->reqNumber($c, 'totals.totalVat');
        } else {
            $totalBase = $totalAmount;
            $totalVat = 0.0;
        }
        return [
            'totalBase'     => $totalBase,
            'totalVat'      => $totalVat,
            'totalAmount'   => $totalAmount,
            'totalRounding' => $totalRounding,
            'taxInclusive'  => $totalAmount !== null ? round($totalAmount - $totalRounding, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>|null null = bez PaymentMeans
     */
    private function checkPayment(array $c): ?array
    {
        $method = $this->optText($c, 'payment.method');
        if ($method === null) {
            return null;
        }
        $code = self::PAYMENT_MEANS_CODE[$method] ?? null;
        if ($code === null) {
            $this->problem('payment.method', "způsob úhrady '{$method}' není podporován");
            return null;
        }
        $payment = ['code' => $code, 'details' => null];
        if ($method !== 'bankTransfer') {
            return $payment;
        }

        $account = $this->reqText($c, 'supplier.bankAccount.accountNumber');
        $accountId = null;
        $bankCode = null;
        $iban = $this->optText($c, 'supplier.bankAccount.iban');
        if ($account !== null) {
            $account = preg_replace('/\s+/', '', $account) ?? $account;
            if (preg_match(self::ACCOUNT_RE, $account) !== 1) {
                $this->problem('supplier.bankAccount.accountNumber', "'{$account}' není ve tvaru [předčíslí-]číslo/kód");
            } else {
                [$accountId, $bankCode] = explode('/', $account, 2);
                $iban ??= CzIbanCalculator::fromAccountNumber($account);
            }
        }
        $payment['details'] = [
            'dueDate'        => $this->reqDate($c, 'dates.dueDate'),
            'accountId'      => $accountId,
            'bankCode'       => $bankCode,
            'iban'           => $iban,
            'bic'            => $this->optText($c, 'supplier.bankAccount.bic'),
            'variableSymbol' => $this->optText($c, 'payment.paymentReference'),
            'constantSymbol' => $this->optText($c, 'payment.constantSymbol'),
            'specificSymbol' => $this->optText($c, 'payment.specificSymbol'),
        ];
        return $payment;
    }

    // ── Zápis ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $m */
    private function writeHeader(\DOMElement $invoice, array $m, EInvoiceOptions $options): void
    {
        $this->add($invoice, 'DocumentType', (string) $m['docType']);
        $this->add($invoice, 'ID', (string) $m['docNumber']);
        $this->add($invoice, 'UUID', strtolower($options->uuid));
        $this->add($invoice, 'IssuingSystem', trim($options->issuingSystem));
        $this->add($invoice, 'IssueDate', (string) $m['issueDate']);
        if ($m['taxPointDate'] !== null) {
            $this->add($invoice, 'TaxPointDate', (string) $m['taxPointDate']);
        }
        $this->add($invoice, 'VATApplicable', self::bool($m['vatApplicable']));
        $this->add($invoice, 'ElectronicPossibilityAgreementReference');
        if ($m['note'] !== null) {
            $this->add($invoice, 'Note', (string) $m['note']);
        }
        $this->add($invoice, 'LocalCurrencyCode', 'CZK');
        $this->add($invoice, 'CurrRate', '1');
        $this->add($invoice, 'RefCurrRate', '1');
    }

    /** @param array<string, mixed> $p */
    private function writeParty(\DOMElement $invoice, string $wrapper, array $p): void
    {
        $party = $this->add($this->add($invoice, $wrapper), 'Party');
        $this->add($this->add($party, 'PartyIdentification'), 'ID', (string) $p['companyId']);
        $this->add($this->add($party, 'PartyName'), 'Name', (string) $p['name']);
        $address = $this->add($party, 'PostalAddress');
        $this->add($address, 'StreetName', (string) $p['street']);
        $this->add($address, 'BuildingNumber', (string) $p['houseNumber']);
        $this->add($address, 'CityName', (string) $p['city']);
        $this->add($address, 'PostalZone', (string) $p['zip']);
        $country = $this->add($address, 'Country');
        $this->add($country, 'IdentificationCode', (string) $p['countryCode']);
        $this->add($country, 'Name', (string) $p['countryName']);
        if ($p['vatId'] !== null) {
            $scheme = $this->add($party, 'PartyTaxScheme');
            $this->add($scheme, 'CompanyID', (string) $p['vatId']);
            $this->add($scheme, 'TaxScheme', 'VAT');
        }
        if ($p['courtRegistration'] !== null) {
            $this->add($this->add($party, 'RegisterIdentification'), 'Preformatted', (string) $p['courtRegistration']);
        }
        if ($p['contact'] !== []) {
            $contact = $this->add($party, 'Contact');
            foreach (['name' => 'Name', 'phone' => 'Telephone', 'email' => 'ElectronicMail'] as $key => $element) {
                if (isset($p['contact'][$key])) {
                    $this->add($contact, $element, (string) $p['contact'][$key]);
                }
            }
        }
    }

    /** @param array<string, mixed> $m */
    private function writeLines(\DOMElement $invoice, array $m): void
    {
        $lines = $this->add($invoice, 'InvoiceLines');
        foreach ($m['rows'] as $n => $row) {
            $line = $this->add($lines, 'InvoiceLine');
            $this->add($line, 'ID', (string) ($n + 1));
            $quantity = $this->add($line, 'InvoicedQuantity', self::plain($row['quantity']));
            if ($row['unit'] !== null) {
                $quantity->setAttribute('unitCode', (string) $row['unit']);
            }
            $this->add($line, 'LineExtensionAmount', self::amount($row['vatBase']));
            if ($row['beforeDiscount'] !== null) {
                $this->add($line, 'LineExtensionAmountBeforeDiscount', self::amount($row['beforeDiscount']));
            }
            $this->add($line, 'LineExtensionAmountTaxInclusive', self::amount($row['vatTotal']));
            $this->add($line, 'LineExtensionTaxAmount', self::amount($row['vatAmount']));
            $this->add($line, 'UnitPrice', self::amount($row['unitPrice']));
            $this->add($line, 'UnitPriceTaxInclusive', self::amount($row['unitPriceTaxIncl']));

            $category = $this->add($line, 'ClassifiedTaxCategory');
            $this->add($category, 'Percent', self::plain($row['pct']));
            $this->add($category, 'VATCalculationMethod', $m['mode'] === 'fromTotal' ? '1' : '0');
            if (!$m['vatApplicable']) {
                $this->add($category, 'VATApplicable', 'false');
            }
            if ($row['reverseChargeCode'] !== null) {
                $this->add($this->add($category, 'LocalReverseCharge'), 'LocalReverseChargeCode', (string) $row['reverseChargeCode']);
            }

            if ($row['description'] !== null) {
                $this->add($line, 'Note', (string) $row['description']);
            }
            if ($row['name'] !== null || $row['ean'] !== null || $row['supplierCode'] !== null) {
                $item = $this->add($line, 'Item');
                if ($row['name'] !== null) {
                    $this->add($item, 'Description', (string) $row['name']);
                }
                if ($row['ean'] !== null) {
                    $this->add($this->add($item, 'CatalogueItemIdentification'), 'ID', (string) $row['ean']);
                }
                if ($row['supplierCode'] !== null) {
                    $this->add($this->add($item, 'SellersItemIdentification'), 'ID', (string) $row['supplierCode']);
                }
            }
        }
    }

    /** @param array<string, mixed> $m */
    private function writeTaxTotal(\DOMElement $invoice, array $m): void
    {
        $taxTotal = $this->add($invoice, 'TaxTotal');
        foreach ($m['recap'] as $entry) {
            $sub = $this->add($taxTotal, 'TaxSubTotal');
            $this->add($sub, 'TaxableAmount', self::amount($entry['base']));
            $this->add($sub, 'TaxAmount', self::amount($entry['tax']));
            $this->add($sub, 'TaxInclusiveAmount', self::amount($entry['total']));
            // Zálohy: nic nebylo uplatněno, rozdíl = celý předpis (bloky záloh jsou nulové).
            $this->add($sub, 'AlreadyClaimedTaxableAmount', '0.00');
            $this->add($sub, 'AlreadyClaimedTaxAmount', '0.00');
            $this->add($sub, 'AlreadyClaimedTaxInclusiveAmount', '0.00');
            $this->add($sub, 'DifferenceTaxableAmount', self::amount($entry['base']));
            $this->add($sub, 'DifferenceTaxAmount', self::amount($entry['tax']));
            $this->add($sub, 'DifferenceTaxInclusiveAmount', self::amount($entry['total']));
            $category = $this->add($sub, 'TaxCategory');
            $this->add($category, 'Percent', self::plain($entry['pct']));
            if (!$m['vatApplicable']) {
                $this->add($category, 'VATApplicable', 'false');
            }
            if ($entry['reverseCharge']) {
                $this->add($category, 'LocalReverseChargeFlag', 'true');
            }
        }
        $this->add($taxTotal, 'TaxAmount', self::amount($m['totals']['totalVat']));
    }

    /** @param array<string, mixed> $m */
    private function writeMonetaryTotal(\DOMElement $invoice, array $m): void
    {
        $t = $m['totals'];
        $total = $this->add($invoice, 'LegalMonetaryTotal');
        $this->add($total, 'TaxExclusiveAmount', self::amount($t['totalBase']));
        $this->add($total, 'TaxInclusiveAmount', self::amount($t['taxInclusive']));
        $this->add($total, 'AlreadyClaimedTaxExclusiveAmount', '0.00');
        $this->add($total, 'AlreadyClaimedTaxInclusiveAmount', '0.00');
        $this->add($total, 'DifferenceTaxExclusiveAmount', self::amount($t['totalBase']));
        $this->add($total, 'DifferenceTaxInclusiveAmount', self::amount($t['taxInclusive']));
        $this->add($total, 'PayableRoundingAmount', self::amount($t['totalRounding']));
        $this->add($total, 'PaidDepositsAmount', '0.00');
        $this->add($total, 'PayableAmount', self::amount($t['totalAmount']));
    }

    /** @param array<string, mixed> $m */
    private function writePaymentMeans(\DOMElement $invoice, array $m): void
    {
        $payment = $m['payment'];
        if ($payment === null) {
            return;
        }
        $p = $this->add($this->add($invoice, 'PaymentMeans'), 'Payment');
        $this->add($p, 'PaidAmount', self::amount($m['totals']['totalAmount']));
        $this->add($p, 'PaymentMeansCode', (string) $payment['code']);
        $d = $payment['details'];
        if ($d === null) {
            return;
        }
        $details = $this->add($p, 'Details');
        $this->add($details, 'PaymentDueDate', (string) $d['dueDate']);
        $this->add($details, 'ID', (string) $d['accountId']);
        $this->add($details, 'BankCode', (string) $d['bankCode']);
        $this->add($details, 'Name');                                  // název banky nemáme (D10)
        $this->add($details, 'IBAN', (string) $d['iban']);
        $this->add($details, 'BIC', (string) ($d['bic'] ?? ''));       // BIC smí chybět (D10)
        foreach (['variableSymbol' => 'VariableSymbol', 'constantSymbol' => 'ConstantSymbol', 'specificSymbol' => 'SpecificSymbol'] as $key => $element) {
            if ($d[$key] !== null) {
                $this->add($details, $element, (string) $d[$key]);
            }
        }
    }

    // ── DOM a formát hodnot ─────────────────────────────────────────────────

    private function add(\DOMElement $parent, string $name, ?string $text = null): \DOMElement
    {
        $doc = $parent->ownerDocument;
        assert($doc !== null);
        $element = $doc->createElementNS(self::NAMESPACE, $name);
        if ($text !== null && $text !== '') {
            $element->appendChild($doc->createTextNode($text));
        }
        $parent->appendChild($element);
        return $element;
    }

    /** Částka na 2 desetinná místa, desetinná tečka, bez `-0.00`. */
    private static function amount(float|int|string|null $value): string
    {
        $text = number_format(round((float) $value, 2), 2, '.', '');
        return $text === '-0.00' ? '0.00' : $text;
    }

    /** Množství a sazby bez koncových nul (`12.5`, `21`, `0`). */
    private static function plain(float|int|string|null $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    // ── Přístup k canonicalu s nálezy ───────────────────────────────────────

    private function problem(string $path, string $message): void
    {
        $this->problems[] = ['path' => $path, 'message' => $message];
    }

    /** @param array<string, mixed> $data */
    private static function at(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private static function fullPath(string $prefix, string $path): string
    {
        return $prefix === '' ? $path : "{$prefix}.{$path}";
    }

    /** @param array<string, mixed> $data */
    private function optText(array $data, string $path): ?string
    {
        $value = self::at($data, $path);
        if ($value === null) {
            return null;
        }
        $text = trim(is_scalar($value) ? (string) $value : '');
        return $text !== '' ? $text : null;
    }

    /** @param array<string, mixed> $data */
    private function reqText(array $data, string $path, string $prefix = ''): ?string
    {
        $text = $this->optText($data, $path);
        if ($text === null) {
            $this->problem(self::fullPath($prefix, $path), 'chybí');
        }
        return $text;
    }

    /** @param array<string, mixed> $data */
    private function optNumber(array $data, string $path, string $prefix): ?float
    {
        $value = self::at($data, $path);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || is_bool($value)) {
            $this->problem(self::fullPath($prefix, $path), 'není číslo');
            return null;
        }
        return (float) $value;
    }

    /** @param array<string, mixed> $data */
    private function reqNumber(array $data, string $path, string $prefix = ''): ?float
    {
        if (self::at($data, $path) === null) {
            $this->problem(self::fullPath($prefix, $path), 'chybí');
            return null;
        }
        return $this->optNumber($data, $path, $prefix);
    }

    /** @param array<string, mixed> $data */
    private function reqDate(array $data, string $path): ?string
    {
        $text = $this->reqText($data, $path);
        if ($text !== null && preg_match(self::DATE_RE, $text) !== 1) {
            $this->problem($path, "'{$text}' není datum RRRR-MM-DD");
            return null;
        }
        return $text;
    }
}
