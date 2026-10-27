/**
 * Čisté funkce nad testovacím kolem (#103 F8a D130–D134): popisky vrstev
 * a stavů (slovník dashboardu, klíče `jobs.round*`), třídy odznaků, součty
 * kroků ze souhrnu `result.json` kola ({@see RoundResult} v PHP) a
 * seskupení tabulky kroků po vrstvách. Bez prohlížeče, testovatelné v Node
 * (tests/Unit/dev/round.test.mjs).
 */
import { label, t } from '../i18n/index.js';

export const ROUND_LAYERS = ['code', 'datasets', 'videos'];
export const ROUND_STEP_STATUSES = ['ok', 'failed', 'skipped', 'cancelled', 'running'];

export const roundLayerLabel = (layer) => label('jobs.roundLayer', layer);
export const roundStatusLabel = (status) => label('jobs.roundStatus', status);

/** Třída odznaku `dev-lvl--*` podle stavu kola nebo kroku. */
export function roundStatusClass(status) {
  switch (status) {
    case 'ok':
      return 'ok';
    case 'failed':
      return 'bad';
    case 'skipped':
    case 'cancelled':
      return 'warn';
    default:
      return 'neutral';
  }
}

/** Třída odznaku `dev-state--*` kroku (tabulka kroků). */
export function roundStepClass(status) {
  switch (status) {
    case 'ok':
      return 'match';
    case 'failed':
      return 'wrong';
    case 'skipped':
    case 'cancelled':
      return 'missing';
    default:
      return 'neutral';
  }
}

/** Součty kroků podle stavu — ze `steps` (živě), `summary` serveru jen jako záloha. */
export function roundSummary(result) {
  const out = { ok: 0, failed: 0, skipped: 0, cancelled: 0, running: 0 };
  const steps = Array.isArray(result?.steps) ? result.steps : null;
  if (steps) {
    for (const s of steps) {
      if (s && typeof s.status === 'string' && s.status in out) out[s.status] += 1;
    }
    return out;
  }
  const summary = result?.summary ?? {};
  for (const k of Object.keys(out)) out[k] = typeof summary[k] === 'number' ? summary[k] : 0;
  return out;
}

/** Jedna věta součtů (`testing.round.summary`). */
export function roundSummaryText(result) {
  return t('testing.round.summary', roundSummary(result));
}

/** Kroky seskupené po vrstvách v pořadí {@see ROUND_LAYERS}; neznámá vrstva na konec. */
export function groupRoundSteps(steps) {
  const byLayer = new Map();
  for (const s of steps ?? []) {
    const layer = typeof s?.layer === 'string' ? s.layer : '?';
    if (!byLayer.has(layer)) byLayer.set(layer, []);
    byLayer.get(layer).push(s);
  }
  const order = [...ROUND_LAYERS, ...[...byLayer.keys()].filter((l) => !ROUND_LAYERS.includes(l))];
  return order.filter((l) => byLayer.has(l)).map((layer) => ({ layer, steps: byLayer.get(layer) }));
}

/** Krátký popis výběru kola pro kartu (`layers`, filtry, evaluace). */
export function roundSelectionText(selection) {
  if (!selection) return '';
  const parts = [(selection.layers ?? []).map(roundLayerLabel).join(', ')];
  if (selection.datasets?.length) parts.push(selection.datasets.join(', '));
  if (selection.scenarios?.length) parts.push(selection.scenarios.join(', '));
  if (selection.eval) parts.push(t('testing.round.evalText', { variant: selection.eval.variant, repeat: selection.eval.repeat, cap: selection.eval.maxCostUsd }));
  return parts.join(' · ');
}

/** Poznámka ke kroku: počty testů, shoda polí a cena evaluace, důvod. */
export function roundStepNote(step) {
  const notes = [];
  if (step?.counts && typeof step.counts.tests === 'number') {
    notes.push(t('testing.round.tests', { tests: step.counts.tests, failed: step.counts.failed ?? 0 }));
  }
  if (step?.eval && typeof step.eval.fieldsHit === 'number') {
    notes.push(t('testing.round.evalLine', { hit: `${(step.eval.fieldsHit * 100).toFixed(1)} %`, cost: `${Number(step.eval.costUsd ?? 0).toFixed(2)} USD` }));
  }
  if (typeof step?.reason === 'string' && step.reason !== '') notes.push(step.reason);
  return notes.join(' · ');
}
