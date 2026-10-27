/**
 * Volání API oddílu Videa (`/_dev/api/videos`, DevVideosController, #103 F7b
 * D122, D126, D127): seznam scénářů se stavem, úloha `video` (Ověřit /
 * Natočit), založení zdroje dat pro videa sady a adresa hotového videa
 * (server ho vydá jen podle `meta.json`, s HTTP Range pro posouvání).
 */
import { getJson, postJson, apiUrl } from '../api.js';

const enc = encodeURIComponent;

export const videosApi = {
  list: () => getJson('/videos'),
  /** @param {'check'|'build'} verb */
  run: (id, verb) => postJson(`/videos/${enc(id)}/run`, { verb }),
  createDataSource: (dataset) => postJson('/videos/data-source', { dataset }),
  fileUrl: (id, download = false) => apiUrl(`/videos/${enc(id)}/file${download ? '?download=1' : ''}`),
};
