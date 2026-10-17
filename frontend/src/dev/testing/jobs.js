/**
 * Čisté funkce nad úlohami na pozadí (F6b D58, D61) — popisky stavů
 * a redukce událostí průběhu `ai-eval` (`progress.jsonl`: started / call /
 * finished / error) do souhrnu pro detail úlohy. Bez prohlížeče,
 * testovatelné v Node (tests/Unit/dev/jobs.test.mjs).
 */

export const ACTIVE_STATUSES = ['starting', 'running', 'cancelling'];

export const JOB_STATUS_LABELS = {
  starting: 'startuje',
  running: 'běží',
  cancelling: 'ruší se',
  finished: 'hotovo',
  failed: 'selhala',
  cancelled: 'zrušena',
};

export const JOB_TYPE_LABELS = {
  eval: 'Evaluace',
  verify: 'Ověření zaúčtování',
  reseed: 'Přeseedování',
  create: 'Založení zdroje dat',
  delete: 'Smazání zdroje dat',
};

export const STEP_STATUS_LABELS = {
  pending: 'čeká',
  running: 'běží',
  finished: 'hotovo',
  failed: 'selhal',
  cancelled: 'zrušen',
  skipped: 'přeskočen',
};

export const STATUS_REASON_LABELS = {
  wrapper_not_started: 'obal úlohy se nespustil (viz výstup)',
  wrapper_died: 'proces úlohy zmizel bez dokončení',
};

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

export function stoppedByLabel(stoppedBy) {
  return { maxCost: 'zastaveno stropem útraty', cancelled: 'zrušeno' }[stoppedBy] ?? null;
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
        out.error = ev.message ?? 'chyba';
        break;
      default:
        break;
    }
  }
  return out;
}
