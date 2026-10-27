# Video runner — pilotní spike (protažení pipeline)

**Stav:** hotovo — implementace i ověření na dev serveru 2026-08-27; závěry níže

> Pilotní scénář `demo/scenarios/spike-dashboard.jsonc` byl zrušen (#103 F7b D129, `tasks/testing-phase7b.md`); odkazy na něj níže jsou historie. Videa běží nad demo sadou přes `shpd-ds video-run` a vývojářský dashboard.

## Kontext / Cíl

Automatizovaná výroba videí z UI podle scénáře — issue **shipard/shpd#48**,
rozhodnutí D1–D9 v komentáři issue (2026-08). Navazující kontext:
`shpd-web/docs/demo-vyroba.md` (D7–D10 — ukázky jsou automatizované nahrávky
reálné aplikace, bez zvuku, titulky v obraze) a `shpd-web/docs/demo-scenare.md`
(scénáře S1/S2/S5, které se budou točit jako první).

**Tohle je spike, ne první video.** Cílem je protáhnout pipeline prvními daty
a zjistit, co se na ní rozbije. Obsah klipu je nepodstatný — vzniklý testovací
soubor se **zahazuje**, necommituje se ani nepublikuje (běží nad dev DS
s reálnými daty).

**Výstupem spike není video, ale zápis závěrů** (sekce „Zjištění" v tomto
souboru + komentář do #48): která varianta záznamu, jak se chová synchronizace
kurzoru, kolik FPS je dost, jak dlouhá pauza je čitelná, kde je pipeline
křehká.

## Rozsah

Vzniká runner v `tools/video-runner/` s podmnožinou funkcionality: verby
`login`, `record`, `compose`, `build`, `check`; verby scénáře `goto`,
`waitFor`, `hover`, `click`, `caption`, `pause`, `highlight`; vložený kurzor;
**obě** varianty záznamu (A+ i B, viz níže) pro porovnání; `timeline.json`;
kompozitor s burned-in titulky; výstup `.mp4`.

*Doplněno během spike:* verb `scroll` (jediná skutečná animace, kterou
produkt má, a zároveň jediné místo, kde by varianta `cdp` mohla nestíhat —
bez toho by rozhodnutí o variantě stálo na nedotčeném předpokladu)
a připnutí jazyka a časové zóny (Z5). Varianta `x11` byla po vyhodnocení
odstraněna (Z7).

**Mimo rozsah:** zoomy a rámečky v postprodukci (`timeline.json` pro ně jen
připravuje data), dabing, verby `fill` / `press` / `scrollTo` / `raw`,
`page.clock` fixace času, WebM a poster, `data-testid` na zlaté cestě aplikace,
sady z #40, scénáře S1/S2/S5, CI, `install.sh`, kontejner `ns-media` na alfě.

## Potvrzená rozhodnutí (viz #48)

- **D1** — čistá knihovna `playwright` + vlastní tenké CLI, ne
  `@playwright/test`; trace ručně přes `context.tracing.start()`.
- **D2** — čtyřvrstvá pipeline s hmatatelnými artefakty:
  `scénář → ① interpret + ② overlay → raw + timeline.json → ③ kompozitor → výstup`.
  Přepsání titulku znamená jen `compose`, nikdy přetočení.
- **D3** — scénář = JSONC, slovník verbů nad `data-testid`, nikdy nad texty
  ani CSS třídami.
- **D4** — `caption` neblokuje; `pause` je jediný spotřebič času a smí být
  modifikátorem každého kroku; přejezd kurzoru se do `pause` nepočítá
  (výchozí 600 ms, přebitelné `travel`); validátor hlídá čitelnost titulků.
- **D5** — titulky burned-in v postprodukci z `timeline.json`, ne overlay
  v DOMu; `timeline.json` nese i `rect` prvků kvůli budoucím zoomům.
- **D6** — vložený DOM kurzor přes `addInitScript`, X kurzor se nepoužívá.
- **D7** — runner i scénáře v `shpd`; konvence `scripts/` = jednosouborové
  helpery, `tools/<name>/` = podprojekt s vlastními závislostmi.
- **D8** — `INSTALL.md` prostředí-agnostický, konfigurace přes `.env`
  (vzor `.env.example`), Node 24 (aktuální LTS) jako minimum.

## Doplňující rozhodnutí z průzkumu kódu (2026-08-27)

- **R1 — prostý ESM JavaScript, žádný build step.** Repo TypeScript nikde
  nepoužívá (`frontend/` je čisté JS + Svelte), zavádět kvůli spike kompilaci
  do PHP repa nemá smysl. Soubory `.mjs`, typy jen JSDoc, kde to pomůže.
  Závislosti: **jen `playwright`** — `.env` se čte přes `process.loadEnvFile()`
  (Node 24 nativně), argumenty přes `node:util` `parseArgs`.
- **R2 — kurzor se synchronizuje sám přes `mousemove`.** Klíčová technika,
  která odstraňuje hlavní riziko z #48 (rozejití vizuálního kurzoru
  a syntetické myši). `page.mouse.move()` dispatchuje **skutečné DOM eventy**,
  takže overlay nemá vlastní animaci — jen si na `document` zaregistruje
  `mousemove` / `mousedown` / `mouseup` a polohuje se podle nich. Synchronizace
  je pak zaručená konstrukcí, ne časováním. Runner tedy dělá jen easing loop
  s `page.mouse.move()` a overlay ho následuje.
- **R3 — `data-testid` v aplikaci neexistuje** (ověřeno: v `frontend/src`
  není ani jeden výskyt). Spike se zlaté cestě proto vyhýbá a používá to,
  co v aplikaci je. Přihlašovací obrazovka má stabilní `id`:
  `#login-name`, `#login-password`, a `handleKeydown` odesílá na Enter
  (`frontend/src/components/auth/LoginScreen.svelte`) — verb `login` tedy
  vyplní obě pole a stiskne Enter, žádné klikání na tlačítko.
  Doplnění `data-testid` na zlatou cestu je samostatná položka před prvním
  skutečným videem.
- **R4 — dvě varianty záznamu se implementují obě**, přepínač
  `--capture=cdp|x11`:
  - **A+ (`cdp`)** — `Page.startScreencast` přes `page.context().newCDPSession(page)`,
    framy se ukládají jako JPEG a jejich `metadata.timestamp` se zapisuje;
    ffmpeg je pak složí přes `-f concat` se `duration` řádky na konstantní FPS.
    Framy vznikají jen při změně obrazu, takže statické pasáže dají jeden
    frame s dlouhou `duration` — což je správně a je to důvod, proč tahle
    varianta nesekne (na rozdíl od `recordVideo`).
    Běží headless, žádná systémová závislost.
  - **B (`x11`)** — `Xvfb :99 -screen 0 <w>x<h>x24`, headed Chromium
    s `DISPLAY=:99`, `ffmpeg -f x11grab -draw_mouse 0 -framerate 30`.
    Konstantní FPS nativně, zachytí CSS transitions. Synchronizace časové
    osy: ffmpeg se spustí, počká se 500 ms na rozběhnutí a až pak se vezme
    `t0` — případný offset se ošetří v `compose` ořezem.
- **R5 — titulky jako ASS, ne SRT.** Kvůli kontrole nad stylem (pozice,
  podklad, font) bez nutnosti druhého filtru. `compose` generuje
  `captions.ass` z `timeline.json` a přiloží ho jako `-vf subtitles=`.

## Struktura

```
tools/video-runner/
  package.json              # name shipard-video-runner, engines node>=24, dep: playwright
  README.md                 # co to je, jak se to pouští
  INSTALL.md                # závislosti prostředí (prostředí-agnosticky)
  .env.example              # vzor konfigurace
  bin/video-runner.mjs      # CLI, parseArgs
  src/config.mjs            # načtení .env + validace
  src/scenario.mjs          # načtení a validace scénáře (JSONC → objekt)
  src/interpret.mjs         # ① průchod kroků
  src/overlay.mjs           # ② injektovaný kurzor + highlight (addInitScript)
  src/capture-cdp.mjs       # A+
  src/capture-x11.mjs       # B
  src/timeline.mjs          # zápis timeline.json
  src/compose.mjs           # ③ ffmpeg, ASS titulky
  src/verbs/login.mjs …     # jednotlivé verby CLI

demo/scenarios/spike-dashboard.jsonc
demo/datasets/README.md     # „sady sem přijdou po dokončení tasks/dataset-phase1.md"
```

Do root `.gitignore` přidat: `/tools/video-runner/node_modules/`,
`/tools/video-runner/.env`, `/tools/video-runner/.storage-state.json`,
`/tools/video-runner/.work/`, `/tools/video-runner/out/`.
Uložená session je stejně citlivá jako heslo.

### `.env.example`

```bash
# Cílová instance Shipardu (bez koncového lomítka)
SHPD_BASE_URL=https://demo.example.dev

# Uživatel v cílovém DS — normální přihlášení formulářem
SHPD_LOGIN=
SHPD_PASSWORD=

# Uložená session z verbu `login` (relativně k tools/video-runner/)
SHPD_STORAGE_STATE=.storage-state.json

# Artefakty (raw + timeline) a hotová videa
VIDEO_WORK_DIR=./.work
VIDEO_OUT_DIR=./out

# Ladění: 1 = viditelný prohlížeč, bez záznamu
VIDEO_HEADFUL=0
```

### Scénář — podporovaná podmnožina

```jsonc
{
  "id": "spike-dashboard",
  "capture": { "w": 2560, "h": 1600, "scale": 2 },
  "output":  { "w": 1280, "h": 800, "fps": 30 },
  "steps": [
    { "goto": "/app" },
    { "waitFor": "#app-shell", "pause": 1 },
    { "caption": "Testovací klip — protažení pipeline", "pause": 2.5 },
    { "hover": "#some-existing-element", "pause": 1 },
    { "caption": null }
  ]
}
```

Pozn.: dokud v aplikaci nejsou `data-testid`, přijímá `waitFor`/`hover`/`click`
CSS selektor. Až budou, přejde se na `data-testid` a CSS varianta se zakáže
validátorem — v README to musí být napsané jako známý dluh.

### `timeline.json`

```jsonc
{ "scenario": "spike-dashboard", "capture": "cdp", "duration": 12.4, "fps": 30,
  "events": [
    { "t": 1.2, "type": "caption", "text": "Testovací klip — protažení pipeline" },
    { "t": 3.7, "type": "caption", "text": null },
    { "t": 4.1, "type": "hover", "selector": "#some-existing-element",
                "rect": [820, 340, 360, 96] }
  ] }
}
```

`t` je v sekundách od začátku záznamu. `rect` je `[x, y, w, h]` v souřadnicích
záznamu (tj. před škálováním na `output`).

## Kroky implementace

Commit po každém kroku.

1. **Skelet podprojektu.** `package.json`, `README.md`, `INSTALL.md`,
   `.env.example`, `.gitignore` v rootu, `demo/datasets/README.md`.
   INSTALL.md obsahuje: seznam apt balíčků (`ffmpeg`, `xvfb`, fonty),
   Node 24, `npx playwright install --with-deps chromium`, a **upozornění,
   že nvm Node není na PATH v neinteraktivním shellu** (cron, systemd,
   budoucí `install.sh`).
2. **CLI skelet + `config.mjs`.** `parseArgs`, verby zaregistrované,
   nenalezený verb → nenulový exit. Chybějící povinná položka `.env` →
   jasná hláška, ne stack trace.
3. **Verb `login`.** Headless Chromium → `SHPD_BASE_URL` → `#login-name`,
   `#login-password`, Enter → čekání na app shell → `context.storageState()`
   do `SHPD_STORAGE_STATE`. Ostatní verby session jen načítají; při expiraci
   selže `record` hláškou „session neplatná, spusť `login`", nikdy nesmí
   vzniknout video přihlašovací stránky.
4. **Overlay (`overlay.mjs`) + interpret (`interpret.mjs`).** Kurzor přes
   `addInitScript` (`position: fixed; pointer-events: none; z-index: 2147483647`),
   polohování podle `mousemove` (R2), efekt kliknutí podle `mousedown`/`mouseup`,
   `highlight` jako rámeček kolem prvku po dobu `for`. Easing přejezdu
   `easeInOutCubic`, výchozí 600 ms, ~60 kroků/s. Verby dle rozsahu, `pause`
   jako modifikátor kteréhokoli kroku. Ověřit verbem `check` (průchod bez
   záznamu) a s `VIDEO_HEADFUL=1`.
5. **Záznam A+ (`capture-cdp.mjs`) + `timeline.mjs`.** Framy + timestampy
   do `VIDEO_WORK_DIR/<id>/`, `timeline.json`, `frames.txt` pro concat.
6. **Záznam B (`capture-x11.mjs`).** Xvfb + x11grab podle R4, přepínač
   `--capture`.
7. **Kompozitor (`compose.mjs`).** `captions.ass` z `timeline.json`,
   škálování na `output`, `libx264 -crf 20 -pix_fmt yuv420p -movflags +faststart -an`,
   výstup do `VIDEO_OUT_DIR`. Validátor čitelnosti: titulek zobrazený kratší
   dobu než `max(1.2 s; znaky/15)` → warning, nad 90 znaků → warning.
8. **Pilotní scénář + zápis.** `demo/scenarios/spike-dashboard.jsonc`,
   `build` oběma variantami, doplnit sekci „Zjištění" do tohoto souboru.

## Před implementací přečti

- `shpd-web/docs/demo-vyroba.md` (D7–D10) a `demo-scenare.md` (S1/S2/S5) —
  k čemu runner nakonec bude
- Komentář v **shipard/shpd#48** — plné znění rozhodnutí D1–D9
- `frontend/src/components/auth/LoginScreen.svelte` — id polí, `handleKeydown`
- `frontend/package.json` — vzor Node podprojektu v tomto repu (prostý ESM,
  bez TypeScriptu, commitnutý lock file)
- `scripts/check-sensitive.py` — aby `.env` a `.storage-state.json` neprošly
- `DEVELOPERS.md` — konvence repa; konvenci `scripts/` vs `tools/` do něj
  **nedoplňovat** bez zadání (rozhodne se po spike)
- Playwright: `BrowserContext.storageState`, `Page.addInitScript`,
  `Page.mouse`, `BrowserContext.newCDPSession`, CDP `Page.startScreencast`

## Hotovo když

- [x] `tools/video-runner/` existuje, `npm install` projde, `--help` vypíše verby
- [x] `INSTALL.md` popisuje instalaci od čistého Debianu/Ubuntu včetně fontů
      a poznámky o nvm a PATH; `.env.example` je kompletní
- [x] `.env` ani `.storage-state.json` nejsou v gitu a `check-sensitive.py`
      je nehlásí
- [x] `login` vyrobí `storageState`; `record` s neplatnou session selže
      jasnou hláškou, ne videem přihlašovací stránky
- [x] `check` projde pilotní scénář bez záznamu a vrátí 0; při neexistujícím
      selektoru vrátí nenulový kód a řekne který krok
- [x] vložený kurzor se v `VIDEO_HEADFUL=1` pohybuje plynule a hover stavy
      naskakují ve chvíli, kdy k prvku opticky dorazí (R2)
- [x] `build` vyrobí přehratelný `.mp4` s vypáleným titulkem ve správném
      čase (obě varianty ověřeny, `x11` pak odstraněna — Z7)
- [x] `compose` se dá pustit samostatně nad existujícím `raw` +
      `timeline.json` a změna textu titulku nevyžaduje přetočení
- [x] `timeline.json` obsahuje `rect` u akcí nad prvky
- [x] validátor čitelnosti titulků hlásí příliš krátké i příliš dlouhé
- [x] **sekce „Zjištění" v tomto souboru vyplněná** — doporučená varianta
      záznamu s odůvodněním, chování synchronizace kurzoru, doporučené FPS
      a délky pauz, seznam křehkých míst (Z1–Z7)
- [x] testovací klip smazán, v repu není žádný videosoubor

## Zjištění

Provedeno na dev serveru (Ubuntu 24.04, Node 24.14, Playwright 1.62)
2026-08-27. Sedm nálezů; první čtyři jsou past, do které by spadl každý,
kdo by pipeline psal podle intuice.

### Z1 — Playwrightí `deviceScaleFactor` a CDP screencast se vylučují

Kontext s `deviceScaleFactor: 2` hlásí ve stránce `devicePixelRatio: 2`,
ale screencast posílá framy **1280×800**, ne 2560×1600. Playwright hustotu
emuluje přes `Emulation.setDeviceMetricsOverride` a screencast se řídí
velikostí emulovaného zařízení v DIP. Dvojnásobná hustota se tedy tiše
nekonala a text byl měkký.

Funkční kombinace je `viewport: null` + `--window-size=<CSS>` +
`--force-device-scale-factor=<scale>`, tedy rozměr určuje okno a hustotu
skutečný raster. Ověřeno: frame 2560×1600. Metadata framu přitom pořád
tvrdí `deviceWidth: 1280`, takže se na ně nedá spolehnout.

*Dovětek po Z8/Z9:* tohle zjištění vzniklo na headless shellu. Po přechodu
na plné Chromium platí kombinace dál, jen `--window-size` už neurčuje
viewport přesně — viz Z9 a samokalibrace v `runner.mjs`.

### Z2 — `--kiosk` ve Xvfb bez window manageru nic nedělá

Chromium fullscreen nekreslí samo, žádá o něj WM přes EWMH. V čerstvém Xvfb
žádný neběží, takže v záznamu zůstala lišta prohlížeče s taby a adresním
řádkem a vedle okna černý pruh (`--window-size` se navíc nepředávalo vůbec).
Matchbox okno roztáhne, ale `_NET_WM_STATE_FULLSCREEN` neobslouží; openbox
ano — a přesto lišta zůstala vidět. Nedořešeno, viz Z7.

### Z3 — stylopis aplikace platí i na overlay

Vložený kurzor nebyl ve videu vidět, přesto byl v DOMu a měl správný
transform. Příčina: `svg { display: block; max-width: 100% }` v
`frontend/src/styles/reset.css`. Rodičovský div kurzoru má `width: 0`
(je to jen ukotvení pro transform), takže `max-width: 100%` znamená nulu
a šipku to smáčklo na `width: 0` při zachované výšce 30 px.

Overlay je součástí stránky, takže na něj platí její CSS — a bude to platit
na každý další prvek, který do overlaye přidáme. Řešeno **shadow rootem**,
ne opravou jedné property; hranice je jediná obrana, která vydrží příští
`!important` v resetu.

### Z4 — zmenšovat výstup na 1280×800 byla chyba v zadání

Pipeline byla celou dobu v pořádku (raw 2560×1600 → výstup 1280×800), ale
video zobrazené na stránce v šířce 1280 CSS px vidí **každý návštěvník
s retina displejem jako dvojnásobný upscale**. Detail, kvůli kterému se
nahrává na 2×, se zahodil až při doručení. Poznávací znak: klip je ostrý
přesně při 50 % velikosti, tedy tam, kde nastane mapování 1:1.

`output` proto nemusí obsahovat rozměr a pak se nezmenšuje. `compose`
navíc `scale` filtr vynechá úplně, když se výstup od záznamu neliší —
`scale` na stejný rozměr není no-op, projde tím další interpolace.

### Z5 — runner točil aplikaci v angličtině

Aplikace si jazyk odvozuje z `navigator.language`
(`frontend/src/stores/language.svelte.js`, režim `auto`) a Playwright
startuje v `en-US`. Popisky navigace tedy byly „Incoming messages", ne
„Došlá pošta". Projevilo se to jako nefunkční selektor; bez toho by první
hero video vyšlo anglické a nikdo by netušil proč.

Scénář má proto `locale` (výchozí `cs-CZ`) a `timezone`
(`Europe/Prague`) — zóna se navíc bude hodit k `fixedTime` z #40.

### Z6 — scrollovat jde jen tam, kde je kurzor

Prohlížeč je dvoupanelový: střed `main.shpd-content` padne na
`.shpd-viewer__detail-panel`, který se nescrolluje. Scrollovatelný je
`.shpd-viewer__rows` (na dev DS 1313 px obsahu v 669 px okna, tedy 644 px
k dispozici). Chromium wheel event nad nescrollovatelnou oblastí **zahodí
beze slova**, což by dalo tiše nehybné video.

Runner proto před a po scrollu čte `scrollTop` nejbližšího scrollovatelného
předka pod kurzorem a při nulovém posunu končí chybou, která vypíše, co pod
kurzorem leží a kde by scroll fungoval. Stejný vzor jako u přihlašování:
hláška má nést data, ne otázku, na kterou runner umí odpovědět sám.

### Z7 — varianta záznamu: `cdp`, `x11` odstraněno

Po opravě geometrie byly obě varianty **opticky nerozlišitelné** — což je
očekávané, protože nahrávají z téhož rendereru se stejnými přepínači.
`x11` si přitom drží tři nevýhody: Xvfb a window manager jako systémové
závislosti, nedořešená lišta prohlížeče (Z2) a časová osa, která se musí
srovnávat s během ffmpegu přes `-progress`.

Jediný technický argument pro `x11` byl strop snímkové frekvence: screencast
posílá další frame až po `screencastFrameAck`, takže by v rychlém pohybu
mohl nestíhat. Měření to nepotvrzuje:

| scénář | framů | délka | průměr | špička |
|---|---|---|---|---|
| bez scrollu | 57 | 8,0 s | 7,1/s | — |
| scroll `over: 1.5` | 329 | 19,4 s | 16,9/s | 47/s |
| scroll `over: 0.4` (1250 px/s) | 269 | 17,9 s | 15,0/s | 40/s |

Průměr je nepoužitelný — framy vznikají jen při změně obrazu, takže měří
hlavně délku pauz ve scénáři. **Špička 40/s i při 1250 px/s** je s rezervou
nad výstupními 30 fps, a to je rychleji, než se v ukázce kdy bude
scrollovat. `x11` proto smazáno včetně `ffmpeg.start()` a `rawOffset`
v časové ose; kód je v historii, kdyby ho někdy vrátila potřeba plynulejších
animací.

Disk: ~7 MB PNG framů na sekundu klipu (106 MB / 17,9 s). U třicetisekundového
videa něco přes 200 MB dočasných dat. Únosné, PNG zůstává — JPEG ze
screencastu má chroma subsampling 4:2:0, které je na textu vidět.

### Z8 — headless shell nemá PDF viewer

Náhledy PDF v aplikaci (`AttachmentGrid` v režimu `full`,
`PdfViewerPanel`) jsou nativní `<iframe src="…pdf">` a spoléhají na
vestavěný viewer Chromia. Playwright ale pro headless běh standardně
používá ořezaný build `chromium-headless-shell` (nástupce starého
headless režimu) — a ten PDF viewer nemá, soubor by se místo zobrazení
stahoval. V iframe proto zůstávalo prázdné místo.

Řešení: `channel: 'chromium'` v `chromium.launch()` — plné Chromium
v novém headless režimu, stejný renderer a stejný PDF viewer, jaký má
skutečný uživatel. Ověřeno na klipu: náhled PDF se vykreslí.

### Z9 — nový headless si z okna ukrajuje výšku na chrome

S plným Chromiem dá `--window-size=1280,800` viewport **1280×713** —
nový headless modeluje okenní chrome (~87 px), který headless shell
neměl. Raw pak vyšel 2560×1426 místo 2560×1600. Delta navíc není nic,
na co by šlo spoléhat mezi verzemi.

Řešení: samokalibrace v `runner.mjs` — po startu se změří
`innerWidth/innerHeight`, okno se přes CDP `Browser.setWindowBounds`
posune o rozdíl a přeměří; při neshodě čitelná chyba. Jednotky sedí,
`--window-size` i bounds jsou CSS px. Bonus: kalibrace srovná geometrii
i při `VIDEO_HEADFUL=1`, kde si výšku ukrajuje skutečné okno.

### Z10 — mrtvá session vykreslí shell, ne přihlašovací formulář

Backend při `/refresh` vydává nový token s novým `expires_at` — ale
nový token dostane jen běžící prohlížeč. Runner ukládal session jednou
ve verbu `login` a už nikdy, takže v souboru zůstával původní token
a po TTL umřel. SPA se s mrtvou session přesto vykreslí: shell naběhne,
sidebar skončí ve stavu „Nepřihlášen" a API vrací 401. Kontrola
`isLoginScreen` nic nepozná a scénář umře timeoutem na nevinném
selektoru (`@nav-…` se bez načteného stromu nikdy neobjeví).

Řešení dvojí: (a) `close()` v `runner.mjs` ukládá `storageState` po
každém běhu, takže refreshnutý token se persistuje a `login` je potřeba
jen po dlouhé odmlce; (b) `assertSession` ověřuje přihlášení přímo
autentizovaným `GET /_ui/navigation` — 401 po jednom opakovaném pokusu
(závod s refreshem aplikace) znamená čitelnou chybu „spusť login".

### Doporučení pro další práci

- **FPS:** 30 na výstupu stačí, záznam má rezervu.
- **Pauzy:** 1,5–2,5 s po titulku je čitelné; validátor hlídá
  `max(1,2 s; znaky/15)`.
- **Rychlost scrollu:** `over` kolem 1,5 s na 500 px je klidné tempo;
  strop záznamu je nejméně 1250 px/s.
- **`data-testid` je teď hlavní blokátor skutečných scénářů.** V `frontend/src`
  není ani jeden. Navigace je serverem řízená a každá položka nese stabilní
  jazykově neutrální `id` (`viewer:core.mail.incoming`,
  `report:economy.accounting.balanceSheet`), takže
  `data-testid={`nav-${node.id}`}` na tlačítku v `NavTree.svelte` pokryje
  celou navigaci třemi řádky a automaticky i vše budoucí. Pro zbytek zlaté
  cesty platí totéž pravidlo: kde existuje serverem definované id, testid
  z něj odvodit; ručně jen tam, kde žádné není.
- Pilotní scénář zatím používá `:has-text('Došlá pošta')`, tedy Playwrightí
  selektor vázaný na český popisek. Dvojnásobný dluh, vědomý, s komentářem
  ve scénáři.
