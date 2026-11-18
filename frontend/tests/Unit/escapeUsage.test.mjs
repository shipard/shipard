// Esc zavírá jen zásobník vrstev `utils/layerStack.js` (#160 D121) —
// komponenta vrstvy vlastní posluchač Esc nemá, jinak jeden stisk zavře
// víc vrstev najednou. Test projde zdroje aplikace a hlídá, že se klávesa
// `'Escape'` nečte jinde než v zásobníku a ve vyjmenovaných výjimkách
// s důvodem. Vývojářský dashboard (`src/dev/`) má vlastní dialogy — test
// ho neprochází.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');

const PATTERN = /['"`]Escape['"`]/;

/** Soubor → důvod, proč v něm čtení klávesy Esc smí být. */
const ALLOWED = {
  'utils/layerStack.js': 'zásobník vrstev — jediný posluchač Esc',
  'components/form/AttachmentPanel.svelte':
    'Esc ruší přejmenování přílohy (editace textu) a stisk označí `preventDefault()`',
  // Do převodu v dalším commitu (#160, krok 2 zadání — zbytek vrstev):
  'components/viewer/ViewerDetailDrawer.svelte': 'čeká na převod na zásobník',
  'components/layout/ThemePanel.svelte': 'čeká na převod na zásobník',
  'components/chrome/UserMenu.svelte': 'čeká na převod na zásobník',
  'components/chrome/CommandPalette.svelte': 'čeká na převod na zásobník',
  'components/shells/SidebarShell.svelte': 'čeká na převod na zásobník',
};

function sources(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (path !== join(SRC, 'dev')) out.push(...sources(path));
    } else if (/\.(js|ts|svelte)$/.test(name)) {
      out.push(path);
    }
  }
  return out;
}

/** Čísla řádků, na kterých se čte klávesa Esc. */
function occurrences(text) {
  const found = [];
  text.split('\n').forEach((line, index) => {
    if (PATTERN.test(line)) found.push(index + 1);
  });
  return found;
}

test('rozpozná čtení klávesy Esc', () => {
  const text = [
    "if (e.key === 'Escape') onClose();",
    'if (event.key === "Escape") close();',
    '// Escape zavře nabídku',
    "if (e.key === 'Enter') submit();",
  ].join('\n');
  assert.deepEqual(occurrences(text), [1, 2]);
});

test('klávesu Esc čte jen zásobník vrstev', () => {
  const files = sources(SRC);
  assert.ok(files.length > 100, 'zdroje aplikace nenalezeny');

  const violations = [];
  const used = new Set();
  for (const file of files) {
    const rel = relative(SRC, file).split(sep).join('/');
    for (const line of occurrences(readFileSync(file, 'utf8'))) {
      if (ALLOWED[rel]) {
        used.add(rel);
        continue;
      }
      violations.push(`${rel}:${line}`);
    }
  }

  assert.deepEqual(
    violations,
    [],
    'Vlastní posluchač Esc mimo zásobník vrstev — vrstvu zapiš přes openLayer() '
      + `z utils/layerStack.js (docs/design-system.md §8 Zavírání vrstev):\n${violations.join('\n')}`,
  );

  const stale = Object.keys(ALLOWED).filter((rel) => !used.has(rel));
  assert.deepEqual(stale, [], 'Výjimka bez výskytu — smaž ji ze seznamu ALLOWED');
});
