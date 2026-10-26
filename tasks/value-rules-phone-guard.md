# Telefon: normalizovat jen hodnotu, která vypadá jako jedno číslo

**Stav:** hotovo — 2026-10-26 (#118 D26); `values-normalize --dry-run` na kopiích reálných dat znovu, ostrý běh pouští člověk


## Kontext / Cíl

`Phone::normalize()` z hodnoty vyhodí všechno kromě číslic a podle země
doplní předvolbu. To porušuje D1 („normalizace mění tvar, ne obsah“):

- pole se dvěma čísly (`577 123 456, 777 888 999`) se slije do jednoho
  nesmyslného čísla;
- číslo s linkou (`577 123 456 linka 25`) přijde o oddělení linky;
- národní číslo nečekané délky dostane předvolbu (14 číslic bez `+` →
  17místné „mezinárodní“ číslo).

V kopiích reálných dat jsou takové hodnoty ojedinělé (agregovaně: pár
čísel nestandardní délky; adresy webu v poli telefonu nemají číslice
a zůstávají beze změny). V canonicalu z AI ale přijít mohou a ostrý běh
`values-normalize` je přepíše natrvalo — proto oprava před ním.

## Návaznost

- **#118** D1, D8, D12 (pravidla telefonu), D26 (níže)
- `tasks/value-rules.md`, `tasks/value-rules-phase2.md`,
  `tasks/value-rules-backfill.md`

## Před implementací přečti

- `modules/world/validations/src/Phone.php`, `CountryValueRules.php`
  (`phonePrefix()`, `trunkPrefix()`, `nationalNumberLength()`)
- `tests/Fixtures/World/value-cases.jsonc` (případy telefonu)
- `modules/world/validations/README.md` (telefon)

## Rozhodnutí

- **D26 — Telefon se normalizuje jen jako jedno číslo.** Jinak zůstane
  hodnota beze změny (jen ořezaná) — bez nálezu, telefon nálezy nevyrábí
  (D12):
  - víc než 15 číslic (E.164) → beze změny;
  - mezinárodní zápis (`+`, `00`): 7–15 číslic → `+` a číslice, jinak beze
    změny;
  - národní zápis: předvolba jen tehdy, když po odebrání národního
    předčíslí sedí délka (`nationalNumberLength()`, cz a sk 9 číslic);
    země bez délky (de) → když výsledek nepřekročí 15 číslic; země bez
    předvolby → beze změny;
  - `(0)` v mezinárodním zápisu se dál zahazuje; písmena kolem jednoho
    čísla (`tel. 577 123 456`) normalizaci nebrání, pokud ostatní pravidla
    sedí.

  Formátovač (D21) nekanonickou hodnotu ukáže beze změny — tak už funguje.

## Kroky

1. `Phone::normalize()` podle D26; případy do `value-cases.jsonc`
   (dvě čísla v poli, číslo s linkou, 14 číslic bez `+`, `tel. 577 123 456`,
   `00420…`, `+49 (0)30 …`, slovenské s `0`); README; `**Stav:**`,
   `python3 scripts/tasks-index.py` → commit
   `fix(world): telefon normalizovat jen jako jedno číslo (#118 D26)`

## Hotovo když

- [x] Nové případy ve fixture procházejí, stávající beze změny.
- [x] `vendor/bin/phpunit --filter` na `World|Phone|ValueCases|PersonValues|CanonicalValue|CorpusValueRules|DirectoryValuesNormalizer`;
      strážce korpusu (normalizace očekávání je identita) dál prochází.
- [x] `values-normalize --dry-run` na kopiích reálných dat znovu; do
      závěrečné zprávy počty změn telefonů před a po (agregovaně).

## Výsledek (2026-10-26)

Upřesnění D26 při implementaci: stát bez předvolby (us, bez země) = do
15 číslic dál jen číslice (stávající případy zůstaly), nad 15 beze
změny; stát bez délky (de) má u národního zápisu totéž okno 7–15 číslic
jako mezinárodní zápis (krátký útržek předvolbu nedostane). Jediná změna
ve stávajících testech: 7 číslic u cz (`0201304`) už předvolbu nedostane
a osoba bez adresy se slovenským číslem pod zemí zdroje dat cz se
v `DirectoryValuesNormalizerTest` neslije.

`values-normalize --dry-run` na kopiích reálných dat (změny telefonů
před → po, agregovaně): btpg-p 1 300 → 1 299, e8w1-i 62 → 62, 4dnh-5
9 → 9, ur4l-0 12 → 12, nelh-0 5 → 5, p4zq-s 22 → 22; ostatní sloupce
a nevalidní hodnoty beze změny. Srovnání staré a nové normalizace nad
všemi telefony šesti kopií (read-only SQL): jiný výsledek má jediná
hodnota — národní zápis s 12 číslicemi na btpg-p, nově beze změny.
Hodnoty s písmeny v poli telefonu jsou jen webové adresy bez číslic
(beze změny dřív i teď).

## Pasti

- **Na kopiích reálných dat jen `--dry-run`.**
- Fixture čte i `test_checks.py`, ale jen kontroly, které `checks.py` umí —
  telefon ne; ověř, že Python testy dál projdou.
