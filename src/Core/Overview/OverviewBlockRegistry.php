<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Instance bloků Přehledu firmy z cfgItemu `core.overview.blocks`
 * (`overviewBlocks` aktivních modulů složené v ConfigCompiler).
 *
 * Chybějící nebo cizí třída (DS před `ds-upgrade`, modul bez kódu) se
 * zaloguje a přeskočí — obrazovka „jak na tom firma je" kvůli jednomu
 * bloku nepadá na 500 (vzor `NavigationController::collectProviderItems`).
 */
final class OverviewBlockRegistry
{
    public const CFG_ITEM = ConfigCompiler::OVERVIEW_BLOCKS_ITEM;

    private const ORDER_DEFAULT = 1000;

    /** @var list<array{id: string, section: string, order: int, block: OverviewBlock}> */
    private array $entries = [];

    public function add(string $id, string $section, int $order, OverviewBlock $block): void
    {
        $this->entries[] = ['id' => $id, 'section' => $section, 'order' => $order, 'block' => $block];
    }

    /** @return list<array{id: string, section: string, order: int, block: OverviewBlock}> seřazené [order, id] */
    public function entries(): array
    {
        $entries = $this->entries;
        usort($entries, static fn (array $a, array $b): int
            => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        return $entries;
    }

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $registry = new self();
        $items    = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($items)) {
            return $registry; // bez compiled configu — prázdný přehled, ne chyba
        }

        foreach ($items as $id => $decl) {
            $id    = (string) $id;
            $class = is_array($decl) ? ($decl['class'] ?? null) : null;
            if (!is_string($class) || !class_exists($class)) {
                ErrorLogger::warn('Overview block class not found', ['block' => $id, 'class' => $class]);
                continue;
            }
            try {
                $block = new $class();
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'Overview block could not be constructed: ' . $id);
                continue;
            }
            if (!$block instanceof OverviewBlock) {
                ErrorLogger::warn('Overview block does not implement OverviewBlock', ['block' => $id, 'class' => $class]);
                continue;
            }
            $registry->add(
                $id,
                (string) ($decl['section'] ?? ''),
                is_int($decl['order'] ?? null) ? $decl['order'] : self::ORDER_DEFAULT,
                $block,
            );
        }

        return $registry;
    }
}
