/**
 * Čisté funkce nad úlohami na pozadí (F6b D58, D61) — popisky stavů
 * (slovník dashboardu, klíče `jobs.*`; neznámý kód ze serveru se ukáže,
 * jak přišel) a redukce událostí průběhu `ai-eval` (`progress.jsonl`:
 * started / call / finished / error) do souhrnu pro detail úlohy. Bez
 * prohlížeče, testovatelné v Node (tests/Unit/dev/jobs.test.mjs).
 */
import { t, has, label } from '../i18n/index.js';

export const ACTIVE_STATUSES = ['starting', 'running', 'cancelling'];

export const jobStatusLabel = (status) => label('jobs.status', status);
export const jobTypeLabel = (type) => label('jobs.type', type);
export const stepStatusLabel = (status) => label('jobs.step', status);
export const statusReasonLabel = (reason) => label('jobs.reason', reason);

export function isActiveStatus(status) {
  return ACTIVE_STATUSES.includes(status);
}

/** Třída odznaku `dev-lvl--*` / `dev-state--*` podle stavu úlohy. */
export function jobStatusClass(status) {
  switch (status) {
    case 'finished':
      return 'ok';
    case 'failed':
      return 'bad';
    case 'cancelled':
    case 'cancelling':
      return 'warn';
    default:
      return 'neutral';
  }
}

/** Konec běhu `ai-eval` (`stoppedBy`): popisek, nebo null pro neznámý / žádný důvod. */
export function stoppedByLabel(stoppedBy) {
  return has(`jobs.stoppedBy.${stoppedBy}`) ? t(`jobs.stoppedBy.${stoppedBy}`) : null;
}

/** Výchozí strop útraty = 1,5 × odhad, zaokrouhleno nahoru na cent, nejméně 0,01 USD (D61). */
export function suggestedCap(total) {
  if (typeof total !== 'number' || !(total > 0)) return null;
  // toFixed před ceil: 0.1 × 150 = 15.000000000000002 by jinak dalo 0,16
  return Math.max(0.01, Math.ceil(Number((total * 150).toFixed(6))) / 100);
}

export function emptyProgress() {
  return { started: null, done: 0, total: null, spentUsd: 0, maxCostUsd: null, lastCalls: [], finished: null, error: null };
}

/**
 * Přidá další dávku událostí (od offsetu) k souhrnu — append-only, volá se
 * při každém pollingu s `progress.events`.
 *
 * @param {ReturnType<typeof emptyProgress>} summary
 * @param {Array<object>} events
 */
export function reduceProgress(summary, events) {
  const out = { ...summary, lastCalls: [...summary.lastCalls] };
  for (const ev of events ?? []) {
    switch (ev?.event) {
      case 'started':
        out.started = ev;
        if (typeof ev.calls === 'number') out.total = ev.calls;
        if (typeof ev.maxCostUsd === 'number') out.maxCostUsd = ev.maxCostUsd;
        break;
      case 'call':
        out.done = typeof ev.done === 'number' ? ev.done : out.done + 1;
        if (typeof ev.total === 'number') out.total = ev.total;
        if (typeof ev.spentUsd === 'number') out.spentUsd = ev.spentUsd;
        out.lastCalls = [...out.lastCalls, ev].slice(-5);
        break;
      case 'finished':
        out.finished = ev;
        if (typeof ev.spentUsd === 'number') out.spentUsd = ev.spentUsd;
        break;
      case 'error':
        out.error = ev.message ?? t('jobs.progress.error');
        break;
      default:
        break;
    }
  }
  return out;
}
