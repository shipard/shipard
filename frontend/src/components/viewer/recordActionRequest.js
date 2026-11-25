/**
 * Akce záznamu s obsluhou v modulu (`kind: "record_action"`, #172 D12–D14):
 * čisté funkce nad popisem akce z detailu a nad obálkou `{success, data,
 * error}` z api/client.js, aby šel dialog i obsluha ve Viewer.svelte
 * otestovat bez komponenty.
 *
 * Pole dialogu: `{id, type, label, required?, default?, hint?}` + podle typu
 * `options`, `min` / `max` / `step`, `accept` / `maxBytes`; `notice` je
 * `{id, type, text}` bez hodnoty.
 */

/** Výchozí hodnota `date` / `datetime`: okamžik otevření dialogu. */
export const NOW = 'now';

/** Kód odpovědi s chybami polí (422). */
export const INVALID = 'RECORD_ACTION_INVALID';

/** Chyba bez vazby na pole (`details[].field`). */
export const FORM_FIELD = '_form';

/** Kódy obecné validace polí — frontend je překládá (`recordAction.fieldError.<kód>`). */
export const FIELD_ERROR_CODES = [
  'required', 'invalid_value', 'invalid_number', 'out_of_range',
  'invalid_date', 'not_in_options', 'file_too_large', 'file_upload_failed',
];

const pad = (n) => String(n).padStart(2, '0');

/** Místní datum `YYYY-MM-DD` (hodnota `<input type="date">`). */
export function localDate(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Místní datum a čas `YYYY-MM-DDTHH:MM` (hodnota `<input type="datetime-local">`). */
export function localDateTime(date) {
  return `${localDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Pole dialogu akce; akce bez dialogu žádná nemá. */
export function dialogFields(action) {
  return Array.isArray(action?.dialog?.fields) ? action.dialog.fields : [];
}

/** Nese pole hodnotu, kterou drží stav dialogu? `notice` a `file` ne. */
function hasValue(field) {
  return field.type !== 'notice' && field.type !== 'file';
}

/**
 * Hodnoty polí při otevření dialogu: výchozí hodnota z deklarace, `"now"`
 * u data a času = teď (místní čas prohlížeče), jinak prázdné. Soubor
 * a `notice` tu nejsou.
 * @param {Array} fields
 * @param {Date} [now]
 * @returns {Record<string, any>}
 */
export function initialValues(fields, now = new Date()) {
  const values = {};
  for (const field of fields) {
    if (!hasValue(field)) continue;
    const def = field.default;
    switch (field.type) {
      case 'checkbox':
        values[field.id] = def === true;
        break;
      case 'number':
        values[field.id] = typeof def === 'number' ? def : null;
        break;
      case 'select':
        values[field.id] = def ?? null;
        break;
      case 'date':
        values[field.id] = def === NOW ? localDate(now) : (def ?? '');
        break;
      case 'datetime':
        values[field.id] = def === NOW ? localDateTime(now) : (def ?? '');
        break;
      case 'password':
        values[field.id] = '';
        break;
      default:
        values[field.id] = typeof def === 'string' ? def : '';
    }
  }
  return values;
}

/** Je hodnota pole nevyplněná? Text jen z mezer se počítá jako prázdný, heslo ne. */
function isEmpty(field, value) {
  if (value === null || value === undefined) return true;
  if (typeof value !== 'string') return false;
  return field.type === 'password' ? value === '' : value.trim() === '';
}

/**
 * Kontrola v prohlížeči před odesláním — povinná pole a velikost souboru
 * (server kontroluje znovu a úplně). Vrací `{<id pole>: <kód>}`.
 * @param {Array} fields
 * @param {Record<string, any>} values
 * @param {Record<string, {size: number}|null|undefined>} files
 */
export function clientErrors(fields, values, files) {
  const errors = {};
  for (const field of fields) {
    if (field.type === 'notice') continue;
    if (field.type === 'file') {
      const file = files?.[field.id] ?? null;
      if (!file) {
        if (field.required) errors[field.id] = 'required';
      } else if (typeof field.maxBytes === 'number' && file.size > field.maxBytes) {
        errors[field.id] = 'file_too_large';
      }
      continue;
    }
    if (field.required && field.type !== 'checkbox' && isEmpty(field, values?.[field.id])) {
      errors[field.id] = 'required';
    }
  }
  return errors;
}

/**
 * Hodnoty pro server: jen pole dialogu s hodnotou, nevyplněné jako `null`.
 * @param {Array} fields
 * @param {Record<string, any>} values
 */
export function requestValues(fields, values) {
  const out = {};
  for (const field of fields) {
    if (!hasValue(field)) continue;
    const value = values?.[field.id];
    if (field.type === 'checkbox') {
      out[field.id] = value === true;
    } else {
      out[field.id] = isEmpty(field, value) ? null : value;
    }
  }
  return out;
}

/**
 * Tělo požadavku: JSON `{values}`, se souborem `FormData` s částí `values`
 * (JSON) a soubory `files[<id pole>]`.
 * @param {Array} fields
 * @param {Record<string, any>} values
 * @param {Record<string, any>} [files] `{<id pole>: File}`
 * @param {typeof FormData} [FormDataCtor] konstruktor (testy)
 * @returns {{values: object}|FormData}
 */
export function requestBody(fields, values, files = {}, FormDataCtor = globalThis.FormData) {
  const body = { values: requestValues(fields, values) };
  const uploads = fields
    .filter((field) => field.type === 'file' && files?.[field.id])
    .map((field) => [field.id, files[field.id]]);
  if (uploads.length === 0) return body;

  const form = new FormDataCtor();
  form.append('values', JSON.stringify(body.values));
  for (const [id, file] of uploads) {
    form.append(`files[${id}]`, file);
  }
  return form;
}

/** Je odpověď chybou polí (422 `RECORD_ACTION_INVALID`)? */
export function isInvalid(result) {
  return result?.success === false && result?.error?.code === INVALID;
}

/**
 * Chyby polí z odpovědi 422 rozdělené pro dialog: `fields` = `{<id pole>:
 * text}` u polí dialogu, `form` = texty bez vazby na pole (`_form` a pole,
 * které dialog nemá). Text obecné chyby dává `translateCode(kód)`, chyba
 * z akce (`code: "invalid"`) a neznámý kód nesou text ze serveru.
 * @param {object} result obálka z api/client.js
 * @param {Array} fields pole dialogu
 * @param {(code: string) => string|null} translateCode
 * @returns {{fields: Record<string, string>, form: string[]}}
 */
export function responseErrors(result, fields, translateCode) {
  const known = new Set(fields.filter((field) => field.type !== 'notice').map((field) => field.id));
  const out = { fields: {}, form: [] };
  const details = Array.isArray(result?.error?.details) ? result.error.details : [];
  for (const detail of details) {
    const text = errorText(detail?.code, translateCode) ?? detail?.message;
    if (typeof text !== 'string' || text === '') continue;
    if (typeof detail.field === 'string' && known.has(detail.field)) {
      out.fields[detail.field] ??= text;
    } else {
      out.form.push(text);
    }
  }
  return out;
}

/** Přeložený text kódu obecné chyby pole, jinak null. */
export function errorText(code, translateCode) {
  return FIELD_ERROR_CODES.includes(code) ? (translateCode(code) ?? null) : null;
}

/** Úroveň oznámení výsledku; neznámá hodnota = `success`. */
export function resultLevel(data) {
  return ['success', 'info', 'warning'].includes(data?.level) ? data.level : 'success';
}
