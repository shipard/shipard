# Video: `data-testid` a selektorová zkratka `@` (#48)

**Stav:** hotovo — testidy zlaté cesty + `@` zkratka + validace,
pilotní klip přetočen proti 4l3j (2026-08-28). Navíc oproti zadání:
`app-shell` na kořenech všech tří shellů (starý `.shpd-shell` seděl
jen na SidebarShell) a `sidebar` — bez nich by scénář nesplnil
„žádné CSS třídy". Pozn.: scénář předpokládá shell `sidebar`;
uživatel s override `classic`/`wild` `@sidebar` nenajde.

> Pilotní scénář `demo/scenarios/spike-dashboard.jsonc` byl zrušen (#103 F7b D129, `tasks/testing-phase7b.md`); odkazy na něj níže jsou historie. Videa běží nad demo sadou přes `shpd-ds video-run` a vývojářský dashboard.

Navazuje na `tasks/video-runner-spike.md` (Zjištění + Doporučení). Rozhodnuto
v chatu, zapsáno v #48:

- **D11 — odvozování:** kde existuje serverem definované, jazykově neutrální
  id, testid se z něj odvozuje mechanicky (`nav-{node.id}`). Ručně pojmenované
  testidy jen tam, kde žádné serverové id není — a jen na zlaté cestě prvního
  videa (dashboard + došlá pošta), ne plošně.
- **D12 — jmenná konvence:** `oblast-konkretum` (`nav-…`, `viewer-rows`,
  `login-name`). Testid identifikuje prvek, ne cestu k němu — žádné hierarchie
  v názvu. Singletony mají unikátní testid; opakované prvky (řádek, karta)
  nesou typový marker (`viewer-row`, `feed-card`) a runner bere `.first()`
  / n-tý.
- **D13 — zkratka ve scénáři:** selektor začínající `@` se překládá na
  `[data-testid="…"]`. Jen přesná shoda, žádný Playwrightí dialekt; plné
  CSS zůstává povolené pro okrajové případy.
- **D14 — testidy zůstávají v produkčním buildu.** Nestripovat: videa se
  točí proti skutečným buildům a tytéž testidy později poslouží smoke E2E.

## Před implementací přečti

- `tasks/video-runner-spike.md` — sekce Zjištění (Z5 jazyk, Z6 scroll)
  a Doporučení pro další práci
- `frontend/src/components/chrome/NavTree.svelte` — strom je serverem řízený,
  leaf tlačítka jsou na **třech** místech (root leaf, leaf ve skupině, leaf
  v podskupině), group/subgroup header na dvou
- `frontend/src/components/viewer/Viewer.svelte` (`.shpd-viewer__rows`,
  panely), `ViewerRow.svelte`
- `frontend/src/components/dashboard/Dashboard.svelte`, `Feed.svelte`,
  `FeedCard.svelte`
- `frontend/src/components/auth/LoginScreen.svelte` (pole mají `id`),
  `frontend/src/components/chrome/CommandPalette.svelte`
- `tools/video-runner/src/scenario.mjs` (`SELECTOR_VERBS`, `validateStep`),
  `interpret.mjs` (řádek ~38, jediné místo, kde se selektor předává
  `page.locator`), `shipard.mjs` (`SELECTORS`)
- `demo/scenarios/spike-dashboard.jsonc`

## Rozsah

### 1. Frontend — testidy zlaté cesty

Navigace (odvozené ze serverových id):

| prvek | testid |
|---|---|
| leaf tlačítko (všechna tři místa v NavTree) | `nav-{node.id}` |
| group / subgroup header | `navgroup-{group.id}` |

Ruční (jen zlatá cesta):

| komponenta | prvek | testid |
|---|---|---|
| Viewer | `.shpd-viewer__rows` | `viewer-rows` |
| ViewerRow | kořenový element řádku | `viewer-row` |
| Viewer | detailní panel (`.shpd-viewer__detail-panel`) | `viewer-detail` |
| Viewer | vyhledávací input | `viewer-search` |
| Dashboard | kořen (`.shpd-dashboard`) | `dashboard` |
| Feed | kontejner (`.shpd-feed`) | `feed` |
| FeedCard | kořen karty | `feed-card` |
| LoginScreen | jméno / heslo / chyba | `login-name`, `login-password`, `login-error` |
| CommandPalette | dialog / input | `palette`, `palette-input` |

Konvence (D11+D12+D14) se zapíše jako krátká sekce do dokumentace frontendu;
pokud vhodný soubor neexistuje, založit `docs/frontend-testids.md` a odkázat
z `docs/README.md`.

### 2. Runner — zkratka `@`

- Překlad na jednom místě (nová malá funkce, např. `src/selectors.mjs`):
  `@name` → `[data-testid="name"]`. Použije se v `interpret.mjs` pro všechny
  `SELECTOR_VERBS`.
- Validátor ve `scenario.mjs`: selektor začínající `@` musí odpovídat
  `^@[A-Za-z0-9_.:-]+$` — překlep se pozná při `check`, ne až uprostřed
  natáčení.
- `shipard.mjs` přejde na `@login-name`, `@login-password`, `@login-error`
  (přes tentýž překlad); komentář o dluhu smazat.
- `timeline.json` nese selektor tak, jak byl ve scénáři (`@…`), ať jde
  zpětně dohledat krok.

### 3. Scénář a dokumentace

- `demo/scenarios/spike-dashboard.jsonc` přepsat na `@` selektory
  (`@nav-viewer:core.mail.incoming`, `@viewer-rows`); komentáře o dvojnásobném
  dluhu odstranit. `:has-text()` ze scénáře zmizí úplně.
- `tools/video-runner/README.md`: sekce o selektorech (`@` primárně, CSS
  výjimečně), odstavec Známé dluhy aktualizovat.

## Testy a ověření

- Unit testy překladu a validace `@` selektorů (vedle stávajících
  `tests/scenario.test.mjs`).
- `video-runner check demo/scenarios/spike-dashboard.jsonc` projde.
- Přetočit pilotní klip (`build`) proti dev instanci s nasazenými testidy —
  end-to-end důkaz, že `@` selektory fungují včetně loginu.

## Commity (po logických krocích)

1. `frontend: data-testid pro navigaci a zlatou cestu + konvence (#48)`
2. `video-runner: selektorová zkratka @data-testid + validace (#48)`
3. `video-runner: pilotní scénář na @ selektorech, README (#48)`

## Hotovo když

- [x] `grep -r "data-testid" frontend/src` nachází navigaci, viewer,
      dashboard, login i paletu; konvence je v dokumentaci
- [x] `@` selektor projde překladem, validátor odmítne `@ne platný!`
- [x] `shipard.mjs` ani scénář neobsahují `:has-text` a CSS třídy
- [x] testy runneru zelené, `check` na pilotním scénáři projde
- [x] pilotní klip přetočen a vizuálně shodný s předchozím
