<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStep;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStepParser;

/** Počty a první selhání z výstupů PHPUnit, `node --test` (TAP), python unittest a skriptů jen s exit kódem. */
final class CodeStepParserTest extends TestCase
{
    public function testPhpUnitOk(): void
    {
        $out = "PHPUnit 12.1.0 by Sebastian Bergmann and contributors.\n\n.....                 5 / 5 (100%)\n\nTime: 00:00.123, Memory: 20.00 MB\n\nOK (9982 tests, 31000 assertions)\n";
        $this->assertSame(['counts' => ['tests' => 9982, 'passed' => 9982, 'failed' => 0], 'firstFailure' => null], CodeStepParser::parse(CodeStep::PARSER_PHPUNIT, $out, 0));
    }

    public function testPhpUnitFailuresAndErrors(): void
    {
        $out = "PHPUnit 12.1.0\n\n..F.E\n\nThere was 1 error:\n\n1) Shipard\\Tests\\Unit\\FooTest::testBoom\nRuntimeException: boom\n\n/repo/tests/Unit/FooTest.php:12\n\nThere was 1 failure:\n\n2) Shipard\\Tests\\Unit\\BarTest::testX\nFailed asserting that false is true.\n\nERRORS!\nTests: 5, Assertions: 7, Errors: 1, Failures: 1.\n";
        $parsed = CodeStepParser::parse(CodeStep::PARSER_PHPUNIT, $out, 2);
        $this->assertSame(['tests' => 5, 'passed' => 3, 'failed' => 2], $parsed['counts']);
        $this->assertSame('Shipard\\Tests\\Unit\\FooTest::testBoom — RuntimeException: boom', $parsed['firstFailure']);

        $issues = "OK, but there were issues!\nTests: 10, Assertions: 12, Deprecations: 2.\n";
        $this->assertSame(['tests' => 10, 'passed' => 10, 'failed' => 0], CodeStepParser::parse(CodeStep::PARSER_PHPUNIT, $issues, 0)['counts']);
    }

    public function testNodeTestTap(): void
    {
        $out = "\n> shipard-frontend@0.1.0 test\n> node --test 'tests/Unit/**/*.test.mjs'\n\nTAP version 13\n# Subtest: routes\n    ok 1 - matches\n    not ok 2 - builds path\n      ---\n      error: 'x'\n      ...\n# tests 12\n# suites 3\n# pass 11\n# fail 1\n# cancelled 0\n";
        $parsed = CodeStepParser::parse(CodeStep::PARSER_NODE_TEST, $out, 1);
        $this->assertSame(['tests' => 12, 'passed' => 11, 'failed' => 1], $parsed['counts']);
        $this->assertSame('builds path', $parsed['firstFailure']);
        $this->assertSame(['counts' => null, 'firstFailure' => null], CodeStepParser::parse(CodeStep::PARSER_NODE_TEST, "nothing\n", 0));
    }

    public function testPythonUnittest(): void
    {
        $ok = "...........\n----------------------------------------------------------------------\nRan 11 tests in 0.004s\n\nOK\n";
        $this->assertSame(['counts' => ['tests' => 11, 'passed' => 11, 'failed' => 0], 'firstFailure' => null], CodeStepParser::parse(CodeStep::PARSER_UNITTEST, $ok, 0));

        $bad = "F.E\n======================================================================\nERROR: test_boom (tests.test_x.XTest.test_boom)\n----------------------------------------------------------------------\nTraceback …\n======================================================================\nFAIL: test_y (tests.test_x.XTest.test_y)\n----------------------------------------------------------------------\nRan 3 tests in 0.1s\n\nFAILED (failures=1, errors=1)\n";
        $parsed = CodeStepParser::parse(CodeStep::PARSER_UNITTEST, $bad, 1);
        $this->assertSame(['tests' => 3, 'passed' => 1, 'failed' => 2], $parsed['counts']);
        $this->assertSame('ERROR: test_boom (tests.test_x.XTest.test_boom)', $parsed['firstFailure']);
    }

    public function testExitCodeOnlyUsesFirstMeaningfulLineOnFailure(): void
    {
        $out = "\n> shipard-frontend@0.1.0 check:i18n\n> node scripts/check-i18n.mjs\n\nnpm warn something\nMissing key in en: testing.round.title\nDone with 1 problem\n";
        $this->assertSame(['counts' => null, 'firstFailure' => 'Missing key in en: testing.round.title'], CodeStepParser::parse(CodeStep::PARSER_EXIT_CODE, $out, 1));
        $this->assertSame(['counts' => null, 'firstFailure' => null], CodeStepParser::parse(CodeStep::PARSER_EXIT_CODE, $out, 0));
        $this->assertSame(['counts' => null, 'firstFailure' => null], CodeStepParser::parse(CodeStep::PARSER_EXIT_CODE, "\n\n", 1));
    }

    public function testFirstFailureIsClipped(): void
    {
        $long = str_repeat('x', 500);
        $parsed = CodeStepParser::parse(CodeStep::PARSER_EXIT_CODE, $long . "\n", 1);
        $this->assertSame(300, mb_strlen((string) $parsed['firstFailure']));
        $this->assertStringEndsWith('…', (string) $parsed['firstFailure']);
    }
}
