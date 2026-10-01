<script>
  // Čistý renderer ReportResult (docs/reports.md §3) — žádné fetche, žádný
  // stav parametrů. Sloupec s display: 'sides' se rozpadá na trojici
  // MD / D / Zůstatek, 'balance' zobrazuje jen balance. Řádky odkázané
  // z messages přes rowRef ('rows.{index}') se podbarvují dle severity;
  // zprávy samotné jdou „pod čaru" dole (D15). Drill-down (D70): řádek
  // s `link` má název jako odkaz, `cellLinks[sloupec]` textovou buňku —
  // akci (tvar akce detailu vieweru) jen předá rodiči přes onAction.
  import Icon from '../ui/Icon.svelte';
  import { iconWarning, iconInfo, iconAlert } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { formatAmount } from '../../utils/formatNumber.js';
  import { language } from '../../stores/language.svelte.js';

  let { result, thousands = false, onAction = null } = $props();

  const columns = $derived(result?.columns ?? []);
  const rows = $derived(result?.rows ?? []);
  const messages = $derived(result?.messages ?? []);

  const severityRank = { info: 0, warning: 1, error: 2 };
  const severityIcons = { info: iconInfo, warning: iconWarning, error: iconAlert };

  // rowRef 'rows.5' → index 5; per řádek drží nejvyšší severity.
  const rowSeverity = $derived.by(() => {
    const map = new Map();
    for (const msg of messages) {
      const match = /^rows\.(\d+)$/.exec(msg.rowRef ?? '');
      if (!match) continue;
      const index = Number(match[1]);
      const prev = map.get(index);
      if (prev === undefined || severityRank[msg.severity] > severityRank[prev]) {
        map.set(index, msg.severity);
      }
    }
    return map;
  });

  const hasSides = $derived(columns.some((c) => (c.display ?? 'balance') === 'sides'));

  function fmt(value) {
    // Nuly prázdné — účetní konvence, mřížka se nezahltí nulami.
    if (value === 0 || value == null) return '';
    return formatAmount(value, { thousands });
  }

  // Text/date sloupce nesou buňku jako prostý string (datum ISO YYYY-MM-DD).
  function isTextual(column) {
    const type = column.type ?? 'money';
    return type === 'text' || type === 'date';
  }

  function fmtText(column, cell) {
    const value = typeof cell === 'string' ? cell : '';
    if (column.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      const d = new Date(value);
      if (!Number.isNaN(d.getTime())) {
        return d.toLocaleDateString(language.current === 'en' ? 'en-US' : 'cs-CZ');
      }
    }
    return value;
  }

  function headerLabel(column) {
    // Suffix „(v tisících)" dává smysl jen u peněžních sloupců.
    if (!thousands || isTextual(column)) return column.label;
    return `${column.label} ${t('reports.thousandsSuffix')}`;
  }
</script>

{#if result}
  {#if result.status !== 'ok'}
    <div
      class="shpd-report__badge"
      class:shpd-report__badge--error={result.status === 'errors'}
    >
      <Icon icon={result.status === 'errors' ? iconAlert : iconWarning} size="sm" />
      <span>{t(result.status === 'errors' ? 'reports.status.errors' : 'reports.status.warnings')}</span>
    </div>
  {/if}

  {#if rows.length === 0}
    <p class="shpd-report__empty">{t('reports.empty')}</p>
  {:else}
    <div class="shpd-report__scroll">
      <table class="shpd-report__table">
        <thead>
          <tr>
            <th class="shpd-report__th shpd-report__th--label" rowspan={hasSides ? 2 : 1}></th>
            {#each columns as column (column.id)}
              {#if isTextual(column)}
                <th class="shpd-report__th shpd-report__th--text" rowspan={hasSides ? 2 : 1}>{headerLabel(column)}</th>
              {:else if (column.display ?? 'balance') === 'sides'}
                <th class="shpd-report__th shpd-report__th--group" colspan="3">{headerLabel(column)}</th>
              {:else}
                <th class="shpd-report__th shpd-report__th--num" rowspan={hasSides ? 2 : 1}>{headerLabel(column)}</th>
              {/if}
            {/each}
          </tr>
          {#if hasSides}
            <tr>
              {#each columns as column (column.id)}
                {#if (column.display ?? 'balance') === 'sides'}
                  <th class="shpd-report__th shpd-report__th--num shpd-report__th--sub">{t('reports.side.md')}</th>
                  <th class="shpd-report__th shpd-report__th--num shpd-report__th--sub">{t('reports.side.d')}</th>
                  <th class="shpd-report__th shpd-report__th--num shpd-report__th--sub">{t('reports.side.balance')}</th>
                {/if}
              {/each}
            </tr>
          {/if}
        </thead>
        <tbody>
          {#each rows as row, index}
            <tr
              class="shpd-report__tr"
              class:shpd-report__tr--subtotal={row.kind === 'subtotal'}
              class:shpd-report__tr--total={row.kind === 'total'}
              class:shpd-report__tr--computed={row.kind === 'computed'}
              class:shpd-report__tr--error={rowSeverity.get(index) === 'error'}
              class:shpd-report__tr--warning={rowSeverity.get(index) === 'warning'}
            >
              <td
                class="shpd-report__td shpd-report__td--label"
                style:padding-left="calc(var(--shpd-space-sm) + {row.level * 16}px)"
              >
                {#if row.account}<span class="shpd-report__account">{row.account}</span>{/if}
                {#if row.link && onAction}
                  <button type="button" class="shpd-report__link" onclick={() => onAction(row.link)}>{row.label}</button>
                {:else}
                  {row.label}
                {/if}
              </td>
              {#each columns as column (column.id)}
                {@const cell = row.values?.[column.id]}
                {#if isTextual(column)}
                  <td class="shpd-report__td shpd-report__td--text">
                    {#if row.cellLinks?.[column.id] && onAction && fmtText(column, cell) !== ''}
                      <button
                        type="button"
                        class="shpd-report__link"
                        onclick={() => onAction(row.cellLinks[column.id])}
                      >{fmtText(column, cell)}</button>
                    {:else}
                      {fmtText(column, cell)}
                    {/if}
                  </td>
                {:else if (column.display ?? 'balance') === 'sides'}
                  <td class="shpd-report__td shpd-report__td--num">{fmt(cell?.md)}</td>
                  <td class="shpd-report__td shpd-report__td--num">{fmt(cell?.d)}</td>
                  <td class="shpd-report__td shpd-report__td--num">{fmt(cell?.balance)}</td>
                {:else}
                  <td class="shpd-report__td shpd-report__td--num">{fmt(cell?.balance)}</td>
                {/if}
              {/each}
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}

  {#if messages.length > 0}
    <ul class="shpd-report__messages">
      {#each messages as msg}
        <li class="shpd-report__message shpd-report__message--{msg.severity}" title={msg.code}>
          <Icon icon={severityIcons[msg.severity] ?? iconInfo} size="sm" />
          <span>{msg.text}</span>
        </li>
      {/each}
    </ul>
  {/if}
{/if}

<style>
  .shpd-report__badge {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin-bottom: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
  }

  .shpd-report__badge--error {
    background-color: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-report__empty {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-report__scroll {
    overflow-x: auto;
  }

  .shpd-report__table {
    width: 100%;
    border-collapse: collapse;
    background-color: var(--shpd-color-bg);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-report__th {
    position: sticky;
    top: 0;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background-color: var(--shpd-color-bg);
    border-bottom: 2px solid var(--shpd-color-border);
    color: var(--shpd-color-text-secondary);
    font-weight: 600;
    white-space: nowrap;
  }

  .shpd-report__th--label {
    text-align: left;
  }

  .shpd-report__th--group {
    text-align: center;
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-report__th--num {
    text-align: right;
  }

  .shpd-report__th--sub {
    font-weight: 500;
  }

  .shpd-report__td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border-light, var(--shpd-color-border));
  }

  .shpd-report__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .shpd-report__th--text,
  .shpd-report__td--text {
    text-align: left;
    white-space: nowrap;
  }

  .shpd-report__account {
    display: inline-block;
    min-width: 4.5em;
    margin-right: var(--shpd-space-xs);
    color: var(--shpd-color-text-secondary);
    font-variant-numeric: tabular-nums;
  }

  .shpd-report__link {
    padding: 0;
    border: none;
    background: transparent;
    font: inherit;
    color: var(--shpd-color-primary);
    cursor: pointer;
    text-align: inherit;
  }

  .shpd-report__link:hover {
    color: var(--shpd-color-primary-hover);
    text-decoration: underline;
  }

  .shpd-report__tr--subtotal > .shpd-report__td {
    font-weight: 600;
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-report__tr--total > .shpd-report__td {
    font-weight: 700;
    border-top: 2px solid var(--shpd-color-border);
    border-bottom: none;
  }

  .shpd-report__tr--computed > .shpd-report__td {
    font-style: italic;
    font-weight: 600;
  }

  .shpd-report__tr--error > .shpd-report__td {
    background-color: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-report__tr--warning > .shpd-report__td {
    background-color: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
  }

  .shpd-report__messages {
    margin: var(--shpd-space-md) 0 0;
    padding: var(--shpd-space-sm) 0 0;
    border-top: 1px solid var(--shpd-color-border);
    list-style: none;
  }

  .shpd-report__message {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-xs);
    padding: 2px 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-report__message--error {
    color: var(--shpd-color-state-error-text);
  }

  .shpd-report__message--warning {
    color: var(--shpd-color-alert-warning-text);
  }

  .shpd-report__message--info {
    color: var(--shpd-color-text-secondary);
  }
</style>
