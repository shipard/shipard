/**
 * Zkušební účtenka (#177 D29): bajty ESC/POS, které prověří tiskárnu
 * i náhled — diakritiku v kódové stránce, šířku papíru, QR a ořez.
 * Používá ji panel simulace i stránka Most ve vývojářském dashboardu.
 * Drží se podmnožiny příkazů z decode.js.
 */
import { CODE_PAGES, DEFAULT_CODE_PAGE, encodeText } from './codePages.js';

export const TEST_RECEIPT_TITLE = 'ZKUŠEBNÍ ÚČTENKA';
/** Věta se všemi českými znaky s diakritikou. */
export const TEST_RECEIPT_PANGRAM = 'Příliš žluťoučký kůň úpěl ďábelské ódy';
export const TEST_RECEIPT_QR = 'https://shipard.dev';

const ESC = 0x1b;
const GS = 0x1d;
const LF = 0x0a;

/** Datum a čas na účtence: `2026-11-27 10:15:42` v místním čase. */
export function formatReceiptTime(at) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())} ${pad(at.getHours())}:${pad(at.getMinutes())}:${pad(at.getSeconds())}`;
}

/** `GS ( k` — funkce symbolu QR (cn = 49). */
function qrFunction(fn, ...payload) {
  const length = payload.length + 2;
  return [GS, 0x28, 0x6b, length & 0xff, length >> 8, 49, fn, ...payload];
}

/**
 * @param {{codePage?: string, columns?: number, at?: Date}} [options]
 * @returns {Uint8Array}
 */
export function buildTestReceipt({ codePage = DEFAULT_CODE_PAGE, columns = 48, at = new Date() } = {}) {
  const page = CODE_PAGES[codePage];
  if (!page) throw new Error(`Unknown code page: ${codePage}`);

  const out = [];
  const text = (value) => out.push(...encodeText(value, codePage));
  const line = (value) => {
    text(value);
    out.push(LF);
  };

  out.push(ESC, 0x40); // inicializace
  out.push(ESC, 0x74, page.escpos); // kódová stránka

  out.push(ESC, 0x61, 1, ESC, 0x45, 1); // na střed, tučně
  line(TEST_RECEIPT_TITLE);
  out.push(ESC, 0x45, 0, ESC, 0x61, 0);

  line(TEST_RECEIPT_PANGRAM);
  line('-'.repeat(columns));
  line(formatReceiptTime(at));

  out.push(ESC, 0x61, 1);
  out.push(...qrFunction(65, 50, 0)); // model 2
  out.push(...qrFunction(67, 6)); // velikost modulu
  out.push(...qrFunction(69, 49)); // korekce chyb M
  out.push(...qrFunction(80, 48, ...new TextEncoder().encode(TEST_RECEIPT_QR))); // uložení dat
  out.push(...qrFunction(81, 48)); // tisk
  out.push(ESC, 0x61, 0);

  out.push(ESC, 0x64, 3); // posun před ořezem
  out.push(GS, 0x56, 66, 0); // částečný ořez

  return Uint8Array.from(out);
}
