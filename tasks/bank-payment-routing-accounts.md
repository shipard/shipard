# Banka — lookup otevřeného předpisu přes všechny účty skupiny (oprava T1)

**Stav:** hotovo — 2026-09-13 (oprava T1 `bank-payment-routing.md`, #69 D3);
zbývá ověření `accbal-match --all --dry-run` na reimportovaném DS

## Kontext

Ověření T1 nad DS importovaným ze starého Shipardu: engine položil ~6 500
úhrad přímo na účty předpisů, na clearingu zůstalo ~1 400 plateb. Z nich
**363 plateb (~6,8 mil. HC)** má přesnou shodu klíče `(partner, VS, SS,
měna)` s otevřeným předpisem, a přesto nesedly — předpis je na účtu **336**,
**331**, **325**, **343**, **345**, **379** (pojištění, daně, mzdy, ostatní
závazky) nebo **315** (ostatní pohledávky), a `LedgerOpenItemLookup` vidí jen
účty s prefixem slučitelným s 311/321.

Příčina je ve formulaci zadání T1 §1 („skupina, která má `balance_accounts`
s prefixem 311/321") — myšleno jako výběr *skupiny*, implementováno jako
filtr *účtů* (`targetsForDirection()`, řádek s `str_starts_with`, a
navazující filtr řádků ledgeru prefixem). Správný model: **směr určuje
skupiny** přes přirozenou stranu předpisu (`acc_side`, to SQL už dělá),
cílem jsou **všechny předpisové účty** těchto skupin.

## Před implementací přečti

- `modules/economy/accbal/src/LedgerOpenItemLookup.php` — třídní komentář,
  `DIRECTION_PREFIX`, `targetsForDirection()`, dotaz na řádky ledgeru.
- `tasks/bank-payment-routing.md` §1 a „Poznámky k implementaci" (proč
  `modify_sign = 0` a `amounts_sign IN (0,1)` — to **zůstává**).
- `tests/Integration/Accbal/LedgerOpenItemLookupTest.php`.
- Nastavení saldokont v seedu (`modules/economy/accbal/config/…`) — které
  účty mají skupiny `receivables`/`payables`.

## Změna

1. `targetsForDirection()`: odstranit prefixový filtr proti
   `DIRECTION_PREFIX`. Cíle = všechny řádky `balance_accounts` s `bal_side = 0`,
   `modify_sign = 0`, `amounts_sign IN (0,1)`, `acc_side` = přirozená strana
   směru, v aktivním stavu. Každý řádek dává dvojici `(balance, prefix účtu z
   nastavení)` — prefix „336" pokryje 336101, „311" pokryje 311100 atd.
   Deduplikace `(balance, prefix)` zůstává. `DIRECTION_PREFIX` smazat;
   `DIRECTION_SIDE` zůstává jediným zdrojem směru.
2. Dotaz na řádky ledgeru klíče: místo jednoho prefixu filtrovat
   `account_number LIKE` přes **všechny** prefixy cílové skupiny (nebo bez
   filtru účtu — řádky skupiny s klíčem jsou předpisy a úhrady téhož případu;
   zvol jednodušší variantu, ale jednu definici sdílenou s T2 `CaseQuery`).
3. Vrácený `OpenItem::accountNumber` = účet skutečného předpisu (např.
   `336101`), stejně jako dnes u 311.
4. Třídní komentář a `docs/accbal.md §5.1` (věta o prefixu) upravit.

Nic jiného se nemění — engine, handler, router, kontrakt v2 beze změny.

## Testy

PHPUnit jen s úzkým `--filter`.

- `LedgerOpenItemLookupTest`: předpis na `336101` (payables) → výdaj se
  najde a `accountNumber = 336101`; předpis na `315100` (receivables) →
  příjem se najde; předpis na 321 s `modify_sign = 1` ve skupině
  pohledávek se pro příjem nenajde (dnešní chování).
- `BankPaymentRoutingTest`: transakce výdaj + předpis na 336 → zaúčtuje se
  na 336101, ne na 261300.

## Ověření po nasazení (Claude v chatu, read-only)

`shpd-ds accbal-match --all --dry-run` na reimportovaném DS: plán ≥ 360
přeúčtování; poté rozbor zbylých 12 plateb s přesnou shodou na 311/321,
které nesedly ani před opravou.

## Hotovo když

- [x] `grep -n DIRECTION_PREFIX modules/economy/accbal/src/*.php` nic nenajde
- [x] testy výše zelené, `BankPaymentRoutingTest` beze změny chování pro 311/321
- [x] `docs/accbal.md §5.1` bez zmínky o prefixu 311/321
- [x] hlavička tasku `**Stav:** hotovo` + `python3 scripts/tasks-index.py`
      ve stejném commitu

## Rozhodnutí k designu (potvrzená)

- ✓ Směr → skupiny přes `acc_side` předpisu; cílem všechny předpisové účty
  skupiny, ne jen 311/321 (#69 D3, upřesnění po ověření 2026-09-13).
- ✓ `modify_sign = 0` a `amounts_sign IN (0,1)` zůstávají.
