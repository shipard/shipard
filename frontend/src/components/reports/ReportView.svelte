<script>
  // Čistý renderer ReportResult (docs/reports.md §3) — žádné fetche, žádný
  // stav parametrů. Sloupec s display: 'sides' se rozpadá na trojici
  // MD / D / Zůstatek, 'balance' zobrazuje jen balance. Drill-down (D70):
  // řádek s `link` má název jako odkaz, `cellLinks[sloupec]` textovou
  // buňku — akci (tvar akce detailu vieweru) jen předá rodiči přes onAction.
  //
  // Stav a zprávy (D15, vzhled #133 D64 podle D61): stav reportu je hláška
  // (`Notice`) nad tabulkou; řádek odkázaný z messages přes rowRef
  // ('rows.{index}') je chyba u prvku — ikona před názvem a tón textem,
  // bez pozadí (pozadí má jen mezisoučet); zprávy samotné jdou „pod čaru“
  // dole. Chyba i varování nesou tón ikonou i barvou textu
  // (`--shpd-color-danger-text`, `--shpd-color-warning-text`, D72): textové
  // tokeny tónu drží 4,5 : 1 na pozadí řádku i mezisoučtu v obou vzhledech.
  //
  // Datová mřížka s držící hlavičkou (#133 D57): kořen je flex sloupec
  // ve výšce oblasti výsledku (řetěz výšky drží `ReportsPage`), tabulka
  // v `TableScroll fill` roluje v obou osách a hlavička drží vůči obalu.
  // Hláška stavu je nad obalem, zprávy pod ním — krátký report je má hned
  // pod tabulkou, u dlouhého zůstávají vidět u spodní hrany.
  import Icon from '../ui/Icon.svelte';
  import Notice from '../ui/Notice.svelte';
  import TableScroll from '../ui/TableScroll.svelte';
  import { iconWarning, iconInfo, iconAlert } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { formatAmount } from '../../utils/formatNumber.js';
  import { formatDate } from '../../utils/formatDate.js';

  let { result, thousands = false, onAction = null } = $props();

  const columns = $derived(result?.columns ?? []);
  const rows = $derived(result?.rows ?? []);
  const messages = $derived(result?.messages ?? []);

  const severityRank = { info: 0, warning: 1, error: 2 };
  const severityIcons = { info: iconInfo, warning: iconWarning, error: iconAlert };

  // rowRef 'rows.5' → index 5 do `result.rows`; per řádek drží nejvyšší
  // severity a text první zprávy, která na něj ukazuje (title řádku).
  const rowMarks = $derived.by(() => {
    const map = new Map();
    for (const msg of messages) {
      const match = /^rows\.(\d+)$/.exec(msg.rowRef ?? '');
      if (!match) continue;
      const index = Number(match[1]);
      const prev = map.get(index);
      if (prev === undefined) {
        map.set(index, { severity: msg.severity, title: msg.text });
      } else if (severityRank[msg.severity] > severityRank[prev.severity]) {
        prev.severity = msg.severity;
      }
    }
    return map;
  });

  // Ikonu a tón má jen chyba a varování; `info` řádek neznačí.
  function rowTone(index) {
    const severity = rowMarks.get(index)?.severity;
    return severity === 'error' || severity === 'warning' ? severity : null;
  }

  const hasSides = $derived(columns.some((c) => (c.display ?? 'balance') === 'sides'));

  // Sloupec „Účet“ (#133 D63) — stejné pravidlo jako export
  // (`ReportTabularizer`): je tam, když číslo účtu má aspoň jeden řádek.
  // Číslo mají i mezisoučty (prefix třídy, skupiny, syntetiky), hierarchii
  // tak nese délka čísla a tučné mezisoučty — název se v účtovém reportu
  // neodsazuje. Report bez účtů odsazuje název podle `level` jako dřív.
  // Popisek sloupce určuje definice reportu (`result.accountLabel`, D68 —
  // „Řádek“ u Přiznání k DPH, „Oddíl“ u Kontrolního hlášení), jinak „Účet“.
  const hasAccount = $derived(rows.some((row) => row.account != null && row.account !== ''));

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
    return column.type === 'date' ? formatDate(value) : value;
  }

  function headerLabel(column) {
    // Suffix „(v tisících)" dává smysl jen u peněžních sloupců.
    if (!thousands || isTextual(column)) return column.label;
    return `${column.label} ${t('reports.thousandsSuffix')}`;
  }
</script>

{#snippet rowName(row)}
  {#if row.link && onAction}
    <button type="button" class="shpd-report__link" onclick={() => onAction(row.link)}>{row.label}</button>
  {:else}
    {row.label}
  {/if}
{/snippet}

{#if result}
  <div class="shpd-report">
    {#if result.status !== 'ok'}
      <div class="shpd-report__status">
        <Notice tone={result.status === 'errors' ? 'danger' : 'warning'}>
          {t(result.status === 'errors' ? 'reports.status.errors' : 'reports.status.warnings')}
        </Notice>
      </div>
    {/if}

    {#if rows.length === 0}
      <p class="shpd-report__empty">{t('reports.empty')}</p>
    {:else}
      <TableScroll fill>
        <table class="shpd-report__table">
          <thead>
            <tr>
              {#if hasAccount}
                <th class="shpd-report__th shpd-report__th--account" rowspan={hasSides ? 2 : 1}>{result?.accountLabel ?? t('reports.column.account')}</th>
              {/if}
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
              {@const tone = rowTone(index)}
              <tr
                class="shpd-report__tr"
                class:shpd-report__tr--subtotal={row.kind === 'subtotal'}
                class:shpd-report__tr--total={row.kind === 'total'}
                class:shpd-report__tr--computed={row.kind === 'computed'}
                class:shpd-report__tr--error={tone === 'error'}
                class:shpd-report__tr--warning={tone === 'warning'}
                title={tone ? rowMarks.get(index).title : undefined}
              >
                {#if hasAccount}
                  <td class="shpd-report__td shpd-report__td--account">{row.account ?? ''}</td>
                {/if}
                <td
                  class="shpd-report__td shpd-report__td--label"
                  style:padding-left={hasAccount ? undefined : `calc(var(--shpd-space-sm) + ${row.level}rem)`}
                >
                  {#if tone}
                    <span class="shpd-report__marked">
                      <span class="shpd-report__row-icon"><Icon icon={severityIcons[tone]} size="sm" /></span>
                      <span>{@render rowName(row)}</span>
                    </span>
                  {:else}
                    {@render rowName(row)}
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
      </TableScroll>
    {/if}

    {#if messages.length > 0}
      <ul class="shpd-report__messages">
        {#each messages as msg}
          <li class="shpd-report__message shpd-report__message--{msg.severity}" title={msg.code}>
            <span class="shpd-report__message-icon"><Icon icon={severityIcons[msg.severity] ?? iconInfo} size="sm" /></span>
            <span>{msg.text}</span>
          </li>
        {/each}
      </ul>
    {/if}
  </div>
{/if}

<style>
  .shpd-report {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
  }

  /* Obal hlášky stavu: `Notice` nemá vnější okraj, mezeru pod ní dává
     rodič. `flex: none` — hláška se nesmrští a tabulce nechá zbytek výšky. */
  .shpd-report__status {
    flex: none;
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-report__empty {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  /* border-collapse: separate — s collapse by čáry držící hlavičky
     odjely s obsahem (stejný důvod jako ViewerGrid). */
  .shpd-report__table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background-color: var(--shpd-color-bg);
    font-size: var(--shpd-font-size-sm);
  }

  /* Drží celý thead, ne jednotlivé th: hlavička může mít dva řádky
     (skupina sloupců + MD / D / Zůstatek) a th s top: 0 v obou řádcích by
     se po odrolování překryly. Pozadí nesou th. */
  .shpd-report__table > thead {
    position: sticky;
    top: 0;
    z-index: 1;
  }

  .shpd-report__th {
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
    border-bottom: 1px solid var(--shpd-color-border);
  }

  /* Sloupec názvu drží nejmenší šířku (#138): v úzkém okně je tabulka širší
     než obal a auto layout by názvu nechal jen nejdelší slovo — text by se
     lámal po slovech. 14rem při 390 px dá název účtu na dva až tři řádky
     (číslo účtu má vlastní sloupec, D63); zbytek roluje do strany
     v TableScroll. */
  .shpd-report__td--label {
    min-width: 14rem;
  }

  /* Sloupec „Účet“ (D63) se drží obsahu — číslo se nikdy neláme a volnou
     šířku tabulky (`width: 100%`, report s pár sloupci) dostanou ostatní
     sloupce: `width: 1%` s `nowrap` znamená „jen tolik, kolik obsah“.
     Tučnost dědí z řádku (mezisoučet, součet). */
  .shpd-report__th--account,
  .shpd-report__td--account {
    width: 1%;
    text-align: left;
    white-space: nowrap;
  }

  .shpd-report__td--account {
    color: var(--shpd-color-text-secondary);
    font-variant-numeric: tabular-nums;
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

  /* Se separate se čáry sousedních řádků nesčítají do jedné jako
     u collapse — řádek nad součtem by přidal svou spodní čáru k horní
     čáře součtu. */
  .shpd-report__tr:has(+ .shpd-report__tr--total) > .shpd-report__td {
    border-bottom: none;
  }

  .shpd-report__tr--computed > .shpd-report__td {
    font-style: italic;
    font-weight: 600;
  }

  /* Řádek se zprávou = chyba u prvku (D61, D64): bez pozadí, tón nese
     ikona před názvem a barva textu buněk — u chyby i u varování (D72).
     Odkaz v řádku zůstává v barvě odkazu. */
  /* Ikona a název drží vedle sebe: název jako odkaz je tlačítko
     (inline-block) a dlouhý by v běžném toku spadl pod ikonu. Víceřádkový
     název se láme vedle ikony, ne pod ni. Zarovnání nahoru, ne na účaří:
     účaří víceřádkového tlačítka je jeho poslední řádek a ikona by sjela
     k němu. */
  .shpd-report__marked {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-xs);
  }

  .shpd-report__row-icon {
    flex: none;
  }

  .shpd-report__tr--error > .shpd-report__td {
    color: var(--shpd-color-danger-text);
  }

  .shpd-report__tr--warning > .shpd-report__td {
    color: var(--shpd-color-warning-text);
  }

  /* Zprávy „pod čarou“ mají vlastní svislé rolování a strop výšky, aby
     při mnoha zprávách nezmáčkly tabulku na pár řádků: nejvýš 12 rem
     (zhruba osm zpráv), na nízkém okně nejvýš 35 % výšky výsledku. */
  .shpd-report__messages {
    flex: none;
    max-height: min(12rem, 35%);
    overflow-y: auto;
    margin: var(--shpd-space-md) 0 0;
    padding: var(--shpd-space-sm) 0 0;
    border-top: 1px solid var(--shpd-color-border);
    list-style: none;
  }

  .shpd-report__message {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-xs);
    padding: 0.125rem 0;
    font-size: var(--shpd-font-size-sm);
  }

  /* Tón jako u řádku: chyba i varování textem i ikonou (D72). */
  .shpd-report__message--error {
    color: var(--shpd-color-danger-text);
  }

  .shpd-report__message--warning {
    color: var(--shpd-color-warning-text);
  }

  .shpd-report__message--info {
    color: var(--shpd-color-text-secondary);
  }
</style>
