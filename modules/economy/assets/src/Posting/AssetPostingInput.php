<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Module\Economy\Assets\Depreciation\Plan;

/**
 * Vstup sestavení řádků dokladu pro jednu kartu ({@see AssetPostingBuilder}).
 */
final readonly class AssetPostingInput
{
    public const ACCOUNT_ASSET = 'asset';
    public const ACCOUNT_ACQUISITION = 'acquisition';
    public const ACCOUNT_ACCUMULATED = 'accumulated';
    public const ACCOUNT_DEPRECIATION = 'depreciation';
    public const ACCOUNT_DISPOSAL = 'disposal';

    /**
     * @param array<string, int|null> $accounts účty účetní skupiny karty
     *        (klíče ACCOUNT_*, hodnota = id účtu rozvrhu, null = nevyplněn)
     * @param list<array<string, mixed>> $events potvrzené nezaúčtované
     *        události účetního okruhu v období (řádky `economy_assets_events`)
     * @param Plan|null $accPlan plán účetního okruhu karty ze všech
     *        potvrzených událostí — potřebuje ho jen vyřazení
     */
    public function __construct(
        public int $assetId,
        public string $assetNumber,
        public string $assetName,
        public array $accounts,
        public array $events,
        public ?Plan $accPlan = null,
    ) {
    }
}
