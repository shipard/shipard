<script>
  import Button from '../ui/Button.svelte';
  import Popover from '../ui/Popover.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconMore } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';

  let {
    docStates = null,
    saving = false,
    onSave,
    onTransition,
    /** Formulář otevřený jen k prohlížení (řádek read-only dokladu ze
     *  sub-tabulky): bez Uložit i bez přechodů — z řádku se nesmí spouštět
     *  přechody sub-záznamu. Nezaměňovat s docStates.read_only, které
     *  přechody (Opravit…) naopak nechává. */
    readOnly = false,
    /** Záznam ještě nemá id (nový). */
    isNew = false,
    /** Nový sub-záznam: Uložit se jmenuje Přidat a vedle něj je Přidat
     *  a pokračovat (tento callback). Bez něj (top-level dialogy) zůstává
     *  Uložit jako dnes. */
    onSaveAndContinue = undefined,
  } = $props();

  // Read-only stav dokumentu schová Uložit — ledaže server povolil pár
  // sloupců editovatelných i tak (doc_states.editable_columns, např.
  // poznámka k podanému podání); pak se ukládají jen ony (FormEditor).
  const showSave = $derived(
    !readOnly
      && (!docStates || !docStates.read_only || (docStates.editable_columns?.length ?? 0) > 0),
  );
  const transitions = $derived(readOnly ? [] : (docStates?.transitions ?? []));
  const showContinue = $derived(showSave && isNew && typeof onSaveAndContinue === 'function');
  const saveLabel = $derived(showContinue ? t('form.add') : t('common.save'));

  const DESTRUCTIVE_STYLES = ['archive', 'trash', 'cancelled'];
  function isDestructive(stateStyle) {
    return DESTRUCTIVE_STYLES.includes(stateStyle);
  }

  // Přechody, které se na mobilu schovají do kebabu:
  //   - destruktivní (bezpečnost, méně omylů palcem) — v kebabu červeně,
  //   - 'concept' (návrat dokladu zpět na koncept — pomocná akce),
  //   - cokoli s příznakem `mobileKebab` z docStates (vedlejší akce, jejíž
  //     stateStyle nestačí — např. "Pozastavit" u úkolů má 'edit' stejně
  //     jako "Opravit" u faktur, které ale viditelné zůstává).
  // Nedestruktivní položky v kebabu zůstávají neutrální.
  const KEBAB_STYLES = [...DESTRUCTIVE_STYLES, 'concept'];
  function inKebab(tr) {
    return KEBAB_STYLES.includes(tr.stateStyle) || tr.mobileKebab === true;
  }

  function variantForStyle(stateStyle) {
    if (stateStyle === 'done') return 'primary';
    if (isDestructive(stateStyle)) return 'danger';
    return 'secondary';
  }

  // Mobil: postupové přechody (Potvrdit = confirmed, V pořádku = done,
  // Opravit = edit…) zůstanou viditelné vedle Uložit — je jich v daném
  // stavu max pár. Destruktivní + 'concept' jdou do kebabu. Na desktopu
  // se kebab nepoužije — renderují se všechny přechody vedle sebe.
  const visibleTransitions = $derived(
    layoutStore.isMobile
      ? transitions.filter(tr => !inKebab(tr))
      : transitions
  );
  // Přidat a pokračovat vedle Přidat na desktopu; na mobilu do kebabu
  // (vedlejší akce — hlavní je Přidat).
  const showContinueInline = $derived(showContinue && !layoutStore.isMobile);

  // Položky kebabu (jen mobil): Přidat a pokračovat + přechody dle inKebab.
  // Generické {key, label, danger, run}, aby kebab nebyl vázaný na přechody.
  const kebabItems = $derived.by(() => {
    if (!layoutStore.isMobile) return [];
    const items = [];
    if (showContinue) {
      items.push({
        key: 'continue',
        label: t('form.saveAndContinue'),
        danger: false,
        run: () => onSaveAndContinue?.(),
      });
    }
    for (const tr of transitions) {
      if (!inKebab(tr)) continue;
      items.push({
        key: `tr:${tr.state}`,
        label: tr.actionName,
        danger: variantForStyle(tr.stateStyle) === 'danger',
        run: () => onTransition?.(tr.state, tr.close_form ?? false),
      });
    }
    return items;
  });

  let kebabOpen = $state(false);
  let kebabAnchor = $state(null);

  function openKebab(e) {
    kebabAnchor = e.currentTarget;
    kebabOpen = !kebabOpen;
  }
  function closeKebab() { kebabOpen = false; }

  function runKebabItem(item) {
    closeKebab();
    item.run();
  }
</script>

<!-- Bez jediné akce se lišta nerenderuje (read-only prohlížení) — prázdný
     pruh s horní linkou by jen ubíral místo. -->
{#if showSave || visibleTransitions.length > 0 || kebabItems.length > 0}
<div class="shpd-form-state-bar">
  {#if showSave}
    <Button
      label={saveLabel}
      variant="primary"
      loading={saving}
      disabled={saving}
      onclick={onSave}
      testid="form-save"
    />
  {/if}

  {#if showContinueInline}
    <Button
      label={t('form.saveAndContinue')}
      variant="secondary"
      disabled={saving}
      onclick={() => onSaveAndContinue?.()}
      testid="form-save-continue"
    />
  {/if}

  {#each visibleTransitions as tr (tr.state)}
    <Button
      label={tr.actionName}
      variant={variantForStyle(tr.stateStyle)}
      disabled={saving}
      onclick={() => onTransition?.(tr.state, tr.close_form ?? false)}
    />
  {/each}

  {#if kebabItems.length > 0}
    <button
      type="button"
      class="shpd-form-state-bar__kebab-btn"
      onclick={openKebab}
      disabled={saving}
      aria-label={t('form.moreActions')}
    >
      <Icon icon={iconMore} size="md" />
    </button>
  {/if}
</div>
{/if}

{#if kebabOpen}
  <Popover open={true} anchor={kebabAnchor} placement="top" onClose={closeKebab}>
    <div class="shpd-form-state-bar__kebab-menu">
      {#each kebabItems as item (item.key)}
        <button
          type="button"
          class="shpd-form-state-bar__kebab-item"
          class:shpd-form-state-bar__kebab-item--danger={item.danger}
          onclick={() => runKebabItem(item)}
        >
          {item.label}
        </button>
      {/each}
    </div>
  </Popover>
{/if}

<style>
  .shpd-form-state-bar {
    display: flex;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
  }

  /* Mobilní kebab tlačítko (⋮) — schovává ne-done přechody. Stejný vzor
     jako kebab ve vieweru (fáze 2). Na desktopu se nerenderuje. */
  .shpd-form-state-bar__kebab-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    /* Vždy na pravý konec footeru — vizuálně oddělený od akčních tlačítek. */
    margin-left: auto;
    color: var(--shpd-color-text);
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: background-color 0.15s;
  }
  .shpd-form-state-bar__kebab-btn:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  .shpd-form-state-bar__kebab-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Kebab menu — položky v Popoveru (světlé pozadí). Stejný vzor jako
     kebab ve vieweru (fáze 2). */
  .shpd-form-state-bar__kebab-menu {
    display: flex;
    flex-direction: column;
    min-width: 180px;
    padding: var(--shpd-space-xs) 0;
  }
  .shpd-form-state-bar__kebab-item {
    text-align: left;
    padding: 0.625rem 0.875rem;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }
  .shpd-form-state-bar__kebab-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  /* Destruktivní přechody (Archivovat, Smazat, Ukončit platnost) červeně —
     uživatel si uvědomí závažnost i bez velkého tlačítka. */
  .shpd-form-state-bar__kebab-item--danger {
    color: var(--shpd-color-danger);
  }
</style>
