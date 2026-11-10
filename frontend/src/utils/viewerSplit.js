// Úzký viewer se seznamem (#133 D58) — čisté funkce pro měření šířky ve
// Viewer.svelte. Práh (`VIEWER_SPLIT_MIN` ve stores/layout.svelte.js) je
// v rem kvůli většímu písmu prohlížeče; na px se přepočítává vždy
// z aktuální velikosti písma kořene, nikdy z konstanty 16.
//
// Práh se předává parametrem — store s runami nejde načíst v unit testu.

/**
 * Délka v rem jako px. Jiná jednotka, nečíselná hodnota nebo neplatná
 * velikost písma → NaN (volající rozhodne, co s tím).
 *
 * @param {string} value            např. '55rem'
 * @param {number} rootFontSizePx   computed font-size kořene v px
 * @returns {number}
 */
export function remToPx(value, rootFontSizePx) {
  const match = /^\s*(\d*\.?\d+)rem\s*$/.exec(String(value));
  if (!match || !Number.isFinite(rootFontSizePx) || rootFontSizePx <= 0) {
    return NaN;
  }
  return parseFloat(match[1]) * rootFontSizePx;
}

/**
 * Je viewer užší než seznam + nejužší rozumný detail? Šířka rovná prahu
 * úzká není. Nespočitatelný práh nebo šířka → false (seznam a detail vedle
 * sebe jako před D58).
 *
 * @param {number} widthPx          šířka těla vieweru v px
 * @param {string} minWidth         práh v rem (VIEWER_SPLIT_MIN)
 * @param {number} rootFontSizePx   computed font-size kořene v px
 * @returns {boolean}
 */
export function isNarrowViewer(widthPx, minWidth, rootFontSizePx) {
  const min = remToPx(minWidth, rootFontSizePx);
  if (!Number.isFinite(min) || !Number.isFinite(widthPx)) {
    return false;
  }
  return widthPx < min;
}
