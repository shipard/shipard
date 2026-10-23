/**
 * Brána tlačítek Použít v kontrole návrhu (DocumentExchangePreviewModal)
 * a pravidlo ovládání odznaků v náhledu (DocumentExchangePreview) nad
 * jedním výčtem rozhodovaných referencí bloku `_resolve`.
 *
 * Čisté funkce bez Svelte — sdílí je modal (`canApply`, marker
 * `review-state` s `data-pending-paths` pro proklikání sady, #103 F7a)
 * i náhled (klikatelnost odznaku). Pravidlo D98 (#103): každá cesta,
 * kterou vrací `pendingPaths`, musí mít v náhledu ovládací prvek — hlídá
 * ho test `tests/Unit/reviewGate.test.mjs` nad `reviewReferences` ×
 * `hasControl`; že šablona odznak skutečně vykreslí, hlídá spec
 * proklikání (`apply/disabled` „cesta bez ovládání“).
 *
 * Reference a jejich druhy (`reviewReferences`):
 *  - strany `supplier` / `customer` (`party`);
 *  - bankovní účty stran `supplierBank` / `customerBank` (`bankAccount`,
 *    rodič = strana — panel potřebuje její id);
 *  - položky řádků `rows[i].item` (`item`), jednotky se nerozhodují
 *    (výchozí applieru);
 *  - kódy DPH řádků `rows[i].vatCode` (`vatCode`).
 *
 * Čekání na rozhodnutí (`isPending`, D17, #76): nenapárovaný blok strany,
 * účtu nebo položky bez rozhodnutí; kód DPH se stavem `notFound` potřebuje
 * `useCode:<kód>` z nabídky `vatCodeOptions` — volba mimo nabídku by
 * skončila `validation_failed` při apply.
 */

/** Tabulky entit pro hledání a „Vytvořit“ v panelu rozhodnutí (kód DPH entitu nemá). */
export const REFERENCE_TABLES = Object.freeze({
  party: 'base_persons_persons',
  bankAccount: 'base_persons_bank_accounts',
  item: 'economy_items',
});

const PARTY_PATHS = ['supplier', 'customer'];
const BANK_PARENTS = Object.freeze({ supplierBank: 'supplier', customerBank: 'customer' });

/** Cesta strany, ke které patří bankovní účet (`supplierBank` → `supplier`); jiná cesta → null. */
export function bankParentPath(path) {
  return BANK_PARENTS[path] ?? null;
}

/**
 * Všechny rozhodované reference v pořadí brány (strany, účty stran, položky,
 * kódy DPH) — i napárované, položka a kód DPH jsou klikací i u matched.
 *
 * @param {object|null|undefined} resolve  blok `canonical._resolve` z náhledu
 * @returns {{path: string, kind: 'party'|'bankAccount'|'item'|'vatCode', block: object, parentPath?: string}[]}
 */
export function reviewReferences(resolve) {
  if (!resolve) return [];
  const refs = [];
  for (const path of PARTY_PATHS) {
    if (resolve[path]) refs.push({ path, kind: 'party', block: resolve[path] });
  }
  for (const [path, parentPath] of Object.entries(BANK_PARENTS)) {
    if (resolve[path]) refs.push({ path, kind: 'bankAccount', block: resolve[path], parentPath });
  }
  const rows = resolve.rows ?? [];
  for (let i = 0; i < rows.length; i++) {
    if (rows[i]?.item) refs.push({ path: `rows[${i}].item`, kind: 'item', block: rows[i].item });
  }
  for (let i = 0; i < rows.length; i++) {
    if (rows[i]?.vatCode) refs.push({ path: `rows[${i}].vatCode`, kind: 'vatCode', block: rows[i].vatCode });
  }
  return refs;
}

/**
 * Čeká reference na rozhodnutí uživatele?
 *
 * @param {{path: string, kind: string, block: object}} ref
 * @param {Record<string, string|null>|null|undefined} userActions  plochá mapa rozhodnutí
 * @param {object|null|undefined} resolve  kvůli `vatCodeOptions`
 */
export function isPending(ref, userActions, resolve) {
  const action = (userActions ?? {})[ref.path];
  const decided = action !== undefined && action !== null;
  switch (ref.kind) {
    case 'party':
    case 'bankAccount':
    case 'item':
      return ref.block?.status !== 'matched' && !decided;
    case 'vatCode': {
      if (ref.block?.status !== 'notFound') return false;
      const codes = new Set((resolve?.vatCodeOptions ?? []).map((o) => o.code));
      return !(typeof action === 'string' && action.startsWith('useCode:')
        && codes.has(action.slice('useCode:'.length)));
    }
    default:
      return false;
  }
}

/**
 * Cesty čekající na rozhodnutí; prázdné pole = lze použít.
 *
 * @param {object|null|undefined} resolve
 * @param {Record<string, string|null>} userActions
 * @returns {string[]}
 */
export function pendingPaths(resolve, userActions) {
  return reviewReferences(resolve)
    .filter((ref) => isPending(ref, userActions, resolve))
    .map((ref) => ref.path);
}

/**
 * Má reference v náhledu ovládací odznak (klikací, otevírá rozhodnutí)?
 * Read-only náhled (`interactive: false`) ovládání nemá. Napárovaná strana
 * ani účet se nemění (#111 D4 — jen položka a kód DPH jsou klikací i u
 * matched). Neznámý druh ovládání nemá — test pravidla D98 ho odhalí.
 *
 * @param {{kind: string, block: object}} ref
 * @param {{interactive: boolean}} options
 */
export function hasControl(ref, { interactive }) {
  if (!interactive || !ref?.block || !ref.kind) return false;
  if (ref.kind === 'item' || ref.kind === 'vatCode') return true;
  if (ref.kind === 'party' || ref.kind === 'bankAccount') return ref.block.status !== 'matched';
  return false;
}
