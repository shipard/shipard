<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Dynamická nabídka pole `select` settings stránky (`optionsProvider`
 * v definici pole, FQCN) — pro číselníky z dat zdroje (řady dokladů…),
 * které nejdou vypsat do `options` v module.jsonc.
 *
 * `SettingsController` nabídku pošle klientovi a při uložení přijme jen
 * hodnotu z ní. Třída se instanciuje bez argumentů.
 */
interface SettingsOptionsProvider
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(DataSourceConnection $db, string $language): array;
}
