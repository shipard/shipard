// Společné vzory ESC/POS (#177 U33): bajty, které skládá `EscPosRenderer`
// na serveru (`tests/Fixtures/EscPos/*.bin`), musí náhled přečíst jako
// očekávané řádky (`*.preview.json`). Očekávaný náhled vyrábí PHP ze sazby,
// ne z bajtů — porovnávají se tedy dvě nezávislé cesty a renderer s náhledem
// se nemůžou rozejít potichu. PHP stranu hlídá `EscPosFixturesTest`.
//
// Po vědomé změně: `php tests/Fixtures/EscPos/regenerate.php` a kontrola diffu.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { decodeEscPos } from '../../../src/native/escpos/decode.js';

const FIXTURES = join(dirname(fileURLToPath(import.meta.url)), '../../../../tests/Fixtures/EscPos');

/** Profily katalogu (`modules/core/prints/config/printerProfiles.jsonc`) podle přípony vzoru. */
const PROFILES = {
  80: { codePage: 'cp852', columns: 42, columnsSmall: 56 },
  58: { codePage: 'cp852', columns: 32, columnsSmall: 42 },
};

const names = readdirSync(FIXTURES)
  .filter((file) => file.endsWith('.bin'))
  .map((file) => file.slice(0, -'.bin'.length))
  .sort();

test('vzory jsou na místě: zkušební účtenka a čtyři prodejky pro oba profily', () => {
  assert.equal(names.length, 10);
  for (const name of names) {
    assert.ok(PROFILES[name.slice(-2)], `${name}: přípona profilu`);
  }
});

for (const name of names) {
  test(`vzor ${name}: náhled přečte řádky, které server vysázel`, () => {
    const bytes = new Uint8Array(readFileSync(join(FIXTURES, `${name}.bin`)));
    const expected = JSON.parse(readFileSync(join(FIXTURES, `${name}.preview.json`), 'utf8'));
    const decoded = decodeEscPos(bytes, PROFILES[name.slice(-2)]);

    assert.deepEqual(decoded.lines, expected.lines);
    // Značky: kde a jaké; obsah jen u QR (režim ořezu a kolík šuplíku jsou věc ovladače).
    assert.deepEqual(
      decoded.marks.map((mark) => ({ at: mark.at, kind: mark.kind, data: mark.kind === 'qr' ? mark.data : null })),
      expected.marks,
    );
    assert.ok(!decoded.marks.some((mark) => mark.kind === 'unknown'), 'žádný neznámý příkaz');
  });
}

test('profily testu sedí na katalog serveru', () => {
  const source = readFileSync(
    join(FIXTURES, '../../../modules/core/prints/config/printerProfiles.jsonc'),
    'utf8',
  );
  for (const [suffix, profile] of Object.entries(PROFILES)) {
    const block = source.slice(source.indexOf(`"escpos-${suffix}-cp852": {`));
    assert.match(block, new RegExp(`"columns": ${profile.columns},`), `escpos-${suffix}-cp852 columns`);
    assert.match(block, new RegExp(`"columnsSmall": ${profile.columnsSmall},`), `escpos-${suffix}-cp852 columnsSmall`);
  }
});
