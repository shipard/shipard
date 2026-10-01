<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

/**
 * Jeden řádek účetního dokladu majetku: operace `asset.*`, karta, účet,
 * strana (0 = MD, 1 = DAL) a částka. `eventId` je událost, ze které řádek
 * vznikl; `part` rozlišuje dvojice zápisů téže události (vyřazení).
 */
final readonly class AssetPostingRow
{
    public const SIDE_DEBIT = 0;
    public const SIDE_CREDIT = 1;

    public function __construct(
        public string $operation,
        public int $assetId,
        public string $assetNumber,
        public int $account,
        public int $side,
        public float $amount,
        public string $description,
        public int $eventId,
        public string $eventDate,
        public int $part = 0,
    ) {
    }

    /**
     * Řádek `docs_core_rows` (bez `order_pos` — pořadí dává volající).
     *
     * @return array<string, mixed>
     */
    public function toDocRow(): array
    {
        return [
            'row_kind'        => 1,
            'operation'       => $this->operation,
            'asset'           => $this->assetId,
            'account'         => $this->account,
            'acc_side'        => $this->side,
            'total_price'     => $this->amount,
            'price_calc_mode' => 1,
            'description'     => mb_substr($this->description, 0, 500),
        ];
    }
}
