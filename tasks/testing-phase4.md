# Testování F4: evaluace AI analýzy nad datovými sadami

**Stav:** hotovo — implementace 2026-10-15 (serverové AI připojení, `AnalysisComputation`, `expected/messages.json`, porovnávač v režimu evaluace, `ai-eval`, `ai-eval-compare`), první běh `baseline` nad `cz-vat-payer` 2026-10-15 v `tests/eval/cz-vat-payer/2026-10-15-baseline.json` (§ 8); nálezy běhu jsou podklad pro ladění promptu a #118, ne úprava očekávání; nález „posunuté výjimky štítků řádků“ byl artefakt pozičního porovnání — porovnávač páruje `rowExceptions` podle `rowIndex` od 2026-10-15; druhý běh `sonnet-5-5` × 3 nad touž sadou 2026-10-15 v `tests/eval/cz-vat-payer/2026-10-15-sonnet-5-5.json`, porovnání s baseline v § 9 (podklad pro M4); třetí kolo 2026-10-15 po opravě párování výjimek: `baseline` × 3 (`2026-10-15-baseline-2.json`) a `sonnet-5-5-tags` × 3 se štítky na řadě 5 (`2026-10-15-sonnet-5-5-tags.json`), porovnání v § 10 — Sonnet 5.5 pro extrakci i štítky není horší, levnější a rychlejší; před přepnutím výchozího modelu opravit prompt (bankovní spojení, `reverseCharge` u EU zboží, adresa mimo ČR, štítek `goods.stock`)

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

## 8. První běh `baseline` nad `cz-vat-payer` (2026-10-15)

Zdroj dat `vg39-l` (nový, `ds-create --no-cron`, `dataset-seed`), serverové
připojení (`server`), model `claude-sonnet-4-6` z mapy úrovní, profil
`czech_general` v4.7.1, bez thinking / effort, 20 zpráv × 1. Soubor:
`tests/eval/cz-vat-payer/2026-10-15-baseline.json` (commit `d33aa305`,
čistý checkout).

| Metrika | Hodnota |
|---------|---------|
| Volání | 20, úspěšných 20 (žádné selhání parseru ani nevalidní canonical) |
| Shoda polí celkem (match + po normalizaci) | 93,2 % (po normalizaci 1,0 %) |
| Hlavička / dodavatel / odběratel | 93,5 % / 90,0 % / 98,2 % |
| DPH / řádky / rekapitulace / součty | 99,4 % / 92,4 % / 100 % / 100 % |
| Štítek dokladu / výjimky štítků řádků / klasifikace zprávy | 90,0 % / 56,5 % (artefakt pozičního porovnání, viz níže) / 100 % |
| Doklady bez odchylky / správné částky / počet řádků sedí | 5,0 % / 100 % / 100 % |
| Cena / tokeny / čas | 1,24 USD (0,062 na doklad) / 185 k vstup, 45 k výstup / 643 s (32 s na doklad) |
| Odhad před během | 1,60 USD z ceníku a typické velikosti (skutečnost 77 %) |

Částky, rekapitulace, počty řádků a klasifikace zprávy sedí u všech dvaceti
dokladů; nízký podíl dokladů „bez odchylky“ dělá několik systematických
mezer. Odchylky podle uzlu (počet polí; stav):

| Uzel | Počet | Co se děje | Povaha |
|------|-------|-----------|--------|
| `rows[].item.supplierCode` | 114 missing | model kód dodavatele u řádku nikdy nevyplní | prompt / schéma extrakce |
| `_resolve.contentTag.rowExceptions[].rowIndex` | 34 wrong | u `long-document` vypadaly indexy výjimek posunuté o −3 (očekáváno 28, výstup 25) | **artefakt porovnávače, opraveno 2026-10-15** — viz níže; skutečný rozdíl je 3 + 5 výjimek navíc (`extra`) |
| `supplier.contact.web` | 16 missing | web dodavatele model nevyplňuje | prompt |
| `supplier.courtRegistration` | 12 wrong | bez úvodu „Spisová značka“, podobnost 0,73 | prompt, nebo rovnocenný tvar (#118 / projekce) |
| `rows[].item.description` | 9 extra, 2 missing, 2 wrong | období plnění vložené do popisu řádku místo `dates.periodFrom/To` (viz níže) | prompt |
| `notes.onDocument` | 8 wrong | místo volné poznámky bere text o DPH (čl. 138, § 92a) | prompt |
| `customer.taxId`, `supplier.taxId` | 6 + 4 missing | vyplní `vatId`, `taxId` nechá prázdné | prompt / sémantika `taxId` vs. `vatId` (#118) |
| `dates.periodFrom`, `dates.periodTo` | 4 + 4 missing | období plnění nenašel v hlavičce (skončilo v popisu řádku) | prompt |
| `dates.taxPointDate` | 4 extra | u dokladu bez DPH (`noVat`) doplní DUZP | prompt / rovnocenný tvar u `vat.mode: none` |
| `supplier.bankAccount.iban` | 3 wrong | IBAN s vynechanou nebo přehozenou číslicí (platební údaj!) | chyba modelu — kandidát na kontrolní součet IBAN v produkční cestě |
| `_resolve.contentTag.tag` | 2 wrong | `long-document` (`goods.stock`) jako `office.cleaning` | klasifikace štítků |
| `payment.paymentReference` | 2 extra | u EU faktury dosadí číslo faktury jako VS | prompt |
| `rows[].vat.pct` | 2 extra | třetí země: 0 místo `null` | rovnocenný tvar (#118) |
| ostatní | 1× `priceCalcMode`, `supplyKind`, `zip` („CA 94105“) | jednotlivosti | — |

Shoda jen po normalizaci (podklad #118): telefon s mezerami 18×, PSČ
s mezerou 10×, `totalRounding` 0 ≡ null 4×, IBAN s mezerami 3×.

**Výjimky štítků řádků — nález prošetřen 2026-10-15.** Digest
`ContentTagClassifier` čísluje řádky indexem v `canonical.rows` a `rowIndex`
z odpovědi se nepřepočítává (test
`testDigestIndexesAreCanonicalRowIndexesAndRowExceptionsPassThrough`);
posun nebyl v klasifikaci. Model označil `people.catering` i řádky před
kávou — u 1001 tři (kávové filtry, papírové kelímky, dřevěná míchátka),
u 1002 pět (navíc papírové ubrousky, odvápňovač kávovaru) — a porovnávač
srovnával `rowExceptions` **po pozicích**, takže výjimka navíc na nižším
řádku posunula všech 16 / 18 dalších do `wrong` („−3“ u 1001, „−5“ u 1002
= počet výjimek navíc). Oprava v `CanonicalComparator::KEYED_LISTS`: výjimky
se párují podle `rowIndex`, prvek jen na jedné straně je jeden `missing` /
`extra` na cestě `rowExceptions[<rowIndex>]`
(`testEvaluateRowExceptionsByRowIndexNotByPosition`). Skutečný rozdíl
běhu je tedy 8× `extra` (sporné pomůcky k občerstvení jako občerstvení)
a 2× špatný štítek dokladu; čísla v tomto souboru výsledků zůstávají
z původního porovnání — další běh se už porovná správně a metrika
„výjimky štítků řádků“ (56,5 %) proti němu není srovnatelná.

Závěr: baseline je použitelný etalon — hodnotové jádro dokladu (částky, DPH,
řádky, klasifikace) sedí, systematické mezery jsou v identifikačních
a textových polích a ve štítcích řádků. Další krok podle roadmapy M4: stejná
sada s variantou pro model řady 5 (`call.model`) a `--repeat 3`, pak
`ai-eval-compare` proti tomuto běhu (hotovo 2026-10-15, § 9). Nálezy k promptu a klasifikaci
štítků řešit úpravou promptu / kódu se samostatnou evaluací, ne úpravou
očekávání.

## 9. Druhý běh `sonnet-5-5` × 3 a porovnání s baseline (2026-10-15)

Varianta `tests/eval/variants/sonnet-5-5.jsonc`: profil `czech_general`
(v4.7.1, stejný hash promptu jako baseline), `call.model` =
`claude-sonnet-5-5` (katalog, úroveň balanced), ostatní `null` — serverové
připojení (`server`), bez thinking / effort, strop 32 768. Týž zdroj dat
`vg39-l` (žádný doklad použitý, preflight prošel), 20 zpráv × 3 = 60 volání.
Soubor: `tests/eval/cz-vat-payer/2026-10-15-sonnet-5-5.json` (commit
`d322c724`, `dirty: true` — v témže checkoutu právě vznikala oprava
porovnávače `2318728f`). Porovnání: `shpd-ds ai-eval-compare
tests/eval/cz-vat-payer/2026-10-15-baseline.json
tests/eval/cz-vat-payer/2026-10-15-sonnet-5-5.json`.

**Co běh měřil a co ne — tři výhrady ke čtení čísel:**

- **Výjimky štítků řádků jsou u obou běhů poziční.** Proces si porovnávač
  načetl před úpravou `2318728f` (na opakování vychází 5× `extra` + 34×
  `wrong` jako u baseline), takže metrika 56,5 % → 56,8 % je mezi § 8 a § 9
  srovnatelná, ale s žádným dalším během už ne.
- **Funkce `exchange.contentTags` zůstala na `claude-sonnet-4-6`**
  (`contentTags.model: null` = mapa úrovní zdroje dat). Štítek dokladu
  a výjimky štítků řádků tedy měří stejný model jako baseline; řada 5 je
  jen v extrakci. Další varianta má nastavit i `contentTags.model`.
- **Teplota.** Tvarovač požadavku u řady 5 vynechal `temperature: 0`
  (model nevýchozí teplotu odmítá; log „temperature 0 omitted“) a Sonnet 5.5
  má výchozí adaptivní thinking. Baseline jela s teplotou 0. Část rozptylu
  mezi opakováními je tedy výchozí nedeterminismus modelu, ne vlastnost
  sady.

| Metrika | baseline (A) | sonnet-5-5 (B, průměr [min–max]) | B − A |
|---------|--------------|----------------------------------|-------|
| Volání | 20, úspěšných 20 | 60, úspěšných 59, 1× `schema_error` | |
| Shoda polí celkem (match + po normalizaci) | 93,2 % (po normalizaci 1,0 %) | 93,7 % [93,6–93,8] (po normalizaci 0,9 %) | +0,5 % lepší, mimo šum |
| Hlavička / dodavatel / odběratel | 93,5 % / 90,0 % / 98,2 % | 94,4 % [93,8–94,7] / 90,6 % / 99,1 % | +0,9 (v rámci šumu) / +0,6 lepší / +0,9 lepší |
| DPH / řádky / rekapitulace / součty | 99,4 % / 92,4 % / 100 % / 100 % | 99,5 % / 92,9 % / 100 % / 100 % | +0,1 (šum) / +0,5 lepší / 0 / 0 |
| Štítek dokladu / výjimky štítků řádků / klasifikace zprávy | 90,0 % / 56,5 % / 100 % | 89,8 % / 56,8 % / 100 % | šum / šum (poziční u obou, model 4.6) / 0 |
| Doklady bez odchylky | 5,0 % | 10,0 % [10–10] | +5,0 lepší (obě prodejky čisté ve všech opakováních) |
| Správné částky / počet řádků sedí / klasifikace / štítek | 100 % / 100 % / 100 % / 90,0 % | 98,3 % [95–100] / 98,3 % / 98,3 % / 88,3 % | −1,7 % = jediné selhané volání; všech 59 úspěšných má částky, počty řádků i klasifikaci správně |
| Cena | 1,24 USD (0,062 / doklad) | 2,83 USD (0,047 / doklad) | −24 % na doklad |
| Tokeny na doklad (vstup / výstup) | 9,3 k / 2,3 k | 11,1 k / 2,5 k | +20 % / +10 % (levnější ceník to převáží) |
| Čas na doklad | 32,1 s | 13,4 s | 2,4× rychlejší |
| Odhad před během | 1,60 USD (skutečnost 77 %) | 3,32 USD (skutečnost 85 %) | |

`ai-eval-compare` vypisuje „pole zlepšená / zhoršená“ a součty (volání,
tokeny, cena, čas) jako prosté počty volání, bez přepočtu na opakování — u B
s trojnásobkem volání tak hlásí jako „zhoršené“ i uzly beze změny
(`supplier.contact.web` 16 → 48) a součty jako „horší“. Níže počty
odchylek **přepočtené na jedno opakování** (B / 3):

| Uzel | A (20 volání) | B na opakování | Změna |
|------|---------------|----------------|-------|
| `supplier.contact.web` | 16 | 16,0 | beze změny |
| `supplier.courtRegistration` | 12 | 11,7 | beze změny |
| `rows[].item.supplierCode` | 10 | 10,0 | beze změny |
| `notes.onDocument` | 8 | 8,3 | beze změny |
| `dates.periodFrom` / `dates.periodTo` | 4 / 4 | 4,0 / 4,0 | beze změny |
| `_resolve.contentTag.rowExceptions` (+ `[].rowIndex`) | 6 (+2) | 5,0 (+2,0) | poziční artefakt u obou, funkce štítků 4.6 |
| `_resolve.contentTag.tag` / `rows[].item.ean` / `rows[].vat.supplyKind` | 2 / 2 / 1 | 2,0 / 2,0 / 1,0 | beze změny (1001 a 1002 dál `office.cleaning`) |
| `rows[].item.description` | 6 | 4,0 | lepší |
| `customer.taxId` / `supplier.taxId` | 6 / 4 | 3,0 / 2,3 | lepší (0502, 0802 stále 3×) |
| `dates.taxPointDate` | 4 | 1,0 | lepší (0701 2×, 0802 1×) |
| `supplier.bankAccount.iban` | 3 | 0,7 | lepší, ale 2 z 60 volání stále špatně (0101, 0701 — vložená / vynechaná číslice), stabilita pole 75 % — kontrolní součet IBAN v produkční cestě zůstává kandidátem |
| `payment.paymentReference` | 2 | 1,3 | lepší (0502 3×, 0501 1×) |
| `rows[].vat.pct` / `supplier.address.zip` / `rows[].priceCalcMode` | 1 / 1 / 1 | 0 / 0 / 0,3 | lepší |
| `vat.reverseCharge` | 0 | 1,7 | **nové:** `eu-goods` 0601 (3×), 0602 (2×) — pořízení zboží z EU označí jako přenesení daňové povinnosti (`true`), očekáváno `false`; sémantika § 92a vs. pořízení z EU v promptu |
| `supplier.address.street` + `houseNumber` | 0 | 1,3 + 1,3 | **nové:** `third-country` 0801 (3×), 0802 (1×) — „500 Market Street, Suite 200“ rozdělí na číslo 500 a ulici; prompt, nebo rovnocenný tvar adresy mimo ČR (#118) |

**Selhání 0702 (3), `schema_error`:** model vložil `bankAccount` pod
`payment` (schéma má `additionalProperties: false`) — v ostrém provozu by
to byla selhaná analýza. Jediný případ z 60, u baseline žádný; sledovat
v dalších bězích (tolerance neznámých vlastností v parseru, nebo prompt).

Shoda jen po normalizaci (podklad #118), na opakování proti A: telefon
s mezerami ≈ 20× (A 18), **IBAN s mezerami 7× (A 3)** — řada 5 píše IBAN
po čtveřicích častěji, `totalRounding` 0 ≡ null 3,3× (A 4), PSČ odběratele
1,3× (A 7), PSČ dodavatele 0× (A 3).

Po variantách korpusu (shoda A → B): `eu-services` 88,4 → 90,8 %,
`domestic-rounding` 93,7 → 96,1 %, `third-country-services` 93,5 → 94,9 %,
`receipt` 99,4 → 100 % (bez odchylky 50 → 100 %), `domestic-goods` 95,2 →
95,8 %, `long-document` 91,6 → 91,6 %, `eu-goods` 92,9 → 93,0 %. Po podobách
`pdf` 93,3 → 93,8 %, `pdfIsdoc` 93,1 → 93,7 % (selhání bylo v `pdfIsdoc`).
Stabilita přes opakování: 73 z 85 uzlů 100 %; nejméně stabilní
`supplier.bankAccount.iban` 75 %, `customer.address.zip` 83 %,
`customer.taxId` 89 %, `dates.taxPointDate` a `notes.onDocument` 90 %.

**Závěr pro M4:** Sonnet 5.5 na téže sadě a témže promptu není horší —
celková shoda +0,5 % mimo šum, lepší dodavatel, odběratel a řádky,
dvojnásobek dokladů bez odchylky — a je o 24 % levnější na doklad a 2,4×
rychlejší. Systematické mezery (web a spisová značka dodavatele, kód
položky, poznámka, období plnění) jsou shodné, tedy věc promptu, ne modelu.
Nová rizika: jeden výstup mimo schéma z 60 a `reverseCharge` u pořízení
z EU. Před přepnutím výchozího modelu: (1) varianta s `contentTags.model`
= řada 5 a explicitním `thinking` / `effort`, (2) oba běhy znovu po
`2318728f`, aby výjimky štítků řádků byly srovnatelné, (3) prompt pro
`reverseCharge` a adresu mimo ČR; očekávání sady se nemění.

**Nálezy k nástrojům (follow-up, ne součást tohoto běhu):**

- `ai-eval-compare` nepřepočítává počty odchylek ani součty na opakování
  (D38 × D41) — porovnání běhu × 1 s během × 3 je v části „pole
  zlepšená / zhoršená“ zavádějící. **Opraveno 2026-10-15:** součty
  i odchylky per uzel na jedno opakování, změna pole od 0,5 volání na
  opakování; nad touto dvojicí `supplier.contact.web` beze změny,
  `vat.reverseCharge` a `supplier.address.street` zhoršené,
  `dates.taxPointDate`, `customer.taxId`, `supplier.bankAccount.iban`
  zlepšené (unit test nad oběma soubory).
- `ai-eval` bez `--yes` s `stdin` z `/dev/null` skončil po „Aborted.“
  exit 0, ne dokumentovaným exit 2 (`isInteractive()` zůstalo `true`,
  `posix` načtený); útrata nehrozí, jen kód pro skripty. **Opraveno
  2026-10-15:** příčina je Symfony Console 7 — `Application::configureIO`
  už interakci z chybějícího TTY nevypíná (jen `-n` / `--quiet`)
  a `QuestionHelper` na prázdném STDIN vrátí výchozí „ne“; příkaz se teď
  ptá jen na skutečném terminálu (`stream_isatty(STDIN)`), jinak exit 2
  bez dotazu; odpověď N dál exit 0.

## 10. Třetí kolo: `baseline` × 3 a `sonnet-5-5-tags` × 3 po opravě párování (2026-10-15)

Dva běhy nad touž sadou a týmž zdrojem dat `vg39-l` (žádný doklad použitý,
bez přeseedování), oba po úpravě porovnávače `2318728f` (výjimky štítků
řádků podle `rowIndex`) a vyhodnocené `ai-eval-compare` po `d9dc9a73`
(součty a odchylky per uzel na jedno opakování, změna pole od 0,5 volání).
Zavírají obě mezery § 9: štítky měří řadu 5 a výjimky štítků řádků jsou
mezi běhy srovnatelné. Schválení útraty v chatu (~6,9 USD); skutečnost
6,63 USD.

- **A — `baseline` × 3** (`tests/eval/cz-vat-payer/2026-10-15-baseline-2.json`,
  `git d9dc9a73`): Sonnet 4.6 z mapy úrovní pro extrakci i štítky, teplota 0,
  bez thinking / effort. 60 volání, **58 úspěšných, 2× `schema_error`** —
  `1002` (1) a (2) „output is not valid JSON“ po 11,1 k výstupních tokenů
  (úspěšné opakování má 9,5 k), 0,41 USD za nic. Cena 3,76 USD (odhad 3,71
  z posledního běhu, skutečnost 101 %), 1 712 s.
- **B — `sonnet-5-5-tags` × 3** (`tests/eval/cz-vat-payer/2026-10-15-sonnet-5-5-tags.json`,
  varianta `tests/eval/variants/sonnet-5-5-tags.jsonc`, `git d9dc9a73`):
  `call.model` i `contentTags.model` = `claude-sonnet-5-5`
  (`effective.contentTags.model` v souboru to potvrzuje), thinking / effort
  `null` = výchozí deklarace (u řady 5 adaptivní thinking modelu), teplotu 0
  tvarovač vynechal jako v § 9. 60 volání, **60 úspěšných**. Cena 2,87 USD
  (odhad 3,19 z ceníku, skutečnost 90 %), 867 s.

**Vliv opravy porovnávače (starý baseline × 1 § 8 → A):** celková shoda
93,2 → 94,1 %; prakticky celý rozdíl je metrika výjimek štítků řádků
56,5 → 88,6 % [87,7–89,3] (poziční artefakt § 8 zmizel: `rowExceptions`
6 → 0 na opakování, nová cesta `rowExceptions[<rowIndex>]` 4,3), ostatní
skupiny ±0,5 %. Odchylky per uzel na opakování jsou jinak shodné —
Sonnet 4.6 s teplotou 0 je reprodukovatelný (78 z 85 uzlů stabilních,
13 z 19 úspěšných dokladů se stejným počtem odchylek ve všech
opakováních), jen `rows[].priceCalcMode` 1 → 2,3 (`fromTotal` na
prodejkách 0901 / 0902) a `rows[].vat.pct` 1 → 2 kolísají. Čísla § 8 a § 9
u výjimek štítků řádků tedy platí jen navzájem; proti § 10 ne.

| Metrika | A baseline × 3 (průměr [min–max]) | B sonnet-5-5-tags × 3 | B − A |
|---------|-----------------------------------|-----------------------|-------|
| Volání | 60, úspěšných 58, 2× `schema_error` (1002) | 60, úspěšných 60 | |
| Shoda polí celkem (match + po normalizaci) | 94,1 % [94,0–94,2] (po normalizaci 1,2 %) | 94,2 % [93,5–94,6] (1,0 %) | +0,1 v rámci šumu; **bez dokladu 1002** 94,0–94,2 → 94,8–94,9 = **+0,7 mimo šum** |
| Hlavička / dodavatel / odběratel | 93,3 % / 90,0 % / 98,1 % | 94,1 % / 89,9 % / 99,0 % | +0,8 lepší / šum / +0,9 lepší |
| DPH / řádky / rekapitulace / součty | 98,9 % / 92,5 % / 100 % / 100 % | 99,6 % / 92,9 % / 100 % / 100 % | +0,7 lepší / +0,4 šum / 0 / 0 |
| Štítek dokladu | 93,2 % [90,0–94,7] (4 špatně z 58) | 90,0 % [90–90] (6 špatně z 60) | **stejné**: 1001 i 1002 obě řady `office.cleaning`; A má méně špatných jen o 2 selhaná volání 1002 (`documents.tagOk` 90 % u obou) |
| Výjimky štítků řádků | 88,6 % [87,7–89,3] | 77,9 % [51,5–91,5] | jedna kaskáda 1002 (níže); mimo ni 91,5 % = lepší |
| Klasifikace zprávy | 100 % | 100 % | 0 |
| Doklady bez odchylky | 1,7 % [0–5] (0901 1×) | 10,0 % [10–10] (0901 a 0902 ve všech opakováních) | +8,3 lepší |
| Správné částky / počet řádků sedí / klasifikace | 96,7 % / 96,7 % / 96,7 % | 100 % / 100 % / 100 % | rozdíl = 2 selhaná volání; všech 58 + 60 úspěšných správně |
| Cena | 3,76 USD (0,0627 / doklad) | 2,87 USD (0,0479 / doklad) | −24 % na doklad |
| Tokeny na doklad (vstup / výstup) | 9,3 k / 2,3 k | 11,1 k / 2,6 k | +20 % / +11 % (shodné s § 9 — tokenizace je deterministická) |
| Čas na doklad | 28,5 s | 14,4 s | 2,0× rychlejší |

**Štítek dokladu a výjimky štítků řádků (konečně srovnatelné):**

- Štítek dokladu je u obou řad špatně jen u dlouhých dokladů 1001 a 1002
  (`goods.stock` → `office.cleaning`; B u 1002 jednou `people.catering`).
  Nezávisí na modelu — věc promptu / klasifikace štítků, ne výměny modelu.
- Výjimky řádků, A (štítky 4.6): na opakování 6–7 sporných výjimek navíc,
  vždy stejné — 1001 řádky 25–27 `people.catering` 3× (nález § 8), 0402
  řádek 2 `services.waste` 3× (+ řádek 1 1×), 0701 řádek 1 `office.supplies`
  3×, 0801 řádek 1 `it.hosting` 3×; 1002 řádky 25–29 v jediném úspěšném
  opakování.
- Výjimky řádků, B (štítky 5.5): 1001 řádky 25–27 3× (totožné), 0402
  řádek 2 3× (totožné), **0701 a 0801 čisté ve všech opakováních**;
  1002 ve dvou opakováních jako 4.6 (řádky 25–28 / 29), v jednom
  **kaskáda**: štítek dokladu `people.catering` → všech 30 řádků mimo
  občerstvení jako výjimka `office.cleaning` (`extra` 30) a 18 řádků
  občerstvení bez výjimky (`missing` 18), 94 odchylek na doklad, metrika
  výjimek v tom opakování 51,5 %. Jedna chyba štítku dokladu se u dlouhého
  dokladu násobí počtem řádků — důvod, proč štítek dokladu u `goods.stock`
  opravit v promptu dřív, než se přepne model.
- Štítky řádků na 5.5 proti 4.6 jsou tedy o dvě sporné výjimky na
  opakování lepší; cena štítkové funkce není v souboru rozepsaná, rozdíl
  ceny a času mezi oběma běhy řady 5 (§ 9 × § 10) je do 2 %, resp. +1 s na
  doklad.

Odchylky per uzel **na opakování** (A → B; shodné zůstávají `supplier.contact.web`
16, `supplier.courtRegistration` 12, `rows[].item.supplierCode` 10,
`dates.periodFrom` / `periodTo` 4 / 4, `rows[].item.ean` 2,
`payment.paymentReference` 2 — nižší hodnoty A 15,3 / 11,3 / 9,3 jsou jen
dvě selhaná volání 1002):

| Uzel | A | B | Změna |
|------|---|---|-------|
| `customer.taxId` / `supplier.taxId` | 6 / 3,7 | 2,7 / 2,7 | lepší (0502, 0601, 0602 teď `taxId` vyplní; 0501, 0802 stále) |
| `dates.taxPointDate` | 4 | 0,7 | lepší (DUZP u `noVat` jen 0701 2×) |
| `rows[].priceCalcMode` / `rows[].vat.pct` / `supplier.address.zip` | 2,3 / 2 / 1 | 0 / 0 / 0 | lepší |
| `rows[].item.description` | 6 | 4 | lepší |
| `supplier.bankAccount.iban` | 3,3 | 1,0 | lepší, ale jiné povahy — viz níže |
| `_resolve.contentTag.rowExceptions[]` | 4,3 | 3,0 | lepší mimo kaskádu |
| `vat.reverseCharge` / `rows[].vat.supplyKind` | 0 / 0,3 | 1,0 / 1,0 | **horší:** 0602 (`eu-goods`) 3× `true` a řádek 2 `services` → `goods`; 0601 tentokrát čistý (§ 9 měl 3× + 2×) |
| `supplier.address.street` + `houseNumber` | 0 | 2 + 2 | **horší:** 0801 i 0802 ve všech opakováních (§ 9 1,3) — „500 Market Street, Suite 200“ rozdělí na číslo 500 a ulici |
| `notes.onDocument` | 8 | 9,3 | horší o 0702 (poznámka „Nejsem plátce DPH“ navíc) |
| `supplier.bankAccount.accountNumber` / `customer.vatId` | 0 / 0 | 0,7 / 0,7 | **nové:** oboje 0702 — viz níže |

**IBAN a bankovní spojení (platební údaj):**

- A (4.6, teplota 0): 10 špatných IBAN z 60 = 3,3 na opakování,
  **systematicky** — 0101 3×, 0102 3×, 0201 3×, 0202 1×, pokaždé táž
  vložená nula nebo vynechaná číslice. Deterministický model opakuje tutéž
  chybu; opakování ani přegenerování nepomůže, pomůže jen kontrolní součet
  IBAN v produkční cestě (kandidát z § 8 trvá).
- B (5.5): 3 z 60 = 1,0 na opakování, všechny na **0702**
  (`domestic-non-payer-supplier`): 1× špatný IBAN (vložené „00“), 2× IBAN
  i `accountNumber` chybí úplně (+ 2× chybí `customer.vatId`). V § 9 stejný
  doklad jednou selhal na `bankAccount` pod `payment`. Bankovní spojení
  tohoto dokladu je tedy u řady 5 nestabilní ve 4 z 6 volání přes oba běhy
  — buď je zahodí, přesune, nebo zkomolí; doklady 0101 / 0201, kde chybuje
  4.6, má 5.5 ve všech opakováních správně. Do produkční cesty patří vedle
  kontrolního součtu i upozornění „faktura bez bankovního spojení“.
- Stabilita `supplier.bankAccount.iban` v B 60 % je převážně mezery v IBAN
  (shoda po normalizaci 8,3 na opakování, A 2,7; `fieldStability` počítá
  `match` ↔ `matchNormalized` jako nestabilitu), věcně nestabilní je jen
  0702.

**Selhání a nedeterminismus:** A má 2 z 60 volání mimo JSON (1002, 48 řádků,
`pdfIsdoc`), B 0 z 60 (§ 9 u 5.5 1 z 60 mimo schéma). Obě řady tedy
u dlouhých dokladů občas vyrobí nepoužitelný výstup — produkční runner
s tím musí počítat (opakování volání), sledovat v dalších bězích.
Reprodukovatelnost: 4.6 s teplotou 0 stabilní 78 z 85 uzlů, 13 z 19
úspěšných dokladů se stejným počtem odchylek; 5.5 bez teploty 0 (tvarovač,
adaptivní thinking) 74 z 85 uzlů, 9 z 20 dokladů — rozdíly jsou ±1
odchylka, výjimkou je kaskáda 1002. Varianta s explicitním `thinking`
/ `effort` (bod 1 závěru § 9) zůstává otevřená.

Po variantách korpusu (A → B): `domestic-rounding` 93,7 → 96,2 %,
`third-country-services` 92,4 → 94,8 %, `eu-services` 88,2 → 89,9 %,
`receipt` 99,0 → 100 % (bez odchylky 16,7 → 100 %), `domestic-goods`
95,2 → 95,9 %, `domestic-reverse-charge` 96,5 → 96,6 %,
`domestic-two-rates` 96,1 → 96,2 %, `domestic-non-payer-supplier`
96,3 → 96,2 % (0702), `eu-goods` 93,3 → 93,6 % (0602), `long-document`
93,3 → 92,6 % (kaskáda; A bez dvou selhaných 1002). Po podobách `pdf`
94,0 → 94,7 %, `pdfIsdoc` 94,2 → 93,7 % (1002 je `pdfIsdoc`). Vliv samotné
štítkové funkce (§ 9 `sonnet-5-5` × B, extrakce stejná): metriky extrakce
v rámci šumu (dodavatel 90,6 → 89,9 % je 0702), štítek dokladu totožný,
výjimky řádků bez 0701 / 0801.

**Závěr pro M4:** Sonnet 5.5 pro extrakci i štítky není horší než 4.6
v hodnotovém jádru (částky, DPH, řádky, rekapitulace, klasifikace — všude
rovno nebo lépe), celkem +0,7 % mimo šum bez kaskádového dokladu, šestkrát
víc dokladů bez odchylky, o 24 % levnější, 2× rychlejší, v tomto běhu bez
selhání (4.6 dvě). Systematické mezery (web, spisová značka, kód položky,
období plnění, poznámka, `taxId` u EU dodavatele) jsou shodné — prompt, ne
model. Před přepnutím výchozího modelu opravit v promptu tři věci, které
řada 5 dělá jinak, a jednu společnou: (1) bankovní spojení 0702 (nikdy
nevynechat, nikdy pod `payment`) + kontrolní součet IBAN a upozornění na
chybějící spojení v produkční cestě, (2) `reverseCharge` u pořízení zboží
z EU (§ 92a vs. pořízení z EU), (3) adresa mimo ČR (ulice s číslem, #118),
(4) štítek `goods.stock` u dlouhých dokladů (u obou řad; u 5.5 se chyba
štítku násobí řádky). Pak jeden běh × 3 nad opraveným promptem a přepnout.
Očekávání sady se nemění.

**Nálezy k nástrojům (follow-up):**

- `fieldStability` počítá `match` ↔ `matchNormalized` (mezery v IBAN,
  telefon) jako nestabilitu — formátovací šum zakrývá věcnou nestabilitu
  (IBAN 60 % v B, věcně jen 1 doklad z 20).
- Selhaná volání se do `fields.*` nepočítají (jen do `documents.*`
  a `calls.*`), takže běh se selháním u nejhoršího dokladu má metriky polí
  o něco lichotivější; porovnání dvou běhů s různými selháními číst s tím.
- Odhad z posledního běhu téže varianty sedí na 1 % (A); odhad z ceníku je
  o 10 % výš (B) — ceník nezná levnější výstup řady 5 na doklad.

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

- [x] `dev-ai-key`: klíč přes skrytý vstup / STDIN, `--status` bez obsahu,
      odmítne server mimo `development` (`DevAiKeyCommandTest`)
- [x] zdroj dat s příznakem bez vlastního připojení použije serverové;
      bez příznaku (obnovená kopie) ne; vlastní připojení má přednost;
      `ai-test` ukáže původ (`AiBackendResolverTest`, `AiFunctionResolverTest`,
      `connectionOrigin` v `--json`)
- [x] ostrá analýza beze změny chování (testy runneru a zápisu zelené);
      nanečisto nic nezapíše (`AnalysisComputationTest` — služba bez DB,
      validátor jen `validateCanonical`)
- [x] unit testy porovnávače, `ai-eval`, `ai-eval-compare` zelené (úzké `--filter`)
- [x] preflight odmítne zdroj dat s použitým dokladem ze sady, cizí data
      a server mimo `development` (`AiEvalCommandTest`; navíc pravidla IČO → štítek)
- [x] první běh `baseline` nad `cz-vat-payer` po schválení (2026-10-15),
      výsledek v `tests/eval/cz-vat-payer/2026-10-15-baseline.json`,
      metriky shrnuté v § 8
- [x] běh varianty řady 5 (`sonnet-5-5`, `--repeat 3`) po schválení (2026-10-15),
      výsledek v `tests/eval/cz-vat-payer/2026-10-15-sonnet-5-5.json`,
      `ai-eval-compare` proti baseline a shrnutí v § 9
- [x] třetí kolo po opravě párování výjimek (2026-10-15): `baseline` × 3
      (`2026-10-15-baseline-2.json`) a `sonnet-5-5-tags` × 3 se štítky na
      řadě 5 (`2026-10-15-sonnet-5-5-tags.json`), `ai-eval-compare` na jedno
      opakování, shrnutí a doporučení pro M4 v § 10
- [x] `docs/test-corpus.md` § 18 (evaluace: varianty, metriky, soubor výsledků,
      porovnání, opakování), `docs/cli.md`, `docs/ai.md`, `CLAUDE.local.md`
      nedotčený (zdroje dat dopíše člověk)
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Odchylky implementace od zadání (2026-10-15)

- **Příznak zdroje dat žije v `config/main.json`** (`"ai": {"useServerConnection": true}`,
  vedle `enableReset` / `skipProvisioning`), ne jako parametr vrstvy C —
  `ds-create` schéma netvoří, do `core_system_settings` zapsat nemůže.
  Rozhodnuto v chatu při plánování. `ds-create` dostal `--no-server-ai`
  (vzor `--no-cron`) pro cíle importu reálných kopií.
- **Klíč v `/etc/shipard/secrets/dev-ai-<platforma>.key`** (adresář 0700,
  soubor 0600, vlastník shipard uživatel; `PermissionSpec` pro `doctor`
  / `fix-permissions`); `shpd-server dev-ai-key` běží pod sudo.
- **Preflight navíc odmítá pravidla IČO → štítek** (`core_exchange_tag_rules`)
  — pravidlo přeskočí LLM klasifikaci štítků stejně jako historie.
- **Podobnost textu jen u volného textu** (`CanonicalComparator::FREE_TEXT_NODES`:
  názvy stran a položek, popisy řádků, poznámka, jména kontaktů, spisová
  značka); číslo dokladu nebo adresa s překlepem jsou `wrong`.
- **Nullable uzel `customer`** prázdný na obou stranách = jeden `match`;
  objekt se samými `null` proti `null` = `matchNormalized`.
- Soubor výsledků nese i `normalized` (cesty polí shodných až po
  normalizaci) a `deviationsByField` — podklad pro #118 a porovnání.

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
