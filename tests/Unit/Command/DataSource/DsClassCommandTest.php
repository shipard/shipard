<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsClassCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsClassCommand extends DsClassCommand
{
    public string $serverMode = 'development';
    public bool $serverAi = false;
    /** Stdin je terminál — v testech napevno, aby nezáleželo na tom, odkud PHPUnit běží. */
    public bool $terminal = true;

    public function __construct(private readonly string $dsDir)
    {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-16T12:00:00Z');
    }

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function serverOffersAi(): bool
    {
        return $this->serverAi;
    }

    protected function stdinIsTerminal(): bool
    {
        return $this->terminal;
    }
}

/**
 * `shpd-ds ds-class` (#126 D8): show / set realCopy kdykoli / set free jen
 * interaktivně s potvrzením — pojistka proti omylu z MCP mostu a Claude Code.
 */
class DsClassCommandTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd-ds-class-cmd-' . uniqid();
        mkdir($this->dsDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsDir . '/config/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    /** @param array<string, mixed> $extra */
    private function writeMainJson(array $extra = []): void
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function mainJson(): array
    {
        return json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
    }

    private function makeTester(string $serverMode = 'development', bool $serverAi = false, bool $terminal = true): CommandTester
    {
        $cmd = new TestableDsClassCommand($this->dsDir);
        $cmd->serverMode = $serverMode;
        $cmd->serverAi = $serverAi;
        $cmd->terminal = $terminal;
        $cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
        return new CommandTester($cmd);
    }

    // ── show ────────────────────────────────────────────────────────────────

    public function testShowWithoutDataClassReportsTheRealCopyDefault(): void
    {
        $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class:        realCopy (not set in main.json — counts as realCopy)', $display);
        $this->assertStringContainsString('Purpose:           (none)', $display);
        $this->assertStringContainsString('Origin:            ?', $display);
        $this->assertStringContainsString('Server AI:         off (realCopy)', $display);
        $this->assertStringNotContainsString('[WARN]', $display);
    }

    public function testShowExplicitFreeWithServerAi(): void
    {
        $this->writeMainJson([
            'dataClass' => 'free', 'purpose' => 'Ruční zkoušení UI',
            'origin' => 'dataset-seed cz-vat-payer', 'dataClassChanged' => '2026-10-16T10:00:00Z',
        ]);

        $tester = $this->makeTester(serverAi: true);
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'show']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class:        free', $display);
        $this->assertStringContainsString('Purpose:           Ruční zkoušení UI', $display);
        $this->assertStringContainsString('Origin:            dataset-seed cz-vat-payer', $display);
        $this->assertStringContainsString('Changed:           2026-10-16T10:00:00Z', $display);
        $this->assertStringContainsString('Server AI:         on (free data source)', $display);

        $tester = $this->makeTester(serverAi: false);
        $tester->execute([]);
        $this->assertStringContainsString('Server AI:         off (no server AI key — shpd-server dev-ai-key)', $tester->getDisplay());

        $tester = $this->makeTester('production', true);
        $tester->execute([]);
        $this->assertStringNotContainsString('Server AI:', $tester->getDisplay(), 'mimo development řádek chybí');
    }

    public function testShowFlagsAnInvalidValueAndTheLegacyServerAiKey(): void
    {
        // U6: neplatná hodnota = realCopy, zdroj se nezavírá; U8: zaniklý příznak jen varování.
        $this->writeMainJson(['dataClass' => 'Free', 'ai' => ['useServerConnection' => true]]);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class:        realCopy (invalid value "Free" — counts as realCopy)', $display);
        $this->assertStringContainsString('[WARN] main.json still has ai.useServerConnection', $display);
    }

    // ── set realCopy ────────────────────────────────────────────────────────

    public function testSetRealCopyNeedsNoConfirmationAndDropsTheLegacyKey(): void
    {
        $this->writeMainJson(['dataClass' => 'free', 'origin' => 'ds-create', 'ai' => ['useServerConnection' => true, 'other' => 1]]);
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'realCopy'], ['interactive' => false]));
        $this->assertStringContainsString('Data class set to realCopy (origin: ds-class)', $tester->getDisplay());
        $this->assertStringContainsString('Server AI:         off (realCopy)', $tester->getDisplay());

        $config = $this->mainJson();
        $this->assertSame('realCopy', $config['dataClass']);
        $this->assertSame('ds-class', $config['origin']);
        $this->assertSame('2026-10-16T12:00:00Z', $config['dataClassChanged']);
        $this->assertSame(['other' => 1], $config['ai'], 'zaniklý příznak pryč, ostatní klíče ai zůstávají');
        $this->assertSame('shpd_abcdefgh', $config['database_user'], 'ostatní klíče beze změny');
    }

    public function testSetRealCopyOnAnImplicitRealCopyMakesItExplicit(): void
    {
        $this->writeMainJson();
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'realCopy', '--purpose' => 'Ověřování importu']));
        $config = $this->mainJson();
        $this->assertSame('realCopy', $config['dataClass']);
        $this->assertSame('Ověřování importu', $config['purpose']);
        $this->assertStringContainsString('Purpose:           Ověřování importu', $tester->getDisplay());
    }

    // ── set free ────────────────────────────────────────────────────────────

    public function testSetFreeWithoutATerminalFailsAndLeavesTheFileUntouched(): void
    {
        // D8: přes MCP most a Claude Code (neinteraktivní vstup) přechod neprojde.
        $this->writeMainJson(['purpose' => 'Ověřování importu']);
        $before = (string) file_get_contents($this->dsDir . '/config/main.json');
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'value' => 'free'], ['interactive' => false]));
        $this->assertStringContainsString('Marking a data source as free requires an interactive terminal — run the command yourself.', $tester->getDisplay());
        $this->assertSame($before, (string) file_get_contents($this->dsDir . '/config/main.json'));
    }

    public function testSetFreeWithPipedStdinFailsEvenWithAYesOnIt(): void
    {
        // Symfony bez `-n` vstup za neinteraktivní nepovažuje — `echo y | shpd-ds ds-class set free`
        // nesmí projít; kontroluje se, že stdin je terminál.
        $this->writeMainJson();
        $before = (string) file_get_contents($this->dsDir . '/config/main.json');
        $tester = $this->makeTester(terminal: false);
        $tester->setInputs(['y']);

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'value' => 'free']));
        $this->assertStringContainsString('requires an interactive terminal', $tester->getDisplay());
        $this->assertStringNotContainsString('Mark it as free', $tester->getDisplay(), 'dotaz se vůbec nepoloží');
        $this->assertSame($before, (string) file_get_contents($this->dsDir . '/config/main.json'));

        // set realCopy terminál nepotřebuje.
        $tester = $this->makeTester(terminal: false);
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'realCopy']));
        $this->assertSame('realCopy', $this->mainJson()['dataClass']);
    }

    public function testSetFreeDeclinedAborts(): void
    {
        $this->writeMainJson(['purpose' => 'Ověřování importu']);
        $before = (string) file_get_contents($this->dsDir . '/config/main.json');
        $tester = $this->makeTester();
        $tester->setInputs(['n']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'free']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString("Data source 'Test DS' is a real copy (purpose: Ověřování importu). Mark it as free", $display);
        $this->assertStringContainsString('Aborted.', $display);
        $this->assertSame($before, (string) file_get_contents($this->dsDir . '/config/main.json'));
    }

    public function testSetFreeConfirmedWritesTheClass(): void
    {
        $this->writeMainJson(['ai' => ['useServerConnection' => true]]);
        $tester = $this->makeTester(serverAi: true);
        $tester->setInputs(['y']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'free', '--purpose' => 'Ruční zkoušení UI']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('(purpose: none)', $display, 'dotaz uvádí dosavadní účel');
        $this->assertStringContainsString('Data class set to free (origin: ds-class)', $display);
        $this->assertStringContainsString('Server AI:         on (free data source)', $display);

        $config = $this->mainJson();
        $this->assertSame('free', $config['dataClass']);
        $this->assertSame('Ruční zkoušení UI', $config['purpose']);
        $this->assertSame('ds-class', $config['origin']);
        $this->assertArrayNotHasKey('ai', $config, 'první zápis třídy odebere zaniklý příznak');
    }

    // ── stejná třída ────────────────────────────────────────────────────────

    public function testSameClassIsUnchangedAndOnlyThePurposeIsWritten(): void
    {
        $this->writeMainJson(['dataClass' => 'free', 'origin' => 'ds-create', 'dataClassChanged' => '2026-10-01T00:00:00Z', 'purpose' => 'staré']);

        $tester = $this->makeTester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'free'], ['interactive' => false]));
        $this->assertStringContainsString('unchanged — data class is already free.', $tester->getDisplay());
        $this->assertSame('staré', $this->mainJson()['purpose']);

        // set free --purpose na volném zdroji nic nepotvrzuje (D8) a mění jen účel.
        $tester = $this->makeTester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'free', '--purpose' => 'nové'], ['interactive' => false]));
        $this->assertStringContainsString('unchanged — data class is already free; purpose set to nové.', $tester->getDisplay());
        $config = $this->mainJson();
        $this->assertSame('nové', $config['purpose']);
        $this->assertSame('ds-create', $config['origin'], 'původ se nemění');
        $this->assertSame('2026-10-01T00:00:00Z', $config['dataClassChanged'], 'čas se nemění');

        $tester = $this->makeTester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'value' => 'free', '--purpose' => ''], ['interactive' => false]));
        $this->assertStringContainsString('purpose removed', $tester->getDisplay());
        $this->assertArrayNotHasKey('purpose', $this->mainJson());
    }

    // ── chyby ───────────────────────────────────────────────────────────────

    public function testInvalidPurposeUnknownClassAndUnknownActionFail(): void
    {
        $this->writeMainJson(['dataClass' => 'realCopy']);
        $before = (string) file_get_contents($this->dsDir . '/config/main.json');

        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'value' => 'realCopy', '--purpose' => "a\nb"]));
        $this->assertStringContainsString('Invalid --purpose: Purpose must be a single line', $tester->getDisplay());

        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'value' => 'public']));
        $this->assertStringContainsString('Usage: ds-class set free|realCopy', $tester->getDisplay());

        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'toggle']));
        $this->assertStringContainsString("Unknown action 'toggle'", $tester->getDisplay());

        $this->assertSame($before, (string) file_get_contents($this->dsDir . '/config/main.json'));
    }

    public function testMissingOrBrokenMainJsonFails(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());

        file_put_contents($this->dsDir . '/config/main.json', '{not json');
        $tester = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('config/main.json is unusable', $tester->getDisplay());
    }
}
