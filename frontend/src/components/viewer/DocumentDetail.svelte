<script>
  // Detail dokladu jako "textová faktura" — render content typu `document`
  // (DocsHeadsViewer::renderDetail). Vizuální vzor: DocumentExchangePreview,
  // ale bez sdílení tříd/klíčů s exchange — server posílá hodnoty už
  // zformátované a lokalizované, frontend jen skládá layout a statické
  // labely přes t().
  //
  // Písmo výhradně přes typografické role (--shpd-font-size-title / heading
  // / body / meta, --shpd-font-weight-normal / strong; #135 D15, D16) —
  // referenční implementace kapitoly Detail záznamu v docs/design-system.md.
  // Hlavičku (titulek, podtitul, štítek stavu) kreslí ViewerDetail nad taby;
  // content klíč `header` nenese. Přílohy (vlastní i ze zdrojové zprávy)
  // obsah `document` nenese také — kreslí je sdílená sekce DetailAttachments
  // na konci Přehledu z `detail.attachments` (#136 D21). Nadpisy sekcí
  // a skupiny údajů kreslí sdílené SectionHeading / PropertyGroups (#137
  // D28, D29) — komponenty vznikly z tohoto detailu, vzhled se jimi nemění.
  import { t } from '../../i18n/index.js';
  import SectionHeading from '../ui/SectionHeading.svelte';
  import PropertyGroups from '../ui/PropertyGroups.svelte';

  let { content = null, onAction = null } = $props();

  let meta = $derived(content?.meta ?? {});
  let rows = $derived(content?.rows ?? []);
  let vatRecap = $derived(content?.vat_recap ?? []);
  let totals = $derived(content?.totals ?? null);

  // Údaje dokladu ve dvou skupinách vedle sebe (#135): data a platební
  // údaje. Kreslí je sdílená PropertyGroups (#137 D29) — pole s null /
  // prázdnou hodnotou a prázdnou skupinu vynechá ona; dostává hotové
  // přeložené texty.
  const META_GROUPS = [
    {
      labelKey: 'viewer.document.section.dates',
      fields: [
        ['issue_date', 'viewer.document.meta.issueDate'],
        ['due_date', 'viewer.document.meta.dueDate'],
        ['vat_duzp', 'viewer.document.meta.taxPointDate'],
        ['accounting_date', 'viewer.document.meta.accountingDate'],
      ],
    },
    {
      labelKey: 'viewer.document.section.payment',
      fields: [
        ['payment_method', 'viewer.document.meta.paymentMethod'],
        ['currency', 'viewer.document.meta.currency'],
        ['exchange_rate', 'viewer.document.meta.exchangeRate'],
        ['cash_desk', 'viewer.document.meta.cashDesk'],
        ['payer', 'viewer.document.meta.payer'],
        ['payment_reference', 'viewer.document.meta.paymentReference'],
        ['specific_symbol', 'viewer.document.meta.specificSymbol'],
        ['constant_symbol', 'viewer.document.meta.constantSymbol'],
      ],
    },
  ];

  let metaGroups = $derived(
    META_GROUPS.map((group) => ({
      title: t(group.labelKey),
      items: group.fields.map(([key, labelKey]) => ({ label: t(labelKey), value: meta?.[key] ?? null })),
    }))
  );

  function formatAddress(addr) {
    if (!addr) return null;
    if (addr.display_line) return addr.display_line;
    const street = [addr.street, addr.house_number].filter(Boolean).join(' ');
    const city = [addr.zip, addr.city].filter(Boolean).join(' ');
    return [street, city].filter(Boolean).join(', ') || null;
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
</script>

{#snippet partyCard(labelKey, party)}
  <!-- Karta strany (D16): nadpis karty a název entity jsou heading, IČO /
       DIČ, adresa a účet běžné údaje barvou textu, šedé a menší jsou jen
       kontakty. -->
  <div class="shpd-docdetail__party">
    <SectionHeading level={3}>{t(labelKey)}</SectionHeading>
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
              <span>{t('viewer.document.party.companyId')}: {party.company_id}</span>
            {/if}
            {#if party.tax_id}
              <span>{t('viewer.document.party.taxId')}: {party.tax_id}</span>
            {/if}
            {#if party.vat_id && party.vat_id !== party.tax_id}
              <span>{t('viewer.document.party.vatId')}: {party.vat_id}</span>
            {/if}
          </div>
        {/if}
        {#if formatAddress(party.address)}
          <div class="shpd-docdetail__party-address">{formatAddress(party.address)}</div>
        {/if}
        {#if party.bank_account?.iban || party.bank_account?.account_number}
          <div class="shpd-docdetail__party-bank">
            {t('viewer.document.party.bankAccount')}:
            {party.bank_account.account_number ?? party.bank_account.iban}
          </div>
        {/if}
        {#if party.contact?.email || party.contact?.phone}
          <div class="shpd-docdetail__party-contact">
            {#if party.contact?.email}<span>{party.contact.email}</span>{/if}
            {#if party.contact?.phone}<span>{party.contact.phone}</span>{/if}
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
    <section class="shpd-docdetail__parties">
      {@render partyCard('viewer.document.section.supplier', content.supplier)}
      {@render partyCard('viewer.document.section.customer', content.customer)}
    </section>

    <!-- Údaje dokladu — sdílené skupiny vlastností (#137 D29); bez
         vyplněného údaje nevykreslí nic, ani mezeru. -->
    <PropertyGroups groups={metaGroups} headingLevel={3} />

    {#if rows.length > 0}
      <section class="shpd-docdetail__section">
        <SectionHeading level={3}>{t('viewer.document.section.rows')}</SectionHeading>
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
  </div>
{/if}

<style>
  .shpd-docdetail {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-body);
    /* Kontejner pro @container dotazy níže (D3). Blok na celou šířku
       obsahu detailu — inline-size mu šířku nezmění; na prvky, které se
       mají smrštit na obsah, container-type nepatří. */
    container-type: inline-size;
  }

  /* Nadpisy sekcí, karet stran i skupin údajů kreslí sdílený SectionHeading,
     skupiny údajů PropertyGroups (#137 D28, D29) — vlastní CSS tu nemají. */

  /* ── Strany ──────────────────────────────────────────────────────────── */

  .shpd-docdetail__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  .shpd-docdetail__party {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    padding: 0.75rem;
    background: var(--shpd-color-bg);
  }

  .shpd-docdetail__party-body {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  /* Název entity má roli nadpisu obsahu karty (D16). */
  .shpd-docdetail__party-name {
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
  }

  /* Klikatelné jméno partnera — link na záznam osoby. Stejná vizuální
     váha jako party-name, jen primary barva + underline na hover. */
  .shpd-docdetail__party-name--link {
    display: block;
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
    text-align: left;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-docdetail__party-name--link:hover {
    text-decoration: underline;
  }

  /* IČO / DIČ, adresa a účet jsou běžné údaje — body, barvou textu, bez
     zvýraznění (D15); dědí z kořene. */
  .shpd-docdetail__party-ids {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  /* Kontakty jsou doplněk — meta, šedé, s malou mezerou nad (D16). */
  .shpd-docdetail__party-contact {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-docdetail__party-empty {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }

  /* ── Řádky a rekapitulace DPH ────────────────────────────────────────── */

  .shpd-docdetail__rows,
  .shpd-docdetail__vat-recap {
    width: 100%;
    border-collapse: collapse;
  }

  .shpd-docdetail__rows th,
  .shpd-docdetail__rows td,
  .shpd-docdetail__vat-recap th,
  .shpd-docdetail__vat-recap td {
    padding: 0.375rem var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  /* Hlavička sloupce — heading: stejná velikost jako řádky, silnější,
     barvou textu, bez verzálek; čára pod ní silnější než mezi řádky. */
  .shpd-docdetail__rows th,
  .shpd-docdetail__vat-recap th {
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    border-bottom-color: var(--shpd-color-border-strong);
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

  /* Štítek stavu položky (archiv/koš) za popisem řádku — meta, stejné
     state tokeny jako štítek stavu v hlavičce detailu, koš přeškrtnutý. */
  .shpd-docdetail__row-badge {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 0.0625rem 0.375rem;
    border-radius: var(--shpd-radius-full);
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-normal);
    line-height: 1.4;
    white-space: nowrap;
    vertical-align: middle;
  }

  .shpd-docdetail__row-badge--trash {
    text-decoration: line-through;
  }

  /* ── Součty ──────────────────────────────────────────────────────────── */

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

  /* Jedna tučná úroveň: <strong> má výchozích 700, tady vždy token. */
  .shpd-docdetail__totals-summary strong {
    font-weight: var(--shpd-font-weight-strong);
  }

  .shpd-docdetail__total {
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-title);
  }

  .shpd-docdetail__total-dom {
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* ── Úzká šířka (D3) ─────────────────────────────────────────────────── */

  /* Pod 30rem (480 px) šířky kontejneru .shpd-docdetail jdou strany pod
     sebe; skupiny údajů si totéž řeší PropertyGroups vlastním kontejnerem
     se stejným prahem. Dotaz platí pro potomky kontejneru, ne pro něj sám.
     Práh vychází ze skutečných šířek: panel vedle seznamu má šířku okna
     − 693 px, drawer 528 px — 40rem ze zadání stohovalo strany i na
     běžných šířkách okna. */
  @container (max-width: 30rem) {
    .shpd-docdetail__parties {
      grid-template-columns: 1fr;
    }
  }
</style>
