// Čisté funkce pro sdílené části dokladu (#139 D48): adaptér detailu
// dokladu — snake_case obsah typu `document` z backendu
// (`DocsHeadsViewer::renderDetail`: `supplier` / `customer` snapshot,
// `rows`, `vat_recap`, `totals`) → jeden tvar dat prezentačních komponent
// `PartyCard`, `DocRows` a `DocTotals` v `components/document/`. Review
// (task 2 #139) má vlastní adaptér z canonical, komponenty dostávají
// totéž: **hotové texty** — čísla, měnu i data formátuje backend, tady se
// nic nepřepočítává ani neformátuje, jen skládá (`rowMetaLine`). Prázdná
// hodnota je `null`; pomlčku kreslí komponenta, ne adaptér. `t` jde
// parametrem, aby šel modul testovat v `node --test` bez i18n.
//
// Tvar dat:
//   strana   { name, ids: [{label, value}], address, bank: {label, value} | null, contacts: [string] }
//   řádek    { key, pos, isText, description, quantity, unit, unitPrice, vat, total, ...extra }
//   součty   { recap: [{key, rate, base, tax, total}], summary: {base, vat, rounding, total} | null, domestic }

const TIMES = '×';
const MIDDOT = '·';

/** Prázdné je null, undefined a prázdný řetězec; jinak text. */
function textOrNull(value) {
  return value === null || value === undefined || value === '' ? null : String(value);
}

/** Adresa jedním řádkem — `display_line` z backendu má přednost. */
export function formatAddress(addr) {
  if (!addr) return null;
  if (addr.display_line) return String(addr.display_line);
  const street = [addr.street, addr.house_number].filter(Boolean).join(' ');
  const city = [addr.zip, addr.city].filter(Boolean).join(' ');
  return [street, city].filter(Boolean).join(', ') || null;
}

/**
 * Strana dokladu (snapshot osoby) → data `PartyCard` (D50): `ids` v pořadí
 * IČO, DIČ, VAT ID (VAT ID jen když se liší od DIČ), účet z čísla účtu,
 * bez něj z IBAN, kontakty e-mail a telefon. `null` → `null` (karta „—“).
 * `person_id` (odkaz na osobu, jen partnerská strana) projde jako extra
 * pro snippet `name` volajícího.
 */
export function detailParty(party, t) {
  if (!party) return null;
  const ids = [];
  if (party.company_id) ids.push({ label: t('document.party.companyId'), value: String(party.company_id) });
  if (party.tax_id) ids.push({ label: t('document.party.taxId'), value: String(party.tax_id) });
  if (party.vat_id && party.vat_id !== party.tax_id) {
    ids.push({ label: t('document.party.vatId'), value: String(party.vat_id) });
  }
  const bankValue = textOrNull(party.bank_account?.account_number) ?? textOrNull(party.bank_account?.iban);
  return {
    person_id: party.person_id ?? null,
    name: textOrNull(party.name),
    ids,
    address: formatAddress(party.address),
    bank: bankValue === null ? null : { label: t('document.party.bankAccount'), value: bankValue },
    contacts: [party.contact?.email, party.contact?.phone].map(textOrNull).filter((c) => c !== null),
  };
}

/**
 * Řádky dokladu → data `DocRows` (D45): `pos` = `order_pos`, bez něj pořadí
 * od 1; textový řádek je `kind === 0`; sazba „21 %“ z `vat_pct`; prázdné
 * hodnoty `null`. Zbylé klíče (`item_id`, `item_state`) projdou jako
 * `extra` pro snippety volajícího.
 */
export function detailRows(rows) {
  return (Array.isArray(rows) ? rows : []).map((row, i) => {
    const { order_pos, kind, description, quantity, unit, unit_price, vat_pct, total_price, ...extra } = row ?? {};
    return {
      ...extra,
      key: i,
      pos: order_pos ?? i + 1,
      isText: kind === 0,
      description: textOrNull(description) ?? '',
      quantity: textOrNull(quantity),
      unit: textOrNull(unit),
      unitPrice: textOrNull(unit_price),
      vat: vat_pct === null || vat_pct === undefined || vat_pct === '' ? null : `${vat_pct} %`,
      total: textOrNull(total_price),
    };
  });
}

function withCurrency(value, currency) {
  const text = textOrNull(value);
  if (text === null) return null;
  return currency ? `${text} ${currency}` : text;
}

/**
 * Rekapitulace DPH a součty → data `DocTotals` (D52, D55): hodnoty souhrnu
 * i s měnou („1 000,00 CZK“), zaokrouhlení jen když je nenulové (backend
 * posílá null), `domestic` = „základ + DPH = celkem měna“ z `totals.dom`.
 * Bez `totals` je `summary` null (jen rekapitulace).
 */
export function detailTotals(vatRecap, totals) {
  const recap = (Array.isArray(vatRecap) ? vatRecap : []).map((r, i) => ({
    key: i,
    rate: r?.vat_pct === null || r?.vat_pct === undefined || r?.vat_pct === '' ? null : `${r.vat_pct} %`,
    base: textOrNull(r?.base),
    tax: textOrNull(r?.tax),
    total: textOrNull(r?.total),
  }));
  if (!totals) {
    return { recap, summary: null, domestic: null };
  }
  const currency = textOrNull(totals.currency) ?? '';
  const summary = {
    base: withCurrency(totals.base, currency),
    vat: withCurrency(totals.vat, currency),
    rounding: totals.rounding ? withCurrency(totals.rounding, currency) : null,
    total: withCurrency(totals.amount, currency),
  };
  const dom = totals.dom;
  const domestic = dom
    ? `${dom.base} + ${dom.vat} = ${dom.amount}${dom.currency ? ` ${dom.currency}` : ''}`
    : null;
  return { recap, summary, domestic };
}

/**
 * Druhý řádek složeného režimu `DocRows` (D45): „množství jednotka × cena
 * · sazba“. Chybějící část se vynechá i s oddělovačem (bez ceny „2 ks ·
 * 21 %“, bez jednotky „2 × 100,00 · 21 %“); textový řádek nebo nic → ''.
 */
export function rowMetaLine(row) {
  if (!row || row.isText) return '';
  const amount = [row.quantity, row.unit].map(textOrNull).filter((p) => p !== null).join(' ');
  const price = textOrNull(row.unitPrice) ?? '';
  const left = [amount, price].filter((p) => p !== '').join(` ${TIMES} `);
  const vat = textOrNull(row.vat) ?? '';
  return [left, vat].filter((p) => p !== '').join(` ${MIDDOT} `);
}
