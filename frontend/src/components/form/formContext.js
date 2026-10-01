/**
 * Svelte context dat formuláře. `FormEditor` ho nastavuje pro své potomky:
 *
 *   { data: () => formData, parent: <context nadřazeného FormEditoru | null> }
 *
 * Prvky formuláře tak vidí živá (i neuložená) data svého formuláře a přes
 * `parent` data formuláře, ze kterého byl otevřen (řádek dokladu → hlavička),
 * bez protahování props přes FormDialog / FormSubTable. První konzument:
 * `LookupInput` — výchozí hodnoty nového záznamu z rodiče (`create_defaults`,
 * docs/edit-forms.md kap. 22).
 */
export const FORM_DATA_CONTEXT = Symbol('shpd-form-data');
