# Testování F6a: vývojářský dashboard ve Svelte a prohlížeč výsledků evaluací

**Stav:** částečně — implementace hotová 2026-10-16 (6 commitů), zbývá ověření rekonstrukce proti syrovému výstupu (jedno volání `ai-eval --save-raw` po schválení útraty)

## Kontext / Cíl

Evaluace AI (F4, F4b, M4) a ověření zaúčtování (F3) dnes běží z CLI.
Výsledky leží v souborech `tests/eval/<sada>/*.json`, kde se na ně
nedá pořádně podívat: jak dopadl konkrétní doklad, co model vrátil
a čím se dva běhy liší. Vývojářský dashboard `/_dev/` je jeden PHP
controller s HTML vloženým v kódu, zhruba 2 000 řádků, a na prohlížeč
výsledků se nehodí.

**Cíl F6a:**

1. **Přepsat vývojářský dashboard do Svelte 5** se stejným chováním
   a beze změny API (D57). Texty budou česky a stránky dostanou
   společnou navigaci.
2. **Přidat sekci Testování s prohlížečem výsledků evaluací** (D53):
   běhy sady, detail běhu, detail dokladu s PDF vedle očekávání
   a výstupu modelu, porovnání běhů a jeden doklad napříč běhy.

F6a jen čte soubory a nic nespouští. Spouštění evaluací, správa
evaluačního zdroje dat a `dataset-verify` z dashboardu jsou F6b
(D54–D56, až po #126).

## Návaznost

- **#103** D51–D53, D57 (oddíl na konci); D36 (soubor výsledků), D37
  (metriky), D38 (porovnání), D14 (papírová projekce), D15 (tvary hodnot)
- **#126** — třída dat a účel zdroje dat, `ds-list`. Běží souběžně.
  Pokud už přidal sloupce do seznamu zdrojů dat
  (`GET /_dev/api/data-sources`), převezmi je do Svelte verze. Pokud
  přistane až po F6a, doplní je on.
- **F6b** — spouštění a evaluační zdroj dat (D54–D56)
- `docs/dev/dev-daily.md` § 1, `docs/test-corpus.md` (evaluace, soubor výsledků)

## Před implementací přečti

- `src/Api/Controller/DevDashboardController.php` — routy, JSON API,
  streamované endpointy (`[STEP]`, `[ERROR]`, `[DONE]`), stránky
  `renderHtml`, `renderLogsHtml`, `renderDsCreateHtml` a akční stránka
  pro Doctor a Upgrade
- `public/index.php` (routování `/` a `/_dev/`), `docs/operations/production.md`
  (dashboard v produkci neexistuje)
- `frontend/vite.config.js`, `frontend/src/main.js`,
  `frontend/src/components/ui/` a `styles/` (sdílené komponenty a proměnné)
- `scripts/dev-update.sh` (build frontendu)
- `modules/core/mail/src/Eval/` — formát `shpd.eval.run.v1`, logika
  `ai-eval-compare` (šum, zlepšená a zhoršená pole)
- jeden soubor výsledků (`tests/eval/cz-vat-payer/2026-10-16-baseline.json`)
  a sada (`tests/datasets/cz/vat-payer/`: `manifest.jsonc`, `mail/`,
  `expected/projection.jsonc`, `expected/messages.json`)

## 1. Architektura (D51, D57)

- **Frontend:** druhý vstup Vite ve `frontend/` (např. `frontend/dev.html`
  + `frontend/src/dev/`), build do `public/_dev/` s `base: '/_dev/'`.
  Sdílí komponenty, styly a proměnné aplikace. `npm run build` staví
  oba vstupy, takže `dev-update.sh` se nemění. Build artefakty se
  necommitují, stejně jako `public/app`.
- **Backend:** `DevDashboardController` vrací na `/_dev/…` index SPA
  (jen v režimu `development`, jinak 404 jako dnes). JSON API
  a streamované endpointy zůstávají beze změny kontraktu. Metody
  `render*Html` zmizí. API Testování v novém controlleru pod
  `/_dev/api/testing/`.
- **Produkce:** `/_dev/` dál vrací 404. Statické soubory `public/_dev/assets/*`
  bez API nic nedělají, ale ověř, že je produkční instalace buď nestaví,
  nebo že nepředstavují riziko. Výsledek zapiš do `docs/operations/production.md`.

## 2. Přepis stávajícího dashboardu (D57)

Stejné funkce, česky, se společnou navigací (Zdroje dat · Testování ·
Logy · Doctor · Upgrade všech) a s pruhem „DEVELOPMENT MODE“:

| Dnešní stránka | Ve Svelte |
|----------------|-----------|
| seznam zdrojů dat (název, vytvoření, databáze, Cron, stav; Otevřít, Logy, Upgrade; kopírování ID; automatické obnovení) | Zdroje dat — totéž |
| `+ New DS` (formulář, živý průběh `ds-create` → `ds-upgrade` → `user-create` → seed) | Nový zdroj dat — totéž, průběh čtený z `fetch` po kouscích |
| Logs (filtr úrovně, zdroje dat, fulltext, režim `tail -f`, detail s trace) | Logy — totéž |
| Doctor, Upgrade All, DS Upgrade (streamovaný výstup) | totéž |

Postup: nejdřív port beze změny chování a teprve potom Testování.
Každá dnešní funkce musí fungovat i po portu. Kontrolní seznam je
v Hotovo když.

## 3. Prohlížeč výsledků — API (D52, D53)

Zdrojem pravdy jsou soubory v checkoutu repa. Server bez `tests/`
(instalace z balíčku) sekci Testování nenabídne.

| Endpoint | Obsah |
|----------|-------|
| `GET /_dev/api/testing/datasets` | sady z `tests/datasets/*/*/manifest.jsonc`: název, titulek, země, profil, počet zpráv, počet běhů, poslední běh |
| `GET /_dev/api/testing/datasets/{sada}/runs` | běhy z `tests/eval/{sada}/*.json`: soubor, datum, varianta, model extrakce a štítků, verze promptu, opakování, shoda polí (průměr a rozpětí), doklady bez odchylky, správné částky, selhání, cena a čas na doklad |
| `GET /_dev/api/testing/runs/{sada}/{soubor}` | běh: metriky, `byVariant`, `byForm`, `deviationsByField`, stabilita, seznam zpráv se souhrnem per opakování |
| `GET /_dev/api/testing/runs/{sada}/{soubor}/messages/{messageId}?repeat=N` | doklad v běhu: strom polí projekce se stavem, očekávanou hodnotou, výstupem a podobností; štítek a výjimky řádků; klasifikace; cena a čas |
| `GET /_dev/api/testing/datasets/{sada}/messages/{messageId}/pdf` | PDF zprávy ze sady (`mail/*.files/`) |
| `GET /_dev/api/testing/compare/{sada}?a=…&b=…` | porovnání dvou běhů touž logikou jako `ai-eval-compare` (šum, zlepšená a zhoršená pole), navíc per doklad počet odchylek A vs. B |
| `GET /_dev/api/testing/datasets/{sada}/messages/{messageId}/runs` | doklad napříč běhy sady: počet odchylek per běh a opakování |

- **Rekonstrukce výstupu:** výstup modelu = očekávání s odchylkami
  z běhu. U `missing`, `extra` a `wrong` je výstup hodnota z odchylky,
  u shody hodnota očekávání. Strom se skládá podle
  `expected/projection.jsonc` sady, řádky po pozicích.
- **Bezpečnost:** sada, běh a zpráva se vybírají jen z výpisu (žádné
  skládání cest z parametrů), PDF jen z adresáře zprávy v sadě. Test
  na path traversal.
- Velký soubor výsledků (~200 kB) se čte jednou na požadavek, cache není
  potřeba.

## 4. Soubor výsledků — výstup u shody po normalizaci (D53)

Dnes se u `matchNormalized` ukládá jen cesta, takže skutečný tvar výstupu
(IBAN s mezerami, PSČ s mezerou) prohlížeč neukáže. Nově `ai-eval` ukládá
u volání `normalized` jako `{cesta, výstup}` místo samotné cesty.
Změna je aditivní: čtení (prohlížeč, `ai-eval-compare`) přijme
oba tvary a staré běhy ukážou u shody po normalizaci očekávanou hodnotu
se štítkem. `format` zůstává `shpd.eval.run.v1`, změna jde do
`docs/test-corpus.md`.

## 5. Prohlížeč výsledků — obrazovky

1. **Sady:** tabulka sad, klik vede na běhy.
2. **Běhy sady:** tabulka (řaditelná) se sloupci z API. Zaškrtnutí dvou
   běhů vede na porovnání.
3. **Detail běhu:**
   - metriky po skupinách s rozpětím opakování;
   - rozpad po variantách korpusu a podobách;
   - nejčastější odchylky po polích;
   - stabilita (věcná a formátová);
   - seznam dokladů (počet odchylek per opakování, stav volání, cena),
     z něj klik do detailu dokladu.
4. **Detail dokladu:**
   - vlevo PDF;
   - vpravo pole projekce ve skupinách (hlavička, dodavatel, odběratel,
     DPH, platba, řádky, rekapitulace, součty) se sloupci očekáváno,
     výstup a stav (barevně: shoda, shoda po normalizaci, chybí, navíc,
     špatně) a s podobností u volného textu;
   - řádky jako tabulka, kde každá buňka nese očekávání a výstup;
   - štítek dokladu a výjimky řádků, klasifikace zprávy;
   - přepínač opakování a filtr „jen odchylky“;
   - odkaz „tentýž doklad v jiných bězích“.
5. **Porovnání běhů:** metriky vedle sebe s rozdílem a značkou „v rámci
   šumu“, pole zlepšená a zhoršená, doklady s rozdílným počtem odchylek.
   Klik otevře detail dokladu v obou bězích vedle sebe.
6. **Doklad napříč běhy:** matice běh × opakování s počtem odchylek,
   klik vede do detailu.

Výkon: sada má 20–60 zpráv a běh do 200 kB, nic nestránkovat.

## Kroky

1. Architektura: druhý vstup Vite, build, servírování indexu SPA, navigace.
2. Port stávajících stránek (§ 2) a ověření kontrolním seznamem.
3. API Testování (§ 3) + PHPUnit (výpis, rekonstrukce výstupu, porovnání,
   path traversal).
4. Výstup u shody po normalizaci v `ai-eval` (§ 4) + testy čtení obou tvarů.
5. Obrazovky prohlížeče (§ 5).
6. Ověření nad existujícími běhy v `tests/eval/cz-vat-payer/` (11 běhů).
7. Dokumentace a hlavička tasku.

## Hotovo když

- [x] `npm run build` staví aplikaci i dashboard; `dev-update.sh` beze změny funguje
- [x] port: seznam zdrojů dat (sloupce, Otevřít, Logy, Upgrade, kopírování ID,
      automatické obnovení), nový zdroj dat s živým průběhem až do odkazu na nový
      zdroj dat, logy (filtry, `tail -f`, detail), Doctor, Upgrade všech,
      Upgrade jednoho — vše funguje jako dřív, česky
- [x] produkce: `/_dev/` 404, posouzení statických souborů zapsané
- [x] PHPUnit API Testování zelené (úzký `--filter`); `ai-eval` zapisuje
      `{cesta, výstup}` u shody po normalizaci a čtení přijme oba tvary
- [x] prohlížeč nad 13 existujícími běhy: běhy sady, detail běhu, detail
      dokladu s PDF (aspoň dlouhý doklad, účtenka a faktura v EUR), porovnání
      `baseline` (4.6) vs. `baseline` po přepnutí, doklad napříč běhy
- [ ] rekonstruovaný výstup u jednoho dokladu ověřený proti syrovému výstupu
      (`ai-eval --only … --save-raw`, jedno volání, po schválení útraty;
      nebo proti existujícímu uloženému syrovému výstupu, pokud je)
- [x] `docs/dev/dev-daily.md` § 1 (nový dashboard, Testování),
      `docs/test-corpus.md` (prohlížeč, tvar `normalized`)
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Hotovo 2026-10-16 — co je jinak než v zadání

Šest commitů na `stable` (druhý vstup Vite → servírování SPA → tvar
`normalized` → API Testování → obrazovky → dokumentace). Ověřeno
v headless Chromiu: všech šest stránek portu vč. založení zdroje dat se
seedem až po odkaz (smoke zdroj dat „F6a smoke“, lze smazat),
Doctor a Upgrade jednoho zdroje; deset obrazovek Testování a interakce
(filtr, přepínač opakování, výběr dvou běhů). PDF v `<iframe>` headless
nevykreslí (endpoint vrací `application/pdf`) — vzhled PDF vedle polí
zbývá na ruční proklik. Upgrade všech přes UI nespuštěný (mutace
reálných kopií; stejná cesta kódu jako Upgrade jednoho).

- **`normalized` nese `{path, actual, similarity?}`** — klíč `actual`
  jako u odchylek, ne `output`.
- **Skupiny polí v detailu dokladu = `EvalFieldGroups`** (shodné
  s metrikami): platba a poznámka patří do hlavičky; řádky, rekapitulace
  a výjimky štítků jsou tabulky (buňka = očekávání + výstup + barva),
  výjimky klíčované `rowIndex`.
- **Porovnání dvou běhů** má nově i doklady vedle sebe
  (`EvalRunComparison::toArray()['messages']`: průměr odchylek na úspěšné
  volání a selhání v A a B); CLI výpis se nemění. Při výběru dvou běhů
  v tabulce je starší A.
- **Detail dokladu ve dvou bězích** (`?b=<soubor>`) = jedna tabulka
  s výstupem A i B u každého pole, ne dvě stránky vedle sebe.
- **Nový `GET /_dev/api/info`** (hostname, adresář zdrojů dat, dostupnost
  Testování) — údaje, které dřív PHP vkládalo do HTML.
- Stránka `/_dev/ds-upgrade/` bez platného `?ds=` už nevrací 400/404
  (SPA ji ukáže s chybou); validaci má klient a `POST /_dev/api/ds-upgrade`.
- Bez sestaveného frontendu vrací `/_dev/` 503 s návodem; neznámá cesta
  pod `/_dev/` dál 404 JSON.
- Stream, který skončí bez `[DONE]` / `[ERROR]`, ukáže neutrální hlášku
  (dřív nic); Logy z řádku zdroje dat se otevírají ve stejném tabu.
- Produkce: `npm run build` staví i `public/_dev/` (inertní JS/CSS, API
  404); `docs/nginx/production.conf` má navíc `location ^~ /_dev/ { return 404; }`.
- `tests/eval/cz-vat-payer/` má běhy nad dvěma verzemi sady — prohlížeč
  je označí „jiná verze sady“ (očekávání v detailu jsou z aktuální sady,
  u dlouhého dokladu pak nesedí počty); porovnání napříč verzemi API
  odmítne (409).
- Roadmapa F6a výslovně neuváděla — doplněno k položce AI modely v M4.

## Pasti

- **Port nemění API.** Streamované endpointy a JSON zůstávají, jinak se
  rozbijí skripty a návody.
- **Cesty jen z výpisu.** API pracuje se soubory na disku a parametr URL
  nesmí vést mimo `tests/datasets` a `tests/eval`.
- **F6a nic nespouští.** Jediné volání AI je ověření rekonstrukce
  (jedno volání, po schválení).
- **Kolize s #126** v seznamu zdrojů dat: převzít jeho sloupce, nepřepsat je.

## Rozhodnutí k designu (potvrzená)

- ✓ **D51 — Testování ve Svelte 5** jako část vývojářského dashboardu
  `/_dev/`, JSON API pod `/_dev/api/testing/`, ochrana jako dnes
  (development mód, důvěryhodná síť).
- ✓ **D52 — Zdroj pravdy jsou soubory v checkoutu repa** (sady, varianty,
  výsledky). Dashboard nové výsledky a varianty zapisuje do pracovního
  stromu (F6b) a commit dělá člověk.
- ✓ **D53 — Prohlížeč výsledků:** běhy, detail běhu, detail dokladu
  s PDF vedle očekávání a výstupu, porovnání, doklad napříč běhy. Výstup
  se skládá z očekávání a odchylek. U shody po normalizaci se nově ukládá
  i výstupní hodnota.
- ✓ **D57 — Celý vývojářský dashboard do Svelte v F6a**, beze změny API,
  česky, se společnou navigací. Staré HTML šablony v PHP zmizí.
