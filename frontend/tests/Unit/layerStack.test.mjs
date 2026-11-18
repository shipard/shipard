// Zásobník vrstev pro Esc (#160 D121): jeden stisk zavře jednu vrstvu —
// tu naposledy otevřenou.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { openLayer, hasLayer, handleEscape } from '../../src/utils/layerStack.js';

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
    kind: 'modal',
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
  const below = openLayer({ kind: 'panel', onEscape: () => { throw new Error('pod vrcholem'); } });
  const busy = openLayer({ kind: 'modal', onEscape: () => { calls += 1; } });

  handleEscape(key());
  handleEscape(key());
  assert.equal(calls, 2);
  busy.remove();
  below.remove();
});

test('jiná klávesa, spotřebovaný Esc, držená klávesa a skládání znaku nic nezavřou', () => {
  let calls = 0;
  const layer = openLayer({ kind: 'modal', onEscape: () => { calls += 1; } });

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

  const first = openLayer({ kind: 'modal', onEscape: () => {} });
  const popover = openLayer({ kind: 'popover', onEscape: () => {} });
  const nested = openLayer({ kind: 'modal', onEscape: () => {} });

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
