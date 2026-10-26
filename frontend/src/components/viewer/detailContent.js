// Sdílené čisté funkce nad obsahem detailu záznamu (ViewerDetail,
// ViewerDetailModal). Kontrakt detailu: docs/frontend.md → Formát detail panelu.

/**
 * Má tab vlastní přílohy záznamu? Obsah typu `attachments` (tab Přílohy od
 * builderu, #136 D21), i jako blok uvnitř `composite`. Takový tab znamená,
 * že se sdílená sekce příloh v Přehledu nekreslí podruhé.
 */
export function contentHasAttachments(content) {
  if (!content) return false;
  if (content.type === 'attachments') return true;
  return content.type === 'composite' && (content.blocks ?? []).some(contentHasAttachments);
}
