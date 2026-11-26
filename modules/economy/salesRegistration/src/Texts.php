<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\CatalogText;

/**
 * Texty doplňku mimo viewer a formulář — akce záznamu, hlášky, upozornění.
 * Katalog `economy.salesRegistration.texts` (sekce `texts`) je lokalizovaný
 * kompilací konfigurace; bez něj (nezkompilovaná konfigurace) platí anglický
 * fallback, který se s holým `name` katalogu shoduje znak po znaku.
 */
final class Texts
{
    public const string CFG_ITEM = 'economy.salesRegistration.texts';

    private function __construct(private readonly mixed $defs)
    {
    }

    public static function of(?ConfigRuntime $config): self
    {
        $catalog = $config?->cfgItem(self::CFG_ITEM);
        return new self(is_array($catalog) ? ($catalog['texts'] ?? null) : null);
    }

    /** @param array<string, scalar> $params */
    public function t(string $key, string $englishFallback, array $params = []): string
    {
        return CatalogText::resolve($this->defs, $key, $englishFallback, $params);
    }
}
