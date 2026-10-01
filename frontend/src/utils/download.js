// Stažení souboru získaného přes fetch (export reportu). Auth jde Bearer
// hlavičkou, takže prostý <a href> nestačí — tělo se stáhne jako Blob
// a uloží přes dočasný object URL.

/**
 * Název souboru z hlavičky Content-Disposition. Přednost má RFC 5987
 * `filename*=UTF-8''…` (percent-encoded), pak prostý `filename="…"`.
 *
 * @param {string|null|undefined} header
 * @returns {string|null} null, když hlavička název nenese
 */
export function fileNameFromContentDisposition(header) {
  if (!header) return null;
  const extended = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header);
  if (extended) {
    try {
      return decodeURIComponent(extended[1].trim());
    } catch {
      // vadné percent-encoding → zkusí se prostý filename
    }
  }
  const plain = /filename\s*=\s*(?:"([^"]*)"|([^;]+))/i.exec(header);
  const name = (plain?.[1] ?? plain?.[2] ?? '').trim();
  return name !== '' ? name : null;
}

/**
 * Nabídne Blob k uložení pod daným názvem.
 *
 * @param {Blob} blob
 * @param {string} fileName
 */
export function saveBlob(blob, fileName) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  // Uvolnění až po spuštění stahování — okamžité revoke umí download zrušit.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
