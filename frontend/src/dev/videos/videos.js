/**
 * Čisté funkce oddílu Videa (#103 F7b): popisky kroků a stavů z průběhu
 * `video-run` (slovník dashboardu, klíče `jobs.videoStep.*` /
 * `jobs.videoStatus.*`), formát délky a velikosti videa, stav posledního
 * ověření a důvod, proč jsou akce Ověřit / Natočit neaktivní. Bez
 * prohlížeče, testovatelné v Node (tests/Unit/dev/videos.test.mjs).
 */
import { t, label } from '../i18n/index.js';

/** Kroky `video-run` v pořadí běhu (`check` a `record` + `compose` se vylučují podle verbu). */
export const VIDEO_STEPS = ['build', 'reseed', 'check', 'record', 'compose'];

export const videoStepLabel = (step) => label('jobs.videoStep', step);
export const videoStatusLabel = (status) => label('jobs.videoStatus', status);

/** `1.8 MiB` / `512 KiB` / `12 B`; jiná hodnota než číslo = pomlčka. */
export function formatBytes(bytes) {
  if (typeof bytes !== 'number' || !(bytes >= 0)) return '—';
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MiB`;
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KiB`;
  return `${bytes} B`;
}

/** `m:ss` z milisekund; jiná hodnota než číslo = pomlčka. */
export function formatDuration(ms) {
  if (typeof ms !== 'number' || !(ms >= 0)) return '—';
  const seconds = Math.round(ms / 1000);
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/** Odznak posledního ověření z `check.json`; null = zatím neověřeno. */
export function checkState(check) {
  if (!check || typeof check.status !== 'string') return null;
  const cls = check.status === 'ok' ? 'ok' : check.status === 'cancelled' ? 'warn' : 'bad';
  return { cls, label: videoStatusLabel(check.status) };
}

/**
 * Proč nejde scénář spustit (text do nápovědy neaktivního tlačítka), nebo
 * null. Pořadí: nástroje na serveru → neplatný scénář → sada mimo
 * katalog → chybějící zdroj dat → běžící úloha zdroje dat.
 */
export function blockerText(row, toolProblems = []) {
  if (Array.isArray(toolProblems) && toolProblems.length) return toolProblems.join(' ');
  if (row?.problem) return row.problem;
  if (!row?.datasetKnown) return t('videos.blocker.dataset', { set: row?.dataset ?? '?' });
  if (!row?.dataSource) return t('videos.blocker.noDataSource', { set: row.dataset });
  if (row?.activeJob) return t('common.runningJob', { id: row.activeJob.id });
  return null;
}

/** Zdroje dat per sada ze seznamu scénářů (každá sada jednou, v pořadí prvního výskytu). */
export function datasetsOf(rows) {
  const map = new Map();
  for (const row of rows ?? []) {
    if (!row?.dataset || map.has(row.dataset)) continue;
    map.set(row.dataset, { name: row.dataset, title: row.datasetTitle ?? null, known: Boolean(row.datasetKnown), dataSource: row.dataSource ?? null, activeJob: row.activeJob ?? null });
  }
  return [...map.values()];
}
