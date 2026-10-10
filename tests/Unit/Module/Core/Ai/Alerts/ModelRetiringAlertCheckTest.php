<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Ai\Alerts\ModelRetirementJudge;
use Shipard\Module\Core\Ai\Alerts\ModelRetiringAlertCheck;
use Shipard\Module\Core\Mail\Analysis\ProfileModelRetiringAlertCheck;

/**
 * „AI model brzy končí“ (tasks/ai-functions.md D42, D47): výslovný model
 * končící do 30 dnů (warning), skončený (error), aktivní nebo vzdálený
 * konec (mlčí); model z mapy úrovní se nehlídá (řádek bez modelu);
 * profil pošty totéž nad vlastní tabulkou; bez katalogu nic.
 */
final class ModelRetiringAlertCheckTest extends TestCase
{
    public const TODAY = '2026-11-05';

    private const MODELS = [
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'prices' => ['input' => 3, 'output' => 15], 'lifecycle' => ['status' => 'active', 'retiresNotBefore' => '2027-02-17']],
        'claude-sonnet-4-5' => ['name' => 'Claude Sonnet 4.5', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-5'],
            'prices' => ['input' => 3, 'output' => 15], 'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6']],
        'claude-haiku-4-5' => ['name' => 'Claude Haiku 4.5', 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-4-5'],
            'prices' => ['input' => 1, 'output' => 5], 'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2027-03-01', 'replacedBy' => 'claude-sonnet-4-6']],
        'claude-opus-4' => ['name' => 'Claude Opus 4', 'platformIds' => ['anthropic' => 'claude-opus-4'],
            'prices' => ['input' => 15, 'output' => 75], 'lifecycle' => ['status' => 'retired', 'retiresAt' => '2026-06-15', 'replacedBy' => 'claude-sonnet-4-6']],
    ];

    private function config(bool $withCatalog = true): ConfigRuntime
    {
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => match ($id) {
            ModelCatalog::CFG_ITEM => $withCatalog ? self::MODELS : null,
            AiFunctionRegistry::CFG_ITEM => ['mail.extraction' => ['name' => 'Vytěžení došlé pošty', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 1]]],
            default => null,
        });
        return $cfg;
    }

    /** @param list<array<string, mixed>> $rows */
    private function functionCheck(array $rows, bool $withCatalog = true, string $language = 'cs'): ModelRetiringAlertCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($rows);
        return new class ($db, $this->config($withCatalog), $language) extends ModelRetiringAlertCheck {
            protected function today(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(ModelRetiringAlertCheckTest::TODAY);
            }
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function profileCheck(array $rows, string $language = 'cs'): ProfileModelRetiringAlertCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($rows);
        return new class ($db, $this->config(), $language) extends ProfileModelRetiringAlertCheck {
            protected function today(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(ModelRetiringAlertCheckTest::TODAY);
            }
        };
    }

    public function testJudgeHorizonAndRetiredState(): void
    {
        $judge = new ModelRetirementJudge(ModelCatalog::fromArray(self::MODELS), new \DateTimeImmutable(self::TODAY));

        $soon = $judge->judge('claude-sonnet-4-5');
        $this->assertNotNull($soon);
        $this->assertFalse($soon['retired']);
        $this->assertSame(25, $soon['daysLeft']);
        $this->assertSame('claude-sonnet-4-6', $soon['replacedBy']);
        $this->assertNotNull($judge->judge('claude-sonnet-4-5-20250929'), 'datová přípona');

        $this->assertNull($judge->judge('claude-haiku-4-5'), 'konec za víc než 30 dnů');
        $this->assertNull($judge->judge('claude-sonnet-4-6'), 'aktivní');
        $this->assertNull($judge->judge('claude-x'), 'mimo katalog');

        $gone = $judge->judge('claude-opus-4');
        $this->assertTrue($gone['retired']);
        $this->assertLessThan(0, $gone['daysLeft']);

        // Na hranici: 30 dnů ještě hlásí, 31 už ne.
        $edge = new ModelRetirementJudge(ModelCatalog::fromArray(self::MODELS), new \DateTimeImmutable('2026-10-31'));
        $this->assertNotNull($edge->judge('claude-sonnet-4-5'));
        $this->assertNull((new ModelRetirementJudge(ModelCatalog::fromArray(self::MODELS), new \DateTimeImmutable('2026-10-30')))->judge('claude-sonnet-4-5'));

        $this->assertStringContainsString('končí 2026-11-30 (za 25 dnů). Náhrada podle katalogu: claude-sonnet-4-6.', ModelRetirementJudge::describe($soon, 'cs'));
        $this->assertStringContainsString('retires on 2026-11-30 (in 25 days). Catalog replacement: claude-sonnet-4-6.', ModelRetirementJudge::describe($soon, 'en'));
        $this->assertStringContainsString('už na platformě skončil (2026-06-15)', ModelRetirementJudge::describe($gone, 'cs'));
    }

    public function testFunctionRowsWithRetiringModelAreReported(): void
    {
        $findings = $this->functionCheck([
            ['id' => 3, 'function_id' => 'mail.extraction', 'model' => 'claude-sonnet-4-5'],
            ['id' => 4, 'function_id' => 'exchange.contentTags', 'model' => 'claude-opus-4'],
            ['id' => 5, 'function_id' => 'chat.default', 'model' => 'claude-haiku-4-5'],
            ['id' => 6, 'function_id' => 'dashboard.summary', 'model' => 'claude-custom-x'],
        ])->run();

        $this->assertCount(2, $findings);
        $this->assertSame('function:mail.extraction', $findings[0]->findingKey);
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame('AI model brzy končí: Vytěžení došlé pošty', $findings[0]->title);
        $this->assertStringContainsString('claude-sonnet-4-5 končí 2026-11-30', $findings[0]->message);
        $this->assertStringContainsString('AI funkce', $findings[0]->message);
        $this->assertSame('claude-sonnet-4-5', $findings[0]->context['model']);

        $this->assertSame('function:exchange.contentTags', $findings[1]->findingKey);
        $this->assertSame('error', $findings[1]->severity);
        $this->assertSame('AI model skončil: exchange.contentTags', $findings[1]->title, 'funkce bez deklarace v registru = id');
    }

    public function testTierModelsAndEmptyCatalogAreSilent(): void
    {
        // Řádky bez výslovného modelu dotaz nevrací (WHERE model IS NOT NULL) — mapu úrovní hlídá repo.
        $this->assertSame([], $this->functionCheck([])->run());
        $this->assertSame([], $this->functionCheck([['id' => 3, 'function_id' => 'mail.extraction', 'model' => 'claude-sonnet-4-5']], withCatalog: false)->run());
        $this->assertSame([], $this->functionCheck([['id' => 3, 'function_id' => 'mail.extraction', 'model' => 'claude-sonnet-4-6']])->run());
    }

    public function testProfilesWithRetiringModelAreReported(): void
    {
        $findings = $this->profileCheck([
            ['id' => 2, 'profile_id' => 'czech_general_s45', 'name' => 'Obecná (4.5)', 'model' => 'claude-sonnet-4-5'],
            ['id' => 3, 'profile_id' => 'fresh', 'name' => 'Nový', 'model' => 'claude-sonnet-4-6'],
        ], 'en')->run();

        $this->assertCount(1, $findings);
        $this->assertSame('profile:czech_general_s45', $findings[0]->findingKey);
        $this->assertSame('AI model retiring soon: profile Obecná (4.5)', $findings[0]->title);
        $this->assertStringContainsString('AI profile', $findings[0]->message);
        $this->assertSame('czech_general_s45', $findings[0]->context['profileId']);
    }
}
