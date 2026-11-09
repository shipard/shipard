// Adaptér review dokladu (#139 D48–D53): čisté funkce bez Svelte, které
// z canonical dokladu (`shpd.docs.document.v1` s `_resolve`) skládají jeden
// tvar dat sdílených částí dokladu — `DetailHeader`, `PartyCard`, `DocRows`,
// `DocTotals` (tvar v `components/document/documentParts.js`) — a hlášku
// z problémů vytěžení. Hodnoty formátuje adaptér (peníze, datum, adresa —
// dřív pomocné funkce komponenty), komponenty dostávají hotové texty;
// prázdná hodnota je `null`, pomlčku kreslí komponenta. `t` jde parametrem,
// aby šel modul testovat v `node --test` bez i18n. Stav review (rozhodnutí,
// značky, popovery) tu není — ten zůstává v `DocumentExchangePreview`
// a do částí jde snippety.

function textOrNull(value) {
  return value === null || value === undefined || value === '' ? null : String(value);
}

/** Částka s měnou („1 234,50 Kč“); prázdná hodnota → null. */
export function formatMoney(value, currency) {
  if (value === null || value === undefined || value === '') return null;
  try {
    return new Intl.NumberFormat('cs-CZ', {
      style: 'currency',
      currency: currency ?? 'CZK',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value);
  } catch {
    return String(value);
  }
}

/** Datum z ISO („12. 10. 2026“); prázdné → null. */
export function formatDate(iso) {
  if (!iso) return null;
  try {
    return new Intl.DateTimeFormat('cs-CZ').format(new Date(iso));
  } catch {
    return String(iso);
  }
}

/** Adresa canonicalu (camelCase) jedním řádkem — `displayLine` má přednost. */
export function formatAddress(addr) {
  if (!addr) return null;
  if (addr.displayLine) return String(addr.displayLine);
  const street = [addr.street, addr.houseNumber].filter(Boolean).join(' ');
  const city = [addr.zip, addr.city].filter(Boolean).join(' ');
  return [street, city].filter(Boolean).join(', ') || null;
}

/**
 * Protistrana podle `selfParty` (docs/exchange-format.md → Self-party flow):
 * vlastní firma je `customer` → protistrana `supplier` a naopak; bez
 * `selfParty` (Jiný dokument, neúplné vytěžení) null — titulek je pak
 * číslo dokladu, ne náhodná strana (D14).
 */
export function counterparty(canonical) {
  const self = canonical?.selfParty ?? null;
  if (self === 'customer') return canonical?.supplier ?? null;
  if (self === 'supplier') return canonical?.customer ?? null;
  return null;
}

/**
 * Hlavička review (D49): titulek název protistrany, bez ní číslo dokladu
 * (bez obojího „—“); podtitul „číslo · text“ (prázdné části i s oddělovačem
 * pryč; když je číslo v titulku, jen text). Štítky: typ výsledku analýzy
 * (`primary`) a souhrnný stav kontroly — „Vše napárováno“ (`success`) /
 * „Vyžaduje pozornost“ (`warning`); bez `summary` štítek stavu chybí.
 */
export function reviewHeader(canonical, summary, t) {
  const name = textOrNull(counterparty(canonical)?.name);
  const docNumber = textOrNull(canonical?.docNumber);
  const docText = textOrNull(canonical?.docText);
  const title = name ?? docNumber ?? '—';
  const subtitle = name
    ? [docNumber, docText].filter((p) => p !== null).join(' · ')
    : (docText ?? '');
  const badges = [];
  const docType = textOrNull(canonical?.docType);
  if (docType !== null) {
    badges.push({ label: t(`exchange.preview.docType.${docType}`), style: 'primary' });
  }
  if (summary) {
    badges.push(summary.status === 'ok'
      ? { label: t('exchange.preview.status.summary.ok'), style: 'success' }
      : { label: t('exchange.preview.status.summary.needsAttention'), style: 'warning' });
  }
  return { title, subtitle, badges };
}

/**
 * Hlavička náhledu Spisovny (D49): titulek název dokumentu (bez něj „—“),
 * podtitul „protistrana · IČO 12345678“, štítek „Spisovna · druh“
 * (neznámý druh = surový název jako dřív), bez štítku stavu.
 */
export function registryHeader(canonical, t) {
  const docType = textOrNull(canonical?.docType);
  let kind = null;
  if (docType !== null) {
    const key = `registry.docKind.${docType}`;
    const translated = t(key);
    kind = translated === key ? docType : translated;
  }
  const partyName = textOrNull(canonical?.party?.name);
  const companyId = textOrNull(canonical?.party?.companyId);
  const subtitle = partyName === null
    ? ''
    : [partyName, companyId === null ? null : `${t('registry.preview.companyId')} ${companyId}`]
      .filter((p) => p !== null)
      .join(' · ');
  return {
    title: textOrNull(canonical?.title) ?? '—',
    subtitle,
    badges: kind === null ? [] : [{ label: t('registry.preview.kindBadge', { kind }), style: 'primary' }],
  };
}

/**
 * Strana canonicalu → data `PartyCard` (D50): `ids` IČO → DIČ → VAT ID (jen
 * když ≠ DIČ), účet `iban ?? accountNumber`, kontaktní osoba jako první
 * položka `contacts` („Kontaktní osoba: …“), pak e-mail a telefon.
 */
export function reviewParty(party, t) {
  if (!party) return null;
  const ids = [];
  if (party.companyId) ids.push({ label: t('document.party.companyId'), value: String(party.companyId) });
  if (party.taxId) ids.push({ label: t('document.party.taxId'), value: String(party.taxId) });
  if (party.vatId && party.vatId !== party.taxId) {
    ids.push({ label: t('document.party.vatId'), value: String(party.vatId) });
  }
  const bankValue = textOrNull(party.bankAccount?.iban) ?? textOrNull(party.bankAccount?.accountNumber);
  const contacts = [];
  const contactName = textOrNull(party.contact?.name);
  if (contactName !== null) contacts.push(`${t('exchange.preview.field.contactName')}: ${contactName}`);
  for (const value of [party.contact?.email, party.contact?.phone]) {
    const text = textOrNull(value);
    if (text !== null) contacts.push(text);
  }
  return {
    name: textOrNull(party.name),
    ids,
    address: formatAddress(party.address),
    bank: bankValue === null ? null : { label: t('document.party.bankAccount'), value: bankValue },
    contacts,
  };
}

/**
 * Efektivní kód a sazba DPH řádku — to, co applier zapíše na doklad
 * (`_resolve.rows[i].vatCode.createPayload`); u samovyměření 21 %, i když
 * dodavatel uvádí 0 %. Bez napárovaného kódu null (sazba z canonicalu).
 */
export function effectiveRowVat(block) {
  if (block?.status !== 'matched') return null;
  const payload = block.createPayload ?? {};
  return { code: payload.code ?? null, pct: payload.pct ?? null };
}

/**
 * Řádky canonicalu → data `DocRows` (D45): popis = text řádku složený
 * serverem (`_resolve.rows[i].rowText`, #84), bez něj název položky, bez
 * obojího „—“ (chybějící vytěžení je informace); ceny z přepočtu dokladem
 * (`computedRowPrices` podle indexu, #97), sazba z efektivního kódu DPH;
 * textový řádek = canonical `rowKind: "text"`. `extra` pro snippety
 * review: `index`, `supplierCode`, `vatCode`.
 */
export function reviewRows(canonical, resolve, computedRowPrices) {
  const currency = textOrNull(canonical?.currency);
  return (Array.isArray(canonical?.rows) ? canonical.rows : []).map((row, i) => {
    const rowResolve = resolve?.rows?.[i] ?? null;
    const effVat = effectiveRowVat(rowResolve?.vatCode ?? null);
    const prices = computedRowPrices?.get?.(i) ?? null;
    const pct = effVat?.pct ?? row?.vat?.pct ?? null;
    return {
      key: i,
      index: i,
      pos: row?.orderPos ?? i + 1,
      isText: row?.rowKind === 'text',
      description: textOrNull(rowResolve?.rowText) ?? textOrNull(row?.item?.name) ?? '—',
      quantity: textOrNull(row?.quantity),
      unit: textOrNull(row?.unit),
      unitPrice: formatMoney(prices?.unitPrice ?? row?.unitPrice, currency),
      vat: pct === null || pct === undefined || pct === '' ? null : `${pct} %`,
      total: formatMoney(prices?.totalPrice ?? row?.totalPrice, currency),
      supplierCode: textOrNull(row?.item?.supplierCode),
      vatCode: effVat?.code ?? null,
    };
  });
}

/**
 * Rekapitulace a součty (přepočet `_resolve.computed`, nebo canonical) →
 * data `DocTotals` (D52, D55): hodnoty s měnou, zaokrouhlení jen když je
 * nenulové, bez přepočtu v domácí měně. Řádek rekapitulace nese navíc
 * `vatCode` a `isReversePair` pro snippet `rate` (`DocTotals` je nečte).
 */
export function reviewTotals(recapRows, totals, currency) {
  const cur = textOrNull(currency);
  const recap = (Array.isArray(recapRows) ? recapRows : []).map((r, i) => ({
    key: i,
    rate: r?.vatPct === null || r?.vatPct === undefined || r?.vatPct === '' ? null : `${r.vatPct} %`,
    base: formatMoney(r?.base, cur),
    tax: formatMoney(r?.tax, cur),
    total: formatMoney(r?.total, cur),
    vatCode: textOrNull(r?.vatCode),
    isReversePair: r?.isReversePair === true,
  }));
  const summary = totals
    ? {
        base: formatMoney(totals.totalBase, cur),
        vat: formatMoney(totals.totalVat, cur),
        rounding: totals.totalRounding ? formatMoney(totals.totalRounding, cur) : null,
        total: formatMoney(totals.totalAmount, cur),
      }
    : null;
  return { recap, summary, domestic: null };
}

const SEVERITY_RANK = { error: 0, warning: 1, info: 2 };

/**
 * Problémy z vytěžení → hláška `Notice` (D53): tón podle nejvyšší
 * závažnosti (`error` → `danger`, `warning` → `warning`, jinak `info`),
 * položky od nejzávažnější (stabilně), `{ text: message, meta: path }`.
 */
export function issueNotice(issues) {
  const rank = (issue) => SEVERITY_RANK[issue?.severity] ?? SEVERITY_RANK.info;
  const sorted = (Array.isArray(issues) ? issues : [])
    .filter((issue) => issue)
    .map((issue, i) => ({ issue, i }))
    .sort((a, b) => rank(a.issue) - rank(b.issue) || a.i - b.i)
    .map(({ issue }) => issue);
  const top = sorted[0]?.severity;
  const tone = top === 'error' ? 'danger' : top === 'warning' ? 'warning' : 'info';
  return {
    tone,
    items: sorted.map((issue) => ({ text: textOrNull(issue.message) ?? '', meta: textOrNull(issue.path) ?? '' })),
  };
}
