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
//     return openLayer({ kind: 'popover', transient: true, onEscape: () => onClose() }).remove;
//   });
//
// Dočasnost (#160 D122, D124) je vlastnost vrstvy, ne jejího druhu: dočasnou
// vrstvu (bublina, nabídka pole, menu, panel vzhledu, paleta) zavírá klik
// mimo, nedočasnou (modal, detail v draweru, boční chat, drawer sidebaru)
// ne. Dokud je otevřená dočasná vrstva, zásobník drží na `<html>` atribut
// `data-transient-layer` a `styles/base.css` podle něj vypne klepnutí do
// vložených dokumentů (`<iframe>`) — klepnutí tak dopadne na stránku
// a posluchač kliku mimo vrstvu zavře.
//
// Modul nic neimportuje — přes `ui/Popover` ho sdílí i vývojářský dashboard.

/**
 * @typedef {'modal' | 'popover' | 'menu' | 'panel' | 'palette' | 'drawer'} LayerKind
 *   modal = `ui/Modal`; popover = `ui/Popover`; menu = nabídka pole
 *   a uživatelské menu; panel = postranní panel (detail v draweru, boční
 *   chat, panel vzhledu); palette = paleta příkazů; drawer = drawer
 *   sidebaru na telefonu.
 * @typedef {{ kind: LayerKind, transient: boolean, onEscape: () => void }} Layer
 * @typedef {{ remove: () => void, isTop: () => boolean, depth: number }} LayerHandle
 */

/** @type {Layer[]} Od nejstarší po naposledy otevřenou. */
const stack = [];

/** Atribut na `<html>` po dobu otevřené dočasné vrstvy (D124). */
export const TRANSIENT_ATTRIBUTE = 'data-transient-layer';

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
 * Přepíše příznak dočasné vrstvy na kořeni dokumentu podle zásobníku.
 * Volá se po každé změně zásobníku — i po odebrání vrstvy ze středu.
 */
function syncTransientFlag() {
  if (typeof document === 'undefined') return;
  document.documentElement.toggleAttribute(TRANSIENT_ATTRIBUTE, hasTransientLayer());
}

/**
 * Zapíše vrstvu na vrchol zásobníku.
 *
 * @param {{ kind: LayerKind, transient: boolean, onEscape: () => void }} layer
 *   `transient` je povinné u každé vrstvy: true = zavírá ji klik mimo.
 * @returns {LayerHandle} `remove()` vrstvu odebere odkudkoli (opakované
 *   volání nevadí), `isTop()` říká, zda je naposledy otevřená, `depth` je
 *   počet vrstev téhož druhu otevřených před ní (zmenšení vnořených modalů).
 */
export function openLayer({ kind, transient, onEscape }) {
  if (typeof transient !== 'boolean') {
    throw new TypeError(`openLayer(): vrstva „${kind}“ nemá výslovné transient (true / false)`);
  }
  const layer = { kind, transient, onEscape };
  const depth = stack.filter((l) => l.kind === kind).length;
  stack.push(layer);
  ensureListener();
  syncTransientFlag();
  return {
    remove() {
      const i = stack.indexOf(layer);
      if (i === -1) return;
      stack.splice(i, 1);
      syncTransientFlag();
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
 * Je otevřená dočasná vrstva, kterou má zavřít klik mimo (D124)?
 *
 * Počítají se jen dočasné vrstvy **nad posledním modalem**. Bublina, ze
 * které se otevřel modal (panel rozhodnutí → vnořený formulář), pod ním
 * zůstává otevřená a kliky v něm záměrně ignoruje (`Popover.handleDocClick`)
 * — vložený dokument v tom modalu by jinak klepnutí nepřijal a žádné
 * klepnutí by ho neodemklo.
 */
export function hasTransientLayer() {
  for (let i = stack.length - 1; i >= 0; i -= 1) {
    if (stack[i].kind === 'modal') return false;
    if (stack[i].transient) return true;
  }
  return false;
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
