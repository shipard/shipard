<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer\Detail;

/**
 * Typy obsahu detailu záznamu (#137 D30) — jediný seznam, který hlídají
 * unit `DetailContractTest` (žádný zdroj neposílá `html`) a integrační
 * `ViewerDetailContractTest` (obsah každého tabu prvního záznamu je jen
 * ze známých typů). Backend HTML se vzhledem neposílá: hláška je `notice`,
 * štítky `badges`, prázdný stav `empty`, prostý text `text`, strojový výpis
 * `code`; jediné HTML v kontraktu je `untrusted-html` (tělo e-mailu
 * v sandboxu). Tvar jednotlivých typů: docs/frontend.md → Formát detail
 * panelu.
 */
final class DetailContent
{
    public const TYPES = [
        'properties', 'table', 'heading', 'composite',
        'notice', 'badges', 'empty', 'text', 'code',
        'untrusted-html', 'attachment-grid', 'attachments',
        'failure', 'proposal', 'document',
    ];

    /**
     * Typy všech bloků obsahu včetně vnořených (`composite.blocks`) v pořadí
     * výskytu; blok bez typu dá prázdný řetězec, ať ho kontrakt nahlásí.
     *
     * @param array<string, mixed> $content
     * @return list<string>
     */
    public static function types(array $content): array
    {
        $type = $content['type'] ?? null;
        $types = [is_string($type) ? $type : ''];
        if ($type === 'composite') {
            foreach ($content['blocks'] ?? [] as $block) {
                if (is_array($block)) {
                    $types = [...$types, ...self::types($block)];
                }
            }
        }
        return $types;
    }
}
