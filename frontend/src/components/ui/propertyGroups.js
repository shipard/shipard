// Čisté funkce pro seznam vlastností detailu záznamu (PropertyList,
// PropertyGroups; #137 D29). Kontrakt skupin typu `properties`:
// docs/frontend.md → Formát detail panelu. Hodnota `0` (počet, pořadí) není
// prázdná — prázdné je jen null, undefined a prázdný řetězec.
//
// Volba `emptyValue` (#139 D51): review ukazuje prázdné pole jako „—“
// (kontroluje se vytěžení), detail ho vynechává — s `emptyValue` prázdná
// hodnota zůstává nahrazená textem a skupina se nevynechá; bez ní
// (výchozí `null`) se chování nemění. Položka se `snippet` (Svelte snippet
// `(item)`, kreslí se místo hodnoty) je viditelná vždy; položka se vrací
// celá, aby snippet dostal i vlastní klíče volajícího.

/** Prázdná hodnota se nevykreslí; `0` a `false` prázdné nejsou. */
export function isEmptyValue(value) {
  return value === null || value === undefined || value === '';
}

/**
 * Vykreslitelné položky skupiny: jen s hodnotou (nebo se snippetem, nebo
 * s `emptyValue` místo prázdné hodnoty), popisek vždy řetězec — prázdný
 * popisek (text poznámky) znamená hodnotu přes oba sloupce.
 */
export function visibleItems(items, { emptyValue = null } = {}) {
  return (Array.isArray(items) ? items : [])
    .filter((item) => item && (typeof item.snippet === 'function' || emptyValue !== null || !isEmptyValue(item.value)))
    .map((item) => ({
      ...item,
      label: item.label === null || item.label === undefined ? '' : String(item.label),
      value: isEmptyValue(item.value) && typeof item.snippet !== 'function' ? emptyValue : item.value,
    }));
}

/**
 * Vykreslitelné skupiny v pořadí od backendu (mřížka je čte po řádcích,
 * frontend je nepřehazuje): jen s aspoň jednou položkou; `wide` jen při
 * přesně `true` — skupina pak jde přes celou šířku.
 *
 * `full` (D32) = skupina se kreslí přes celou šířku: je `wide`, nebo by
 * v řádku zůstala sama — stojí v levém sloupci a za ní už žádná viditelná
 * skupina není, nebo je za ní skupina `wide`. Počítá se nad viditelnými
 * skupinami kurzorem sloupce (`wide` kurzor nuluje), ne CSS sudá / lichá —
 * ta by nevěděla, co skupina za ní udělá.
 */
export function visibleGroups(groups, options = {}) {
  const visible = (Array.isArray(groups) ? groups : [])
    .filter((group) => group)
    .map((group) => ({
      title: group.title === null || group.title === undefined ? '' : String(group.title),
      items: visibleItems(group.items, options),
      wide: group.wide === true,
    }))
    .filter((group) => group.items.length > 0);

  let column = 0;
  return visible.map((group, i) => {
    if (group.wide) {
      column = 0;
      return { ...group, full: true };
    }
    if (column === 1) {
      column = 0;
      return { ...group, full: false };
    }
    const next = visible[i + 1];
    const alone = next === undefined || next.wide;
    column = alone ? 0 : 1;
    return { ...group, full: alone };
  });
}
