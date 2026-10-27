# video-runner

Výroba videí z uživatelského rozhraní podle scénáře: Playwright projede
zapsané kroky, vloží do stránky kurzor a ffmpeg z toho složí `.mp4`
s vypálenými titulky. Bez zvuku, bez ruční práce, regenerovatelné jedním
příkazem.

Runner o Shipardu neví nic — Shipard-specifické jsou jen scénáře
v [`demo/scenarios/`](../../demo/scenarios/) a verb `login`. Běžná cesta
k videu vede přes `shpd-ds video-run` (#103 F7b): ten naseeduje datovou
sadu scénáře, připraví session, nastaví runneru proměnné prostředí
a přečte si jeho `result.json`. Runner sám je jen podproces, který se dá
stejně dobře pustit z terminálu (viz Ruční použití mimo Shipard).

Rozhodnutí, ze kterých návrh vychází, jsou v issue **shipard/shipard#48**
a v tasku [`tasks/video-runner-spike.md`](../../tasks/video-runner-spike.md);
napojení na datové sady, pevný čas a dashboard řeší #103 D119–D129
([`tasks/testing-phase7b.md`](../../tasks/testing-phase7b.md)).

> **Stav: pilotní spike.** Implementovaná je podmnožina — chybí zoomy
> a rámečky v postprodukci, dabing, verby `fill` / `press` / `scrollTo` /
> `raw`, WebM a poster.

---

## Instalace

Viz [`INSTALL.md`](INSTALL.md). Zkráceně: `apt install ffmpeg fonts-liberation
fonts-noto-core`, Node 24+, `npm install`, `npx playwright install chromium`;
pro ruční použití ještě `cp .env.example .env` a vyplnit.

---

## Konfigurace

Runner čte proměnné prostředí. Doplňkově načte `tools/video-runner/.env`,
**pokud existuje** — proměnné už nastavené v prostředí mají přednost,
takže jednorázové přebití jde prefixem příkazu (`VIDEO_HEADFUL=1 node
bin/…`). Chybějící `.env` není chyba; chybí-li povinná položka, řekne to
hláška u ní.

| Proměnná | Význam | Výchozí |
|----------|--------|---------|
| `SHPD_BASE_URL` | Adresa instance včetně ID datového zdroje, bez koncového lomítka. Potřebují `login`, `check`, `record`, `build`. | povinná |
| `SHPD_LOGIN`, `SHPD_PASSWORD` | Přihlášení formulářem — čte jen verb `login`. | povinné jen pro `login` |
| `SHPD_STORAGE_STATE` | Uložená session (relativně k `tools/video-runner/`). Stejně citlivá jako heslo. | `.storage-state.json` |
| `VIDEO_WORK_DIR` | Artefakty per scénář: `raw.mp4`, `timeline.json`, `result.json`. | `./.work` |
| `VIDEO_OUT_DIR` | Hotová videa `<id>.mp4`. | `./out` |
| `VIDEO_HEADFUL` | `1` = viditelný prohlížeč (ladění `check`). | `0` |
| `VIDEO_FIXED_TIME` | Pevný čas v prohlížeči — ISO 8601 s posunem, např. `2026-10-27T09:30:00+01:00`. `Date` ve stránce na něm stojí od prvního načtení (`context.clock.setFixedTime`, D120). Prázdné = skutečný čas. Nastavuje `shpd-ds video-run` z kotvy sady a `clockTime` scénáře. | prázdné |
| `VIDEO_HOST_RESOLVER_RULES` | Připíchnutá adresa hostitele pro Chromium (D97), syntaxe `--host-resolver-rules`, např. `MAP demo.example.dev 127.0.0.1`. Nastavuje `shpd-ds video-run`. | prázdné |

Relativní cesty se počítají od `tools/video-runner/`, ne od aktuálního
adresáře.

---

## Pipeline

```
scénář (.jsonc)
  │  ① interpret     průchod kroků Playwrightem
  │  ② overlay       kurzor, klik, highlight (injektované do stránky)
  ├────────────────► raw.mp4  +  timeline.json
  │  ③ kompozitor    ffmpeg: titulky, škálování, výstupní formát
  └────────────────► out/<id>.mp4
```

Podstatné je, co z toho plyne: **přepsat titulek neznamená přetočit
video.** Text titulku žije v `timeline.json`, ne v obraze — stačí pustit
`compose` nad už uloženým `raw.mp4`. Rozdíl mezi dvěma sekundami a dvěma
minutami při každém ladění formulace.

---

## Verby

| Verb | Co dělá |
|------|---------|
| `login` | Přihlásí se přes formulář a uloží session do `SHPD_STORAGE_STATE`. |
| `check` | Projede scénář bez záznamu. Nenulový exit = scénář nesedí na aplikaci; hláška řekne krok a selektor. Zároveň smoke E2E. |
| `record` | ① + ② → `raw.mp4` + `timeline.json` do `VIDEO_WORK_DIR/<id>/`. |
| `compose` | ③ nad existujícími artefakty → `VIDEO_OUT_DIR/<id>.mp4`. Cestou zkontroluje čitelnost titulků. |
| `build` | `record` + `compose`. |

```bash
node bin/video-runner.mjs <verb> [scénář]
node bin/video-runner.mjs --help
```

Ostatní verby než `login` session jen čtou. Když vyprší, `record`
skončí hláškou „session neplatná, spusť `login`" — nikdy nesmí vzniknout
video přihlašovací stránky.

### Výsledek běhu — `result.json`

`check`, `record`, `compose` i `build` zapíší po doběhnutí
`VIDEO_WORK_DIR/<id>/result.json` — **při úspěchu i při selhání**. Starý
soubor se před během smaže, takže pád uprostřed nenechá za sebou výsledek
minulého běhu. Čte ho `shpd-ds video-run`; člověk v něm najde to samé, co
řekla hláška, jen strojově:

```json
{
  "verb": "build",
  "scenario": "s1-prijata-faktura",
  "status": "failed",
  "durationMs": 18342,
  "error": {
    "message": "krok #7 (click @card-action-review): prvek se do 15 s neobjevil.",
    "hint": "Sedí selektor na aktuální aplikaci? `@name` hledá [data-testid=\"name\"], přesnou shodu.",
    "step": { "index": 7, "verb": "click", "value": "@card-action-review" }
  }
}
```

| Pole | Význam |
|------|--------|
| `verb` | `check` / `record` / `compose` / `build` — `build` zapisuje jeden soubor za celý běh. |
| `scenario` | Id scénáře. |
| `status` | `ok` nebo `failed`. |
| `durationMs` | Od startu verbu (po načtení scénáře) do zápisu výsledku. |
| `error` | Jen u `failed`: `message` (věta z hlášky), volitelně `hint` (druhý řádek hlášky) a `step` — `index` od jedničky, `verb` kroku a `value` (u akcí nad prvky selektor), když scénář selhal na konkrétním kroku. |
| `video` | Jen u `compose` / `build` po úspěchu: `path` = absolutní cesta k hotovému `.mp4`. |

Scénář, který neprojde validací (chybí `dataset`, překlep ve verbu),
`result.json` nemá — bez platného id není kam ho zapsat; hláška jde na
stderr a exit je nenulový.

### Jak vzniká obraz

CDP `Page.startScreencast`: framy s timestampy, ffmpeg je složí přes concat
na konstantní FPS. Běží headless, žádná systémová závislost navíc.

Klíčová vlastnost je, že **Chrome posílá framy jen při změně obrazu**.
Statická pasáž tedy dá jeden frame s dlouhou dobou trvání místo stovky
identických — proto tahle cesta neseká tam, kde Playwrightí `recordVideo`
ano. Průměrná snímková frekvence za celý klip je z téhož důvodu nesmyslné
číslo; ve statistice po záznamu je proto i **špička**, tedy nejvyšší počet
framů v jednosekundovém okně. To je jediné číslo, které odpovídá na otázku
„stíhá záznam v pohybu".

Zvažovala se ještě varianta s headed Chromiem ve Xvfb a `ffmpeg -f x11grab`
(konstantní FPS nativně). Ve spike prohrála — měla stejný obraz za cenu dvou
systémových závislostí a nedala se v ní schovat lišta prohlížeče. Důvody
jsou v `tasks/video-runner-spike.md` a v shipard/shipard#48.

---

## Scénář

JSONC v [`demo/scenarios/`](../../demo/scenarios/).

```jsonc
{
  "id": "s1-prijata-faktura",
  "dataset": "it-servis",          // sada v demo/datasets/ — povinné
  "clockTime": "09:30",            // čas dne v prohlížeči — volitelné, toto je výchozí
  "capture": { "w": 2560, "h": 1600, "scale": 2 },
  "output":  { "fps": 30 },
  "steps": [
    { "goto": "/app/" },
    { "waitFor": "@app-shell", "pause": 0.8 },
    { "caption": "Klient přeposlal fakturu e-mailem", "pause": 0.3 },
    { "hover": "@feed-card", "pause": 1.2 },
    { "caption": null }
  ]
}
```

`dataset` je název složky v `demo/datasets/` (malá písmena, číslice,
pomlčky) a `clockTime` čas dne `HH:MM` v časové zóně scénáře. **Runner
žádné z nich nepoužívá** — validuje jen tvar, aby překlep spadl už při
`check`. Čte je `shpd-ds video-run`: sadu naseeduje (a ověří, že
existuje) a z kotvy sady a `clockTime` složí `VIDEO_FIXED_TIME`.

`locale` (výchozí `cs-CZ`) a `timezone` (výchozí `Europe/Prague`) řídí
jazyk a časovou zónu prohlížeče — aplikace si jazyk odvozuje
z `navigator.language`, takže bez `cs-CZ` by video vyšlo anglicky (D121).

`capture.w`/`capture.h` jsou rozměry **rawu v pixelech**, `scale` je
`deviceScaleFactor`. CSS viewport z toho vychází jako `w/scale × h/scale`
— tady tedy 1280×800 při dvojnásobné hustotě.

`output` **nemusí obsahovat rozměr** a pak se nezmenšuje: video vyjde
v rozlišení záznamu. Zmenšit na 1280×800 znamenalo zahodit přesně ten
detail, kvůli kterému se nahrává na dvojnásobnou hustotu — takové video
vidí každý návštěvník s retina displejem jako dvojnásobný upscale.
Zmenšit se dá vždycky, zpátky se to nedodělá.

Geometrie **nesmí** používat Playwrightí `deviceScaleFactor`: ten hustotu
jen emuluje a CDP screencast pak posílá framy ve velikosti emulovaného
zařízení v DIP, tedy 1×. Skutečný raster dělá teprve
`--force-device-scale-factor` s `viewport: null`.

### Verby scénáře

| Verb | Význam |
|------|--------|
| `goto` | Navigace na cestu relativní k `SHPD_BASE_URL`. |
| `waitFor` | Počká na prvek. Timeout = selhání celého běhu. |
| `hover` | Přejezd kurzoru nad prvek. |
| `click` | Přejezd + kliknutí. |
| `scroll` | Posun v CSS px (kladný dolů) za dobu `over`. Scrolluje tam, kde je kurzor. |
| `caption` | Nastaví titulek. `null` ho sundá. |
| `highlight` | Rámeček kolem prvku po dobu `for` (sekundy). |
| `pause` | Čas. |

**`caption` neblokuje, `pause` je jediný spotřebič času** a smí být
modifikátorem kteréhokoli kroku (`{ "click": "…", "pause": 1.5 }`).
Titulek tak přirozeně přežije několik akcí. Doba přejezdu kurzoru se do
`pause` nepočítá — je to vlastnost akce, ne vyprávění; výchozí 0,6 s,
přebitelná modifikátorem `travel`.

**Totéž platí pro `scroll`:** doba `over` se do `pause` nepočítá. Easing
řídí runner, ne prohlížeč — jeden velký `wheel` delta by animaci nechal na
kompozitoru Chromia, který se může chovat jinak v jiné verzi. Scrolluje se
tam, kde je kurzor, takže `scroll` musí následovat po `hover` nebo `click`
nad obsahem; když se nic neposune, je to chyba, ne tiše nehybné video.

**Všechny časy jsou v sekundách** — `pause`, `travel`, `for` i `over`. Dvě
jednotky v jednom souboru by spolehlivě vyráběly překlepy typu
`"travel": 1` v domnění, že jde o vteřinu.

### Selektory

Selektor začínající `@` je zkratka za `data-testid`: `@viewer-rows`
znamená `[data-testid="viewer-rows"]`. Jen přesná shoda, žádný Playwrightí
dialekt — co smí být za zavináčem, hlídá validátor už při `check`
(`A-Z a-z 0-9 _ . : -`). Testidy aplikace a jejich konvence popisuje
`docs/frontend.md` § 9; navigace je má odvozené ze serverových id
(`@nav-viewer:core.mail.incoming`).

**`@` je primární volba.** Plné CSS zůstává povolené pro okrajové případy,
na které testid není — s vědomím, že třídy se mění s refaktoringem.
`timeline.json` nese selektor tak, jak byl ve scénáři, takže krok jde
zpětně dohledat i po přejmenování tříd.

### Kurzor se synchronizuje sám

Overlay nemá vlastní animaci. `page.mouse.move()` dispatchuje **skutečné
DOM eventy** a vložený kurzor se polohuje výhradně z nich — takže nemůže
dorazit dřív ani později než hover stav, protože oboje spouští tentýž
event. Runner dělá jen easing smyčku, kurzor jede za ní.

Rozejití vizuálního kurzoru a syntetické myši bylo hlavní technické
riziko v #48; tímhle mizí konstrukcí, ne laděním časování.

### Pevný čas v prohlížeči

S nastaveným `VIDEO_FIXED_TIME` stojí `Date` ve stránce na zadaném
okamžiku od prvního načtení (nastavuje se hned po vzniku kontextu, před
ověřením session), takže „dnes" a „před chvílí" ve videu nezávisí na tom,
kdy se točilo. Zmrazený je **jen prohlížeč** — server počítá dnešek sám;
proto `video-run` dává za datum skutečný dnešek a mění jen čas dne.
Runner nastavení zaloguje řádkem `čas: <ISO>`.

### Validátor čitelnosti

`compose` varuje u titulku, který je vidět kratší dobu než
`max(1,2 s; znaky/15)`, a u titulku delšího než 90 znaků. Kontroluje se
**skutečná doba zobrazení** — titulek zmizí, až ho vystřídá další, což ze
samotného scénáře vidět není. Varování běh nezastaví.

---

## Ruční použití mimo Shipard

Runner nepotřebuje `shpd-ds`: stačí instance, na kterou se dá přihlásit
formulářem. Vyplň `.env` (`SHPD_BASE_URL`, `SHPD_LOGIN`, `SHPD_PASSWORD`),
pusť `login` a pak `check` / `build` nad scénářem — `login` i `.env`
zůstávají právě pro tenhle případ. Co ruční cesta nedělá: nenaseeduje
datovou sadu scénáře (`dataset` jen projde validací), nenastaví
`VIDEO_FIXED_TIME` ani mapování hostitele. Video pak ukazuje, co na
instanci zrovna je, se skutečným časem — pro ladění scénáře to stačí, pro
video na web ne.

---

## Známé dluhy

- **Testidy pokrývají jen zlatou cestu prvního videa.** Login, shell,
  navigace, dashboard + feed, viewer, paleta — viz `docs/frontend.md` § 9.
  Scénář mimo ni si musí vypomoct CSS (povolené, viz Selektory), nebo
  testid nejdřív doplnit.

Datové sady, pevný čas a jazyk, které tu dřív stály jako dluhy, řeší
`shpd-ds video-run` (#103 F7b): sadu ze scénáře naseeduje, čas předá
`VIDEO_FIXED_TIME`, jazyk jde z `locale` scénáře.

---

## Testy

```bash
npm test
```

Pokrývají to, co jde ověřit bez prohlížeče:

| Soubor | Co testuje |
|--------|------------|
| `tests/jsonc.test.mjs` | Parser JSONC — komentáře uvnitř řetězců, zbytkové čárky, zachování čísel řádků. |
| `tests/scenario.test.mjs` | Validace scénáře — překlep v názvu verbu, dva verby v kroku, modifikátor u verbu, kam nepatří, `@` selektory, povinný `dataset`, `clockTime`, `locale` / `timezone`. |
| `tests/selectors.test.mjs` | Překlad `@name` na atributový selektor a povolené znaky. |
| `tests/captions.test.mjs` | Titulky z časové osy, kontrola čitelnosti, zápis ASS. |
| `tests/config.test.mjs` | `VIDEO_FIXED_TIME` (prázdné, platné, neplatné) a `VIDEO_HOST_RESOLVER_RULES` z prostředí. |
| `tests/result.test.mjs` | Tvar `result.json` při úspěchu i selhání, zápis, smazání starého výsledku a propuštění chyby. |

Průchod scénáře se testuje verbem `check` proti živé instanci.

---

## Artefakty a citlivost

`.env`, `.storage-state.json`, `.work/` a `out/` jsou v kořenovém
`.gitignore`. Uložená session je stejně citlivá jako heslo. Do repozitáře
nepatří žádný videosoubor — videa se generují, ne verzují. V `.work/<id>/`
leží vedle `raw.mp4` a `timeline.json` i `result.json` posledního běhu.
