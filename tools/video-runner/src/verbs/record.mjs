/**
 * Verb `record` — ① interpret + ② overlay → `raw.mp4` a `timeline.json`.
 *
 * Záznam končí vždy stejnými dvěma artefakty; `compose` pak nemusí vědět
 * nic o tom, jak vznikly.
 */

import { mkdir, rm } from 'node:fs/promises';
import { join, relative } from 'node:path';

import { RAW_NAME, TIMELINE_NAME } from '../artifacts.mjs';
import capture from '../capture-cdp.mjs';
import { PROJECT_ROOT } from '../config.mjs';
import * as ffmpeg from '../ffmpeg.mjs';
import { withResult } from '../result.mjs';
import { loadScenario } from '../scenario.mjs';
import { Timeline } from '../timeline.mjs';

/**
 * Sdílené s verbem `build`, aby se scénář nenačítal dvakrát.
 *
 * @param {import('./../config.mjs').Config} config
 * @param {import('./../scenario.mjs').Scenario} scenario
 * @returns {Promise<{dir: string, rawPath: string, timelinePath: string}>}
 */
export async function recordScenario(config, scenario) {
  await ffmpeg.assertAvailable();

  const dir = join(config.workDir, scenario.id);
  await mkdir(dir, { recursive: true });

  // Zbytky z minulého běhu by při pádu uprostřed vypadaly jako platný
  // výsledek — a `compose` by beze slova složil staré video. Maže se jen
  // to, co teď vznikne (`result.json` si hlídá obal verbu).
  for (const name of [RAW_NAME, TIMELINE_NAME]) {
    await rm(join(dir, name), { force: true });
  }

  const timeline = new Timeline(scenario);
  const log = (line) => console.log(line);

  console.log(`record ${scenario.id}`);
  const rawPath = await capture({ config, scenario, timeline, dir, log });

  const timelinePath = join(dir, TIMELINE_NAME);
  await timeline.write(timelinePath);

  console.log(
    `Záznam ${timeline.duration().toFixed(1)} s → ${relative(PROJECT_ROOT, dir)}/`
    + ` (${RAW_NAME}, ${TIMELINE_NAME})`,
  );

  return { dir, rawPath, timelinePath };
}

export default async function record({ config, scenarioPath }) {
  const scenario = await loadScenario(scenarioPath);
  await withResult({
    verb: 'record',
    dir: join(config.workDir, scenario.id),
    scenarioId: scenario.id,
    run: () => recordScenario(config, scenario),
  });
}
