/**
 * Volání API sekce Testování (`/_dev/api/testing/…`, DevTestingController).
 * Identifikátory (sada, soubor běhu, messageId) jdou do cesty zakódované;
 * server je stejně hledá jen ve výpisu.
 */
import { getJson, apiUrl } from '../api.js';

const enc = encodeURIComponent;

export const testingApi = {
  datasets: () => getJson('/testing/datasets'),
  runs: (set) => getJson(`/testing/datasets/${enc(set)}/runs`),
  run: (set, file) => getJson(`/testing/runs/${enc(set)}/${enc(file)}`),
  message: (set, file, messageId, repeat = 1) =>
    getJson(`/testing/runs/${enc(set)}/${enc(file)}/messages/${enc(messageId)}?repeat=${enc(repeat)}`),
  messageRuns: (set, messageId) => getJson(`/testing/datasets/${enc(set)}/messages/${enc(messageId)}/runs`),
  compare: (set, a, b) => getJson(`/testing/compare/${enc(set)}?a=${enc(a)}&b=${enc(b)}`),
  pdfUrl: (set, messageId) => apiUrl(`/testing/datasets/${enc(set)}/messages/${enc(messageId)}/pdf`),
};
