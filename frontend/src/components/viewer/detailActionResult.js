/**
 * Výsledek akce detailu, která zakládá doklad (Vystavit opravný doklad,
 * #92 D6): čisté funkce nad obálkou `{success, data, error}` z api/client.js,
 * aby šla obsluha z Viewer.svelte otestovat bez komponenty.
 */

/** Id založeného dokladu z úspěšné odpovědi (`data.docId`), jinak null. */
export function createdDocId(result) {
  if (!result?.success) return null;
  const id = result.data?.docId;
  return Number.isInteger(id) && id > 0 ? id : null;
}

/**
 * Text chyby pro alert: přeložená hlavní hláška (translate = translateError
 * z i18n/errors.js) a pod ní odrážky z `error.details[].message` — hlášky
 * applieru u CORRECTIVE_APPLY_FAILED. Bez detailů jen hláška.
 */
export function errorText(result, translate) {
  const details = (Array.isArray(result?.error?.details) ? result.error.details : [])
    .map((d) => d?.message)
    .filter((m) => typeof m === 'string' && m !== '');
  const head = translate(result?.error);
  return details.length ? `${head}\n\n${details.map((m) => `• ${m}`).join('\n')}` : head;
}
