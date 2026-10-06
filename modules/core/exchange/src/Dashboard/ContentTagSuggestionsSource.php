<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dashboard;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Karta položky k založení (tasks/content-tag-ui.md D25): otevřené
 * dokumentové návrhy nesou obsahový štítek, který nemá živou otagovanou
 * položku — jeden klik založí startovní položku z nabídky (D26) a návrhy se
 * při dalším otevření povýší na plnou trojici bez reanalýzy (D16). Ve feedu
 * má karta vlastní sekci Položky k založení (`feedSection = newItems`,
 * #101 D2) a titulek = jen label štítku (D6).
 *
 * Jedna karta per štítek (dedupe přes zprávy), query-driven bez dismiss
 * stavu — karta zmizí, jakmile položka existuje nebo žádný otevřený návrh
 * štítek nepotřebuje. `goods.stock` nemá mapování v nabídce (D7) — karta
 * nabízí volbu účtu materiál (501…) / zboží (504…) z aktivní osnovy.
 * Štítky vědomě bez mapování (admin.other, people.benefits) nekartují —
 * jsou „review by design" a karta by neměla co založit.
 *
 * Akce nesou lokalizovaný `label` ze serveru (passthrough vzor AlertsSource)
 * — u goods.stock je v labelu číslo účtu z osnovy, frontend klíč nestačí.
 */
final class ContentTagSuggestionsSource implements FeedSource
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';

    /** Stejná trojice jako ContentTagResolver — položka musí být živá. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    /** @return list<array<string, mixed>> */
    public function collectCards(FeedContext $ctx): array
    {
        $tagRows = $this->fetchOpenTagCounts($ctx);
        if ($tagRows === []) {
            return [];
        }

        $covered = $this->coveredTags($ctx);
        $offer = new AccountingItemsOffer($ctx->db);

        $cards = [];
        foreach ($tagRows as $row) {
            $tag = trim((string) ($row['tag'] ?? ''));
            if ($tag === '' || isset($covered[$tag])) {
                continue;
            }
            $card = $this->buildCard($ctx, $offer, $tag, (int) $row['waiting'], $row['latest'] ?? null);
            if ($card !== null) {
                $cards[] = $card;
            }
            if (count($cards) >= $ctx->sourceLimit) {
                break;
            }
        }
        return $cards;
    }

    /**
     * Otevřené návrhy (poslední úspěšná analýza per zpráva, bez verdiktu,
     * zpráva mimo Hotovo/Archiv/Koš) agregované per štítek.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchOpenTagCounts(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `a`.`content_tag` AS `tag`, COUNT(*) AS `waiting`,'
            . ' MAX(`m`.`received_at`) AS `latest`'
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' JOIN `' . self::ANALYSES_TABLE . '` `a` ON `a`.`id` = ('
            . '     SELECT `a2`.`id` FROM `' . self::ANALYSES_TABLE . '` `a2`'
            . '     WHERE `a2`.`message` = `m`.`id` AND `a2`.`status` = 2'
            . '     ORDER BY `a2`.`analyzed_at` DESC, `a2`.`id` DESC LIMIT 1'
            . ' )'
            . ' WHERE `m`.`docState` IN %in'
            . ' AND `m`.`analysis_state` = %i'
            . ' AND `a`.`canonical_json` IS NOT NULL'
            . ' AND `a`.`resolution` IS NULL'
            . ' AND `a`.`content_tag` IS NOT NULL'
            . ' GROUP BY `a`.`content_tag`'
            . ' ORDER BY `waiting` DESC, `latest` DESC',
            [IncomingMessageDocument::DOC_STATE_NEW, IncomingMessageDocument::DOC_STATE_OPEN],
            IncomingMessageDocument::ANALYSIS_ANALYZED,
        );
    }

    /**
     * Štítky pokryté živou otagovanou položkou — content_tags je JSON list,
     * filtr běží v PHP (stejný předpoklad jako ContentTagResolver).
     *
     * @return array<string, true>
     */
    private function coveredTags(FeedContext $ctx): array
    {
        $rows = $ctx->db->fetchAll(
            'SELECT `content_tags` FROM `economy_items`'
            . ' WHERE `docState` IN %in AND `content_tags` IS NOT NULL',
            self::ITEM_ACTIVE_STATES,
        );
        $covered = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
            if (!is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $covered[$tag] = true;
                }
            }
        }
        return $covered;
    }

    /** @return array<string, mixed>|null null = štítek bez čeho založit (review by design) */
    private function buildCard(
        FeedContext $ctx,
        AccountingItemsOffer $offer,
        string $tag,
        int $waiting,
        mixed $latest,
    ): ?array {
        $cs = $ctx->language === 'cs';
        $label = $this->tagLabel($ctx, $tag);

        $entry = $tag === 'goods.stock' ? null : $offer->entryForTag($tag);
        if ($entry !== null) {
            $starterName = AccountingItemsOffer::localizedField(
                $entry, 'name', $ctx->language, (string) $entry['code'],
            );
            $account = (string) ($entry['account'] ?? '');
            $subtitle = $this->waitingText($cs, $waiting)
                . ' · ' . ($cs ? 'návrh' : 'suggestion') . ": {$starterName}"
                . ($account !== '' ? " ({$account})" : '');
            $actions = [[
                'id'      => 'materialize',
                'kind'    => 'materialize_content_tag',
                'label'   => $cs ? 'Založit položku' : 'Create item',
                'target'  => ['tag' => $tag],
                'primary' => true,
            ]];
        } elseif ($tag === 'goods.stock') {
            $material = $this->firstAccountByPrefix($ctx, '501');
            $goods    = $this->firstAccountByPrefix($ctx, '504');
            if ($material === null || $goods === null) {
                return null; // osnova bez 501/504 — není z čeho volit
            }
            $subtitle = $this->waitingText($cs, $waiting)
                . ' · ' . ($cs ? 'zvolte účtování: materiál, nebo zboží' : 'choose posting: material or goods');
            $actions = [
                [
                    'id'      => 'materializeMaterial',
                    'kind'    => 'materialize_content_tag',
                    'label'   => $cs ? "Jako materiál ({$material})" : "As material ({$material})",
                    'target'  => ['tag' => $tag, 'account' => $material],
                    'primary' => true,
                ],
                [
                    'id'     => 'materializeGoods',
                    'kind'   => 'materialize_content_tag',
                    'label'  => $cs ? "Jako zboží ({$goods})" : "As goods ({$goods})",
                    'target' => ['tag' => $tag, 'account' => $goods],
                ],
            ];
        } else {
            return null;
        }

        return [
            'id'         => 'content_tag:' . $tag,
            'source'     => 'contentTags',
            // review, ne info (Issue #32/2 D12): karta blokuje povýšení
            // návrhů a po založení položky se přestane objevovat. Sekci
            // Položky k založení (#101 D2) určuje feedSection, kind zůstává
            // (county, badge sekcí navigace a AI shrnutí na něm stojí).
            'kind'        => 'review',
            'feedSection' => FeedSource::SECTION_NEW_ITEMS,
            'icon'       => 'question',
            'stateStyle' => 'concept',
            'category'   => FeedSource::CATEGORY_INVOICES,
            // Po plánovaném sloučení sekce Základní do `_top` (ui-shells.md
            // §13) změnit na NAV_SECTION_TOP.
            'navSection' => 'basic',
            'title'      => $label,
            'subtitle'   => $subtitle,
            'timestamp'  => $this->toAtom($latest),
            'context'    => ['tag' => $tag, 'waiting' => $waiting],
            'actions'    => $actions,
        ];
    }

    /** Lokalizovaný label štítku z cfgItem taxonomie; fallback na klíč. */
    private function tagLabel(FeedContext $ctx, string $tag): string
    {
        $taxonomy = $ctx->config?->cfgItem('core.exchange.contentTags');
        $name = is_array($taxonomy) ? ($taxonomy[$tag]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : $tag;
    }

    /**
     * První aktivní analytický účet dle prefixu čísla (501 → 501100) —
     * přímý dotaz jako exchange AccountResolver, bez závislosti na
     * economy.accounting třídách.
     */
    private function firstAccountByPrefix(FeedContext $ctx, string $prefix): ?string
    {
        $number = $ctx->db->fetchSingle(
            'SELECT `number` FROM `economy_accounting_accounts`'
            . ' WHERE `number` LIKE %like~ AND `account_level` = 4 AND `docState` IN %in'
            . ' ORDER BY `number` LIMIT 1',
            $prefix,
            self::ITEM_ACTIVE_STATES,
        );
        return is_string($number) && $number !== '' ? $number : null;
    }

    private function waitingText(bool $cs, int $waiting): string
    {
        if (!$cs) {
            return $waiting === 1 ? '1 document waiting' : "{$waiting} documents waiting";
        }
        return match (true) {
            $waiting === 1 => '1 doklad čeká',
            $waiting < 5   => "{$waiting} doklady čekají",
            default        => "{$waiting} dokladů čeká",
        };
    }

    /** ATOM timestamp z DB hodnoty (DateTime|string|null) — vzor AlertsSource. */
    private function toAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
