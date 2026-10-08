<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dashboard;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Feed\FeedTexts;
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
 * štítek nepotřebuje. Štítky dokladu = primární `content_tag` **i** štítky
 * řádkových výjimek (`_resolve.contentTag.rowExceptions`, elektřina na
 * faktuře za nájem — tasks/content-tag-row-exceptions.md D1); doklad se
 * do každé své karty počítá právě jednou. `goods.stock` nemá mapování
 * v nabídce (D7) — karta nabízí volbu účtu materiál (501…) / zboží (504…)
 * z aktivní osnovy. Štítky vědomě bez mapování (admin.other,
 * people.benefits) nekartují — jsou „review by design" a karta by neměla
 * co založit.
 *
 * Akce nesou lokalizovaný `label` ze serveru (passthrough vzor AlertsSource)
 * — u goods.stock je v labelu číslo účtu z osnovy, frontend klíč nestačí.
 * Texty podtitulku a labelů jdou z katalogu `core.exchange.feedTexts` přes
 * `FeedTexts` (ICU plurály, #101 D11–D15), bez katalogu anglický fallback;
 * titulek je název štítku z taxonomie `core.exchange.contentTags`.
 */
final class ContentTagSuggestionsSource implements FeedSource
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';

    /** Stejná trojice jako ContentTagResolver — položka musí být živá. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    /** Katalog textů karet (`config/feedTexts.jsonc`). */
    private const FEED_TEXTS_CFG_ITEM = 'core.exchange.feedTexts';

    /** @return list<array<string, mixed>> */
    public function collectCards(FeedContext $ctx): array
    {
        $tagRows = $this->fetchOpenTagCounts($ctx);
        if ($tagRows === []) {
            return [];
        }

        $covered = $this->coveredTags($ctx);
        $offer = new AccountingItemsOffer($ctx->db);
        $texts = FeedTexts::forContext($ctx, self::FEED_TEXTS_CFG_ITEM);

        $cards = [];
        foreach ($tagRows as $row) {
            $tag = trim((string) ($row['tag'] ?? ''));
            if ($tag === '' || isset($covered[$tag])) {
                continue;
            }
            $card = $this->buildCard($ctx, $texts, $offer, $tag, (int) $row['waiting'], $row['latest'] ?? null);
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
     * zpráva mimo Hotovo/Archiv/Koš) agregované per štítek. Štítek dokladu je
     * primární `content_tag` **i** každá řádková výjimka
     * (`_resolve.contentTag.rowExceptions[*].tag`,
     * tasks/content-tag-row-exceptions.md D1), proto dotaz vrací řádky
     * analýz a agregace běží v PHP: doklad se do každého svého štítku
     * počítá právě jednou. Řazení waiting DESC, latest DESC, tag ASC.
     *
     * Sloupec `content_tag` zůstává primární štítek (learning, ISDOC,
     * filtrování) — výjimky se čtou z canonicalu. Podmínka na
     * `rowExceptions[0]` kryje i návrh bez primárního štítku s výjimkami
     * (dnes se nepersistuje, do budoucna stojí málo).
     *
     * @return list<array{tag: string, waiting: int, latest: mixed}>
     */
    private function fetchOpenTagCounts(FeedContext $ctx): array
    {
        $rows = $ctx->db->fetchAll(
            'SELECT `a`.`content_tag` AS `tag`,'
            . ' JSON_EXTRACT(`a`.`canonical_json`, \'$._resolve.contentTag.rowExceptions[*].tag\') AS `row_tags`,'
            . ' `m`.`received_at` AS `latest`'
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
            . ' AND (`a`.`content_tag` IS NOT NULL'
            . '   OR JSON_EXTRACT(`a`.`canonical_json`, \'$._resolve.contentTag.rowExceptions[0]\') IS NOT NULL)',
            [IncomingMessageDocument::DOC_STATE_NEW, IncomingMessageDocument::DOC_STATE_OPEN],
            IncomingMessageDocument::ANALYSIS_ANALYZED,
        );

        /** @var array<string, array{waiting: int, latest: mixed, latestTs: ?int}> $agg */
        $agg = [];
        foreach ($rows as $row) {
            $latest = $row['latest'] ?? null;
            $latestTs = $this->toDateTime($latest)?->getTimestamp();
            foreach ($this->documentTags($row) as $tag) {
                $entry = $agg[$tag] ?? ['waiting' => 0, 'latest' => null, 'latestTs' => null];
                $entry['waiting']++;
                if ($latestTs !== null && ($entry['latestTs'] === null || $latestTs > $entry['latestTs'])) {
                    $entry['latest'] = $latest;
                    $entry['latestTs'] = $latestTs;
                }
                $agg[$tag] = $entry;
            }
        }

        uksort($agg, static fn (string $a, string $b): int =>
            [$agg[$b]['waiting'], $agg[$b]['latestTs'] ?? PHP_INT_MIN, $a]
            <=> [$agg[$a]['waiting'], $agg[$a]['latestTs'] ?? PHP_INT_MIN, $b]);

        $result = [];
        foreach ($agg as $tag => $entry) {
            $result[] = ['tag' => (string) $tag, 'waiting' => $entry['waiting'], 'latest' => $entry['latest']];
        }
        return $result;
    }

    /**
     * Štítky jednoho otevřeného návrhu bez duplicit: primární + řádkové
     * výjimky. `row_tags` přijde z JSON_EXTRACT jako string s JSON polem
     * (`["a","a","b"]`, Dibi JSON typ nevrací) nebo NULL, když cesta chybí.
     *
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $row
     * @return list<string>
     */
    private function documentTags(array|\ArrayAccess $row): array
    {
        $tags = [];
        $primary = trim((string) ($row['tag'] ?? ''));
        if ($primary !== '') {
            $tags[$primary] = true;
        }
        $decoded = json_decode((string) ($row['row_tags'] ?? ''), true);
        foreach (is_array($decoded) ? $decoded : [] as $tag) {
            $tag = is_string($tag) ? trim($tag) : '';
            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }
        return array_map('strval', array_keys($tags));
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
        FeedTexts $texts,
        AccountingItemsOffer $offer,
        string $tag,
        int $waiting,
        mixed $latest,
    ): ?array {
        $label = $this->tagLabel($ctx, $tag);
        $waitingText = $texts->t(
            'contentTag.waiting',
            '{n, plural, one {# document waiting} other {# documents waiting}}',
            ['n' => $waiting],
        );

        $entry = $tag === 'goods.stock' ? null : $offer->entryForTag($tag);
        if ($entry !== null) {
            $starterName = AccountingItemsOffer::localizedField(
                $entry, 'name', $ctx->language, (string) $entry['code'],
            );
            $account = (string) ($entry['account'] ?? '');
            $subtitle = $account !== ''
                ? $texts->t('contentTag.suggestionAccount', '{waiting} · suggestion: {starter} ({account})',
                    ['waiting' => $waitingText, 'starter' => $starterName, 'account' => $account])
                : $texts->t('contentTag.suggestion', '{waiting} · suggestion: {starter}',
                    ['waiting' => $waitingText, 'starter' => $starterName]);
            $actions = [[
                'id'      => 'materialize',
                'kind'    => 'materialize_content_tag',
                'label'   => $texts->t('contentTag.create', 'Create item'),
                'target'  => ['tag' => $tag],
                'primary' => true,
            ]];
        } elseif ($tag === 'goods.stock') {
            $material = $this->firstAccountByPrefix($ctx, '501');
            $goods    = $this->firstAccountByPrefix($ctx, '504');
            if ($material === null || $goods === null) {
                return null; // osnova bez 501/504 — není z čeho volit
            }
            $subtitle = $texts->t('contentTag.chooseStock', '{waiting} · choose posting: material or goods',
                ['waiting' => $waitingText]);
            $actions = [
                [
                    'id'      => 'materializeMaterial',
                    'kind'    => 'materialize_content_tag',
                    'label'   => $texts->t('contentTag.asMaterial', 'As material ({account})', ['account' => $material]),
                    'target'  => ['tag' => $tag, 'account' => $material],
                    'primary' => true,
                ],
                [
                    'id'     => 'materializeGoods',
                    'kind'   => 'materialize_content_tag',
                    'label'  => $texts->t('contentTag.asGoods', 'As goods ({account})', ['account' => $goods]),
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

    /** ATOM timestamp z DB hodnoty (DateTime|string|null) — vzor AlertsSource. */
    private function toAtom(mixed $value): ?string
    {
        return $this->toDateTime($value)?->format(\DateTimeInterface::ATOM);
    }

    /** DB hodnota (DateTime|string|null) jako datum; nečitelná → null. */
    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
