<script>
  // Detail dokladu jako "textová faktura" — render content typu `document`
  // (DocsHeadsViewer::renderDetail). Server posílá hodnoty už zformátované
  // a lokalizované; frontend jen skládá layout ze **sdílených částí
  // dokladu** (#139 D48): karty stran `PartyCard`, údaje `PropertyGroups`,
  // řádky `DocRows` a součty `DocTotals` v `components/document/` — tytéž
  // části použije review (task 2 #139) se svým adaptérem z canonical.
  // Data jdou přes adaptér `documentParts.js` (snake_case obsah → jeden
  // tvar částí, hotové texty); tady zůstává kořen, mřížka stran, snippety
  // s odkazy na záznamy a akce.
  //
  // Písmo výhradně přes typografické role (--shpd-font-size-title / heading
  // / body / meta, --shpd-font-weight-normal / strong; #135 D15, D16) —
  // referenční implementace kapitoly Detail záznamu v docs/design-system.md.
  // Hlavičku (titulek, podtitul, štítek stavu) kreslí ViewerDetail nad taby
  // (`DetailHeader`); content klíč `header` nenese. Přílohy (vlastní i ze
  // zdrojové zprávy) obsah `document` nenese také — kreslí je sdílená sekce
  // DetailAttachments na konci Přehledu z `detail.attachments` (#136 D21).
  import { t } from '../../i18n/index.js';
  import PropertyGroups from '../ui/PropertyGroups.svelte';
  import Badge from '../ui/Badge.svelte';
  import PartyCard from '../document/PartyCard.svelte';
  import DocRows from '../document/DocRows.svelte';
  import DocTotals from '../document/DocTotals.svelte';
  import { detailParty, detailRows, detailTotals } from '../document/documentParts.js';

  let { content = null, onAction = null } = $props();

  let meta = $derived(content?.meta ?? {});
  let supplier = $derived(detailParty(content?.supplier ?? null, t));
  let customer = $derived(detailParty(content?.customer ?? null, t));
  let rows = $derived(detailRows(content?.rows ?? []));
  let totals = $derived(detailTotals(content?.vat_recap ?? [], content?.totals ?? null));

  // Údaje dokladu ve dvou skupinách vedle sebe (#135): data a platební
  // údaje. Kreslí je sdílená PropertyGroups (#137 D29) — pole s null /
  // prázdnou hodnotou a prázdnou skupinu vynechá ona; dostává hotové
  // přeložené texty.
  const META_GROUPS = [
    {
      labelKey: 'document.section.dates',
      fields: [
        ['issue_date', 'document.meta.issueDate'],
        ['due_date', 'document.meta.dueDate'],
        ['vat_duzp', 'document.meta.taxPointDate'],
        ['accounting_date', 'document.meta.accountingDate'],
      ],
    },
    {
      labelKey: 'document.section.payment',
      fields: [
        ['payment_method', 'document.meta.paymentMethod'],
        ['currency', 'document.meta.currency'],
        ['exchange_rate', 'document.meta.exchangeRate'],
        ['cash_desk', 'document.meta.cashDesk'],
        ['payer', 'document.meta.payer'],
        ['payment_reference', 'document.meta.paymentReference'],
        ['specific_symbol', 'document.meta.specificSymbol'],
        ['constant_symbol', 'document.meta.constantSymbol'],
      ],
    },
  ];

  let metaGroups = $derived(
    META_GROUPS.map((group) => ({
      title: t(group.labelKey),
      items: group.fields.map(([key, labelKey]) => ({ label: t(labelKey), value: meta?.[key] ?? null })),
    }))
  );

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

{#snippet partyName(party)}
  <!-- Klikatelné jméno partnera — odkaz na záznam osoby (`person_id` posílá
       backend jen na partnerské straně, vlastní firma ho nemá). Písmo
       (heading, strong) dědí z obalu názvu v PartyCard. -->
  {#if party.person_id && onAction}
    <button
      type="button"
      class="shpd-docdetail__link"
      onclick={() => openRecord('base_persons_persons', party.person_id)}
    >
      {party.name}
    </button>
  {:else}
    {party.name}
  {/if}
{/snippet}

{#snippet rowDescription(row)}
  <!-- Popis řádku: klikatelný link na Položku, když řádek má FK item
       a položka v DB existuje (backend posílá item_id jen tehdy).
       item_state (archiv/koš) je za popisem jako sdílený štítek Badge
       (koš přeškrtnutý dělá Badge sám). -->
  {#if row.item_id && onAction}
    <button
      type="button"
      class="shpd-docdetail__link"
      onclick={() => openRecord('economy_items', row.item_id)}
    >
      {row.description}
    </button>
  {:else}
    {row.description}
  {/if}
  {#if row.item_state}
    <span class="shpd-docdetail__row-state">
      <Badge style={row.item_state.style} label={row.item_state.label} />
    </span>
  {/if}
{/snippet}

{#if content}
  <div class="shpd-docdetail">
    <!-- Strany: dvě sdílené karty strany (#139 D50) v mřížce dvou sloupců. -->
    <section class="shpd-docdetail__parties">
      <PartyCard title={t('document.section.supplier')} party={supplier} name={partyName} />
      <PartyCard title={t('document.section.customer')} party={customer} name={partyName} />
    </section>

    <!-- Údaje dokladu — sdílené skupiny vlastností (#137 D29); bez
         vyplněného údaje nevykreslí nic, ani mezeru. -->
    <PropertyGroups groups={metaGroups} headingLevel={3} />

    <!-- Řádky dokladu: sdílená část (#139 D45) — tabulka v obalu s posunem
         do strany, pod 36 rem kontejneru složený řádek (pořadí, popis,
         celkem; pod popisem množství × cena · sazba šedě). -->
    {#if rows.length > 0}
      <DocRows title={t('document.section.rows')} {rows} description={rowDescription} />
    {/if}

    <!-- Součty: sdílená část (#139 D52, D55); bez rekapitulace i součtů
         nevykreslí nic. -->
    <DocTotals {totals} />
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

  /* Skupiny údajů kreslí sdílená PropertyGroups (#137 D29), karty stran
     PartyCard, řádky DocRows a součty DocTotals (#139 D48) — vlastní CSS
     tu nemají; zůstává kořen, mřížka stran a odkazy na záznamy. */

  /* ── Strany ──────────────────────────────────────────────────────────── */

  /* Dva sloupce karet stran — není to CardGrid: strany jsou pevně dvě
     a pod 30 rem kontejneru jdou pod sebe (D3). */
  .shpd-docdetail__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  /* Odkaz na záznam (partner v kartě strany, položka v řádku) — tlačítko
     vzhledem odkazu: písmo dědí z místa (název strany heading + strong,
     popis řádku body), primary barva, underline na hover. */
  .shpd-docdetail__link {
    display: inline;
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    text-align: left;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-docdetail__link:hover {
    text-decoration: underline;
  }

  /* Štítek stavu položky (archiv/koš) za popisem řádku — sdílený Badge,
     jen umístění za textem. */
  .shpd-docdetail__row-state {
    margin-left: var(--shpd-space-xs);
    vertical-align: middle;
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
