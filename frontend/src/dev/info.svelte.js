/**
 * `GET /_dev/api/info` — hostname, adresář zdrojů dat a dostupnost sekce
 * Testování. Načítá se jednou při startu, stránky čtou `devInfo.value`.
 */
import { getJson } from './api.js';

let value = $state(null);
let error = $state(null);
let loaded = false;

export const devInfo = {
  get value() {
    return value;
  },
  get error() {
    return error;
  },
  async load() {
    if (loaded) return value;
    loaded = true;
    try {
      value = await getJson('/info');
      error = null;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
    return value;
  },
};
