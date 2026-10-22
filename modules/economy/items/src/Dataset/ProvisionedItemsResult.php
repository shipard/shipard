<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items\Dataset;

use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;

/**
 * Výsledek {@see ProvisionedItemsDetector}: které položky zdroje dat jsou
 * nezměněné položky z nabídky účetních položek osnovy a jak je má dump
 * zapsat do manifestu (`provision.accountingItems`, #131 krok 0c).
 *
 * `itemIds` dump vyloučí ze sekce `items/`; `accountingItems` je `"all"`,
 * seznam kódů nabídky v pořadí souboru nabídky, nebo null = manifest bez
 * `provision` (nic nezměněného se nenašlo).
 */
final class ProvisionedItemsResult
{
    /**
     * @param list<int> $itemIds id položek `economy_items`, které manifest pokryje
     * @param list<string>|'all'|null $accountingItems hodnota `provision.accountingItems`
     */
    public function __construct(
        public readonly array $itemIds,
        public readonly array|string|null $accountingItems,
    ) {}

    /** Nic k vyloučení — zdroj dat bez nabídky, bez varianty osnovy nebo bez položek z nabídky. */
    public static function none(): self
    {
        return new self([], null);
    }

    public function isEmpty(): bool
    {
        return $this->itemIds === [];
    }

    public function isAll(): bool
    {
        return $this->accountingItems === DatasetManifest::PROVISION_ALL;
    }

    /**
     * Objekt `provision` pro {@see DatasetManifest}: prázdný = manifest bez klíče.
     *
     * @return array<string, mixed>
     */
    public function manifestProvision(): array
    {
        return $this->accountingItems === null ? [] : ['accountingItems' => $this->accountingItems];
    }
}
