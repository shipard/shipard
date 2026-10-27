/**
 * Načtení a validace konfigurace z prostředí a z `.env`.
 *
 * Žádná závislost — `process.loadEnvFile()` umí Node 24 nativně. Hodnoty
 * už přítomné v prostředí mají přednost před souborem, takže jednorázové
 * přebití jde udělat prefixem příkazu (`VIDEO_HEADFUL=1 node bin/…`).
 *
 * `.env` je **volitelný**: slouží ručnímu použití runneru mimo Shipard.
 * Když runner spouští `shpd-ds video-run`, předá všechno prostředím
 * a žádný `.env` neexistuje — chybějící soubor proto není chyba, chybí-li
 * položka, řekne to až kontrola konkrétní proměnné.
 */

import { existsSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { UserError } from './errors.mjs';

/** Kořen podprojektu (`tools/video-runner/`). Relativní cesty z `.env` se řeší vůči němu. */
export const PROJECT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Kořen repozitáře — kvůli cestám ke scénářům v `demo/scenarios/`. */
export const REPO_ROOT = resolve(PROJECT_ROOT, '..', '..');

const ENV_FILE = resolve(PROJECT_ROOT, '.env');

let envLoaded = false;

function loadEnvFile() {
  if (envLoaded) return;
  envLoaded = true;

  if (existsSync(ENV_FILE)) {
    process.loadEnvFile(ENV_FILE);
  }
}

/**
 * @param {string} name
 * @param {string} what Lidský popis položky do hlášky.
 */
function required(name, what) {
  const value = process.env[name]?.trim();
  if (!value) {
    throw new UserError(
      `Chybí povinná položka ${name} (${what}).`,
      'Nastav ji v prostředí, nebo v tools/video-runner/.env — vzor všech položek je v .env.example.',
    );
  }
  return value;
}

function optional(name, fallback) {
  const value = process.env[name]?.trim();
  return value ? value : fallback;
}

/** Relativní cesta z `.env` se počítá od kořene podprojektu, ne od CWD. */
function projectPath(value) {
  return isAbsolute(value) ? value : resolve(PROJECT_ROOT, value);
}

/**
 * Pevný čas v prohlížeči (D120). Prázdná nebo nenastavená proměnná
 * znamená skutečný čas — runner se pak chová jako dřív.
 *
 * Exportované kvůli testům; `loadConfig` ho volá nad `VIDEO_FIXED_TIME`.
 *
 * @param {string|undefined} raw
 * @returns {Date|null}
 */
export function parseFixedTime(raw) {
  const value = raw?.trim();
  if (!value) return null;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new UserError(
      `VIDEO_FIXED_TIME není platný čas: ${value}`,
      'Očekává se ISO 8601 s posunem, např. 2026-10-27T09:30:00+01:00',
    );
  }
  return date;
}

/**
 * @typedef {object} Config
 * @property {string} baseUrl Bez koncového lomítka.
 * @property {string} [login]
 * @property {string} [password]
 * @property {string} storageState Absolutní cesta.
 * @property {string} workDir Absolutní cesta.
 * @property {string} outDir Absolutní cesta.
 * @property {boolean} headful
 * @property {Date|null} fixedTime Pevný čas v prohlížeči (`VIDEO_FIXED_TIME`);
 *   `null` = skutečný čas.
 * @property {string|null} hostResolverRules Mapování hostitele pro Chromium
 *   (`VIDEO_HOST_RESOLVER_RULES`, syntaxe `--host-resolver-rules`); `null` = žádné.
 */

/**
 * @param {object} [need] Co daný verb skutečně potřebuje — aby `compose`
 *   nepadal na chybějící adrese instance a `check` na chybějícím hesle.
 * @param {boolean} [need.baseUrl]
 * @param {boolean} [need.credentials]
 * @returns {Config}
 */
export function loadConfig(need = {}) {
  loadEnvFile();

  /** @type {Config} */
  const config = {
    baseUrl: '',
    storageState: projectPath(optional('SHPD_STORAGE_STATE', '.storage-state.json')),
    workDir: projectPath(optional('VIDEO_WORK_DIR', './.work')),
    outDir: projectPath(optional('VIDEO_OUT_DIR', './out')),
    headful: optional('VIDEO_HEADFUL', '0') === '1',
    fixedTime: parseFixedTime(process.env.VIDEO_FIXED_TIME),
    hostResolverRules: optional('VIDEO_HOST_RESOLVER_RULES', null),
  };

  if (need.baseUrl) {
    const raw = required('SHPD_BASE_URL', 'adresa cílové instance včetně ID datového zdroje');
    let parsed;
    try {
      parsed = new URL(raw);
    } catch {
      throw new UserError(
        `SHPD_BASE_URL není platná adresa: ${raw}`,
        'Očekává se např. https://demo.example.dev/a3f2-b8c1-d4e7-f9a0',
      );
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      throw new UserError(`SHPD_BASE_URL musí být http nebo https, ne ${parsed.protocol}`);
    }
    config.baseUrl = raw.replace(/\/+$/, '');
  }

  if (need.credentials) {
    config.login = required('SHPD_LOGIN', 'uživatel v cílovém datovém zdroji');
    config.password = required('SHPD_PASSWORD', 'heslo uživatele');
  }

  return config;
}

/** Cesta relativní k `baseUrl` → absolutní URL. */
export function pageUrl(config, path) {
  return config.baseUrl + (path.startsWith('/') ? path : `/${path}`);
}
