// Jednořádková textová pole bez automatických oprav (#177 D10, U14): WebKit
// na Macu i iPadu — Safari i WKWebView nativní aplikace — jinak přepisuje
// kódy, čísla dokladů a jména a zvětšuje první písmeno. Každý `<input>`
// v `.svelte` souborech, který není zaškrtávátko, soubor, barva, posuvník,
// skryté pole ani heslo, proto nese `autocorrect="off"`,
// `autocapitalize="off"` a `spellcheck="false"`. Víceřádkový `<textarea>`
// (volný text) opravy ponechává — test ho neprochází.
//
// Prochází i vývojářský dashboard (`src/dev/`): stránka Most se otevírá
// přímo ve WebView nativní aplikace. Výjimka jen s komentářem v kódu těsně
// před značkou: `<!-- autocorrect-ok: důvod -->`.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');

/** Typy, které text nepíšou (nebo ho prohlížeč neopravuje) — atributy nepotřebují. */
const EXEMPT_TYPES = ['checkbox', 'radio', 'file', 'color', 'range', 'hidden', 'password'];

const REQUIRED = {
  autocorrect: /\bautocorrect\s*=\s*(["']?)off\1(?=[\s/>]|$)/,
  autocapitalize: /\bautocapitalize\s*=\s*(["']?)off\1(?=[\s/>]|$)/,
  spellcheck: /\bspellcheck\s*=\s*(?:(["']?)false\1|\{false\})(?=[\s/>]|$)/,
};

const EXCEPTION = /<!--\s*autocorrect-ok:\s*\S[\s\S]*?-->\s*$/;

/** Nahradí úsek mezerami a zachová konce řádků — čísla řádků a pozice zůstanou. */
function blank(text, from, to) {
  return text.slice(0, from) + text.slice(from, to).replace(/[^\n]/g, ' ') + text.slice(to);
}

/** Konec značky od pozice za `<input`: přeskočí `{výrazy}` a řetězce, vrátí index za `>`. */
function tagEnd(text, start) {
  let depth = 0;
  let quote = null; // uvozovka atributu (hloubka 0) nebo řetězce JS (hloubka > 0)
  for (let i = start; i < text.length; i++) {
    const ch = text[i];
    if (quote) {
      if (ch === '\\' && depth > 0) i += 1;
      else if (ch === quote) quote = null;
      else if (ch === '{' && depth === 0) {
        // výraz uvnitř hodnoty atributu v uvozovkách: class="a {b}"
        const close = text.indexOf('}', i);
        if (close !== -1) i = close;
      }
      continue;
    }
    if (ch === '"' || ch === "'" || (ch === '`' && depth > 0)) quote = ch;
    else if (ch === '{') depth += 1;
    else if (ch === '}') depth -= 1;
    else if (ch === '>' && depth === 0) return i + 1;
  }
  return text.length;
}

/**
 * Značky `<input>` v šabloně Svelte: `[{line, tag, type, missing, excepted}]`.
 * `type` je statická hodnota atributu, `null` u dynamického (`type={…}`,
 * `{type}`) a `'text'` bez atributu.
 */
function inputs(source) {
  // Skript a styl nejsou šablona — `<input>` v komentáři skriptu není značka.
  let text = source;
  for (const m of source.matchAll(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/g)) {
    text = blank(text, m.index, m.index + m[0].length);
  }
  const comments = [...text.matchAll(/<!--[\s\S]*?-->/g)].map((m) => [m.index, m.index + m[0].length]);

  const found = [];
  for (const m of text.matchAll(/<input(?=[\s/>])/g)) {
    if (comments.some(([from, to]) => m.index >= from && m.index < to)) continue;
    const end = tagEnd(text, m.index + m[0].length);
    const tag = text.slice(m.index, end);

    let type = 'text';
    const literal = /\stype\s*=\s*(?:"([^"{]*)"|'([^'{]*)'|([^\s"'{}/>]+))/.exec(tag);
    if (literal) type = (literal[1] ?? literal[2] ?? literal[3]).toLowerCase();
    else if (/\stype\s*=|\{type\}/.test(tag)) type = null;

    found.push({
      line: text.slice(0, m.index).split('\n').length,
      tag,
      type,
      missing: Object.keys(REQUIRED).filter((name) => !REQUIRED[name].test(tag)),
      excepted: EXCEPTION.test(text.slice(0, m.index)),
    });
  }
  return found;
}

function svelteFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) out.push(...svelteFiles(path));
    else if (name.endsWith('.svelte')) out.push(path);
  }
  return out;
}

test('rozpozná značky input, jejich typ a chybějící atributy', () => {
  const source = [
    '<script>',
    '  // <input> v komentáři skriptu není značka',
    "  const html = '<input type=\"text\">';",
    '</script>',
    '<input type="text" bind:value />',
    '<input type="checkbox" bind:checked />',
    '<input {type} autocorrect="off" autocapitalize="off" spellcheck="false" />',
    '<input',
    '  type={show ? "text" : "password"}',
    '  oninput={(e) => (value = e.currentTarget.value > 3 ? "a" : "b")}',
    '  class="field {error ? \'bad\' : \'\'}"',
    '  autocorrect="off"',
    '  spellcheck={false}',
    '/>',
    '<!-- <input type="text"> v komentáři šablony -->',
    '<!-- autocorrect-ok: jméno se píše s velkým písmenem -->',
    '<input bind:value={name} />',
    '<inputmask />',
    '<input type=number autocapitalize="off" autocorrect=off spellcheck="false">',
  ].join('\n');

  assert.deepEqual(
    inputs(source).map((i) => [i.line, i.type, i.missing, i.excepted]),
    [
      [5, 'text', ['autocorrect', 'autocapitalize', 'spellcheck'], false],
      [6, 'checkbox', ['autocorrect', 'autocapitalize', 'spellcheck'], false],
      [7, null, [], false],
      [8, null, ['autocapitalize'], false],
      [17, 'text', ['autocorrect', 'autocapitalize', 'spellcheck'], true],
      [19, 'number', [], false],
    ],
  );
  // hodnota "on" nebo true se nepočítá
  assert.deepEqual(inputs('<input autocorrect="on" autocapitalize="offx" spellcheck="true" />')[0].missing, ['autocorrect', 'autocapitalize', 'spellcheck']);
});

test('textová pole mají vypnuté automatické opravy', () => {
  const files = svelteFiles(SRC);
  assert.ok(files.length > 100, 'zdroje frontendu nenalezeny');

  const violations = [];
  const idleExceptions = [];
  let checked = 0;
  for (const file of files) {
    const rel = relative(SRC, file).split(sep).join('/');
    for (const input of inputs(readFileSync(file, 'utf8'))) {
      const needs = input.type === null || !EXEMPT_TYPES.includes(input.type);
      if (input.excepted) {
        if (!needs || input.missing.length === 0) idleExceptions.push(`${rel}:${input.line}`);
        continue;
      }
      if (!needs) continue;
      checked += 1;
      if (input.missing.length) violations.push(`${rel}:${input.line} — chybí ${input.missing.join(', ')}`);
    }
  }

  assert.ok(checked > 30, 'textová pole nenalezena');
  assert.deepEqual(
    violations,
    [],
    'Textový <input> bez vypnutých automatických oprav — doplň autocorrect="off" autocapitalize="off" '
      + 'spellcheck="false" (docs/frontend.md → Nativní most), výjimku jen komentářem '
      + `<!-- autocorrect-ok: důvod --> těsně před značkou:\n${violations.join('\n')}`,
  );
  assert.deepEqual(idleExceptions, [], 'Výjimka autocorrect-ok u pole, které ji nepotřebuje — smaž komentář');
});
