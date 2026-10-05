<script>
  // Settings page — server-driven stránka vlastností (nav typ `page`).
  // Načte definici + hodnoty z GET /_ui/settings/page/{pageId}, textová pole
  // edituje lokálně a ukládá POST-em, image pole spravuje ImageSlotField
  // (upload/delete jde rovnou na /_app/branding, mimo Uložit).
  import { get, post } from '../../api/client.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Input from '../ui/Input.svelte';
  import Select from '../ui/Select.svelte';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import ImageSlotField from './ImageSlotField.svelte';
  import AvatarSlotField from './AvatarSlotField.svelte';
  import ThemeField from './ThemeField.svelte';
  import DsThemeField from './DsThemeField.svelte';
  import ShellField from './ShellField.svelte';
  import DsShellField from './DsShellField.svelte';
  import LanguageField from './LanguageField.svelte';
  import ColorField from './ColorField.svelte';
  import { iconSave, iconSpinner, resolveIcon } from '../../icons.js';

  // onOpenThemePanel probublává z AppShellu (přes ContentArea) — ThemeField
  // ho volá při volbě „Vlastní" / „Upravit barvu".
  let { tab, onOpenThemePanel } = $props();

  let definition  = $state(null);
  let values      = $state({});      // textová pole — editovaný stav
  let imageStates = $state({});      // image pole — stav slotů (read-only, mění ImageSlotField)
  let loading     = $state(true);
  let loadError   = $state(null);

  let saving      = $state(false);
  let saveMessage = $state(null);    // { type: 'success'|'error', text }
  let fieldErrors = $state({});      // field id → message

  $effect(() => {
    const pageId = tab?.pageId;
    if (!pageId) return;

    loading = true;
    loadError = null;
    saveMessage = null;
    fieldErrors = {};

    (async () => {
      try {
        const response = await get(`/_ui/settings/page/${encodeURIComponent(pageId)}`);
        if (!response?.success) {
          loadError = response?.error ? translateError(response.error) : t('settingsPage.loadFailed');
          return;
        }
        definition = response.data.definition;
        splitValues(response.data.values);
      } catch {
        loadError = t('settingsPage.loadFailed');
      } finally {
        loading = false;
      }
    })();
  });

  // Pole ukládaná přes Uložit stránky: texty, select, color + DS-scope theme/shell
  // (structured hodnoty v `values`). User-scope theme/shell/language jedou
  // live stores, image/avatar vlastní upload endpoint — mimo Uložit.
  function isSavedViaPage(field, scope) {
    return field.type === 'text'
      || field.type === 'select'
      || field.type === 'color'
      || ((field.type === 'theme' || field.type === 'shell') && scope === 'ds');
  }

  // Server vrací jednu mapu values — rozdělíme na editovatelné texty
  // (string | null → '') a stavy image slotů.
  //   user-scope theme/shell/language → řízená přímo stores (live), do
  //     `values` nepatří (mění se okamžitě, ne přes Uložit)
  //   ds-scope theme/shell (app.theme/app.shell) → ukládá se přes Uložit
  //     jako hodnota, takže do `values` patří (controlled Ds*Field)
  function splitValues(serverValues) {
    const texts = {};
    const images = {};
    const scope = definition?.scope ?? 'ds';
    for (const field of definition?.fields ?? []) {
      if (field.type === 'image') {
        images[field.id] = serverValues[field.id] ?? null;
      } else if (field.type === 'text') {
        texts[field.id] = serverValues[field.id] ?? '';
      } else if (field.type === 'select' || field.type === 'color') {
        // Nezvolené = null → server klíč smaže, čtenáři použijí výchozí.
        texts[field.id] = serverValues[field.id] ?? null;
      } else if (isSavedViaPage(field, scope)) {
        // DS default — structured objekt ({mode, custom} / {shell, params})
        // nebo null.
        texts[field.id] = serverValues[field.id] ?? null;
      }
    }
    values = texts;
    imageStates = images;
  }

  async function handleSave() {
    if (saving || !definition) return;
    saving = true;
    saveMessage = null;
    fieldErrors = {};

    try {
      const response = await post(`/_ui/settings/page/${encodeURIComponent(tab.pageId)}`, { values });
      if (response?.success) {
        splitValues(response.data.values);
        saveMessage = { type: 'success', text: t('settingsPage.saved') };
        // Název/zkrácený název se propisují do titulku, sidebaru a loginu.
        appInfoStore.load();
      } else {
        const details = response?.error?.details ?? [];
        for (const d of details) {
          if (d.field) fieldErrors[d.field] = d.message;
        }
        saveMessage = {
          type: 'error',
          text: response?.error ? translateError(response.error) : t('settingsPage.saveFailed'),
        };
      }
    } catch {
      saveMessage = { type: 'error', text: t('settingsPage.saveFailed') };
    } finally {
      saving = false;
    }
  }

  // ImageSlotField hlásí změnu slotu — refresh metadat + globálního app infa.
  function handleImageChange(fieldId, newState) {
    imageStates = { ...imageStates, [fieldId]: newState };
    appInfoStore.load();
  }

  // Uložit tlačítko jen pro stránky s poli ukládanými přes savePage —
  // account Basic (live stores) tlačítko nemá.
  let hasSavableFields = $derived((definition?.fields ?? []).some(f =>
    isSavedViaPage(f, definition?.scope ?? 'ds')
  ));
</script>

<div class="shpd-settings-page">
  {#if loading}
    <div class="shpd-settings-page__status">
      <Icon icon={iconSpinner} spin size="sm" />
      <span>{t('common.loading')}</span>
    </div>
  {:else if loadError}
    <div class="shpd-settings-page__status shpd-settings-page__status--error">{loadError}</div>
  {:else if definition}
    <div class="shpd-settings-page__card">
      <h2 class="shpd-settings-page__title">
        {#if definition.icon}
          <Icon icon={resolveIcon(definition.icon)} size="md" />
        {/if}
        <span>{definition.label}</span>
      </h2>

      {#if saveMessage}
        <div
          class="shpd-settings-page__message"
          class:shpd-settings-page__message--success={saveMessage.type === 'success'}
          class:shpd-settings-page__message--error={saveMessage.type === 'error'}
        >
          {saveMessage.text}
        </div>
      {/if}

      <div class="shpd-settings-page__fields">
        {#each definition.fields as field (field.id)}
          <div class="shpd-settings-page__field">
            <label class="shpd-settings-page__label" for={`settings-${field.id}`}>{field.label}</label>
            <div class="shpd-settings-page__input">
              {#if field.type === 'image'}
                <ImageSlotField
                  slotId={field.slot}
                  slotState={imageStates[field.id]}
                  onchange={(newState) => handleImageChange(field.id, newState)}
                />
              {:else if field.type === 'avatar'}
                <AvatarSlotField />
              {:else if field.type === 'theme'}
                {#if (definition.scope ?? 'ds') === 'user'}
                  <ThemeField {onOpenThemePanel} />
                {:else}
                  <DsThemeField
                    value={values[field.id]}
                    onchange={(v) => { values[field.id] = v; }}
                  />
                {/if}
              {:else if field.type === 'shell'}
                {#if (definition.scope ?? 'ds') === 'user'}
                  <ShellField />
                {:else}
                  <DsShellField
                    value={values[field.id]}
                    onchange={(v) => { values[field.id] = v; }}
                  />
                {/if}
              {:else if field.type === 'language'}
                <LanguageField />
              {:else if field.type === 'color'}
                <ColorField
                  id={`settings-${field.id}`}
                  value={values[field.id]}
                  defaultColor={field.default}
                  error={fieldErrors[field.id] ?? null}
                  disabled={saving}
                  onchange={(v) => { values[field.id] = v; }}
                />
              {:else if field.type === 'select'}
                <Select
                  id={`settings-${field.id}`}
                  bind:value={values[field.id]}
                  options={field.options ?? []}
                  error={fieldErrors[field.id] ?? null}
                  disabled={saving}
                />
              {:else}
                <Input
                  id={`settings-${field.id}`}
                  bind:value={values[field.id]}
                  maxlength={field.maxLength}
                  error={fieldErrors[field.id] ?? null}
                  disabled={saving}
                />
              {/if}
              {#if field.hint}
                <p class="shpd-settings-page__hint">{field.hint}</p>
              {/if}
            </div>
          </div>
        {/each}
      </div>

      {#if hasSavableFields}
        <div class="shpd-settings-page__actions">
          <Button
            label={t('settingsPage.save')}
            icon={iconSave}
            loading={saving}
            onclick={handleSave}
          />
        </div>
      {/if}
    </div>
  {/if}
</div>

<style>
  .shpd-settings-page {
    padding: var(--shpd-space-lg);
    max-width: 760px;
  }

  .shpd-settings-page__card {
    padding: var(--shpd-space-lg);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
  }

  .shpd-settings-page__title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-settings-page__message {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-settings-page__message--success {
    color: var(--shpd-color-success, #16a34a);
    background-color: var(--shpd-color-success-soft, rgba(22, 163, 74, 0.1));
    border: 1px solid var(--shpd-color-success, #16a34a);
  }

  .shpd-settings-page__message--error {
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
  }

  /* Label vlevo (zarovnaný doprava), input vpravo — stejný vzor jako
     FormFieldRow, ale s vlastním gridem (settings page nejede přes
     FormEditor). */
  .shpd-settings-page__fields {
    display: grid;
    grid-template-columns: max-content 1fr;
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-md);
    align-items: start;
  }

  .shpd-settings-page__field {
    display: grid;
    grid-template-columns: subgrid;
    grid-column: 1 / -1;
    align-items: start;
  }

  .shpd-settings-page__label {
    padding-top: calc(var(--shpd-input-padding-y) + 1px);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-align: right;
    white-space: nowrap;
  }

  .shpd-settings-page__input {
    min-width: 0;
  }

  .shpd-settings-page__hint {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-settings-page__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: var(--shpd-space-lg);
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-settings-page__status {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-settings-page__status--error {
    color: var(--shpd-color-danger);
  }

  @media (max-width: 640px) {
    .shpd-settings-page__fields,
    .shpd-settings-page__field {
      grid-template-columns: 1fr;
    }

    .shpd-settings-page__label {
      padding-top: 0;
      text-align: left;
    }
  }
</style>
