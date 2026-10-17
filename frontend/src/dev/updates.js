/**
 * Čisté funkce štítku aktualizace v pruhu dashboardu (#129 D19, D20):
 * druh štítku podle stavu z `GET /_dev/api/updates`, stáří, texty pojistek
 * a chyb kontroly ze slovníku (`updates.*`). Bez prohlížeče, testovatelné
 * v Node (tests/Unit/dev/updates.test.mjs).
 */
import { t, has, label } from './i18n/index.js';
import { isActiveStatus } from './testing/jobs.js';

/** Nejstarší chybějící změna starší než tolik dní = plný štítek. */
export const OLD_AFTER_DAYS = 7;

/**
 * Co štítek ukazuje: `reload` po úspěšné aktualizaci, `updating` při běžící
 * úloze, `behind` když je checkout pozadu (a stav platí pro dnešní HEAD),
 * `unverified` když kontrola selhala, jinak null = bez štítku (aktuální,
 * nebo stav zatím neznámý).
 *
 * @param {{state?: object|null, head?: {hash?: string|null}|null}|null} status
 * @param {{status?: string}|null} job běžící úloha `update`
 * @param {boolean} reloadNeeded
 * @returns {'reload'|'updating'|'behind'|'unverified'|null}
 */
export function chipKind(status, job, reloadNeeded = false) {
  if (reloadNeeded) return 'reload';
  if (job && isActiveStatus(job.status)) return 'updating';
  const state = status?.state ?? null;
  if (!state) return null;
  if (state.ok === false) return 'unverified';
  if (!headMatches(status)) return null;
  return (state.behind ?? 0) > 0 ? 'behind' : null;
}

/** Stav z cache platí pro dnešní HEAD (po lokálním commitu nebo merge čísla neplatí). */
export function headMatches(status) {
  const cached = status?.state?.head?.hash ?? null;
  const current = status?.head?.hash ?? null;
  return Boolean(cached && current && cached === current);
}

/** Celé dny od `iso` do `now` (záporné = v budoucnu → 0); null bez data. */
export function daysSince(iso, now = Date.now()) {
  const ts = toTime(iso);
  if (ts === null) return null;
  return Math.max(0, Math.floor((now - ts) / 86_400_000));
}

/** Celé minuty od `iso` do `now`; null bez data. */
export function minutesSince(iso, now = Date.now()) {
  const ts = toTime(iso);
  if (ts === null) return null;
  return Math.max(0, Math.floor((now - ts) / 60_000));
}

export function isOlderThanDays(iso, now = Date.now(), days = OLD_AFTER_DAYS) {
  const d = daysSince(iso, now);
  return d !== null && d > days;
}

/** Text pojistky pro bublinu: `updates.blocker.<kód>` s parametry, neznámý kód obecně. */
export function blockerText(blocker) {
  const code = blocker?.code ?? '';
  const params = {
    branch: blocker?.branch ?? t('updates.blocker.detached'),
    ahead: blocker?.ahead ?? 0,
    jobId: blocker?.jobId ?? '?',
    type: label('jobs.type', blocker?.type),
    tools: Array.isArray(blocker?.tools) ? blocker.tools.join(', ') : '',
    reason: blocker?.reason ?? '',
    code,
  };
  return has(`updates.blocker.${code}`) ? t(`updates.blocker.${code}`, params) : t('updates.blocker.other', params);
}

/** Věta k selhané kontrole: přeložený kód + anglický detail ze serveru. */
export function checkErrorText(state) {
  const short = label('updates.error', state?.errorCode ?? 'unknown');
  return state?.error ? `${short} (${state.error})` : short;
}

function toTime(iso) {
  if (!iso) return null;
  const ts = new Date(iso).getTime();
  return Number.isNaN(ts) ? null : ts;
}
