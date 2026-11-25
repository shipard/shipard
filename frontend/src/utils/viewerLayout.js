// Persistence volby layoutu vieweru (list | grid | tree) — docs/viewer-grid.md
// §7.2 (D10). localStorage, per-DS klíč (stejný vzor jako storageKey
// v theme store — volby různých DS na stejném originu se nesmí míchat).
// Hodnota = JSON mapa {viewerId: 'list'|'grid'|'tree'}. Server-side persistence
// (user settings) až bude potřeba.
import { DATA_SOURCE_ID } from '../api/config.js';

const STORAGE_KEY = DATA_SOURCE_ID
  ? `shpd_viewer_layout:${DATA_SOURCE_ID}`
  : 'shpd_viewer_layout';

function readMap() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

/** Uložená volba layoutu pro viewer, nebo null. Platnost proti
 *  meta.layouts si ověřuje volající (Viewer.svelte). */
export function getViewerLayout(viewerId) {
  const value = readMap()[viewerId];
  return value === 'list' || value === 'grid' || value === 'tree' ? value : null;
}

export function setViewerLayout(viewerId, layout) {
  try {
    const map = readMap();
    map[viewerId] = layout;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
  } catch {
    // localStorage nedostupná (private mode apod.) — volba prostě nepřežije.
  }
}
