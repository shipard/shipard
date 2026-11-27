// Sdílená normalizace span hodnot viewer řádků (list i grid layout).
// Span formát viz docs/viewer-grid.md §3.4 a TableViewer::renderRow docblock.

/**
 * Normalize a field value into an array of span objects.
 * Handles: null, string, object {text, class?, icon?, badge?}, or array of those.
 */
export function normalizeSpans(value) {
  if (value == null) return null;
  if (typeof value === 'string') return [{ text: value }];
  if (Array.isArray(value)) return value;
  if (typeof value === 'object' && value.text != null) return [value];
  return null;
}

/**
 * Spany pro zařízení, na kterém se uživatel dívá (#177 D13): span se
 * značkou `nativeDevice` zůstane jen tehdy, když sedí na `deviceId` z mostu
 * nativní aplikace — štítek „toto zařízení“ v seznamu zařízení. Server neví,
 * ze kterého zařízení se uživatel dívá, proto značku posílá a rozhoduje
 * klient. Spany bez značky projdou beze změny.
 *
 * @param {Array<object>|null} spans  výstup `normalizeSpans`
 * @param {string|null|undefined} deviceId  `info.deviceId` mostu; bez mostu `null`
 * @returns {Array<object>|null}
 */
export function spansForDevice(spans, deviceId) {
  if (!Array.isArray(spans)) return spans;
  const known = typeof deviceId === 'string' && deviceId !== '';
  return spans.filter((span) => span?.nativeDevice == null || (known && span.nativeDevice === deviceId));
}
