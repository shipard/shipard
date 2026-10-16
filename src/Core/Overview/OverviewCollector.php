<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Sběr Přehledu firmy (#120 D2): projde bloky v pořadí sekcí (cfgItem
 * `core.overview.sections`) a `order` bloku a složí odpověď
 * `GET /_ui/overview` (kontrakt v docs/overview.md).
 *
 * Izolace per blok (vzor FeedCollector): výjimka jednoho bloku se zaloguje,
 * sekce dostane stav `error` a zprávu, ostatní bloky běží dál. Blok, který
 * na DS nedává smysl (`isAvailable` false), se vynechá bez stopy. Sekce bez
 * položek i zpráv se neposílá.
 *
 * Stav sekce: `error` (některý blok selhal) > `unavailable` (bez položek
 * a některý blok ji vrátil — pojistka D6 / D15) > `ok`.
 */
final class OverviewCollector
{
    public const SECTIONS_ITEM = 'core.overview.sections';

    /** Katalog textů sběru samotného (zpráva o selhání bloku). */
    public const TEXTS_ITEM = 'core.system.overviewTexts';

    public const STATE_OK          = 'ok';
    public const STATE_UNAVAILABLE = 'unavailable';
    public const STATE_ERROR       = 'error';

    /** Pořadí a anglické názvy sekcí bez compiled configu (DS před `ds-upgrade`). */
    private const SECTIONS_FALLBACK = [
        ['id' => 'money',       'order' => 10, 'name' => 'Money'],
        ['id' => 'receivables', 'order' => 20, 'name' => 'Receivables'],
        ['id' => 'payables',    'order' => 30, 'name' => 'Payables'],
        ['id' => 'vat',         'order' => 40, 'name' => 'VAT'],
        ['id' => 'result',      'order' => 50, 'name' => 'Profit or loss'],
    ];

    /**
     * @return array{asOf: string, sections: list<array{id: string, title: string,
     *         items: list<array<string, mixed>>, messages: list<array<string, mixed>>, state: string}>}
     */
    public function collect(OverviewContext $ctx, OverviewBlockRegistry $registry): array
    {
        $sections = $this->sections($ctx->config);
        $texts    = $ctx->texts(self::TEXTS_ITEM);

        /** @var array<string, array{items: list<OverviewItem>, messages: list<OverviewMessage>, failed: bool, unavailable: bool}> $acc */
        $acc = [];
        foreach ($registry->entries() as $entry) {
            $sectionId = $entry['section'];
            if (!isset($sections[$sectionId])) {
                ErrorLogger::warn('Overview block declares unknown section', [
                    'block' => $entry['id'], 'section' => $sectionId,
                ]);
                continue;
            }
            $acc[$sectionId] ??= ['items' => [], 'messages' => [], 'failed' => false, 'unavailable' => false];

            try {
                if (!$entry['block']->isAvailable($ctx)) {
                    continue;
                }
                $result = $entry['block']->collect($ctx);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'Overview block failed: ' . $entry['id']);
                $acc[$sectionId]['failed']     = true;
                $acc[$sectionId]['messages'][] = new OverviewMessage(
                    OverviewMessage::SEVERITY_ERROR,
                    'blockFailed',
                    $texts->t('section.blockFailed', 'Part of this section could not be computed.'),
                );
                continue;
            }

            foreach ($result->items as $item) {
                $acc[$sectionId]['items'][] = $item;
            }
            foreach ($result->messages as $message) {
                $acc[$sectionId]['messages'][] = $message;
            }
            if ($result->state === OverviewBlockResult::STATE_UNAVAILABLE) {
                $acc[$sectionId]['unavailable'] = true;
            }
        }

        $out = [];
        foreach ($sections as $sectionId => $section) {
            $a = $acc[$sectionId] ?? null;
            if ($a === null || ($a['items'] === [] && $a['messages'] === [])) {
                continue;
            }
            $state = $a['failed']
                ? self::STATE_ERROR
                : (($a['items'] === [] && $a['unavailable']) ? self::STATE_UNAVAILABLE : self::STATE_OK);
            $out[] = [
                'id'       => $sectionId,
                'title'    => $section['name'],
                'items'    => array_map(static fn (OverviewItem $i): array => $i->toArray(), $a['items']),
                'messages' => array_map(static fn (OverviewMessage $m): array => $m->toArray(), $a['messages']),
                'state'    => $state,
            ];
        }

        return ['asOf' => $ctx->todayIso(), 'sections' => $out];
    }

    /**
     * Sekce z cfgItemu v pořadí `order` (compiled config nese `name` už
     * lokalizované); bez configu anglický fallback.
     *
     * @return array<string, array{order: int, name: string}> id → sekce, seřazené
     */
    private function sections(?ConfigRuntime $config): array
    {
        $raw  = $config?->cfgItem(self::SECTIONS_ITEM);
        $list = is_array($raw) && is_array($raw['sections'] ?? null) ? $raw['sections'] : self::SECTIONS_FALLBACK;

        $sections = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || $entry['id'] === '') {
                continue;
            }
            $sections[$entry['id']] = [
                'order' => is_int($entry['order'] ?? null) ? $entry['order'] : 1000,
                'name'  => is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : $entry['id'],
            ];
        }
        uksort($sections, static fn (string $a, string $b): int
            => [$sections[$a]['order'], $a] <=> [$sections[$b]['order'], $b]);

        return $sections;
    }
}
