<script>
  /**
   * Renders a `table` block token from markdown.js as a native <table>.
   * All cell content is text-bound spans — never {@html} — and the per-column
   * text-align comes from the parser's whitelisted values, never model text.
   */
  import Icon from '../ui/Icon.svelte';
  import { iconCopy, iconConfirm } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { copyTableToClipboard } from './clipboardTable.js';
  import TableScroll from '../ui/TableScroll.svelte';

  let { header = [], rows = [], align = [] } = $props();

  let copied = $state(false);
  let copiedTimer = null;

  const cellStyle = (col) => (align[col] ? `text-align: ${align[col]}` : null);

  async function copy() {
    try {
      await copyTableToClipboard({ header, rows });
    } catch {
      return; // clipboard unavailable (e.g. insecure context) — no feedback
    }
    copied = true;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied = false), 2000);
  }
</script>

{#snippet cell(spans)}
  {#each spans as span}
    {#if span.type === 'strong'}<strong>{span.text}</strong>
    {:else if span.type === 'em'}<em>{span.text}</em>
    {:else if span.type === 'code'}<code>{span.text}</code>
    {:else}{span.text}{/if}
  {/each}
{/snippet}

<div class="shpd-md-table">
  <button
    class="shpd-md-table__copy"
    class:shpd-md-table__copy--copied={copied}
    aria-label={t('chat.copyTable')}
    title={t('chat.copyTable')}
    onclick={copy}
  >
    <Icon icon={copied ? iconConfirm : iconCopy} size="sm" />
    {#if copied}<span>{t('chat.copied')}</span>{/if}
  </button>
  <TableScroll label={t('chat.table')}>
    <table>
      <thead>
        <tr>
          {#each header as cellSpans, col}
            <th style={cellStyle(col)}>{@render cell(cellSpans)}</th>
          {/each}
        </tr>
      </thead>
      <tbody>
        {#each rows as row}
          <tr>
            {#each row as cellSpans, col}
              <td style={cellStyle(col)}>{@render cell(cellSpans)}</td>
            {/each}
          </tr>
        {/each}
      </tbody>
    </table>
  </TableScroll>
</div>

<style>
  .shpd-md-table {
    position: relative;
    margin: 0 0 var(--shpd-space-sm);
  }
  .shpd-md-table:last-child {
    margin-bottom: 0;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }
  th,
  td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
    vertical-align: top;
    /* Bublina (`.shpd-msg__text`, `.shpd-md`) má `word-break: break-word`
       a tabulka ho dědí. Na rozdíl od `overflow-wrap: break-word` zmenšuje
       i nejmenší šířku obsahu (min-content) — automatický layout tabulky
       pak smí zúžit sloupec pod šířku slova, tabulka se vždy vejde
       a láme text po znacích. Tabulka má rolovat ve svém obalu, ne lámat
       slova (#133 D44, D57); dlouhé nedělitelné slovo (URL) sloupec
       rozšíří. `overflow-wrap` sem nepřidávat. */
    word-break: normal;
  }
  th {
    font-weight: 600;
  }
  tbody tr:nth-child(even) {
    background-color: var(--shpd-color-bg-secondary);
  }
  tbody tr:last-child td {
    border-bottom: 0;
  }

  .shpd-md-table__copy {
    position: absolute;
    top: 2px;
    right: 2px;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0.125rem 0.375rem;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease;
  }
  .shpd-md-table:hover .shpd-md-table__copy,
  .shpd-md-table:focus-within .shpd-md-table__copy,
  .shpd-md-table__copy--copied {
    opacity: 1;
  }
  .shpd-md-table__copy:hover {
    color: var(--shpd-color-text);
  }
  @media (hover: none) {
    .shpd-md-table__copy {
      opacity: 1;
    }
  }
</style>
