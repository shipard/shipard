/**
 * Výsledek běhu verbu — `result.json` v pracovním adresáři scénáře.
 *
 * Čte ho `shpd-ds video-run`, který runner spouští jako podproces: z něj
 * bere stav, dobu, krok a selektor, na kterém scénář selhal, a u `build`
 * cestu k hotovému videu. Zapisuje se **vždy**, i při selhání — proto
 * verby obalují svůj běh do try/catch, výsledek zapíší a chybu pustí dál,
 * aby CLI dál skončilo nenulovým kódem s čitelnou hláškou.
 *
 * `buildResult` je čistá funkce bez filesystému, aby šla testovat.
 */

import { mkdir, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

export const RESULT_NAME = 'result.json';

/**
 * @typedef {object} ResultStep
 * @property {number} index Číslo kroku od jedničky — stejné jako v hlášce.
 * @property {string} verb
 * @property {unknown} [value] Hodnota kroku, u akcí nad prvky selektor.
 */

/**
 * @typedef {object} RunResult
 * @property {string} verb
 * @property {string} scenario Id scénáře.
 * @property {'ok'|'failed'} status
 * @property {number} durationMs Od startu verbu do zápisu výsledku.
 * @property {{message: string, hint?: string, step?: ResultStep}} [error] Jen u `failed`.
 * @property {{path: string}} [video] Jen u `compose` / `build` po úspěchu; absolutní cesta.
 */

/**
 * @param {object} params
 * @param {string} params.verb
 * @param {string} params.scenarioId
 * @param {number} params.startedAt `Date.now()` při startu verbu.
 * @param {Error|null} [params.error] Chyba, se kterou běh skončil; `null` = úspěch.
 * @param {{path: string}|null} [params.video] Hotové video (jen u úspěchu).
 * @param {number} [params.now] Pro testy — výchozí `Date.now()`.
 * @returns {RunResult}
 */
export function buildResult({ verb, scenarioId, startedAt, error = null, video = null, now = Date.now() }) {
  /** @type {RunResult} */
  const result = {
    verb,
    scenario: scenarioId,
    status: error ? 'failed' : 'ok',
    durationMs: Math.max(0, now - startedAt),
  };

  if (error) {
    result.error = { message: error.message ?? String(error) };
    if (error.hint) result.error.hint = error.hint;
    // `details.step` plní interpret u chyb kroku (třetí parametr UserError).
    if (error.details?.step) result.error.step = error.details.step;
  } else if (video) {
    result.video = { path: video.path };
  }

  return result;
}

/**
 * Zapíše `result.json` do adresáře (který založí, pokud chybí).
 *
 * @param {string} dir Pracovní adresář scénáře (`VIDEO_WORK_DIR/<id>`).
 * @param {RunResult} result
 * @returns {Promise<string>} Cesta k zapsanému souboru.
 */
export async function writeResult(dir, result) {
  await mkdir(dir, { recursive: true });
  const path = join(dir, RESULT_NAME);
  await writeFile(path, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  return path;
}

/**
 * Obal běhu verbu: smaže starý výsledek, pustí `run`, zapíše nový výsledek
 * a případnou chybu pustí dál.
 *
 * Starý `result.json` se maže **před** během — při pádu uprostřed by
 * `video-run` jinak mohl přečíst výsledek minulého běhu jako platný.
 *
 * @template T
 * @param {object} params
 * @param {string} params.verb
 * @param {string} params.dir Pracovní adresář scénáře.
 * @param {string} params.scenarioId
 * @param {() => Promise<T>} params.run Vlastní práce verbu. Když vrátí objekt
 *   s řetězcem `videoPath`, dostane ho výsledek jako `video.path`.
 * @returns {Promise<T>}
 */
export async function withResult({ verb, dir, scenarioId, run }) {
  const startedAt = Date.now();
  await rm(join(dir, RESULT_NAME), { force: true });

  let outcome;
  try {
    outcome = await run();
  } catch (error) {
    await writeResult(dir, buildResult({ verb, scenarioId, startedAt, error }));
    throw error;
  }

  const video = typeof outcome?.videoPath === 'string' ? { path: outcome.videoPath } : null;
  await writeResult(dir, buildResult({ verb, scenarioId, startedAt, video }));
  return outcome;
}
