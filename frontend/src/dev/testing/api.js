/**
 * Volání API sekce Testování (`/_dev/api/testing/…`): prohlížeč výsledků
 * (DevTestingController, jen GET), úlohy na pozadí (DevJobsController)
 * a spouštěcí akce evaluací + editor variant (DevEvalController, F6b).
 * Identifikátory (sada, soubor běhu, messageId, úloha, varianta) jdou do
 * cesty zakódované; server je stejně hledá jen ve výpisu.
 */
import { getJson, postJson, putJson, apiUrl } from '../api.js';

const enc = encodeURIComponent;

function query(params) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  }
  const q = qs.toString();
  return q ? `?${q}` : '';
}

export const testingApi = {
  datasets: () => getJson('/testing/datasets'),
  runs: (set) => getJson(`/testing/datasets/${enc(set)}/runs`),
  run: (set, file) => getJson(`/testing/runs/${enc(set)}/${enc(file)}`),
  message: (set, file, messageId, repeat = 1) =>
    getJson(`/testing/runs/${enc(set)}/${enc(file)}/messages/${enc(messageId)}?repeat=${enc(repeat)}`),
  messageRuns: (set, messageId) => getJson(`/testing/datasets/${enc(set)}/messages/${enc(messageId)}/runs`),
  compare: (set, a, b) => getJson(`/testing/compare/${enc(set)}?a=${enc(a)}&b=${enc(b)}`),
  pdfUrl: (set, messageId) => apiUrl(`/testing/datasets/${enc(set)}/messages/${enc(messageId)}/pdf`),

  // Evaluační zdroje dat a akce (F6b D54, D55, D61, D63)
  dataSources: (set) => getJson(`/testing/eval/data-sources${query({ dataset: set })}`),
  // `replaceDataset` jen jako true — výměna držené sady za jinou chce výslovné potvrzení (D66), jinak server vrátí 409 DATASET_MISMATCH
  reseed: (set, dataSource, { replaceDataset = false } = {}) =>
    postJson('/testing/eval/reseed', { dataset: set, dataSource, ...(replaceDataset ? { replaceDataset: true } : {}) }),
  create: (set) => postJson('/testing/eval/create', { dataset: set }),
  verify: (set, dataSource, apply = true) => postJson('/testing/eval/verify', { dataset: set, dataSource, apply }),
  // Proklikání sady v prohlížeči (F7a D88): přeseedování + Playwright + dataset-verify jako úloha `e2e`
  startE2e: (set, dataSource) => postJson('/testing/eval/e2e', { dataset: set, dataSource }),
  estimate: (body) => postJson('/testing/eval/estimate', body),
  startEval: (body) => postJson('/testing/eval/run', body), // ne `run` — ten je detail běhu výše

  // Úlohy na pozadí (D58)
  jobs: ({ dataset, ds, days } = {}) => getJson(`/testing/jobs${query({ dataset, ds, days })}`),
  job: (id, offset = 0, tail = undefined) => getJson(`/testing/jobs/${enc(id)}${query({ offset, tail })}`),
  cancelJob: (id, force = false) => postJson(`/testing/jobs/${enc(id)}/cancel${force ? '?force=1' : ''}`, { force }),
  // Trace selhané karty proklikání (D96) — server vydá jen soubory, které jmenuje report úlohy
  jobFileUrl: (id, name) => apiUrl(`/testing/jobs/${enc(id)}/files/${enc(name)}`),

  // Editor variant (D62)
  variants: () => getJson('/testing/eval/variants'),
  variant: (name) => getJson(`/testing/eval/variants/${enc(name)}`),
  saveVariant: (name, body) => putJson(`/testing/eval/variants/${enc(name)}`, body),
  variantOptions: (dataSource) => getJson(`/testing/eval/variants/options${query({ dataSource })}`),
};
