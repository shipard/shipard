// Proklikání karet návrhů datové sady jako účetní (#103 F7a, D87, D89, D93–D95):
// dashboard → Zkontrolovat → nerozhodnuté reference Vytvořit → Vystavit a
// uzavřít → toast → znovu načtený feed, dokud ve feedu je karta návrhu.
// Jeden test, každá karta `test.step` s id karty; selhání karty se sbírají
// (test pokračuje další kartou) a vyhodnotí na konci. Trace jen u selhaných
// karet (I2). Výsledek `walkthrough.json` v adresáři běhu čte
// `shpd-ds dataset-e2e`. Texty UI se nepoužívají — jen testidy
// (docs/frontend.md § 9).
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  readEnv, CARD_ID_PREFIX, BASE_TIMEOUT_MS, PER_CARD_TIMEOUT_MS,
  messageNdxFromCardId, traceFileName, parsePendingPaths, isUndecided, refPathFromTestId,
  CardFailure, WalkthroughState, summarize,
} from './lib/walkthrough.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const env = readEnv(process.env, { defaultOutputDir: path.join(here, '.out') });
const tracesDir = path.join(env.outputDir, 'traces');

const CARD_SELECTOR = `[data-testid="feed-card"][data-card-id^="${CARD_ID_PREFIX}"]`;
const DASHBOARD_RE = /\/_ui\/dashboard(\?|$)/;
const DECISIONS_RE = /\/_mail\/messages\/\d+\/decisions$/;

const cardLocator = (cardId) => `[data-testid="feed-card"][data-card-id="${cardId}"]`;

/** Čekání na odpověď, které nikdy neskončí neodchyceným odmítnutím (čeká se na něj až později). */
function responseWatcher(page, predicate, timeout) {
  return page.waitForResponse(predicate, { timeout }).then(
    (response) => ({ response, error: null }),
    (error) => ({ response: null, error }),
  );
}

function attachCollector(page, state) {
  page.on('dialog', (dialog) => {
    state.dialog(dialog.type(), dialog.message());
    dialog.dismiss().catch(() => {});
  });
  page.on('pageerror', (error) => state.browserError('pageerror', error?.message ?? String(error)));
  page.on('console', (msg) => {
    // „Failed to load resource: … status of 404" je jen ozvěna odpovědi,
    // kterou už eviduje posluchač `response` (status, metoda, URL) — jako
    // chyba prohlížeče by každou 4xx počítal dvakrát.
    if (msg.type() === 'error' && !/^Failed to load resource/.test(msg.text())) state.browserError('console', msg.text());
  });
  page.on('response', (response) => {
    if (response.status() >= 400) state.apiResponse(response.status(), response.request().method(), response.url());
  });
}

async function loadDashboard(page) {
  const dashboard = responseWatcher(page, (r) => DASHBOARD_RE.test(r.url()) && r.request().method() === 'GET', 30_000);
  await page.goto(env.appUrl);
  if (await page.getByTestId('login-name').count()) {
    throw new Error('Aplikace ukazuje přihlášení — session ve storageState neplatí (origin nebo token).');
  }
  await expect(page.getByTestId('dashboard')).toBeVisible({ timeout: 30_000 });
  const first = await dashboard;
  if (first.error) throw new Error(`Dashboard neodpověděl: ${first.error.message}`);
  await expect(page.getByTestId('feed')).toBeVisible();
}

/** Pásmo ready je sbalené — karty v něm jsou v DOM až po rozbalení. */
async function expandReady(page) {
  const toggle = page.getByTestId('feed-ready-toggle');
  if ((await toggle.count()) === 0) return;
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
}

/** Id karet návrhů v pořadí DOM = pořadí serveru (D94). */
async function cardIds(page) {
  return page.locator(CARD_SELECTOR).evaluateAll((els) => els.map((el) => el.getAttribute('data-card-id')).filter(Boolean));
}

async function pickNextCard(page, processed) {
  return (await cardIds(page)).find((id) => !processed.has(id)) ?? null;
}

async function openReview(page, cardId) {
  try {
    await page.locator(cardLocator(cardId)).getByTestId('card-action-review').click();
    await expect(page.getByTestId('review-modal')).toBeVisible();
  } catch (error) {
    throw CardFailure.from(error, 'open_review');
  }
  try {
    await expect(
      page.locator('[data-testid="review-state"][data-loaded="true"], [data-testid="review-error"]').first(),
    ).toBeAttached({ timeout: 30_000 });
  } catch (error) {
    throw CardFailure.from(error, 'preview');
  }
  const loadError = page.getByTestId('review-error');
  if (await loadError.count()) {
    throw new CardFailure('preview', 'load_error', { message: ((await loadError.textContent()) ?? '').trim().slice(0, 500) });
  }
  if ((await page.getByTestId('review-state').getAttribute('data-ai-failed')) === 'true') {
    throw new CardFailure('preview', 'ai_failed');
  }
}

async function applyButton(page) {
  const final = page.getByTestId('review-apply-final');
  if (await final.count()) return final;
  return page.getByTestId('review-apply-registry');
}

/** První odznak čekající reference (jen cesty z `data-pending-paths`), nebo null. */
async function findUndecidedBadge(page, pending) {
  const infos = await page
    .locator('[data-testid^="review-ref:"]')
    .evaluateAll((els) => els.map((el) => ({ testid: el.getAttribute('data-testid'), status: el.getAttribute('data-status') })));
  for (const info of infos) {
    const refPath = refPathFromTestId(info.testid);
    if (refPath && pending.includes(refPath) && isUndecided(info.status)) {
      return { path: refPath, locator: page.locator(`[data-testid="review-ref:${refPath}"]`).first() };
    }
  }
  return null;
}

/** Rozhodnutí Vytvořit (D93): odznak → popover → Vytvořit → formulář → Uložit → odznak rozhodnutý → uložená rozhodnutí. */
async function decideByCreate(page, target) {
  const { path: refPath, locator } = target;
  const dialog = page.getByTestId('review-ref-dialog');
  try {
    await locator.click();
    await expect(dialog).toBeVisible();
  } catch (error) {
    throw CardFailure.from(error, 'decide', { path: refPath });
  }
  const create = dialog.getByTestId(`review-ref-create:${refPath}`);
  if ((await create.count()) === 0) {
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden().catch(() => {});
    throw new CardFailure('decide', 'no_create_offered', { path: refPath });
  }
  const decisions = responseWatcher(page, (r) => DECISIONS_RE.test(r.url()) && r.request().method() === 'POST', 20_000);
  await create.click();
  const form = page.getByTestId('form-dialog').last();
  try {
    await expect(form).toBeVisible();
    await form.getByTestId('form-save').click();
    await expect(form).toBeHidden({ timeout: 20_000 });
  } catch (error) {
    throw new CardFailure('decide', 'create_failed', { path: refPath, message: CardFailure.from(error).extra.message ?? null });
  }
  try {
    await expect(locator).not.toHaveAttribute('data-status', /^(canCreate|ambiguous|notFound)$/);
  } catch {
    throw new CardFailure('decide', 'decision_timeout', { path: refPath });
  }
  const saved = await decisions;
  if (saved.error) throw new CardFailure('decide', 'decisions_not_saved', { path: refPath });
  if (saved.response.status() >= 400) {
    throw new CardFailure('decide', `decisions_rejected:${saved.response.status()}`, { path: refPath });
  }
}

/** Rozhoduje čekající reference, dokud není Vystavit a uzavřít aktivní; vrací tlačítko. */
async function decidePending(page) {
  const stateEl = page.getByTestId('review-state');
  const button = await applyButton(page);
  for (let round = 0; round < 50; round++) {
    if (await button.isEnabled()) return button;
    const pending = parsePendingPaths(await stateEl.getAttribute('data-pending-paths'));
    if (pending.length === 0) {
      // Nic nečeká — tlačítko se jen ještě neodblokovalo (ukládání rozhodnutí, obnova náhledu).
      try {
        await expect(button).toBeEnabled({ timeout: 15_000 });
        return button;
      } catch {
        throw new CardFailure('apply', 'disabled', { pendingPaths: [], message: 'tlačítko neaktivní bez čekající reference' });
      }
    }
    const target = await findUndecidedBadge(page, pending);
    if (!target) {
      throw new CardFailure('apply', 'disabled', { path: pending[0], pendingPaths: pending, message: 'cesta bez ovládání: reference čeká na rozhodnutí, ale náhled pro ni nemá odznak (pravidlo D98)' });
    }
    await decideByCreate(page, target);
  }
  throw new CardFailure('decide', 'too_many_rounds');
}

async function applyAndSettle(page, state, cardId, button) {
  const ndx = messageNdxFromCardId(cardId);
  const stateEl = page.getByTestId('review-state');
  // Apply nesmí předběhnout ukládání rozhodnutí — pozdní POST /decisions by
  // dopadl na už použitou zprávu (šum 4xx v reportu).
  await expect(stateEl).toHaveAttribute('data-pending-save', 'false');
  const applyRe = new RegExp(`/_mail/messages/${ndx}/apply$`);
  const apply = responseWatcher(page, (r) => applyRe.test(r.url()) && r.request().method() === 'POST', 60_000);
  const dashboard = responseWatcher(page, (r) => DASHBOARD_RE.test(r.url()) && r.request().method() === 'GET', 60_000);
  await button.click();
  const applied = await apply;
  if (applied.error) throw new CardFailure('apply', 'no_response', { message: applied.error.message });
  const response = applied.response;
  const body = await response.json().catch(() => null);
  if (response.status() >= 400) {
    // alert() z dashboardu přichází až po odpovědi.
    await page.waitForTimeout(500);
    throw new CardFailure('apply', `rejected:${body?.error?.code ?? response.status()}`, {
      httpStatus: response.status(),
      message: body?.error?.message ?? null,
      alert: state.lastDialogMessage(),
    });
  }
  const savedDocId = body?.data?.savedDocId ?? null;
  try {
    await expect(page.getByTestId('review-modal')).toBeHidden();
  } catch {
    throw new CardFailure('after_apply', 'modal_open', { savedDocId });
  }
  const reloaded = await dashboard;
  if (reloaded.error) throw new CardFailure('after_apply', 'feed_reload_timeout', { savedDocId });
  try {
    await expect(page.getByTestId('toast')).toBeVisible();
  } catch {
    throw new CardFailure('after_apply', 'toast_timeout', { savedDocId });
  }
  try {
    await expect(page.locator(cardLocator(cardId))).toHaveCount(0);
  } catch {
    throw new CardFailure('after_apply', 'card_not_removed', { savedDocId });
  }
  return savedDocId;
}

/** Po selhané kartě zavřít, co zůstalo otevřené (formulář, popover, modal); jinak znovu načíst dashboard. */
async function recover(page) {
  for (let i = 0; i < 6; i++) {
    if (await page.getByTestId('form-dialog').count()) {
      await page.keyboard.press('Escape');
      const unsaved = page.getByTestId('unsaved-dialog');
      if (await unsaved.count()) await unsaved.getByTestId('confirm-ok').click();
      await page.waitForTimeout(200);
      continue;
    }
    if (await page.getByTestId('review-ref-dialog').count()) {
      await page.keyboard.press('Escape');
      await page.waitForTimeout(200);
      continue;
    }
    if (await page.getByTestId('review-modal').isVisible()) {
      await page.getByTestId('review-close').click();
      const unsaved = page.getByTestId('review-unsaved-dialog');
      if (await unsaved.count()) await unsaved.getByTestId('confirm-ok').click();
      await page.waitForTimeout(200);
      continue;
    }
    return;
  }
  await loadDashboard(page);
}

async function processCard(page, context, testInfo, state, cardId) {
  state.begin(cardId);
  state.write();
  await context.tracing.startChunk({ title: cardId });
  let failure = null;
  let savedDocId = null;
  try {
    await test.step(cardId, async () => {
      await openReview(page, cardId);
      const button = await decidePending(page);
      savedDocId = await applyAndSettle(page, state, cardId, button);
    });
  } catch (error) {
    failure = CardFailure.from(error);
  }
  if (failure === null) {
    await context.tracing.stopChunk();
    state.applied_(savedDocId);
    state.write();
    return;
  }
  // Jediná kopie trace je v traces/ — tu nabízí detail úlohy ke stažení.
  // Jako příloha testu (testInfo.attach) by ležela ještě dvakrát:
  // v test-results/…/attachments/ a v playwright-report/data/ (#146 N10).
  const file = traceFileName(cardId);
  const tracePath = path.join(tracesDir, file);
  await context.tracing.stopChunk({ path: tracePath });
  state.fail(failure, file);
  state.write();
  await recover(page);
}

test.describe.configure({ mode: 'serial' });

test('proklikání karet návrhů datové sady', async ({ page, context }, testInfo) => {
  test.setTimeout(BASE_TIMEOUT_MS + env.expectedCards * PER_CARD_TIMEOUT_MS);
  fs.mkdirSync(tracesDir, { recursive: true });
  const state = new WalkthroughState({ expected: env.expectedCards, outputDir: env.outputDir, progressFile: env.progressFile });
  attachCollector(page, state);
  state.write();

  await context.tracing.start({ screenshots: true, snapshots: true, sources: false });
  await test.step('dashboard', async () => {
    await loadDashboard(page);
  });

  // Pojistka proti nekonečné smyčce: každá karta se bere jen jednou, pár navíc
  // pro karty, které by se po použití objevily znovu.
  const maxCards = env.expectedCards + 5;
  for (let i = 0; i < maxCards; i++) {
    await expandReady(page);
    const cardId = await pickNextCard(page, state.processed);
    if (cardId === null) break;
    await processCard(page, context, testInfo, state, cardId);
  }

  const leftover = (await cardIds(page)).filter((id) => !state.processed.has(id));
  state.finish(leftover.length);
  const walkthroughPath = state.write();
  await context.tracing.stop();
  await testInfo.attach('walkthrough.json', { path: walkthroughPath, contentType: 'application/json' });

  const summary = summarize(state);
  expect.soft(summary.applied, 'použité karty').toBe(env.expectedCards);
  expect.soft(leftover, 'karty návrhů, na které nedošlo').toEqual([]);
  expect.soft(
    state.failed.map((f) => `${f.cardId}: ${f.step}/${f.reason}${f.path ? ` (${f.path})` : ''}`),
    'selhané karty',
  ).toEqual([]);
  expect.soft(state.browserErrors.map((e) => `${e.cardId ?? '-'}: ${e.type}: ${e.message}`), 'chyby prohlížeče').toEqual([]);
  expect.soft(
    state.apiResponses.filter((r) => r.status >= 500).map((r) => `${r.cardId ?? '-'}: ${r.status} ${r.method} ${r.url}`),
    'odpovědi API 5xx',
  ).toEqual([]);
});
