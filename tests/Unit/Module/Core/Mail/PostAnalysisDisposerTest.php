<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;
use Shipard\Module\Core\Mail\PostAnalysisDisposer;
use Shipard\Module\Core\Mail\SenderRuleMatcher;

/**
 * Archivace ostatní pošty podle pravidla odesílatele
 * (tasks/mail-sender-rules-after-analysis.md D4–D6, D8).
 *
 * DB mock routuje dotazy podle tvaru SQL: řádek zprávy (`sender_email,
 * primary_type`), řádek pravidla (`pattern_kind`), COUNT úspěšných analýz
 * (fetchSingle), kandidáti D8 (fetchAll). Matcher a resolver prahů se
 * podstrkují konstruktorem.
 */
final class PostAnalysisDisposerTest extends TestCase
{
    /** @var list<array<mixed>> argumenty execute() */
    private array $executes = [];
    /** @var list<array<mixed>> argumenty fetchAll() */
    private array $fetchAlls = [];
    /** @var list<?int> profily, na které se ptal resolver */
    private array $thresholdProfiles = [];

    private const MESSAGE_OK = [
        'sender_email' => 'scan@example.com',
        'primary_type' => 'other',
        'docState' => 10,
        'source_type' => 2,
    ];

    private const RULE_EMAIL = ['id' => 5, 'pattern_kind' => 'email', 'pattern' => 'scan@example.com', 'disposition' => 'archiveIfOther'];

    /**
     * @param array<string,mixed>|null $message        řádek zprávy (afterResult)
     * @param array<string,mixed>|null $rule           řádek pravidla (applyToWaiting)
     * @param list<array<string,mixed>> $candidates    kandidáti D8
     * @param array<string, array<string,mixed>|null> $matches sender_email → pravidlo z matcheru
     * @param array<int, float> $reviewByProfile       review práh per profil (0 = bez profilu)
     */
    private function disposer(
        ?array $message = self::MESSAGE_OK,
        int $successfulRuns = 1,
        ?array $rule = null,
        array $candidates = [],
        array $matches = ['scan@example.com' => self::RULE_EMAIL],
        array $reviewByProfile = [0 => 0.6],
        int $affectedRows = 1,
        ?ConfigRuntime $config = null,
    ): PostAnalysisDisposer {
        $this->executes = [];
        $this->fetchAlls = [];
        $this->thresholdProfiles = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql) use ($message, $rule): ?array {
                if (str_contains($sql, 'pattern_kind')) {
                    return $rule;
                }
                if (str_contains($sql, 'sender_email, primary_type')) {
                    return $message;
                }
                return null;
            },
        );
        $db->method('fetchSingle')->willReturn($successfulRuns);
        $db->method('fetchAll')->willReturnCallback(
            function (mixed ...$args) use ($candidates): array {
                $this->fetchAlls[] = $args;
                return $candidates;
            },
        );
        $db->method('execute')->willReturnCallback(
            function (mixed ...$args): void {
                $this->executes[] = $args;
            },
        );
        $db->method('getAffectedRows')->willReturn($affectedRows);

        $matcher = $this->createMock(SenderRuleMatcher::class);
        $matcher->method('match')->willReturnCallback(
            static fn(string $email): ?array => $matches[strtolower(trim($email))] ?? null,
        );

        $resolver = $this->createMock(AnalysisConfidenceResolver::class);
        $resolver->method('thresholdsForProfile')->willReturnCallback(
            function (?int $profileNdx) use ($reviewByProfile): array {
                $this->thresholdProfiles[] = $profileNdx;
                return ['ready' => 0.9, 'review' => $reviewByProfile[$profileNdx ?? 0] ?? 0.6];
            },
        );

        return new PostAnalysisDisposer($db, $config, $matcher, $resolver);
    }

    /** @return list<array<mixed>> execute() volání nad tabulkou zpráv */
    private function messageUpdates(): array
    {
        return array_values(array_filter(
            $this->executes,
            static fn(array $call): bool => ($call[1] ?? null) === 'core_mail_incoming_messages',
        ));
    }

    /** @return list<array<mixed>> execute() volání nad tabulkou pravidel */
    private function ruleUpdates(): array
    {
        return array_values(array_filter(
            $this->executes,
            static fn(array $call): bool => ($call[1] ?? null) === 'core_mail_sender_rules',
        ));
    }

    // --- afterResult (D4–D6) -------------------------------------------------

    public function testArchivesOtherMessageFromSenderWithConfirmedRule(): void
    {
        $disposer = $this->disposer();

        $this->assertSame(5, $disposer->afterResult(77, false, 0.85, null));

        $updates = $this->messageUpdates();
        $this->assertCount(1, $updates);
        $sql = (string) $updates[0][0];
        $this->assertStringContainsString('docState = %i, docStateMain = %i', $sql);
        $this->assertStringContainsString('auto_disposed_by = %i, auto_disposed_at = %s', $sql);
        // Pojistka proti souběhu: archivuje se jen zpráva stále v Nové.
        $this->assertStringContainsString('WHERE id IN %in AND docState = %i', $sql);
        $this->assertSame(80, $updates[0][2]);
        $this->assertSame(4, $updates[0][3], 'docStateMain Archivu bez configu = 4');
        $this->assertSame(5, $updates[0][4]);
        $this->assertSame([77], $updates[0][7]);
        $this->assertSame(10, $updates[0][8]);

        $rules = $this->ruleUpdates();
        $this->assertCount(1, $rules);
        $this->assertStringContainsString('hit_count = hit_count + %i, last_hit_at = %s', (string) $rules[0][0]);
        $this->assertSame(1, $rules[0][2]);
        $this->assertSame(5, $rules[0][4]);
    }

    public function testArchiveDispositionRuleArchivesAfterAnalysisToo(): void
    {
        // D6: `archive` znamená „všechno“ — po analýze archivuje i ostatní poštu.
        $rule = ['id' => 9, 'pattern_kind' => 'domain', 'pattern' => 'example.com', 'disposition' => 'archive'];
        $disposer = $this->disposer(matches: ['scan@example.com' => $rule]);

        $this->assertSame(9, $disposer->afterResult(77, false, 0.85, null));
        $this->assertCount(1, $this->messageUpdates());
    }

    public function testDocumentPresentSkipsWithoutQueries(): void
    {
        $disposer = $this->disposer();

        $this->assertNull($disposer->afterResult(77, true, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testMissingConfidenceSkips(): void
    {
        $disposer = $this->disposer();

        $this->assertNull($disposer->afterResult(77, false, null, null));
        $this->assertSame([], $this->executes);
    }

    public function testNonOtherPrimaryTypeSkips(): void
    {
        // Ruční volba uživatele (nebo AI typ dokladu) má přednost.
        $disposer = $this->disposer(message: ['primary_type' => 'invoiceReceived'] + self::MESSAGE_OK);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testMessageNotInNewStateSkips(): void
    {
        $disposer = $this->disposer(message: ['docState' => 20] + self::MESSAGE_OK);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testManualUploadSkips(): void
    {
        $disposer = $this->disposer(message: ['source_type' => 1] + self::MESSAGE_OK);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testActionAttentionNeverArchives(): void
    {
        // tasks/mail-other-attention.md D6: zpráva K vyřízení (expirace, výzva
        // k platbě) pravidlem nikdy do Archivu — zůstává na dashboardu.
        $disposer = $this->disposer(message: ['attention' => 'action'] + self::MESSAGE_OK);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testInformationalAndUnknownAttentionArchive(): void
    {
        foreach (['info', 'promo', null] as $attention) {
            $disposer = $this->disposer(message: ['attention' => $attention] + self::MESSAGE_OK);

            $this->assertSame(5, $disposer->afterResult(77, false, 0.95, null), 'attention ' . var_export($attention, true));
            $this->assertCount(1, $this->messageUpdates());
        }
    }

    public function testMissingMessageRowSkips(): void
    {
        $disposer = $this->disposer(message: null);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testSecondSuccessfulAnalysisSkips(): void
    {
        // Zpráva vrácená z Archivu nebo ručně reanalyzovaná — pravidlo ji
        // znovu neodklidí (počet včetně právě vloženého řádku musí být 1).
        $disposer = $this->disposer(successfulRuns: 2);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testConfidenceBelowReviewThresholdSkips(): void
    {
        $disposer = $this->disposer();

        $this->assertNull($disposer->afterResult(77, false, 0.59, null));
        $this->assertSame([], $this->executes);
    }

    public function testConfidenceAtReviewThresholdArchives(): void
    {
        $disposer = $this->disposer();

        $this->assertSame(5, $disposer->afterResult(77, false, 0.6, null));
    }

    public function testThresholdComesFromRunProfile(): void
    {
        // D5: práh `review` AI profilu běhu, ne výchozího profilu.
        $disposer = $this->disposer(reviewByProfile: [0 => 0.6, 3 => 0.9]);

        $this->assertNull($disposer->afterResult(77, false, 0.85, 3));
        $this->assertSame([3], $this->thresholdProfiles);
        $this->assertSame([], $this->executes);
    }

    public function testNoRuleSkips(): void
    {
        $disposer = $this->disposer(matches: []);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertSame([], $this->executes);
    }

    public function testRaceLostYieldsNullWithoutHitCount(): void
    {
        // UPDATE nic nezasáhl (uživatel zprávu mezitím odklidil) → bez zásahu pravidla.
        $disposer = $this->disposer(affectedRows: 0);

        $this->assertNull($disposer->afterResult(77, false, 0.95, null));
        $this->assertCount(1, $this->messageUpdates());
        $this->assertSame([], $this->ruleUpdates());
    }

    public function testArchivedMainStateComesFromDocStatesConfig(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'core.mail.docStatesIncoming'
                ? ['10' => ['mainState' => 1], '80' => ['mainState' => 7]]
                : null,
        );
        $disposer = $this->disposer(config: $config);

        $disposer->afterResult(77, false, 0.95, null);

        $this->assertSame(7, $this->messageUpdates()[0][3]);
    }

    // --- applyToWaiting (D8) --------------------------------------------------

    public function testApplyToWaitingArchivesOnlyMatchingConfidentCandidates(): void
    {
        $otherRule = ['id' => 9, 'pattern_kind' => 'domain', 'pattern' => 'example.com', 'disposition' => 'archive'];
        $disposer = $this->disposer(
            rule: ['docState' => 40] + self::RULE_EMAIL,
            candidates: [
                ['id' => 101, 'sender_email' => 'scan@example.com', 'profile' => null, 'cls_confidence' => '0.95'],
                ['id' => 102, 'sender_email' => 'scan@example.com', 'profile' => null, 'cls_confidence' => '0.30'],
                ['id' => 103, 'sender_email' => 'scan@example.com', 'profile' => null, 'cls_confidence' => null],
                ['id' => 104, 'sender_email' => 'Scan@Example.com', 'profile' => null, 'cls_confidence' => '0.80'],
                // D6: pro tuhle adresu je nejkonkrétnější jiné pravidlo.
                ['id' => 105, 'sender_email' => 'news@example.com', 'profile' => null, 'cls_confidence' => '0.99'],
            ],
            matches: ['scan@example.com' => self::RULE_EMAIL, 'news@example.com' => $otherRule],
            affectedRows: 2,
        );

        $this->assertSame(2, $disposer->applyToWaiting(5));

        $updates = $this->messageUpdates();
        $this->assertCount(1, $updates);
        $this->assertSame([101, 104], $updates[0][7]);
        $this->assertSame(5, $updates[0][4]);

        $rules = $this->ruleUpdates();
        $this->assertCount(1, $rules);
        $this->assertSame(2, $rules[0][2], 'hit_count + počet archivovaných');
    }

    public function testApplyToWaitingCandidateQueryShape(): void
    {
        $disposer = $this->disposer(rule: ['docState' => 40] + self::RULE_EMAIL);

        $disposer->applyToWaiting(5);

        $this->assertCount(1, $this->fetchAlls);
        $call = $this->fetchAlls[0];
        $sql = (string) $call[0];
        $this->assertStringContainsString('JSON_VALUE(a.analysis_json, %s)', $sql);
        $this->assertStringContainsString('ORDER BY a2.analyzed_at DESC, a2.id DESC LIMIT 1', $sql);
        $this->assertStringContainsString('m.docState = %i AND m.analysis_state = %i', $sql);
        $this->assertStringContainsString('m.primary_type = %s AND m.source_type <> %i', $sql);
        $this->assertStringContainsString('NOT (a.canonical_json IS NOT NULL AND a.resolution IS NULL)', $sql);
        // D6 (#105): řádky K vyřízení pravidlo vynechá.
        $this->assertStringContainsString('(m.attention IS NULL OR m.attention <> %s)', $sql);
        $this->assertStringContainsString('LOWER(m.sender_email) = %s', $sql);
        $this->assertStringNotContainsString('SUBSTRING_INDEX', $sql);
        $this->assertSame('$.message_classification.confidence', $call[1]);
        $this->assertContains(10, $call);
        $this->assertContains(30, $call);
        $this->assertContains('other', $call);
        $this->assertContains('action', $call);
        $this->assertSame('scan@example.com', $call[count($call) - 1]);
    }

    public function testApplyToWaitingDomainRuleMatchesExactDomainAfterLastAt(): void
    {
        $rule = ['id' => 9, 'pattern_kind' => 'domain', 'pattern' => 'Example.COM', 'docState' => 40];
        $disposer = $this->disposer(rule: $rule);

        $disposer->applyToWaiting(9);

        $call = $this->fetchAlls[0];
        $this->assertStringContainsString("SUBSTRING_INDEX(LOWER(m.sender_email), '@', -1) = %s", (string) $call[0]);
        $this->assertSame('example.com', $call[count($call) - 1]);
    }

    public function testApplyToWaitingUsesThresholdOfEachCandidateProfile(): void
    {
        $disposer = $this->disposer(
            rule: ['docState' => 40] + self::RULE_EMAIL,
            candidates: [
                ['id' => 101, 'sender_email' => 'scan@example.com', 'profile' => 2, 'cls_confidence' => '0.70'],
                ['id' => 102, 'sender_email' => 'scan@example.com', 'profile' => 3, 'cls_confidence' => '0.70'],
            ],
            reviewByProfile: [2 => 0.8, 3 => 0.6],
        );

        $this->assertSame(1, $disposer->applyToWaiting(5));

        $this->assertSame([102], $this->messageUpdates()[0][7]);
        $this->assertSame([2, 3], $this->thresholdProfiles);
    }

    public function testApplyToWaitingNothingMatchingDoesNoUpdate(): void
    {
        $disposer = $this->disposer(
            rule: ['docState' => 40] + self::RULE_EMAIL,
            candidates: [
                ['id' => 101, 'sender_email' => 'scan@example.com', 'profile' => null, 'cls_confidence' => '0.10'],
            ],
        );

        $this->assertSame(0, $disposer->applyToWaiting(5));
        $this->assertSame([], $this->executes);
    }

    public function testApplyToWaitingUnconfirmedRuleReturnsZero(): void
    {
        $disposer = $this->disposer(rule: ['docState' => 10] + self::RULE_EMAIL);

        $this->assertSame(0, $disposer->applyToWaiting(5));
        $this->assertSame([], $this->fetchAlls);
        $this->assertSame([], $this->executes);
    }

    public function testApplyToWaitingMissingRuleReturnsZero(): void
    {
        $disposer = $this->disposer(rule: null);

        $this->assertSame(0, $disposer->applyToWaiting(5));
        $this->assertSame([], $this->fetchAlls);
    }
}
