/**
 * Brána tlačítek Použít v kontrole návrhu (DocumentExchangePreviewModal):
 * které reference z bloku `_resolve` ještě čekají na rozhodnutí uživatele.
 *
 * Čistá funkce bez Svelte — sdílí ji modal (canApply) a marker
 * `review-state` pro automatizaci (#103 F7a): proklikání sady z něj čte
 * přesnou blokující cestu i tam, kde náhled žádný odznak nekreslí
 * (bankovní účet strany).
 *
 * Pravidla (D17, #76):
 *  - strany `supplier` / `customer` / `supplierBank` / `customerBank`:
 *    nenapárovaný blok bez rozhodnutí čeká;
 *  - `rows[i].item`: nenapárovaná položka bez rozhodnutí čeká; jednotky
 *    se nerozhodují (výchozí applieru);
 *  - `rows[i].vatCode` se stavem `notFound` potřebuje `useCode:<kód>`
 *    z nabídky `vatCodeOptions` — volba mimo nabídku by skončila
 *    `validation_failed` při apply.
 *
 * @param {object|null|undefined} resolve  blok `canonical._resolve` z náhledu
 * @param {Record<string, string|null>} userActions  plochá mapa rozhodnutí
 * @returns {string[]} cesty čekající na rozhodnutí; prázdné pole = lze použít
 */
export function pendingPaths(resolve, userActions) {
  if (!resolve) return [];
  const ua = userActions ?? {};
  const decided = (path) => ua[path] !== undefined && ua[path] !== null;
  const pending = [];

  for (const key of ['supplier', 'customer', 'supplierBank', 'customerBank']) {
    const block = resolve[key];
    if (!block) continue;
    if (block.status === 'matched') continue;
    if (decided(key)) continue;
    pending.push(key);
  }

  const rows = resolve.rows ?? [];
  for (let i = 0; i < rows.length; i++) {
    const itemBlock = rows[i]?.item;
    if (!itemBlock) continue;
    if (itemBlock.status === 'matched') continue;
    const path = `rows[${i}].item`;
    if (decided(path)) continue;
    pending.push(path);
  }

  const vatCodes = new Set((resolve.vatCodeOptions ?? []).map((o) => o.code));
  for (let i = 0; i < rows.length; i++) {
    if (rows[i]?.vatCode?.status !== 'notFound') continue;
    const path = `rows[${i}].vatCode`;
    const chosen = ua[path];
    if (typeof chosen === 'string' && chosen.startsWith('useCode:')
        && vatCodes.has(chosen.slice('useCode:'.length))) continue;
    pending.push(path);
  }

  return pending;
}
