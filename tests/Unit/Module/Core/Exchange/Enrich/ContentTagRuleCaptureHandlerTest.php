<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Enrich\ContentTagRuleCaptureHandler;

/**
 * Testable handler — Connection::query je final, executeSql se přepisuje
 * subclassingem (vzor TestableSupplierCodeCaptureHandler).
 */
class TestableContentTagRuleCaptureHandler extends ContentTagRuleCaptureHandler
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class ContentTagRuleCaptureHandlerTest extends TestCase
{
    /**
     * Mock routuje fetch podle tabulky: docs_core_heads → hlavička,
     * core_mail_message_analyses → canonical, core_exchange_tag_rules →
     * existující pravidlo.
     *
     * @param array<string, mixed>|null $head
     * @param array<string, mixed>|null $canonical
     * @param array<string, mixed>|null $rule
     */
    private function handler(?array $head, ?array $canonical, ?array $rule = null): TestableContentTagRuleCaptureHandler
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql) use ($head, $canonical, $rule): ?Row {
                if (str_contains($sql, 'docs_core_heads')) {
                    return $head !== null ? new Row($head) : null;
                }
                if (str_contains($sql, 'core_exchange_tag_rules')) {
                    return $rule !== null ? new Row($rule) : null;
                }
                return $canonical !== null
                    ? new Row(['canonical_json' => json_encode($canonical)])
                    : null;
            },
        );

        $handler = new TestableContentTagRuleCaptureHandler();
        $handler->setDb($db);
        return $handler;
    }

    /** @return array<string, mixed> */
    private function aiHead(): array
    {
        return ['source_kind' => 'aiExtraction', 'source_message' => 678];
    }

    /** @return array<string, mixed> */
    private function llmCanonical(
        string $tag = 'vehicle.fuel',
        string $tagSource = 'llm',
        ?string $companyId = '123 456 78',
    ): array {
        return [
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Benzina', 'companyId' => $companyId],
            '_resolve' => ['contentTag' => ['tag' => $tag, 'tagSource' => $tagSource]],
        ];
    }

    public function testLlmTagOnConfirmInsertsLearnedRule(): void
    {
        $handler = $this->handler($this->aiHead(), $this->llmCanonical());

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $companyId, $tag, $origin] = $handler->sqlCalls[0];
        $this->assertStringContainsString('INSERT INTO [core_exchange_tag_rules]', $sql);
        $this->assertSame('12345678', $companyId); // normalizované IČO
        $this->assertSame('vehicle.fuel', $tag);
        $this->assertSame('learned', $origin);
    }

    public function testMatchingRuleOnlyUpdatesStats(): void
    {
        $handler = $this->handler(
            $this->aiHead(),
            $this->llmCanonical(),
            rule: ['id' => 7, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $ruleId] = $handler->sqlCalls[0];
        $this->assertStringContainsString('UPDATE [core_exchange_tag_rules]', $sql);
        $this->assertStringContainsString('[hit_count] = [hit_count] + 1', $sql);
        $this->assertSame(7, $ruleId);
    }

    public function testConflictingLearnedRuleIsDeleted(): void
    {
        $handler = $this->handler(
            $this->aiHead(),
            $this->llmCanonical(tag: 'office.supplies'),
            rule: ['id' => 7, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $ruleId] = $handler->sqlCalls[0];
        $this->assertStringContainsString('DELETE FROM [core_exchange_tag_rules]', $sql);
        $this->assertSame(7, $ruleId);
    }

    public function testConflictingUserRuleIsNoOp(): void
    {
        $handler = $this->handler(
            $this->aiHead(),
            $this->llmCanonical(tag: 'office.supplies'),
            rule: ['id' => 7, 'tag' => 'vehicle.fuel', 'origin' => 'user'],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testRuleSourcedTagIsNoOp(): void
    {
        // Učí se jen z LLM štítků — rule-sourced štítek už pravidlo má.
        $handler = $this->handler($this->aiHead(), $this->llmCanonical(tagSource: 'rule'));

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testMissingCompanyIdIsNoOp(): void
    {
        $handler = $this->handler($this->aiHead(), $this->llmCanonical(companyId: null));

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testNonAiLineageIsNoOp(): void
    {
        $handler = $this->handler(
            ['source_kind' => 'manual', 'source_message' => 0],
            $this->llmCanonical(),
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testIsdocLineageWithLlmTagInsertsLearnedRule(): void
    {
        // ISDOC import prochází touž obsahovou eskalací (#81 D2) — potvrzený
        // ISDOC doklad s LLM štítkem učí pravidlo stejně jako AI extrakce.
        $handler = $this->handler(
            ['source_kind' => 'isdoc', 'source_message' => 678],
            $this->llmCanonical(),
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $companyId, $tag, $origin] = $handler->sqlCalls[0];
        $this->assertStringContainsString('INSERT INTO [core_exchange_tag_rules]', $sql);
        $this->assertSame('12345678', $companyId);
        $this->assertSame('vehicle.fuel', $tag);
        $this->assertSame('learned', $origin);
    }

    public function testIsdocLineageWithRuleSourcedTagIsNoOp(): void
    {
        // Podmínka tagSource = 'llm' platí i pro ISDOC (D2) — rule štítek
        // pravidlo už má.
        $handler = $this->handler(
            ['source_kind' => 'isdoc', 'source_message' => 678],
            $this->llmCanonical(tagSource: 'rule'),
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testDirectCloseInsertsLearnedRule(): void
    {
        // „Vystavit a uzavřít“ — doklad vzniká rovnou ve 40, přechod 0 → 40.
        $handler = $this->handler($this->aiHead(), $this->llmCanonical());

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 0, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $companyId, $tag, $origin] = $handler->sqlCalls[0];
        $this->assertStringContainsString('INSERT INTO [core_exchange_tag_rules]', $sql);
        $this->assertSame('12345678', $companyId);
        $this->assertSame('vehicle.fuel', $tag);
        $this->assertSame('learned', $origin);
    }

    public function testDirectCloseWithoutMessageLineageIsNoOp(): void
    {
        // Import ve 40 (old = 0) nemá AI/ISDOC lineage se zprávou — neučí se.
        $handler = $this->handler(
            ['source_kind' => 'manual', 'source_message' => null],
            $this->llmCanonical(),
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 0, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testOtherTransitionsAreNoOp(): void
    {
        $handler = $this->handler($this->aiHead(), $this->llmCanonical());

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 80, 40);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 90);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 0, 90);

        $this->assertSame([], $handler->sqlCalls);
    }
}
