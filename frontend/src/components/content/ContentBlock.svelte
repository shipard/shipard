<script>
  // Jeden blok obsahu (#150 D83) — sdílené vykreslení jednoduchých typů
  // kontraktu `DetailContent` pro detail záznamu (`ViewerDetail`) i prvek
  // formuláře `content` (`FormElement`): `properties`, `table`, `heading`,
  // `composite`, `notice`, `badges`, `empty`, `text`, `code`, `hint`
  // (backend `DetailContent::SHARED_TYPES`). Všechny typy nesou čistý text,
  // vzhled dávají sdílené komponenty — žádné `{@html}`.
  //
  // `composite` kreslí bloky rekurzivně touž komponentou; mezeru mezi bloky
  // dává obal bloku (D28), blok `heading` je nadpis bloku pod ním. Typ, který
  // komponenta nezná (bloky vázané na viewer — `document`, `proposal`,
  // přílohy…), předá snippetu `other(block)` hostitele, i uvnitř `composite`.
  //
  // `onAction(action)`: odkaz v buňce tabulky (`columns[].link` +
  // `rows[]._action`); bez něj (formulář, read-only náhled) prostý text.
  import ContentBlock from './ContentBlock.svelte';
  import SectionHeading from '../ui/SectionHeading.svelte';
  import Badge from '../ui/Badge.svelte';
  import Notice from '../ui/Notice.svelte';
  import PropertyGroups from '../ui/PropertyGroups.svelte';
  import TableScroll from '../ui/TableScroll.svelte';
  import { t } from '../../i18n/index.js';

  let { block = null, onAction = null, other = undefined } = $props();
</script>

{#if block?.type === 'properties'}
  <!-- Skupiny vlastností sdílenou komponentou (#137 D29): dva sloupce,
       skupina `wide` přes celou šířku, prázdné skupiny a hodnoty
       vynechá komponenta. Nadpis skupiny h4. -->
  <PropertyGroups groups={block.groups} headingLevel={4} />

{:else if block?.type === 'table'}
  <TableScroll>
    <table class="shpd-content__table">
      <thead>
        <tr>
          {#each block.columns ?? [] as col (col.id)}
            <th class="shpd-content__th" class:shpd-content__th--num={col.align === 'right'}>{col.label}</th>
          {/each}
        </tr>
      </thead>
      <tbody>
        {#if (block.rows ?? []).length === 0}
          <tr>
            <td class="shpd-content__empty-cell" colspan={block.columns?.length ?? 1}>
              {t('common.empty')}
            </td>
          </tr>
        {:else}
          <!-- row._class (error | total | muted) — klasifikace řádku od
               backendu: chybové a součtové řádky účetního deníku,
               tlumené plánované řádky (odpisy majetku). -->
          {#each block.rows ?? [] as row}
            <tr
              class:shpd-content__tr--error={row._class === 'error'}
              class:shpd-content__tr--total={row._class === 'total'}
              class:shpd-content__tr--muted={row._class === 'muted'}
            >
              <!-- col.link + row._action: buňka je odkaz, klik spustí
                   akci řádku (typicky open_detail dokladu). Bez
                   onAction (read-only modal, formulář) zůstává prostý text. -->
              {#each block.columns ?? [] as col (col.id)}
                <td class="shpd-content__td" class:shpd-content__td--num={col.align === 'right'}>
                  {#if col.link && row._action && onAction}
                    <button type="button" class="shpd-content__cell-link" onclick={() => onAction(row._action)}>
                      {row[col.id] ?? '—'}
                    </button>
                  {:else}
                    {row[col.id] ?? '—'}
                  {/if}
                </td>
              {/each}
            </tr>
          {/each}
        {/if}
      </tbody>
    </table>
  </TableScroll>

{:else if block?.type === 'notice'}
  <!-- Hláška v obsahu (#137 D30, D40): backend posílá tón a text
       nebo položky, vzhled dává sdílená Notice. -->
  <Notice tone={block.tone} title={block.title ?? ''} items={block.items}>
    {#if block.text}{block.text}{/if}
  </Notice>

{:else if block?.type === 'badges'}
  <!-- Řada štítků (stav zaúčtování) — tytéž styly jako štítky hlavičky. -->
  <div class="shpd-content__badges">
    {#each block.items ?? [] as badge}
      <Badge style={badge.style ?? 'neutral'} label={badge.label} />
    {/each}
  </div>

{:else if block?.type === 'empty'}
  <p class="shpd-content__empty">{block.text}</p>

{:else if block?.type === 'text'}
  <!-- Prostý text (tělo pošty bez HTML, AI shrnutí) — vkládá se jako
       text, zalomení z obsahu zůstávají. -->
  <p class="shpd-content__text">{block.text}</p>

{:else if block?.type === 'hint'}
  <!-- Nápověda (#150 D84) — odstavec body šedě, bez tučného zvýraznění. -->
  <p class="shpd-content__hint">{block.text}</p>

{:else if block?.type === 'code'}
  <!-- Strojový výpis (JSON kontextu upozornění) — neproporcionální
       písmo, dlouhý řádek roluje v bloku. -->
  <pre class="shpd-content__code">{block.text}</pre>

{:else if block?.type === 'heading'}
  <SectionHeading level={3}>{block.text}</SectionHeading>

{:else if block?.type === 'composite'}
  <!-- Composite = seznam bloků, každý blok je libovolný známý typ.
       Mezeru mezi bloky dává obal bloku (D28), blok `heading` je nadpis
       bloku pod ním. -->
  {#each block.blocks ?? [] as inner}
    <div class="shpd-content__block" class:shpd-content__block--heading={inner?.type === 'heading'}>
      <ContentBlock block={inner} {onAction} {other} />
    </div>
  {/each}

{:else if block}
  {@render other?.(block)}
{/if}

<style>
  /* Bloky composite: sekce od sebe odděluje mezera (D28) — dává ji obal
     bloku, ne nadpis (SectionHeading horní odsazení nemá). Blok `heading` je
     nadpis bloku pod ním (tabulka podzakázek, Kontrola deníku), proto ten
     mezeru nad sebou nemá. Skupiny vlastností kreslí PropertyGroups. */
  .shpd-content__block + .shpd-content__block {
    margin-top: var(--shpd-space-lg);
  }

  .shpd-content__block--heading + .shpd-content__block {
    margin-top: 0;
  }

  /* Table content — obal s posunem do strany dává `TableScroll` (D44). */
  .shpd-content__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-body);
  }

  /* Hlavička sloupce — body + strong ve velikosti řádků (není heading,
     #137 task 2b). */
  .shpd-content__th {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-content__td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
  }

  .shpd-content__empty-cell {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  /* Číselné sloupce (columns[].align === 'right') — stejná konvence jako
     .num v DocumentDetail. */
  .shpd-content__th--num,
  .shpd-content__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Buňka-odkaz (col.link + row._action) — vypadá jako odkaz, chová se
     jako tlačítko. Dědí písmo i zarovnání buňky. */
  .shpd-content__cell-link {
    padding: 0;
    border: none;
    background: transparent;
    font: inherit;
    color: var(--shpd-color-primary);
    cursor: pointer;
    text-align: inherit;
  }

  .shpd-content__cell-link:hover {
    color: var(--shpd-color-primary-hover);
    text-decoration: underline;
  }

  /* Klasifikace řádků (row._class) — chybové a součtové řádky. */
  .shpd-content__tr--error > .shpd-content__td {
    background: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-content__tr--total > .shpd-content__td {
    font-weight: var(--shpd-font-weight-strong);
    border-top: 2px solid var(--shpd-color-border);
    border-bottom: none;
  }

  .shpd-content__tr--muted > .shpd-content__td {
    color: var(--shpd-color-text-secondary);
  }

  /* Obecné typy obsahu (#137 D30): hláška kreslí Notice, štítky Badge;
     prázdný stav, prostý text, nápověda a kód jsou odstavce v rolích
     body / meta. Backend HTML se vzhledem neposílá. */
  .shpd-content__badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  /* Prázdný stav — odstavec body šedě, vystředěný; jako jediný obsah tabu
     detailu stojí uprostřed výšky plochy (`height: 100%`), jinde (blok
     composite, formulář) se procento nepoužije. */
  .shpd-content__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    margin: 0;
    font-size: var(--shpd-font-size-body);
    line-height: 1.5;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content__text {
    margin: 0;
    font-size: var(--shpd-font-size-body);
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  /* Nápověda (#150 D84) — body, šedě, zalomení z textu; bez vnějšího
     okraje, mezeru dává hostitel. */
  .shpd-content__hint {
    margin: 0;
    font-size: var(--shpd-font-size-body);
    line-height: 1.5;
    color: var(--shpd-color-text-secondary);
    white-space: pre-wrap;
  }

  .shpd-content__code {
    margin: 0;
    padding: var(--shpd-space-sm);
    overflow-x: auto;
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg-secondary);
    font-family: var(--shpd-font-family-mono);
    font-size: var(--shpd-font-size-meta);
    line-height: 1.5;
  }
</style>
