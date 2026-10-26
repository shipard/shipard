// Čisté funkce pro seznam vlastností detailu záznamu (PropertyList,
// PropertyGroups; #137 D29). Kontrakt skupin typu `properties`:
// docs/frontend.md → Formát detail panelu. Hodnota `0` (počet, pořadí) není
// prázdná — prázdné je jen null, undefined a prázdný řetězec.

/** Prázdná hodnota se nevykreslí; `0` a `false` prázdné nejsou. */
export function isEmptyValue(value) {
  return value === null || value === undefined || value === '';
}

/**
 * Vykreslitelné položky skupiny: jen s hodnotou, popisek vždy řetězec —
 * prázdný popisek (text poznámky) znamená hodnotu přes oba sloupce.
 */
export function visibleItems(items) {
  return (Array.isArray(items) ? items : [])
    .filter((item) => item && !isEmptyValue(item.value))
    .map((item) => ({
      label: item.label === null || item.label === undefined ? '' : String(item.label),
      value: item.value,
    }));
}

/**
 * Vykreslitelné skupiny v pořadí od backendu (mřížka je čte po řádcích,
 * frontend je nepřehazuje): jen s aspoň jednou položkou; `wide` jen při
 * přesně `true` — skupina pak jde přes celou šířku.
 */
export function visibleGroups(groups) {
  return (Array.isArray(groups) ? groups : [])
    .filter((group) => group)
    .map((group) => ({
      title: group.title === null || group.title === undefined ? '' : String(group.title),
      items: visibleItems(group.items),
      wide: group.wide === true,
    }))
    .filter((group) => group.items.length > 0);
}
