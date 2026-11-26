<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Viewer;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\CatalogText;

/**
 * Texty záložky Fakturace a akcí záznamu z katalogu
 * `economy.periodicInvoicing.viewerLabels` (v jazyce konfigurace) a názvy
 * hodnot číselníků; bez katalogu anglický fallback.
 */
final class InvoicingTexts
{
    public const CFG_ITEM = 'economy.periodicInvoicing.viewerLabels';

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /** @param array<string, scalar> $params */
    public function text(string $key, string $englishFallback, array $params = []): string
    {
        return CatalogText::resolve($this->config?->cfgItem(self::CFG_ITEM), $key, $englishFallback, $params);
    }

    /** Název hodnoty cfgItem tvaru `{klíč: {name}}`, bez konfigurace klíč. */
    public function cfgLabel(string $cfgItemId, string $key): string
    {
        if ($key === '') {
            return '';
        }
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? (string) ($cfg[$key]['name'] ?? $key) : $key;
    }
}
