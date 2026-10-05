<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintTranslator;
use Twig\Error\Error;

/**
 * Uživatelské texty → obsah slotů obálky tisku (#90 D50).
 *
 *  - slot stránky: Twig v sandboxu uživatelských textů, hodnoty escapované
 *    pro Markdown → Markdown → HTML; každý text ve vlastním
 *    `<div class="print-text">`, texty slotu za sebou;
 *  - e-mailový slot: jen Twig, výstup je prostý text; víc textů se spojí —
 *    předmět mezerou, tělo prázdným řádkem.
 *
 * Text vidí `data`, `meta` a `language` z `PrintData`. Chyba jednoho textu
 * (neznámá proměnná, prvek mimo politiku) tisk nerozbije: text se vynechá
 * a obálka dostane varování `textError` s jeho id.
 */
final class PrintTextRenderer
{
    public const ERROR_CODE = 'textError';

    /** Klíč katalogu tisku s textem varování; bez něj je varování anglicky. */
    private const ERROR_MESSAGE_KEY = 'message.textError';

    private readonly PrintTextMarkdown $markdown;

    public function __construct()
    {
        $this->markdown = new PrintTextMarkdown();
    }

    /**
     * @param array<string, list<array{id: int, text: string}>> $texts slot → texty v pořadí
     */
    public function render(array $texts, PrintData $data, PrintTranslator $translator): RenderedPrintTexts
    {
        $envelope = $data->toArray();
        $context  = ['data' => $envelope['data'], 'meta' => $envelope['meta'], 'language' => $envelope['language']];
        $compiler = new PrintTextCompiler($translator);

        $slots    = [];
        $messages = [];
        foreach (PrintTextSlot::cases() as $slot) {
            $parts = [];
            foreach ($texts[$slot->value] ?? [] as $text) {
                try {
                    $rendered = $compiler->compile($text['text'], markdown: !$slot->isEmail())->render($context);
                    if (!$slot->isEmail()) {
                        $rendered = $this->markdown->toHtml($rendered);
                    }
                } catch (\Throwable $e) {
                    $messages[] = self::errorMessage($translator, $text['id'], $e);
                    continue;
                }
                if (trim($rendered) !== '') {
                    $parts[] = trim($rendered);
                }
            }
            if ($parts !== []) {
                $slots[$slot->value] = self::join($slot, $parts);
            }
        }

        return new RenderedPrintTexts($slots, $messages);
    }

    /** @param non-empty-list<string> $parts */
    private static function join(PrintTextSlot $slot, array $parts): string
    {
        return match (true) {
            $slot === PrintTextSlot::EmailSubject => implode(' ', $parts),
            $slot->isEmail()                      => implode("\n\n", $parts),
            default => implode("\n", array_map(
                static fn (string $html): string => '<div class="print-text">' . $html . '</div>',
                $parts,
            )),
        };
    }

    private static function errorMessage(PrintTranslator $translator, int $textId, \Throwable $error): PrintMessage
    {
        $reason = $error instanceof Error ? PrintTextCompiler::describe($error) : $error->getMessage();
        ErrorLogger::warn('prints: text on print skipped', ['text' => $textId, 'reason' => $reason]);

        return PrintMessage::warning(
            self::ERROR_CODE,
            $translator->has(self::ERROR_MESSAGE_KEY)
                ? $translator->t(self::ERROR_MESSAGE_KEY, ['id' => $textId, 'reason' => $reason])
                : "Text on print #{$textId} was left out: {$reason}",
        );
    }
}
