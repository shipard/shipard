/**
 * ① Interpret — průchod kroky scénáře.
 *
 * Čas spotřebovává jedině `pause` (D4). Přejezd kurzoru je vlastnost akce,
 * ne vyprávění, takže se do `pause` nepočítá; autor scénáře řeší tempo,
 * ne mechaniku myši.
 */

import { DEFAULT_TIMEOUT } from './browser.mjs';
import { pageUrl } from './config.mjs';
import { UserError } from './errors.mjs';
import { highlight } from './overlay.mjs';
import { DEFAULT_HIGHLIGHT_S, DEFAULT_SCROLL_S, DEFAULT_TRAVEL_S } from './scenario.mjs';
import { resolveSelector } from './selectors.mjs';

/** Kolikrát za sekundu se posune syntetická myš při přejezdu. */
const CURSOR_HZ = 60;

/** Kolik kroků za sekundu má scrollování. */
const SCROLL_HZ = 60;

/** Pod tímhle posunem v px se scroll považuje za neúčinný. */
const SCROLL_EPSILON = 1;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));

const easeInOutCubic = (t) => (t < 0.5 ? 4 * t * t * t : 1 - ((-2 * t + 2) ** 3) / 2);

function label(step) {
  const value = step[step.verb];
  return `krok #${step.index + 1} (${step.verb}${value === null ? ' null' : value !== undefined ? ` ${value}` : ''})`;
}

/**
 * Strojově čitelná podoba `label()` — třetí parametr `UserError`, ze
 * kterého `result.json` bere krok a selektor, na kterém scénář selhal.
 * Číslo kroku je od jedničky, stejně jako v hlášce.
 */
function stepDetails(step) {
  return { step: { index: step.index + 1, verb: step.verb, value: step[step.verb] } };
}

/** První řádek hlášky Playwrightu — zbytek je výpis volání, který do věty nepatří. */
const firstLine = (error) => String(error?.message ?? error).split('\n')[0];

/**
 * Počká na viditelný prvek a vrátí jeho obdélník v CSS px.
 * @returns {Promise<{x:number,y:number,width:number,height:number}>}
 */
async function elementRect(ctx, step) {
  const selector = step[step.verb];
  const locator = ctx.page.locator(resolveSelector(selector)).first();

  try {
    await locator.waitFor({ state: 'visible', timeout: DEFAULT_TIMEOUT });
  } catch {
    throw new UserError(
      `${label(step)}: prvek se do ${DEFAULT_TIMEOUT / 1000} s neobjevil.`,
      'Sedí selektor na aktuální aplikaci? `@name` hledá [data-testid="name"], přesnou shodu.',
      stepDetails(step),
    );
  }

  const box = await locator.boundingBox();
  if (!box) {
    throw new UserError(
      `${label(step)}: prvek nemá rozměr, nedá se na něj najet.`,
      undefined,
      stepDetails(step),
    );
  }
  return box;
}

/**
 * Přejezd kurzoru s easingem. Posílá skutečné `mousemove` eventy — overlay
 * ve stránce žádnou vlastní animaci nemá a jede za nimi (viz overlay.mjs).
 */
async function travelTo(ctx, x, y, seconds) {
  const from = ctx.mouse;
  const ms = seconds * 1000;
  const frames = Math.max(2, Math.round((ms / 1000) * CURSOR_HZ));
  const started = performance.now();

  for (let i = 1; i <= frames; i++) {
    const p = easeInOutCubic(i / frames);
    await ctx.page.mouse.move(from.x + (x - from.x) * p, from.y + (y - from.y) * p);
    // Deadline, ne fixní sleep — `mouse.move` přes CDP sám něco stojí
    // a s fixním sleepem by přejezd trval znatelně déle, než říká scénář.
    await sleep(started + (ms * i) / frames - performance.now());
  }

  ctx.mouse = { x, y };
}

/**
 * Stav scrollování pod daným bodem — hodnota pro porovnání a zároveň
 * podklad pro chybovou hlášku.
 *
 * Wheel event jde tam, kde je kurzor. Když nad ním nic scrollovatelného
 * není, Chromium ho zahodí **beze slova**, takže bez tohohle by z toho
 * vzniklo tiše nehybné video. A protože „je kurzor nad správným místem?"
 * je otázka, na kterou runner umí odpovědět sám, vrací se i to, co pod
 * kurzorem leží a kde by scroll fungoval.
 */
function scrollState(page, x, y) {
  return page.evaluate(([px, py]) => {
    const name = (el) => el.tagName.toLowerCase()
      + [...el.classList].filter((c) => !c.startsWith('svelte-')).map((c) => `.${c}`).join('');
    const scrollable = (el) => /(auto|scroll)/.test(getComputedStyle(el).overflowY)
      && el.scrollHeight > el.clientHeight;

    const hit = document.elementFromPoint(px, py);

    let el = hit;
    while (el) {
      if (scrollable(el)) return { top: el.scrollTop, container: name(el), hit: hit && name(hit) };
      el = el.parentElement;
    }

    // Nic pod kurzorem — ať hláška umí poradit, kde by to šlo.
    const candidates = [...document.querySelectorAll('*')]
      .filter(scrollable)
      .map((node) => `${name(node)} (${node.scrollHeight - node.clientHeight} px)`)
      .slice(0, 6);

    return {
      top: document.scrollingElement?.scrollTop ?? 0,
      container: null,
      hit: hit && name(hit),
      candidates,
    };
  }, [x, y]);
}

async function moveToElement(ctx, step) {
  const box = await elementRect(ctx, step);
  const center = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
  await travelTo(ctx, center.x, center.y, step.travel ?? DEFAULT_TRAVEL_S);
  return box;
}

const HANDLERS = {
  async goto(ctx, step) {
    try {
      await ctx.page.goto(pageUrl(ctx.config, step.goto), {
        waitUntil: 'domcontentloaded',
        timeout: DEFAULT_TIMEOUT,
      });
    } catch (error) {
      throw new UserError(
        `${label(step)}: stránka se nenačetla — ${firstLine(error)}`,
        'Běží instance? Sedí SHPD_BASE_URL a cesta ve scénáři?',
        stepDetails(step),
      );
    }
    ctx.timeline.mark('goto', { path: step.goto });
  },

  async waitFor(ctx, step) {
    const box = await elementRect(ctx, step);
    ctx.timeline.mark('waitFor', { selector: step.waitFor, rect: [box.x, box.y, box.width, box.height] });
  },

  async hover(ctx, step) {
    const box = await moveToElement(ctx, step);
    ctx.timeline.mark('hover', { selector: step.hover, rect: [box.x, box.y, box.width, box.height] });
  },

  async click(ctx, step) {
    const box = await moveToElement(ctx, step);
    await ctx.page.mouse.down();
    await ctx.page.mouse.up();
    ctx.timeline.mark('click', { selector: step.click, rect: [box.x, box.y, box.width, box.height] });
  },

  /**
   * Scroll s easingem řízený runnerem, ne kompozitorem prohlížeče: jeden
   * velký `wheel` delta by nechal animaci na Chromiu, které se může
   * chovat jinak ve jiné verzi. Takhle je pohyb vždycky stejný a tempo
   * si řídí scénář.
   */
  async scroll(ctx, step) {
    const seconds = step.over ?? DEFAULT_SCROLL_S;
    const total = step.scroll;
    const steps = Math.max(2, Math.round(seconds * SCROLL_HZ));
    const ms = seconds * 1000;

    const before = await scrollState(ctx.page, ctx.mouse.x, ctx.mouse.y);
    if (before.container === null) {
      throw new UserError(
        `${label(step)}: pod kurzorem není nic, co by se dalo scrollovat.`
        + ` Kurzor je na ${ctx.mouse.x},${ctx.mouse.y} nad ${before.hit ?? 'ničím'}.`,
        before.candidates?.length
          ? `Scrollovat jde tady: ${before.candidates.join(', ')}.`
          : 'Na stránce není žádná scrollovatelná oblast — má obsah dost řádků?',
        stepDetails(step),
      );
    }

    const started = performance.now();
    let done = 0;

    for (let i = 1; i <= steps; i++) {
      const target = total * easeInOutCubic(i / steps);
      const delta = target - done;
      done = target;
      if (delta !== 0) await ctx.page.mouse.wheel(0, delta);
      await sleep(started + (ms * i) / steps - performance.now());
    }

    const after = await scrollState(ctx.page, ctx.mouse.x, ctx.mouse.y);
    if (Math.abs(after.top - before.top) < SCROLL_EPSILON) {
      throw new UserError(
        `${label(step)}: ${before.container} se neposunul (scrollTop ${before.top}).`,
        'Je oblast na konci ve směru scrollu? Kladný posun jde dolů, záporný nahoru.',
        stepDetails(step),
      );
    }

    ctx.timeline.mark('scroll', {
      by: total, over: seconds, container: before.container,
      from: before.top, to: after.top,
    });
  },

  async caption(ctx, step) {
    // Titulek se do obrazu nekreslí — vypálí ho `compose` z časové osy (D5).
    ctx.timeline.mark('caption', { text: step.caption });
  },

  async highlight(ctx, step) {
    const seconds = step.for ?? DEFAULT_HIGHLIGHT_S;
    await elementRect(ctx, step);
    // Overlay dělá in-page querySelector — zkratku `@` musí dostat přeloženou.
    const rect = await highlight(ctx.page, resolveSelector(step.highlight), seconds);
    if (!rect) {
      throw new UserError(
        `${label(step)}: rámeček se nepodařilo vykreslit.`,
        undefined,
        stepDetails(step),
      );
    }
    ctx.timeline.mark('highlight', { selector: step.highlight, for: seconds, rect });
  },

  async pause(ctx, step) {
    await sleep(step.pause * 1000);
  },
};

/**
 * @param {object} ctx
 * @param {import('playwright').Page} ctx.page
 * @param {import('./config.mjs').Config} ctx.config
 * @param {import('./scenario.mjs').Scenario} ctx.scenario
 * @param {import('./timeline.mjs').Timeline} ctx.timeline
 * @param {(line: string) => void} [ctx.log]
 */
export async function interpret(ctx) {
  const { scenario } = ctx;

  // Výchozí poloha myši je dolní hrana uprostřed, takže první přejezd
  // přijede do obrazu zdola a kurzor se nikde nezjeví „z ničeho".
  ctx.mouse = { x: scenario.viewport.width / 2, y: scenario.viewport.height - 2 };

  for (const step of scenario.steps) {
    ctx.log?.(`  ${label(step)}`);
    await HANDLERS[step.verb](ctx, step);

    // `pause` jako modifikátor kteréhokoli kroku; u samotného verbu `pause`
    // ho už spotřeboval handler.
    if (step.verb !== 'pause' && step.pause) await sleep(step.pause * 1000);
  }
}
