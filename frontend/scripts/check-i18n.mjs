#!/usr/bin/env node
// Kontrola slovníků: parita klíčů cs ↔ en pro aplikaci (src/i18n) i pro
// vývojářský dashboard (src/dev/i18n, #129 D12). U dashboardu navíc: každý
// statický klíč `t('…')` v src/dev musí ve slovníku být; každý klíč slovníku
// musí někdo používat — jako literál kdekoli v kódu (i v mapě klíčů nebo
// v podmínce `t(a ? 'x' : 'y')`), nebo prefixem dynamického volání
// (`t(`jobs.status.${s}`)`, `has(`…`)`, `label('jobs.status', s)`); a každá
// zpráva obou slovníků dashboardu se musí dát přeložit do ICU MessageFormat
// (neuzavřená závorka nebo apostrof by jinak spadly až v prohlížeči).
// Aplikace kontrolu použití nemá (klíče skládá i server). Spouští
// `npm run check:i18n`; exit 0 v pořádku, 1 při nálezu.

import { readdirSync, readFileSync, statSync } from 'node:fs';
import IntlMessageFormat from 'intl-messageformat';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import csApp from '../src/i18n/cs.js';
import enApp from '../src/i18n/en.js';
import csDev from '../src/dev/i18n/cs.js';
import enDev from '../src/dev/i18n/en.js';

const FRONTEND = fileURLToPath(new URL('..', import.meta.url));
const DEV_SRC = join(FRONTEND, 'src', 'dev');

const errors = [];

function parity(name, cs, en) {
  const csKeys = new Set(Object.keys(cs));
  const enKeys = new Set(Object.keys(en));
  const missingInEn = [...csKeys].filter((k) => !enKeys.has(k));
  const missingInCs = [...enKeys].filter((k) => !csKeys.has(k));
  if (missingInEn.length) errors.push(`${name}: klíče chybí v en:\n  ${missingInEn.join('\n  ')}`);
  if (missingInCs.length) errors.push(`${name}: klíče chybí v cs:\n  ${missingInCs.join('\n  ')}`);
  return csKeys.size;
}

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) walk(path, out);
    else if (/\.(svelte|js)$/.test(name) && !path.includes(`${join('src', 'dev', 'i18n')}`)) out.push(path);
  }
  return out;
}

/** Statické klíče `t('…')`, všechny tečkované literály a prefixy dynamických volání v src/dev. */
function usedDevKeys() {
  const statics = new Map(); // key → první soubor
  const literals = new Set();
  const prefixes = new Set();
  const staticRe = /\bt\(\s*(['"])([A-Za-z0-9_.-]+)\1/g;
  const literalRe = /(['"])([a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9_-]+)+)\1/g;
  const templateRe = /\b(?:t|has)\(\s*`([A-Za-z0-9_.-]+)\.\$\{/g;
  const labelRe = /\blabel\(\s*(['"])([A-Za-z0-9_.-]+)\1\s*,/g;
  for (const file of walk(DEV_SRC)) {
    const text = readFileSync(file, 'utf8');
    const rel = relative(FRONTEND, file);
    for (const m of text.matchAll(staticRe)) if (!statics.has(m[2])) statics.set(m[2], rel);
    for (const m of text.matchAll(literalRe)) literals.add(m[2]);
    for (const m of text.matchAll(templateRe)) prefixes.add(m[1] + '.');
    for (const m of text.matchAll(labelRe)) prefixes.add(m[2] + '.');
  }
  return { statics, literals, prefixes };
}

/** Každá zpráva slovníku dashboardu musí jít přeložit (ICU syntaxe). */
function compileAll(name, dict, locale) {
  const bad = [];
  for (const [key, message] of Object.entries(dict)) {
    try {
      new IntlMessageFormat(message, locale, undefined, { ignoreTag: true }); // jako src/dev/i18n/index.js
    } catch (e) {
      bad.push(`${key}: ${e.message.split('\n')[0]}`);
    }
  }
  if (bad.length) errors.push(`${name}: zprávy, které ICU MessageFormat nepřeloží:\n  ${bad.join('\n  ')}`);
}

const appCount = parity('aplikace (src/i18n)', csApp, enApp);
const devCount = parity('dashboard (src/dev/i18n)', csDev, enDev);

compileAll('dashboard cs', csDev, 'cs');
compileAll('dashboard en', enDev, 'en');

const { statics, literals, prefixes } = usedDevKeys();
const devKeys = new Set([...Object.keys(csDev), ...Object.keys(enDev)]);
const undefinedUsed = [...statics].filter(([key]) => !devKeys.has(key));
if (undefinedUsed.length) {
  errors.push(`dashboard: komponenty používají klíče, které ve slovníku nejsou:\n  ${undefinedUsed.map(([k, f]) => `${k}  (${f})`).join('\n  ')}`);
}
const unused = [...devKeys].filter((key) => !statics.has(key) && !literals.has(key) && ![...prefixes].some((p) => key.startsWith(p)));
if (unused.length) {
  errors.push(`dashboard: klíče slovníku, které nic nepoužívá (smaž, nebo je použij):\n  ${unused.join('\n  ')}`);
}

if (errors.length === 0) {
  console.log(`✓ i18n: aplikace ${appCount} klíčů, dashboard ${devCount} klíčů (${statics.size} statických použití, ${prefixes.size} prefixů) — v pořádku`);
  process.exit(0);
}
for (const e of errors) console.error(e + '\n');
process.exit(1);
