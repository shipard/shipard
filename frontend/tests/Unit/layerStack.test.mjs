// Zásobník vrstev pro Esc (#160 D121): jeden stisk zavře jednu vrstvu —
// tu naposledy otevřenou.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  openLayer,
  hasLayer,
  hasTransientLayer,
  handleEscape,
  TRANSIENT_ATTRIBUTE,
} from '../../src/utils/layerStack.js';

// Kořen dokumentu pro příznak dočasné vrstvy (#160 D124) — zásobník čte
// `document` až při volání, takže stačí podvrh s atributy.
const rootAttributes = new Set();
globalThis.document = {
  documentElement: {
    toggleAttribute(name, force) {
      if (force) rootAttributes.add(name);
      else rootAttributes.delete(name);
    },
  },
};

/** Je na kořeni dokumentu příznak dočasné vrstvy? */
function flagged() {
  return rootAttributes.has(TRANSIENT_ATTRIBUTE);
}

const noop = () => {};

function key(name = 'Escape', extra = {}) {
  return {
    key: name,
    defaultPrevented: false,
    repeat: false,
    isComposing: false,
    preventDefault() { this.defaultPrevented = true; },
    ...extra,
  };
}

/** Vrstva, která se na Esc zavře (odebere) a zapíše se do `log`. */
function closable(kind, name, log) {
  const handle = openLayer({
    kind,
    transient: kind === 'popover',
    onEscape: () => {
      log.push(name);
      handle.remove();
    },
  });
  return handle;
}

test('Esc zavře jen vrchol, další Esc vrstvu pod ním', () => {
  const log = [];
  const modal = closable('modal', 'modal', log);
  const popover = closable('popover', 'popover', log);

  assert.equal(modal.isTop(), false);
  assert.equal(popover.isTop(), true);

  const first = key();
  assert.equal(handleEscape(first), true);
  assert.deepEqual(log, ['popover']);
  assert.equal(first.defaultPrevented, true);
  assert.equal(modal.isTop(), true);

  assert.equal(handleEscape(key()), true);
  assert.deepEqual(log, ['popover', 'modal']);

  // Prázdný zásobník: Esc nikdo nespotřebuje.
  const idle = key();
  assert.equal(handleEscape(idle), false);
  assert.equal(idle.defaultPrevented, false);
});

test('vrstva zavřená jinak než Esc zmizí ze středu zásobníku', () => {
  const log = [];
  const bottom = closable('panel', 'bottom', log);
  const middle = closable('modal', 'middle', log);
  const top = closable('popover', 'top', log);

  middle.remove();
  middle.remove(); // opakované odebrání nevadí
  assert.equal(top.isTop(), true);

  handleEscape(key());
  handleEscape(key());
  assert.deepEqual(log, ['top', 'bottom']);
  assert.equal(handleEscape(key()), false);
  bottom.remove();
});

test('vrstva otevřená během onEscape se týmž stiskem nezavře', () => {
  const log = [];
  let confirm = null;
  // Formulář s neuloženými změnami: Esc ho nezavře, otevře dotaz.
  const form = openLayer({
    kind: 'modal', transient: false,
    onEscape: () => {
      log.push('form');
      confirm ??= closable('modal', 'confirm', log);
    },
  });

  handleEscape(key());
  assert.deepEqual(log, ['form']);
  assert.equal(confirm.isTop(), true);

  // Druhý stisk zavře jen dotaz, formulář zůstává.
  handleEscape(key());
  assert.deepEqual(log, ['form', 'confirm']);
  assert.equal(form.isTop(), true);
  form.remove();
});

test('vrstva, která se na Esc nezavře, Esc přesto spotřebuje', () => {
  let calls = 0;
  const below = openLayer({ kind: 'panel', transient: false, onEscape: () => { throw new Error('pod vrcholem'); } });
  const busy = openLayer({ kind: 'modal', transient: false, onEscape: () => { calls += 1; } });

  handleEscape(key());
  handleEscape(key());
  assert.equal(calls, 2);
  busy.remove();
  below.remove();
});

test('jiná klávesa, spotřebovaný Esc, držená klávesa a skládání znaku nic nezavřou', () => {
  let calls = 0;
  const layer = openLayer({ kind: 'modal', transient: false, onEscape: () => { calls += 1; } });

  const other = key('Enter');
  assert.equal(handleEscape(other), false);
  assert.equal(other.defaultPrevented, false);

  // Esc, který zrušil přejmenování přílohy (lokální obsluha ho označila).
  assert.equal(handleEscape(key('Escape', { defaultPrevented: true })), false);

  const held = key('Escape', { repeat: true });
  assert.equal(handleEscape(held), false);
  assert.equal(held.defaultPrevented, false);

  assert.equal(handleEscape(key('Escape', { isComposing: true })), false);
  assert.equal(calls, 0);
  layer.remove();
});

test('depth počítá vrstvy téhož druhu, hasLayer hlídá druh', () => {
  assert.equal(hasLayer('modal'), false);

  const first = openLayer({ kind: 'modal', transient: false, onEscape: () => {} });
  const popover = openLayer({ kind: 'popover', transient: true, onEscape: () => {} });
  const nested = openLayer({ kind: 'modal', transient: false, onEscape: () => {} });

  assert.equal(first.depth, 0);
  assert.equal(popover.depth, 0);
  assert.equal(nested.depth, 1);
  assert.equal(hasLayer('modal'), true);
  assert.equal(hasLayer('popover'), true);
  assert.equal(hasLayer('panel'), false);

  nested.remove();
  assert.equal(hasLayer('modal'), true);
  first.remove();
  assert.equal(hasLayer('modal'), false);
  popover.remove();
  assert.equal(hasLayer('popover'), false);
});

test('dočasnost je povinná u každé vrstvy', () => {
  assert.throws(() => openLayer({ kind: 'popover', onEscape: noop }), TypeError);
  assert.throws(() => openLayer({ kind: 'modal', transient: 1, onEscape: noop }), TypeError);
  // Odmítnutá vrstva se do zásobníku nezapsala.
  assert.equal(hasLayer('popover'), false);
  assert.equal(hasLayer('modal'), false);
  assert.equal(flagged(), false);
});

test('dočasná vrstva nastaví příznak na kořeni dokumentu, poslední zavřená ho uklidí', () => {
  assert.equal(flagged(), false);

  const menu = openLayer({ kind: 'menu', transient: true, onEscape: noop });
  assert.equal(hasTransientLayer(), true);
  assert.equal(flagged(), true);

  // Nabídka pole uvnitř bubliny: příznak drží, dokud je otevřená aspoň jedna.
  const popover = openLayer({ kind: 'popover', transient: true, onEscape: noop });
  menu.remove();
  assert.equal(flagged(), true);

  popover.remove();
  popover.remove(); // opakované odebrání nevadí
  assert.equal(hasTransientLayer(), false);
  assert.equal(flagged(), false);
});

test('nedočasná vrstva příznak nenastaví', () => {
  // Detail v draweru, boční chat a drawer sidebaru — pracuje se vedle nich.
  const drawer = openLayer({ kind: 'panel', transient: false, onEscape: noop });
  const sidebar = openLayer({ kind: 'drawer', transient: false, onEscape: noop });
  const modal = openLayer({ kind: 'modal', transient: false, onEscape: noop });
  assert.equal(hasTransientLayer(), false);
  assert.equal(flagged(), false);

  // Panel vzhledu je téhož druhu jako drawer detailu, ale dočasný.
  const theme = openLayer({ kind: 'panel', transient: true, onEscape: noop });
  assert.equal(flagged(), true);
  theme.remove();
  assert.equal(flagged(), false);

  modal.remove();
  sidebar.remove();
  drawer.remove();
});

test('příznak se uklidí i při odebrání dočasné vrstvy ze středu zásobníku', () => {
  const popover = openLayer({ kind: 'popover', transient: true, onEscape: noop });
  const chat = openLayer({ kind: 'panel', transient: false, onEscape: noop });
  assert.equal(flagged(), true);

  popover.remove();
  assert.equal(flagged(), false);
  chat.remove();
});

test('Esc na dočasné vrstvě příznak uklidí', () => {
  const log = [];
  closable('popover', 'popover', log);
  assert.equal(flagged(), true);

  handleEscape(key());
  assert.deepEqual(log, ['popover']);
  assert.equal(flagged(), false);
});

test('dočasná vrstva pod později otevřeným modalem se nepočítá', () => {
  // Náhled dokladu → panel rozhodnutí → vnořený formulář z lookupu: bublina
  // zůstává otevřená pod formulářem a kliky v něm ignoruje.
  const preview = openLayer({ kind: 'modal', transient: false, onEscape: noop });
  const decision = openLayer({ kind: 'popover', transient: true, onEscape: noop });
  assert.equal(flagged(), true);

  const nested = openLayer({ kind: 'modal', transient: false, onEscape: noop });
  assert.equal(hasTransientLayer(), false);
  assert.equal(flagged(), false);

  // Nabídka lookupu ve vnořeném formuláři je zase dočasná vrstva nad ním.
  const lookup = openLayer({ kind: 'menu', transient: true, onEscape: noop });
  assert.equal(flagged(), true);
  lookup.remove();
  assert.equal(flagged(), false);

  // Po zavření vnořeného formuláře je bublina zase na řadě.
  nested.remove();
  assert.equal(flagged(), true);

  decision.remove();
  assert.equal(flagged(), false);
  preview.remove();
});
