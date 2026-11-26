<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Source;

/**
 * Zdroj periodické fakturace (#175 D1–D5): evidence, jejíž záznamy nesou
 * fakturační předpis a engine za ně vystavuje koncepty dokladů za období.
 * Registrace v `module.jsonc` → `periodicInvoicingSources: [{id, class}]`,
 * `ConfigCompiler` je skládá do cfgItem
 * `economy.periodicInvoicing.sources`; instance staví
 * {@see InvoicingSources}. Třída vzniká bez argumentů konstruktoru.
 *
 * **Normalizovaný záznam** (U1) je pole s klíči, které engine čte:
 * `id`, `number`, `title`, `customer`, `currency`, `payment_reference`,
 * `date_end`, `inv_periodicity`, `inv_from`, `inv_doc_text` a přepisy
 * předpisu `inv_doc_type`, `inv_number_series`, `inv_due_days`,
 * `inv_timing`, `inv_vat_mode`, `inv_payment_method`, `inv_bank_account`.
 * Řádek druhu nese tytéž sloupce `inv_*` (D4).
 *
 * **Řádek předpisu** (U2): `id`, `order_pos`, `item`, `description`,
 * `quantity`, `unit` (id jednotky), `unit_price`, `vat_code`, `operation`,
 * `valid_from`, `valid_to`, `contributor`.
 *
 * Evidence období je per zdroj (D1): vlastní tabulka tvaru
 * `economy_work_orders_periods` se sloupcem záznamu
 * `periodsRecordColumn()`. Id období je jednoznačné jen v tabulce zdroje.
 */
interface PeriodicInvoicingSource
{
    /** Id z registrace (`periodicInvoicingSources[].id`). */
    public function id(): string;

    /** Tabulka záznamů zdroje (nad ní zdroj deklaruje akce záznamu). */
    public function table(): string;

    /** Tabulka evidence období zdroje. */
    public function periodsTable(): string;

    /** Sloupec záznamu v tabulce období. */
    public function periodsRecordColumn(): string;

    /** Id vieweru, který záznam otevírá (akce upozornění). */
    public function viewerId(): string;

    /** Sekce navigace vieweru zdroje — badge upozornění; null = bez badge. */
    public function navSection(): ?string;

    /**
     * Záznamy k fakturaci (U1), podle id vzestupně; `$recordId` omezí
     * výběr na jeden záznam — záznam mimo kandidáty výběr vynechá.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(?int $recordId): array;

    /** @return array<string, mixed>|null normalizovaný záznam (U1) */
    public function record(int $id): ?array;

    public function recordIdByNumber(string $number): ?int;

    /**
     * Řádek druhu záznamu se sloupci `inv_*` (D4); null = druh neznámý.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    public function kind(array $record): ?array;

    /**
     * Řádky předpisu (U2), `order_pos` vzestupně.
     *
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    public function rows(array $record): array;

    /**
     * Dimenze dokladu referencí id (id dimenze → id záznamu).
     *
     * @param array<string, mixed> $record
     * @return array<string, int>
     */
    public function dimensions(array $record): array;

    /**
     * Číslo a název záznamu pro výpis běhu a upozornění.
     *
     * @param array<string, mixed> $record
     */
    public function recordLabel(array $record): string;

    /**
     * Pojmenování záznamu v jazyce konfigurace zdroje, s velkým písmenem
     * („Zakázka“) — začátek titulku upozornění.
     */
    public function recordNoun(): string;

    /** Popisek akce upozornění, která záznam otevře („Otevřít zakázku“). */
    public function openActionLabel(): string;
}
