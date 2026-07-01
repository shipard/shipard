# Účetní doklady (cmnbkp) — Doplněk Fáze 4b (import): operation-default účet + neúčtovatelné doklady

## Kontext

Doplněk k `11-cmnbkp-import.md`. Řeší řádky cmnbkp, kde účet **není** na řádku
(`debsAccountId` prázdné) ani z položky (`item` prázdné) — účet se ve starém
odvozoval z operace (`acc-default.json`). Rozložení v datech (operation-default
řádky):

| kód | operace | nová cesta | počet |
|---|---|---|---|
| 1090001 | Zápočet pohledávky | `acc.balanceReceivable` (cat 311) | 12181 |
| 1090002 | Zápočet závazku | `acc.balancePayable` (cat 321) | 228 |
| 1090012 | Kurz. rozdíl závazku | ⚠ neúčtovatelné | 3 |
| 1090070 | Zařazení majetku | ⚠ neúčtovatelné | 24 |
| 1090071 | Vyřazení majetku | ⚠ neúčtovatelné | 36 |
| 1090072 | Odpis majetku | ⚠ neúčtovatelné | 148 |
| 1090073 | Tech. zhodnocení majetku | ⚠ neúčtovatelné | 5 |
| 1099998 | Účetní položka (bez položky) | ⚠ neúčtovatelné | 1 |

Saldokontní (1090001/1090002) řeší nové operace z doplňku Fáze 2
(`accounting-docs-phase2-balance-ops.md`, **nasadit první**). Majetkové a
kurzové operace se ve starém účtovaly přes „Druh dokladu" na hlavičce
(účet z modulu majetku / kurzových účtů) — nový Shipard to zatím nemá. V této
fázi je **neúčtujeme**: doklad se naimportuje kompletně, ale ve sníženém stavu,
takže se nezaúčtuje (a nic se neztratí ani nelže o zaúčtování).

## Cíl

`DocsRunner` importuje všechny cmnbkp:

- řádky se saldokontní operací dostanou novou operaci (`acc.balanceReceivable` /
  `acc.balancePayable`) → účet z kategorie na nové straně → doklad vyrovnaný a
  zaúčtovaný (stav 40);
- doklad s libovolnou **neúčtovatelnou** operací (majetek/kurz/vadný) se
  naimportuje s číslem a kompletními řádky, ale **cílový stav max 20**
  (Potvrzeno) → nezaúčtuje se → žádná tvrdá chyba, žádné tiché rozbití.

## Návaznost

- **Doplňuje:** `11-cmnbkp-import.md`.
- **Páruje se s:** `nov_shipard` `tasks/accounting-docs-phase2-balance-ops.md`
  (**nová strana první** — operace `acc.balanceReceivable`/`acc.balancePayable`
  musí existovat).

## Před implementací přečti

- `modules/imports/newShipard/libs/runners/DocsRunner.php` — cmnbkp větev
  `buildCanonical` + `loadCmnbkpRows` (z `11-cmnbkp-import.md`), `targetDocState`
  / `stateHasNumber` (stropování stavu jako u vlastního bank účtu invno),
  `DOC_STATE_MAP_TARGET`.
- `nov_shipard` `tasks/accounting-docs-phase2-balance-ops.md` — nové saldo
  operace.

## Scope

`loadCmnbkpRows` (výběr operace per řádek) + `buildCanonical` cmnbkp větev
(stropování stavu). **Nesahat na:** import faktur, applier.

## Co implementovat

### 1. Mapa starých operací → nové (výběr per řádek)

V `loadCmnbkpRows` určit operaci a zdroj účtu v tomto pořadí:

1. `debsAccountId` neprázdné → `acc.record`, `account` = `debsAccountId`.
2. `item > 0` → `acc.item`, `item` pin (LocalIdMap).
3. operace ∈ saldokontní mapě → category operace, bez účtu/položky:
   ```php
   private const CMNBKP_BALANCE_OP = [
       1090001 => 'acc.balanceReceivable',
       1090002 => 'acc.balancePayable',
   ];
   ```
4. jinak (1090012, 1090070–73, 1099998 bez položky, neznámé) →
   **neúčtovatelné**: operace `acc.record` s `account = null` (řádek se uloží,
   nese stranu/částku/partnera/symboly/text), starý kód operace zachovat do
   `source.raw.oldOperation` kvůli dohledatelnosti.

### 2. Stropování stavu u neúčtovatelného dokladu

V `buildCanonical` (cmnbkp větev): pokud `loadCmnbkpRows` označí, že doklad
obsahuje **aspoň jeden neúčtovatelný řádek** (krok 4 výše), zastropovat
`targetState = min(targetState, 20)` + `warn("doc {ndx}: contains
non-accountable operation(s) (asset/fx), importing as Confirmed (20), not
posted")`. Číslo + řada se přidělí stejně jako u jiných stavů s číslem
(20 ∈ `STATES_WITH_NUMBER`).

Návratová hodnota `loadCmnbkpRows` se rozšíří o příznak
`hasNonAccountable` (bool) vedle `rows` + `resolve`.

## Hotovo když

- Doklady jen se saldokontními / acc.record / acc.item řádky jdou do **40**,
  vyrovnané, zaúčtované (deník + saldo) — ověřit na vzorku zápočtových dokladů
  (1090001).
- Doklad s majetkovou/kurzovou operací jde do **20**: má číslo, kompletní
  řádky, **není** zaúčtovaný; v logu warning. Žádná tvrdá chyba importu.
- Žádný cmnbkp doklad se neztratí (nevyřazuje se kvůli chybějícímu účtu).
- Series summary sedí; import faktur a saldokontních cmnbkp beze změny.

## Doporučené pořadí

1. `CMNBKP_BALANCE_OP` mapa + krok 4 (neúčtovatelné) v `loadCmnbkpRows`.
2. `hasNonAccountable` příznak + stropování stavu v `buildCanonical`.
3. Běh na vzorku (limit, nejdřív zápočtové, pak majetkové) → kontrola stavů
   (40 vs 20), deníku + salda na nové straně.

## Rozhodnutí ✓

- Saldokontní řádky → category operace (účet 311/321 na nové straně).
- Neúčtovatelné (majetek/kurz/vadné) → doklad ve stavu **20** (warning), ne
  tvrdá chyba, ne tiché rozbití ve 40.
- Účtování majetku/kurzu přes „Druh dokladu" — budoucí fáze.

## Otevřené body

- Po doplnění účtování majetku/kurzu lze tyto doklady povýšit 20 → 40
  (zaúčtují se); zvážit re-run importu nebo hromadné povýšení.
- README (`tasks/`) — spravuje David.
