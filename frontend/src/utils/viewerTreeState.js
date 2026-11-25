// Paměť rozbalení stromového layoutu vieweru (#169 D22;
// docs/viewer-tree.md). localStorage, klíč per zdroj dat (vzor
// viewerLayout.js — stavy různých zdrojů dat na stejném originu se nesmí
// míchat). Hodnota = JSON mapa {viewerId: [id, …]}.
//
// `null` z getExpanded() znamená „nic uloženo“ (viewer pak rozbalí
// kořeny); prázdná sada je platný uložený stav — Sbalit vše.
import { DATA_SOURCE_ID } from '../api/config.js';

const STORAGE_KEY = DATA_SOURCE_ID
  ? `shpd_viewer_tree_open:${DATA_SOURCE_ID}`
  : 'shpd_viewer_tree_open';

function readMap() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

/**
 * Uložená sada rozbalených uzlů vieweru, nebo null. Chybná data jsou jako
 * nic uloženo.
 *
 * @param {string} viewerId
 * @returns {Set<number>|null}
 */
export function getExpanded(viewerId) {
  const value = readMap()[viewerId];
  if (!Array.isArray(value)) return null;
  return new Set(value.filter(id => Number.isInteger(id)));
}

export function setExpanded(viewerId, expandedIds) {
  try {
    const map = readMap();
    map[viewerId] = [...expandedIds];
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
  } catch {
    // localStorage nedostupná (private mode apod.) — rozbalení nepřežije.
  }
}
