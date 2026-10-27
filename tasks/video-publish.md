# Video: publikace přes HTTP — galerie v `out/` (#48)

**Stav:** zrušeno — galerie přes nginx a `gallery.mjs` odstraněny 2026-10-27 (#103 F7b D122, D129); videa se prohlížejí a stahují v oddílu **Videa** vývojářského dashboardu (`tasks/testing-phase7b.md`). Text níže je historie D15–D17.

Rozhodnuto v chatu, zapsáno v #48:

- **D15 — publikace přes HTTP:** `out/` servírovaný nginx na dev serveru
  **s basic auth od prvního dne**, galerie `index.html` generovaná runnerem.
  Žádné scp, žádné lokální skripty — preview je URL v prohlížeči, download
  je „Uložit jako".
- **D16 — spouštění zůstává CLI** (SSH / remote-dev-bridge). Webový trigger
  („přegenerovat" tlačítko) je připravený další krok, implementuje se až na
  první reálnou bolest, ne teď.
- **D17 — obsah videí je interní, dokud nestojí na #40:** videa nad dev DS
  můžou obsahovat reálná jména a částky. Na veřejný web smí až klipy točené
  nad seedovaným deterministickým datasetem. Basic auth proto není dočasná
  berlička, ale podmínka existence té URL.

## Před implementací přečti

- `tools/video-runner/src/verbs/compose.mjs` — `composeScenario` zná
  `config.outDir` a je jediné místo, kde vzniká výstup
- `tools/video-runner/src/config.mjs`, `.env.example` (`VIDEO_OUT_DIR`)
- `tools/video-runner/INSTALL.md` — sem přibude sekce o nginx

## Rozsah

### 1. Galerie

- `composeScenario` po zápisu videa přegeneruje `out/index.html`: projde
  `out/*.mp4`, pro každé video `<video controls preload="metadata">`,
  název scénáře, velikost, čas vzniku (nejnovější nahoře). Čistá statická
  šablona bez závislostí, generátor jako malý modul (např.
  `src/gallery.mjs`), ať jde testovat bez ffmpegu.
- Galerie je odvozenina obsahu `out/` — negeneruje se z paměti běhu, ale
  ze skenu adresáře, takže ruční smazání souboru ji při dalším `compose`
  srovná.
- Česky, minimální inline CSS, žádný JS.

### 2. nginx na dev serveru (ruční krok, mimo repo)

- Vhost (návrh: `media.shpd.dev`, finální jméno určí David) →
  `root` na `VIDEO_OUT_DIR`, `autoindex off`, basic auth přes `htpasswd`.
- Do `INSTALL.md` sekce „Publikace": vzorový server-block, příkaz na
  založení `htpasswd`, upozornění na D17.
- Samotné nasazení configu a certifikátu udělá David (mutace na serveru);
  PRD dodává jen předpis.

## Testy a ověření

- Unit test generátoru galerie (vstup: seznam souborů s metadaty, výstup:
  HTML obsahuje očekávané prvky; prázdný adresář → smysluplná prázdná
  stránka).
- Po nasazení nginx: galerie dostupná přes HTTPS, bez hesla vrací 401,
  video se přehraje v prohlížeči.

## Commity

1. `video-runner: galerie out/index.html po compose (#48)`
2. `video-runner: INSTALL — publikace přes nginx s basic auth (#48)`

## Hotovo když

- [x] `compose` po každém běhu přegeneruje `out/index.html` ze skenu adresáře
- [x] test generátoru zelený
- [x] `INSTALL.md` obsahuje vzorový nginx config s basic auth a poznámku D17
- [ ] (po ručním nasazení) galerie běží za auth a Anna otevře video bez
      terminálu
