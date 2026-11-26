<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Economy\SalesRegistration\Certificate\LegacyPkcs12Reader;

/**
 * Záložní čtení `.p12` přes `openssl pkcs12 -legacy` (#171 D11): heslo jen
 * na stdin, nikdy v argumentech; rozlišení výsledků podle výstupu openssl.
 */
class LegacyPkcs12ReaderTest extends TestCase
{
    /**
     * Čtečka, která příkaz nespouští — zaznamená ho a vrátí danou odpověď.
     *
     * @param array{exitCode: int, stdout: string, stderr: string}|null $reply
     */
    private function recordingReader(?array $reply, ?string $binary = '/usr/bin/openssl'): LegacyPkcs12Reader
    {
        return new class($reply, $binary) extends LegacyPkcs12Reader {
            /** @var list<array{argv: list<string>, stdin: string}> */
            public array $runs = [];

            public function __construct(private readonly ?array $reply, private readonly ?string $binary)
            {
            }

            protected function findBinary(): ?string
            {
                return $this->binary;
            }

            protected function run(array $argv, #[\SensitiveParameter] string $stdin): ?array
            {
                $this->runs[] = ['argv' => $argv, 'stdin' => $stdin];
                return $this->reply;
            }
        };
    }

    public function testPasswordGoesToStdinAndNeverToArguments(): void
    {
        $reader = $this->recordingReader(['exitCode' => 0, 'stdout' => "-----BEGIN PRIVATE KEY-----\n…", 'stderr' => '']);

        $result = $reader->read('/tmp/upload.p12', 'Tajné heslo 123');

        $this->assertSame(LegacyPkcs12Reader::OUTCOME_OK, $result['outcome']);
        $this->assertCount(1, $reader->runs);
        $this->assertSame(
            ['/usr/bin/openssl', 'pkcs12', '-legacy', '-nodes', '-clcerts', '-in', '/tmp/upload.p12', '-passin', 'stdin'],
            $reader->runs[0]['argv'],
        );
        $this->assertSame("Tajné heslo 123\n", $reader->runs[0]['stdin']);
        foreach ($reader->runs[0]['argv'] as $argument) {
            $this->assertStringNotContainsString('Tajné heslo', $argument);
        }
    }

    public function testOutcomesFollowTheOutputOfOpenssl(): void
    {
        $cases = [
            LegacyPkcs12Reader::OUTCOME_WRONG_PASSWORD => ['exitCode' => 1, 'stdout' => '', 'stderr' => "Mac verify error: invalid password?\n"],
            LegacyPkcs12Reader::OUTCOME_UNREADABLE     => ['exitCode' => 1, 'stdout' => '', 'stderr' => "error:0680008E:asn1 encoding routines::not enough data\n"],
            LegacyPkcs12Reader::OUTCOME_UNAVAILABLE    => null,
        ];
        foreach ($cases as $outcome => $reply) {
            $result = $this->recordingReader($reply)->read('/tmp/x.p12', 'heslo');
            $this->assertSame(['outcome' => $outcome, 'pem' => ''], $result);
        }

        // Úspěšný kód bez PEM bloku není převzatý certifikát.
        $empty = $this->recordingReader(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])->read('/tmp/x.p12', 'heslo');
        $this->assertSame(LegacyPkcs12Reader::OUTCOME_UNREADABLE, $empty['outcome']);
    }

    public function testMissingOpensslProgramIsReportedWithoutRunningAnything(): void
    {
        $reader = $this->recordingReader(['exitCode' => 0, 'stdout' => 'x', 'stderr' => ''], binary: null);

        $this->assertSame(LegacyPkcs12Reader::OUTCOME_UNAVAILABLE, $reader->read('/tmp/x.p12', 'heslo')['outcome']);
        $this->assertSame([], $reader->runs);
    }

    public function testPasswordWithALineBreakIsNeverSent(): void
    {
        // `-passin stdin` čte první řádek — předalo by se jiné heslo.
        $reader = $this->recordingReader(['exitCode' => 0, 'stdout' => '-----BEGIN x', 'stderr' => '']);

        $this->assertSame(LegacyPkcs12Reader::OUTCOME_WRONG_PASSWORD, $reader->read('/tmp/x.p12', "první\ndruhý")['outcome']);
        $this->assertSame([], $reader->runs);
    }

    public function testRealOpensslReadsThePlaygroundFile(): void
    {
        if (UpdateEnvironment::findTool('openssl') === null) {
            $this->markTestSkipped('Program openssl není na tomto stroji.');
        }
        $reader = new LegacyPkcs12Reader();

        $ok = $reader->read(TestCertificates::PLAYGROUND_P12, TestCertificates::PLAYGROUND_PASSWORD);
        $this->assertSame(LegacyPkcs12Reader::OUTCOME_OK, $ok['outcome']);
        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $ok['pem']);
        $this->assertStringContainsString('PRIVATE KEY-----', $ok['pem']);

        $this->assertSame(
            LegacyPkcs12Reader::OUTCOME_WRONG_PASSWORD,
            $reader->read(TestCertificates::PLAYGROUND_P12, 'spatne')['outcome'],
        );
        $this->assertSame(
            LegacyPkcs12Reader::OUTCOME_UNREADABLE,
            $reader->read(__FILE__, 'heslo')['outcome'],
            'soubor, který není PKCS#12',
        );
    }
}
