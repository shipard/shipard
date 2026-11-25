<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer\Detail;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Module\ModuleUiExtension;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\Viewer\DetailExtension;

/**
 * Taby a štítky detailu z modulů (`detailExtensions`, #172 D16) — sběr pro
 * builder `TableViewer::renderDetail()`. Poskytovatele volá v pořadí
 * registru a jeho výsledek **kontroluje proti kontraktu detailu**:
 *
 *  - tab je `{id, label, content}`, obsah jen ze sdílených typů
 *    (`DetailContent::SHARED_TYPES`, včetně vnořených bloků `composite`) —
 *    žádné HTML ani typy vázané na viewer (přílohy, návrh, doklad);
 *  - štítek je `{label, style}`;
 *  - id tabu se prefixuje id modulu (`<modul>.<id>`), takže nekoliduje
 *    s taby vieweru ani jiného modulu.
 *
 * Porušení kontraktu je selhání poskytovatele jako výjimka: zaloguje se
 * a poskytovatel se přeskočí **celý** (taby i štítky) — viz
 * `ModuleUiExtensions::call()`.
 */
final class DetailExtensions
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $record záznam, který dostávají háčky vieweru
     * @return array{tabs: list<array{id: string, label: string, content: array<string, mixed>}>,
     *               badges: list<array{label: string, style: string}>}
     */
    public static function collect(ModuleUiExtensions $extensions, ModuleExtensionContext $context, array $record): array
    {
        $tabs = [];
        $badges = [];

        foreach ($extensions->for(ModuleUiExtensions::DETAIL, $context->table) as $extension) {
            $part = $extensions->call(
                $extension,
                static fn (DetailExtension $provider): array => [
                    'tabs' => self::checkedTabs($extension, $provider->tabs($context->table, $record, $context)),
                    'badges' => self::checkedBadges($provider->badges($context->table, $record, $context)),
                ],
            );
            if ($part === null) {
                continue;
            }
            $tabs = [...$tabs, ...$part['tabs']];
            $badges = [...$badges, ...$part['badges']];
        }

        return ['tabs' => $tabs, 'badges' => $badges];
    }

    /**
     * @param array<mixed> $tabs
     * @return list<array{id: string, label: string, content: array<string, mixed>}>
     */
    private static function checkedTabs(ModuleUiExtension $extension, array $tabs): array
    {
        if (!array_is_list($tabs)) {
            throw new \UnexpectedValueException('tabs() must return a list of tabs');
        }

        $checked = [];
        $seen = [];
        foreach ($tabs as $idx => $tab) {
            $where = "tabs()[{$idx}]";
            if (!is_array($tab)) {
                throw new \UnexpectedValueException("{$where} must be an array");
            }
            $id = $tab['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \UnexpectedValueException("{$where}.id must be a non-empty string");
            }
            if (isset($seen[$id])) {
                throw new \UnexpectedValueException("{$where}.id '{$id}' is used twice");
            }
            $seen[$id] = true;

            $label = $tab['label'] ?? null;
            if (!is_string($label) || $label === '') {
                throw new \UnexpectedValueException("{$where}.label must be a non-empty string");
            }
            $content = $tab['content'] ?? null;
            if (!is_array($content)) {
                throw new \UnexpectedValueException("{$where}.content must be a content block");
            }
            foreach (DetailContent::types($content) as $type) {
                if (!in_array($type, DetailContent::SHARED_TYPES, true)) {
                    throw new \UnexpectedValueException(
                        "{$where}.content uses type '{$type}' (allowed: " . implode(', ', DetailContent::SHARED_TYPES) . ')',
                    );
                }
            }

            $checked[] = ['id' => $extension->moduleId . '.' . $id, 'label' => $label, 'content' => $content];
        }
        return $checked;
    }

    /**
     * @param array<mixed> $badges
     * @return list<array{label: string, style: string}>
     */
    private static function checkedBadges(array $badges): array
    {
        if (!array_is_list($badges)) {
            throw new \UnexpectedValueException('badges() must return a list of badges');
        }

        $checked = [];
        foreach ($badges as $idx => $badge) {
            $where = "badges()[{$idx}]";
            if (!is_array($badge)) {
                throw new \UnexpectedValueException("{$where} must be an array");
            }
            $label = $badge['label'] ?? null;
            if (!is_string($label) || $label === '') {
                throw new \UnexpectedValueException("{$where}.label must be a non-empty string");
            }
            $style = $badge['style'] ?? null;
            if (!is_string($style) || $style === '') {
                throw new \UnexpectedValueException("{$where}.style must be a non-empty string");
            }
            $checked[] = ['label' => $label, 'style' => $style];
        }
        return $checked;
    }
}
