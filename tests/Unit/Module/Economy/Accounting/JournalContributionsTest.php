<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalContributor;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;
use Shipard\Module\Economy\Accounting\JournalContributions;

/**
 * Sdílený krok contributorů (#79 D3b): sběr požadavků v pořadí sady,
 * výjimka jednoho contributoru = varování a ostatní běží dál, neplatný
 * návrat = varování; převod požadavku na účet (kategorie → maska →
 * resolver, přesné číslo → resolver, nenalezeno → chybový řádek).
 */
class JournalContributionsTest extends TestCase
{
    private const RULES = [
        'accounts' => [
            ['cat' => 'offbalance.contra', 'accountMask' => '799'],
            ['cat' => 'advances.received', 'accountMask' => ['3249', '324']],
        ],
    ];

    private function context(): JournalSourceContext
    {
        return new JournalSourceContext('doc', 7, '2026-06-10', 3, 'czk');
    }

    private static function request(int $side, ?string $category, ?string $account, float $amount = 100.0): JournalLineRequest
    {
        return new JournalLineRequest($side, $category, $account, 42, 'VS1', null, $amount, $amount, 'text');
    }

    // ── collect ─────────────────────────────────────────────────────────────

    public function testCollectKeepsSetOrderAndFlattensRequests(): void
    {
        $a = self::request(0, 'offbalance.contra', null);
        $b = self::request(1, null, '756100');
        $c = self::request(0, 'advances.received', null);
        $set = new JournalContributorSet([
            new StubContributor([$a, $b]),
            new StubContributor([$c]),
        ]);
        $warnings = [];

        $requests = JournalContributions::collect($set, $this->context(), [], function (string $code, string $message) use (&$warnings): void {
            $warnings[] = $code;
        });

        $this->assertSame([$a, $b, $c], $requests);
        $this->assertSame([], $warnings);
    }

    public function testThrowingContributorYieldsWarningAndOthersStillRun(): void
    {
        $c = self::request(0, 'advances.received', null);
        $set = new JournalContributorSet([
            new StubContributor([], new \RuntimeException('boom')),
            new StubContributor([$c]),
        ]);
        $warnings = [];

        $requests = JournalContributions::collect($set, $this->context(), [], function (string $code, string $message) use (&$warnings): void {
            $warnings[] = [$code, $message];
        });

        $this->assertSame([$c], $requests);
        $this->assertCount(1, $warnings);
        $this->assertSame(JournalContributions::WARNING_CODE, $warnings[0][0]);
        $this->assertStringContainsString('StubContributor', $warnings[0][1]);
        $this->assertStringContainsString('boom', $warnings[0][1]);
    }

    public function testNonRequestReturnValueIsContributorFailure(): void
    {
        $set = new JournalContributorSet([new StubContributor(['not a request'])]);
        $warnings = [];

        $requests = JournalContributions::collect($set, $this->context(), [], function (string $code) use (&$warnings): void {
            $warnings[] = $code;
        });

        $this->assertSame([], $requests);
        $this->assertSame([JournalContributions::WARNING_CODE], $warnings);
    }

    // ── resolveAccount ──────────────────────────────────────────────────────

    /** @param array<string, array{id: int, number: string}|null> $byMask maska → účet */
    private function resolver(array $byMask): AccountMaskResolver
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use ($byMask) {
            $mask = (string) $args[1];
            $row = $byMask[$mask] ?? null;
            return $row === null ? null : new \Dibi\Row($row);
        });
        return new AccountMaskResolver($db);
    }

    public function testCategoryResolvesThroughFirstMask(): void
    {
        $resolver = $this->resolver(['799' => ['id' => 9, 'number' => '799100']]);
        $errors = [];

        $account = JournalContributions::resolveAccount(
            self::request(0, 'offbalance.contra', null), self::RULES, $resolver, '2026-06-10',
            function (string $code) use (&$errors): void { $errors[] = $code; },
        );

        $this->assertSame(['id' => 9, 'number' => '799100'], $account);
        $this->assertSame([], $errors);
    }

    public function testCategoryChainUsesFirstMaskOnly(): void
    {
        // Řetěz ['3249', '324']: bez řádkového kontextu se bere první maska —
        // rozvrh bez 3249 → chybový řádek 3249??, ne fallback na 324.
        $resolver = $this->resolver(['324' => ['id' => 4, 'number' => '324100']]);
        $errors = [];

        $account = JournalContributions::resolveAccount(
            self::request(1, 'advances.received', null), self::RULES, $resolver, '2026-06-10',
            function (string $code) use (&$errors): void { $errors[] = $code; },
        );

        $this->assertSame(['number' => '3249??', 'is_error' => true], $account);
        $this->assertSame(['account_not_found'], $errors);
    }

    public function testUnknownCategoryIsErrorRow(): void
    {
        $errors = [];
        $account = JournalContributions::resolveAccount(
            self::request(0, 'no.such', null), self::RULES, $this->resolver([]), '2026-06-10',
            function (string $code, string $message) use (&$errors): void { $errors[] = [$code, $message]; },
        );

        $this->assertSame(['number' => '??????', 'is_error' => true], $account);
        $this->assertSame('account_not_found', $errors[0][0]);
        $this->assertStringContainsString('no.such', $errors[0][1]);
    }

    public function testExactAccountNumberIsVerifiedInChart(): void
    {
        $resolver = $this->resolver(['756100' => ['id' => 6, 'number' => '756100']]);
        $errors = [];

        $account = JournalContributions::resolveAccount(
            self::request(1, null, '756100'), self::RULES, $resolver, '2026-06-10',
            function (string $code) use (&$errors): void { $errors[] = $code; },
        );

        $this->assertSame(['id' => 6, 'number' => '756100'], $account);
        $this->assertSame([], $errors);
    }

    public function testExactAccountNumberMustMatchExactly(): void
    {
        // Resolver hledá LIKE 'mask%' — pro přesné číslo musí sedět celé
        // (756 by jinak našlo 756100 a zapsalo jiný účet, než contributor chtěl).
        $resolver = $this->resolver(['756' => ['id' => 6, 'number' => '756100']]);
        $errors = [];

        $account = JournalContributions::resolveAccount(
            self::request(1, null, '756'), self::RULES, $resolver, '2026-06-10',
            function (string $code) use (&$errors): void { $errors[] = $code; },
        );

        $this->assertSame(['number' => '756???', 'is_error' => true], $account);
        $this->assertSame(['account_not_found'], $errors);
    }

    public function testMissingExactAccountIsErrorRow(): void
    {
        $errors = [];
        $account = JournalContributions::resolveAccount(
            self::request(1, null, '999999'), self::RULES, $this->resolver([]), '2026-06-10',
            function (string $code) use (&$errors): void { $errors[] = $code; },
        );

        $this->assertSame(['number' => '999999', 'is_error' => true], $account);
        $this->assertSame(['account_not_found'], $errors);
    }

    public function testRequestNeedsExactlyOneAccountSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JournalLineRequest(0, 'offbalance.contra', '799100', null, null, null, 1.0, 1.0, 't');
    }

    public function testRequestRejectsBothSourcesMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JournalLineRequest(0, null, null, null, null, null, 1.0, 1.0, 't');
    }
}

class StubContributor implements JournalContributor
{
    /** @param list<mixed> $requests */
    public function __construct(
        private readonly array $requests,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function contribute(JournalSourceContext $context, array $lines): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->requests;
    }
}
