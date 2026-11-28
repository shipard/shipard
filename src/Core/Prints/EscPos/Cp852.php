<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * CP852 (PC Latin 2) — jediná kódová stránka v1 (#177 U27). Tabulka musí být
 * shodná s `CP852_HIGH` v `frontend/src/native/escpos/codePages.js`, jinak
 * by náhled ukazoval jiné znaky než tiskárna; hlídá Cp852Test.
 */
final class Cp852 extends SingleByteCodePage
{
    public const NAME = 'cp852';

    private const HIGH =
        'ÇüéâäůćçłëŐőîŹÄĆ' . // 0x80
        'ÉĹĺôöĽľŚśÖÜŤťŁ×č' . // 0x90
        'áíóúĄąŽžĘę¬źČş«»' . // 0xA0
        '░▒▓│┤ÁÂĚŞ╣║╗╝Żż┐' . // 0xB0
        '└┴┬├─┼Ăă╚╔╩╦╠═╬¤' . // 0xC0
        'đĐĎËďŇÍÎě┘┌█▄ŢŮ▀' . // 0xD0
        'ÓßÔŃńňŠšŔÚŕŰýÝţ´' . // 0xE0
        "\u{00AD}˝˛ˇ˘§÷¸°¨˙űŘř■\u{00A0}"; // 0xF0

    public function name(): string
    {
        return self::NAME;
    }

    protected function highTable(): string
    {
        return self::HIGH;
    }
}
