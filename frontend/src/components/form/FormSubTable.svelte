<script>
  /**
   * Sub-tabulka ve formuláři (tab typu `subtable`) — issue #53, fáze 1.
   *
   * Sloupce i obsah buněk definuje server (`GET /_ui/form/{parentTable}/
   * subtable/{tabId}/{parentId}`, renderer na rodičovském formu — viz
   * docs/edit-forms.md kap. 15). Tvar `columns` je shodný s gridem
   * vieweru (`id`, `label`, `align`, `grow`, `width`), buňka je string nebo
   * `{text, class}` span; klient nezná FK, enumy ani formát částek.
   *
   * Režimy:
   *   - editovatelný rodič: Přidat vlevo v toolbaru, u řádku Upravit / Smazat
   *     (Smazat potvrzuje ConfirmDialog), dvojklik = Upravit;
   *   - `disabled` (rodič se právě ukládá / přepočítává): tytéž akce, jen
   *     dočasně vypnuté — ikony nepřeskakují na Zobrazit;
   *   - `readOnly` (rodič jen pro čtení): bez Přidat / Smazat, u řádku
   *     Zobrazit — dialog řádku se otevře s vypnutými poli, bez Uložit
   *     a přechodů (FormDialog `readOnly`);
   *   - `element.independent_rows` (`subtableTab(..., independentRows:)`):
   *     řádky žijí vlastním životem — bez Přidat / Smazat / přesunu vždy,
   *     ale dialog řádku se otevře plný i u read-only rodiče a řídí se
   *     stavy a zámkem vlastní tabulky (události majetku nad kartou
   *     ve stavu V pořádku).
   *
   * Klientský filtr nad tabulkou od 11 řádků (bez diakritiky, přes texty
   * všech buněk); serverové hledání zatím ne (tasks/TODO.md).
   *
   * Dialog řádku (fáze 2): nový záznam má Přidat (uloží a zavře) a Přidat
   * a pokračovat (uloží, reset na další nový); existující záznam listuje
   * šipkami ‹ › přes `navigation` (i v read-only režimu).
   *
   * Pořadí (fáze 3): když server pošle `order_column`, řádky mají šipky
   * ▲ ▼ → `POST …/move`, server skupinu přečísluje 1..N a prohodí sousedy;
   * poté jen refetch (mění se i sloupec #). Šipky chybí u read-only rodiče
   * a při zadaném textu filtru (soused ve filtru není soused v pořadí).
   */
  import { get, post, del } from '../../api/client.js';
  import Button from '../ui/Button.svelte';
  import Input from '../ui/Input.svelte';
  import ConfirmDialog from '../ui/ConfirmDialog.svelte';
  import FormDialog from './FormDialog.svelte';
  import { iconAdd, iconEdit, iconDelete, iconPreview, iconMoveUp, iconMoveDown } from '../../icons.js';
  import { normalizeSpans } from '../viewer/viewerSpans.js';
  import { foldDiacritics } from '../../utils/paletteMatch.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    /** `tab.subtable` z FormDefinition: { table, foreign_key, form_id?, sort? }. */
    element,
    /** Id tabu — segment cesty endpointu. */
    tabId,
    /** Tabulka rodiče — segment cesty endpointu. */
    parentTable = null,
    parentId = null,
    disabled = false,
    readOnly = false,
    /** Po uložení / smazání řádku — rodič si přenačte odvozené hodnoty (součty). */
    onChanged,
  } = $props();

  const independentRows = $derived(!!element?.independent_rows);
  /** Seznam (Přidat / Smazat / přesun) je jen ke čtení. */
  const listReadOnly = $derived(readOnly || independentRows);
  /** Dialog řádku jen ke čtení — nezávislé řádky ho mají plný vždy. */
  const dialogReadOnly = $derived(readOnly && !independentRows);

  /** Filtr se zobrazí až nad tímto počtem řádků. */
  const FILTER_THRESHOLD = 10;

  let columns = $state([]);
  let rows = $state([]);
  // Pořadový sloupec z deklarace tabu (`subtableTab(..., orderColumn:)`);
  // null = tabulka pořadí neřeší, šipky se nerenderují.
  let orderColumn = $state(null);
  // Probíhá přesun — obě šipky vypnuté proti dvojkliku.
  let moving = $state(false);
  let loading = $state(false);
  let loadError = $state(null);
  let filter = $state('');

  // Dialog řádku (nový / úprava / prohlížení)
  let dialogOpen = $state(false);
  let editRecordId = $state(null);

  // Potvrzení smazání
  let deleteId = $state(null);
  let deleting = $state(false);

  async function fetchRows() {
    if (parentId == null || !parentTable || !tabId) return;
    loading = true;
    loadError = null;
    const res = await get(`/_ui/form/${parentTable}/subtable/${tabId}/${parentId}`);
    if (res?.success) {
      columns = res.data?.columns ?? [];
      rows = res.data?.rows ?? [];
      orderColumn = res.data?.order_column ?? null;
    } else {
      loadError = res?.error ? translateError(res.error) : t('subtable.loadFailed');
    }
    loading = false;
  }

  // ── Filtr ──────────────────────────────────────────────────────────────────

  const filterVisible = $derived(rows.length > FILTER_THRESHOLD);

  function rowText(row) {
    const parts = [];
    for (const col of columns) {
      for (const span of normalizeSpans(row.cells?.[col.id]) ?? []) {
        if (span?.text) parts.push(span.text);
      }
    }
    return foldDiacritics(parts.join(' '));
  }

  const visibleRows = $derived.by(() => {
    const q = foldDiacritics(filter.trim());
    if (!filterVisible || q === '') return rows;
    return rows.filter(row => rowText(row).includes(q));
  });

  // Reset filtru při změně rodiče — jiný záznam, jiný seznam.
  $effect(() => {
    parentId;
    filter = '';
  });

  // ── Pořadí ─────────────────────────────────────────────────────────────────

  // Zadaný text filtru = zúžený seznam; přesun by prohazoval sousedy, které
  // uživatel nevidí vedle sebe. Prázdný filtr (jen zobrazené pole) nevadí.
  const filterActive = $derived(filterVisible && filter.trim() !== '');
  const canReorder = $derived(orderColumn != null && !listReadOnly && !filterActive);

  async function moveRow(id, direction) {
    if (!canReorder || disabled || moving) return;
    moving = true;
    const res = await post(`/_ui/form/${parentTable}/subtable/${tabId}/${parentId}/move`, { id, direction });
    moving = false;
    if (!res?.success) {
      loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
      return;
    }
    // Refetch, ne lokální přeuspořádání: server přečísloval celou skupinu
    // a sloupec # by jinak ukazoval stará čísla. Řádky zůstávají zobrazené,
    // tabulka nebliká. Rodič se nepřenačítá — součty na pořadí nezávisí.
    await fetchRows();
  }

  // ── Sloupce ────────────────────────────────────────────────────────────────

  /**
   * Inline styl <col>: px jen pro pevnou šířku. Rostoucí sloupec záměrně
   * BEZ `width: 100%` (vzor ViewerGrid): v auto layoutu se k procentuálnímu
   * sloupci přičtou pevné šířky ostatních a tabulka přeteče doprava přes
   * padding tabu — grid to maskuje vlastním scroll kontejnerem, formulář ne.
   * Zbytek šířky dostanou automatické sloupce úměrně obsahu; číselné sloupce
   * se smrsknou na obsah přes `--num` (width: 1% + nowrap), takže text
   * (popis) dostane prakticky všechno.
   */
  function colStyle(col) {
    if (col.width) return `width: ${col.width}px;`;
    return '';
  }

  // ── Akce ───────────────────────────────────────────────────────────────────

  function handleAdd() {
    if (disabled || listReadOnly) return;
    editRecordId = null;
    dialogOpen = true;
  }

  /** Upravit / Zobrazit — dialog rozliší režim přes `dialogReadOnly`. */
  function openRow(id) {
    if (disabled && !dialogReadOnly) return;
    editRecordId = id;
    dialogOpen = true;
  }

  function requestDelete(id) {
    if (disabled || listReadOnly) return;
    deleteId = id;
  }

  async function confirmDelete() {
    if (deleteId == null || deleting) return;
    deleting = true;
    const res = await del(`/${element.table}/${deleteId}`);
    deleting = false;
    deleteId = null;
    if (!res?.success) {
      loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
    }
    await fetchRows();
    onChanged?.();
  }

  function cancelDelete() {
    if (deleting) return;
    deleteId = null;
  }

  function handleDialogClose() {
    dialogOpen = false;
    editRecordId = null;
  }

  // onSaved se volá po každém uložení včetně přechodu stavu s close_form: 0
  // (např. "Opravit" 40 → 80). Modal proto NEzavíráme bezpodmínečně — to by
  // shazovalo formulář i při Opravit, což je špatně (viz hlavní modaly, kde
  // onSaved jen refetchuje). Zavíráme u formulářů BEZ doc states — ty mají
  // jedinou akci Uložit (žádné přechody), takže po Uložit nemá smysl zůstávat
  // otevřený (jinak by šel zavřít jen křížkem) — a u NOVÉHO záznamu vždy
  // (tlačítko Přidat = uložit a zavřít; Přidat a pokračovat jde jinou cestou,
  // viz handleSaveAndContinue). Existující záznam s doc states (Kontakty,
  // Adresy …) zůstane otevřený — zavření řeší close_form / onClose.
  async function handleDialogSaved(_record, info) {
    if (!info?.hasDocStates || info?.wasNew) {
      dialogOpen = false;
      editRecordId = null;
    }
    await fetchRows();
    onChanged?.();
  }

  // Přidat a pokračovat: řádek je uložený, dialog zůstává otevřený na dalším
  // novém záznamu (reset řeší FormEditor). Jen refetch + rodič si přepočte
  // odvozené hodnoty.
  async function handleSaveAndContinue() {
    await fetchRows();
    onChanged?.();
  }

  // Navigace Předchozí / Další v dialogu řádku (fáze 2): index
  // v NEFILTROVANÉM seřazeném seznamu `rows` — filtr je jen pro tabulku,
  // listování jde přes všechny řádky rodiče. -1 = nový záznam → dialog šipky
  // nerenderuje. Změna editRecordId → FormDialog dostane nový recordId →
  // FormEditor formulář přenačte ($effect na recordId).
  const navigation = $derived.by(() => {
    const index = editRecordId == null ? -1 : rows.findIndex(r => r.id === editRecordId);
    const count = rows.length;
    return {
      index,
      count,
      onPrev: () => {
        if (index > 0) editRecordId = rows[index - 1].id;
      },
      onNext: () => {
        if (index >= 0 && index < count - 1) editRecordId = rows[index + 1].id;
      },
    };
  });

  // Načtení při změně rodiče / tabu. Závislosti jen na primitivech — po
  // tichém reloadu rodiče přijde nový objekt `element`, ale řádky jsme si
  // už načetli sami a druhý fetch je zbytečný.
  $effect(() => {
    const pid = parentId;
    const pt = parentTable;
    const tid = tabId;
    if (pid != null && pt && tid) fetchRows();
  });
</script>

<div class="shpd-form-subtable" data-testid="subtable">
  {#if parentId == null}
    <div class="shpd-form-subtable__info">
      {t('subtable.saveFirst')}
    </div>
  {:else}
    <div class="shpd-form-subtable__toolbar">
      <div class="shpd-form-subtable__toolbar-left">
        {#if !listReadOnly}
          <Button
            label={t('common.add')}
            icon={iconAdd}
            variant="secondary"
            size="sm"
            onclick={handleAdd}
            {disabled}
            testid="subtable-add"
          />
        {/if}
      </div>
      {#if filterVisible}
        <div class="shpd-form-subtable__filter">
          <Input
            bind:value={filter}
            placeholder={t('subtable.filterPlaceholder')}
            testid="subtable-filter"
          />
        </div>
      {/if}
    </div>

    {#if loadError}
      <div class="shpd-form-subtable__error" role="alert">{loadError}</div>
    {/if}

    {#if loading && rows.length === 0}
      <div class="shpd-form-subtable__status">{t('common.loading')}</div>
    {:else if rows.length === 0}
      <div class="shpd-form-subtable__status">{t('common.empty')}</div>
    {:else if visibleRows.length === 0}
      <div class="shpd-form-subtable__status">{t('subtable.noMatch')}</div>
    {:else}
      <div class="shpd-form-subtable__table-wrap">
      <table class="shpd-form-subtable__table">
        <colgroup>
          {#each columns as col (col.id)}
            <col style={colStyle(col)} />
          {/each}
          <col style="width: {canReorder ? 148 : 72}px;" />
        </colgroup>
        <thead>
          <tr>
            {#each columns as col (col.id)}
              <th
                class="shpd-form-subtable__th"
                class:shpd-form-subtable__th--num={col.align === 'right'}
              >{col.label}</th>
            {/each}
            <th class="shpd-form-subtable__th shpd-form-subtable__th--actions"></th>
          </tr>
        </thead>
        <tbody>
          {#each visibleRows as row, i (row.id)}
            <tr
              class="shpd-form-subtable__tr {row.stateStyle ? `docState_${row.stateStyle}` : ''}"
              data-testid="subtable-row"
              data-row-id={row.id}
              ondblclick={() => openRow(row.id)}
            >
              {#each columns as col (col.id)}
                <td
                  class="shpd-form-subtable__td"
                  class:shpd-form-subtable__td--num={col.align === 'right'}
                >
                  {#each normalizeSpans(row.cells?.[col.id]) ?? [] as span}
                    <span class={span.class ? `shpd-form-subtable__span--${span.class}` : ''}>{span.text}</span>
                  {/each}
                </td>
              {/each}
              <td class="shpd-form-subtable__td shpd-form-subtable__actions">
                {#if canReorder}
                  <Button
                    icon={iconMoveUp}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('subtable.moveUp')}
                    disabled={disabled || moving || i === 0}
                    onclick={() => moveRow(row.id, 'up')}
                    testid="subtable-row-up"
                  />
                  <Button
                    icon={iconMoveDown}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('subtable.moveDown')}
                    disabled={disabled || moving || i === visibleRows.length - 1}
                    onclick={() => moveRow(row.id, 'down')}
                    testid="subtable-row-down"
                  />
                {/if}
                {#if dialogReadOnly}
                  <Button
                    icon={iconPreview}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('common.view')}
                    onclick={() => openRow(row.id)}
                    testid="subtable-row-view"
                  />
                {:else if independentRows}
                  <Button
                    icon={iconEdit}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('common.open')}
                    {disabled}
                    onclick={() => openRow(row.id)}
                    testid="subtable-row-edit"
                  />
                {:else}
                  <Button
                    icon={iconEdit}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('common.edit')}
                    {disabled}
                    onclick={() => openRow(row.id)}
                    testid="subtable-row-edit"
                  />
                  <Button
                    icon={iconDelete}
                    iconOnly
                    size="sm"
                    variant="ghost"
                    label={t('common.delete')}
                    {disabled}
                    onclick={() => requestDelete(row.id)}
                    testid="subtable-row-delete"
                  />
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
      </div>
    {/if}
  {/if}
</div>

<FormDialog
  table={element.table}
  recordId={editRecordId}
  open={dialogOpen}
  onClose={handleDialogClose}
  onSaved={handleDialogSaved}
  onSaveAndContinue={handleSaveAndContinue}
  {navigation}
  defaultData={{ [element.foreign_key]: parentId }}
  readOnly={dialogReadOnly}
  notice={dialogReadOnly ? t('subtable.readOnlyNotice') : null}
/>

<ConfirmDialog
  open={deleteId != null}
  title={t('subtable.deleteTitle')}
  message={t('subtable.confirmDelete')}
  confirmLabel={t('common.delete')}
  variant="danger"
  busy={deleting}
  onConfirm={confirmDelete}
  onCancel={cancelDelete}
/>

<style>
  .shpd-form-subtable {
    width: 100%;
  }

  .shpd-form-subtable__info,
  .shpd-form-subtable__status {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-subtable__error {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    margin-bottom: var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-alert-danger-bg, var(--shpd-color-bg-hover));
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-subtable__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) 0;
  }

  .shpd-form-subtable__toolbar-left {
    display: flex;
    gap: var(--shpd-space-sm);
  }

  .shpd-form-subtable__filter {
    width: min(280px, 50%);
  }

  /* Na desktopu bez vlastního scroll kontejneru (overflow visible), aby
     sticky hlavička držela vůči scroll oblasti tabu; tabulka se do šířky
     vejde (viz colStyle). Na mobilu se 9 sloupců řádků dokladu nevejde
     nikdy — tam obal posouvá vodorovně sám a padding tabu zůstane. */
  .shpd-form-subtable__table-wrap {
    width: 100%;
    overflow: visible;
  }

  @media (max-width: 768px) {
    .shpd-form-subtable__table-wrap {
      overflow-x: auto;
    }
  }

  /* border-collapse: separate — s collapse by bordery sticky hlavičky
     odscrollovaly s obsahem (stejný důvod jako ViewerGrid). Barva textu
     na tabulce, ne na td — globální .docState_archive / .docState_trash
     na <tr> ji přebíjí (tlumený archiv, škrtnutý koš). */
  .shpd-form-subtable__table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-form-subtable__th {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    background: var(--shpd-color-bg);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-form-subtable__td {
    padding: 4px var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    vertical-align: middle;
  }

  /* Číselné sloupce — zarovnání + tabular-nums, hlavička i buňky.
     width: 1% + nowrap = shrink-to-fit: sloupec je jen tak široký, jak
     nejdelší částka, zbytek šířky zůstane textovým sloupcům. */
  .shpd-form-subtable__th--num,
  .shpd-form-subtable__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    width: 1%;
  }

  .shpd-form-subtable__tr:hover > .shpd-form-subtable__td {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-form-subtable__actions {
    white-space: nowrap;
    text-align: right;
    padding-top: 0;
    padding-bottom: 0;
  }

  /* Styled span variants — stejný slovník jako ViewerGrid; `muted` navíc
     kurzívou (textový řádek dokladu). */
  .shpd-form-subtable__span--amount  { font-variant-numeric: tabular-nums; font-weight: 600; }
  .shpd-form-subtable__span--muted   { opacity: 0.7; font-style: italic; }
  .shpd-form-subtable__span--bold    { font-weight: 600; }
  .shpd-form-subtable__span--primary { color: var(--shpd-color-primary); }
  .shpd-form-subtable__span--success { color: var(--shpd-color-success); }
  .shpd-form-subtable__span--warning { color: var(--shpd-color-warning); }
  .shpd-form-subtable__span--danger  { color: var(--shpd-color-danger); }

  .shpd-form-subtable__td > span + span {
    margin-left: var(--shpd-space-xs);
  }
</style>
