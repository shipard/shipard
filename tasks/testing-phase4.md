# Testování F4: evaluace AI analýzy nad datovými sadami

**Stav:** naplánováno — design schválen 2026-10-15 (#103 D34–D41), čeká na implementaci

## Kontext / Cíl

F1–F3 (#103) daly korpus dokladů s očekávaným výsledkem, datové sady
a porovnávač canonicalu. F4 je **AI polovina** testování (#103 D3): stejné
doklady projdou skutečnou AI analýzou **nanečisto** a výsledek se porovná
s očekáváním po polích. Výsledky běhů se ukládají do repa, aby šly
dlouhodobě porovnávat napříč modely, laděním a verzemi promptu (D8).

Hlavní důvod: přechod výchozího modelu na řadu 5 po evaluaci
(`docs/roadmap.md` M4 — Sonnet 4.6 je aktivní nejméně do 17. 2. 2027).
Bez měření nejde říct, jestli je nový model lepší, ani jestli rozdíl
není jen šum (D41).

## Návaznost

- **#103** D34–D41 (oddíl na konci); D1 (očekávání = canonical), D8 (výsledky
  do repa), D10 (serverové AI připojení), D14 (papírová projekce), D15 (tvary
  hodnot), D27 (porovnávač)
- `tasks/testing-phase1.md`, `testing-phase2.md`, `testing-phase3.md`,
  `docs/test-corpus.md`
- **#118** — normalizace v produkční cestě; do té doby porovnávač normalizuje
  obě strany a evaluace hlásí „shodu až po normalizaci“ zvlášť (podklad pro #118)
- **#119** — ISDOC; do té doby jdou podoby `pdfIsdoc` analýzou jako PDF
- **#121** — kurzy; evaluace extrakce je nepotřebuje (kurz na dokladech není)

## Před implementací přečti

- `modules/core/mail/docs/ai-analysis.md` — § Analýza v procesu, § Zápis
  výsledku běhu, § Obsahová eskalace
- `modules/core/mail/src/Analysis/` — `AnalysisRunner`, `AnalysisClaimService`,
  `AttachmentPreparer`, `PromptRenderer`, `OutputParser`,
  `AnalysisResultWriter` (`validateCanonical()` — validace + obohacení
  běží před transakcí a jen čte)
- `src/Core/Ai/AiFunctionResolver.php`, `AiCallOverride.php`; `docs/ai.md`
- `docs/cli.md` → `ai-backend-set-key`, `ai-test`, `hosting-ai-gw-init`
  (vzor serverového klíče v `secrets/`)
- `modules/core/exchange/src/Compare/` (porovnávač z F3),
  `modules/core/exchange/src/Dataset/Verify/` (preflight `dataset-verify`)

## Rozsah

**V rozsahu:** serverové AI připojení pro vývoj (D40); analýza nanečisto
jako sdílená služba; varianty; `ai-eval` s opakováním; soubor výsledků;
`ai-eval-compare`; první běh výchozí varianty; dokumentace.

**Mimo rozsah:** UI evaluací (F6); ISDOC (#119); normalizace v produkci
(#118); párování řádků podle obsahu (zatím po pozicích); automatické
spouštění evaluací (F8).

## 1. Serverové AI připojení pro vývoj (D40, #103 D10)

- **Klíč:** `shpd-server dev-ai-key --set-key` (skrytý vstup nebo STDIN,
  **nikdy argv**) → `secrets/dev-ai-<platforma>.key` (0600, vzor
  `hosting-ai-gw-init`); `--status` ukáže existenci, mtime a práva, nikdy
  obsah. Platforma a volitelné base URL v `server.json` (`devAi`).
  Příkaz odmítne server mimo režim `development`.
- **Kdo ho použije — opt-in per zdroj dat:** příznak zdroje dat (parametr
  vrstvy C, např. `core.ai.useServerConnection`). Nastaví ho
  `ds-create` na serveru v režimu `development` a `dataset-seed`
  (manifest `provision.serverAi: true`, sady korpusu ho nesou). Obnovená
  reálná kopie příznak **nemá** — bez vlastního připojení zůstane bez AI
  a fronta její pošty se nespustí (mutace reálných dat jen se schválením).
- **Resolver:** zdroj dat s příznakem, na serveru v režimu `development`,
  bez vlastního aktivního připojení s klíčem → serverové připojení
  (virtuální, kód `server`). Vlastní připojení zdroje dat má vždy
  přednost. Mimo `development` se serverové připojení nikdy nepoužije.
- `ai-test` a `ai-test --json` ukážou původ připojení (`server` / kód
  řádku); sonda fronty (`AnalysisBackendProbe`) serverové připojení vidí.

## 2. Analýza nanečisto (D34)

Vytáhnout z `AnalysisRunner` a `AnalysisResultWriter` sdílenou službu
(např. `AnalysisComputation`), která pro zprávu, profil a volání udělá:

1. přípravu příloh, vykreslení promptu, volání modelu, parsování výstupu;
2. validaci canonicalu a obohacení (`validateCanonical()` včetně LLM
   klasifikace štítků, historie řádků, pravidel);
3. vrátí tělo výsledku (kontrakt v4), canonical s `_resolve`, cenu, tokeny,
   čas a chyby — **bez claimu, bez změny stavu zprávy, bez zápisu
   analýzy, návrhu ani partnera**.

Ostrý runner jede přes tutéž službu; jeho chování se nemění (strážce:
existující testy runneru a zápisu výsledku zelené, nový test s falešným
LLM ověří, že nanečisto nic nezapíše).

## 3. Varianty (D35)

`tests/eval/variants/<název>.jsonc`:

```jsonc
{
	"format": "shpd.eval.variant.v1",
	"name": "baseline",
	"description": "Výchozí profil a model funkce mail.extraction",
	"profile": "czech_general",                  // kód profilu pošty
	"promptTemplateFile": null,                  // šablona promptu ze souboru (vyzkoušení bez zásahu do zdroje dat)
	"call": { "connection": null, "model": null, "thinking": null, "effort": null, "maxTokens": null },
	"contentTags": { "model": null, "thinking": null, "effort": null }, // přepis funkce exchange.contentTags
}
```

`null` = jak je nastaveno ve zdroji dat. `connection` = kód připojení
zdroje dat nebo `server`. Šablona ze souboru nahradí `prompt_template`
profilu jen v paměti běhu; do výsledku jde její hash. Výchozí varianta
`baseline` je v repu.

## 4. `ai-eval` (D34, D39, D41)

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ai-eval tests/datasets/cz/vat-payer --variant tests/eval/variants/baseline.jsonc
    [--only 0101,0902] [--limit 5] [--repeat 3] [--save-raw <adresář>] [--yes] [--json]
```

**Preflight (D39, nic se nemění, exit 2):** server mimo `development` →
odmítnout; `country` sady = země zdroje dat; zprávy zdroje dat jsou přesně
zprávy sady (jako `dataset-verify`) a **žádný doklad ze sady není
použitý** — historie a pravidla by pokryla štítky dřív než AI a běh by
měřil něco jiného. Zdroj dat po `dataset-verify` se přeseeduje.

**Odhad a potvrzení:** před během vypíše počet volání (zprávy ×
opakování), model a odhad ceny (z posledního běhu téže varianty nad sadou,
jinak z ceníku katalogu a typické velikosti dokladu). Bez `--yes` se zeptá
(TTY), neinteraktivně bez `--yes` skončí. Útrata peněz = vědomý krok.

**Běh:** zprávy postupně, každá `--repeat` krát (výchozí 1). Selhání
jednoho volání (síť, parser, nevalidní canonical) je výsledek zprávy, ne
pád příkazu. Očekávání = `canonicalJson` ideální analýzy ze souborů sady
(ne ze zdroje dat).

**Výstup:** souhrn metrik (D37) na terminál, soubor výsledků (D36),
`--json` totéž strojově. Exit 0 = běh doběhl (i se špatnými výsledky),
1 = nějaké volání selhalo infrastrukturně, 2 = preflight nebo vstup.

## 5. Porovnání a metriky (D37)

Porovnávač z F3 dostane režim pro evaluaci — stav per list projekce:

| Stav | Význam |
|------|--------|
| `match` | shoda beze změny |
| `matchNormalized` | shoda až po normalizaci tvaru (D15) — podklad pro #118 |
| `missing` | očekávaná hodnota, výstup `null` / chybí („model nenašel“) |
| `extra` | očekáván `null` („na dokladu není“), výstup má hodnotu |
| `wrong` | obě strany mají hodnotu, liší se |

Volný text (názvy stran a položek, popisy řádků, `notes.onDocument`):
po normalizaci mezer porovnat přesně; navíc zaznamenat podobnost (0–1).
Podobnost ≥ 0,9 se počítá jako `matchNormalized`. Práh je konstanta
porovnávače. Řádky po pozicích (známé omezení: vynechaný řádek posune
zbytek — metrika „počet řádků sedí“ to odliší).

**Metriky běhu:** podíl `match` + `matchNormalized` po skupinách (hlavička,
dodavatel, odběratel, DPH, řádky, rekapitulace, součty, štítek dokladu,
výjimky štítků řádků, klasifikace zprávy `primary_type`); podíl dokladů
bez jediného `missing` / `extra` / `wrong`; podíl dokladů se správnými
částkami (řádky `totalPrice`, rekapitulace, součty); počet řádků sedí;
selhání (parser, nevalidní canonical); cena, tokeny, čas (součet, průměr
na doklad). Vše i po variantách korpusu (mapování zpráva → varianta
a podoba: `expected/messages.json` sady, doplní generátor).

**Opakování (D41):** metriky jako průměr a rozpětí (min–max) přes
opakování; per pole stabilita (podíl opakování se stejným stavem).

## 6. Soubor výsledků (D36)

`tests/eval/<název sady>/<YYYY-MM-DD>-<varianta>[-<n>].json`, mimo
generovanou sadu (build ho nepřepíše):

- `format` `shpd.eval.run.v1`; sada (název, země, profil, hash obsahu
  `mail/` a `expected/`); git commit a příznak neuložených změn; varianta
  rozepsaná na skutečné hodnoty (profil, verze a hash promptu, platforma
  a původ připojení, model, `thinking`, `effort`, strop tokenů, ladění
  funkce štítků); začátek a konec; počet opakování;
- souhrnné metriky (D37);
- per zpráva a opakování: stav volání, cena, tokeny, čas, klasifikace,
  štítky a **jen odchylky** po polích (`{cesta, stav, očekáváno, výstup,
  podobnost}`) — počty `match` / `matchNormalized` jako čísla.

Syrové výstupy modelu do repa nejdou; `--save-raw <adresář>` je uloží
lokálně (mimo repo). Data korpusu jsou fiktivní, výsledky nad veřejnými
sadami jdou do veřejného repa; nad reálnými doklady jen do soukromého (D8).

## 7. `ai-eval-compare` (D38)

```bash
shpd-ds ai-eval-compare tests/eval/cz-vat-payer/A.json tests/eval/cz-vat-payer/B.json [--json]
```

Metriky vedle sebe s rozdílem; rozdíl menší než rozpětí opakování (má-li
některý běh `--repeat` > 1) označí jako „v rámci šumu“; pole, která se
zlepšila / zhoršila (cesta, počet zpráv); cena a čas. Odmítne běhy nad
různými sadami (hash).

## Kroky

1. Serverové AI připojení (D40) + testy + `docs/ai.md`, `docs/cli.md`.
2. Analýza nanečisto jako sdílená služba; ostrý runner přes ni beze změny
   chování; test „nanečisto nic nezapíše“.
3. Generátor: `expected/messages.json` (varianta, podoba); sady přestavět;
   manifest `provision.serverAi`.
4. Porovnávač — režim evaluace (stavy, podobnost textu) + unit testy.
5. Varianty, `ai-eval` (preflight, odhad, opakování, soubor výsledků) +
   unit testy s falešným LLM.
6. `ai-eval-compare` + unit testy.
7. **První běh — zastavit se před ním:** nový volný dev zdroj dat,
   `dataset-seed tests/datasets/cz/vat-payer`, `ai-eval --variant baseline`
   s odhadem ceny. **Spouští se až po schválení v chatu** (stojí peníze).
   Výsledek commitnout, metriky shrnout.
8. Dokumentace a hlavička tasku.

## Hotovo když

- [ ] `dev-ai-key`: klíč přes skrytý vstup / STDIN, `--status` bez obsahu,
      odmítne server mimo `development`
- [ ] zdroj dat s příznakem bez vlastního připojení použije serverové;
      bez příznaku (obnovená kopie) ne; vlastní připojení má přednost;
      `ai-test` ukáže původ
- [ ] ostrá analýza beze změny chování (testy runneru a zápisu zelené);
      nanečisto nic nezapíše (test)
- [ ] unit testy porovnávače, `ai-eval`, `ai-eval-compare` zelené (úzké `--filter`)
- [ ] preflight odmítne zdroj dat s použitým dokladem ze sady, cizí data
      a server mimo `development`
- [ ] první běh `baseline` nad `cz-vat-payer` po schválení, výsledek
      v `tests/eval/cz-vat-payer/`, metriky shrnuté v tasku
- [ ] `docs/test-corpus.md` (evaluace: varianty, metriky, soubor výsledků,
      porovnání, opakování), `docs/cli.md`, `docs/ai.md`, `CLAUDE.local.md`
      nedotčený (zdroje dat dopíše člověk)
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Útrata.** Každé volání stojí peníze; odhad a `--yes` nejsou formalita.
  `--only` / `--limit` na ladění, celé sady s `--repeat` jen vědomě.
- **Reálná data.** Serverové připojení je opt-in právě proto, aby obnovená
  reálná kopie nezačala analyzovat poštu (mutace bez schválení). Příznak
  nenastavovat ručně na reálných kopiích.
- **Stav zdroje dat.** Evaluace na zdroji dat s použitými doklady měří
  historii a pravidla, ne model — preflight ji odmítne.
- **Očekávání se neupravuje podle modelu.** Rozdíl je výsledek měření;
  chyba očekávání se opravuje v generátoru se schválením.
- **Nedeterminismus.** Jeden běh nestačí na závěr „lepší / horší“ u malých
  rozdílů — proto opakování a označení „v rámci šumu“.

## Rozhodnutí k designu (potvrzená)

- ✓ **D34 — `shpd-ds ai-eval`:** analýza nanečisto (stejný pipeline jako
  ostrá analýza bez claimu a zápisu), porovnání s očekáváním, odhad ceny
  a `--yes`, `--only` / `--limit`.
- ✓ **D35 — Varianta je soubor v repu:** profil pošty nebo šablona promptu
  ze souboru, přepis volání, volitelně přepis funkce štítků.
- ✓ **D36 — Výsledky v repu** (`tests/eval/<sada>/`): metadata, metriky,
  per zpráva jen odchylky; syrové výstupy modelu jen lokálně.
- ✓ **D37 — Metriky** po skupinách polí, doklady bez chyby, správné částky,
  zvlášť shoda až po normalizaci (#118); řádky zatím po pozicích.
- ✓ **D38 — `shpd-ds ai-eval-compare`** pro porovnání dvou běhů; UI až F6.
- ✓ **D39 — Evaluace jen na čerstvě naseedovaném zdroji dat** (žádný
  použitý doklad ze sady) a jen na serveru v režimu `development`.
- ✓ **D40 — Serverové AI připojení (D10) jako první krok F4;** používá ho
  jen zdroj dat s příznakem (opt-in: `ds-create` ve vývoji, `dataset-seed`),
  obnovené reálné kopie ne.
- ✓ **D41 — Opakování `--repeat N` hned** (výchozí 1): průměr a rozpětí
  metrik, stabilita polí, porovnání označí rozdíl v rámci šumu.
