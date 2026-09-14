// Výchozí hodnoty custom filtrů vieweru (meta.filters) — docs/frontend.md
// § Filtry vieweru. Definice filtru může nést `default` (string pro
// select / text, '1' pro checkbox); backend nic dalšího nedělá, hodnotu
// jen pošle v meta. Sloučení s pending filtry z akce open_viewer dělá
// tento modul, aby precedence (pending > default) byla na jednom místě
// a testovatelná bez Svelte.

/** Typy filtrů, které ViewerFilters.svelte umí vykreslit; ostatní
 *  (historický 'enum') se přeskakují — i pro defaulty. */
export const SUPPORTED_FILTER_TYPES = ['select', 'text', 'checkbox'];

export function supportedFilters(filterDefs) {
  return (filterDefs ?? []).filter(f => SUPPORTED_FILTER_TYPES.includes(f?.type));
}

/**
 * Počáteční hodnoty filtrů při otevření vieweru: výchozí hodnoty
 * podporovaných filtrů s neprázdným `default`, přes ně pending filtry
 * z open_viewer (vítězí — volající, který chce jiné období, ho posílá
 * explicitně). Hodnoty jsou vždy stringy (select porovnává String(value),
 * checkbox '1'); prázdný default se ignoruje. Default závislého selectu
 * (parentFilter) platí jen když má rodič hodnotu — bez ní je select
 * disabled a hodnota by zůstala neviditelně aktivní.
 *
 * @param {Array<{id: string, type: string, default?: unknown, parentFilter?: string}>} filterDefs
 * @param {Record<string, string>|null|undefined} pending
 * @returns {Record<string, string>}
 */
export function initialFilterValues(filterDefs, pending) {
  const defs = supportedFilters(filterDefs);
  const values = {};
  for (const f of defs) {
    const value = f.default == null ? '' : String(f.default);
    if (value !== '') {
      values[f.id] = value;
    }
  }
  for (const [id, value] of Object.entries(pending ?? {})) {
    if (value == null || value === '') {
      delete values[id];
    } else {
      values[id] = String(value);
    }
  }
  for (const f of defs) {
    if (f.parentFilter && values[f.id] != null && !values[f.parentFilter]) {
      delete values[f.id];
    }
  }
  return values;
}
