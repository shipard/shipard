<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\PrintSendCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\SendDraft;
use Shipard\Module\Core\Mail\Sent\SendRequest;
use Shipard\Module\Core\Mail\Sent\SendResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestablePrintSendCommand extends PrintSendCommand
{
    protected function getDataSourceDir(): string
    {
        return sys_get_temp_dir();
    }
}

/** `shpd-ds print-send` — návrh (`--dry-run`) a odeslání záznamu z příkazové řádky. */
class PrintSendCommandTest extends TestCase
{
    /**
     * Server příkazu: výchozí je dev server s vypnutou pojistkou — jediná
     * kombinace, kde je `--to` povinné (#95 D8).
     *
     * @param ?array<string, mixed> $safety Sekce `mail.safety`; null = chybí.
     */
    private function server(string $mode = 'development', ?array $safety = ['mode' => 'off']): ServerConfig
    {
        $data = ['mode' => $mode] + ($safety !== null ? ['mail' => ['safety' => $safety]] : []);

        $server = $this->createMock(ServerConfig::class);
        $server->method('getMode')->willReturn($mode);
        $server->method('getMailSafety')->willReturn(MailSafetyConfig::fromServerData($data));
        return $server;
    }

    private function tester(RecordSendService $service, ?ServerConfig $server = null): CommandTester
    {
        return new CommandTester(new TestablePrintSendCommand(
            $this->createMock(DataSourceConfig::class),
            $service,
            $server ?? $this->server(),
        ));
    }

    private function draft(): SendDraft
    {
        return new SendDraft(
            printId: 'docs.invoicesOut.invoice',
            recordId: 55,
            table: 'docs_core_heads',
            language: 'cs',
            purpose: 'invoices',
            targetLabel: 'Faktura – daňový doklad 2260011',
            fileName: 'faktura-2260011.pdf',
            recipientPerson: ['id' => 12, 'name' => 'Odběratel s.r.o.'],
            to: [['email' => 'ucetni@odberatel.example', 'name' => 'Účtárna', 'source' => 'contact', 'label' => 'Kontakt Účtárna']],
            cc: [],
            from: ['email' => 'fakturace@firma.example', 'name' => 'Naše firma s.r.o.', 'source' => 'default'],
            allowedSenders: [['email' => 'fakturace@firma.example', 'source' => 'default']],
            subject: 'Faktura – daňový doklad 2260011 — Naše firma s.r.o.',
            body: "Dobrý den,\n",
            attachments: [],
            mergeAttachments: false,
            messages: [],
        );
    }

    public function testDryRunPrintsDraftAndCreatesNothing(): void
    {
        $captured = null;
        $service  = $this->createMock(RecordSendService::class);
        $service->method('prepare')->willReturnCallback(function (SendRequest $request) use (&$captured): SendDraft {
            $captured = $request;
            return $this->draft();
        });
        $service->expects($this->never())->method('send');

        $tester = $this->tester($service);
        $exit   = $tester->execute(['printId' => 'docs.invoicesOut.invoice', 'recordId' => '55', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $draft = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ucetni@odberatel.example', $draft['to'][0]['email']);
        $this->assertSame('Faktura – daňový doklad 2260011 — Naše firma s.r.o.', $draft['subject']);
        $this->assertTrue($draft['canSend']);
        // Návrh bez --to ukazuje příjemce dohledané z kontaktů.
        $this->assertNull($captured->to);
        $this->assertSame(SendRequest::TRIGGER_CLI, $captured->trigger);
    }

    public function testSendWithoutRecipientIsRefusedBeforeAnythingRuns(): void
    {
        $service = $this->createMock(RecordSendService::class);
        $service->expects($this->never())->method('send');
        $service->expects($this->never())->method('prepare');

        $tester = $this->tester($service);

        // Dev server bez pojistky: zpráva by došla adresám partnerů z kontaktů.
        $this->assertSame(
            Command::INVALID,
            $tester->execute(['printId' => 'docs.invoicesOut.invoice', 'recordId' => '55']),
        );
        $this->assertStringContainsString('--to is required', $tester->getDisplay());
    }

    /** @return array<string, array{ServerConfig}> */
    private function serversWhereRecipientIsOptional(): array
    {
        $unreadable = $this->createMock(ServerConfig::class);
        $unreadable->method('getMailSafety')->willThrowException(new \RuntimeException('Config file not found'));

        return [
            'dev server, default drop'      => [$this->server('development', null)],
            'dev server, redirect'          => [$this->server('development', ['mode' => 'redirect', 'redirectTo' => 'testy@firma.example'])],
            'production, no safety'         => [$this->server('production', null)],
            'production, safety off'        => [$this->server('production', ['mode' => 'off'])],
            'production (test), redirect'   => [$this->server('production', ['mode' => 'redirect', 'redirectTo' => 'testy@firma.example'])],
            'dev server, invalid section'   => [$this->server('development', ['mode' => 'redirect'])],
            'server.json cannot be read'    => [$unreadable],
        ];
    }

    public function testRecipientIsOptionalWithActiveSafetyOrOnProduction(): void
    {
        foreach ($this->serversWhereRecipientIsOptional() as $case => [$server]) {
            $captured = null;
            $service  = $this->createMock(RecordSendService::class);
            $service->method('send')->willReturnCallback(function (SendRequest $request) use (&$captured): SendResult {
                $captured = $request;
                return new SendResult(701, 31, 'sent');
            });

            $tester = $this->tester($service, $server);
            $exit   = $tester->execute(['printId' => 'docs.invoicesOut.invoice', 'recordId' => '55']);

            $this->assertSame(Command::SUCCESS, $exit, $case);
            // Bez --to rozhodnou o příjemcích kontakty partnera.
            $this->assertNotNull($captured, $case);
            $this->assertNull($captured->to, $case);
        }
    }

    public function testOutputSaysWhereMailGoesUnderSafety(): void
    {
        foreach ([
            [$this->server('development', ['mode' => 'redirect', 'redirectTo' => 'testy@firma.example']), 'Mail safety: redirect — the message goes to testy@firma.example'],
            [$this->server('development', ['mode' => 'allowlist', 'allow' => ['@firma.example']]), 'Mail safety: allowlist — only allowed addresses receive the message'],
            [$this->server('development', null), 'Mail safety: drop — the message will not be sent'],
        ] as [$server, $line]) {
            $service = $this->createMock(RecordSendService::class);
            // Zpráva čeká ve frontě — výsledek pojistky ještě není znám.
            $service->method('send')->willReturn(new SendResult(701, 31, 'queued'));

            $tester = $this->tester($service, $server);
            $tester->execute(['printId' => 'docs.invoicesOut.invoice', 'recordId' => '55']);

            $this->assertStringContainsString($line, $tester->getDisplay());
        }
    }

    public function testOutputIsSilentAboutSafetyWhenItIsOff(): void
    {
        $service = $this->createMock(RecordSendService::class);
        $service->method('send')->willReturn(new SendResult(701, 31, 'queued'));

        $tester = $this->tester($service, $this->server('production', null));
        $tester->execute(['printId' => 'docs.invoicesOut.invoice', 'recordId' => '55']);

        $this->assertStringNotContainsString('Mail safety', $tester->getDisplay());
    }

    public function testSendPassesAddressesSenderAndLanguage(): void
    {
        $captured = null;
        $service  = $this->createMock(RecordSendService::class);
        $service->method('send')->willReturnCallback(function (SendRequest $request) use (&$captured): SendResult {
            $captured = $request;
            return new SendResult(701, 31, 'queued', [
                ['severity' => 'warning', 'code' => 'payment.qrNoAccount', 'text' => 'QR platba nevznikla.'],
            ]);
        });

        $tester = $this->tester($service);
        $exit   = $tester->execute([
            'printId'    => 'docs.invoicesOut.invoice',
            'recordId'   => '55',
            '--to'       => ['test@prijemce.example', 'druhy@prijemce.example'],
            '--cc'       => ['kopie@prijemce.example'],
            '--from'     => 'ucet@firma.example',
            '--language' => 'en',
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString("Sent message #701: outbox #31, transport 'queued'", $tester->getDisplay());
        $this->assertStringContainsString('QR platba nevznikla.', $tester->getDisplay());

        $this->assertSame(55, $captured->recordId);
        $this->assertSame(['test@prijemce.example', 'druhy@prijemce.example'], $captured->to);
        $this->assertSame(['kopie@prijemce.example'], $captured->cc);
        $this->assertSame('ucet@firma.example', $captured->from);
        $this->assertSame('en', $captured->language);
        $this->assertSame(SendRequest::TRIGGER_CLI, $captured->trigger);
    }

    public function testErrorsAreReportedWithExitCode(): void
    {
        foreach ([
            [new RecordSendException(RecordSendException::NO_SENDER, 'Chybí adresa odesílatele.'), Command::FAILURE, 'NO_SENDER: Chybí adresa odesílatele.'],
            [new PrintNotAvailableException('Print is not available'), Command::FAILURE, 'Print is not available'],
            [new PrintNotFoundException("Unknown print 'x'"), Command::INVALID, "Unknown print 'x'"],
        ] as [$exception, $exitCode, $text]) {
            $service = $this->createMock(RecordSendService::class);
            $service->method('send')->willThrowException($exception);

            $tester = $this->tester($service);
            $exit   = $tester->execute(['printId' => 'x', 'recordId' => '55', '--to' => ['test@prijemce.example']]);

            $this->assertSame($exitCode, $exit, $text);
            $this->assertStringContainsString($text, $tester->getDisplay());
        }
    }

    public function testInvalidRecordIdIsRejected(): void
    {
        $tester = $this->tester($this->createMock(RecordSendService::class));

        $this->assertSame(Command::INVALID, $tester->execute(['printId' => 'x', 'recordId' => 'abc', '--dry-run' => true]));
    }
}
