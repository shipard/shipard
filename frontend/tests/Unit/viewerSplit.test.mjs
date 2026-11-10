import { test } from 'node:test';
import assert from 'node:assert/strict';
import { remToPx, isNarrowViewer } from '../../src/utils/viewerSplit.js';

test('remToPx: rem podle písma kořene', () => {
  assert.equal(remToPx('55rem', 16), 880);
  assert.equal(remToPx('55rem', 20), 1100);
  assert.equal(remToPx('68.75rem', 16), 1100);
  assert.equal(remToPx(' 25rem ', 16), 400);
});

test('remToPx: jiná jednotka, nečíslo a neplatné písmo → NaN', () => {
  assert.ok(Number.isNaN(remToPx('880px', 16)));
  assert.ok(Number.isNaN(remToPx('55', 16)));
  assert.ok(Number.isNaN(remToPx('rem', 16)));
  assert.ok(Number.isNaN(remToPx(null, 16)));
  assert.ok(Number.isNaN(remToPx('55rem', 0)));
  assert.ok(Number.isNaN(remToPx('55rem', NaN)));
});

test('isNarrowViewer: hrana prahu — rovná se prahu není úzký', () => {
  assert.equal(isNarrowViewer(879.5, '55rem', 16), true);
  assert.equal(isNarrowViewer(880, '55rem', 16), false);
  assert.equal(isNarrowViewer(880.5, '55rem', 16), false);
});

test('isNarrowViewer: sidebar shell při písmu 16 px', () => {
  // okno − sidebar 260 px
  assert.equal(isNarrowViewer(1280 - 260, '55rem', 16), false);
  assert.equal(isNarrowViewer(1024 - 260, '55rem', 16), true);
  assert.equal(isNarrowViewer(800 - 260, '55rem', 16), true);
});

test('isNarrowViewer: větší písmo posouvá práh', () => {
  // okno 1280, písmo 20 px: sidebar 325 px, práh 1100 px
  assert.equal(isNarrowViewer(1280 - 325, '55rem', 20), true);
  assert.equal(isNarrowViewer(1280 - 325, '55rem', 16), false);
});

test('isNarrowViewer: nespočitatelný vstup → ne úzký', () => {
  assert.equal(isNarrowViewer(500, '880px', 16), false);
  assert.equal(isNarrowViewer(500, '55rem', 0), false);
  assert.equal(isNarrowViewer(NaN, '55rem', 16), false);
});
