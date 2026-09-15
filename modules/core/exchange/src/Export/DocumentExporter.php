<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * `docs_core_heads` (+ řádky, rekapitulace DPH) → `shpd.docs.document.v1`.
 * Inverze `DocumentApplier::transform()` / `transformRows()`.
 *
 * Reference externě: partner jako Party blok (identifikátory + adresa +
 * banka z denormalizovaných `partner_bank_*`), položky řádků přes
 * `ourCode`, účty přes číslo, jednotky přes `system_code`, řada přes
 * `numberSeriesCode`, vlastní bankovní účet přes kód číselníku
 * (`applyOptions.importOwnBankAccount` jako string).
 *
 * Číslo dokladu se zachovává režimem `applyOptions.importNumber`;
 * koncepty bez čísla ho nemají a číslo dostanou až při potvrzení.
 * Odvozené sloupce (fiskální rok, DPH období, částky v domácí měně,
 * snapshoty partnera, účetní stav) se neexportují — `DocDocument` je při
 * seedu spočítá znovu. Řádkový partner (účetní doklad) formát nenese →
 * warning.
 */
final class DocumentExporter implements RecordExporter
{
    private const ACTIVE_STATES = [10, 40, 80];

    private const DOC_TYPE_NAMES = [
        'invni'  => 'invoiceReceived',
        'invno'  => 'invoiceIssued',
        'cmnbkp' => 'accountingDocument',
    ];

    private const VAT_MODE_NAMES = [0 => 'none', 1 => 'fromBase', 2 => 'fromTotal'];
    private const VAT_PLACE_NAMES = [0 => 'domestic', 1 => 'intracom', 2 => 'thirdCountry'];
    private const VAT_RECAP_SOURCE_NAMES = [0 => 'computed', 1 => 'declared'];
    private const VAT_CALC_SOURCE_NAMES = [0 => 'header', 1 => 'rows'];
    /** docs_core_heads.cs_mode → canonical vat.controlStatementMode (#77). */
    private const CS_MODE_NAMES = [0 => 'auto', 1 => 'detail', 2 => 'aggregate', 3 => 'exclude'];
    private const PAYMENT_METHOD_NAMES = [0 => 'cash', 1 => 'bankTransfer', 2 => 'card', 3 => 'cashOnDelivery', 4 => 'setOff', 5 => 'paymentGateway'];
    private const PRICE_CALC_MODE_NAMES = [0 => 'fromUnitPrice', 1 => 'fromTotal'];
    private const ROW_KIND_NAMES = [0 => 'text', 1 => 'item', 2 => 'section'];

    private ?bool $hasAccounting = null;

    /** @var array<int, ?array<string, mixed>> */
    private array $partyCache = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function section(): string
    {
        return 'docs';
    }

    public function exportAll(): array
    {
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [h.docState] <> 90 ' . $this->orderSql(),
        ));
    }

    public function exportByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [h.id] IN %in AND [h.docState] <> 90 ' . $this->orderSql(),
            $ids,
        ));
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function selectSql(): string
    {
        return 'SELECT [h.*], [ns.doc_number_code], [vr.country] AS [vat_reg_country], [ba.code] AS [own_bank_code]'
            . ' FROM [docs_core_heads] AS [h]'
            . ' LEFT JOIN [docs_core_number_series] AS [ns] ON [ns.id] = [h.number_series]'
            . ' LEFT JOIN [economy_codebooks_vat_registrations] AS [vr] ON [vr.id] = [h.vat_registration]'
            . ' LEFT JOIN [economy_codebooks_bank_accounts] AS [ba] ON [ba.id] = [h.bank_account]';
    }

    private function orderSql(): string
    {
        return 'ORDER BY [h.doc_type], [ns.doc_number_code], [h.sequence_number], [h.doc_number], [h.issue_date], [h.id]';
    }

    private function hasAccounting(): bool
    {
        if ($this->hasAccounting === null) {
            $row = $this->db->fetch("SHOW TABLES LIKE 'economy_accounting_accounts'");
            $this->hasAccounting = $row !== null;
        }
        return $this->hasAccounting;
    }

    /**
     * @param iterable<\Dibi\Row|array<string, mixed>> $rows
     * @return list<ExportedRecord>
     */
    private function exportRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->exportDocument(is_array($row) ? $row : $row->toArray());
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $h
     */
    public function exportDocument(array $h): ExportedRecord
    {
        $id = (int) $h['id'];
        $docTypeCode = (string) $h['doc_type'];
        $docType = self::DOC_TYPE_NAMES[$docTypeCode] ?? $docTypeCode;
        $docState = (int) ($h['docState'] ?? 10);
        $docNumber = V::str($h['doc_number'] ?? null);
        $label = $docNumber ?? "#{$id}";

        // Strana partnera: přijatá faktura → my jsme odběratel, partner dodavatel;
        // vydaná → naopak; účetní doklad partnera v hlavičce nemá povinně.
        [$selfParty, $partnerSide] = match ($docTypeCode) {
            'invno'  => ['supplier', 'customer'],
            'cmnbkp' => [null, null],
            default  => ['customer', 'supplier'],
        };

        $partner = $this->party(V::int($h['partner'] ?? null));
        if ($partnerSide === 'supplier' && $partner !== null) {
            $partner['bankAccount'] = V::prune([
                'accountNumber' => V::str($h['partner_bank_account'] ?? null),
                'iban'          => V::str($h['partner_bank_iban'] ?? null),
                'bic'           => V::str($h['partner_bank_bic'] ?? null),
            ]) ?: null;
        }
        if ($docTypeCode === 'cmnbkp' && $partner !== null) {
            $this->warnings[] = "docs {$label}: hlavičkový partner účetního dokladu formát nenese (přenáší se jen řádkové údaje)";
        }

        $applyOptions = [
            'targetDocState' => in_array($docState, [10, 40, 30, 80], true) ? $docState : 10,
        ];
        if ($docNumber !== null) {
            $seq = V::int($h['sequence_number'] ?? null);
            $applyOptions['importNumber'] = [
                'docNumber'      => $docNumber,
                'sequenceNumber' => ($seq !== null && $seq > 0) ? $seq : null,
            ];
        } elseif ($docState === 80) {
            // 80 bez čísla schema/validátor nepovolí (jen s importNumber).
            $applyOptions['targetDocState'] = 10;
        }
        $seriesCode = V::str($h['doc_number_code'] ?? null);
        if ($seriesCode !== null) {
            $applyOptions['numberSeriesCode'] = $seriesCode;
        }
        $ownBank = V::str($h['own_bank_code'] ?? null);
        if ($ownBank !== null) {
            $applyOptions['importOwnBankAccount'] = $ownBank;
        } elseif (V::int($h['bank_account'] ?? null) !== null) {
            $this->warnings[] = "docs {$label}: vlastní bankovní účet #{$h['bank_account']} nemá kód v číselníku — nelze přenést";
        }

        $canonical = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'source'        => V::prune([
                'kind'        => V::str($h['source_kind'] ?? null),
                'extractedAt' => V::dateTime($h['source_extracted_at'] ?? null),
            ]) ?: null,
            'docType'       => $docType,
            'docNumber'     => V::str($h['partner_doc_number'] ?? null),
            'docText'       => V::str($h['doc_text'] ?? null),
            'selfParty'     => $selfParty,
            'supplier'      => $partnerSide === 'supplier' ? $partner : null,
            'customer'      => $partnerSide === 'customer' ? $partner : null,
            // Jen ruční plátce (#72): odvozený (terminál / dopravce / partner)
            // si cílový DS odvodí sám, export by ho zbytečně zmrazil.
            'balanceParty'  => !empty($h['partner_balance_manual'])
                ? $this->party(isset($h['partner_balance']) ? (int) $h['partner_balance'] : null)
                : null,
            'dates'         => [
                'issueDate'         => V::date($h['issue_date'] ?? null),
                'dueDate'           => V::date($h['due_date'] ?? null),
                'accountingDate'    => V::date($h['accounting_date'] ?? null),
                'taxPointDate'      => V::date($h['vat_duzp'] ?? null),
                'vatObligationDate' => V::date($h['vat_dppd'] ?? null),
                'periodFrom'        => V::date($h['period_from'] ?? null),
                'periodTo'          => V::date($h['period_to'] ?? null),
            ],
            'currency'      => V::currencyUpper($h['doc_currency'] ?? null),
            'exchangeRate'  => V::float($h['exchange_rate'] ?? null),
            'vat'           => [
                'mode'                => self::VAT_MODE_NAMES[(int) ($h['vat_mode'] ?? 1)] ?? null,
                'place'               => self::VAT_PLACE_NAMES[(int) ($h['vat_place'] ?? 0)] ?? null,
                'registrationCountry' => V::countryLower($h['vat_reg_country'] ?? null),
                // Autorita rekapitulace a metoda výpočtu — bez nich by
                // round-trip (dump → seed) změnil doklad s převzatou
                // rekapitulací na přepočítaný a přepsal částky z dokladu.
                'recapSource'         => self::VAT_RECAP_SOURCE_NAMES[(int) ($h['vat_recap_source'] ?? 0)] ?? null,
                'calcSource'          => self::VAT_CALC_SOURCE_NAMES[(int) ($h['vat_calc_source'] ?? 0)] ?? null,
                // Ruční zařazení do KH — bez něj by seed vrátil doklad
                // do automatiky a hlášení by vyšlo jinak než podané.
                'controlStatementMode' => self::CS_MODE_NAMES[(int) ($h['cs_mode'] ?? 0)] ?? null,
            ],
            'payment'       => [
                'method'           => self::PAYMENT_METHOD_NAMES[(int) ($h['payment_method'] ?? 1)] ?? null,
                'paymentReference' => V::str($h['payment_reference'] ?? null),
                'specificSymbol'   => V::str($h['specific_symbol'] ?? null),
                'constantSymbol'   => V::str($h['constant_symbol'] ?? null),
            ],
            'notes'         => [
                'internal'   => V::str($h['notice'] ?? null),
                'onDocument' => V::str($h['doc_notice'] ?? null),
            ],
            'rows'          => $this->rows($id, $label),
            'vatRecap'      => $this->vatRecap($id),
            'totals'        => [
                'totalBase'     => V::float($h['total_base'] ?? null),
                'totalVat'      => V::float($h['total_vat'] ?? null),
                'totalAmount'   => V::float($h['total_amount'] ?? null),
                'totalRounding' => V::float($h['total_rounding'] ?? null),
            ],
            'applyOptions'  => $applyOptions,
        ];

        $slug = $docNumber !== null ? $docNumber : "koncept {$docTypeCode}";

        $pruned = V::prune($canonical);
        // Explicitní `sequenceNumber: null` (číslo mimo vzorec řady) je ve
        // schématu povinný klíč — prune ho nesmí odstranit.
        if (isset($pruned['applyOptions']['importNumber']) && !array_key_exists('sequenceNumber', $pruned['applyOptions']['importNumber'])) {
            $pruned['applyOptions']['importNumber']['sequenceNumber'] = null;
        }

        return new ExportedRecord($id, $slug, $pruned);
    }

    // ── partner ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function party(?int $personId): ?array
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }
        if (array_key_exists($personId, $this->partyCache)) {
            return $this->partyCache[$personId];
        }

        $p = $this->db->fetch('SELECT * FROM [base_persons_persons] WHERE [id] = %i', $personId);
        if ($p === null) {
            return $this->partyCache[$personId] = null;
        }
        $p = is_array($p) ? $p : $p->toArray();
        $a = $this->db->fetch(
            'SELECT * FROM [base_persons_addresses] WHERE [person] = %i AND [docState] IN %in
             ORDER BY [order_pos], [address_type], [id] LIMIT 1',
            $personId,
            self::ACTIVE_STATES,
        );
        $a = $a === null ? [] : (is_array($a) ? $a : $a->toArray());

        $party = [
            'name'              => V::str($p['full_name'] ?? null),
            'country'           => V::countryLower($a['country'] ?? null),
            'companyId'         => V::str($p['company_id'] ?? null),
            'taxId'             => V::str($p['tax_id'] ?? null),
            'vatId'             => V::str($p['vat_id'] ?? null),
            'courtRegistration' => V::str($p['court_registration'] ?? null),
            'address'           => [
                'street'       => V::str($a['street'] ?? null),
                'houseNumber'  => V::str($a['house_number'] ?? null),
                'city'         => V::str($a['city'] ?? null),
                'cityPart'     => V::str($a['city_part'] ?? null),
                'zip'          => V::str($a['zip'] ?? null),
                'country'      => V::countryLower($a['country'] ?? null),
                'registryCode' => V::str($a['registry_code'] ?? null),
            ],
            'contact'           => [
                'email' => V::str($p['email'] ?? null),
                'phone' => V::str($p['phone'] ?? null),
                'web'   => V::str($p['web'] ?? null),
            ],
        ];

        return $this->partyCache[$personId] = V::prune($party);
    }

    // ── rows / recap ────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $headId, string $label): array
    {
        $accountSelect = $this->hasAccounting() ? ', [a.number] AS [account_number]' : ', NULL AS [account_number]';
        $accountJoin = $this->hasAccounting()
            ? ' LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [r.account]'
            : '';
        $rows = $this->db->fetchAll(
            'SELECT [r.*], [i.code] AS [item_code], [i.name] AS [item_name],'
            . ' [u.system_code] AS [unit_code], [u.shortcut] AS [unit_shortcut]'
            . $accountSelect
            . ' FROM [docs_core_rows] AS [r]'
            . ' LEFT JOIN [economy_items] AS [i] ON [i.id] = [r.item]'
            . ' LEFT JOIN [core_units] AS [u] ON [u.id] = [r.unit]'
            . $accountJoin
            . ' WHERE [r.doc_head] = %i ORDER BY [r.order_pos], [r.id]',
            $headId,
        );

        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            if (V::int($r['partner'] ?? null) !== null) {
                $this->warnings[] = "docs {$label}: řádek {$r['order_pos']} má partnera, formát ho nenese";
            }
            $accSide = V::int($r['acc_side'] ?? null);
            $itemCode = V::str($r['item_code'] ?? null);
            $out[] = V::prune([
                'rowKind'        => self::ROW_KIND_NAMES[(int) ($r['row_kind'] ?? 1)] ?? 'item',
                'operation'      => V::str($r['operation'] ?? null),
                'orderPos'       => V::int($r['order_pos'] ?? null),
                'item'           => $itemCode !== null ? [
                    'ourCode' => $itemCode,
                    'name'    => V::str($r['item_name'] ?? null),
                ] : null,
                'unit'           => V::str($r['unit_code'] ?? null) ?? V::str($r['unit_shortcut'] ?? null),
                'quantity'       => V::float($r['quantity'] ?? null),
                'unitPrice'      => V::float($r['unit_price'] ?? null),
                'totalPrice'     => V::float($r['total_price'] ?? null),
                'priceCalcMode'  => self::PRICE_CALC_MODE_NAMES[(int) ($r['price_calc_mode'] ?? 0)] ?? null,
                'discountPct'    => V::float($r['discount_pct'] ?? null),
                'discountAmount' => V::float($r['discount_amount'] ?? null),
                'vat'            => [
                    'code' => V::str($r['vat_code'] ?? null),
                    'pct'  => V::float($r['vat_pct'] ?? null),
                ],
                'computed'       => [
                    'vatBase'   => V::float($r['vat_base'] ?? null),
                    'vatAmount' => V::float($r['vat_amount'] ?? null),
                    'vatTotal'  => V::float($r['vat_total'] ?? null),
                ],
                'description'      => V::str($r['description'] ?? null),
                'account'          => V::str($r['account_number'] ?? null),
                'accSide'          => $accSide === null ? null : ($accSide === 1 ? 'credit' : 'debit'),
                'paymentReference' => V::str($r['payment_reference'] ?? null),
                'specificSymbol'   => V::str($r['specific_symbol'] ?? null),
                'constantSymbol'   => V::str($r['constant_symbol'] ?? null),
                'dueDate'          => V::date($r['due_date'] ?? null),
            ]);
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vatRecap(int $headId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_vat_recap] WHERE [doc_head] = %i ORDER BY [order_pos], [vat_code], [id]',
            $headId,
        );
        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $out[] = V::prune([
                'vatCode'       => V::str($r['vat_code'] ?? null),
                'vatPct'        => V::float($r['vat_pct'] ?? null),
                'base'          => V::float($r['base'] ?? null),
                'tax'           => V::float($r['tax'] ?? null),
                'total'         => V::float($r['total'] ?? null),
                'isReversePair' => ((int) ($r['is_reverse_pair'] ?? 0)) === 1 ? true : null,
            ]);
        }
        return $out;
    }
}
