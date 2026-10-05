<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;

/**
 * Předmět a tělo e-mailu k tisku (#90 D37) — sdílené šablony dokladů
 * z repozitáře nad fixture `PrintData`, ve všech jazycích tisku.
 */
class PrintEmailRendererTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    private static function modules(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
    }

    private static function invoice(): PrintDefinition
    {
        return PrintDefinition::fromArray(PrintDefinitionTest::declaration([
            'sendPurpose'     => 'invoices',
            'recipientPerson' => 'partner',
        ]), 'docs.invoicesOut');
    }

    /** @param ?callable(array<string, mixed>): array<string, mixed> $modify */
    private static function printData(string $language = 'cs', ?callable $modify = null): PrintData
    {
        $envelope = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Prints/invoice.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $envelope['language'] = $language;
        if ($modify !== null) {
            $envelope = $modify($envelope);
        }
        return PrintData::fromArray($envelope);
    }

    private static function render(PrintData $data, ?ModulePathResolver $modules = null): \Shipard\Core\Prints\PrintEmail
    {
        $paths = new PrintTemplatePaths($modules ?? self::modules());

        return (new PrintEmailRenderer($paths, new PrintTwigFactory($paths)))->render(
            self::invoice(),
            $data,
            (new PrintCatalogLoader($paths))->translator(self::invoice(), $data->language),
        );
    }

    public function testCzechInvoiceEmail(): void
    {
        $email = self::render(self::printData());

        $this->assertSame('Faktura – daňový doklad IT-PRINT-INV — Tiskárna Vzorová s.r.o.', $email->subject);
        // Částky a data formátují filtry tisku (nedělitelné mezery).
        $this->assertSame(
            "Dobrý den,\n\n"
            . "v příloze najdete doklad: Faktura – daňový doklad IT-PRINT-INV.\n\n"
            . "Částka k úhradě: 810,80 EUR\n"
            . "Datum splatnosti: 14. 10. 2026\n\n"
            . "S pozdravem\nTiskárna Vzorová s.r.o.\n",
            str_replace(self::NBSP, ' ', $email->body),
        );
    }

    public function testEveryPrintLanguageHasItsOwnTexts(): void
    {
        $greetings = [];
        foreach (PrintLanguageResolver::LANGUAGES as $language) {
            $email = self::render(self::printData($language));

            $greetings[$language] = strtok($email->body, "\n");
            $this->assertStringNotContainsString('email.', $email->subject . $email->body, "{$language}: nepřeložený klíč");
        }

        $this->assertSame(
            ['cs' => 'Dobrý den,', 'en' => 'Hello,', 'sk' => 'Dobrý deň,', 'de' => 'Guten Tag,'],
            $greetings,
        );
    }

    public function testDocumentPaidInCashHasNoAmountDue(): void
    {
        $email = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['data']['payment']['bankTransfer'] = false;
            return $envelope;
        }));

        $this->assertStringNotContainsString('Částka k úhradě', $email->body);
        $this->assertStringNotContainsString('Datum splatnosti', $email->body);
        $this->assertStringNotContainsString("\n\n\n", $email->body, 'vynechaný blok nenechá díru');
    }

    public function testSubjectNeverCarriesLineBreaks(): void
    {
        // Název firmy ze snapshotu s koncem řádku by do zprávy podstrčil hlavičku.
        $email = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['data']['supplier']['name'] = "Firma s.r.o.\r\nBcc: nekdo@jinde.example";
            return $envelope;
        }));

        $this->assertStringNotContainsString("\n", $email->subject);
        $this->assertStringNotContainsString("\r", $email->subject);
        $this->assertStringEndsWith('Firma s.r.o. Bcc: nekdo@jinde.example', $email->subject);
    }

    public function testPlainTextIsNotHtmlEscaped(): void
    {
        $email = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['data']['supplier']['name'] = 'Novák & syn <obchod>';
            return $envelope;
        }));

        $this->assertStringEndsWith('— Novák & syn <obchod>', $email->subject);
        $this->assertStringContainsString("S pozdravem\nNovák & syn <obchod>\n", $email->body);
    }

    // ── uživatelské texty (#90 D49) ─────────────────────────────────────────

    public function testUserTextsOverrideDefaultSubjectAndBody(): void
    {
        $email = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['texts'] = [
                'emailSubject' => 'Vaše faktura IT-PRINT-INV',
                'emailBody'    => "Dobrý den,\r\n\r\n\r\n\r\nposíláme fakturu.  \nS pozdravem",
            ];
            return $envelope;
        }));

        $this->assertSame('Vaše faktura IT-PRINT-INV', $email->subject);
        // Tělo projde stejnou úpravou jako výchozí šablona.
        $this->assertSame("Dobrý den,\n\nposíláme fakturu.\nS pozdravem\n", $email->body);
    }

    public function testOnlyOverriddenPartIsReplaced(): void
    {
        $default = self::render(self::printData());

        $subjectOnly = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['texts'] = ['emailSubject' => 'Vlastní předmět'];
            return $envelope;
        }));
        $this->assertSame('Vlastní předmět', $subjectOnly->subject);
        $this->assertSame($default->body, $subjectOnly->body);

        $bodyOnly = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['texts'] = ['emailBody' => 'Vlastní text', 'footer' => '<div class="print-text"><p>x</p></div>'];
            return $envelope;
        }));
        $this->assertSame($default->subject, $bodyOnly->subject);
        $this->assertSame("Vlastní text\n", $bodyOnly->body);
    }

    public function testUserSubjectNeverCarriesLineBreaks(): void
    {
        $email = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['texts'] = ['emailSubject' => "Faktura\r\nBcc: attacker@example.com"];
            return $envelope;
        }));

        $this->assertSame('Faktura Bcc: attacker@example.com', $email->subject);
    }

    public function testBlankUserTextKeepsDefaultTemplate(): void
    {
        $default = self::render(self::printData());
        $email   = self::render(self::printData(modify: static function (array $envelope): array {
            $envelope['texts'] = ['emailSubject' => " \n ", 'emailBody' => "  \n"];
            return $envelope;
        }));

        $this->assertSame($default->subject, $email->subject);
        $this->assertSame($default->body, $email->body);
    }

    public function testPrintCanOverrideSharedTemplatesInItsOwnDirectory(): void
    {
        // Kopie modulů s vlastní šablonou předmětu v adresáři tisku faktury.
        $this->tmpDir = sys_get_temp_dir() . '/shpd-email-tpl-' . bin2hex(random_bytes(6));
        $repo = dirname(__DIR__, 4) . '/modules';
        foreach (['docs/core', 'docs/invoicesOut'] as $module) {
            mkdir($this->tmpDir . '/' . $module, 0755, true);
            exec('cp -r ' . escapeshellarg("{$repo}/{$module}/.") . ' ' . escapeshellarg("{$this->tmpDir}/{$module}"));
        }
        file_put_contents(
            $this->tmpDir . '/docs/invoicesOut/prints/invoice/' . PrintEmailRenderer::SUBJECT_TEMPLATE,
            'Vyúčtování {{ data.document.number }}',
        );

        $email = self::render(self::printData(), new ModulePathResolver([$this->tmpDir]));

        $this->assertSame('Vyúčtování IT-PRINT-INV', $email->subject);
        $this->assertStringStartsWith('Dobrý den,', $email->body, 'tělo zůstává sdílené');
    }

    public function testPrintWithoutEmailTemplatesIsDeclarationError(): void
    {
        $paths    = new PrintTemplatePaths(self::modules());
        $renderer = new PrintEmailRenderer($paths, new PrintTwigFactory($paths));
        // Tisk bez sdíleného adresáře dokladů e-mailové šablony nemá odkud vzít.
        $bare = PrintDefinition::fromArray(PrintDefinitionTest::declaration(['catalogs' => []]), 'docs.invoicesOut');

        $this->assertFalse($renderer->hasTemplates($bare));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no e-mail templates/');

        $renderer->render($bare, self::printData(), (new PrintCatalogLoader($paths))->translator($bare, 'cs'));
    }
}
