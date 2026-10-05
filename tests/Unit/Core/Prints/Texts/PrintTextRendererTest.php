<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Texts\PrintTextRenderer;

/**
 * Uživatelské texty → obsah slotů (#90 D50): sloty stránky přes Markdown,
 * e-mailové jako prostý text, chybný text se vynechá s varováním.
 */
class PrintTextRendererTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    private static function data(): PrintData
    {
        return PrintData::fromArray([
            'printId'  => 'docs.invoicesOut.invoice',
            'version'  => 1,
            'language' => 'cs',
            'record'   => ['table' => 'docs_core_heads', 'id' => 123, 'docState' => 40],
            'meta'     => ['title' => 'Faktura 2026000123', 'fileName' => 'faktura.pdf'],
            'branding' => ['logo' => 'logo.png'],
            'data'     => [
                'document' => ['number' => '2026000123'],
                'customer' => ['name' => 'Firma *Hvězda* <b>s.r.o.</b>'],
                'dates'    => ['due' => '2026-10-16'],
                'payment'  => ['amountToPay' => 1210.5, 'currency' => 'CZK'],
            ],
        ]);
    }

    /** @param array<string, list<array{id: int, text: string}>> $texts */
    private static function render(array $texts, ?PrintTranslator $translator = null): \Shipard\Core\Prints\Texts\RenderedPrintTexts
    {
        return (new PrintTextRenderer())->render($texts, self::data(), $translator ?? new PrintTranslator([], 'cs'));
    }

    public function testPageSlotGoesThroughMarkdownIntoItsOwnBlock(): void
    {
        $result = self::render(['afterRows' => [
            ['id' => 1, 'text' => 'Příští týden máme **dovolenou**.'],
        ]]);

        $this->assertSame(
            ['afterRows' => '<div class="print-text"><p>Příští týden máme <strong>dovolenou</strong>.</p></div>'],
            $result->texts,
        );
        $this->assertSame([], $result->messages);
    }

    public function testVariablesAreFormattedAndEscapedForMarkdownAndHtml(): void
    {
        $result = self::render(['footer' => [[
            'id'   => 1,
            'text' => 'Doklad {{ data.document.number }} pro {{ data.customer.name }}: '
                . '**{{ data.payment.amountToPay|money(data.payment.currency) }}** do {{ data.dates.due|date }}.',
        ]]]);

        // Hvězdičky z názvu firmy nejsou zvýraznění a značky nejsou HTML;
        // zvýraznění, které napsal uživatel kolem částky, platí.
        $this->assertSame(
            '<div class="print-text"><p>Doklad 2026000123 pro Firma *Hvězda* &lt;b&gt;s.r.o.&lt;/b&gt;: '
            . '<strong>1' . self::NBSP . '210,50' . self::NBSP . 'CZK</strong> do 16.' . self::NBSP . '10.' . self::NBSP . '2026.</p></div>',
            $result->texts['footer'],
        );
    }

    public function testAllTextsOfSlotAreJoinedInGivenOrder(): void
    {
        $result = self::render([
            'footer' => [['id' => 5, 'text' => 'první'], ['id' => 2, 'text' => 'druhý']],
            'header' => [['id' => 9, 'text' => 'nahoře']],
        ]);

        $this->assertSame(
            "<div class=\"print-text\"><p>první</p></div>\n<div class=\"print-text\"><p>druhý</p></div>",
            $result->texts['footer'],
        );
        $this->assertSame('<div class="print-text"><p>nahoře</p></div>', $result->texts['header']);
    }

    public function testEmailSlotsArePlainTextWithoutMarkdownAndEscaping(): void
    {
        $result = self::render([
            'emailSubject' => [
                ['id' => 1, 'text' => "Doklad {{ data.document.number }}\n"],
                ['id' => 2, 'text' => 'pro {{ data.customer.name }}'],
            ],
            'emailBody' => [
                ['id' => 3, 'text' => "Dobrý den,\n\nposíláme **doklad** {{ data.document.number }}."],
                ['id' => 4, 'text' => 'S pozdravem'],
            ],
        ]);

        // Předmět: texty mezerou; tělo: prázdným řádkem. Hodnoty i Markdown beze změny.
        $this->assertSame('Doklad 2026000123 pro Firma *Hvězda* <b>s.r.o.</b>', $result->texts['emailSubject']);
        $this->assertSame(
            "Dobrý den,\n\nposíláme **doklad** 2026000123.\n\nS pozdravem",
            $result->texts['emailBody'],
        );
    }

    public function testBrokenTextIsLeftOutWithWarningAndOthersStillPrint(): void
    {
        $result = self::render(['footer' => [
            ['id' => 1, 'text' => 'v pořádku'],
            ['id' => 7, 'text' => 'Doklad {{ data.document.numbr }}'],
            ['id' => 8, 'text' => '{% for r in data.rows %}x{% endfor %}'],
            ['id' => 9, 'text' => 'Neuzavřeno {{ data.document.number'],
            ['id' => 2, 'text' => 'také v pořádku'],
        ]]);

        $this->assertSame(
            "<div class=\"print-text\"><p>v pořádku</p></div>\n<div class=\"print-text\"><p>také v pořádku</p></div>",
            $result->texts['footer'],
        );
        $this->assertCount(3, $result->messages);
        foreach ($result->messages as $message) {
            $this->assertSame('textError', $message->code);
            $this->assertSame('warning', $message->severity->value);
        }
        $this->assertStringContainsString('#7', $result->messages[0]->text);
        $this->assertStringContainsString('numbr', $result->messages[0]->text);
        $this->assertStringContainsString('#8', $result->messages[1]->text);
        $this->assertStringContainsString('Tag "for" is not allowed', $result->messages[1]->text);
        $this->assertStringContainsString('#9', $result->messages[2]->text);
    }

    public function testWarningUsesPrintCatalogWhenItHasTheKey(): void
    {
        $translator = new PrintTranslator(
            ['message.textError' => ['cs' => 'Text č. {id} se nevytiskl: {reason}']],
            'cs',
        );

        $result = self::render(['footer' => [['id' => 7, 'text' => '{{ data.x|raw }}']]], $translator);

        $this->assertSame('Text č. 7 se nevytiskl: Filter "raw" is not allowed (řádek 1)', $result->messages[0]->text);
        $this->assertArrayNotHasKey('footer', $result->texts);
    }

    public function testTextThatRendersEmptyLeavesNoBlock(): void
    {
        $result = self::render([
            'footer' => [['id' => 1, 'text' => '{% if data.payment.amountToPay > 5000 %}Velká částka{% endif %}']],
            'header' => [['id' => 2, 'text' => "  \n"]],
        ]);

        $this->assertSame([], $result->texts);
        $this->assertSame([], $result->messages);
    }

    public function testTextSeesOnlyDataMetaAndLanguage(): void
    {
        $result = self::render(['footer' => [
            ['id' => 1, 'text' => '{{ meta.title }} / {{ language }}'],
            ['id' => 2, 'text' => '{{ branding.logo }}'],
            ['id' => 3, 'text' => '{{ record.id }}'],
        ]]);

        $this->assertSame('<div class="print-text"><p>Faktura 2026000123 / cs</p></div>', $result->texts['footer']);
        $this->assertCount(2, $result->messages);
    }

    public function testUnknownSlotsAreIgnored(): void
    {
        $this->assertSame([], self::render(['sidebar' => [['id' => 1, 'text' => 'x']]])->texts);
    }
}
