<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\Analysis\PreparedAttachment;
use Shipard\Module\Core\Mail\Analysis\PromptRenderer;
use Shipard\Module\Core\Mail\Analysis\PromptRenderException;

/**
 * Prompt z `prompt_template` profilu (D17): dodávaná šablona se vykreslí,
 * sandbox a `strict_variables` hlásí výjimkou, emulace Jinja
 * `trim_blocks` / `lstrip_blocks` drží výstup shodný s démonem.
 */
final class PromptRendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'subject' => 'Faktura č. 2026000123',
            'sender_email' => 'acc@example.com',
            'sender_name' => 'Účetní',
            'received_at' => '2026-04-26T10:00:00',
            'body_plain' => 'V příloze faktura.',
            'body_html' => null,
        ];
    }

    private function pdf(int $ndx, string $name, int $bytes = 3072): PreparedAttachment
    {
        return new PreparedAttachment($ndx, PreparedAttachment::KIND_PDF, $name, 'application/pdf', base64_encode(str_repeat('P', $bytes)));
    }

    public function testShippedProfileTemplateRenders(): void
    {
        $profile = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/core/mail/profiles/czech_general.jsonc');
        $this->assertIsArray($profile);

        $out = new PromptRenderer()->render(
            (string) $profile['prompt_template'],
            $this->message(),
            [$this->pdf(501, 'invoice.pdf'), new PreparedAttachment(502, PreparedAttachment::KIND_TEXT, 'note.txt', 'text/plain', null, 'hi')],
            $profile['output_schema'],
        );

        $this->assertStringContainsString('- Předmět: Faktura č. 2026000123', $out);
        // Jinja2 `trim_blocks` démona stříhá konec řádku za `{% endif %}` (slitek s dalším
        // řádkem) i za `{% endfor %}` — výstup je s ním shodný bajt po bajtu (ověřeno
        // proti jinja2 3.1), včetně tohoto slitku.
        $this->assertStringContainsString("- Odesílatel: acc@example.com (Účetní)- Tělo zprávy: V příloze faktura.\n", $out);
        $this->assertStringContainsString("PŘÍLOHY (2):\n- #501: invoice.pdf (application/pdf, 3.0 KB)\n- #502: note.txt (text/plain, 2 B)\n\nÚKOL:", $out);
        $this->assertStringEndsWith('Nyní analyzuj zprávu a vrať JSON.', $out);
    }

    public function testNullFieldsRenderAsEmptyStrings(): void
    {
        $out = new PromptRenderer()->render(
            '[{{ message.sender_name }}][{{ message.body_html }}]{% if message.sender_name %}X{% endif %}',
            ['subject' => 's', 'sender_email' => 'e', 'sender_name' => null, 'body_plain' => null, 'body_html' => null, 'received_at' => null],
            [],
            [],
        );

        $this->assertSame('[][]', $out);
    }

    public function testUnknownVariableThrows(): void
    {
        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessage('does_not_exist');

        new PromptRenderer()->render('{{ does_not_exist }}', $this->message(), [], []);
    }

    public function testForbiddenTagThrows(): void
    {
        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessage('Tag "include" is not allowed');

        new PromptRenderer()->render('{% include "x" %}', $this->message(), [], []);
    }

    public function testForbiddenFilterThrows(): void
    {
        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessage('Filter "raw" is not allowed');

        new PromptRenderer()->render('{{ message.subject|raw }}', $this->message(), [], []);
    }

    public function testForbiddenFunctionThrows(): void
    {
        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessage('Function "range" is not allowed');

        new PromptRenderer()->render('{{ range(1, 3)|join(",") }}', $this->message(), [], []);
    }

    public function testSyntaxErrorThrows(): void
    {
        $this->expectException(PromptRenderException::class);

        new PromptRenderer()->render('{% if %}', $this->message(), [], []);
    }

    public function testAllowedFiltersWork(): void
    {
        $out = new PromptRenderer()->render(
            '{{ attachments|length }}|{{ message.subject|upper|trim }}|{{ missing|default("d") }}|{{ ["a","b"]|join("+") }}|{{ "X"|lower }}',
            ['subject' => ' s '] + $this->message(),
            [$this->pdf(1, 'a.pdf')],
            [],
        );

        $this->assertSame('1|S|d|a+b|x', $out);
    }

    public function testJinjaCompatibleStripsIndentBeforeBlockTagsOnly(): void
    {
        $source = "A\n{% if x %}\n  B\n  {% endif %}\n  C {{ y }}\n";

        $this->assertSame("A\n{% if x %}\n  B\n{% endif %}\n  C {{ y }}\n", PromptRenderer::jinjaCompatible($source));
    }

    public function testBlockWhitespaceMatchesJinjaTrimAndLstripBlocks(): void
    {
        // Jinja2 (trim_blocks + lstrip_blocks) i Twig (vlastní trim) dají "A\n  B\nC".
        $out = new PromptRenderer()->render(
            "A\n{% if message.subject %}\n  B\n  {% endif %}\nC{% for a in attachments %}\n- {{ a.ndx }}\n{% endfor %}\n\nD",
            $this->message(),
            [$this->pdf(1, 'a.pdf'), $this->pdf(2, 'b.pdf')],
            [],
        );

        $this->assertSame("A\n  B\nC- 1\n- 2\n\nD", $out);
    }

    public function testHumanSizeMatchesDaemon(): void
    {
        $this->assertSame('0 B', PromptRenderer::humanSize(0));
        $this->assertSame('1023 B', PromptRenderer::humanSize(1023));
        $this->assertSame('1.0 KB', PromptRenderer::humanSize(1536));
        $this->assertSame('3.0 KB', PromptRenderer::humanSize(3072));
        $this->assertSame('2.0 MB', PromptRenderer::humanSize(2 * 1024 * 1024 + 5000));
        $this->assertSame('1.0 GB', PromptRenderer::humanSize(1024 ** 3));
    }
}
