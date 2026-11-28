<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Sazba účtenky ve tvaru, v jakém ji přečte náhled ve frontendu
 * (`decodeEscPos` v `frontend/src/native/escpos/decode.js`): řádky se stylem
 * a značky příkazů. Vzniká ze sazby, ne z bajtů — společné vzory (#177 U33)
 * tak porovnávají dvě nezávislé cesty: PHP sazba → očekávaný náhled, JS
 * dekodér nad bajty → skutečný náhled.
 */
final class EscPosPreview
{
    /**
     * @param list<ReceiptLine|ReceiptCommand> $items
     * @return array{lines: list<array{text: string, align: string, bold: bool, size: array{w: int, h: int}, font: string}>, marks: list<array{at: int, kind: string, data: array<string, string>|null}>}
     */
    public static function of(array $items): array
    {
        $lines = [];
        $marks = [];

        foreach ($items as $item) {
            if ($item instanceof ReceiptLine) {
                $lines[] = self::line($item);
                continue;
            }
            if ($item->type === ReceiptCommand::FEED) {
                for ($i = 0; $i < $item->lines; $i++) {
                    $lines[] = self::line(new ReceiptLine(''));
                }
                continue;
            }
            $marks[] = [
                'at'   => count($lines),
                'kind' => $item->type,
                'data' => $item->type === ReceiptCommand::QR ? ['content' => $item->data] : null,
            ];
        }

        return ['lines' => $lines, 'marks' => $marks];
    }

    /** @return array{text: string, align: string, bold: bool, size: array{w: int, h: int}, font: string} */
    private static function line(ReceiptLine $line): array
    {
        return [
            // Nezlomitelná mezera se tiskne jako mezera.
            'text'  => str_replace(CodePage::NBSP, ' ', $line->text),
            'align' => $line->align,
            'bold'  => $line->bold,
            'size'  => ['w' => $line->width, 'h' => $line->height],
            'font'  => $line->small ? 'small' : 'normal',
        ];
    }
}
