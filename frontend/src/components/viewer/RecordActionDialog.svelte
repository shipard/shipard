<script>
  /**
   * Obecný dialog akce záznamu s obsluhou v modulu (`kind: "record_action"`,
   * #172 D12–D14). Pole kreslí podle popisu akce z detailu
   * (`action.dialog.fields`), po odeslání volá jediný endpoint akcí
   * (`api/recordActions.js`) a chyby polí z 422 ukáže u polí; chyby bez
   * vazby na pole nad nimi.
   *
   * Hodnoty drží jen lokálně a při každém otevření i zavření je zahodí —
   * heslo ani vybraný soubor nepřežijí mezi akcemi. Po dobu volání je
   * odeslání zablokované a dialog nejde zavřít (Esc, ×, Zrušit).
   *
   * Výsledek hlásí rodiči: `onDone(data)` po úspěchu (`{message, level}`),
   * `onFailed(result)` u chyby mimo validaci (rodič ji ohlásí a rozhodne,
   * jestli dialog zavře).
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import Notice from '../ui/Notice.svelte';
  import { t } from '../../i18n/index.js';
  import { runRecordAction } from '../../api/recordActions.js';
  import {
    clientErrors, dialogFields, errorText, initialValues, isInvalid, requestBody, responseErrors,
  } from './recordActionRequest.js';

  let {
    open = false,
    /** Popis akce z `detail.actions` (id, label, variant, dialog, target). */
    action = null,
    onDone = () => {},
    onFailed = () => {},
    onClose = () => {},
  } = $props();

  let values = $state({});
  let files = $state({});
  let errors = $state({});
  let formErrors = $state([]);
  let submitting = $state(false);

  let dialog = $derived(action?.dialog ?? null);
  let fields = $derived(dialogFields(action));

  const translateCode = (code) => t(`recordAction.fieldError.${code}`);

  function reset() {
    values = {};
    files = {};
    errors = {};
    formErrors = [];
  }

  // Čisté hodnoty při každém otevření (výchozí hodnoty, "now" = teď)
  // a zahození při zavření — heslo ani soubor v paměti nezůstávají.
  // Efekt běží až po prvním vykreslení polí, proto mají vazby níže getter
  // s výchozí hodnotou (bind na `undefined` Svelte 5 odmítá).
  $effect(() => {
    if (open && action) {
      reset();
      values = initialValues(dialogFields(action));
    } else {
      reset();
    }
  });

  function handleClose() {
    if (submitting) return;
    onClose();
  }

  function pickFile(field, event) {
    const file = event.currentTarget.files?.[0] ?? null;
    files = { ...files, [field.id]: file };
    // Velikost hned po výběru — server kontroluje znovu.
    const { [field.id]: _removed, ...rest } = errors;
    const sizeError = clientErrors([field], {}, { [field.id]: file })[field.id];
    errors = sizeError === 'file_too_large' ? { ...rest, [field.id]: translateCode(sizeError) } : rest;
  }

  async function submit() {
    if (submitting || !action) return;

    formErrors = [];
    const found = clientErrors(fields, values, files);
    if (Object.keys(found).length > 0) {
      errors = Object.fromEntries(
        Object.entries(found).map(([id, code]) => [id, errorText(code, translateCode) ?? code]),
      );
      return;
    }
    errors = {};

    submitting = true;
    let result;
    try {
      result = await runRecordAction(action.target, action.id, requestBody(fields, values, files));
    } finally {
      submitting = false;
    }

    if (result?.success) {
      onDone(result.data);
      return;
    }
    if (isInvalid(result)) {
      const parsed = responseErrors(result, fields, translateCode);
      errors = parsed.fields;
      formErrors = parsed.form;
      return;
    }
    onFailed(result);
  }

  function submitOnEnter(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      submit();
    }
  }
</script>

<Modal
  title={dialog?.title ?? action?.label ?? ''}
  open={open && action !== null}
  onClose={handleClose}
  width="32.5rem"
  testid="record-action-dialog"
>
  <div class="shpd-record-action">
    {#if dialog?.intro}
      <p class="shpd-record-action__intro">{dialog.intro}</p>
    {/if}

    {#if formErrors.length > 0}
      <Notice tone="danger" items={formErrors} data-testid="record-action-form-errors" />
    {/if}

    {#each fields as field (field.id)}
      {@const inputId = `shpd-record-action-${field.id}`}
      {@const error = errors[field.id] ?? null}
      <div class="shpd-record-action__field" data-testid={`record-action-field-${field.id}`}>
        {#if field.type === 'notice'}
          <Notice tone="info">{field.text}</Notice>
        {:else if field.type === 'checkbox'}
          <Checkbox label={field.label} bind:checked={() => values[field.id] === true, (v) => { values[field.id] = v; }} disabled={submitting} />
        {:else}
          <label class="shpd-record-action__label" for={inputId}>
            {field.label}{#if field.required}<span class="shpd-record-action__required" aria-hidden="true"> *</span>{/if}
          </label>
          {#if field.type === 'textarea'}
            <TextArea id={inputId} bind:value={() => values[field.id] ?? '', (v) => { values[field.id] = v; }} required={field.required ?? false} disabled={submitting} {error} />
          {:else if field.type === 'password'}
            <Input
              id={inputId}
              type="password"
              autocomplete="new-password"
              bind:value={() => values[field.id] ?? '', (v) => { values[field.id] = v; }}
              required={field.required ?? false}
              disabled={submitting}
              {error}
              onkeydown={submitOnEnter}
            />
          {:else if field.type === 'number'}
            <NumberInput
              id={inputId}
              bind:value={() => values[field.id] ?? null, (v) => { values[field.id] = v; }}
              required={field.required ?? false}
              disabled={submitting}
              min={field.min}
              max={field.max}
              step={field.step}
              {error}
            />
          {:else if field.type === 'date'}
            <DateInput id={inputId} bind:value={() => values[field.id] ?? '', (v) => { values[field.id] = v; }} required={field.required ?? false} disabled={submitting} {error} />
          {:else if field.type === 'datetime'}
            <Input
              id={inputId}
              type="datetime-local"
              bind:value={() => values[field.id] ?? '', (v) => { values[field.id] = v; }}
              required={field.required ?? false}
              disabled={submitting}
              {error}
            />
          {:else if field.type === 'select'}
            <Select
              id={inputId}
              bind:value={() => values[field.id] ?? null, (v) => { values[field.id] = v; }}
              options={field.options ?? []}
              required={field.required ?? false}
              disabled={submitting}
              {error}
            />
          {:else if field.type === 'file'}
            <input
              id={inputId}
              class="shpd-record-action__file"
              class:shpd-record-action__file--error={!!error}
              type="file"
              accept={field.accept ?? undefined}
              disabled={submitting}
              onchange={(event) => pickFile(field, event)}
            />
            {#if error}
              <span class="shpd-record-action__error">{error}</span>
            {/if}
          {:else}
            <Input
              id={inputId}
              bind:value={() => values[field.id] ?? '', (v) => { values[field.id] = v; }}
              required={field.required ?? false}
              disabled={submitting}
              {error}
              onkeydown={submitOnEnter}
            />
          {/if}
        {/if}
        {#if field.hint}
          <span class="shpd-record-action__hint">{field.hint}</span>
        {/if}
      </div>
    {/each}
  </div>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" dismiss disabled={submitting} onclick={handleClose} />
    <Button
      label={submitting ? t('recordAction.running') : (dialog?.submitLabel ?? t('recordAction.submit'))}
      variant={action?.variant === 'danger' ? 'danger' : 'primary'}
      disabled={submitting}
      onclick={submit}
      testid="record-action-submit"
    />
  {/snippet}
</Modal>

<style>
  .shpd-record-action {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-record-action__intro {
    margin: 0;
    color: var(--shpd-color-text);
  }

  .shpd-record-action__field {
    display: flex;
    flex-direction: column;
  }

  .shpd-record-action__label {
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-record-action__required {
    color: var(--shpd-color-danger-text);
  }

  .shpd-record-action__hint {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  /* Nativní pole souboru: písmo a rámeček z tokenů ovládacího prvku. */
  .shpd-record-action__file {
    width: 100%;
    box-sizing: border-box;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-field);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
  }

  .shpd-record-action__file--error {
    border-color: var(--shpd-color-danger-text);
  }

  .shpd-record-action__error {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
  }
</style>
