# Testování F4b: oprava očekávání, prompt v4.8.0 a srovnání modelů řady 5

**Stav:** naplánováno — design schválen 2026-10-15 (#103 D42–D46), čeká na implementaci

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
- Build (korpus a sady), `CorpusExpectedTest`.
- `dataset-verify --no-apply` na třech volných zdrojích dat z F3: kontroly
  1–5 `ok` beze změny snímků. Kdyby se snímek změnil, **zastav se**:
  znamenalo by to, že `office.cleaning` jde jinam než `goods.stock`.
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

- [ ] korpus: dlouhé doklady `office.cleaning`; `CorpusExpectedTest` zelený;
      `dataset-verify` na třech volných zdrojích dat 1–5 `ok` beze změny snímků
- [ ] prompt v4.8.0 v šabloně i changelogu, eval zdroj dat ho má
- [ ] unit testy nástrojů (stabilita, selhání v metrikách, odhad) zelené
- [ ] pět běhů × 3 v `tests/eval/cz-vat-payer/` (nebo zapsané selhání varianty)
- [ ] vyhodnocení v tasku: tabulka, porovnání proti baseline, nálezy,
      doporučení pro M4
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

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
