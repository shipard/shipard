// Položky navigace, které se týkají odchozí pošty — nad nimi ContentArea
// ukáže upozornění na pojistku (#95 D7): agenda Odeslaná pošta a Nastavení
// → Pošta (odesílatelé, fronta a její log, stránka Odchozí pošta).

const VIEWERS = new Set(['core.mail.sent', 'core.mail.senders']);
const TABLES = new Set(['core_mail_outbox', 'core_mail_outbox_log']);
const PAGES = new Set(['mailOutbound']);

/** @param {?{type?: string, viewerId?: string, table?: string, pageId?: string}} item */
export function isOutboundMailItem(item) {
  switch (item?.type) {
    case 'viewer': return VIEWERS.has(item.viewerId);
    case 'table':  return TABLES.has(item.table);
    case 'page':   return PAGES.has(item.pageId);
    default:       return false;
  }
}
