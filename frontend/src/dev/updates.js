/**
 * Čisté funkce stavu aktualizací v pruhu dashboardu (#129 D19, D20, D23, D24):
 * stav checkoutu vůči stable pro bublinu, štítek u verze, stáří, texty
 * pojistek a chyb kontroly ze slovníku (`updates.*`) a `aria-label` tlačítka
 * verze. Bez prohlížeče, testovatelné
 * v Node (tests/Unit/dev/updates.test.mjs).
 */
import { t, has, label } from './i18n/index.js';
import { isActiveStatus } from './testing/jobs.js';

/** Nejstarší chybějící změna starší než tolik dní = plný štítek. */
export const OLD_AFTER_DAYS = 7;

/**
 * Stav checkoutu vůči stable pro řádek v bublině (D24): `current` = stav
 * platí pro dnešní HEAD a nic nechybí, `behind` = checkout je pozadu,
 * `unverified` = kontrola selhala, `unknown` = bez stavu, nebo stav z cache
 * pro jiný HEAD (po lokálním commitu nebo merge čísla neplatí).
 *
 * @param {{state?: object|null, head?: {hash?: string|null}|null}|null} status
 * @returns {'current'|'behind'|'unverified'|'unknown'}
 */
export function statusKind(status) {
  const state = status?.state ?? null;
  if (!state) return 'unknown';
  if (state.ok === false) return 'unverified';
  if (!headMatches(status)) return 'unknown';
  return (state.behind ?? 0) > 0 ? 'behind' : 'current';
}

/**
 * Co se ukazuje v pruhu: `reload` po úspěšné aktualizaci a `updating` při
 * běžící úloze (samostatné prvky vedle tlačítka verze), `behind` / `unverified`
 * = štítek uvnitř tlačítka verze (D23), jinak null = bez štítku (aktuální,
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
  const kind = statusKind(status);
  return kind === 'behind' || kind === 'unverified' ? kind : null;
}

/** Stav z cache platí pro dnešní HEAD (po lokálním commitu nebo merge čísla neplatí). */
export function headMatches(status) {
  const cached = status?.state?.head?.hash ?? null;
  const current = status?.head?.hash ?? null;
  return Boolean(cached && current && cached === current);
}

/**
 * `aria-label` tlačítka verze v pruhu (D23) s celým stavem: „Verze 0.1.1,
 * 6aaa2a47, stable — 1 commit pozadu“. Bez hashe jen číslo verze, bez
 * štítku bez pomlčky; před načtením údajů obecný popisek.
 *
 * @param {{version: string, hash?: string|null, branch?: string|null}|null} version z `/_dev/api/info`
 * @param {'behind'|'unverified'|null} badge štítek uvnitř tlačítka
 * @param {number} behind
 */
export function versionAriaLabel(version, badge, behind = 0) {
  if (!version) return t('updates.version.ariaUnknown');
  const parts = [version.version, version.hash, version.hash ? version.branch : null].filter(Boolean).join(', ');
  const state = badge === 'behind' ? t('updates.version.ariaBehind', { n: behind }) : badge === 'unverified' ? t('updates.badge.unverified') : null;
  return state === null ? t('updates.version.aria', { version: parts }) : t('updates.version.ariaStatus', { version: parts, state });
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
