<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Tiskové popisky kódů DPH a poznámky k nim (`world.vat.<země>`:
 * `vatCodes[].print`, `vatCodes[].note`, `vatNotes`) — aktuální v okamžiku
 * tisku, v jazyce tisku (#90 D13, D14).
 *
 * Značky poznámek přiděluje v pořadí prvního použití; stejný text má jednu
 * značku, i když ho nese víc kódů. Řádky i rekapitulace proto musí brát
 * značky z téže instance.
 */
final class DocVatCodes
{
    /** @var array<string, string> text poznámky → značka */
    private array $marks = [];

    /**
     * @param array<string, array<string, mixed>> $codes
     * @param array<string, array<string, mixed>> $notes
     */
    public function __construct(
        private readonly array $codes,
        private readonly array $notes,
    ) {}

    public static function fromConfig(?ConfigRuntime $config, ?string $country): self
    {
        $data = $country === null || $country === ''
            ? null
            : $config?->cfgItem('world.vat.' . strtolower($country));
        if (!is_array($data)) {
            return new self([], []);
        }
        return new self(
            is_array($data['vatCodes'] ?? null) ? $data['vatCodes'] : [],
            is_array($data['vatNotes'] ?? null) ? $data['vatNotes'] : [],
        );
    }

    /** Popisek sazby pro tisk; neznámý kód → kód sám. */
    public function label(string $code): string
    {
        $def = $this->codes[$code] ?? null;
        $label = is_array($def) ? ($def['print'] ?? $def['name'] ?? null) : null;
        return is_string($label) && $label !== '' ? $label : $code;
    }

    /** Značka poznámky kódu; null, když kód poznámku nemá. */
    public function noteMark(string $code): ?string
    {
        $noteId = $this->codes[$code]['note'] ?? null;
        $text   = is_string($noteId) ? ($this->notes[$noteId]['text'] ?? null) : null;
        if (!is_string($text) || $text === '') {
            return null;
        }
        return $this->marks[$text] ??= (string) (count($this->marks) + 1);
    }

    /**
     * Poznámky, které dostaly značku — každá jednou, v pořadí značek.
     *
     * @return list<array{mark: string, text: string}>
     */
    public function notes(): array
    {
        $notes = [];
        foreach ($this->marks as $text => $mark) {
            $notes[] = ['mark' => $mark, 'text' => (string) $text];
        }
        return $notes;
    }
}
