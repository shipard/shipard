/**
 * Verb `build` — `record` a hned `compose` nad jedním načtením scénáře.
 *
 * Jeden `result.json` za celý běh (verb `build`): selhání záznamu
 * i skládání končí stejně, úspěch nese cestu k hotovému videu.
 */

import { join } from 'node:path';

import { withResult } from '../result.mjs';
import { loadScenario } from '../scenario.mjs';
import { composeScenario } from './compose.mjs';
import { recordScenario } from './record.mjs';

export default async function build({ config, scenarioPath }) {
  const scenario = await loadScenario(scenarioPath);
  await withResult({
    verb: 'build',
    dir: join(config.workDir, scenario.id),
    scenarioId: scenario.id,
    run: async () => {
      await recordScenario(config, scenario);
      return { videoPath: await composeScenario(config, scenario) };
    },
  });
}
