// Náhled ESC/POS (#177 U13): každý příkaz podmnožiny, obě kódové stránky
// s diakritikou, zalomení, neznámý příkaz a zkušební účtenka tam i zpět.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CODE_PAGES, codePageOfEscPos, decodeByte, encodeText } from '../../../src/native/escpos/codePages.js';
import { decodeEscPos } from '../../../src/native/escpos/decode.js';
import {
  TEST_RECEIPT_PANGRAM,
  TEST_RECEIPT_QR,
  TEST_RECEIPT_TITLE,
  buildTestReceipt,
  formatReceiptTime,
} from '../../../src/native/escpos/testReceipt.js';

const ESC = 0x1b;
const GS = 0x1d;
const LF = 0x0a;

/** Složí bajty z čísel a textů (text v CP852, pokud není řečeno jinak). */
function bytes(parts, codePage = 'cp852') {
  const out = [];
  for (const part of parts) {
    if (typeof part === 'string') out.push(...encodeText(part, codePage));
    else out.push(part);
  }
  return Uint8Array.from(out);
}

const texts = (decoded) => decoded.lines.map((l) => l.text);

test('kódové stránky: čísla ESC t a převod tam i zpět', () => {
  assert.equal(CODE_PAGES.cp852.escpos, 18);
  assert.equal(CODE_PAGES['windows-1250'].escpos, 45);
  assert.equal(codePageOfEscPos(18), 'cp852');
  assert.equal(codePageOfEscPos(45), 'windows-1250');
  assert.equal(codePageOfEscPos(0), null);

  // známé pozice: CP852 ř = 0xFD, č = 0x9F; Windows-1250 ř = 0xF8, č = 0xE8
  assert.deepEqual([...encodeText('řč', 'cp852')], [0xfd, 0x9f]);
  assert.deepEqual([...encodeText('řč', 'windows-1250')], [0xf8, 0xe8]);
  assert.equal(decodeByte(0xfd, 'cp852'), 'ř');
  assert.equal(decodeByte(0xf8, 'windows-1250'), 'ř');
  assert.equal(decodeByte(0x41, 'cp852'), 'A');

  const sample = 'Příliš žluťoučký kůň úpěl ďábelské ódy ĚŠČŘŽÝÁÍÉÚŮŤĎŇ';
  for (const codePage of Object.keys(CODE_PAGES)) {
    const encoded = encodeText(sample, codePage);
    assert.equal(encoded.length, sample.length, `${codePage}: jeden bajt na znak`);
    assert.equal(Array.from(encoded, (b) => decodeByte(b, codePage)).join(''), sample, codePage);
  }
  assert.deepEqual([...encodeText('a€→', 'cp852')], [0x61, 0x3f, 0x3f], 'znak mimo stránku je otazník');
});

test('LF: text a nový řádek, výchozí styl', () => {
  const decoded = decodeEscPos(bytes(['Ahoj', LF, LF, 'svět']));
  assert.deepEqual(decoded.lines, [
    { text: 'Ahoj', align: 'left', bold: false, size: { w: 1, h: 1 } },
    { text: '', align: 'left', bold: false, size: { w: 1, h: 1 } },
    { text: 'svět', align: 'left', bold: false, size: { w: 1, h: 1 } },
  ]);
  assert.deepEqual(decoded.marks, []);
});

test('ESC d n: n prázdných řádků, s rozepsaným textem jako n odřádkování', () => {
  assert.deepEqual(texts(decodeEscPos(bytes([ESC, 0x64, 3]))), ['', '', '']);
  assert.deepEqual(texts(decodeEscPos(bytes(['a', ESC, 0x64, 3, 'b']))), ['a', '', '', 'b']);
  assert.deepEqual(texts(decodeEscPos(bytes(['a', ESC, 0x64, 0, 'b']))), ['a', 'b']);
});

test('ESC a n: zarovnání', () => {
  const decoded = decodeEscPos(bytes([ESC, 0x61, 1, 'střed', LF, ESC, 0x61, 2, 'vpravo', LF, ESC, 0x61, 48, 'vlevo', LF]));
  assert.deepEqual(decoded.lines.map((l) => l.align), ['center', 'right', 'left']);
});

test('ESC E n: tučně', () => {
  const decoded = decodeEscPos(bytes([ESC, 0x45, 1, 'tučně', LF, ESC, 0x45, 0, 'běžně', LF]));
  assert.deepEqual(decoded.lines.map((l) => l.bold), [true, false]);
});

test('GS ! n: velikost a zalomení širokého písma', () => {
  const decoded = decodeEscPos(bytes([GS, 0x21, 0x11, 'AB', LF, GS, 0x21, 0x30, 'C', LF, GS, 0x21, 0x00, 'D', LF]));
  assert.deepEqual(decoded.lines.map((l) => l.size), [{ w: 2, h: 2 }, { w: 4, h: 1 }, { w: 1, h: 1 }]);

  // dvojnásobná šířka: na 8 sloupců se vejdou 4 znaky
  assert.deepEqual(texts(decodeEscPos(bytes([GS, 0x21, 0x10, 'abcdef', LF]), { columns: 8 })), ['abcd', 'ef']);
});

test('ESC @: reset stylu, kódové stránky a rozepsaného řádku', () => {
  const decoded = decodeEscPos(bytes([ESC, 0x61, 1, ESC, 0x45, 1, GS, 0x21, 0x11, ESC, 0x74, 45, 'zahozeno', ESC, 0x40, 0xfd, LF]));
  assert.deepEqual(decoded.lines, [{ text: 'ř', align: 'left', bold: false, size: { w: 1, h: 1 } }]);
});

test('ESC t n: přepnutí kódové stránky uprostřed úlohy', () => {
  const data = [
    ESC, 0x74, 18, ...encodeText('žluťoučký kůň', 'cp852'), LF,
    ESC, 0x74, 45, ...encodeText('žluťoučký kůň', 'windows-1250'), LF,
  ];
  assert.deepEqual(texts(decodeEscPos(Uint8Array.from(data))), ['žluťoučký kůň', 'žluťoučký kůň']);

  // výchozí stránka z voleb platí do prvního ESC t
  assert.deepEqual(texts(decodeEscPos(encodeText('ďábelské ódy', 'windows-1250'), { codePage: 'windows-1250' })), ['ďábelské ódy']);

  // stránka mimo podmnožinu: značka, dekódování pokračuje dosavadní stránkou
  const other = decodeEscPos(bytes([ESC, 0x74, 2, 'ř', LF]));
  assert.deepEqual(other.marks, [{ at: 0, kind: 'unknown', data: { hex: '1b 74 02' } }]);
  assert.deepEqual(texts(other), ['ř']);
});

test('GS V: ořez plný, částečný a s posunem', () => {
  const decoded = decodeEscPos(bytes(['a', LF, GS, 0x56, 0, 'b', GS, 0x56, 1, GS, 0x56, 66, 3, GS, 0x56, 65, 0]));
  assert.deepEqual(texts(decoded), ['a', 'b']);
  assert.deepEqual(decoded.marks, [
    { at: 1, kind: 'cut', data: { mode: 'full' } },
    { at: 2, kind: 'cut', data: { mode: 'partial' } },
    { at: 2, kind: 'cut', data: { mode: 'partial' } },
    { at: 2, kind: 'cut', data: { mode: 'full' } },
  ]);
});

test('GS ( k: QR — uložení a tisk dají značku s obsahem', () => {
  const content = [...new TextEncoder().encode('https://shipard.dev/x?a=1')];
  const store = [GS, 0x28, 0x6b, (content.length + 3) & 0xff, (content.length + 3) >> 8, 49, 80, 48, ...content];
  const decoded = decodeEscPos(Uint8Array.from([
    GS, 0x28, 0x6b, 4, 0, 49, 65, 50, 0, // model
    GS, 0x28, 0x6b, 3, 0, 49, 67, 6, // velikost modulu
    GS, 0x28, 0x6b, 3, 0, 49, 69, 49, // korekce chyb
    ...store,
    GS, 0x28, 0x6b, 3, 0, 49, 81, 48, // tisk
    LF,
  ]));
  assert.deepEqual(decoded.marks, [{ at: 0, kind: 'qr', data: { content: 'https://shipard.dev/x?a=1' } }]);
  assert.deepEqual(texts(decoded), ['']);

  // jiný symbol než QR a useknutá data nespadnou
  assert.equal(decodeEscPos(Uint8Array.from([GS, 0x28, 0x6b, 3, 0, 48, 65, 1, 0x41, LF])).marks[0].kind, 'unknown');
  const cut = decodeEscPos(Uint8Array.from([0x41, GS, 0x28, 0x6b, 200, 0, 49, 80, 48, 0x42]));
  assert.deepEqual(texts(cut), ['A']);
  assert.equal(cut.marks[0].kind, 'unknown');
});

test('ESC p: šuplík', () => {
  const decoded = decodeEscPos(bytes([ESC, 0x70, 0, 25, 250, 'a', LF, ESC, 0x70, 1, 25, 250]));
  assert.deepEqual(decoded.marks, [
    { at: 0, kind: 'drawer', data: { pin: 0 } },
    { at: 1, kind: 'drawer', data: { pin: 1 } },
  ]);
  assert.deepEqual(texts(decoded), ['a']);
});

test('neznámý příkaz: značka s hex bajty, dekódování pokračuje', () => {
  const decoded = decodeEscPos(bytes(['a', LF, ESC, 0x7a, 'b', LF, GS, 0x7a, 0x07, 'c', LF, ESC]));
  assert.deepEqual(texts(decoded), ['a', 'b', 'c']);
  assert.deepEqual(decoded.marks, [
    { at: 1, kind: 'unknown', data: { hex: '1b 7a' } },
    { at: 2, kind: 'unknown', data: { hex: '1d 7a' } },
    { at: 2, kind: 'unknown', data: { hex: '07' } },
    { at: 3, kind: 'unknown', data: { hex: '1b' } },
  ]);
});

test('zalomení: řádek delší než columns pokračuje na dalším', () => {
  assert.deepEqual(texts(decodeEscPos(bytes(['1234567890', LF]), { columns: 4 })), ['1234', '5678', '90']);
  // přesně plný řádek a LF je jeden řádek, ne řádek a prázdný
  assert.deepEqual(texts(decodeEscPos(bytes(['1234', LF, 'x', LF]), { columns: 4 })), ['1234', 'x']);
  // text bez odřádkování na konci se neztratí
  assert.deepEqual(texts(decodeEscPos(bytes(['konec']))), ['konec']);
  // pole čísel místo Uint8Array
  assert.deepEqual(texts(decodeEscPos([0x41, LF])), ['A']);
});

test('zkušební účtenka: dekódování vrátí původní texty, QR a ořez', () => {
  const at = new Date(2026, 10, 27, 10, 15, 42);
  for (const codePage of Object.keys(CODE_PAGES)) {
    for (const columns of [32, 48]) {
      const receipt = buildTestReceipt({ codePage, columns, at });
      assert.ok(receipt instanceof Uint8Array);
      // výchozí stránka náhledu je schválně ta druhá — rozhoduje ESC t v úloze
      const other = Object.keys(CODE_PAGES).find((name) => name !== codePage);
      const decoded = decodeEscPos(receipt, { codePage: other, columns });

      assert.equal(decoded.lines[0].text, TEST_RECEIPT_TITLE, codePage);
      assert.equal(decoded.lines[0].align, 'center');
      assert.equal(decoded.lines[0].bold, true);
      const body = texts(decoded).join('\n');
      const pangram = columns >= TEST_RECEIPT_PANGRAM.length
        ? TEST_RECEIPT_PANGRAM
        : `${TEST_RECEIPT_PANGRAM.slice(0, columns)}\n${TEST_RECEIPT_PANGRAM.slice(columns)}`;
      assert.ok(body.includes(pangram), `${codePage}/${columns}: diakritika`);
      assert.ok(texts(decoded).includes('-'.repeat(columns)), 'dělicí čára na šířku');
      assert.ok(texts(decoded).includes('2026-11-27 10:15:42'), 'datum a čas');
      assert.equal(formatReceiptTime(at), '2026-11-27 10:15:42');

      assert.deepEqual(decoded.marks.map((m) => m.kind), ['qr', 'cut']);
      assert.deepEqual(decoded.marks[0].data, { content: TEST_RECEIPT_QR });
      assert.equal(decoded.marks[1].at, decoded.lines.length, 'ořez je poslední');
    }
  }
  assert.throws(() => buildTestReceipt({ codePage: 'utf-8' }), /Unknown code page/);
});
