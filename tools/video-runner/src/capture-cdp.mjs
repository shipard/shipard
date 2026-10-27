/**
 * Varianta záznamu A+ — CDP `Page.startScreencast`.
 *
 * Chrome posílá framy **jen když se obraz změní**. To je celý vtip téhle
 * varianty: statická pasáž dá jeden frame s dlouhým trváním místo stovky
 * identických, a ffmpeg z nich přes concat s `duration` řádky složí
 * konstantní FPS. Právě proto tahle cesta neseká tam, kde `recordVideo`
 * ano.
 *
 * Běží headless, žádná systémová závislost kromě ffmpegu.
 */

import { mkdir, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { RAW_NAME } from './artifacts.mjs';
import { UserError } from './errors.mjs';
import * as ffmpeg from './ffmpeg.mjs';
import { interpret } from './interpret.mjs';
import { assertSession, createSession } from './runner.mjs';

/**
 * Framy se berou bezeztrátově. JPEG ze screencastu má chroma subsampling
 * 4:2:0, což je na textu vidět jako měkké okraje — a protože se pak ještě
 * jednou kóduje do x264, sečítaly by se dvě ztráty. PNG je dražší na disk
 * (statistika níže hlásí kolik), ale framy jsou stejně jen dočasné.
 */
const FRAME_FORMAT = 'png';
const FIRST_FRAME_TIMEOUT_MS = 5000;

function withTimeout(promise, ms, message) {
  return Promise.race([
    promise,
    new Promise((_, reject) => setTimeout(() => reject(new UserError(message)), ms)),
  ]);
}

/**
 * Concat list pro ffmpeg. Trvání framu je rozdíl proti následujícímu;
 * poslední se počítá do konce záznamu a musí se zopakovat — bez toho ho
 * concat demuxer zahodí.
 */
function concatList(frames, endEpoch) {
  const lines = [];
  for (let i = 0; i < frames.length; i++) {
    const next = i + 1 < frames.length ? frames[i + 1].ts : endEpoch;
    const duration = Math.max(0.001, next - frames[i].ts);
    lines.push(`file '${frames[i].path}'`, `duration ${duration.toFixed(4)}`);
  }
  lines.push(`file '${frames.at(-1).path}'`);
  return `${lines.join('\n')}\n`;
}

/**
 * Diagnostika záznamu.
 *
 * Podstatné číslo je **špička**, ne průměr: framy vznikají jen při změně
 * obrazu, takže průměr přes celý klip vypovídá hlavně o tom, jak dlouhé
 * jsou v scénáři pauzy. Jediná otázka, na kterou tady jde odpovědět, je
 * „stíhá screencast v pohybu" — a tu řeší nejvyšší počet framů
 * v jednosekundovém okně.
 */
function stats(frames, duration, bytes) {
  let maxGap = 0;
  for (let i = 1; i < frames.length; i++) {
    maxGap = Math.max(maxGap, frames[i].ts - frames[i - 1].ts);
  }

  let peak = 0;
  let from = 0;
  for (let i = 0; i < frames.length; i++) {
    while (frames[i].ts - frames[from].ts > 1) from++;
    peak = Math.max(peak, i - from + 1);
  }

  return `${frames.length} framů za ${duration.toFixed(1)} s `
    + `(průměr ${(frames.length / duration).toFixed(1)}/s, špička ${peak}/s, `
    + `největší mezera ${maxGap.toFixed(2)} s, ${(bytes / 1024 / 1024).toFixed(0)} MB framů)`;
}

/**
 * @param {object} params
 * @param {import('./config.mjs').Config} params.config
 * @param {import('./scenario.mjs').Scenario} params.scenario
 * @param {import('./timeline.mjs').Timeline} params.timeline
 * @param {string} params.dir Pracovní adresář scénáře.
 * @param {(line: string) => void} [params.log]
 * @returns {Promise<string>} Cesta k raw.mp4.
 */
export default async function captureCdp({ config, scenario, timeline, dir, log }) {
  const framesDir = join(dir, 'frames');
  await mkdir(framesDir, { recursive: true });

  const session = await createSession(config, scenario, { headless: true, log });

  /** @type {Array<{path: string, ts: number}>} */
  const frames = [];
  /** @type {Array<Promise<void>>} */
  const writes = [];
  let bytes = 0;
  let t0Epoch = null;
  let onFirstFrame;
  const firstFrame = new Promise((resolve) => { onFirstFrame = resolve; });

  try {
    await assertSession(session.page, config);

    const cdp = await session.context.newCDPSession(session.page);

    cdp.on('Page.screencastFrame', (frame) => {
      // Ack musí jít první — Chrome pošle další frame až po něm, takže
      // čekání na zápis na disk by rovnou snížilo snímkovou frekvenci.
      cdp.send('Page.screencastFrameAck', { sessionId: frame.sessionId }).catch(() => {});

      const path = join(framesDir, `frame-${String(frames.length).padStart(6, '0')}.${FRAME_FORMAT}`);
      const ts = frame.metadata?.timestamp ?? Date.now() / 1000;
      const data = Buffer.from(frame.data, 'base64');

      bytes += data.byteLength;
      frames.push({ path, ts });
      writes.push(writeFile(path, data));

      if (t0Epoch === null) {
        t0Epoch = ts;
        timeline.start();
        onFirstFrame();
      }
    });

    // `maxWidth`/`maxHeight` jsou strop, ne cíl — velikost framu určuje
    // raster okna (viz komentář v runner.mjs). Strop je tady jen proto, aby
    // se při špatně zadaném `capture` nezaznamenávalo něco jiného, než
    // scénář slibuje.
    await cdp.send('Page.startScreencast', {
      format: FRAME_FORMAT,
      maxWidth: scenario.capture.w,
      maxHeight: scenario.capture.h,
      everyNthFrame: 1,
    });

    // Statická stránka by nevydala ani jeden frame a časová osa by neměla
    // nulu. Vynutí ji první pohyb myši — ten navíc usadí kurzor přesně tam,
    // odkud interpret počítá první přejezd.
    await session.page.mouse.move(scenario.viewport.width / 2, scenario.viewport.height - 2);
    await withTimeout(
      firstFrame,
      FIRST_FRAME_TIMEOUT_MS,
      'Screencast neposlal ani jeden frame — záznam by byl prázdný.',
    );

    await interpret({ page: session.page, config, scenario, timeline, log });
    timeline.finish();

    await cdp.send('Page.stopScreencast');
  } finally {
    await session.close();
  }

  await Promise.all(writes);
  if (frames.length === 0) throw new UserError('Záznam neobsahuje žádné framy.');

  log?.(`  ${stats(frames, timeline.duration(), bytes)}`);

  const listPath = join(dir, 'frames.txt');
  await writeFile(listPath, concatList(frames, t0Epoch + timeline.duration()), 'utf8');

  const rawPath = join(dir, RAW_NAME);
  await ffmpeg.run([
    '-y',
    '-f', 'concat', '-safe', '0', '-i', listPath,
    '-fps_mode', 'cfr', '-r', String(scenario.output.fps),
    '-c:v', 'libx264', '-crf', '16', '-preset', 'veryfast',
    '-pix_fmt', 'yuv420p',
    rawPath,
  ], { log });

  // Framy jsou po zakódování jen zabraná stovka megabajtů; diagnostiku
  // z nich nese řádek se statistikou výš.
  await rm(framesDir, { recursive: true, force: true });
  await rm(listPath, { force: true });

  return rawPath;
}
