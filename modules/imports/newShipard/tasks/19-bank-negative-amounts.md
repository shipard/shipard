# 19 — BankStatementsRunner: záporné credit/debit řádky (ztracené vratky)

## Kontext

Na dev DS (msi-zlin `btpg-peg5-b0tr-chln`) je 95 výpisů s alertem
`economy.bank.reconciliation_errors` — počáteční zůstatek + obraty
neodpovídají koncovému. Diagnóza proti starým datům (`msiu70160`):

Starý Shipard ukládá **vratky jako záporné hodnoty** ve sloupci původního
směru — vratka přijaté platby je `credit < 0` (typicky refundace
k dobropisu, VS odkazuje na náš dobropis), vratka odchozí platby je
`debit < 0`. `BankStatementsRunner::loadTransactions()` (~ř. 218) ale
staví částku vzorcem

```php
'amount' => $credit > 0 ? $credit : -$debit,
```

který pro `credit < 0, debit = 0` vrátí `-0.0` — nová strana
(`StatementImportService`) nulovou transakci zahodí. Transakce **v nové DB
vůbec není** (ověřeno přes `external_id`: `old:11280`, `old:29713`,
`old:41063` chybí). Záporný `debit` projde jen náhodou správně
(`-(-x) = +x` = příjem, což u vratky odchozí platby sedí; ověřeno
`old:278`, `old:299`, `old:232292` — direction 1, kladná částka).

Docblock metody tvrdí „ověřeno: žádný řádek nemá credit i debit zároveň" —
ověření nepokrylo záporné hodnoty.

Rozsah ve zdrojových datech (bank doklady, docState 4000):

| DS | `credit < 0` | `debit < 0` | dopad |
|---|---|---|---|
| msi-zlin (`msiu70160`) | 194 | 88 | 95 nevyrovnaných výpisů |
| lefreal (`33271805401633`) | 0 | 13 | 0 (debit prošel náhodou) |

Zbylé alerty jsou výpisy, které **nesedí už ve zdrojových datech** —
migrace je přenesla věrně, alert je oprávněný, kód se nemění. Jen se doplní
warn při importu (viz Scope 2), ať se to u budoucích DS ví hned.

> **Ověřeno proti `msiu70160` (2026-07-20, read-only):** po opravě
> znaménkového vzorce klesnou nevyrovnané výpisy na msi-zlin z ~95 na
> **1** (ne na 8, jak odhadovala původní verze tasku). Tím jedním je
> **ndx 3477** (docOrderNumber 1): `balance −80 707,80`, obrat
> `−80 708,80` → reálný **rozdíl 1 Kč** ve zdroji, nenulový zůstatek.
> Nulových výpisů (`initBalance = 0 AND balance = 0`) je 200, ale **všechny
> mají znaménkový obrat = 0** → reconciliují (0+0=0), alert by neměly.
> Výpisů s „nulové zůstatky + nenulový obrat" je **0** — původní predikát
> warnu by se nikdy nespustil. Proto Scope 2 rozšířen o obecný
> reconciliation-mismatch (viz níže).

## Návaznost

- **Task 11** (`11-bank-statements.md`) — původní import výpisů; tento
  task opravuje jeho `loadTransactions()`.
- **Nová strana:** `StatementImportService` dělá find-or-create výpisu
  a per-transakci dedup (`external_id` / `fingerprint`) s backfillem
  existujících — cílený re-import doplní jen chybějící transakce,
  hlavičky výpisů se neduplikují.
- Oprava DPH okrajů na nové straně (`tasks/docs-vat-totals-reverse-charge.md`
  v nov_shipard) — nezávislá; společně jsou předpokladem plného
  re-importu dev DS.

## Scope

### 1. `libs/runners/BankStatementsRunner.php` — znaménková částka

V `loadTransactions()` nahradit skip podmínku i výpočet částky:

```php
$amount = $credit - $debit;   // znaménková: credit +, debit −; vratky
                              // (záporný credit/debit) se otočí přirozeně
if ($amount == 0.0)
    continue;   // řádek bez pohybu peněz (informativní, nebo nettovaná nula)
```

Sémantika: `credit −615` → `amount = −615` (odchozí vratka přijaté
platby), `debit −88` → `amount = +88` (příchozí vratka odchozí platby) —
stejné výsledky jako dosud u kladných hodnot, správné u záporných.

Aktualizovat docblock metody: popsat konvenci záporných hodnot ve starých
datech a smazat nepravdivou poznámku o „žádný řádek nemá credit i debit
zároveň" (vzorec `credit − debit` je vůči tomu robustní tak jako tak).

### 2. `buildCanonical()` — warn na nesedící rekonciliaci zdroje

Po sestavení transakcí zkontrolovat `initBalance + Σ(znaménkové částky) == balance`.
Když neplatí, jsou zdrojová data vadná (starý doklad nesedí sám v sobě) — warn
zviditelní vadu v čase importu místo až v alertech. Dvě větve (výsledek volby
„obojí"):

- **Podtyp nulové zůstatky + reálný pohyb** (`initBalance == 0 && balance == 0
  && turnover != 0`): nejnázornější případ, zvláštní hláška
  `"statement {ndx}: zero balances with non-zero turnover ({suma}) — source
  data issue, will fail reconciliation"`.
- **Obecný mismatch** (`round(initBalance + turnover − balance, 2) != 0`):
  `"statement {ndx}: reconciliation mismatch — initBalance {i} + turnover {t}
  != balance {b} — source data issue, will fail reconciliation"`. Chytá zbytek
  (haléřové rozdíly u nenulových zůstatků, např. ndx 3477).

Import pokračuje beze změny (věrný přenos je správně). Na msi-zlin warn ohlásí
**1 výpis** (ndx 3477), obecnou větví.

### 3. Ověření (dry-run, bez zápisů)

```
… bank-statements --dry-run --dump-payload   (omezené na starý doklad 19494)
```

Payload výpisu 3/2017 (old ndx 19494, msi-zlin) musí obsahovat **21
transakcí**: 19 záporných (−615 … −6 256), +412, +1 000 — dnes jich
posílá 2 správně a 19 s nulou.

## Oprava dat

- **Dev DS:** primárně plný re-import po dokončení všech migračních oprav
  (vlna C — zálohové/majetkové řádky). Pokud bude potřeba banka dřív,
  cíleně: `forget --entity=bank-statement` + fáze `bank-statements` —
  backfill na nové straně doplní jen chybějící transakce. Po doběhnutí
  ověřím read-only rekonciliaci: očekávám pokles nevyrovnaných výpisů
  na msi-zlin na **1** (ndx 3477 — reálný 1Kč rozdíl ve zdroji; ověřeno
  proti `msiu70160`, viz Kontext).
- **Alfa:** stejná chyba je i v datech alfy (importována stejným
  runnerem) — rozhodnutí o opravě (forget+rerun vs. plný re-import)
  samostatně, mimo tento task.

## Hotovo když

- [x] `loadTransactions()` počítá `amount = credit − debit`, skip jen při
      výsledné nule; docblock popisuje záporné vratky.
- [x] Doklad 19494 dá 21 transakcí se správnými znaménky (19× záporné
      −615…−6 256, +412, −1 000); obrat −53 268,00 = delta zůstatků.
      Ověřeno přímým dotazem proti `msiu70160` (runner nemá per-ndx filtr;
      logika `credit − debit` ověřena na zdrojových řádcích).
- [x] Warn na nesedící rekonciliaci zdroje (podtyp nulové zůstatky +
      obecný mismatch). Na msi-zlin ohlásí 1 výpis (ndx 3477, rozdíl 1 Kč).
- [ ] Po re-importu (až proběhne): rekonciliace na msi-zlin nevyrovnaná
      jen u 8 výpisů s nulovými zdrojovými zůstatky; `external_id`
      `old:11280`, `old:29713`, `old:41063` existují v nové DB se
      zápornou stranou (direction 2).
