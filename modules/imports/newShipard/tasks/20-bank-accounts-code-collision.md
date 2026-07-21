# 20 — BankAccountsRunner: kolize kódů účtů (duplicitní `id` ve zdroji)

## Kontext

Původní plný import lefreal (log `import-20260716-181100.log`) tiše
nedoimportoval **oba hlavní FIO účty** (old ndx 9, 10):

```
✗ Failed bank-account (old ndx=9): POST …/economy_codebooks_bank_accounts
  → HTTP 500: INTERNAL_ERROR — Dibi\UniqueConstraintViolationException:
  Duplicate entry '3' for key 'unq_code'
```

`BankAccountsRunner::buildCanonical()` odvozuje `code` ze starého sloupce
`id` (`deriveCode($oldRow['id'], ndx, 'BA')`). Starý Shipard ale unikátnost
`id` nevynucuje — lefreal má duplicity **i mezi aktivními účty**:

| old ndx | id | účet | docState |
|---|---|---|---|
| 4 | 3 | USD 5188612/0800 | 4000 |
| 9 | 3 | FIO CZK 2702433563/2010 | 4000 |
| 1 | 4 | CZK 2272472369/0800 | 4000 |
| 10 | 4 | FIO EUR 2602433566/2010 | 4000 |

Nová strana má `unq_code` → druhý účet se stejným kódem spadne na 500
a **kaskáda**: 504 výpisů FIO účtů soft-skip („own bank account not in
LocalIdMap"), **252 dokladů** importováno jako koncept bez účtování
(„own bank account unresolved; importing issued invoice as draft").
msi-zlin bez duplicit — nezasaženo.

## Scope

### 1. `libs/runners/BankAccountsRunner.php` — deterministická deduplikace kódů

V `run()`/`buildCanonical()`: před zpracováním projít celou zdrojovou sadu
a spočítat výskyty odvozených kódů. První výskyt (nejnižší ndx) si kód
nechá, každý další dostane deterministický suffix `{code}-{ndx}`
(např. `3-9` pro FIO CZK). Prázdné/NULL `id` beze změny (stávající
fallback `BA{ndx}`). Warn při každém přejmenování, ať je to v logu vidět.
Idempotentní: odvození závisí jen na zdrojových datech.

### 2. Poznámka pro novou stranu (samostatné rozhodnutí)

Generic CRUD POST mapuje `Dibi\UniqueConstraintViolationException` na
HTTP 500 INTERNAL_ERROR — správně má vracet 422 validation_failed
s názvem klíče. Hardening mimo tento task; založit v nov_shipard, pokud
souhlas.

## Oprava dat (lefreal, po nasazení)

1. Fáze `bank-accounts` (ndx 9, 10 nejsou v LocalIdMap → naimportují se).
2. Plain re-run fáze `bank-statements` (504 skipnutých výpisů v mapě není).
3. Ověřím read-only: aktivní účty 5, výpisy 121 → 625 (622 + koncepty?),
   viz počty per účet proti zdroji.
4. **252 konceptových dokladů** v mapě je — cílená oprava by chtěla
   `forget --entity=doc` + re-run docs; doporučuji nechat na plný
   re-import lefreal po vlně C (spraví zároveň slité výpisy,
   viz `bank-statement-identity.md` v nov_shipard).

## Hotovo když

- [x] Duplicitní `id` ve zdroji nezpůsobí selhání účtu; druhý účet dostane
      `{code}-{ndx}` + warn. Ověřeno: FIO účty importovány s kódy `3-9`
      a `4-10`.
- [x] Po re-runu na lefreal: 5 aktivních + 2 archivní účty, výpisy FIO
      účtů naimportované (410 + 94 = 504), „not in LocalIdMap" warny
      zmizely (0 v posledním logu). Ověřeno read-only 2026-07-20;
      rekonciliace lefreal nevyrovnaná jen u 3 výpisů s nulovými
      zdrojovými zůstatky.
