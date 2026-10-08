<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\DocumentLanguageResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Docs\Core\DefaultBankAccountResolver;

/**
 * Sestaví kanonický doklad `shpd.docs.document.v1` za jedno období
 * periodické zakázky (docs/work-orders.md §5.5, tasks/work-orders-phase2.md
 * §2). Doklad pak vzniká výhradně přes DocumentApplier (Q1) — builder nic
 * nezapisuje a žádné odvození DPH, řady ani zaokrouhlení nedělá.
 *
 *  - typ dokladu, řada, splatnost, fakturace na počátku / konci, režim DPH,
 *    způsob platby a vlastní účet z efektivního předpisu
 *    (InvoicingSettingsResolver); prázdný účet = výchozí účet dokladu
 *    (DefaultBankAccountResolver, Q5);
 *  - datum vystavení = účetní datum = DUZP = den fakturace období (D6),
 *    splatnost = + dny; `periodFrom` / `periodTo` = období;
 *  - partner = zákazník zakázky jako kanonická strana + pin `useExisting`;
 *  - text dokladu z `inv_doc_text` s `{období}` v jazyce dokumentu
 *    zákazníka (DocumentLanguageResolver, PeriodLabel), prázdný = „název
 *    období“ (Q6);
 *  - pevný VS (D11), dimenze zakázka a středisko, `source.kind = workOrder`;
 *  - řádky předpisu platné k DUZP (`valid_from ≤ DUZP ≤ valid_to`, NULL
 *    neomezeno); žádný = InvoiceBuildException `no_rows`.
 *
 * Řádky přispěvatelů (D10) builder jen zařadí s množstvím 0 — doplní je
 * běh (InvoicingRunService) podle výsledku přispěvatele.
 */
class InvoiceBuilder
{
    public const SOURCE_KIND = 'workOrder';
    public const DIMENSION_WORK_ORDER = 'workOrder';
    public const DIMENSION_COST_CENTER = 'costCenter';
    public const FORMAT_VERSION = '1.0';

    /** @var array<int, ?string> */
    private array $unitCache = [];

    /**
     * @param ?\Closure(string): ?ConfigRuntime $configForLanguage konfigurace
     *        v jazyce dokumentu (cfgItem periodTexts); null = texty jen
     *        z `$config` (jazyk rozhraní) a anglický fallback
     */
    public function __construct(
        protected readonly Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly string $ownCountry,
        private readonly ?\Closure $configForLanguage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $workOrder hlavička zakázky
     * @param array<string, mixed>|null $kind druh zakázky
     * @param list<array<string, mixed>> $rows všechny řádky předpisu (order_pos ASC)
     * @throws InvoiceBuildException
     */
    public function build(array $workOrder, ?array $kind, Period $period, array $rows): BuiltInvoice
    {
        $settings = InvoicingSettingsResolver::resolve($workOrder, $kind);
        if ($settings->docType === null) {
            throw new InvoiceBuildException(InvoiceBuildException::NO_DOC_TYPE, 'Zakázka ani její druh nemají typ dokladu.');
        }
        if ($settings->numberSeries === null) {
            throw new InvoiceBuildException(InvoiceBuildException::NO_SERIES, 'Zakázka ani její druh nemají řadu dokladů.');
        }
        $customerId = (int) ($workOrder['customer'] ?? 0);
        $party = $customerId > 0 ? $this->customerParty($customerId) : null;
        if ($party === null) {
            throw new InvoiceBuildException(InvoiceBuildException::NO_CUSTOMER, 'Zakázka nemá zákazníka, nebo zákazník neexistuje.');
        }

        $billingDate = PeriodCalendar::billingDate($period, $settings->timing);
        $validRows = self::rowsValidAt($rows, $billingDate);
        if ($validRows === []) {
            throw new InvoiceBuildException(
                InvoiceBuildException::NO_ROWS,
                "K datu {$billingDate} nemá zakázka žádný platný řádek předpisu.",
            );
        }

        $language = $this->languageFor($customerId, $party['country'] ?? null);
        $periodicity = (string) ($workOrder['inv_periodicity'] ?? 'month');
        $periodLabel = PeriodLabel::format($period, $periodicity, $language, $this->periodTexts($language));
        $docText = DocTextTemplate::render($workOrder['inv_doc_text'] ?? null, (string) ($workOrder['title'] ?? ''), $periodLabel);
        $dueDate = (new \DateTimeImmutable($billingDate))->modify('+' . $settings->dueDays . ' days')->format('Y-m-d');

        $canonicalRows = [];
        $rowResolve = [];
        foreach ($validRows as $i => $row) {
            $canonicalRows[] = $this->canonicalRow($row, $i + 1, $settings->vatMode !== 0);
            $itemId = (int) ($row['item'] ?? 0);
            if ($itemId > 0) {
                $rowResolve[] = ['index' => $i, 'item' => ['userAction' => "useExisting:{$itemId}"]];
            }
        }

        $payment = ['method' => DocumentApplier::canonicalPaymentMethod($settings->paymentMethod)];
        $paymentReference = trim((string) ($workOrder['payment_reference'] ?? ''));
        if ($paymentReference !== '') {
            $payment['paymentReference'] = $paymentReference;
        }

        $dimensions = [];
        $number = trim((string) ($workOrder['number'] ?? ''));
        if ($number !== '') {
            $dimensions[self::DIMENSION_WORK_ORDER] = $number;
        }
        $costCenterId = (int) ($workOrder['cost_center'] ?? 0);
        $costCenterCode = $costCenterId > 0 ? $this->costCenterCode($costCenterId) : null;
        if ($costCenterCode !== null) {
            $dimensions[self::DIMENSION_COST_CENTER] = $costCenterCode;
        }

        $applyOptions = [
            'targetDocState' => 10,
            'numberSeriesId' => $settings->numberSeries,
        ];
        $bankAccount = $settings->bankAccount ?? $this->defaultBankAccount();
        if ($bankAccount !== null) {
            $applyOptions['importOwnBankAccount'] = $bankAccount;
        }

        $currency = strtoupper(trim((string) ($workOrder['currency'] ?? '')));

        $canonical = [
            'format'        => DocumentApplier::FORMAT_ID,
            'formatVersion' => self::FORMAT_VERSION,
            'docType'       => DocumentApplier::canonicalDocType($settings->docType),
            'docText'       => $docText,
            'selfParty'     => 'supplier',
            'customer'      => $party,
            'dates'         => [
                'issueDate'      => $billingDate,
                'accountingDate' => $billingDate,
                'taxPointDate'   => $billingDate,
                'dueDate'        => $dueDate,
                'periodFrom'     => $period->from,
                'periodTo'       => $period->to,
            ],
            'vat'           => ['mode' => DocumentApplier::canonicalVatMode($settings->vatMode)],
            'payment'       => $payment,
            'source'        => ['kind' => self::SOURCE_KIND],
            'rows'          => $canonicalRows,
            '_resolve'      => [
                'customer' => ['userAction' => "useExisting:{$customerId}"],
                'rows'     => $rowResolve,
            ],
            'applyOptions'  => $applyOptions,
        ];
        if ($currency !== '') {
            $canonical['currency'] = $currency;
        }
        if ($dimensions !== []) {
            $canonical['dimensions'] = $dimensions;
        }

        return new BuiltInvoice($canonical, $language, $billingDate, $periodLabel, $validRows);
    }

    /**
     * Řádky předpisu platné k datu (DUZP): `valid_from ≤ datum ≤ valid_to`,
     * NULL = neomezeno. Pořadí zůstává (order_pos).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function rowsValidAt(array $rows, string $date): array
    {
        $out = [];
        foreach ($rows as $row) {
            $from = self::isoDate($row['valid_from'] ?? null);
            $to = self::isoDate($row['valid_to'] ?? null);
            if (($from === null || $from <= $date) && ($to === null || $to >= $date)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function canonicalRow(array $row, int $orderPos, bool $withVat): array
    {
        $contributor = trim((string) ($row['contributor'] ?? ''));
        $out = [
            'rowKind'       => 'item',
            'orderPos'      => $orderPos,
            'description'   => (string) ($row['description'] ?? ''),
            // Řádek přispěvatele vzniká s množstvím 0 — doplní ho běh (D10).
            'quantity'      => $contributor !== '' ? 0.0 : (float) ($row['quantity'] ?? 0),
            'unitPrice'     => (float) ($row['unit_price'] ?? 0),
            'priceCalcMode' => 'fromUnitPrice',
        ];
        $operation = trim((string) ($row['operation'] ?? ''));
        if ($operation !== '') {
            $out['operation'] = $operation;
        }
        $unit = $this->unitShortcut(isset($row['unit']) ? (int) $row['unit'] : null);
        if ($unit !== null) {
            $out['unit'] = $unit;
        }
        $itemId = (int) ($row['item'] ?? 0);
        if ($itemId > 0) {
            $code = $this->itemCode($itemId);
            $out['item'] = ['ourCode' => $code, 'name' => $out['description'] !== '' ? $out['description'] : null];
        }
        $vatCode = trim((string) ($row['vat_code'] ?? ''));
        if ($withVat && $vatCode !== '') {
            $out['vat'] = ['code' => $vatCode];
        }
        return $out;
    }

    private function languageFor(int $customerId, ?string $partyCountry): string
    {
        return DocumentLanguageResolver::fromConfig($this->config, $this->ownCountry)
            ->resolve($this->personLanguage($customerId), $partyCountry);
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** @return array<string, mixed>|null */
    protected function customerParty(int $personId): ?array
    {
        return CanonicalParty::fromPerson($this->db, $personId);
    }

    protected function personLanguage(int $personId): ?string
    {
        $value = $this->db->fetchSingle('SELECT [language] FROM [base_persons_persons] WHERE [id] = %i', $personId);
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function costCenterCode(int $costCenterId): ?string
    {
        $value = $this->db->fetchSingle('SELECT [code] FROM [economy_codebooks_cost_centers] WHERE [id] = %i', $costCenterId);
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function itemCode(int $itemId): ?string
    {
        $value = $this->db->fetchSingle('SELECT [code] FROM [economy_items] WHERE [id] = %i', $itemId);
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function unitShortcut(?int $unitId): ?string
    {
        if ($unitId === null || $unitId <= 0) {
            return null;
        }
        if (!array_key_exists($unitId, $this->unitCache)) {
            $row = $this->db->fetch('SELECT [shortcut], [name] FROM [core_units] WHERE [id] = %i', $unitId);
            $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
            $shortcut = trim((string) ($row['shortcut'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $this->unitCache[$unitId] = $shortcut !== '' ? $shortcut : ($name !== '' ? $name : null);
        }
        return $this->unitCache[$unitId];
    }

    protected function defaultBankAccount(): ?int
    {
        return DefaultBankAccountResolver::resolve($this->db);
    }

    /**
     * cfgItem periodTexts v jazyce dokumentu; bez konfigurace pro jazyk
     * konfigurace rozhraní, bez ní null (anglický fallback PeriodLabel).
     *
     * @return array<string, mixed>|null
     */
    protected function periodTexts(string $language): ?array
    {
        $config = $this->configForLanguage !== null ? ($this->configForLanguage)($language) : null;
        $texts = ($config ?? $this->config)?->cfgItem(PeriodLabel::CFG_ITEM);
        return is_array($texts) ? $texts : null;
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
