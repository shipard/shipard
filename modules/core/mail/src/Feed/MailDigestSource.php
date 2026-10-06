<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Feed;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Feed\FeedTexts;

/**
 * Feed zdroj šumu došlé pošty (Fáze 3, design §8):
 *
 *   - **Digest karta** (max 1 denně): souhrn zpráv auto-archivovaných dnes
 *     pravidly odesílatelů — počet + vzorek odesílatelů. Nic se nesmí dít
 *     tiše (D7): každý zásah pre-triage je viditelný tady, s akcemi
 *     „Zobrazit" (viewer, tab Archiv) a „Vrátit vše" (undo endpoint).
 *     Žádný auto-archiv dnes → žádná karta.
 *   - **Karty návrhů pravidel**: pravidla docState=10 (Koncept)
 *     s `origin='suggested'` z učícího handleru — potvrdit / zamítnout /
 *     upravit (formulář, např. změna na doménové pravidlo před potvrzením).
 *
 * Akce se emitují bez `label` — frontend je lokalizuje podle `action.id`
 * (i18n klíče `dashboard.card.action.*`). Titulky/podtitulky skládá server
 * z katalogu `core.mail.feedTexts` přes `FeedTexts` (ICU plurály, #101
 * D11–D15; vzor MailSuggestionsSource), bez katalogu anglický fallback.
 *
 * Sekce feedu (#101 D10): bez `feedSection` — výchozí mapování z `kind`,
 * tedy návrh pravidla (review) → Ke kontrole, digest (info) → Ostatní.
 */
final class MailDigestSource implements FeedSource
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const RULES_TABLE = 'core_mail_sender_rules';

    /** Strop vzorku odesílatelů v podtitulku digestu. */
    private const MAX_SAMPLE_SENDERS = 3;

    /** docState pravidel (core.system.docStatesArchive). */
    private const RULE_STATE_DRAFT = 10;

    private const PATTERN_KINDS_CFG_ITEM = 'core.mail.senderRulePatternKinds';

    /** Katalog textů karet (`config/feedTexts.jsonc`). */
    private const FEED_TEXTS_CFG_ITEM = 'core.mail.feedTexts';

    public function collectCards(FeedContext $ctx): array
    {
        $cards = [];
        $texts = FeedTexts::forContext($ctx, self::FEED_TEXTS_CFG_ITEM);

        $digest = $this->buildDigestCard($ctx, $texts);
        if ($digest !== null) {
            $cards[] = $digest;
        }

        foreach ($this->fetchSuggestedRules($ctx) as $row) {
            $cards[] = $this->buildRuleSuggestionCard($ctx, $texts, $row);
        }

        return $cards;
    }

    /** Digest dnešního auto-archivu, nebo null když dnes nic nespadlo. */
    private function buildDigestCard(FeedContext $ctx, FeedTexts $texts): ?array
    {
        $date = date('Y-m-d');
        $summary = $ctx->db->fetchAll(
            'SELECT COUNT(*) AS `cnt`, MAX(`auto_disposed_at`) AS `last_at`'
            . ' FROM `' . self::MESSAGES_TABLE . '`'
            . ' WHERE `auto_disposed_at` >= %s',
            $date . ' 00:00:00',
        );
        $count = (int) (($summary[0]['cnt'] ?? null) ?? 0);
        if ($count === 0) {
            return null;
        }

        $senderRows = $ctx->db->fetchAll(
            'SELECT DISTINCT `sender_email`'
            . ' FROM `' . self::MESSAGES_TABLE . '`'
            . ' WHERE `auto_disposed_at` >= %s'
            . ' ORDER BY `sender_email`'
            . ' LIMIT %i',
            $date . ' 00:00:00',
            self::MAX_SAMPLE_SENDERS + 1,
        );
        $senders = array_map(static fn(array $r): string => (string) $r['sender_email'], $senderRows);
        $sample = implode(' · ', array_slice($senders, 0, self::MAX_SAMPLE_SENDERS));
        if (count($senders) > self::MAX_SAMPLE_SENDERS) {
            $sample .= ' …';
        }

        $title = $texts->t(
            'digest.title',
            '{n, plural, one {# message auto-archived} other {# messages auto-archived}}',
            ['n' => $count],
        );

        return [
            'id'         => 'mail_digest:' . $date,
            'source'     => 'mail',
            'kind'       => 'info',
            'icon'       => 'info',
            'stateStyle' => 'archive',
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $title,
            'subtitle'   => $sample,
            'timestamp'  => $this->toAtom($summary[0]['last_at'] ?? null),
            'context'    => ['date' => $date, 'count' => $count],
            'actions'    => [
                ['id' => 'openArchive', 'kind' => 'open_viewer',
                    'target' => ['viewerId' => 'core.mail.incoming', 'viewGroup' => 'archive'], 'primary' => true],
                ['id' => 'undoAutoArchive', 'kind' => 'undo_auto_archive', 'target' => ['date' => $date]],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchSuggestedRules(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `id`, `pattern_kind`, `pattern`, `notice`, `created`'
            . ' FROM `' . self::RULES_TABLE . '`'
            . ' WHERE `docState` = %i AND `origin` = %s'
            . ' ORDER BY `created` DESC, `id` DESC'
            . ' LIMIT %i',
            self::RULE_STATE_DRAFT,
            'suggested',
            $ctx->sourceLimit,
        );
    }

    /** @param array<string,mixed> $row */
    private function buildRuleSuggestionCard(FeedContext $ctx, FeedTexts $texts, array $row): array
    {
        $ruleId = (int) $row['id'];
        $pattern = (string) $row['pattern'];

        $subtitleParts = [];
        $notice = trim((string) ($row['notice'] ?? ''));
        if ($notice !== '') {
            $subtitleParts[] = $notice;
        }
        $subtitleParts[] = $this->patternKindLabel($ctx, (string) $row['pattern_kind']);

        return [
            'id'         => 'mail_rule_suggestion:' . $ruleId,
            'source'     => 'mail',
            'kind'       => 'review',
            'icon'       => 'question',
            'stateStyle' => 'concept',
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $texts->t('senderRule.title', 'Always archive mail from {pattern}?', ['pattern' => $pattern]),
            'subtitle'   => implode(' · ', $subtitleParts),
            'timestamp'  => $this->toAtom($row['created'] ?? null),
            'context'    => ['ruleId' => $ruleId],
            'actions'    => [
                ['id' => 'confirmRule', 'kind' => 'confirm_sender_rule', 'target' => ['ruleId' => $ruleId], 'primary' => true],
                ['id' => 'rejectRule',  'kind' => 'reject_sender_rule',  'target' => ['ruleId' => $ruleId]],
                ['id' => 'editRule',    'kind' => 'open_form',           'target' => ['table' => self::RULES_TABLE, 'recordId' => $ruleId]],
            ],
        ];
    }

    private function patternKindLabel(FeedContext $ctx, string $kind): string
    {
        $cfg = $ctx->config?->cfgItem(self::PATTERN_KINDS_CFG_ITEM);
        if (is_array($cfg) && isset($cfg[$kind]['name'])) {
            return (string) $cfg[$kind]['name'];
        }
        return $kind;
    }

    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
