<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Stavba případů matice round-tripu nad živým zdrojem dat (U4, U7):
 * partneři, položky a číselníky s prefixem `IT-RT`, kódy DPH a sazby
 * z konfigurace zdroje, doklad A přes `TableGateway` stejnou cestou jako
 * formulář (Koncept → V pořádku) a úklid.
 *
 * Co zdroj dat nemá (řada typu, pokladna, registrace DPH, dimenze), hlásí
 * {@see FixtureUnavailable} — test z ní udělá přeskočení případu.
 *
 * Úklid ({@see purge()}) jde podle prefixu, ne podle seznamu id: smete
 * i zbytky po spadlém běhu a partnery či položky, které by apply dokladu B
 * založilo místo dohledání. Čítače řad vrací {@see restoreCounters()} —
 * doklad A bere číslo z řady jako formulář, po úklidu nesmí zůstat díra.
 */
final class RoundTripFixtures
{
    public const PREFIX = 'IT-RT';

    /** Datum dokladů — fiskální rok i sazby DPH k němu musí na zdroji existovat. */
    public const DATE = '2026-06-10';

    /** Ukázkový tuzemský účet a jeho IBAN (vzor z dokumentace formátu IBAN). */
    public const SUPPLIER_ACCOUNT = '19-2000145399/0800';
    public const SUPPLIER_IBAN = 'CZ6508000000192000145399';
    public const SUPPLIER_BIC = 'GIBACZPX';

    /** Fixní osoby: klíč → [person_id, název, IČO, DIČ, země, město]. */
    private const PERSONS = [
        'customer'   => ['IT-RT-P1', 'Odběratel s.r.o.', '88100014', 'CZ88100014', 'cz', 'Brno'],
        'supplier'   => ['IT-RT-P2', 'Dodavatel s.r.o.', '88200019', 'CZ88200019', 'cz', 'Olomouc'],
        'nonPayer'   => ['IT-RT-P3', 'Neplátce s.r.o.', '88300013', '', 'cz', 'Zlín'],
        'euSupplier' => ['IT-RT-P4', 'Lieferant GmbH', '', 'DE811234567', 'de', 'Berlin'],
    ];

    private readonly VatRateResolver $vatRates;

    /** @var array<string, int> klíč osoby → id */
    private array $persons = [];
    /** @var array<string, int> kód položky → id */
    private array $items = [];
    /** @var array<int, true> řady, ze kterých doklad A bral číslo */
    private array $usedSeries = [];
    /** @var list<array<string, mixed>> */
    private array $counterSnapshot = [];
    /** @var array<string, array<int, string>> tabulka → id → označení pro popis rozdílu */
    private array $labels = [];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly TableGateway $heads,
        private readonly ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
    ) {
        $this->vatRates = new VatRateResolver($config);
    }

    // ── předpoklady zdroje dat ──────────────────────────────────────────────

    public function country(): string
    {
        return $this->dsConfig->getCountry();
    }

    public function homeCurrency(): string
    {
        $raw = $this->db->fetchSingle('SELECT [value] FROM [core_system_settings] WHERE [key] = %s', 'economy.homeCurrency');
        $value = is_string($raw) ? json_decode($raw, true) : null;
        return is_string($value) && $value !== '' ? strtolower($value) : 'czk';
    }

    public function vatRegistrationId(): int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_codebooks_vat_registrations] WHERE [country] = %s AND [docState] = 40 ORDER BY [id] LIMIT 1',
            $this->country(),
        );
        return (int) ($id ?? throw new FixtureUnavailable('zdroj dat nemá registraci DPH'));
    }

    public function seriesId(string $docType, ?int $cashDeskId = null): int
    {
        $id = $cashDeskId !== null
            ? $this->db->fetchSingle(
                'SELECT [id] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [cash_desk] = %i AND [docState] = 40 ORDER BY [id] LIMIT 1',
                $docType, $cashDeskId,
            )
            : $this->db->fetchSingle(
                'SELECT [id] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [docState] = 40 ORDER BY [id] LIMIT 1',
                $docType,
            );
        $id = (int) ($id ?? throw new FixtureUnavailable("zdroj dat nemá aktivní řadu typu {$docType}"));
        $this->usedSeries[$id] = true;
        return $id;
    }

    public function ownBankAccountId(): int
    {
        $id = $this->db->fetchSingle('SELECT [id] FROM [economy_codebooks_bank_accounts] WHERE [docState] = 40 ORDER BY [is_default] DESC, [id] LIMIT 1');
        return (int) ($id ?? throw new FixtureUnavailable('zdroj dat nemá vlastní bankovní účet'));
    }

    public function cashDeskId(): int
    {
        $id = $this->db->fetchSingle('SELECT [id] FROM [economy_codebooks_cash_desks] WHERE [docState] = 40 ORDER BY [is_default] DESC, [id] LIMIT 1');
        return (int) ($id ?? throw new FixtureUnavailable('zdroj dat nemá pokladnu'));
    }

    public function accountId(string $numberPrefix): int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_accounting_accounts] WHERE [number] LIKE %like~ AND [account_level] = 4 AND [docState] = 40 ORDER BY [number] LIMIT 1',
            $numberPrefix,
        );
        return (int) ($id ?? throw new FixtureUnavailable("osnova zdroje dat nemá účet {$numberPrefix}…"));
    }

    // ── kódy DPH z konfigurace zdroje ───────────────────────────────────────

    /**
     * První viditelný kód DPH daných vlastností, který má k datu sazbu.
     *
     * @param array{reverseCharge?: bool, supplyKind?: string} $traits
     */
    public function vatCode(string $direction, string $place, string $category, array $traits = [], string $date = self::DATE): string
    {
        $wantReverse = $traits['reverseCharge'] ?? false;
        foreach ($this->vatRates->getVatCodes($this->country(), $direction, $place) as $code => $def) {
            if (($def['category'] ?? null) !== $category || !empty($def['reverseCharge']) !== $wantReverse) {
                continue;
            }
            if (isset($traits['supplyKind']) && ($def['supplyKind'] ?? null) !== $traits['supplyKind']) {
                continue;
            }
            if ($this->rateAt((string) $code, $date) !== null) {
                return (string) $code;
            }
        }
        throw new FixtureUnavailable("konfigurace DPH nemá kód {$direction}/{$place}/{$category} se sazbou k {$date}");
    }

    /** Kód kategorie „Mimo DPH“. */
    public function outOfScopeCode(): string
    {
        foreach ($this->vatRates->getVatCodes($this->country()) as $code => $def) {
            if (VatRateResolver::isOutOfScopeDefinition($def)) {
                return (string) $code;
            }
        }
        throw new FixtureUnavailable('konfigurace DPH nemá kód „Mimo DPH“');
    }

    public function rateAt(string $code, string $date): ?float
    {
        try {
            return $this->vatRates->resolveVatPct($this->country(), $code, $date);
        } catch (\LogicException) {
            return null;
        }
    }

    /**
     * Datum, ke kterému má kód jinou sazbu než k {@see DATE}; když takové
     * není, den před {@see DATE} (stejná sazba, jiné datum).
     */
    public function otherRateDate(string $code): string
    {
        $current = $this->rateAt($code, self::DATE);
        $cfg = $this->config->cfgItem('world.vat.' . $this->country());
        foreach (is_array($cfg) ? ($cfg['vatPercents'] ?? []) : [] as $entry) {
            $from = (string) ($entry['from'] ?? '');
            if (($entry['code'] ?? null) !== $code || $from === '' || $from === '0000-00-00') {
                continue;
            }
            $rate = $this->rateAt($code, $from);
            if ($rate !== null && $rate !== $current) {
                return $from;
            }
        }
        return (new \DateTimeImmutable(self::DATE))->modify('-1 day')->format('Y-m-d');
    }

    /** Předmět plnění tuzemského přenesení platný k datu. */
    public function reverseChargeSubject(string $date = self::DATE): string
    {
        $subjects = $this->vatRates->getReverseChargeSubjectsValidAt($this->country(), $date);
        $code = $subjects[0]['code'] ?? throw new FixtureUnavailable("konfigurace DPH nemá předmět plnění přenesení k {$date}");
        return (string) $code;
    }

    // ── osoby, položky, číselníky ───────────────────────────────────────────

    public function person(string $key): int
    {
        if (isset($this->persons[$key])) {
            return $this->persons[$key];
        }
        [$personId, $name, $companyId, $vatId, $country, $city] = self::PERSONS[$key];
        $id = $this->db->insertRow('base_persons_persons', [
            'person_id'    => $personId,
            'person_type'  => 2,
            'full_name'    => self::PREFIX . ' ' . $name,
            'last_name'    => self::PREFIX . ' ' . $name,
            'first_name'   => '',
            'company_id'   => $companyId,
            'tax_id'       => $vatId,
            'vat_id'       => $vatId,
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $addressId = $this->db->insertRow('base_persons_addresses', [
            'person'       => $id,
            'address_type' => 1,
            'street'       => self::PREFIX . ' ulice',
            'house_number' => '12',
            'city'         => $city,
            'zip'          => '60200',
            'country'      => $country,
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $this->labels['base_persons_persons'][$id] = "@{$key}";
        $this->labels['base_persons_addresses'][$addressId] = "@{$key}.address";
        return $this->persons[$key] = $id;
    }

    /**
     * Bankovní účet tuzemského dodavatele: [id účtu v adresáři, číslo, IBAN, BIC].
     *
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    public function supplierBank(): array
    {
        $person = $this->person('supplier');
        $id = $this->db->fetchSingle('SELECT [id] FROM [base_persons_bank_accounts] WHERE [person] = %i LIMIT 1', $person);
        if ($id === null) {
            $id = $this->db->insertRow('base_persons_bank_accounts', [
                'person'         => $person,
                'account_number' => self::SUPPLIER_ACCOUNT,
                'iban'           => self::SUPPLIER_IBAN,
                'bic'            => self::SUPPLIER_BIC,
                'currency'       => $this->homeCurrency(),
                'docState'       => 40,
                'docStateMain'   => 3,
            ]);
            $this->labels['base_persons_bank_accounts'][(int) $id] = '@supplier.bank';
        }
        return [(int) $id, self::SUPPLIER_ACCOUNT, self::SUPPLIER_IBAN, self::SUPPLIER_BIC];
    }

    /** Položka služby s účtem z osnovy; `$sale` = prodejní (výnos), jinak nákupní (náklad). */
    public function item(bool $sale): int
    {
        $code = self::PREFIX . ($sale ? '-PRODEJ' : '-NAKUP');
        if (isset($this->items[$code])) {
            return $this->items[$code];
        }
        $unit = $this->db->fetchSingle('SELECT [id] FROM [core_units] WHERE [system_code] = %s LIMIT 1', 'pcs');
        $id = $this->db->insertRow('economy_items', [
            'code'               => $code,
            'name'               => self::PREFIX . ($sale ? ' prodej služeb' : ' nákup služeb'),
            'item_kind'          => 3,
            'item_type'          => 2,
            'unit'               => $unit,
            'accounting_account' => $this->accountId($sale ? '602' : '518'),
            'docState'           => 40,
            'docStateMain'       => 3,
        ]);
        $this->labels['economy_items'][$id] = '@item.' . ($sale ? 'sale' : 'purchase');
        return $this->items[$code] = $id;
    }

    public function unitId(): ?int
    {
        $id = $this->db->fetchSingle('SELECT [id] FROM [core_units] WHERE [system_code] = %s LIMIT 1', 'pcs');
        return $id !== null ? (int) $id : null;
    }

    /** Středisko `IT-RT` — jen když zdroj dat dimenzi středisek deklaruje. */
    public function costCenter(): int
    {
        $dimension = JournalDimensionSet::fromConfig($this->config)->get('costCenter');
        if ($dimension === null || $dimension->exchangeKey === null || $dimension->headColumn === null) {
            throw new FixtureUnavailable('zdroj dat nemá dimenzi středisko');
        }
        $id = $this->db->fetchSingle('SELECT [id] FROM [economy_codebooks_cost_centers] WHERE [code] = %s LIMIT 1', self::PREFIX);
        if ($id === null) {
            $id = $this->db->insertRow('economy_codebooks_cost_centers', [
                'code'         => self::PREFIX,
                'name'         => self::PREFIX . ' středisko',
                'sort_order'   => 0,
                'docState'     => 40,
                'docStateMain' => 3,
            ]);
            $this->labels['economy_codebooks_cost_centers'][(int) $id] = '@costCenter';
        }
        return (int) $id;
    }

    // ── stavba dat dokladu ──────────────────────────────────────────────────

    /**
     * Hlavička faktury vydané s DPH; `$overrides` přepisují.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function issuedHead(string $text, array $overrides = []): array
    {
        return $overrides + [
            'doc_type'         => 'invno',
            'number_series'    => $this->seriesId('invno'),
            'partner'          => $this->person('customer'),
            'bank_account'     => $this->ownBankAccountId(),
            'vat_registration' => $this->vatRegistrationId(),
            'vat_mode'         => 1,
            'doc_currency'     => $this->homeCurrency(),
            'payment_method'   => 1,
            'issue_date'       => self::DATE,
            'accounting_date'  => self::DATE,
            'due_date'         => '2026-06-24',
            'doc_text'         => self::PREFIX . ' ' . $text,
            'doc_notice'       => self::PREFIX . ' poznámka na dokladu',
            'notice'           => self::PREFIX . ' interní poznámka',
        ];
    }

    /**
     * Hlavička faktury přijaté s DPH od tuzemského plátce vč. bankovního
     * spojení dodavatele; `$overrides` přepisují.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function receivedHead(string $text, array $overrides = []): array
    {
        $head = $overrides + [
            'doc_type'           => 'invni',
            'number_series'      => $this->seriesId('invni'),
            'partner'            => $this->person('supplier'),
            'partner_doc_number' => self::PREFIX . '-FP-2026-077',
            'bank_account'       => $this->ownBankAccountId(),
            'vat_registration'   => $this->vatRegistrationId(),
            'vat_mode'           => 1,
            'doc_currency'       => $this->homeCurrency(),
            'payment_method'     => 1,
            'issue_date'         => self::DATE,
            'accounting_date'    => self::DATE,
            'vat_duzp'           => self::DATE,
            'due_date'           => '2026-06-24',
            'payment_reference'  => '2026077',
            'doc_text'           => self::PREFIX . ' ' . $text,
        ];
        if ($head['partner'] === $this->person('supplier')) {
            [$bankId, $account, $iban, $bic] = $this->supplierBank();
            $head += [
                'partner_bank'         => $bankId,
                'partner_bank_account' => $account,
                'partner_bank_iban'    => $iban,
                'partner_bank_bic'     => $bic,
            ];
        }
        return $head;
    }

    /**
     * Položkový řádek služby; bez kódu DPH u dokladu Bez DPH.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function row(bool $sale, string $description, float $quantity, float $unitPrice, ?string $vatCode, array $extra = []): array
    {
        return $extra + array_filter([
            'row_kind'    => 1,
            'operation'   => $sale ? 'sale.services' : 'purchase.services',
            'item'        => $this->item($sale),
            'unit'        => $this->unitId(),
            'description' => self::PREFIX . ' ' . $description,
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'vat_code'    => $vatCode,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Uloží doklad cestou formuláře: nový doklad vzniká v Konceptu, cílový
     * stav 40 je druhé uložení (potvrzení, číslo z řady).
     *
     * @param array<string, mixed> $data hlavička + `rows` (+ `vatRecap`), bez stavu
     */
    public function saveDocument(array $data, int $docState = 40): int
    {
        // Sub-formulář čísluje řádky od 1 (DocRowsDocument); řádky poslané
        // s hlavičkou si pořadí nesou samy — bez něj by všechny měly 0.
        foreach (array_values($data['rows'] ?? []) as $i => $row) {
            $data['rows'][$i] = $row + ['order_pos' => $i + 1];
        }
        $create = $this->heads->saveDocument($data + ['docState' => 10]);
        self::assertSaved($create, 'založení konceptu');
        $id = (int) $create->getData()['id'];
        if ($docState === 10) {
            return $id;
        }
        $doc = $this->heads->loadDocument($id);
        if ($doc === null) {
            throw new \RuntimeException("Doklad #{$id} po založení nejde načíst.");
        }
        $doc['docState'] = $docState;
        self::assertSaved($this->heads->saveDocument($doc), "přechod do stavu {$docState}");
        return $id;
    }

    private static function assertSaved(DocumentResult $result, string $step): void
    {
        if ($result->isSuccess()) {
            return;
        }
        $errors = array_map(
            static fn ($e): string => ($e->column !== '' ? $e->column . ': ' : '') . $e->message . ' [' . $e->code . ']',
            $result->getValidation()?->getErrors() ?? [],
        );
        throw new \RuntimeException(
            "Doklad A se nepodařilo uložit ({$step}): " . ($result->getErrorMessage() ?? implode('; ', $errors)),
        );
    }

    /** Stálé označení záznamu fixtury pro popis rozdílu; cizí záznam `tabulka:?`. */
    public function referenceLabel(string $table, int $id): string
    {
        return $this->labels[$table][$id] ?? "{$table}:?";
    }

    public function labelDocument(int $id, string $label): void
    {
        $this->labels['docs_core_heads'][$id] = $label;
    }

    // ── úklid ───────────────────────────────────────────────────────────────

    /** Stav čítačů řad před testem; volat před první stavbou dokladu. */
    public function snapshotCounters(): void
    {
        $this->counterSnapshot = $this->db->fetchAll('SELECT * FROM [docs_core_number_counters]');
    }

    /** Vrátí čítače řad, ze kterých test bral číslo, do stavu ze snapshotu. */
    public function restoreCounters(): void
    {
        foreach (array_keys($this->usedSeries) as $seriesId) {
            $this->db->execute('DELETE FROM [docs_core_number_counters] WHERE [number_series] = %i', $seriesId);
            foreach ($this->counterSnapshot as $row) {
                if ((int) $row['number_series'] === $seriesId) {
                    $this->db->insertRow('docs_core_number_counters', $row);
                }
            }
        }
        $this->usedSeries = [];
    }

    /**
     * Smaže vše s prefixem `IT-RT`: doklady (vč. deníku a saldokonta),
     * osoby, položky, středisko. Jen na zdroji s třídou dat `free`.
     */
    public function purge(): void
    {
        if (!$this->dsConfig->isFree()) {
            throw new \RuntimeException('Úklid matice round-tripu smí běžet jen nad zdrojem dat s třídou `free`.');
        }
        $like = self::PREFIX . '%';
        $personIds = array_map('intval', array_column($this->db->fetchAll(
            'SELECT [id] FROM [base_persons_persons] WHERE [person_id] LIKE %s OR [full_name] LIKE %s',
            $like, $like,
        ), 'id'));
        $itemIds = array_map('intval', array_column($this->db->fetchAll(
            'SELECT [id] FROM [economy_items] WHERE [code] LIKE %s OR [name] LIKE %s',
            $like, $like,
        ), 'id'));

        $docIds = array_map('intval', array_column($this->db->fetchAll(
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_text] LIKE %s OR [doc_number] LIKE %s'
            . ($personIds !== [] ? ' OR [partner] IN %in' : ''),
            $like, $like, ...($personIds !== [] ? [$personIds] : []),
        ), 'id'));
        if ($docIds !== []) {
            foreach (['economy_accbal_ledger', 'economy_accounting_journal', 'docs_core_vat_recap', 'docs_core_rows'] as $table) {
                if ($this->tableExists($table)) {
                    $this->db->execute("DELETE FROM [{$table}] WHERE [doc_head] IN %in", $docIds);
                }
            }
            $this->db->execute('DELETE FROM [docs_core_heads] WHERE [id] IN %in', $docIds);
        }

        if ($itemIds !== []) {
            $this->db->execute('DELETE FROM [economy_items_supplier_codes] WHERE [item] IN %in', $itemIds);
            $this->db->execute('DELETE FROM [economy_items] WHERE [id] IN %in', $itemIds);
        }
        if ($personIds !== []) {
            foreach ([
                'economy_items_supplier_codes' => 'person',
                'base_persons_addresses'       => 'person',
                'base_persons_bank_accounts'   => 'person',
                'base_persons_contacts'        => 'person',
            ] as $table => $column) {
                if ($this->tableExists($table)) {
                    $this->db->execute("DELETE FROM [{$table}] WHERE [{$column}] IN %in", $personIds);
                }
            }
            $this->db->execute('DELETE FROM [base_persons_persons] WHERE [id] IN %in', $personIds);
        }
        if ($this->tableExists('economy_codebooks_cost_centers')) {
            $this->db->execute('DELETE FROM [economy_codebooks_cost_centers] WHERE [code] LIKE %s', $like);
        }

        $this->persons = [];
        $this->items = [];
        $this->labels = [];
    }

    private function tableExists(string $table): bool
    {
        return in_array($table, $this->db->getAllTableNames(), true);
    }
}
