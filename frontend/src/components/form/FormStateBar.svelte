<script>
  import Button from '../ui/Button.svelte';
  import KebabMenu from '../ui/KebabMenu.svelte';
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
  // Generické {key, label, danger, run} pro sdílený `ui/KebabMenu` (#155
  // D114) — co do kebabu patří, rozhoduje tato lišta.
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

  <!-- Bez položek (desktop, nic do kebabu) se nevykreslí. -->
  <KebabMenu items={kebabItems} disabled={saving} placement="top" />
</div>
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

  /* Kebab (sdílený `ui/KebabMenu`) vždy na pravém konci lišty — oddělený
     od akčních tlačítek. Na desktopu se nevykreslí. */
  .shpd-form-state-bar > :global(.shpd-kebab) {
    margin-left: auto;
  }
</style>
