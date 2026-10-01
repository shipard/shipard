<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Settings\KeyValueStore;

/**
 * Dimenze deníku aktivních modulů v pořadí resolve ({@see JournalDimension}).
 *
 * `ConfigCompiler` je z `journalDimensions` v module.jsonc skládá do
 * cfgItem {@see self::CFG_ITEM}; `fromConfig()` je jediná konstrukce —
 * engine, viewer deníku i formuláře mají konfiguraci, sada se nikam
 * neinjektuje. Chybějící konfigurace nebo DS bez modulu s dimenzí = prázdná
 * sada a chování beze změny. Sada odpovídá aktivním modulům, takže sloupce
 * dimenzí (extensions) v tabulkách vždy existují.
 *
 * @implements \IteratorAggregate<int, JournalDimension>
 */
final class JournalDimensionSet implements \IteratorAggregate, \Countable
{
    public const CFG_ITEM = ConfigCompiler::JOURNAL_DIMENSIONS_ITEM;

    /** @var list<JournalDimension> */
    private readonly array $dimensions;

    /** @param iterable<JournalDimension> $dimensions */
    public function __construct(iterable $dimensions = [])
    {
        $list = [];
        foreach ($dimensions as $dimension) {
            if (!$dimension instanceof JournalDimension) {
                throw new \LogicException(
                    'JournalDimensionSet accepts only JournalDimension instances, '
                    . get_debug_type($dimension) . ' given',
                );
            }
            $list[] = $dimension;
        }
        $this->dimensions = $list;
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $items = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($items)) {
            return self::empty();
        }
        $dimensions = [];
        foreach ($items as $item) {
            // Tvar hlídá kompilace; cokoli jiného (ruční zásah do kompilátu)
            // se přeskočí — dimenze je analytika, účtování na ní nesmí spadnout.
            if (is_array($item) && isset($item['id'], $item['rowColumn'], $item['journalColumn'], $item['table'])) {
                $dimensions[] = JournalDimension::fromArray($item);
            }
        }
        return new self($dimensions);
    }

    public function isEmpty(): bool
    {
        return $this->dimensions === [];
    }

    public function count(): int
    {
        return count($this->dimensions);
    }

    public function get(string $id): ?JournalDimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->id === $id) {
                return $dimension;
            }
        }
        return null;
    }

    /**
     * Dimenze, jejichž pole má formulář dokladu daného typu nabídnout —
     * na hlavičce (`$head`), nebo na řádku. Dimenze s `enabledBySetting`
     * jen se zapnutým nastavením; bez úložiště nastavení se nenabízí.
     *
     * @return list<JournalDimension>
     */
    public function forForm(string $docType, bool $head, ?KeyValueStore $settings): array
    {
        $out = [];
        foreach ($this->dimensions as $dimension) {
            if (!$dimension->isOnForm($docType, $head)) {
                continue;
            }
            if ($dimension->enabledBySetting !== null
                && !self::isSettingOn($settings?->get($dimension->enabledBySetting))
            ) {
                continue;
            }
            $out[] = $dimension;
        }
        return $out;
    }

    /** Settings `select` ukládá řetězec (`yes`); bool a 1 pro ruční zápis přes CLI. */
    public static function isSettingOn(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'yes';
    }

    /**
     * Hodnoty všech dimenzí pro řádek deníku, klíč = sloupec deníku.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $head
     * @return array<string, int|null>
     */
    public function valuesOf(array $row, array $head): array
    {
        $values = [];
        foreach ($this->dimensions as $dimension) {
            $values[$dimension->journalColumn] = $dimension->valueOf($row, $head);
        }
        return $values;
    }

    /** @return \ArrayIterator<int, JournalDimension> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->dimensions);
    }
}
