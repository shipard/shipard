/**
 * Verb `check` — průchod scénáře bez záznamu.
 *
 * Zároveň smoke E2E: nenulový exit znamená, že se scénář rozešel
 * s aplikací. Hláška vždycky říká číslo kroku a selektor; totéž strojově
 * nese `result.json` v pracovním adresáři scénáře.
 */

import { join } from 'node:path';

import { interpret } from '../interpret.mjs';
import { withResult } from '../result.mjs';
import { assertSession, createSession } from '../runner.mjs';
import { loadScenario } from '../scenario.mjs';
import { Timeline } from '../timeline.mjs';

/** Chvilka na konci, aby při VIDEO_HEADFUL=1 šel výsledek vůbec zahlédnout. */
const HEADFUL_LINGER_MS = 2000;

/**
 * @param {import('./../config.mjs').Config} config
 * @param {import('./../scenario.mjs').Scenario} scenario
 */
async function checkScenario(config, scenario) {
  const { width, height } = scenario.viewport;

  console.log(
    `check ${scenario.id} — ${scenario.steps.length} kroků, `
    + `viewport ${width}×${height} @${scenario.capture.scale}x, ${scenario.locale}`,
  );

  const session = await createSession(config, scenario, {
    headless: !config.headful,
    // Viditelný prohlížeč při ladění: dvojnásobná hustota by okno zvětšila
    // na dvojnásobek toho, co zadává scénář, a na běžném displeji by se
    // nevešlo. `check` žádné pixely neukládá, takže na výsledku nezáleží.
    scale: config.headful ? 1 : scenario.capture.scale,
  });
  const timeline = new Timeline(scenario);

  try {
    await assertSession(session.page, config);

    timeline.start();
    await interpret({
      page: session.page,
      config,
      scenario,
      timeline,
      log: (line) => console.log(line),
    });
    timeline.finish();

    console.log(`OK — průchod trval ${timeline.duration().toFixed(1)} s`);

    if (config.headful) {
      await new Promise((resolve) => setTimeout(resolve, HEADFUL_LINGER_MS));
    }
  } finally {
    await session.close();
  }
}

export default async function check({ config, scenarioPath }) {
  const scenario = await loadScenario(scenarioPath);
  await withResult({
    verb: 'check',
    dir: join(config.workDir, scenario.id),
    scenarioId: scenario.id,
    run: () => checkScenario(config, scenario),
  });
}
