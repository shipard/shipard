/**
 * Náhled ESC/POS (#177 U13): z bajtů tiskové úlohy udělá čitelné řádky
 * účtenky a značky toho, co se nedá napsat (ořez, QR, šuplík). Čistá
 * funkce nad pevnou podmnožinou příkazů:
 *
 *   ESC @        inicializace — reset stylu a kódové stránky, zahodí rozepsaný řádek
 *   LF           nový řádek
 *   ESC d n      vytiskne rozepsaný řádek a posune o n řádků
 *   ESC a n      zarovnání (0 / 1 / 2 = left / center / right)
 *   ESC E n      tučně
 *   GS ! n       velikost znaku (šířka a výška 1–8)
 *   ESC t n      kódová stránka (čísla v codePages.js)
 *   GS V …       ořez → značka `cut`
 *   GS ( k …     QR: uložení dat (funkce 80) a tisk (81) → značka `qr`
 *   ESC p …      šuplík → značka `drawer`
 *
 * Neznámý příkaz dá značku `unknown` s hex bajty a dekódování pokračuje —
 * náhled nikdy nespadne. `EscPosRenderer` na serveru (task 4) musí zůstat
 * v téhle podmnožině, nebo náhled rozšířit i s testem.
 *
 * Meze náhledu: řádek nese jeden styl — ten, který platil u jeho prvního
 * znaku (zarovnání tiskárna stejně bere jen na začátku řádku; změna tučného
 * uprostřed řádku se neukáže). Řádek delší než `columns` se zalomí jako na
 * papíře; široké písmo (GS !) zabírá násobek sloupců.
 */
import { DEFAULT_CODE_PAGE, codePageOfEscPos, decodeByte } from './codePages.js';

const LF = 0x0a;
const CR = 0x0d;
const ESC = 0x1b;
const GS = 0x1d;

const ALIGN = { 0: 'left', 1: 'center', 2: 'right', 48: 'left', 49: 'center', 50: 'right' };

function hex(bytes) {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join(' ');
}

/**
 * @param {Uint8Array|number[]} bytes
 * @param {{codePage?: string, columns?: number}} [options]  výchozí stránka (do prvního `ESC t`) a šířka papíru ve znacích
 * @returns {{
 *   lines: {text: string, align: 'left'|'center'|'right', bold: boolean, size: {w: number, h: number}}[],
 *   marks: {at: number, kind: 'cut'|'qr'|'drawer'|'unknown', data: object}[],
 * }} `marks[].at` = počet řádků hotových před značkou (značka patří před řádek s tímto indexem)
 */
export function decodeEscPos(bytes, { codePage = DEFAULT_CODE_PAGE, columns = 48 } = {}) {
  const data = bytes instanceof Uint8Array ? bytes : Uint8Array.from(bytes);
  const lines = [];
  const marks = [];

  let align = 'left';
  let bold = false;
  let size = { w: 1, h: 1 };
  let page = codePage;
  let qrContent = null;

  // Rozepsaný řádek: text a styl zachycený u prvního znaku.
  let text = '';
  let style = null;

  const currentStyle = () => ({ align, bold, size: { ...size } });

  function pushLine() {
    lines.push({ text, ...(style ?? currentStyle()) });
    text = '';
    style = null;
  }

  function flushPending() {
    if (text !== '') pushLine();
  }

  function mark(kind, markData) {
    marks.push({ at: lines.length, kind, data: markData });
  }

  function unknown(from, to) {
    mark('unknown', { hex: hex(data.subarray(from, to)) });
  }

  function putChar(char) {
    if (style === null) style = currentStyle();
    const limit = Math.max(1, Math.floor(columns / style.size.w));
    if (text.length >= limit) {
      pushLine();
      style = currentStyle();
    }
    text += char;
  }

  let i = 0;
  while (i < data.length) {
    const byte = data[i];

    if (byte === LF) {
      pushLine();
      i += 1;
      continue;
    }
    if (byte === CR) {
      i += 1;
      continue;
    }

    if (byte === ESC) {
      const command = data[i + 1];
      const arg = data[i + 2];
      if (command === 0x40) { // ESC @
        align = 'left';
        bold = false;
        size = { w: 1, h: 1 };
        page = codePage;
        text = '';
        style = null;
        i += 2;
      } else if (command === 0x64 && arg !== undefined) { // ESC d n
        const feed = text !== '' ? arg : arg + 1;
        if (text !== '') pushLine();
        for (let n = 1; n < feed; n++) pushLine();
        i += 3;
      } else if (command === 0x61 && arg !== undefined && ALIGN[arg] !== undefined) { // ESC a n
        align = ALIGN[arg];
        i += 3;
      } else if (command === 0x45 && arg !== undefined) { // ESC E n
        bold = (arg & 1) === 1;
        i += 3;
      } else if (command === 0x74 && arg !== undefined) { // ESC t n
        const next = codePageOfEscPos(arg);
        if (next) page = next;
        else unknown(i, i + 3);
        i += 3;
      } else if (command === 0x70 && i + 4 < data.length) { // ESC p m t1 t2
        mark('drawer', { pin: arg === 1 || arg === 49 ? 1 : 0 });
        i += 5;
      } else {
        const end = Math.min(i + 2, data.length);
        unknown(i, end);
        i = end;
      }
      continue;
    }

    if (byte === GS) {
      const command = data[i + 1];
      const arg = data[i + 2];
      if (command === 0x21 && arg !== undefined) { // GS ! n
        size = { w: ((arg >> 4) & 0x07) + 1, h: (arg & 0x07) + 1 };
        i += 3;
      } else if (command === 0x56 && arg !== undefined) { // GS V m [n]
        const withFeed = arg === 65 || arg === 66;
        if (withFeed && data[i + 3] === undefined) {
          unknown(i, data.length);
          i = data.length;
        } else {
          flushPending();
          mark('cut', { mode: arg === 1 || arg === 49 || arg === 66 ? 'partial' : 'full' });
          i += withFeed ? 4 : 3;
        }
      } else if (command === 0x28 && arg === 0x6b) { // GS ( k pL pH cn fn …
        const length = data[i + 3] === undefined || data[i + 4] === undefined ? null : data[i + 3] + data[i + 4] * 256;
        const end = length === null ? data.length : i + 5 + length;
        if (length === null || length < 2 || end > data.length) {
          unknown(i, data.length);
          i = data.length;
        } else {
          const cn = data[i + 5];
          const fn = data[i + 6];
          if (cn === 49 && fn === 80) { // uložení dat: cn fn m d1…dk
            qrContent = new TextDecoder('utf-8').decode(data.subarray(i + 8, end));
          } else if (cn === 49 && fn === 81) { // tisk uloženého symbolu
            flushPending();
            mark('qr', { content: qrContent ?? '' });
          } else if (!(cn === 49 && (fn === 65 || fn === 67 || fn === 69))) { // model, velikost modulu, korekce se v náhledu neukazují
            unknown(i, end);
          }
          i = end;
        }
      } else {
        const end = Math.min(i + 2, data.length);
        unknown(i, end);
        i = end;
      }
      continue;
    }

    if (byte < 0x20) {
      unknown(i, i + 1);
      i += 1;
      continue;
    }

    putChar(decodeByte(byte, page));
    i += 1;
  }

  flushPending();
  return { lines, marks };
}
