# 21 — DocsRunner: klasifikace řádků a mapování záloh/majetku (vlna C)

> **Design:** `docs/design-import-row-operations.md` v nov_shipard
> (D1–D7 potvrzeno) — tento task je implementačním kontraktem D3 + D5
> pro migrační stranu.
> **Závislost:** nejdřív musí být nasazený nov_shipard PRD
> `tasks/docs-advance-asset-row-operations.md` (operace + předpis +
> ds-upgrade), jinak applier nové operace odmítne.
> **Návaznost:** task 10 (docs import), task 11-cmnbkp (kontační řádky —
> vzor payloadu s účtem).

## Cíl

Řádky bez vazby na položku přestávají degradovat na text: zálohové,
majetkové a kategorie-účtované řádky se přenášejí jako operační řádky
s účtem, penězi a DPH. Řádek s penězi bez mapované operace je hlasitá
chyba, nikdy tichá ztráta.

## Scope — `libs/runners/DocsRunner.php`

### 1. Mapovací tabulka operací (`mapRowOperation` + nová data)

| stará op | nová op | účet (pravidlo) | payment_reference |
|---|---|---|---|
| 1020101 | `purchase.advanceDeduction` | `tax ≠ 0 → 314901`, `tax = 0 → 314001` | `symbol1` |
| 1020104 | `purchase.advanceVat` | `314901` (tax ≠ 0 z definice — totéž pravidlo) | `symbol1` |
| 1010101 | `sale.advanceDeduction` | `tax ≠ 0 → 324901`, `tax = 0 → 324001` | `symbol1` |
| 1010104 | `sale.advanceVat` | `324901` | `symbol1` |
| 1090050 | `purchase.asset` | `042002` | — |
| 1090051 | `purchase.asset` | `042001` | — |
| 1090052 | `purchase.asset` | `501101` | — |

Dosavadní mapování 1020101/1020104/1010101/1010104 → `acc.entry` zrušit.
Kategorie ops (1010102/1010199 → purchase.goods; 1010001/02/99, 1090060 →
sale.*) zůstávají — mění se jen klasifikace (bod 2), item už není
podmínkou.

### 2. Klasifikace řádků v `loadRows()` (D3)

Pořadí vyhodnocení:
1. **operační řádek s účtem** — stará op je v tabulce bodu 1: payload
   s `operation`, `account` (dle pravidla), signed částkami (odpočty
   záporné!), DPH poli a `paymentReference` (je-li ve sloupci); item se
   neposílá. Payload shape dle cmnbkp kontační cesty (task 11).
2. **item řádek** — `itemCode`/`item > 0`: beze změny (vč. stávajícího
   mapování kategorie ops).
3. **operační řádek bez účtu** — mapovaná kategorie op (purchase.*/
   sale.*) bez item: payload s `operation` + penězi + DPH, bez `account`
   (účet dodá kategorie předpisu 504/518/548/6xx), bez item.
4. **textový řádek** — jen řádek **bez peněz** (`priceAll` prázdné/0
   a `credit`/`debit` 0).
5. **chyba** — řádek s penězi a nemapovanou operací: doklad selže
   s chybou (počítá se do errors, `--continue-on-error` pokračuje dalším
   dokladem). Žádný tichý text.

`acc_side` se neposílá — stranu určuje krok předpisu per operace.

### 3. Kontrolní krok D5 pro nové DS

Před zapnutím importu na dalším DS ověřit pravidlo účtů proti tamnímu
deníku. Kontrolní dotaz (starý deník účtuje zálohy v základu, proto
`ABS(j.money) = ABS(r.priceAll)`; prázdné accountDr/CrId je `''`, ne
NULL — CONCAT obou dá vždy právě jeden účet):

```sql
SELECT h.docType, r.operation AS oldOp, (r.tax = 0) AS tax0,
       CONCAT(j.accountDrId, j.accountCrId) AS account, COUNT(*) AS rowsMatched
FROM e10doc_core_rows r
JOIN e10doc_core_heads h ON h.ndx = r.document AND h.docState <> 9800
JOIN e10doc_debs_journal j ON j.document = r.document AND j.text = r.text
    AND ABS(j.money - ABS(r.priceAll)) < 0.005
WHERE (h.docType = 'invni' AND r.operation IN (1020101, 1020104, 1090050, 1090051, 1090052))
   OR (h.docType = 'invno' AND r.operation IN (1010101, 1010104))
GROUP BY h.docType, r.operation, tax0, account
ORDER BY h.docType, r.operation, tax0, account;
```

Pravidlo sedí, když každá kombinace (docType, oldOp, tax0) padá právě
na jeden účet — ten z tabulky bodu 1.

**msi (2026-07-21): sedí 100 %.** 1020101: tax≠0 → 314901 (227),
tax=0 → 314001 (249); 1020104: vše → 314901 (205); 1090050 → 042002
(83), 1090051 → 042001 (68), 1090052 → 501101 (120); invno 1010101:
tax≠0 → 324901 (1), tax=0 → 324001 (1 033); 1010104 → 324901 (1).
Žádná kombinace mimo pravidlo.

**lefreal (2026-07-21): NESEDÍ — jiný účtový rozvrh.** Deník: 1020101
tax≠0 → 314900 (109), tax=0 → 314100 (50); 1020104 → 314900 (56);
1090050 → 042500 (5, jeden doklad i 042300); invno 1010101 → 324100
(15); 1010104 → 311100 (10). Účty 314001/314901/324001/324901/042001/
042002 v lefreal rozvrhu (`e10doc_debs_accounts`) vůbec neexistují —
applier by je nedohledal a řádky by šly bez účtu. Mapovací tabulka
bodu 1 s pevnými účty byla msi-specific → vyřešeno dodatkem D8/D9
níže (zálohy bez účtu, majetek per řádek z deníku).

Dotaz zůstává jako **akceptační kontrola před importem každého nového
DS**: ověřit, že zálohové analytiky v tamním deníku odpovídají maskám
kategorií `advances.given`/`advances.received` na nové straně
(tax=0 → maska `314`/`324`, tax≠0 → `3149`/`3249`; lefreal 314100/
314900/324100 maskám vyhovuje, msi 314001/314901/324001/324901 také).
Nesoulad řešit předem (úprava masek kategorie per DS), ne po importu.

## Ověření (dry-run, bez zápisů)

`--dry-run --dump-payload` na vzorových dokladech:

- **49264 / 22010540** (invni): řádek `purchase.asset` účet 501101
  (+35 981,82 / DPH 7 556,18) + 2× `purchase.advanceDeduction` účet
  314901, záporné částky, payment_reference 220203512 / 220203650.
- **56036 / 22210024** (invni): `purchase.advanceVat` 314901 (+7 290
  vč. DPH) + `purchase.advanceDeduction` 314001 (−7 290, tax0,
  payment_reference 8060926543).
- libovolný invno s 1010101: `sale.advanceDeduction` 324001, záporná
  částka.
- kontrolní negativní případ: řádek s penězi a smyšlenou operací →
  chyba dokladu, ne text.

## Oprava dat

Plný re-import obou dev DS (D6) — samostatný krok po dokončení obou PRD;
zahrne i 252 konceptů lefreal a definitivní srovnání výpisů. Akceptace
(provedu read-only): old↔new kontrola COUNT + SUM per
`LEFT(doc_number, 3)` sedí u invni i invno na korunu (modulo
koncepty/storna), účetní alerty jen akceptované případy, deník všude
vyrovnaný.

## Dodatek 2026-07-20 — D8/D9 (potvrzeno)

Nahrazuje pevné účty v mapovací tabulce bodu 1 — analytiky nejsou
univerzální (lefreal: zálohy 314900/314100/324100, majetek per řádek
042100/042500). Zdůvodnění v dodatku designu
`docs/design-import-row-operations.md` (nov_shipard). Strany a struktura
platí globálně — předpis se nemění.

### D8 — zálohové účty z per-DS configu

**ZRUŠENO 2026-07-20 ve prospěch D10** (kategorie předpisu na nové
straně — viz dodatek designu a PRD
`tasks/docs-advance-asset-row-operations.md`). Migrace účty záloh
**neodesílá vůbec**: zálohový řádek nese jen `operation`, signed částky,
DPH pole a `paymentReference`; účet dohledá kategorie
(`advances.given`/`advances.received`) per DS maskou v jeho rozvrhu.
Z toho plyne pro DocsRunner:

- z mapy operací odstranit `accountTax`/`accountNoTax`/`configKey*`
  a veškerou práci s `advanceAccounts` configem (sekce se do
  `config/import-newShipard.json` nezavádí);
- fail-fast kontrola configu a validate sonda se ruší (není co
  konfigurovat; nedohledatelný účet se projeví standardně přes
  `is_error` řádek deníku a účetní alert);
- dotaz D5 zůstává jako dokumentovaná **akceptační kontrola** před
  importem každého nového DS: porovnat masky 314/3149/324/3249 proti
  tamnímu `e10doc_debs_journal` (a při nesouladu řešit předem, ne po
  importu).

### D9 — majetkový účet per řádek ze starého deníku

Zrušit literály `account` u 1090050/51/52; účet řádku dohledává per-row
lookup (deníkové řádky majetku nesou `property` — silný klíč), částky
v domácí měně (`taxBaseHc` ↔ `moneyDr`), majetková operace bez
`property` na řádku = chyba dokladu.

**Zjištění z dat (2026-07-21):** samotná přesná shoda nestačí —
(a) starý deník **agreguje** řádky téhož (účet, property) do jedné
položky (msi 11 řádků / 6 dokladů, lefreal 1 řádek: např. doc 32268
řádky 43 043,80 + 519,00 → deník 43 562,80), (b) 2 doklady mají
v deníku **korigovanou částku** (msi 38830: 12 086,76 vs 12 076,86;
lefreal 7490: 119 850,00 vs 119 850,41). Proto vrstvený lookup
(`resolveAssetAccount`), každý krok bere jen jednoznačný výsledek:

1. přesná shoda: `document + property + moneyDr = taxBaseHc` → právě 1
   záznam → `accountDrId`;
2. deníková agregace: `SUM(taxBaseHc)` přes řádky dokladu se stejnou
   (property, operation) proti `moneyDr` → právě 1 → účet;
3. korekce částek: jediný `DISTINCT accountDrId` na (document,
   property) s `moneyDr > 0` mimo DPH (`343%`) a zaokrouhlení
   (`548%`) → účet;
4. jinak chyba dokladu (režim D3d) s diagnostikou počtů kandidátů.

Pokrytí ověřeno: msi 257/257 a lefreal 5/5 majetkových řádků (kroky
1/2/3 = 246+10+1 na msi, 4+0+1 na lefreal). Doklad bez deníku
(koncept/storno) s majetkovým řádkem skončí hlasitou chybou — na obou
DS dnes neexistuje.

### Ověření dodatku (dry-run)

- msi 49264: odpočty bez účtu v payloadu (dohledá kategorie), majetek
  501101 z joinu;
- lefreal vzorek 1020101 a 1090050 (042100 vs 042500 per řádek dle
  deníku);
- po re-importu vzorku: deník odpočtů na správných analytikách obou DS
  (msi 314901/314001/324901/324001, lefreal 314900/314100/324100).

Provedeno 2026-07-21 (dry-run, sandbox mimo DS): msi 49264 — odpočty
`account: null` + payment_reference, majetek 501101 z deníku; 56036 —
oba zálohové řádky bez účtu; 32268 — 2 agregované řádky → 042002
(krok 2); 38830 — korigovaná částka → 501101 (krok 3). Lefreal: 1274/
1450 odpočty bez účtu; majetek per řádek 4204/4331 → 042500, 5334/
7490/7543 → 042100 (7490 přes krok 3). Negativní případ: syntetický
majetkový řádek bez property → doklad FAILED s důvodem, exit 2,
`--continue-on-error` pokračoval. Re-import vzorku (poslední bod) je
součást D6.

### Hotovo když (dodatek)

- [x] Zálohové řádky bez účtu v payloadu; žádný `advanceAccounts` config,
      žádné literály účtů záloh v DocsRunneru.
- [x] Majetkové účty per řádek z deníku (vrstvený property lookup);
      nejednoznačnost nebo chybějící property = chyba dokladu.
- [x] Dry-run ověření výše na msi i lefreal (viz „Provedeno 2026-07-21“).
- [x] Dotaz D5 zdokumentován jako akceptační kontrola pro každý nový DS
      (masky 314/3149/324/3249 — sekce 3).

## Hotovo když

- [x] Mapovací tabulka dle bodu 1 vč. zrušení acc.entry fallbacků pro
      zálohy.
- [x] Klasifikace dle bodu 2; text jen bez peněz; nemapovaná op s penězi
      = chyba (ověřeno syntetickým řádkem s op 9999999 → doklad FAILED,
      exit 2, `--continue-on-error` pokračoval).
- [x] Dry-run payloady 49264, 56036 a invno vzorků (30614 tax0 →
      324001, 44062 tax≠0 → 324901) odpovídají očekávání výše.
- [x] Kontrolní dotaz D5 zdokumentovaný v tasku a spuštěný na msi
      i lefreal — msi sedí 100 %, **lefreal má jiný rozvrh** (viz bod 3);
      před D6 na lefreal nutno vyřešit per-DS účty.
