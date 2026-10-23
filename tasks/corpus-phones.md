# Korpus: telefonní čísla fiktivních identit z nepřidělitelného rozsahu

**Stav:** hotovo — 2026-10-23 (#132, #103 D90); zadání 2026-10-23

## Kontext / Cíl

Fiktivní identity korpusu (`tests/corpus/cz/identities.jsonc`) mají
telefonní čísla, která vypadají jako skutečné pevné linky (české krajské
předvolby, německá čísla). IČO, DIČ, domény, účty i spisové značky jsou
ověřeně fiktivní, telefony ne — číslo může patřit existující firmě nebo
člověku. Repo je veřejné a čísla se tisknou do PDF korpusu.

**Cíl:** stejné pravidlo jako u demo sady „IT servis“ (#131,
`demo/datasets/README.md`): národní číslo začíná `000`, což se
nepřiděluje. Pravidlo hlídá build, aby platilo i pro nové identity.

Úprava mění obsah i hash všech tří sad korpusu, proto jde před
evaluacemi neplátce a spolku (#103 D90).

## Návaznost

- **#132** (zadání, dopad), **#103** D90 (pořadí)
- **#131** — totéž pravidlo v demo sadě (`demo/datasets/stories/`)
- `docs/test-corpus.md` § 3 (identity), § 11 (tvary hodnot), § 12 (build)

## Před implementací přečti

- `docs/test-corpus.md` § 3, § 11, § 12, § 14
- `tests/corpus/cz/identities.jsonc` (hlavička a sekce s kontakty)
- `tools/invoice-generator/invoicegen/checks.py` (`phone_canonical`,
  `validate_identities`), `render.py` (`fmt_phone` — délka kódu země)
- `tests/Unit/Module/Core/Exchange/Compare/ValueShapesTest.php`,
  `CanonicalComparatorTest.php` (telefon v testovacích datech)

## 1. Pravidlo a kontrola v buildu

- **Pravidlo:** první tři číslice národního čísla (za kódem země) se
  nahradí `000`, délka zůstává. `+420585201304` → `+420000201304`,
  `+493028874410` → `+490008874410`. Koncovky zůstávají, čísla jsou
  dál navzájem různá.
- **Výjimka:** americké číslo z vyhrazeného rozsahu 555-01xx
  (`+1 …555 01xx`) zůstává beze změny.
- `checks.validate_identities` hlásí telefon, který pravidlo nesplňuje
  (s výjimkou výše). Délku kódu země určovat stejně jako `fmt_phone` —
  jedna pomocná funkce, ne druhá kopie tabulky.
- Unit test generátoru na obě větve (porušení pravidla, výjimka 555-01xx).

## 2. Identity a přegenerování

- Upravit všechny telefony v `identities.jsonc` podle pravidla; do
  hlavičky souboru připsat pravidlo jednou větou.
- Přegenerovat korpus a sady běžným buildem
  (`tests/corpus/cz/build.py`, § 12) — PDF, ISDOC, `expected/`,
  `build-state.json` a `tests/datasets/cz/*`.
- **Kontrola rozsahu změny:** v textových souborech (`expected/`,
  `tests/datasets/cz/*/mail/` mimo PDF, ISDOC) se smí změnit jen
  telefony a odvozené hodnoty (hash sady, otisky). Ověřit `git diff
  --word-diff` nad textovými soubory a vypsat počet změněných řádků,
  které telefon neobsahují — musí to být jen hashe a otisky.

## 3. Testy a uložené výsledky evaluací

- `ValueShapesTest`, `CanonicalComparatorTest`: nahradit telefon novou
  hodnotou podle pravidla (v obou tvarech — s mezerami i bez).
- `tests/eval/cz-vat-payer/*.json`: staré telefony nahradit novými
  **mechanicky ve všech tvarech**, ve kterých se v souborech vyskytují
  (kanonický, s mezerami, výstupy modelu v jiném formátu). Nic jiného
  se v souborech nemění — metriky, hash sady a struktura zůstávají.
  Před úpravou vypsat všechny nalezené tvary každého čísla
  a po úpravě ověřit, že `grep` na národní části starých čísel nic
  nenajde. Běhy zůstávají „nad starší sadou“ (jiný hash) — to je
  správně.

## 4. Dokumentace

- `docs/test-corpus.md` § 3: odrážka o telefonech (pravidlo `000`,
  výjimka 555-01xx, kontrola v buildu).
- `docs/test-corpus.md` § 15 (Pasti): telefon mimo pravidlo build odmítne.

## Kroky

1. Identity, přegenerování korpusu a sad, kontrola rozsahu změny →
   commit `fix(corpus): telefony identit s předčíslím 000, přegenerovaný korpus a sady (#132)`
2. Kontrola pravidla v `checks.py` + unit test → commit
   `feat(corpus): kontrola telefonů fiktivních identit v buildu (#132)`
3. Unit testy porovnání a uložené výsledky evaluací → commit
   `test: telefony korpusu v testech porovnání a výsledcích evaluací (#132)`
4. Dokumentace, `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`
   → commit `docs(corpus): pravidlo telefonů identit (#132)`

## Hotovo když

- [x] Všechny telefony v `identities.jsonc` splňují pravidlo, kromě
      čísla 555-01xx.
- [x] Build telefon mimo pravidlo odmítne (unit test).
- [x] Korpus a sady přegenerované; v textových souborech se změnily jen
      telefony, hashe a otisky (výpis z kontroly v kroku 2 v závěrečné
      zprávě).
- [x] `vendor/bin/phpunit --filter 'CorpusExpectedTest|ValueShapesTest|CanonicalComparatorTest|ProjectionTest|IsdocCorpusRoundTripTest'` projde.
- [x] Testy generátoru (`tools/invoice-generator`) projdou.
- [x] `grep` na národní části starých čísel v `tests/` nic nenajde.
- [x] `shpd-ds dataset-verify` nad všemi třemi sadami na čerstvě
      naseedovaném volném zdroji dat: kontroly 1–6 `ok`.
- [x] `docs/test-corpus.md` § 3 a § 15 aktualizované, `**Stav:**`
      a index tasků.

## Pasti

- **Demo (`demo/invoices/`) telefony nemá** — beze změny. Demo sada
  „IT servis“ pravidlo už splňuje.
- **Dataset-dump musí dál sedět bajtově** se sadou (F2) — telefony jdou
  přes `dumpshape.py` beze změny tvaru.
- **Model může čísla začínající `000` „opravovat“.** Neřešit v tomto
  tasku; ukáže to další evaluace (#103 D90).
