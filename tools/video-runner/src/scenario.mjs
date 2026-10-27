/**
 * Načtení a validace scénáře.
 *
 * Validuje se přísně a hláška vždycky říká **číslo kroku** — scénář je
 * datový soubor psaný ručně a nejčastější chyba je překlep v názvu verbu,
 * který by se jinak projevil až tím, že se krok tiše nic neudělá.
 */

import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

import { UserError } from './errors.mjs';
import { parseJsonc } from './jsonc.mjs';
import { TESTID_SELECTOR_RE } from './selectors.mjs';

/** Verby scénáře podporované ve spike. Klíč kroku, který je jedním z nich, určuje akci. */
export const VERBS = ['goto', 'waitFor', 'hover', 'click', 'scroll', 'caption', 'highlight', 'pause'];

/** Klíče, které akci neurčují, jen ji upravují. */
const MODIFIERS = ['pause', 'travel', 'for', 'over'];

/** Verby pracující se selektorem. */
export const SELECTOR_VERBS = ['waitFor', 'hover', 'click', 'highlight'];

const DEFAULT_CAPTURE = { w: 2560, h: 1600, scale: 2 };

/**
 * Výstupní rozlišení se **nezmenšuje**, dokud si to scénář nevyžádá.
 *
 * Zmenšit na 1280×800 znamenalo zahodit přesně ten detail, kvůli kterému se
 * nahrává na dvojnásobnou hustotu: video zobrazené na stránce v šířce
 * 1280 CSS px vidí každý návštěvník s retina displejem jako dvojnásobný
 * upscale. Zmenšit se dá vždycky, zpátky se to nedodělá.
 */
const DEFAULT_OUTPUT_FPS = 30;

/**
 * Jazyk a časová zóna prohlížeče.
 *
 * Není to detail: aplikace si jazyk odvozuje z `navigator.language`
 * (`frontend/src/stores/language.svelte.js`) a Playwright startuje
 * v `en-US`. Bez připnutí vyjde celé video v angličtině — a pozná se to
 * až na hotovém klipu, protože scénář ani runner o jazyku nic nevědí.
 */
const DEFAULT_LOCALE = 'cs-CZ';
const DEFAULT_TIMEZONE = 'Europe/Prague';

/**
 * Datová sada a čas dne — pole scénáře, která **runner sám nečte**.
 *
 * `dataset` (povinné) říká, nad kterou sadou z `demo/datasets/` má scénář
 * běžet; `clockTime` (volitelné, `HH:MM`) je čas dne v časové zóně scénáře,
 * na kterém má v prohlížeči stát `Date` (D120). Obojí čte `shpd-ds
 * video-run`: sadu naseeduje a čas předá runneru jako `VIDEO_FIXED_TIME`.
 * Validátor je tu proto, aby překlep spadl už při `check`, ne až v úloze
 * dashboardu; existenci sady ověřuje `video-run`.
 */
const DATASET_ID_RE = /^[a-z0-9][a-z0-9-]*$/;
const CLOCK_TIME_RE = /^\d{2}:\d{2}$/;
export const DEFAULT_CLOCK_TIME = '09:30';

/**
 * Výchozí doba přejezdu kurzoru v sekundách. Do `pause` se nepočítá (D4).
 *
 * Jednotka je sekunda jako u všech ostatních časů ve scénáři, i když se
 * o ní v #48 mluví jako o „600 ms" — dvě jednotky v jednom souboru jsou
 * spolehlivý zdroj překlepů typu `"travel": 1` v domnění, že jde o vteřinu.
 */
export const DEFAULT_TRAVEL_S = 0.6;

/** Výchozí doba zobrazení rámečku `highlight` v sekundách. */
export const DEFAULT_HIGHLIGHT_S = 1.5;

/**
 * Výchozí doba scrollování v sekundách. Stejně jako `travel` se do `pause`
 * nepočítá — je to vlastnost akce, ne vyprávění.
 */
export const DEFAULT_SCROLL_S = 1.2;

function fail(message, hint) {
  throw new UserError(message, hint);
}

/** Posun scrollu smí být i negativní (nahoru), nesmí být nulový. */
function nonZeroNumber(value, label) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value === 0) {
    fail(`${label} musí být nenulové číslo, je ${JSON.stringify(value)}.`);
  }
  return value;
}

function positiveNumber(value, label) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
    fail(`${label} musí být kladné číslo, je ${JSON.stringify(value)}.`);
  }
  return value;
}

function readString(raw, label, fallback) {
  if (raw === undefined) return fallback;
  if (typeof raw !== 'string' || raw.trim() === '') {
    fail(`${label} musí být neprázdný řetězec, je ${JSON.stringify(raw)}.`);
  }
  return raw;
}

function readDataset(raw) {
  if (typeof raw !== 'string' || !DATASET_ID_RE.test(raw)) {
    fail(
      'Scénář musí mít dataset — název datové sady z malých písmen, číslic a pomlček.',
      'Název odpovídá složce v demo/datasets/ (např. "it-servis"); existenci ověřuje shpd-ds video-run.',
    );
  }
  return raw;
}

function readClockTime(raw) {
  if (raw === undefined) return DEFAULT_CLOCK_TIME;
  if (typeof raw !== 'string' || !CLOCK_TIME_RE.test(raw)) {
    fail(
      `clockTime musí být čas dne ve tvaru HH:MM, je ${JSON.stringify(raw)}.`,
      `Výchozí je ${DEFAULT_CLOCK_TIME}; platí v časové zóně scénáře (timezone).`,
    );
  }
  const [hours, minutes] = raw.split(':').map(Number);
  if (hours > 23 || minutes > 59) {
    fail(`clockTime ${raw} není platný čas dne (00:00–23:59).`);
  }
  return raw;
}

function readBlock(raw, label, defaults) {
  if (raw === undefined) return { ...defaults };
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    fail(`Sekce ${label} musí být objekt.`);
  }
  const merged = { ...defaults, ...raw };
  for (const key of Object.keys(defaults)) {
    positiveNumber(merged[key], `${label}.${key}`);
  }
  for (const key of Object.keys(raw)) {
    if (!(key in defaults)) fail(`Neznámý klíč ${label}.${key}.`);
  }
  return merged;
}

/**
 * @param {Record<string, unknown>} step
 * @param {number} index Nulový index v poli kroků; do hlášek jde o jedničku vyšší.
 */
function validateStep(step, index) {
  const at = `krok #${index + 1}`;

  if (typeof step !== 'object' || step === null || Array.isArray(step)) {
    fail(`${at}: musí být objekt, je ${JSON.stringify(step)}.`);
  }

  const keys = Object.keys(step);
  const unknown = keys.filter((k) => !VERBS.includes(k) && !MODIFIERS.includes(k));
  if (unknown.length > 0) {
    fail(
      `${at}: neznámý klíč ${unknown.join(', ')}.`,
      `Verby: ${VERBS.join(', ')}. Modifikátory: ${MODIFIERS.join(', ')}.`,
    );
  }

  // `pause` je zároveň verb i modifikátor — samotné `{ "pause": 2 }` je
  // legitimní krok, ale jakmile je ve kroku jiný verb, je to modifikátor.
  const verbs = keys.filter((k) => VERBS.includes(k) && k !== 'pause');
  if (verbs.length > 1) {
    fail(`${at}: víc verbů najednou (${verbs.join(', ')}), krok smí mít jeden.`);
  }

  const verb = verbs[0] ?? (Object.hasOwn(step, 'pause') ? 'pause' : null);
  if (!verb) fail(`${at}: krok neobsahuje žádný verb.`);

  if (Object.hasOwn(step, 'pause') && verb !== 'pause') {
    positiveNumber(step.pause, `${at} → pause`);
  }
  if (verb === 'pause') positiveNumber(step.pause, `${at} → pause`);

  if (Object.hasOwn(step, 'travel')) {
    positiveNumber(step.travel, `${at} → travel`);
    if (verb !== 'hover' && verb !== 'click') {
      fail(`${at}: travel dává smysl jen u hover a click, ne u ${verb}.`);
    }
  }

  if (Object.hasOwn(step, 'for')) {
    positiveNumber(step.for, `${at} → for`);
    if (verb !== 'highlight') fail(`${at}: for dává smysl jen u highlight, ne u ${verb}.`);
  }

  if (Object.hasOwn(step, 'over')) {
    positiveNumber(step.over, `${at} → over`);
    if (verb !== 'scroll') fail(`${at}: over dává smysl jen u scroll, ne u ${verb}.`);
  }

  if (verb === 'scroll') {
    nonZeroNumber(step.scroll, `${at} → scroll`);
  }

  if (SELECTOR_VERBS.includes(verb)) {
    if (typeof step[verb] !== 'string' || step[verb].trim() === '') {
      fail(`${at}: ${verb} chce neprázdný selektor.`);
    }
    // Zkratka `@` je jen testid, žádné CSS za ním — překlep se má poznat
    // při `check`, ne až uprostřed natáčení.
    if (step[verb].startsWith('@') && !TESTID_SELECTOR_RE.test(step[verb])) {
      fail(
        `${at}: ${verb} má neplatný @ selektor ${JSON.stringify(step[verb])}.`,
        '@name znamená [data-testid="name"]; povolené znaky jsou A-Z a-z 0-9 _ . : -',
      );
    }
  }

  if (verb === 'goto') {
    if (typeof step.goto !== 'string' || !step.goto.startsWith('/')) {
      fail(
        `${at}: goto chce cestu začínající lomítkem, je ${JSON.stringify(step.goto)}.`,
        'Adresa instance patří do SHPD_BASE_URL, ne do scénáře.',
      );
    }
  }

  if (verb === 'caption') {
    if (step.caption !== null && typeof step.caption !== 'string') {
      fail(`${at}: caption chce text nebo null (sundání titulku).`);
    }
  }

  return { ...step, verb, index };
}

/**
 * @typedef {object} Scenario
 * @property {string} id
 * @property {string} path
 * @property {{w:number,h:number,scale:number}} capture Rozměry rawu v pixelech.
 * @property {{w:number,h:number,fps:number}} output
 * @property {{width:number,height:number}} viewport CSS viewport odvozený z capture.
 * @property {string} locale Jazyk prohlížeče — určuje jazyk aplikace ve videu.
 * @property {string} timezone Časová zóna prohlížeče.
 * @property {string} dataset Název datové sady v `demo/datasets/`. Runner ho
 *   nepoužívá — čte ho `shpd-ds video-run`.
 * @property {string} clockTime Čas dne `HH:MM` v zóně scénáře, výchozí 09:30.
 *   Runner ho nepoužívá — `video-run` z něj a z kotvy sady skládá `VIDEO_FIXED_TIME`.
 * @property {Array<Record<string, any> & {verb:string,index:number}>} steps
 */

/**
 * @param {string} path Cesta ke scénáři, relativní k CWD.
 * @returns {Promise<Scenario>}
 */
export async function loadScenario(path) {
  const absolute = resolve(process.cwd(), path);

  let text;
  try {
    text = await readFile(absolute, 'utf8');
  } catch {
    throw new UserError(`Scénář ${path} se nepodařilo přečíst.`);
  }

  let raw;
  try {
    raw = parseJsonc(text);
  } catch (error) {
    throw new UserError(`Scénář ${path} není platný JSONC: ${error.message}`);
  }

  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    throw new UserError(`Scénář ${path} musí být objekt.`);
  }

  if (typeof raw.id !== 'string' || !/^[a-z0-9][a-z0-9-]*$/.test(raw.id)) {
    throw new UserError(
      'Scénář musí mít id z malých písmen, číslic a pomlček.',
      'Id se používá jako název adresáře s artefakty a výsledného souboru.',
    );
  }

  const dataset = readDataset(raw.dataset);
  const clockTime = readClockTime(raw.clockTime);

  if (!Array.isArray(raw.steps) || raw.steps.length === 0) {
    throw new UserError(`Scénář ${raw.id} nemá žádné kroky.`);
  }

  const capture = readBlock(raw.capture, 'capture', DEFAULT_CAPTURE);
  const output = readBlock(raw.output, 'output', {
    w: capture.w, h: capture.h, fps: DEFAULT_OUTPUT_FPS,
  });

  // CSS viewport se odvozuje, nezadává. `capture` jsou pixely rawu; při
  // scale 2 tedy 2560×1600 znamená okno 1280×800 s dvojnásobnou hustotou.
  const width = capture.w / capture.scale;
  const height = capture.h / capture.scale;
  if (!Number.isInteger(width) || !Number.isInteger(height)) {
    throw new UserError(
      `capture.w a capture.h musí být beze zbytku dělitelné scale (${capture.scale}).`,
      `Z ${capture.w}×${capture.h} vychází viewport ${width}×${height}.`,
    );
  }

  return {
    id: raw.id,
    path: absolute,
    capture,
    output,
    viewport: { width, height },
    locale: readString(raw.locale, 'locale', DEFAULT_LOCALE),
    timezone: readString(raw.timezone, 'timezone', DEFAULT_TIMEZONE),
    dataset,
    clockTime,
    steps: raw.steps.map(validateStep),
  };
}
