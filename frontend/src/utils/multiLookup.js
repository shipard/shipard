// Čisté funkce prvku formuláře `multilookup` (#176) — výběr více záznamů
// z tabulky. Hodnota prvku je seznam id odkazovaných záznamů v pořadí
// výběru; popisky štítků nese `dataResolved[column]` jako seznam položek
// `{id, primary, secondary}`. Funkce seznamy nikdy nemění v místě — snapshot
// formuláře sdílí referenci na původní pole.

const sameId = (a, b) => String(a) === String(b);

/** Hodnota prvku jako pole (cokoli jiného = prázdný seznam). */
export function selectedIds(value) {
  return Array.isArray(value) ? value : [];
}

/** Je id mezi vybranými? Porovnává se textově — id může přijít jako číslo i řetězec. */
export function isSelected(ids, id) {
  return selectedIds(ids).some((x) => sameId(x, id));
}

/** Položky nabídky bez těch, které už jsou vybrané (D6). */
export function withoutSelected(items, ids) {
  const selected = new Set(selectedIds(ids).map(String));
  return (Array.isArray(items) ? items : []).filter((item) => !selected.has(String(item.id)));
}

/** Přidá id na konec; už vybrané id seznam nemění (duplicitu pole nedovolí, D5). */
export function addId(ids, id) {
  const current = selectedIds(ids);
  return isSelected(current, id) ? current : [...current, id];
}

/** Odebere id. */
export function removeId(ids, id) {
  return selectedIds(ids).filter((x) => !sameId(x, id));
}

/** Odebere poslední id (Backspace v prázdném vstupu, D7). */
export function removeLast(ids) {
  return selectedIds(ids).slice(0, -1);
}

/**
 * Štítky v pořadí hodnoty: popisek z `resolved`, bez něj zástupný `#id`
 * (záznam mezitím zmizel — server ho v `dataResolved` vynechá).
 *
 * @param {Array<number|string>} ids
 * @param {Array<{id: number|string, primary: string, secondary?: string|null}>|null} resolved
 */
export function chipsOf(ids, resolved) {
  const byId = new Map((Array.isArray(resolved) ? resolved : []).map((item) => [String(item.id), item]));
  return selectedIds(ids).map((id) => {
    const item = byId.get(String(id));
    return {
      id,
      primary: item?.primary ?? `#${id}`,
      secondary: item?.secondary ?? null,
    };
  });
}

/**
 * Seznam popisků pro `onResolveChange` po změně výběru: položky v pořadí
 * `ids`, nově vybraná položka přidaná, odebrané pryč. Id bez známého popisku
 * se vynechá (štítek pak ukáže zástupný text).
 */
export function resolvedFor(ids, resolved, added = null) {
  const byId = new Map((Array.isArray(resolved) ? resolved : []).map((item) => [String(item.id), item]));
  if (added) {
    byId.set(String(added.id), { id: added.id, primary: added.primary, secondary: added.secondary ?? null });
  }
  return selectedIds(ids).map((id) => byId.get(String(id))).filter(Boolean);
}

/**
 * Sloupec prvku `multilookup`, ke kterému patří chyba s daným `field`:
 * přesná shoda (`places` — chyba z jádra formuláře), nebo cesta do řádku
 * child tabulky (`places.1.place` — chyba Document třídy, D5). Jinak `null`.
 *
 * @param {string|null|undefined} field
 * @param {Record<string, {type?: string}>} elementMap sloupec → prvek formuláře
 */
export function multiLookupErrorColumn(field, elementMap) {
  if (typeof field !== 'string' || field === '') return null;
  if (elementMap?.[field]?.type === 'multilookup') return field;
  for (const [column, element] of Object.entries(elementMap ?? {})) {
    if (element?.type === 'multilookup' && field.startsWith(`${column}.`)) {
      return column;
    }
  }
  return null;
}

/** Připojí zprávu k chybě pole — víc chyb jednoho prvku se spojí, stejná se neopakuje. */
export function joinFieldError(current, message) {
  const text = message ?? '';
  if (!current) return text;
  if (text === '' || current.split('\n').includes(text)) return current;
  return `${current}\n${text}`;
}

const isEmptyList = (v) => v == null || v === '' || (Array.isArray(v) && v.length === 0);

/**
 * Porovnání hodnot pro detekci neuložených změn: dva seznamy jsou stejné,
 * když mají stejný obsah ve stejném pořadí (prvky textově — `1` a `'1'`
 * je totéž id); prázdný seznam je totéž co nevyplněno. `null`, když ani
 * jedna hodnota není pole — rozhoduje pak volající.
 *
 * @returns {boolean|null}
 */
export function sameList(a, b) {
  if (!Array.isArray(a) && !Array.isArray(b)) return null;
  if (isEmptyList(a) && isEmptyList(b)) return true;
  if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
  return a.every((item, i) => (
    item !== null && typeof item === 'object'
      ? JSON.stringify(item) === JSON.stringify(b[i])
      : String(item) === String(b[i])
  ));
}
