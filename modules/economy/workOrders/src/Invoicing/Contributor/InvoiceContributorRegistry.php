<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing\Contributor;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Registr přispěvatelů obsahu faktury (D10): id → instance. Staví se
 * z cfgItem `economy.workOrders.invoiceContributors`, který ConfigCompiler
 * skládá z registrací `workOrderInvoiceContributors` aktivních modulů
 * (id, název, třída). Neexistující třída nebo třída bez rozhraní = chyba
 * konfigurace modulů (LogicException). Bez registrace prázdný registr —
 * pole Přispěvatel se ve formuláři řádku neukáže.
 */
final class InvoiceContributorRegistry
{
    public const CFG_ITEM = 'economy.workOrders.invoiceContributors';

    /**
     * @param array<string, InvoiceContributor> $contributors id → instance
     */
    public function __construct(private readonly array $contributors = [])
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function fromConfig(
        ?ConfigRuntime $config,
        ?\Dibi\Connection $db = null,
        ?DataSourceConfig $dsConfig = null,
    ): self {
        $declared = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($declared)) {
            return self::empty();
        }
        $contributors = [];
        foreach ($declared as $id => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? null) : null;
            if (!is_string($class) || $class === '') {
                continue;
            }
            if (!class_exists($class)) {
                throw new \LogicException("workOrderInvoiceContributors '{$id}': class {$class} not found");
            }
            $contributor = new $class();
            if (!$contributor instanceof InvoiceContributor) {
                throw new \LogicException("Class {$class} does not implement InvoiceContributor");
            }
            if ($contributor instanceof AbstractInvoiceContributor) {
                if ($db !== null) {
                    $contributor->setDb($db);
                }
                if ($config !== null) {
                    $contributor->setConfig($config);
                }
                if ($dsConfig !== null) {
                    $contributor->setDsConfig($dsConfig);
                }
            }
            $contributors[(string) $id] = $contributor;
        }
        return new self($contributors);
    }

    /**
     * Nabídka pro formulář řádku: id → lokalizovaný název z cfgItem.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(?ConfigRuntime $config): array
    {
        $declared = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($declared)) {
            return [];
        }
        $options = [];
        foreach ($declared as $id => $entry) {
            $options[] = ['value' => (string) $id, 'label' => is_array($entry) ? (string) ($entry['name'] ?? $id) : (string) $id];
        }
        return $options;
    }

    /** Je id registrované? Bez cfgItem (konfigurace nezkompilovaná) se nehlídá. */
    public static function isKnown(?ConfigRuntime $config, string $id): ?bool
    {
        $declared = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($declared)) {
            return null;
        }
        return isset($declared[$id]);
    }

    public function get(string $id): ?InvoiceContributor
    {
        return $this->contributors[$id] ?? null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->contributors);
    }

    public function isEmpty(): bool
    {
        return $this->contributors === [];
    }
}
