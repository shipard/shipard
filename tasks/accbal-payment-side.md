# Saldokonto — předpis/úhradu určuje strana řádku i u `payment.*` (#69 D23)

**Stav:** částečně — generátor, viewer, testy a docs hotové 2026-09-22 (3 commity, bez změny schématu); zbývá `btpg-p` po `old_shipard` task 41 + reimportu: `accbal-regenerate --all`, kontrola Přijaté zálohy 2026 (bankovní 324 jako předpisy, `bal_side` 0, +) a Poskytnuté zálohy ≈ starý 17 / 178 078, výsledek do #69

## Kontext

D17 (2) dělá z každé operace `payment.*` úhradu (`bal_side` 1) se znaménkem
podle strany. Na zálohových skupinách je to naopak: bankovní příjem na
324 DAL je **předpis** přijaté zálohy, výdaj na 314 MD předpis poskytnuté
(starý modul `balance`: `bank side 0 = request`). Na `btpg-p` 2026 je tak
253 bankovních příjmů na 324 v ledgeru jako úhrada −1 101 574 a jeden
výdaj na 314 jako úhrada −3 632; reziduum sedí, druh pohybu ne, a lookup
pro zálohu nemá co najít.

**D23:** Pro všechny operace včetně `payment.*` platí totéž co pro operace
se stranou: řádek na předpisové straně skupiny je předpis, na opačné straně
úhrada, znaménko částky se zachová. Vratka na 311 MD je tím předpis +
(reziduum a D19 lookup beze změny).

## Před implementací přečti

- `docs/accbal.md` §4.2 (kroky D17, prefix D22), §5.1 (D19).
- `modules/economy/accbal/src/LedgerGenerator.php` — `candidatesFor()`
  větev `OperationSides::PAYMENT`, `paymentGroup()`, `requestGroup()`,
  `rowSide()`; `OperationSides`.
- `modules/economy/accbal/src/LedgerViewer.php` / `CasesViewer.php` —
  popis druhu pohybu.
- `docs/bank.md` — fáze „zálohy, zápočty, kurzové rozdíle“ (mimo scope,
  jen odkaz).

## 1. Generátor

- Větev `PAYMENT` v `candidatesFor()` sjednotit s větví operací se
  stranou: skupina = `paymentGroup()` (skupina, která účet řádku sleduje,
  po D22 nejdelší prefix); `bal_side` = 0, když strana řádku ==
  `request_side` skupiny, jinak 1; `sign` = +1 vždy.
- `paymentGroup()` musí vracet i `request_side` (dnes jen `payment_side`);
  jde-li o týž výpočet jako v `requestGroup()`, sloučit.
- Odstranit z docstringu `buildDesired()` a z §4.2 formulaci „`payment.*`
  = vždy úhrada … − na opačné (vratka)“.

## 2. Viewer

Pohyb ze zdroje `bankTransaction` popisovat jako „platba“ (příjem/výdaj
podle strany), ne „předpis“/„úhrada“ z `bal_side`; `bal_side` zůstává
datově. Ověř `LedgerViewer` a detail případu; pokud popis bere z jedné
mapy, stačí podmínka na `source_kind`.

## 3. Dokumentace

`docs/accbal.md` §4.2 (D23 jako jediné pravidlo strany), §3.3 (`bal_side`
u bankovních pohybů), §12 (srovnání záloh se starým: `bank side 0 =
request`).

## Testy (úzký `--filter`)

- Unit `buildDesired`: `payment.in` na 324 DAL +100 → předpis +100
  v Přijatých zálohách; `payment.out` na 314 MD +100 → předpis +100
  v Poskytnutých zálohách; `payment.receivable` na 311 DAL +100 → úhrada
  +100; `payment.receivable` na 311 MD +100 → předpis +100 (vratka).
- Regrese D19: reziduum přeplatkového případu po vratce = 0.

## Hotovo když

- Testy zelené, `git diff` po každém patchi, docs aktualizovány.
- `btpg-p` po `accbal-regenerate --all` (a po task 41 + reimportu):
  rok 2026 Přijaté zálohy — bankovní řádky 324 jako předpisy (`bal_side`
  0, +), Poskytnuté zálohy ≈ starý 17 / 178 078 (SELECT z #69 komentáře
  2026-09-22). Výsledek do #69.
