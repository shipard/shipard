<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Source;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Registr zdrojů periodické fakturace (#175 D3): id → instance. Staví se
 * z cfgItem `economy.periodicInvoicing.sources`, který ConfigCompiler
 * skládá z registrací `periodicInvoicingSources` aktivních modulů.
 * Neexistující třída nebo třída bez rozhraní = chyba konfigurace modulů
 * (LogicException). Bez registrace prázdný registr — běh nemá co dělat.
 */
final class InvoicingSources
{
    public const CFG_ITEM = 'economy.periodicInvoicing.sources';

    /** @var array<string, PeriodicInvoicingSource> id → instance */
    private readonly array $sources;

    /**
     * @param iterable<PeriodicInvoicingSource> $sources
     */
    public function __construct(iterable $sources = [])
    {
        $byId = [];
        foreach ($sources as $source) {
            $id = $source->id();
            if (isset($byId[$id])) {
                throw new \LogicException("Periodic invoicing source '{$id}' is registered twice");
            }
            $byId[$id] = $source;
        }
        $this->sources = $byId;
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
        $sources = [];
        foreach ($declared as $id => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? null) : null;
            if (!is_string($class) || $class === '') {
                continue;
            }
            if (!class_exists($class)) {
                throw new \LogicException("periodicInvoicingSources '{$id}': class {$class} not found");
            }
            $source = new $class();
            if (!$source instanceof PeriodicInvoicingSource) {
                throw new \LogicException("Class {$class} does not implement PeriodicInvoicingSource");
            }
            if ($source->id() !== (string) $id) {
                throw new \LogicException("periodicInvoicingSources '{$id}': class {$class} reports id '{$source->id()}'");
            }
            if ($source instanceof AbstractPeriodicInvoicingSource) {
                if ($db !== null) {
                    $source->setDb($db);
                }
                if ($config !== null) {
                    $source->setConfig($config);
                }
                if ($dsConfig !== null) {
                    $source->setDsConfig($dsConfig);
                }
            }
            $sources[] = $source;
        }
        return new self($sources);
    }

    public function get(string $id): ?PeriodicInvoicingSource
    {
        return $this->sources[$id] ?? null;
    }

    /** Zdroj nad tabulkou záznamů (akce záznamu); null = tabulka zdroj nemá. */
    public function forTable(string $table): ?PeriodicInvoicingSource
    {
        foreach ($this->sources as $source) {
            if ($source->table() === $table) {
                return $source;
            }
        }
        return null;
    }

    /** @return list<PeriodicInvoicingSource> v pořadí registrace */
    public function all(): array
    {
        return array_values($this->sources);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }
}
