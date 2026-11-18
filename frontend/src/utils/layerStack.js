// Jeden zásobník vrstev pro Esc (#160 D121). Každá vrstva, která se dá
// zavřít — modal, bublina, nabídka pole, drawer detailu, boční chat, panel
// vzhledu, uživatelské menu, paleta příkazů, drawer sidebaru — se při
// otevření zapíše sem a při zavření či zničení komponenty odebere. Jediný
// posluchač Esc zavře jen vrstvu na vrcholu: jeden stisk = jedna vrstva.
//
// Komponenta vrstvy vlastní posluchač Esc nemá (docs/design-system.md §6,
// hlídá tests/Unit/escapeUsage.test.mjs):
//
//   $effect(() => {
//     if (!open) return;
//     return openLayer({ kind: 'popover', onEscape: () => onClose() }).remove;
//   });
//
// Modul nic neimportuje — přes `ui/Popover` ho sdílí i vývojářský dashboard.

/**
 * @typedef {'modal' | 'popover' | 'menu' | 'panel' | 'palette' | 'drawer'} LayerKind
 *   modal = `ui/Modal`; popover = `ui/Popover`; menu = nabídka pole
 *   a uživatelské menu; panel = postranní panel (detail v draweru, boční
 *   chat, panel vzhledu); palette = paleta příkazů; drawer = drawer
 *   sidebaru na telefonu.
 * @typedef {{ kind: LayerKind, onEscape: () => void }} Layer
 * @typedef {{ remove: () => void, isTop: () => boolean, depth: number }} LayerHandle
 */

/** @type {Layer[]} Od nejstarší po naposledy otevřenou. */
const stack = [];

let listening = false;

function ensureListener() {
  if (listening || typeof window === 'undefined') return;
  listening = true;
  // Fáze bubble na `window` = až po obsluhách v komponentách (delegované
  // události Svelte běží na kořeni aplikace a na `document`). Editace
  // textu, která Esc ruší sama, tak stihne událost označit
  // `preventDefault()` a zásobník ji nechá být.
  window.addEventListener('keydown', handleEscape);
}

/**
 * Zapíše vrstvu na vrchol zásobníku.
 *
 * @param {{ kind: LayerKind, onEscape: () => void }} layer
 * @returns {LayerHandle} `remove()` vrstvu odebere odkudkoli (opakované
 *   volání nevadí), `isTop()` říká, zda je naposledy otevřená, `depth` je
 *   počet vrstev téhož druhu otevřených před ní (zmenšení vnořených modalů).
 */
export function openLayer({ kind, onEscape }) {
  const layer = { kind, onEscape };
  const depth = stack.filter((l) => l.kind === kind).length;
  stack.push(layer);
  ensureListener();
  return {
    remove() {
      const i = stack.indexOf(layer);
      if (i !== -1) stack.splice(i, 1);
    },
    isTop() {
      return stack[stack.length - 1] === layer;
    },
    depth,
  };
}

/** Je otevřená aspoň jedna vrstva daného druhu? */
export function hasLayer(kind) {
  return stack.some((l) => l.kind === kind);
}

/**
 * Obsluha `keydown`: Esc zavře vrstvu, která je na vrcholu v okamžiku
 * stisku. Vrstva otevřená uvnitř `onEscape` (dotaz „Neuložené změny“) se
 * týmž stiskem nezavře — vrchol se čte jednou a posluchač je jediný.
 *
 * Nechá být: jinou klávesu, Esc už spotřebovaný jinde (`defaultPrevented`
 * — přejmenování přílohy), drženou klávesu (`repeat` — jinak by jedno
 * podržení zavřelo všechny vrstvy) a Esc uprostřed skládání znaku (IME).
 *
 * @param {KeyboardEvent} event
 * @returns {boolean} true = vrstva dostala Esc
 */
export function handleEscape(event) {
  if (event.key !== 'Escape') return false;
  if (event.defaultPrevented || event.repeat || event.isComposing) return false;
  const top = stack[stack.length - 1];
  if (!top) return false;
  event.preventDefault();
  top.onEscape();
  return true;
}
