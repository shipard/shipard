# Testování F4b: oprava očekávání, prompt v4.8.0 a srovnání modelů řady 5

**Stav:** hotovo — 2026-10-15 (#103 D42–D46; výsledky v § 5, M4 nepřepnuto — doporučení ano, rozhodnutí lidské)

## Kontext / Cíl

První evaluace (F4, `tasks/testing-phase4.md` § 8–10) porovnala Sonnet 4.6
a Sonnet 5.5 nad sadou plátce. Ze závěru plynou čtyři úkoly a jedno
rozšíření:

1. **Chyba očekávání, ne modelu.** Dlouhé doklady korpusu (`1001`, `1002`)
   jsou úklidové a hygienické potřeby (toaletní papír, mýdlo, dezinfekce,
   pytle na odpad, rukavice) a k tomu 16–18 řádků občerstvení. Oba modely
   daly štítek dokladu `office.cleaning`, korpus čekal `goods.stock`. Opraví
   se korpus. Oba štítky mají v nabídkách položku 501100, takže deník,
   náklady ani snímky se nemění.
2. **Prompt v4.8.0** — tři věci, které řada 5 dělá jinak než očekávání:
   bankovní spojení, `reverseCharge` u pořízení zboží z EU a adresa mimo ČR.
3. **Drobnosti nástrojů evaluace** z F4 § 10.
4. **Srovnání modelů** na opraveném promptu: Sonnet 4.6 (dnešní výchozí),
   Sonnet 5.5, Opus 5.5, Haiku 5.5 a Fable 5.1, každý × 3. Cena je při
   20 dokladech malá, zajímá nás, jestli silnější model není výrazně lepší
   a jestli Haiku 5.5 (dvacetkrát levnější než Sonnet 5.5) nestačí.

Přepnutí výchozího modelu (`modules/core/ai/config/tiers.jsonc`, M4) **není**
součástí tasku. Rozhodne člověk nad výsledky.

## Návaznost

- **#103** D42–D46 (oddíl na konci); F4 D34–D41
- `tasks/testing-phase4.md` § 8–10 — výsledky a nálezy
- **#118** — kontrolní součet IBAN, chybějící bankovní spojení, tvary
  hodnot (systematicky špatný IBAN u 4.6 prompt nespraví)
- `modules/core/mail/docs/ai-prompts.md` — šablona, verze, changelog,
  `ds-upgrade` synchronizuje profil

## Před implementací přečti

- `tasks/testing-phase4.md` § 8–10 (konkrétní doklady a odchylky)
- `modules/core/mail/profiles/czech_general.jsonc` (`prompt_template` v4.7.1)
- `modules/core/mail/docs/ai-prompts.md` (§ Iterativní ladění promptu, changelog)
- `docs/test-corpus.md` — datové soubory, tvary hodnot, evaluace
- `tests/eval/variants/`, `modules/core/mail/src/Eval/` (nebo kde je `ai-eval`)

## 1. Oprava očekávání — štítek dlouhých dokladů (D42)

- `tests/corpus/cz/data/1001-long-document.jsonc`, `1002-…`:
  `contentTag` `goods.stock` → `office.cleaning`. Výjimky řádků občerstvení
  (`people.catering`) zůstávají. Zkontroluj, že žádný jiný řádek neodpovídá
  lépe jinému štítku — pokud ano, **zastav se a zeptej**.
  *Kontrola 2026-10-15:* oba modely ve všech bězích značí jako
  `people.catering` i pomůcky k občerstvení (1001 řádky 25–27 kávové
  filtry, kelímky, míchátka; 1002 navíc ubrousky a odvápňovač, řádky
  25–29) — rozhodnuto v chatu **nechat bez výjimky** (spotřební materiál
  501, ne reprezentace 513; výjimka by změnila snímky deníku tří profilů).
  Vybavení (koš, mop, kbelík, smeták → `office.equipment` by šel na
  501201) a rukavice (`people.workwear`, táž položka) také ponechány
  pod štítkem dokladu.
- Build (korpus a sady), `CorpusExpectedTest`.
- `dataset-verify --no-apply` na třech volných zdrojích dat z F3: kontroly
  1–5 `ok` beze změny snímků. Kdyby se snímek změnil, **zastav se**:
  znamenalo by to, že `office.cleaning` jde jinam než `goods.stock`.
  *Provedeno jako `dataset-seed` + `dataset-verify` s apply* — `--no-apply`
  čte očekávaný canonical z databáze, na nepřeseedovaném zdroji by měřil
  staré návrhy. Build změnil jen 2 datové soubory, 6 `expected/` a 6 zpráv
  sad (PDF beze změny); `ob9b` / `ab5v` / `wp66` kontroly 1–5 `ok`,
  `general-ledger.json`, `costs.json` ani `vat.json` se nezměnily.
- Starší soubory výsledků v `tests/eval/` zůstávají jako historie. Hash
  sady se změní, takže `ai-eval-compare` je s novými běhy neporovná.
  Je to správně, protože měřily proti jinému očekávání.

## 2. Prompt v4.8.0 (D43)

Úpravy `prompt_template` profilu `czech_general`:

| Nález (F4 § 10) | Pravidlo v promptu |
|-----------------|--------------------|
| 0702: řada 5 bankovní spojení dodavatele vynechá, nebo ho dá pod `payment` | Bankovní spojení dodavatele uvedené na dokladu **vždy** do `supplier.bankAccount` (`accountNumber`, `iban`, `bic`), i u dokladu bez DPH a od neplátce. Nikdy pod `payment`. |
| 0602: řada 5 dává `vat.reverseCharge: true` u osvobozeného dodání zboží z EU (čl. 138) | `reverseCharge: true` jen když doklad výslovně uvádí, že daň přizná příjemce (reverse charge, čl. 196, § 92a). Osvobozené dodání zboží do jiného členského státu (čl. 138, „intra-Community supply“, „steuerfreie innergemeinschaftliche Lieferung“) je `false`; místo plnění a druh plnění určí systém. |
| 0801 / 0802: řada 5 rozdělí „500 Market Street, Suite 200“ na číslo a ulici | Číslo domu odděl do `houseNumber` jen tam, kde následuje za ulicí (CZ, SK, DE, AT…). Kde číslo ulici předchází (US, UK…), dej celý řádek do `street` a `houseNumber` vynech. |

- `prompt_version` v4.7.1 → **v4.8.0**, changelog v `ai-prompts.md` (nálezy
  a odkaz na F4 § 10).
- Pravidla piš obecně (žádné názvy dokladů korpusu, žádné „0702“).
- `ds-upgrade` na eval zdroji dat profil synchronizuje — ověř verzi.
- *Hotovo 2026-10-15:* tři odrážky v `PRAVIDLA:` (za identifikátory,
  za zeměmi, rozšířená `reverseCharge`), ukázkový příklad adresy
  „100 Main Street, Suite 5“ (ne adresa korpusu — `ProfileSchemaDriftTest`
  hlídá), `ds-upgrade` na `vg39-l` hlásí `profile 'czech_general' at v4.8.0`.

## 3. Nástroje evaluace (D44)

- **Stabilita pole** (`fieldStability`): `match` ↔ `matchNormalized` už
  není nestabilita. Rozdíl jen ve formátu (mezery v IBAN, telefonu) se
  vykazuje zvlášť jako `formatStability`. Věcná nestabilita (`missing` /
  `extra` / `wrong` ↔ shoda) zůstává ve `fieldStability`.
- **Selhaná volání v metrikách polí:** volání, které skončilo chybou
  (`schema_error`, síť), se do `fields.*` počítá jako `missing` všech
  očekávaných neprázdných polí dokladu. Běh se selháním u nejhoršího
  dokladu tak nevychází lichotivěji. `calls.*` beze změny.
- **Odhad ceny:** když neexistuje běh téže varianty, vezmi tokeny na
  doklad z posledního běhu nad sadou s modelem **téže řady**. Teprve pak
  použij typickou velikost dokladu. Ceník dál z katalogu.
- Unit testy ke všem třem.
- *Hotovo 2026-10-15 (`docs/test-corpus.md` § 18, `docs/cli.md`):*
  `fieldStability` srovnává třídy stavů (shoda = `match` i `matchNormalized`),
  nový klíč `formatStability` v souboru výsledků a řádek souhrnu; selhané
  volání nese `CanonicalComparator::evaluateAgainstNothing()` (listy
  očekávání proti prázdnému výstupu — `0` ≡ null se nepočítá, výjimky
  štítků řádků po pozicích, protože prázdný výstup klíč nemá) plus
  klasifikaci, do `fields.*` i `byVariant` / `byForm`; `deviationsByField`
  a stabilita zůstávají jen z úspěšných volání (jinak by jedno selhání
  zhoršilo každý uzel a `ai-eval-compare` hlásil všechna pole);
  řada = první číslo v ID katalogu (`EvalCostEstimator::modelSeries()`),
  běh téže řady se hledá bez ohledu na hash sady (tokeny závisí na `mail/`
  a tokenizéru, ne na `expected/`), v souboru výsledků `counts` i u selhaného
  volání.

## 4. Srovnání modelů (D45)

Varianty v `tests/eval/variants/` (extrakce i štítky na stejném modelu,
`thinking` / `effort` `null` = výchozí deklarace modelu):

| Varianta | Model | Pozn. |
|----------|-------|-------|
| `baseline` | dnešní výchozí z `tiers.jsonc` (Sonnet 4.6) | existuje |
| `sonnet-5-5-tags` | `claude-sonnet-5-5` | existuje |
| `opus-5-5` | `claude-opus-5-5` | nová |
| `haiku-5-5` | `claude-haiku-5-5` | nová |
| `fable-5-1` | `claude-fable-5-1` | nová |

**Běh:**

- Na eval zdroji dat (`vg39-l5b3-seer-242c`, volný) **přeseeduj** sadu
  plátce, protože se změnila. Pak `ds-upgrade` (profil v4.8.0) a pro každou
  variantu `ai-eval … --repeat 3`.
- **Před prvním během zastav a vypiš odhad ceny všech pěti variant**
  (orientačně ~30 USD, nejdražší Fable 5.1 ~15 USD). **Pouštěj až
  po schválení v chatu.**
- Selhání modelu (nedostupný, chybný identifikátor) zapiš a pokračuj
  dalšími variantami.

**Vyhodnocení** (nový § v tasku, formát jako F4 § 10):

- tabulka všech pěti variant: shoda polí po skupinách, doklady bez odchylky,
  správné částky, štítek dokladu, výjimky štítků řádků, selhání, cena na
  doklad, tokeny, čas, rozpětí opakování;
- `ai-eval-compare` každé varianty proti `baseline`; rozdíly v rámci šumu
  označit;
- nálezy po polích (co který model dělá jinak), dopad promptu v4.8.0
  (opravily se tři nálezy?);
- **doporučení pro M4** (výchozí model pro úrovně `fast` / `balanced` /
  `strong`) s cenou na doklad. **Nic se nepřepíná.**

## 5. Srovnání pěti modelů × 3 na promptu v4.8.0 (2026-10-15)

Pět běhů nad sadou `cz-vat-payer` po opravě očekávání (D42, hash sady
`427e8322`) a promptu v4.8.0 (D43), eval zdroj dat `vg39-l` přeseedovaný
(`dataset-seed`, profil v4.8.0, žádný doklad použitý), serverové připojení,
20 zpráv × 3 = 60 volání na variantu, git `18e9dd6e`. Nástroje po D44:
stabilita bez formátu, selhané volání jako `missing` polí, odhad podle
řady. Schválení útraty v chatu (~29 USD); skutečnost 27,98 USD.
Varianty `baseline` (Sonnet 4.6 z mapy úrovní pro extrakci i štítky,
teplota 0) a čtyři s `call.model` i `contentTags.model` na jednom modelu
řady 5 (thinking / effort výchozí, teplotu 0 tvarovač vynechá).

**Fable 5.1 napoprvé selhal celý** (60× `ai_error`, 0 USD): položka
katalogu „jen ceník“ neměla `capabilities`, tvarovač nechal `temperature 0`
z deklarace funkce a API každé volání odmítlo („temperature is deprecated
for this model“). Oprava: blok schopností jako u Opus 5.5 (commit
`ai: katalog — schopnosti Claude Fable 5.1`), `ds-upgrade`, běh znovu;
soubor selhaného běhu v repu není.

| Metrika | baseline (Sonnet 4.6) | sonnet-5-5-tags | opus-5-5 | haiku-5-5 | fable-5-1 |
|---------|-----------------------|-----------------|----------|-----------|-----------|
| Soubor `tests/eval/cz-vat-payer/2026-10-15-…` | `baseline-3` | `sonnet-5-5-tags-2` | `opus-5-5` | `haiku-5-5` | `fable-5-1` |
| Volání / úspěšných / selhání | 60 / 60 / 0 | 60 / 60 / 0 | 60 / 60 / 0 | 60 / 60 / 0 | 60 / 60 / 0 (napodruhé) |
| Shoda polí celkem (match + po normalizaci), průměr [min–max] | 94,1 % [94,1–94,2] (norm. 1,3 %) | **94,8 %** [94,8–94,9] (1,1 %) | 94,4 % [93,7–94,8] (0,8 %) | 94,2 % [94,0–94,3] (1,9 %) | 94,0 % [93,9–94,0] (1,1 %) |
| Hlavička / dodavatel / odběratel | 93,5 / 90,4 / 98,2 % | 93,9 / **92,0** / **99,6** % | **94,4** / 91,0 / 99,3 % | 93,4 / 90,4 / 98,3 % | 92,8 / 90,9 / **99,7** % |
| DPH / řádky / rekapitulace / součty | 99,7 / 92,3 / 100 / 100 % | 99,8 / 92,9 / 100 / 100 % | 99,5 / 92,9 / 100 / 100 % | 100 / 92,3 / 100 / 99,2 % | 98,2 / 92,2 / 100 / 97,1 % [95–100] |
| Štítek dokladu | 100 % | 100 % | 98,3 % [95–100] | 100 % | 100 % |
| Výjimky štítků řádků | 88,4 % [87,8–89,6] | **91,5 %** [91,5–91,5] | 77,4 % [50,5–91,3] (kaskáda 1002, mimo ni 91,3 %) | 88,8 % [88,4–89,6] | 90,3 % [89,4–91,3] |
| Klasifikace zprávy | 100 % | 100 % | 100 % | 100 % | 100 % |
| Doklady bez odchylky | 5,0 % [5–5] | **10,0 %** [10–10] | 8,3 % [5–10] | 3,3 % [0–5] | **0,0 %** [0–0] |
| Správné částky / počet řádků / klasifikace / štítek | 100 / 100 / 100 / 100 % | 100 / 100 / 100 / 100 % | 100 / 100 / 100 / 98,3 % | **96,7** / 100 / 100 / 100 % | **93,3** / 100 / 100 / 100 % |
| Cena běhu (na doklad) | 3,73 USD (0,0621) | 2,89 USD (0,0482) | 5,94 USD (0,0990) | **0,20 USD (0,0033)** | 15,23 USD (0,2539) |
| Tokeny na doklad (vstup / výstup) | 9,6 k / 2,2 k | 11,5 k / 2,5 k | 11,5 k / 2,6 k | 11,5 k / 4,3 k | 11,5 k / 2,8 k |
| Čas na doklad | 31,3 s | **13,9 s** | 20,0 s | 16,0 s | 24,6 s |
| Stabilních uzlů z 85 (věcně) | 80 | 80 | 79 | 71 | 79 |
| Odhad → skutečnost | 4,14 → 3,73 (90 %) | 3,13 → 2,89 (92 %) | 6,25 → 5,94 (95 %) | 0,16 → 0,20 (126 %) | 16,11 → 15,23 (95 %) |

Odhady šly z tokenů na doklad podle běhu téže řady (D44 — po změně hashe
sady není běh téže varianty), s příplatkem za volání štítků; u Haiku
výstup 1,7× delší než u Sonnet 5.5 (odhad bral tokeny Sonnetu), proto
126 %, pořád pod pojistkou 150 %.

**`ai-eval-compare` proti `baseline` × 3** (na jedno opakování; „mimo šum“
= rozdíl nad rozpětím opakování obou běhů):

| Varianta | Celkem | Skupiny mimo šum | Pole zlepšená (odchylek / opak. A → B) | Pole zhoršená |
|----------|--------|------------------|----------------------------------------|---------------|
| `sonnet-5-5-tags` | **+0,7 % lepší** | dodavatel +1,7, odběratel +1,4, řádky +0,5, výjimky řádků +3,1, bez odchylky +5,0 | `customer.taxId` 6 → 1,3, `supplier.taxId` 4 → 0,7, `dates.taxPointDate` 4 → 1, `supplier.bankAccount.iban` 2,3 → 0, `rows[].item.description` 6 → 4, `rows[].priceCalcMode` 2 → 0, výjimky řádků 4,7 → 3 | `notes.onDocument` 8 → 9, `payment.paymentReference` 2 → 2,7 |
| `opus-5-5` | +0,2 % v rámci šumu | hlavička +0,9, dodavatel +0,7, odběratel +1,1, řádky +0,6; štítek −1,7 a výjimky −11,0 jen kaskáda 1002/3 | `dates.taxPointDate` 4 → 0, `customer.taxId` 6 → 2,3, iban 2,3 → 0, výjimky řádků 4,7 → 3, `description` 6 → 4,3, `priceCalcMode` 2 → 0,7 | `payment.paymentReference` 2 → 3 |
| `haiku-5-5` | +0,0 % v rámci šumu | DPH +0,3; součty −0,8 a správné částky −3,3 (dvě chybějící `totalAmount`) | iban 2,3 → 0, `taxPointDate` 4 → 2, `customer.taxId` 6 → 4,3, `supplier.taxId` 4 → 2,3, `description` 6 → 4,7, `priceCalcMode` 2 → 1, `supplyKind` 0,7 → 0 | `rows[].discountAmount` 0 → 4, `supplier.courtRegistration` 12 → 16, `notes.onDocument` 8 → 9,7, `customer.vatId` 0 → 1, `rows[].item.name` 0 → 1, `rows[].unit` 0 → 0,7, `totals.totalAmount` 0 → 0,7 |
| `fable-5-1` | −0,2 % horší (proti Sonnet 5.5 −0,9 %) | dodavatel +0,5, odběratel +1,5, výjimky řádků +2,0; DPH −1,6 horší, bez odchylky −5,0 horší; součty −2,9 a správné částky −6,7 v rámci šumu | `customer.taxId` 6 → 1, iban 2,3 → 0, `dates.taxPointDate` 4 → 2,3, výjimky řádků 4,7 → 3,7 (proti Sonnet 5.5 nic) | `rows[].vat.pct` 0,3 → 4, `payment.paymentReference` 2 → 4,3, `notes.onDocument` 8 → 10, `totals.totalBase` 0 → 1,3, `totals.totalVat` 0 → 1, `rows[].item.description` 6 → 6,7, `rows[].priceCalcMode` 2 → 2,7 |

**Dopad promptu v4.8.0 na tři nálezy F4 § 10** (per opakování, u řady 5
dřív 0702 bankovní spojení špatně nebo chybí ve 4 z 6 volání, 0602
`reverseCharge: true` 3×, 0801 / 0802 adresa rozdělená 3× + 3×):

- **0702 bankovní spojení** — `supplier.bankAccount` správně ve všech
  opakováních u všech pěti modelů (0 / 3 odchylek na `accountNumber`
  i `iban`), nic pod `payment`, žádný `schema_error`. **Opraveno.**
  Zbývá u všech `courtRegistration` bez úvodu „Spisová značka“ a poznámka
  s „Nejsem plátce DPH“ navíc; Haiku 2×, Opus a Fable 3× navíc nevyplní
  výjimku `travel.fares` na řádku 1, Fable dává řádkům `vat.pct` 0.
- **0602 `reverseCharge`** — `false` ve všech opakováních u Sonnet 5.5,
  Opus, Haiku i Fable (4.6 ho nikdy nedával). **Opraveno.** Zbývá řádek 2
  (`supplyKind` `services` → `goods`, Sonnet 5.5 i Opus 3×, 4.6 2×)
  a poznámka = text čl. 138 místo výhrady vlastnictví — obojí v promptu
  neřešené (poznámka je společná mezera níže).
- **0801 / 0802 adresa** — `street` + `houseNumber` správně ve všech
  opakováních u všech modelů. **Opraveno.** 4.6 jednou „CA 94105“ v PSČ.
- Baseline (4.6) prompt nezměnil: celkem 94,1 % jako F4 § 10 A, štítek
  dokladu 93,2 → 100 % je oprava očekávání (D42), IBAN 3,3 → 2,3 / opak.
  táž vložená nula (0101 3×, 0102 2×, 1001, 1002), tentokrát bez selhání
  (§ 10 A dvakrát mimo JSON u 1002 — nedeterminismus, ne oprava).
  Sonnet 5.5 celkem 94,2 → 94,8 % (prompt + očekávání).

**Nálezy po polích — společné všem modelům** (prompt / očekávání, ne
model; podklad #118): `supplier.contact.web` 16 / opak. (nikdo
nevyplňuje), `supplier.courtRegistration` 12 (Haiku 16 — i jiný tvar),
`rows[].item.supplierCode` 10, `notes.onDocument` 8–9,7 (text o DPH
nebo neplátcovství místo volné poznámky), `dates.periodFrom` / `periodTo`
4 / 4 (období v popisu řádku), `rows[].item.description` 4–6,
`rows[].item.ean` 2, `payment.paymentReference` 2–3 (číslo faktury jako
VS u EU dokladů).

**Co který model dělá jinak:**

- *Sonnet 4.6* — IBAN systematicky špatně 7 z 60 (táž vložená nula,
  deterministické; kontrolní součet #118), `dates.taxPointDate` navíc
  u dokladů bez DPH 4 / opak., `customer.taxId` 6 (vyplní jen `vatId`),
  `priceCalcMode` 2 (`fromTotal` na prodejkách); 3× pomalejší než 5.5.
- *Sonnet 5.5* — nejčistší: IBAN 0 špatně (jen mezery po čtveřicích —
  formátová stabilita 60 %), `taxId` 1,3 / 0,7, žádné selhání; drobně
  horší poznámka (9) a VS u EU faktur (2,7); nestabilita jen
  `customer.taxId` 83 % a `paymentReference` 85 %.
- *Opus 5.5* — v hodnotovém jádru shodný se Sonnet 5.5, `supplier.taxId`
  4 / opak. jako 4.6 (EU dodavatel), jedna kaskáda štítku 1002/3
  (`people.catering` → 94 odchylek), VS 3; 2× dražší a 1,4× pomalejší
  než Sonnet 5.5 — na této sadě nic navíc.
- *Haiku 5.5* — dopočítává a překládá: `rows[].discountAmount` z procenta
  4 / opak. (doklad částku slevy nemá), jednotka „pcs“ → „ks“ 0,7, název
  položky s množstvím, jednou vymyšlený specifický symbol; **2× ze 60
  chybí `totals.totalAmount`** (0701, 0801 — doklad bez částky k úhradě),
  `customer.vatId` 1; stabilita 71 z 85 uzlů; výstup 1,7× delší; cena
  0,0033 USD / doklad = 19× levnější než 4.6, 15× než Sonnet 5.5.
- *Fable 5.1* — nejdražší a na této úloze nejslabší z řady 5:
  `rows[].vat.pct` 0 místo null u dokladů bez DPH (0702, 0801; 4 / opak.),
  `totals.totalBase` / `totalVat` dopočtené u dokladu bez rekapitulace
  (0701, 0801, 0802 — proto správné částky 93,3 %), číslo faktury jako VS
  u zahraničních faktur 4,3 / opak. (0501, 0502, 0601 3×), na prodejkách
  poznámka „Děkujeme za návštěvu“ navíc 3× + 3× (proto 0 dokladů bez
  odchylky), `supplier.taxId` 4 jako 4.6 a Opus, `priceCalcMode` 2,7;
  výjimky řádků 90,3 % druhé nejlepší, IBAN 0 špatně, bez selhání; 5,3×
  dražší a 1,8× pomalejší než Sonnet 5.5.

**Doporučení pro M4** (`modules/core/ai/config/tiers.jsonc`, nic se
nepřepíná — rozhodne člověk):

| Úroveň | Dnes | Doporučení | Cena / doklad | Proč |
|--------|------|------------|---------------|------|
| `balanced` (extrakce `mail.extraction`) | Sonnet 4.6 | **Sonnet 5.5** | 0,048 USD (4.6: 0,062) | nejvyšší shoda 94,8 % (+0,7 mimo šum), 2× víc dokladů bez odchylky, 0 špatných IBAN, bez selhání, 2,25× rychlejší, −22 % ceny; tři nálezy řady 5 prompt v4.8.0 odstranil |
| `strong` | Sonnet 4.6 | **Sonnet 5.5** (Opus 5.5 jen s důvodem) | 0,048 (Opus 0,099) | Opus na této sadě nepřináší nic navíc (94,4 % v rámci šumu, jedna kaskáda štítku), stojí 2× a je pomalejší; Fable 5.1 je proti Sonnet 5.5 horší (−0,9 % mimo šum, 0 dokladů bez odchylky) za 5,3× cenu |
| `fast` (štítky `exchange.contentTags`, shrnutí) | Sonnet 4.6 | **Haiku 5.5 pro štítky**, ne pro extrakci | 0,0033 (celý doklad) | štítek dokladu 100 %, výjimky řádků 88,8 % = 4.6 (Sonnet 5.5 91,5 %); pro extrakci ne: 2 ze 60 bez celkové částky, dopočítané slevy, překlad jednotek, nejnižší stabilita |

Před přepnutím `balanced` ještě: varianta s explicitním `thinking` /
`effort` (bod 1 závěru F4 § 9 zůstává otevřený) — tento běh jel s výchozím
adaptivním thinking; a produkční cesta má dostat kontrolní součet IBAN
a upozornění „bez bankovního spojení“ (#118), protože 4.6 IBAN komolí
deterministicky a 5.5 ho jen jinak formátuje.

## Kroky

1. Oprava očekávání (D42), build, ověření snímků.
2. Prompt v4.8.0 (D43), changelog.
3. Nástroje evaluace (D44) + unit testy.
4. Varianty (D45).
5. Přeseedování eval zdroje dat, `ds-upgrade`, odhad ceny — **zastavit se**.
6. Po schválení běhy × 3, vyhodnocení, commit výsledků.
7. Dokumentace (`docs/test-corpus.md` — metriky stability a selhání,
   odhad ceny), hlavička tasku.

## Hotovo když

- [x] korpus: dlouhé doklady `office.cleaning`; `CorpusExpectedTest` zelený;
      `dataset-verify` na třech volných zdrojích dat 1–5 `ok` beze změny snímků
      (2026-10-15, po reseedu — `--no-apply` by měřil staré návrhy)
- [x] prompt v4.8.0 v šabloně i changelogu, eval zdroj dat ho má
- [x] unit testy nástrojů (stabilita, selhání v metrikách, odhad) zelené
- [x] pět běhů × 3 v `tests/eval/cz-vat-payer/` (nebo zapsané selhání varianty)
      — 2026-10-15, 27,98 USD; Fable 5.1 napoprvé selhal (katalog bez
      `capabilities`), po opravě proběhl
- [x] vyhodnocení v tasku: tabulka, porovnání proti baseline, nálezy,
      doporučení pro M4 (§ 5)
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Očekávání se mění jen tady a jen u štítku dlouhých dokladů** (schváleno).
  Další nesoulad modelu s očekáváním se nepřepisuje — zapsat a zeptat se.
- **Prompt nesmí znát korpus.** Pravidla obecně, bez čísel dokladů a názvů
  fiktivních firem. Jinak se evaluace měří proti promptu, který zná test.
- **Útrata až po schválení.** Odhad je orientační; když běh překročí odhad
  o víc než 50 %, další variantu nepouštěj a zeptej se.
- **Výchozí model se nepřepíná** — rozhodnutí M4 je lidské.

## Rozhodnutí k designu (potvrzená)

- ✓ **D42 — Štítek dlouhých dokladů korpusu je `office.cleaning`**
  (chyba očekávání, ne modelu); deník a snímky beze změny.
- ✓ **D43 — Prompt v4.8.0:** bankovní spojení vždy do `supplier.bankAccount`,
  `reverseCharge` jen při výslovném přenesení (čl. 138 = `false`), číslo domu
  odděleně jen tam, kde následuje za ulicí.
- ✓ **D44 — Nástroje evaluace:** stabilita bez formátových rozdílů, selhaná
  volání v metrikách polí, odhad ceny z modelu téže řady.
- ✓ **D45 — Srovnání pěti modelů × 3** na promptu v4.8.0 (Sonnet 4.6,
  Sonnet 5.5, Opus 5.5, Haiku 5.5, Fable 5.1) po schválení útraty.
- ✓ **D46 — Přepnutí výchozího modelu (M4) není součástí tasku**; doporučení
  ano, rozhodnutí lidské.
