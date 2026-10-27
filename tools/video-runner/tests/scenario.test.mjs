import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, test } from 'node:test';

import { DEFAULT_CLOCK_TIME, loadScenario } from '../src/scenario.mjs';

const dir = await mkdtemp(join(tmpdir(), 'video-runner-'));
after(() => rm(dir, { recursive: true, force: true }));

let counter = 0;

/** Zapíše scénář do dočasného souboru a načte ho. */
async function load(body) {
  const path = join(dir, `s${counter++}.jsonc`);
  await writeFile(path, JSON.stringify({ id: 'test', dataset: 'demo', ...body }), 'utf8');
  return loadScenario(path);
}

function message(promise) {
  return promise.then(() => null, (error) => error.message);
}

function failure(promise) {
  return promise.then(() => null, (error) => error);
}

test('výchozí capture a odvozený viewport', async () => {
  const scenario = await load({ steps: [{ goto: '/app/' }] });
  assert.deepEqual(scenario.capture, { w: 2560, h: 1600, scale: 2 });
  assert.deepEqual(scenario.viewport, { width: 1280, height: 800 });
});

test('capture nedělitelné scale je chyba', async () => {
  const text = await message(load({ capture: { w: 1001, h: 800, scale: 2 }, steps: [{ goto: '/app/' }] }));
  assert.match(text, /dělitelné scale/);
});

test('verb se odvodí a pause funguje jako modifikátor', async () => {
  const scenario = await load({ steps: [{ hover: '.x', pause: 1 }, { pause: 2 }] });
  assert.equal(scenario.steps[0].verb, 'hover');
  assert.equal(scenario.steps[1].verb, 'pause');
});

test('dva verby v jednom kroku', async () => {
  const text = await message(load({ steps: [{ hover: '.x', click: '.y' }] }));
  assert.match(text, /krok #1: víc verbů/);
});

test('překlep v názvu verbu', async () => {
  const text = await message(load({ steps: [{ hovr: '.x' }] }));
  assert.match(text, /krok #1: neznámý klíč hovr/);
});

test('goto bez lomítka', async () => {
  const text = await message(load({ steps: [{ goto: 'https://example.dev/app/' }] }));
  assert.match(text, /krok #1: goto chce cestu/);
});

test('caption smí být null, jinak jen text', async () => {
  const scenario = await load({ steps: [{ caption: null }] });
  assert.equal(scenario.steps[0].caption, null);

  const text = await message(load({ steps: [{ caption: 7 }] }));
  assert.match(text, /caption chce text nebo null/);
});

test('for jen u highlight, travel jen u přejezdu', async () => {
  assert.match(await message(load({ steps: [{ hover: '.x', for: 1 }] })), /for dává smysl jen u highlight/);
  assert.match(await message(load({ steps: [{ waitFor: '.x', travel: 1 }] })), /travel dává smysl jen u hover a click/);
});

test('prázdný selektor', async () => {
  assert.match(await message(load({ steps: [{ click: '  ' }] })), /neprázdný selektor/);
});

test('@ selektor: platný projde, překlep spadne s číslem kroku', async () => {
  const scenario = await load({
    steps: [{ click: '@nav-viewer:core.mail.incoming' }, { waitFor: '@viewer-rows' }],
  });
  // Krok si nese selektor tak, jak byl ve scénáři — překládá se až u locatoru.
  assert.equal(scenario.steps[0].click, '@nav-viewer:core.mail.incoming');

  const text = await message(load({ steps: [{ pause: 1 }, { highlight: '@ne platný!' }] }));
  assert.match(text, /krok #2: highlight má neplatný @ selektor/);
});

test('scénář bez kroků', async () => {
  assert.match(await message(load({ steps: [] })), /nemá žádné kroky/);
});

test('id musí být slug', async () => {
  const path = join(dir, 'bad-id.jsonc');
  await writeFile(
    path,
    JSON.stringify({ id: 'Test Scénář', dataset: 'demo', steps: [{ pause: 1 }] }),
    'utf8',
  );
  assert.match(await message(loadScenario(path)), /id z malých písmen/);
});

test('scroll chce nenulové číslo, over jen u něj', async () => {
  assert.match(await message(load({ steps: [{ scroll: 0 }] })), /scroll musí být nenulové/);
  assert.match(await message(load({ steps: [{ scroll: 'dolu' }] })), /scroll musí být nenulové/);
  assert.match(await message(load({ steps: [{ hover: '.x', over: 1 }] })), /over dává smysl jen u scroll/);

  const scenario = await load({ steps: [{ scroll: -400, over: 0.8 }] });
  assert.equal(scenario.steps[0].verb, 'scroll');
  assert.equal(scenario.steps[0].scroll, -400);
  assert.equal(scenario.steps[0].over, 0.8);
});

test('jazyk a zóna mají výchozí hodnotu a validují se', async () => {
  const implicit = await load({ steps: [{ pause: 1 }] });
  assert.equal(implicit.locale, 'cs-CZ');
  assert.equal(implicit.timezone, 'Europe/Prague');

  const explicit = await load({ locale: 'en-US', steps: [{ pause: 1 }] });
  assert.equal(explicit.locale, 'en-US');

  assert.match(await message(load({ locale: '', steps: [{ pause: 1 }] })), /locale musí být/);
});

test('dataset je povinný a vrací se beze změny', async () => {
  const scenario = await load({ dataset: 'it-servis', steps: [{ pause: 1 }] });
  assert.equal(scenario.dataset, 'it-servis');

  // JSON.stringify klíč s undefined vynechá — scénář pak pole nemá vůbec.
  const error = await failure(load({ dataset: undefined, steps: [{ pause: 1 }] }));
  assert.match(error.message, /Scénář musí mít dataset/);
  assert.match(error.hint, /demo\/datasets\//);
  assert.match(error.hint, /video-run/);
});

test('dataset musí být slug', async () => {
  for (const bad of ['IT servis', 'It-Servis', '-it', '', 7, null, ['it-servis']]) {
    assert.match(
      await message(load({ dataset: bad, steps: [{ pause: 1 }] })),
      /Scénář musí mít dataset/,
      `dataset ${JSON.stringify(bad)} měl spadnout`,
    );
  }
});

test('clockTime má výchozí hodnotu, zadaný se vrací, špatný tvar spadne', async () => {
  const implicit = await load({ steps: [{ pause: 1 }] });
  assert.equal(implicit.clockTime, DEFAULT_CLOCK_TIME);
  assert.equal(implicit.clockTime, '09:30');

  const explicit = await load({ clockTime: '14:05', steps: [{ pause: 1 }] });
  assert.equal(explicit.clockTime, '14:05');

  for (const bad of ['9:30', '09:30:00', '0930', 930, '', null, 'ráno']) {
    assert.match(
      await message(load({ clockTime: bad, steps: [{ pause: 1 }] })),
      /clockTime musí být čas dne ve tvaru HH:MM/,
      `clockTime ${JSON.stringify(bad)} měl spadnout`,
    );
  }

  assert.match(
    await message(load({ clockTime: '24:00', steps: [{ pause: 1 }] })),
    /není platný čas dne/,
  );
  assert.match(
    await message(load({ clockTime: '09:60', steps: [{ pause: 1 }] })),
    /není platný čas dne/,
  );
});
