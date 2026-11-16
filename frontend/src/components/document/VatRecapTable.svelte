<script>
  // Tabulka rekapitulace DPH (#150 D86) — sdílená část dokladu vytažená
  // z `DocTotals`; vždy v obalu `TableScroll` (D44), styly tabulky §12.
  // Hodnoty jsou hotové texty (částky formátuje backend nebo adaptér),
  // tady se nic nepočítá. Dvě podoby:
  //
  // - **kompaktní** (výchozí; detail dokladu a review přes `DocTotals`):
  //   sloupce DPH / Základ / DPH / Celkem, řádek `{key, rate, base, tax,
  //   total}`; snippet `rate(r)` nahradí výchozí text sazby (review: kód DPH
  //   a dvojice přenesené povinnosti).
  // - **rozšířená** (`extended`; formulář dokladu, komponenta `docVatRecap`):
  //   sloupce Sazba / % / Měna / Základ / DPH / Celkem, řádek `{label, pct,
  //   base, tax, total, sum: {base, tax, total}, dom: {base, tax, total} |
  //   null}`. U dokladu v cizí měně je pod řádkem v měně dokladu řádek
  //   v domácí měně (`dom`) barvou textu, bez čáry mezi nimi; Sazba a %
  //   jdou přes oba (`rowspan`), zarovnané nahoru. **Šedá má v tabulce
  //   jediný význam: hodnota, která se nesčítá do součtu dokladu**
  //   (`sum.* === false`, typicky přenesená daňová povinnost). Patička
  //   `totals` `{base, tax, total, rate, dom}`: řádek Celkem (s kurzem
  //   `rate` u cizí měny) a pod ním součet v domácí měně.
  import { t } from '../../i18n/index.js';
  import TableScroll from '../ui/TableScroll.svelte';

  let {
    rows = [],
    rate = undefined,
    extended = false,
    currency = '',
    homeCurrency = null,
    totals = null,
  } = $props();

  const DASH = '—';
</script>

<TableScroll>
  <table class="shpd-recap-table">
    {#if extended}
      <thead>
        <tr>
          <th>{t('document.recap.rate')}</th>
          <th class="num">{t('document.recap.pct')}</th>
          <th>{t('document.recap.currency')}</th>
          <th class="num">{t('document.totals.base')}</th>
          <th class="num">{t('document.totals.vat')}</th>
          <th class="num">{t('document.totals.total')}</th>
        </tr>
      </thead>
      <tbody>
        {#each rows as r, i (i)}
          <tr class:shpd-recap-table__pair={r.dom}>
            <td class="shpd-recap-table__label" rowspan={r.dom ? 2 : 1}>{r.label ?? DASH}</td>
            <td class="num shpd-recap-table__span" rowspan={r.dom ? 2 : 1}>{r.pct ?? DASH}</td>
            <td>{currency}</td>
            <td class="num" class:shpd-recap-table__unsummed={!r.sum?.base}>{r.base ?? DASH}</td>
            <td class="num" class:shpd-recap-table__unsummed={!r.sum?.tax}>{r.tax ?? DASH}</td>
            <td class="num" class:shpd-recap-table__unsummed={!r.sum?.total}>{r.total ?? DASH}</td>
          </tr>
          {#if r.dom}
            <tr>
              <td>{homeCurrency}</td>
              <td class="num" class:shpd-recap-table__unsummed={!r.sum?.base}>{r.dom.base ?? DASH}</td>
              <td class="num" class:shpd-recap-table__unsummed={!r.sum?.tax}>{r.dom.tax ?? DASH}</td>
              <td class="num" class:shpd-recap-table__unsummed={!r.sum?.total}>{r.dom.total ?? DASH}</td>
            </tr>
          {/if}
        {/each}
      </tbody>
      {#if totals}
        <tfoot>
          <tr class="shpd-recap-table__total" class:shpd-recap-table__pair={totals.dom}>
            <td class="shpd-recap-table__label" colspan="2" rowspan={totals.dom ? 2 : 1}>
              {t('document.totals.total')}{#if totals.rate}{' '}<span class="shpd-recap-table__exchange">({totals.rate})</span>{/if}
            </td>
            <td>{currency}</td>
            <td class="num">{totals.base ?? DASH}</td>
            <td class="num">{totals.tax ?? DASH}</td>
            <td class="num">{totals.total ?? DASH}</td>
          </tr>
          {#if totals.dom}
            <tr class="shpd-recap-table__total shpd-recap-table__total--dom">
              <td>{homeCurrency}</td>
              <td class="num">{totals.dom.base ?? DASH}</td>
              <td class="num">{totals.dom.tax ?? DASH}</td>
              <td class="num">{totals.dom.total ?? DASH}</td>
            </tr>
          {/if}
        </tfoot>
      {/if}
    {:else}
      <thead>
        <tr>
          <th>{t('document.section.recap')}</th>
          <th class="num">{t('document.totals.base')}</th>
          <th class="num">{t('document.totals.vat')}</th>
          <th class="num">{t('document.totals.total')}</th>
        </tr>
      </thead>
      <tbody>
        {#each rows as r (r.key)}
          <tr>
            <td>{#if rate}{@render rate(r)}{:else}{r.rate ?? DASH}{/if}</td>
            <td class="num">{r.base ?? DASH}</td>
            <td class="num">{r.tax ?? DASH}</td>
            <td class="num">{r.total ?? DASH}</td>
          </tr>
        {/each}
      </tbody>
    {/if}
  </table>
</TableScroll>

<style>
  .shpd-recap-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-body);
  }

  .shpd-recap-table th,
  .shpd-recap-table td {
    padding: 0.375rem var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  /* Hlavička sloupce — body + strong: stejná velikost jako řádky, silnější,
     barvou textu, bez verzálek (není heading); čára pod ní silnější než
     mezi řádky. Rekapitulace se nezvýrazňuje (§12 Součty). */
  .shpd-recap-table th {
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    border-bottom-color: var(--shpd-color-border-strong);
  }

  /* Čísla vpravo; buňky rekapitulace se nelámou — v úzkém obalu se
     tabulka posune (TableScroll, D44), ne rozpadne na dva řádky. */
  .shpd-recap-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .shpd-recap-table th,
  .shpd-recap-table td {
    white-space: nowrap;
  }

  /* ── Rozšířená podoba (formulář dokladu, D86) ───────────────────────── */

  /* Sazba a % přes řádek měny dokladu i domácí měny — zarovnané nahoru.
     Název kódu DPH je jediný text tabulky, který se smí zalomit. */
  .shpd-recap-table__label,
  .shpd-recap-table__span {
    vertical-align: top;
  }

  .shpd-recap-table td.shpd-recap-table__label {
    min-width: 10rem;
    white-space: normal;
  }

  /* Dvojice řádků jedné sazby (měna dokladu + domácí měna): mezi nimi
     čára není, drží ji až spodní řádek; buňky přes oba řádky čáru nesou. */
  .shpd-recap-table__pair > td:not([rowspan]) {
    border-bottom: none;
  }

  /* Jediná šedá v tabulce: hodnota, která se do součtu dokladu nesčítá. */
  .shpd-recap-table td.shpd-recap-table__unsummed {
    color: var(--shpd-color-text-secondary);
  }

  /* Řádek Celkem — strong, čára nad; pod součtem čára není. */
  .shpd-recap-table__total > td {
    font-weight: var(--shpd-font-weight-strong);
    border-top: 2px solid var(--shpd-color-border);
    border-bottom: none;
  }

  .shpd-recap-table__total--dom > td {
    border-top: none;
  }

  /* Kurz za slovem Celkem je údaj, ne součet — běžnou tloušťkou. */
  .shpd-recap-table__exchange {
    font-weight: var(--shpd-font-weight-normal);
  }
</style>
