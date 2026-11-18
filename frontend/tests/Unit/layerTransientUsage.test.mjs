// Dočasnost je výslovná u každé vrstvy (#160 D124): každé volání
// `openLayer()` ve zdrojích nese `transient: true` nebo `transient: false`.
// Zásobník chybějící volbu odmítne výjimkou až při otevření vrstvy — test
// ji najde dřív, i u vrstvy, kterou při ověřování nikdo neotevřel. Prochází
// i vývojářský dashboard (`src/dev/`), sdílí týž zásobník.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');

/** Definice a ukázka v komentáři — ne volání. */
const SKIPPED = new Set(['utils/layerStack.js']);

function sources(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) out.push(...sources(path));
    else if (/\.(js|ts|svelte)$/.test(name)) out.push(path);
  }
  return out;
}

/**
 * Volání `openLayer(` v textu: řádek a zda argument nese výslovné
 * `transient`. Argument je objektový literál — čte se po jeho uzavírací
 * závorku (volání smí být na víc řádků).
 */
function calls(text) {
  const found = [];
  const pattern = /openLayer\(/g;
  let match;
  while ((match = pattern.exec(text)) !== null) {
    let depth = 0;
    let end = match.index + match[0].length;
    for (; end < text.length; end += 1) {
      if (text[end] === '(') depth += 1;
      else if (text[end] === ')') {
        if (depth === 0) break;
        depth -= 1;
      }
    }
    const argument = text.slice(match.index + match[0].length, end);
    found.push({
      line: text.slice(0, match.index).split('\n').length,
      explicit: /\btransient:\s*(true|false)\b/.test(argument),
    });
  }
  return found;
}

test('rozpozná volání s dočasností i bez ní', () => {
  const text = [
    "openLayer({ kind: 'popover', transient: true, onEscape: () => onClose() });",
    "openLayer({ kind: 'modal', onEscape: () => onClose() });",
    'openLayer({',
    "  kind: 'panel',",
    '  transient: false,',
    '  onEscape: () => close(),',
    '});',
    "openLayer({ kind: 'menu', transient, onEscape });",
  ].join('\n');
  assert.deepEqual(calls(text), [
    { line: 1, explicit: true },
    { line: 2, explicit: false },
    { line: 3, explicit: true },
    { line: 8, explicit: false },
  ]);
});

test('každé volání openLayer() má výslovné transient', () => {
  const files = sources(SRC);
  assert.ok(files.length > 100, 'zdroje aplikace nenalezeny');

  const violations = [];
  let total = 0;
  for (const file of files) {
    const rel = relative(SRC, file).split(sep).join('/');
    if (SKIPPED.has(rel)) continue;
    for (const call of calls(readFileSync(file, 'utf8'))) {
      total += 1;
      if (!call.explicit) violations.push(`${rel}:${call.line}`);
    }
  }

  assert.ok(total >= 10, `volání openLayer() nenalezena (${total})`);
  assert.deepEqual(
    violations,
    [],
    'openLayer() bez výslovné dočasnosti — doplň transient: true (klik mimo vrstvu zavírá) '
      + `nebo transient: false (docs/design-system.md §8 Zavírání vrstev):\n${violations.join('\n')}`,
  );
});
