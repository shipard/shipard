// Čisté helpery pro druhý řádek buňky Položka v náhledu extrahovaného
// dokladu — napárovaná / zvolená položka a zdroj napárování
// (tasks/exchange-preview-matched-item.md, #111 D1–D2). Bez závislostí na
// Svelte, testovatelné přes `node --test`. Lokalizaci (t()) dělá komponenta.

/**
 * Rozhodnutí uživatele o položce řádku `i` z ploché mapy userActions
 * (`rows[i].item`):
 *   {kind: 'useExisting', id} | {kind: 'noItem'} | {kind: 'skip'} | null.
 * Neplatný tvar (`useExisting:` bez čísla, jiná hodnota) → null.
 */
export function itemDecision(userActions, i) {
  const ua = userActions?.[`rows[${i}].item`] ?? null;
  if (typeof ua !== 'string') return null;
  if (ua.startsWith('useExisting:')) {
    const id = Number(ua.slice('useExisting:'.length));
    return Number.isInteger(id) && id > 0 ? { kind: 'useExisting', id } : null;
  }
  if (ua === 'noItem') return { kind: 'noItem' };
  if (ua === 'skip') return { kind: 'skip' };
  return null;
}

/**
 * Klíč zdroje napárování pro i18n (`exchange.preview.match.source.*`):
 *   user            — ruční volba (pin) nebo položka založená z náhledu
 *   historyExact / historyFuzzy / historyDominant / contentTag
 *                   — řádek napárovaný přes `ourCode`, který doplnil
 *                     enrichment (display.code === suggested.ourCode)
 *   ourCode / supplierCode / ean / sku / name — podle `item.matchedBy`
 * Bez napárování null.
 */
export function matchSourceKey(itemBlock, enrichment, display, pinned = false) {
  if (pinned || display?.pinned === true) return 'user';
  const by = itemBlock?.matchedBy ?? null;
  if (by === null) return null;
  if (by === 'created') return 'user';
  if (by === 'ourCode') {
    const suggested = enrichment?.suggested?.ourCode ?? null;
    if (suggested !== null && display?.code != null && display.code === suggested) {
      const e = enrichment?.matchedBy ?? null;
      if (e === 'historyExactRaw' || e === 'historyExactNorm') return 'historyExact';
      if (e === 'historyFuzzy') return 'historyFuzzy';
      if (e === 'historyDominantItem') return 'historyDominant';
      if (e === 'contentTag') return 'contentTag';
    }
    return 'ourCode';
  }
  if (by === 'supplierCode' || by === 'ean' || by === 'sku' || by === 'name') return by;
  return 'ourCode';
}

/** Slabé zdroje — druhý řádek jantarově, uživatel má ověřit (D2). */
export function isWeakSource(key) {
  return key === 'historyFuzzy' || key === 'historyDominant' || key === 'contentTag' || key === 'name';
}

/**
 * Zdroj napárování je z enrichmentu (tooltip nese jeho detail — zdrojový
 * doklad, doplněná pole, upozornění k odpočtu DPH).
 */
export function isEnrichmentSource(key) {
  return key === 'historyExact' || key === 'historyFuzzy' || key === 'historyDominant' || key === 'contentTag';
}

/**
 * Řádek má druhý řádek (efektivní položka, nebo rozhodnutí noItem / skip)
 * — ⟲ enrichment badge se pak nekreslí (U1).
 */
export function hasSecondLine(decision, display) {
  if (decision?.kind === 'noItem' || decision?.kind === 'skip') return true;
  if (decision?.kind === 'useExisting') return true;
  return display != null;
}
