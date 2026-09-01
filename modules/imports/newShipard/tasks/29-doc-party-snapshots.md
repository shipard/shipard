# 29 — Snapshoty partnera na importovaných dokladech (dobové DIČ pro KH)

**Stav:** Stará strana hotová (DocsRunner), čeká na novou stranu + ověření
**Issue (nová strana):** shipard/shpd#55 — nález z verifikace fáze 0+1 (`tasks/taxes-phase01.md`)
**Návaznost:** nová strana `tasks/docs-import-party-snapshots.md` — musí být
hotová a nasazená dřív, než poběží ostrý re-import (applier jinak snapshot
z kanonického payloadu zahodí).

## Kontext / problém

Kontrolní hlášení čte DIČ partnera výhradně ze snapshotů dokladu
(`docs_core_heads.customer_snapshot` / `supplier_snapshot` — viz
`economy.taxes/src/VatDocumentSelection::vatIdFromSnapshot`). Import
snapshoty **záměrně** nechává NULL (`DocDocument::$importMode`: „building
snapshots from today's person data for historical documents would be
factually wrong") — důsledek na migrovaných DS:

- sekce **A4 nemůže nikdy vzniknout** (vyžaduje CZ DIČ odběratele) — na alfě
  (qrce, Q1/2026) padá všech 74 výstupních dokladů do A5, včetně 15 nad
  10 tis. Kč,
- řádky **B2 jsou bez DIČ dodavatele** (14× warning `vatKh.missingVatId`
  = přesně 14 vstupů nad limit),
- SH má řádky bez DIČ.

Původní princip ale **neporušujeme, nýbrž naplňujeme**: starý Shipard nese
dobové DIČ přímo na hlavičce dokladu (`e10doc_core_heads.personVATIN` —
stejné pole, ze kterého četl starý `VatCSEngine`). Dobová pravda tedy
existuje a do snapshotu patří ona; zbytek snapshotu (jméno, adresa) se bere
ze starého adresáře v okamžiku exportu, což je nejlepší dostupná aproximace
a `source_kind = import.oldShipard` na hlavičce provenienci přiznává.

Žádný SQL backfill — oprava dat = `ds-reset` + re-import (zavedený vzor).

## Scope — stará strana (`modules/imports/newShipard/libs/runners/DocsRunner.php`)

### 1. `buildCanonical()` — dobové DIČ přebíjí adresářové

Po `loadParty($partnerNdx)` (vzor: existující override `headerBank`):

- `personVATIN` z `$oldRow` (trim); je-li neprázdné, nastavit
  `$partnerParty['vatId']` na tuto hodnotu. Prázdné `personVATIN` →
  ponechat adresářový fallback z `loadParty()` (properties `taxid`).
- Platí pro obě fakturové větve (`invno` → customer, `invni` → supplier).
  `cmnbkp` bez obchodních stran se nemění.

### 2. Statistiky pro dry-run

Do souhrnu runneru přidat počty: dokladů s dobovým `personVATIN`,
s adresářovým fallbackem, bez DIČ. Bez toho se nedá před ostrým re-importem
odhadnout, kolik `missingVatId` warningů je legitimních.

## Stav implementace — stará strana

Hotovo v `DocsRunner`:

- `buildCanonical()` — za override `headerBank` přibyl override DIČ:
  `personVATIN` z hlavičky (přes `emptyToNull`, tj. trim + prázdné → null)
  přepíše `$partnerParty['vatId']`; prázdné ponechá adresářový fallback
  z `loadParty()`. Neshoda hlavičky s adresářem se loguje na `debug`.
  Společné pro obě fakturové větve (partner je táž strana), `cmnbkp`
  beze změny — `loadParty()` tam vůbec nevolá.
- `recordVatIdSource()` / `printVatIdSummary()` — souhrn per docType
  `header` / `directory` / `none` na konci běhu (vedle `printSeriesSummary()`).
  Zapisuje se až po dostavění dokladu, aby soft-skipy a tvrdé chyby souhrn
  nezkreslovaly.
- Navíc proti PRD: sloupec `unpinned partner` — počet dokladů, kde partner
  není v `LocalIdMap`. Tam `vatId` slouží na nové straně i jako business klíč
  dohledání osoby (pin `_resolve.<side>.userAction = useExisting:<id>` chybí),
  takže dobová hodnota může trefit jinou osobu než adresářová. Při řádném
  pořadí `persons → docs` má být 0.

Souhrn počítá jen doklady, které běh skutečně stavěl — už namapované
`processOneRow()` skipuje před `buildCanonical()`, takže dry-run nad
naimportovaným DS vypíše prázdno. Počty přes celý zdroj nezávisle na stavu
mapy dá pre-flight SQL v README (sekce Doklady).

## Scope — nová strana (viz `tasks/docs-import-party-snapshots.md` v shpd)

Shrnutí kontraktu (implementace je úkol nové strany):

- `DocumentApplier` složí z kanonické strany partnera snapshot ve tvaru
  `PersonSnapshotBuilder` (`name`, `company_id`, `tax_id`, `vat_id`,
  `address{…}`, `bank_account{…}`) a předá ho do `$data`.
- `DocDocument` v import módu partnerský snapshot **persistuje z payloadu**
  (nestaví z dnešního adresáře); vlastní strana se staví standardně
  (vlastní firma + `bank_account` + `vat_registration` z hlavičky).
  Sloupec dle `trade_dir` — stejná větev jako `buildSnapshots()`.
- Exchange schema se nemění (`vatId` ve straně už je).

## Ověření / Hotovo když

- [ ] Dry-run DS qrce vypíše statistiky DIČ (bod 2) a žádné nové chyby.
- [ ] Po `ds-reset` + re-importu (per-DS, operativní rozhodnutí David):
      importované faktury mají neprázdné oba snapshoty; `vat_id`
      partnerské strany = staré `personVATIN` tam, kde bylo vyplněné.
- [ ] KH živě (qrce, Q1/2026): sekce **A4 obsahuje výstupy nad 10 tis.
      s CZ DIČ**, B2 řádky nesou DIČ, `vatKh.missingVatId` zbývá jen
      u dokladů s prázdným `personVATIN` ve zdroji.
- [ ] Přiznání a křížová kontrola beze změny (snapshoty nemění částky):
      qrce Q1/2026 ř. 64 = 99 042,86, rozdíly proti deníku 0.

Kontrolní SQL na nové straně (read-only, claude_ro):

```sql
SELECT COUNT(*) AS docs,
 SUM(customer_snapshot IS NULL OR customer_snapshot='') AS cust_empty,
 SUM(supplier_snapshot IS NULL OR supplier_snapshot='') AS supp_empty
FROM docs_core_heads WHERE vat_period=57 AND docState=40;
```
