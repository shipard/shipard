<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiEvalCompareCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** `shpd-ds ai-eval-compare`: lidský výpis, --json, odmítnutí jiné sady a špatného souboru. */
final class AiEvalCompareCommandTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return dirname(__DIR__, 4) . "/tests/Fixtures/Eval/{$name}.json";
    }

    private function tester(): CommandTester
    {
        $command = new AiEvalCompareCommand();
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testHumanAndJsonOutput(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['a' => self::fixture('run-a'), 'b' => self::fixture('run-b')]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Porovnání běhů — sada cz-vat-payer', $display);
        $this->assertStringContainsString('documents.clean', $display);
        $this->assertStringContainsString('Pole zlepšená', $display);

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['a' => self::fixture('run-a'), 'b' => self::fixture('run-b'), '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        $this->assertSame('cz-vat-payer', $json['set']['name']);
        $this->assertSame('run-a.json', $json['runs']['a']['file']);
        $this->assertNotEmpty($json['metrics']);
    }

    public function testRefusesDifferentSetsAndUnreadableFiles(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::INVALID, $tester->execute(['a' => self::fixture('run-a'), 'b' => self::fixture('run-other-set')]));
        $this->assertStringContainsString('nejsou nad touž sadou', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(Command::INVALID, $tester->execute(['a' => self::fixture('run-a'), 'b' => '/nonexistent.json']));
        $this->assertStringContainsString('neexistuje', $tester->getDisplay());
    }
}
