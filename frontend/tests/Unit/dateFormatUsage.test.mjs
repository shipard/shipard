// Datum na obrazovce tvoří jen `utils/formatDate.js` (jádro
// `formatDateCore.js`) — #133 D73. Test projde zdroje aplikace a hlídá, že
// se datum neformátuje jinde: `toLocaleDateString`, `toLocaleTimeString`,
// `toLocaleString` a `Intl.DateTimeFormat` smí být jen ve vyjmenovaných
// výjimkách s důvodem. Vývojářský dashboard (`src/dev/`) má vlastní jazyk
// a formátování čísel — test ho neprochází.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');

const PATTERNS = ['toLocaleDateString', 'toLocaleTimeString', 'toLocaleString', 'Intl.DateTimeFormat'];

/** Soubor → vzor → důvod, proč tam smí být. */
const ALLOWED = {
  'components/settings/DsSetup.svelte': {
    'Intl.DateTimeFormat': 'názvy měsíců do výběru (`month: long`), ne datum',
  },
  'components/exchange/RegistryExtractedPreview.svelte': {
    toLocaleString: 'formát čísla, ne data',
  },
};

function sources(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (path !== join(SRC, 'dev')) out.push(...sources(path));
    } else if (/\.(js|svelte)$/.test(name)) {
      out.push(path);
    }
  }
  return out;
}

/** Výskyty vzorů v souboru: [{pattern, line}]. */
function occurrences(text) {
  const found = [];
  text.split('\n').forEach((line, index) => {
    for (const pattern of PATTERNS) {
      if (line.includes(pattern)) found.push({ pattern, line: index + 1 });
    }
  });
  return found;
}

test('rozpozná formátování data mimo sdílenou funkci', () => {
  const text = [
    'const a = d.toLocaleDateString();',
    "const b = new Intl.DateTimeFormat('cs-CZ').format(d);",
    'const c = d.toLocaleString();',
    'const e = d.toLocaleTimeString();',
    'const f = formatDate(value);',
  ].join('\n');
  assert.deepEqual(occurrences(text), [
    { pattern: 'toLocaleDateString', line: 1 },
    { pattern: 'Intl.DateTimeFormat', line: 2 },
    { pattern: 'toLocaleString', line: 3 },
    { pattern: 'toLocaleTimeString', line: 4 },
  ]);
});

test('datum se formátuje jen v utils/formatDate.js', () => {
  const files = sources(SRC);
  assert.ok(files.length > 100, 'zdroje aplikace nenalezeny');

  const violations = [];
  const used = new Set();
  for (const file of files) {
    const rel = relative(SRC, file).split(sep).join('/');
    for (const { pattern, line } of occurrences(readFileSync(file, 'utf8'))) {
      if (ALLOWED[rel]?.[pattern]) {
        used.add(`${rel}:${pattern}`);
        continue;
      }
      violations.push(`${rel}:${line}  ${pattern}`);
    }
  }

  assert.deepEqual(
    violations,
    [],
    `Datum formátované mimo utils/formatDate.js — použij formatDate() / formatDateTime():\n${violations.join('\n')}`,
  );

  const stale = Object.entries(ALLOWED)
    .flatMap(([rel, patterns]) => Object.keys(patterns).map((pattern) => `${rel}:${pattern}`))
    .filter((key) => !used.has(key));
  assert.deepEqual(stale, [], 'Výjimka bez výskytu — smaž ji ze seznamu ALLOWED');
});
