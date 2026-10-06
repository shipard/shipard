<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Mail\Feed\MailDigestSource;

/**
 * Unit testy pro MailDigestSource.
 *
 * Pokrývají:
 *   - digest karta jen když dnes něco auto-spadlo (jinak žádná)
 *   - vzorek odesílatelů v podtitulku (strop 3 + „…")
 *   - akce digestu (open_viewer s tabem archive, undo_auto_archive s datem)
 *   - návrhové karty pravidel (kind review, akce confirm/reject/open_form)
 *   - prázdný vstup → []
 */
final class MailDigestSourceTest extends TestCase
{
    /**
     * Sestaví FeedContext s DB mockem routujícím dotazy dle tvaru SQL:
     * COUNT (digest souhrn), DISTINCT sender_email (vzorek), zbytek
     * (core_mail_sender_rules) = návrhy.
     *
     * @param list<array<string,mixed>> $summaryRows
     * @param list<array<string,mixed>> $senderRows
     * @param list<array<string,mixed>> $ruleRows
     */
    private function context(
        array $summaryRows,
        array $senderRows = [],
        array $ruleRows = [],
        ?ConfigRuntime $config = null,
        string $lang = 'cs',
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use ($summaryRows, $senderRows, $ruleRows): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return $summaryRows;
                }
                if (str_contains($sql, 'DISTINCT')) {
                    return $senderRows;
                }
                return $ruleRows;
            },
        );
        return new FeedContext($db, $config, $lang, 30);
    }

    public function testNoAutoDisposedTodayEmitsNoDigestCard(): void
    {
        $ctx = $this->context([['cnt' => 0, 'last_at' => null]]);

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertSame([], $cards);
    }

    public function testDigestCardShapeAndActions(): void
    {
        $ctx = $this->context(
            [['cnt' => 5, 'last_at' => '2026-07-15 09:30:00']],
            [['sender_email' => 'a@x.cz'], ['sender_email' => 'b@y.cz']],
        );

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('info', $card['kind']);
        // Bez feedSection — digest padá výchozím mapováním do Ostatní (#101 D10).
        $this->assertArrayNotHasKey('feedSection', $card);
        $this->assertSame('archive', $card['stateStyle']);
        $this->assertSame('other', $card['category']);
        $this->assertStringContainsString('5 zpráv automaticky archivováno', $card['title']);
        $this->assertSame('a@x.cz · b@y.cz', $card['subtitle']);

        $kinds = array_column($card['actions'], 'kind');
        $this->assertSame(['open_viewer', 'undo_auto_archive'], $kinds);

        $open = $card['actions'][0];
        $this->assertTrue($open['primary']);
        $this->assertSame('core.mail.incoming', $open['target']['viewerId']);
        $this->assertSame('archive', $open['target']['viewGroup']);

        $undo = $card['actions'][1];
        $this->assertSame(date('Y-m-d'), $undo['target']['date']);
    }

    public function testSenderSampleIsCappedWithEllipsis(): void
    {
        $ctx = $this->context(
            [['cnt' => 10, 'last_at' => '2026-07-15 09:30:00']],
            [
                ['sender_email' => 'a@x.cz'],
                ['sender_email' => 'b@x.cz'],
                ['sender_email' => 'c@x.cz'],
                ['sender_email' => 'd@x.cz'],
            ],
        );

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertSame('a@x.cz · b@x.cz · c@x.cz …', $cards[0]['subtitle']);
    }

    public function testEnglishTitle(): void
    {
        $ctx = $this->context(
            [['cnt' => 2, 'last_at' => '2026-07-15 09:30:00']],
            [['sender_email' => 'a@x.cz']],
            lang: 'en',
        );

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertSame('2 messages auto-archived', $cards[0]['title']);
    }

    public function testSuggestedRuleEmitsReviewCard(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'core.mail.senderRulePatternKinds'
                ? ['email' => ['name' => 'E-mailová adresa'], 'domain' => ['name' => 'Doména']]
                : null,
        );

        $ctx = $this->context(
            [['cnt' => 0, 'last_at' => null]],
            [],
            [[
                'id' => 7,
                'pattern_kind' => 'email',
                'pattern' => 'news@example.com',
                'notice' => 'Navrženo po 3 ručních odklizeních',
                'created' => '2026-07-15 08:00:00',
            ]],
            $config,
        );

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_rule_suggestion:7', $card['id']);
        $this->assertSame('review', $card['kind']);
        // Bez feedSection — návrh pravidla zůstává v Ke kontrole (#101 D10).
        $this->assertArrayNotHasKey('feedSection', $card);
        $this->assertSame('other', $card['category']);
        $this->assertSame('Vždy archivovat poštu od news@example.com?', $card['title']);
        $this->assertSame('Navrženo po 3 ručních odklizeních · E-mailová adresa', $card['subtitle']);
        $this->assertSame(7, $card['context']['ruleId']);

        $this->assertSame(
            ['confirm_sender_rule', 'reject_sender_rule', 'open_form'],
            array_column($card['actions'], 'kind'),
        );
        $this->assertTrue($card['actions'][0]['primary']);
        $this->assertSame(7, $card['actions'][0]['target']['ruleId']);
        $this->assertSame('core_mail_sender_rules', $card['actions'][2]['target']['table']);

        foreach ($card['actions'] as $action) {
            $this->assertArrayNotHasKey('label', $action, 'akce lokalizuje frontend dle action.id');
        }
    }

    public function testSuggestedRulesQueryUsesSourceLimit(): void
    {
        // LIMIT dotazu = pojistka zdroje z kontextu, ne strop feedu (#101 D3a).
        $limits = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$limits): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['cnt' => 0, 'last_at' => null]];
                }
                if (str_contains($sql, 'core_mail_sender_rules')) {
                    $limits[] = $args[array_key_last($args)];
                }
                return [];
            },
        );

        new MailDigestSource()->collectCards(new FeedContext($db, null, 'cs', 123));

        $this->assertSame([123], $limits);
    }

    public function testDigestAndSuggestionsCombine(): void
    {
        $ctx = $this->context(
            [['cnt' => 1, 'last_at' => '2026-07-15 09:00:00']],
            [['sender_email' => 'a@x.cz']],
            [[
                'id' => 3,
                'pattern_kind' => 'email',
                'pattern' => 'spam@example.com',
                'notice' => null,
                'created' => '2026-07-15 08:00:00',
            ]],
        );

        $cards = new MailDigestSource()->collectCards($ctx);

        $this->assertCount(2, $cards);
        $this->assertSame('mail_digest:' . date('Y-m-d'), $cards[0]['id']);
        $this->assertSame('mail_rule_suggestion:3', $cards[1]['id']);
        // Bez configu degraduje label druhu na holý klíč.
        $this->assertSame('email', $cards[1]['subtitle']);
    }
}
