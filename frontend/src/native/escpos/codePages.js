/**
 * Kódové stránky náhledu ESC/POS (#177 U13): CP852 a Windows-1250,
 * jednobajtové. CP852 prohlížeče v `TextDecoder` nemají — horní polovina je
 * tady jako tabulka (shodná s kodekem `cp852` v Pythonu); Windows-1250
 * dekóduje `TextDecoder`.
 *
 * Čísla stránek pro příkaz `ESC t n` jsou číslování Epson (ESC/POS):
 * 18 = PC852 (Latin 2), 45 = WPC1250. `EscPosRenderer` na serveru (task 4)
 * musí posílat tatáž čísla.
 */

const CP852_HIGH =
  'ÇüéâäůćçłëŐőîŹÄĆ' + // 0x80
  'ÉĹĺôöĽľŚśÖÜŤťŁ×č' + // 0x90
  'áíóúĄąŽžĘę¬źČş«»' + // 0xA0
  '░▒▓│┤ÁÂĚŞ╣║╗╝Żż┐' + // 0xB0
  '└┴┬├─┼Ăă╚╔╩╦╠═╬¤' + // 0xC0
  'đĐĎËďŇÍÎě┘┌█▄ŢŮ▀' + // 0xD0
  'ÓßÔŃńňŠšŔÚŕŰýÝţ´' + // 0xE0
  '­˝˛ˇ˘§÷¸°¨˙űŘř■ '; //  0xF0

/** Kódování → číslo stránky pro `ESC t n` a popisek pro výběr. */
export const CODE_PAGES = {
  cp852: { escpos: 18, label: 'CP852' },
  'windows-1250': { escpos: 45, label: 'Windows-1250' },
};

export const DEFAULT_CODE_PAGE = 'cp852';

/** Číslo stránky z `ESC t n` → kódování, `null` pro stránku mimo podmnožinu. */
export function codePageOfEscPos(number) {
  for (const [name, page] of Object.entries(CODE_PAGES)) {
    if (page.escpos === number) return name;
  }
  return null;
}

const highTables = new Map();

/** Znaky bajtů 0x80–0xFF dané stránky (řetězec o 128 znacích). */
function highTable(codePage) {
  if (highTables.has(codePage)) return highTables.get(codePage);
  let table;
  if (codePage === 'cp852') {
    table = CP852_HIGH;
  } else if (codePage === 'windows-1250') {
    const bytes = new Uint8Array(128);
    for (let i = 0; i < 128; i++) bytes[i] = 0x80 + i;
    table = new TextDecoder('windows-1250').decode(bytes);
  } else {
    throw new Error(`Unknown code page: ${codePage}`);
  }
  highTables.set(codePage, table);
  return table;
}

/** Jeden bajt → znak. */
export function decodeByte(byte, codePage) {
  return byte < 0x80 ? String.fromCharCode(byte) : highTable(codePage)[byte - 0x80];
}

const reverseTables = new Map();

function reverseTable(codePage) {
  if (reverseTables.has(codePage)) return reverseTables.get(codePage);
  const table = highTable(codePage);
  const reverse = new Map();
  for (let i = 0; i < table.length; i++) {
    const char = table[i];
    // Nepřiřazené pozice Windows-1250 dekodér vrací jako U+FFFD nebo řídicí znak C1.
    if (char !== '�' && char.charCodeAt(0) > 0x9f && !reverse.has(char)) reverse.set(char, 0x80 + i);
  }
  reverseTables.set(codePage, reverse);
  return reverse;
}

/**
 * Text → bajty v kódové stránce; znak, který stránka nemá, je `?`.
 *
 * @param {string} text
 * @param {string} codePage
 * @returns {Uint8Array}
 */
export function encodeText(text, codePage) {
  const reverse = reverseTable(codePage);
  const chars = Array.from(text);
  const bytes = new Uint8Array(chars.length);
  chars.forEach((char, index) => {
    const code = char.codePointAt(0);
    bytes[index] = code < 0x80 ? code : (reverse.get(char) ?? 0x3f);
  });
  return bytes;
}
