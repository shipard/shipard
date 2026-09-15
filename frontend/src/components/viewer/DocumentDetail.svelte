<script>
  // Detail dokladu jako "textová faktura" — render content typu `document`
  // (DocsHeadsViewer::renderDetail). Vizuální vzor: DocumentExchangePreview,
  // ale bez sdílení tříd/klíčů s exchange — server posílá hodnoty už
  // zformátované a lokalizované, frontend jen skládá layout a statické
  // labely přes t().
  import { t } from '../../i18n/index.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { attachmentViewStore } from '../../stores/attachmentView.svelte.js';
  import AttachmentGrid from './AttachmentGrid.svelte';

  let { content = null, onAction = null } = $props();

  let header = $derived(content?.header ?? null);
  let meta = $derived(content?.meta ?? {});
  let rows = $derived(content?.rows ?? []);
  let vatRecap = $derived(content?.vat_recap ?? []);
  let totals = $derived(content?.totals ?? null);
  let attachmentGroups = $derived(content?.attachments?.groups ?? []);

  // Pole meta mřížky — null hodnoty se nerenderují.
  const META_FIELDS = [
    ['issue_date', 'viewer.document.meta.issueDate'],
    ['due_date', 'viewer.document.meta.dueDate'],
    ['accounting_date', 'viewer.document.meta.accountingDate'],
    ['vat_duzp', 'viewer.document.meta.taxPointDate'],
    ['currency', 'viewer.document.meta.currency'],
    ['exchange_rate', 'viewer.document.meta.exchangeRate'],
    ['payment_method', 'viewer.document.meta.paymentMethod'],
    ['cash_desk', 'viewer.document.meta.cashDesk'],
    ['payer', 'viewer.document.meta.payer'],
    ['payment_reference', 'viewer.document.meta.paymentReference'],
    ['specific_symbol', 'viewer.document.meta.specificSymbol'],
    ['constant_symbol', 'viewer.document.meta.constantSymbol'],
  ];

  let metaItems = $derived(
    META_FIELDS
      .map(([key, labelKey]) => ({ key, labelKey, value: meta?.[key] ?? null }))
      .filter((f) => f.value !== null && f.value !== '')
  );

  function formatAddress(addr) {
    if (!addr) return null;
    if (addr.display_line) return addr.display_line;
    const street = [addr.street, addr.house_number].filter(Boolean).join(' ');
    const city = [addr.zip, addr.city].filter(Boolean).join(' ');
    return [street, city].filter(Boolean).join(', ') || null;
  }

  function openSourceMessage(group) {
    if (group.sourceViewerId) {
      navigationStore.navigateToViewer(group.sourceViewerId, group.message_ndx);
    }
  }

  // Klik na partnera / položku řádku — otevře FormDialog přes generický
  // handler detail akcí ve Viewer.svelte (kind: open_form). Akční id
  // 'open_record' není ve sdíleném slovníku vestavěných akcí (snooze,
  // reaccount, ...), takže propadne na obsluhu podle kind.
  function openRecord(table, id) {
    onAction?.('open_record', {
      kind: 'open_form',
      target: { table, mode: 'edit', id },
    });
  }

  // Rezim zobrazeni priloh ('full'/'grid') je sdileny store — stejna volba
  // plati i v detailu dosle posty (ViewerDetail). Detaily v attachmentView.svelte.js.
</script>

{#snippet partyCard(labelKey, party)}
  <div class="shpd-docdetail__party">
    <h3 class="shpd-docdetail__party-label">{t(labelKey)}</h3>
    {#if party}
      <div class="shpd-docdetail__party-body">
        {#if party.name}
          {#if party.person_id && onAction}
            <button
              type="button"
              class="shpd-docdetail__party-name shpd-docdetail__party-name--link"
              onclick={() => openRecord('base_persons_persons', party.person_id)}
            >
              {party.name}
            </button>
          {:else}
            <div class="shpd-docdetail__party-name">{party.name}</div>
          {/if}
        {/if}
        {#if party.company_id || party.tax_id || party.vat_id}
          <div class="shpd-docdetail__party-ids">
            {#if party.company_id}
              <span>{t('viewer.document.party.companyId')}: <strong>{party.company_id}</strong></span>
            {/if}
            {#if party.tax_id}
              <span>{t('viewer.document.party.taxId')}: <strong>{party.tax_id}</strong></span>
            {/if}
            {#if party.vat_id && party.vat_id !== party.tax_id}
              <span>{t('viewer.document.party.vatId')}: <strong>{party.vat_id}</strong></span>
            {/if}
          </div>
        {/if}
        {#if formatAddress(party.address)}
          <div class="shpd-docdetail__party-address">{formatAddress(party.address)}</div>
        {/if}
        {#if party.contact?.email || party.contact?.phone}
          <div class="shpd-docdetail__party-contact">
            {#if party.contact?.email}<span>{party.contact.email}</span>{/if}
            {#if party.contact?.phone}<span>{party.contact.phone}</span>{/if}
          </div>
        {/if}
        {#if party.bank_account?.iban || party.bank_account?.account_number}
          <div class="shpd-docdetail__party-bank">
            {t('viewer.document.party.bankAccount')}:
            <strong>{party.bank_account.account_number ?? party.bank_account.iban}</strong>
          </div>
        {/if}
      </div>
    {:else}
      <div class="shpd-docdetail__party-empty">—</div>
    {/if}
  </div>
{/snippet}

{#snippet rowDescription(row)}
  <!-- Popis řádku: klikatelný link na Položku, když řádek má FK item
       a položka v DB existuje (backend posílá item_id jen tehdy).
       item_state (archiv/koš) se vykreslí jako badge za popisem. -->
  {#if row.item_id && onAction}
    <button
      type="button"
      class="shpd-docdetail__row-link"
      onclick={() => openRecord('economy_items', row.item_id)}
    >
      {row.description}
    </button>
  {:else}
    {row.description}
  {/if}
  {#if row.item_state}
    <span
      class="shpd-docdetail__row-badge"
      class:shpd-docdetail__row-badge--trash={row.item_state.style === 'trash'}
      style="background: var(--shpd-color-state-{row.item_state.style}-bg); color: var(--shpd-color-state-{row.item_state.style}-text);"
    >
      {row.item_state.label}
    </span>
  {/if}
{/snippet}

{#if content}
  <div class="shpd-docdetail">
    {#if header}
      <header class="shpd-docdetail__header">
        <div class="shpd-docdetail__header-main">
          {#if header.docTypeLabel}
            <span class="shpd-docdetail__type-badge">{header.docTypeLabel}</span>
          {/if}
          <h2 class="shpd-docdetail__title">{header.docNumber || '—'}</h2>
          {#if header.docText}
            <p class="shpd-docdetail__subtitle">{header.docText}</p>
          {/if}
        </div>
        {#if header.state?.name}
          <span
            class="shpd-docdetail__state-badge"
            style="background: var(--shpd-color-state-{header.state.style}-bg); color: var(--shpd-color-state-{header.state.style}-text);"
          >
            {header.state.name}
          </span>
        {/if}
      </header>
    {/if}

    <section class="shpd-docdetail__parties">
      {@render partyCard('viewer.document.section.supplier', content.supplier)}
      {@render partyCard('viewer.document.section.customer', content.customer)}
    </section>

    {#if metaItems.length > 0}
      <section class="shpd-docdetail__meta-grid">
        {#each metaItems as item (item.key)}
          <div class="shpd-docdetail__field">
            <span class="shpd-docdetail__field-label">{t(item.labelKey)}</span>
            <span class="shpd-docdetail__field-value">{item.value}</span>
          </div>
        {/each}
      </section>
    {/if}

    {#if rows.length > 0}
      <section class="shpd-docdetail__section">
        <h3 class="shpd-docdetail__section-heading">{t('viewer.document.section.rows')}</h3>
        <table class="shpd-docdetail__rows">
          <thead>
            <tr>
              <th class="shpd-docdetail__rows-pos">{t('viewer.document.row.position')}</th>
              <th>{t('viewer.document.row.description')}</th>
              <th class="num">{t('viewer.document.row.quantity')}</th>
              <th>{t('viewer.document.row.unit')}</th>
              <th class="num">{t('viewer.document.row.unitPrice')}</th>
              <th class="num">{t('viewer.document.row.vat')}</th>
              <th class="num">{t('viewer.document.row.total')}</th>
            </tr>
          </thead>
          <tbody>
            {#each rows as row, i}
              <tr>
                {#if row.kind === 0}
                  <!-- Textový řádek — jen popis přes celou šířku. -->
                  <td>{row.order_pos ?? i + 1}</td>
                  <td colspan="6">{@render rowDescription(row)}</td>
                {:else}
                  <td>{row.order_pos ?? i + 1}</td>
                  <td>{@render rowDescription(row)}</td>
                  <td class="num">{row.quantity ?? '—'}</td>
                  <td>{row.unit ?? '—'}</td>
                  <td class="num">{row.unit_price ?? '—'}</td>
                  <td class="num">{row.vat_pct != null ? `${row.vat_pct} %` : '—'}</td>
                  <td class="num">{row.total_price ?? '—'}</td>
                {/if}
              </tr>
            {/each}
          </tbody>
        </table>
      </section>
    {/if}

    {#if vatRecap.length > 0 || totals}
      <section class="shpd-docdetail__totals-section">
        {#if vatRecap.length > 0}
          <table class="shpd-docdetail__vat-recap">
            <thead>
              <tr>
                <th>{t('viewer.document.section.recap')}</th>
                <th class="num">{t('viewer.document.totals.base')}</th>
                <th class="num">{t('viewer.document.totals.vat')}</th>
                <th class="num">{t('viewer.document.totals.total')}</th>
              </tr>
            </thead>
            <tbody>
              {#each vatRecap as r}
                <tr>
                  <td>{r.vat_pct} %</td>
                  <td class="num">{r.base ?? '—'}</td>
                  <td class="num">{r.tax ?? '—'}</td>
                  <td class="num">{r.total ?? '—'}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}
        {#if totals}
          <div class="shpd-docdetail__totals-summary">
            <div>
              {t('viewer.document.totals.base')}:
              <strong>{totals.base ?? '—'} {totals.currency}</strong>
            </div>
            <div>
              {t('viewer.document.totals.vat')}:
              <strong>{totals.vat ?? '—'} {totals.currency}</strong>
            </div>
            {#if totals.rounding}
              <div>
                {t('viewer.document.totals.rounding')}:
                <strong>{totals.rounding} {totals.currency}</strong>
              </div>
            {/if}
            <div class="shpd-docdetail__total">
              {t('viewer.document.totals.total')}:
              <strong>{totals.amount ?? '—'} {totals.currency}</strong>
            </div>
            {#if totals.dom}
              <div class="shpd-docdetail__total-dom">
                {totals.dom.base} + {totals.dom.vat} = {totals.dom.amount} {totals.dom.currency}
              </div>
            {/if}
          </div>
        {/if}
      </section>
    {/if}

    {#if attachmentGroups.length > 0}
      <section class="shpd-docdetail__section shpd-docdetail__attachments">
        <div class="shpd-docdetail__att-toolbar">
          <button
            type="button"
            class="shpd-docdetail__att-toggle"
            onclick={() => attachmentViewStore.toggle()}
          >
            {attachmentViewStore.mode === 'full'
              ? t('viewer.document.attachments.viewGrid')
              : t('viewer.document.attachments.viewFull')}
          </button>
        </div>
        {#each attachmentGroups as group, i (group.kind === 'mail' ? `mail-${group.message_ndx}` : `doc-${i}`)}
          <div class="shpd-docdetail__att-group">
            <h3 class="shpd-docdetail__section-heading">
              {#if group.kind === 'mail'}
                {#if group.sourceViewerId}
                  <button
                    type="button"
                    class="shpd-docdetail__att-msglink"
                    onclick={() => openSourceMessage(group)}
                  >
                    #{group.message_id}
                  </button>
                {:else}
                  <span>#{group.message_id}</span>
                {/if}
                {#if group.received_at}
                  <span class="shpd-docdetail__att-msgdate">· {group.received_at}</span>
                {/if}
              {:else}
                {t('viewer.document.attachments.doc')}
              {/if}
            </h3>
            <AttachmentGrid attachments={group.attachments} mode={attachmentViewStore.mode} />
          </div>
        {/each}
      </section>
    {/if}
  </div>
{/if}

<style>
  .shpd-docdetail {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    font-size: 0.875rem;
  }

  /* ── Header ──────────────────────────────────────────────────────────── */

  .shpd-docdetail__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    padding-bottom: var(--shpd-space-md);
  }

  .shpd-docdetail__type-badge {
    display: inline-block;
    padding: 2px 8px;
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    border-radius: 4px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-docdetail__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
  }

  .shpd-docdetail__subtitle {
    margin: var(--shpd-space-xs) 0 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-docdetail__state-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
  }

  /* ── Parties ─────────────────────────────────────────────────────────── */

  .shpd-docdetail__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  @media (max-width: 600px) {
    .shpd-docdetail__parties {
      grid-template-columns: 1fr;
    }
  }

  .shpd-docdetail__party {
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-bg);
  }

  .shpd-docdetail__party-label {
    margin: 0 0 var(--shpd-space-xs);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-docdetail__party-name {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-docdetail__party-ids,
  .shpd-docdetail__party-contact {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-md);
    font-size: 0.8125rem;
    color: var(--shpd-color-text-secondary);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-docdetail__party-address,
  .shpd-docdetail__party-bank {
    font-size: 0.8125rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-docdetail__party-empty {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }

  /* Klikatelné jméno partnera — link na záznam osoby. Stejná vizuální
     váha jako party-name, jen primary barva + underline na hover. */
  .shpd-docdetail__party-name--link {
    display: block;
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    font-size: 1rem;
    text-align: left;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-docdetail__party-name--link:hover {
    text-decoration: underline;
  }

  /* ── Meta grid ───────────────────────────────────────────────────────── */

  .shpd-docdetail__meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-docdetail__field {
    display: flex;
    flex-direction: column;
  }

  .shpd-docdetail__field-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-secondary);
    letter-spacing: 0.5px;
  }

  .shpd-docdetail__field-value {
    font-size: 0.875rem;
  }

  /* Na mobilu (≤ 768px, MOBILE_BREAKPOINT) má meta mřížka dva pevné
     sloupce místo jednoho — hodnoty (datumy, symboly, měna) jsou krátké
     a vejdou se vedle sebe, čímž se ušetří vertikální místo. Desktop
     zůstává na auto-fit (minmax 180px). */
  @media (max-width: 768px) {
    .shpd-docdetail__meta-grid {
      grid-template-columns: 1fr 1fr;
    }
  }

  /* Na mobilu (≤ 768px, MOBILE_BREAKPOINT) má meta mřížka dva pevné
     sloupce místo jednoho — hodnoty (datumy, symboly, měna) jsou krátké
     a vejdou se vedle sebe, čímž se ušetří vertikální místo. Desktop
     zůstává na auto-fit (minmax 180px). */
  @media (max-width: 768px) {
    .shpd-docdetail__meta-grid {
      grid-template-columns: 1fr 1fr;
    }
  }

  /* ── Rows + VAT recap tables ─────────────────────────────────────────── */

  .shpd-docdetail__section-heading {
    margin: 0 0 var(--shpd-space-xs);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-docdetail__rows,
  .shpd-docdetail__vat-recap {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
  }

  .shpd-docdetail__rows th,
  .shpd-docdetail__rows td,
  .shpd-docdetail__vat-recap th,
  .shpd-docdetail__vat-recap td {
    padding: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  .shpd-docdetail__rows th,
  .shpd-docdetail__vat-recap th {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-secondary);
    font-weight: 500;
    letter-spacing: 0.5px;
  }

  .shpd-docdetail__rows .num,
  .shpd-docdetail__vat-recap .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Klikatelný popis řádku — link na záznam položky. */
  .shpd-docdetail__row-link {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    text-align: left;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-docdetail__row-link:hover {
    text-decoration: underline;
  }

  /* Badge stavu položky (archiv/koš) za popisem řádku — stejné state
     tokeny jako badge v hlavičce detailu, koš navíc přeškrtnutý. */
  .shpd-docdetail__row-badge {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
    vertical-align: middle;
  }

  .shpd-docdetail__row-badge--trash {
    text-decoration: line-through;
  }

  /* ── Totals ──────────────────────────────────────────────────────────── */

  .shpd-docdetail__totals-section {
    display: flex;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    align-items: flex-end;
    flex-wrap: wrap;
  }

  .shpd-docdetail__vat-recap {
    max-width: 420px;
    flex: 1 1 280px;
  }

  .shpd-docdetail__totals-summary {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    text-align: right;
    min-width: 220px;
    font-variant-numeric: tabular-nums;
  }

  .shpd-docdetail__total {
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-xs);
    font-size: 1rem;
  }

  .shpd-docdetail__total-dom {
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
  }

  /* ── Attachments ─────────────────────────────────────────────────────── */

  .shpd-docdetail__attachments {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-md);
  }

  .shpd-docdetail__att-msglink {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    color: var(--shpd-color-primary);
    cursor: pointer;
    text-transform: none;
    letter-spacing: normal;
  }

  .shpd-docdetail__att-msglink:hover {
    text-decoration: underline;
  }

  .shpd-docdetail__att-msgdate {
    font-weight: 400;
    text-transform: none;
    letter-spacing: normal;
  }

  .shpd-docdetail__att-toolbar {
    display: flex;
    justify-content: flex-end;
    /* Kompenzace gap kontejneru, toolbar ma sedet tesne nad prvni skupinou. */
    margin-bottom: calc(-1 * var(--shpd-space-md));
  }

  .shpd-docdetail__att-toggle {
    border: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    border-radius: var(--shpd-radius-md);
    padding: 2px 10px;
    font: inherit;
    font-size: 0.75rem;
    cursor: pointer;
  }

  .shpd-docdetail__att-toggle:hover {
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-text-secondary);
  }
</style>
