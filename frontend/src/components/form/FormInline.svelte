<script>
  import Input from '../ui/Input.svelte';
  import Select from '../ui/Select.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import NumberInput from '../ui/NumberInput.svelte';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    id,
  } = $props();

  function innerId(_idx) {
    return `${id}-${_idx}`;
  }

  function handleChange(inner) {
    if (inner.triggers === 'reload' && inner.column) {
      onTrigger?.(inner.column);
    }
  }
</script>

<!-- Vstupní pole pro jeden inner element. bind:value cílí na outer-scope
     $bindable formData[inner.column] — funguje i uvnitř snippetu (snippet
     uzavírá komponentní scope). -->
{#snippet inputFor(inner, i)}
  {#if inner.type === 'select'}
    <Select
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      options={inner.options ?? []}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      placeholder={inner.placeholder}
      error={fieldErrors[inner.column] ?? null}
      onchange={() => handleChange(inner)}
    />
  {:else if inner.input_type === 'date'}
    <DateInput
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {:else if inner.input_type === 'number'}
    <NumberInput
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {:else}
    <Input
      id={i === 0 ? id : innerId(i)}
      type={inner.input_type ?? 'text'}
      bind:value={formData[inner.column]}
      placeholder={inner.placeholder}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {/if}
{/snippet}

<!-- Jedno DOM pro obě podoby (#157 D119), přepíná CSS podle šířky sekce
     (kontejner `form-section`, práh 30rem jako popisky nad pole):
     - široká sekce: popisek prvního pole ve stopě popisků, pole vedle sebe,
       další pole s malým popiskem před sebou; když se pole vedle sebe
       nevejdou (položka by měla pod 12rem), zalomí se na další řádek;
     - úzká sekce: pole pod sebou, každé se svým popiskem nad sebou.
     Každé pole má právě jeden <label for> — malý popisek se pod prahem jen
     přesune nad pole, nic se neskrývá ani nezdvojuje. -->
{#if !element.hidden}
  <label class="shpd-form-field-row__label" for={id}>
    {element.elements[0].label ?? ''}{#if element.elements[0].required}<span class="shpd-form-field-row__required">*</span>{/if}
  </label>
  <div class="shpd-form-inline">
    {#each element.elements as inner, i (inner.column ?? i)}
      <div class="shpd-form-inline__item">
        {#if i > 0}
          <label class="shpd-form-inline__mini-label" for={innerId(i)}>
            {inner.label ?? ''}{#if inner.required}<span class="shpd-form-field-row__required">*</span>{/if}
          </label>
        {/if}
        <div class="shpd-form-inline__field">
          {@render inputFor(inner, i)}
        </div>
      </div>
    {/each}
  </div>
{/if}

<style>
  /* Položky skupiny jsou stejně široké buňky mřížky. `auto-fit` s minimem
     12rem na položku (malý popisek + pole): dokud se všechny vejdou, stojí
     v jedné řadě; v užším sloupci (dvousloupcová sekce v úzkém modalu, tablet
     na výšku) se zalomí na další řádek místo toho, aby se pole smrskla na
     nulu a přetekla do sousedního sloupce. Zarovnání nahoru (#157 D118):
     pole s chybou nebo vyšší pole sousedy nenatahuje ani neposouvá. */
  .shpd-form-inline {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    align-items: start;
  }
  .shpd-form-inline__item {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: start;
    min-width: 0;
  }
  .shpd-form-inline__field {
    flex: 1 1 0;
    min-width: 0;
  }
  /*
   * Malé popisky u 2. a dalších polí skupiny. Vizuálně shodné s popiskem
   * řádku (.shpd-form-field-row__label) — stejná velikost, váha a barva —
   * a stejně jako on mají řádek na svislém středu pole.
   */
  .shpd-form-inline__mini-label {
    padding-top: calc((var(--shpd-control-height) - 1lh) / 2);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    white-space: nowrap;
  }

  /* Úzká sekce: skupina se rozpadne na pole pod sebou. Mezery jako u běžných
     polí (D117) — popisek–pole `xs`, mezi poli `md`; první pole se ke svému
     popisku ve stopě mřížky přitáhne stejným záporným okrajem jako
     FormFieldRow. */
  @container form-section (max-width: 30rem) {
    .shpd-form-inline {
      grid-template-columns: minmax(0, 1fr);
      gap: var(--shpd-space-md);
      margin-top: calc(var(--shpd-space-xs) - var(--shpd-space-md));
    }
    .shpd-form-inline__item {
      flex-direction: column;
      align-items: stretch;
      gap: var(--shpd-space-xs);
    }
    .shpd-form-inline__field {
      flex: none;
    }
    .shpd-form-inline__mini-label {
      padding-top: 0;
      white-space: normal;
      overflow-wrap: anywhere;
    }
  }
</style>
