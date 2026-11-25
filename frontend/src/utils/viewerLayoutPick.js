// Výběr layoutu vieweru (list | grid | tree) — čisté funkce pro
// Viewer.svelte (docs/viewer-grid.md §7.2, docs/viewer-tree.md).
//
// Zvolený layout (uložená volba nebo výchozí z meta) ještě není ten, který
// se kreslí: na telefonu grid degraduje na seznam (grid D2), strom zůstává
// (#169 D24), a layout, který viewer nenabízí, padá na seznam.

/**
 * Layouty použitelné na daném zařízení, v pořadí z meta. Bez meta jen
 * seznam.
 *
 * @param {string[]|null|undefined} layouts  meta.layouts
 * @param {boolean} isMobile
 * @returns {string[]}
 */
export function availableLayouts(layouts, isMobile) {
  const list = Array.isArray(layouts) && layouts.length > 0 ? layouts : ['list'];
  return isMobile ? list.filter(l => l !== 'grid') : list;
}

/** Layout, který se opravdu kreslí (tvar řádků, dotaz na server). */
export function effectiveLayoutOf(chosen, layouts, isMobile) {
  return availableLayouts(layouts, isMobile).includes(chosen) ? chosen : 'list';
}

/**
 * Cíl přepínače: další layout v pořadí, po posledním zase první. Počítá
 * se z layoutu, který se právě kreslí. Když není mezi čím přepínat, vrací
 * `current`.
 */
export function nextLayout(current, layouts, isMobile) {
  const available = availableLayouts(layouts, isMobile);
  if (available.length < 2) return current;
  return available[(available.indexOf(current) + 1) % available.length];
}
