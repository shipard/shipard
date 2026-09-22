# Saldokonto — nejdelší shodný prefix účtu vyhrává (#69 D22)

**Stav:** částečně — generátor, testy a docs hotové 2026-09-22 (2 commity, bez změny schématu); lookup přednost neuplatňuje (skupinu nese `balance` v klíči, `docs/accbal.md` §5.1); zbývá `btpg-p`: přesun 325201/325202 do Přijatých záloh (nastavení, David) + `accbal-regenerate --all` + kontrola 13 / ≈86 a výsledek do #69

## Kontext

`LedgerGenerator` vybírá řádky nastavení prefixem (`311` chytí `311100`).
Když sedí víc řádků téže strany účtu z různých skupin (seed má `325`
v Závazcích, účetní přidá `325201` do Přijatých záloh), vzniknou pohyby
v obou skupinách. Na `btpg-p` jsou přijaté zálohy vedle 324001 i na
325201/325202 (88 případů / 714 850 v roce 2026 leží dnes v Závazcích —
starý systém je má v Přijatých zálohách podle operace řádku, kterou import
mapuje na `acc.item`/`acc.entry`). Nastavení per DS je správná páka, ale
bez přednosti prefixu se použít nedá.

**D22:** Když na řádek deníku sedí víc řádků nastavení téže strany účtu
platných k datu, použijí se jen ty s nejdelším prefixem; kratší prefixy
jsou vyloučeny. Platí pro všechna místa výběru v generátoru i pro lookup.

## Před implementací přečti

- `docs/accbal.md` §3.2 (nastavení, prefix), §4.2 (kroky generátoru
  D17), §5.1 (lookup D19).
- `modules/economy/accbal/src/LedgerGenerator.php` — `candidatesFor()`,
  `requestGroup()`, `paymentGroup()`, `settingsCandidates()`,
  `matchesPrefix()`.
- `modules/economy/accbal/src/LedgerOpenItemLookup.php` —
  `targetsForDirection()`, `loadKeyRows()` (prefixy `LIKE`).
- `tests/Unit/Module/Economy/Accbal/LedgerOpenItemLookupTest.php`,
  existující testy generátoru (`buildDesired` je veřejná kvůli testům).

## 1. Generátor

- Jedna společná funkce (např. `matchingAccounts(row, accountNumber,
  date, accounts, acc_side?)`) vrátí řádky nastavení platné k datu, jejichž
  prefix sedí, **zúžené na nejdelší prefix per `acc_side`** (délka
  `account_number` po `trim`). Řádky téže délky z různých skupin zůstávají
  všechny (týž účet vědomě ve dvou skupinách — dnešní chování, např. 315).
- `requestGroup()` a `paymentGroup()` hledají skupinu jen mezi těmito
  řádky; `settingsCandidates()` iteruje jen tyto řádky (sign-pravidla a
  `modify_sign` se účastní přednosti stejně — `325201` v Přijatých zálohách
  vyřadí i `creditNoteRule` řádek `325` v Závazcích, pokud by existoval).
- Přednost se určuje per strana účtu (`acc_side`), ne globálně: řádek
  `325201 DAL` v jedné skupině nevyřadí `325 MD` v jiné.

## 2. Lookup

`LedgerOpenItemLookup`: cílové skupiny a jejich prefixy (§5.1) —
pokud po D19 ještě filtruje řádky klíče prefixem, filtr zrušit (řádky
ledgeru už skupinu mají) nebo uplatnit stejnou přednost. Zdokumentuj,
která varianta platí.

**Zvoleno (2026-09-22): filtr zůstává, přednost se neuplatňuje.** Filtr
není redundantní — prefixy skupiny bez `modify_sign` jsou jediné, co
skrývá sign-ruled řádky (dobropis 311 v Závazcích). Přednost je per řádek
deníku a per datum: pohyb na 325201 z doby před `valid_from` řádku
Přijatých záloh leží v Závazcích a lookup ho přes `325` musí najít;
skupinu nese `balance` v klíči dotazu. Viz `docs/accbal.md` §5.1.

## 3. Nastavení — validace

Řádek nastavení, jehož prefix je **stejný** jako řádek jiné skupiny téže
strany, zůstává povolen (vědomé zdvojení). Do `docs/accbal.md` §3.2
napiš přednost a příklad 325 / 325201; do formuláře řádku žádná změna.

## Testy (úzký `--filter`)

- Unit `buildDesired`: nastavení `325` Závazky + `325201` Přijaté zálohy,
  řádek na 325201 → jediný pohyb v Přijatých zálohách; řádek na 325101 →
  Závazky. Totéž s `payment.payable` na 325201 → úhrada v Přijatých
  zálohách. Stejná délka ve dvou skupinách → dva pohyby (regrese).
- Unit lookup podle zvolené varianty (§2).

## Hotovo když

- Testy zelené, `git diff` po každém patchi, `docs/accbal.md` aktualizován.
- `btpg-p` po `ds-upgrade` + ručním přesunu 325201/325202 do Přijatých
  záloh (nastavení, David) + `accbal-regenerate --all`: rok 2026 Závazky
  **13 případů**, Přijaté zálohy ≈ 86 otevřených na 325.2xx / 714 850 +
  páry 324 (starý: 11 / 238 798 a 86 / 714 850 — rozdíl 2 částečně placené
  faktury je vysvětlen). Výsledek do #69.
