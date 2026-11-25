// Stromový layout vieweru (#169 D20, D22; docs/viewer-tree.md) — čisté
// funkce nad řádky stromu. Server posílá plochý seznam v pořadí průchodu
// do hloubky; řádek nese `id`, `parent` (id nebo null), `depth` (od 0)
// a `childCount`. Rozbalení je sada id rozbalených uzlů — nikdy se nemění
// na místě (Set ve $state by reaktivitu nespustil), každá změna vrací
// novou instanci.

/**
 * Řádky, které jsou vidět: všichni předci řádku jsou rozbalení. Jeden
 * průchod — stačí pamatovat si hloubku nejbližšího sbaleného předka.
 *
 * @param {Array<object>} rows
 * @param {Set<number>|null} expandedIds
 * @param {boolean} expandAll  true = všechno rozbalené, sada se nečte
 * @returns {Array<object>}
 */
export function visibleTreeRows(rows, expandedIds, expandAll = false) {
  const out = [];
  let collapsedDepth = null;
  for (const row of rows ?? []) {
    if (collapsedDepth !== null) {
      if (row.depth > collapsedDepth) continue;
      collapsedDepth = null;
    }
    out.push(row);
    if (row.childCount > 0 && !isExpanded(row, expandedIds, expandAll)) {
      collapsedDepth = row.depth;
    }
  }
  return out;
}

/** Je uzel rozbalený? Uzel bez dětí není nikdy. */
export function isExpanded(row, expandedIds, expandAll = false) {
  if (!(row.childCount > 0)) return false;
  return expandAll || (expandedIds?.has(row.id) ?? false);
}

/**
 * Id předků řádku shora dolů (kořen první), bez řádku samotného. Neznámé
 * id nebo řádek nejvyšší úrovně → prázdné pole. Cyklus v datech průchod
 * ukončí.
 *
 * @param {Array<object>} rows
 * @param {number} id
 * @returns {number[]}
 */
export function ancestorIds(rows, id) {
  const parents = new Map();
  for (const row of rows ?? []) {
    parents.set(row.id, row.parent ?? null);
  }
  const chain = [];
  const seen = new Set([id]);
  let current = parents.get(id) ?? null;
  while (current != null && !seen.has(current) && parents.has(current)) {
    seen.add(current);
    chain.push(current);
    current = parents.get(current) ?? null;
  }
  return chain.reverse();
}

/** Výchozí rozbalení bez uloženého stavu (D22): kořeny s dětmi — vidět je druhá úroveň. */
export function defaultExpanded(rows) {
  return new Set((rows ?? []).filter(r => r.depth === 0 && r.childCount > 0).map(r => r.id));
}

/** Všechny uzly, které jde rozbalit (mají děti) — Rozbalit vše. */
export function expandableIds(rows) {
  return new Set((rows ?? []).filter(r => r.childCount > 0).map(r => r.id));
}

/** Nová sada s přepnutým uzlem. */
export function toggleExpanded(expandedIds, id) {
  const next = new Set(expandedIds ?? []);
  if (next.has(id)) {
    next.delete(id);
  } else {
    next.add(id);
  }
  return next;
}

/**
 * Nová sada s přidanými uzly; když v ní už všechny jsou, vrací tutéž
 * instanci (volající pozná, že není co ukládat).
 */
export function withExpanded(expandedIds, ids) {
  const missing = (ids ?? []).filter(id => !(expandedIds?.has(id) ?? false));
  if (missing.length === 0 && expandedIds != null) return expandedIds;
  return new Set([...(expandedIds ?? []), ...missing]);
}

/**
 * Sada omezená na uzly, které ve stromu jsou a mají děti — před uložením,
 * ať v paměti prohlížeče nezůstávají smazané záznamy.
 */
export function pruneExpanded(expandedIds, rows) {
  const expandable = expandableIds(rows);
  return new Set([...(expandedIds ?? [])].filter(id => expandable.has(id)));
}

/**
 * Je strom v dočasném pohledu (D22)? Při hledání, vlastním filtru nebo na
 * jiné záložce stavů než výchozí se strom ukazuje celý rozbalený a nic se
 * neukládá — po návratu platí zase uložené rozbalení. Filtr se počítá jako
 * vlastní, jen když se liší od výchozí hodnoty z definice.
 *
 * @param {{search?: string, viewGroup?: string, defaultViewGroup?: string,
 *          filters?: Record<string, unknown>, defaultFilters?: Record<string, unknown>}} view
 * @returns {boolean}
 */
export function isTemporaryView({ search, viewGroup, defaultViewGroup, filters, defaultFilters }) {
  if (search) return true;
  if (viewGroup !== defaultViewGroup) return true;
  const current = filters ?? {};
  const defaults = defaultFilters ?? {};
  for (const id of new Set([...Object.keys(current), ...Object.keys(defaults)])) {
    if (String(current[id] ?? '') !== String(defaults[id] ?? '')) return true;
  }
  return false;
}
