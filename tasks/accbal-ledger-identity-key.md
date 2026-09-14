# Saldokonto — pohyb per platební identita, ne per doklad (oprava generátoru)

**Stav:** částečně — kód, CLI, testy a docs hotové 2026-09-14 (3 commity), nasazeno a ověřeno na 4l3j; zbývá ds-upgrade → DROP INDEX → `accbal-regenerate --all` + kontrolní SELECT na e8w1-i a btpg-p (poslední bod „Hotovo když“)

## Kontext

`LedgerGenerator::buildDesired()` skládá pohyby přes klíč
`(source_kind, source_id, balance, bal_side, account_number)`. Partner, VS,
SS, měna a splatnost se berou z **prvního** řádku deníku, částky **všech**
řádků dokladu na tom účtu se sečtou. Předpoklad z `accbal.md` §4.2
(„partner je konstantní per zdroj → per zdroj právě jeden řádek") platí pro
fakturu a bankovní transakci, ale ne pro:

- otevírací doklad období (desítky pohledávek různých partnerů v jednom
  dokladu → v ledgeru jeden pohyb s VS první pohledávky a součtem všech),
- interní doklady (zápočty, hromadné vyúčtování inkas, opravy salda),
- pokladní doklady s více řádky.

Dopad na reimportovaných DS: `e8w1-i` 98 dokladů / 373 řádků deníku slitých
do jednoho pohybu; `btpg-p` **2 966 interních + 353 pokladních dokladů,
11 782 řádků** — většina včerejšího nálezu „úhrady bez předpisu ~14 mil."
(#72, komentář 2026-09-14) je tento bug, ne data.

## Před implementací přečti

- `docs/accbal.md` §4.2–§4.3 (algoritmus generátoru, stabilní klíč, UPSERT)
  — přepisuje se.
- `modules/economy/accbal/src/LedgerGenerator.php` — `buildDesired()`,
  diff desired × existing, DELETE osiřelých.
- `modules/economy/accbal/tables/economy_accbal_ledger.jsonc` — index
  `unq_stable_key`.
- `docs/table-definitions.md` §10 — `ds-upgrade` neumí index změnit ani
  zrušit, jen přidat.
- `CaseQuery::normalizeKey()` — normalizace, kterou má sdílet i klíč pohybu.
- `docs/accounting.md` § platební identita řádku (`rowPaymentId`,
  `identityRequired`) — proč mají řádky jednoho dokladu různé identity.

## 1. Klíč pohybu

Nový stabilní klíč pohybu = zdroj + **platební identita řádku**:

```
(source_kind, source_id, balance, bal_side, account_number,
 partner, payment_reference, specific_symbol, currency)
```

- Řádky téhož zdroje se **stejnou** identitou se dál sčítají (faktura se
  dvěma řádky na 311 = jeden pohyb, jako dnes). Různé identity = různé pohyby.
- Symboly a měna v klíči už normalizované (`CaseQuery::normalizeSymbol/
  normalizeCurrency` — D10), aby se klíč shodoval s klíčem případu.
- `journal_row` = první řádek skupiny (denorm, jako dnes); `due_date`, `text`
  = z prvního řádku skupiny.
- Stabilita přes přeúčtování zůstává: identita řádku plyne z dokladu, ne
  z `journal.id`.

## 2. Unikátnost v DB

`ds-upgrade` neumí přepsat `unq_stable_key` a MariaDB v unikátním indexu
nevynucuje shodu přes `NULL` (VS/SS mohou být `NULL` po D10). Proto:

- nový sloupec `movement_key CHAR(40) NOT NULL` = SHA-1 kanonické podoby
  klíče z §1 (oddělovač `|`, `NULL` jako prázdný řetězec) — **jen identita
  pohybu**, případ zůstává n-ticí bez hash sloupce (D11, T2);
- nový index `unq_movement_key (movement_key)` unique;
- původní `unq_stable_key` z definice **odstranit** (na nových DS nevznikne;
  na existujících zůstane a **musí pryč ručně**, jinak INSERT druhého pohybu
  téhož dokladu selže — viz §4);
- UPSERT generátoru podle `movement_key`.

## 3. Hromadná re-derivace

Dnes se ledger přegeneruje jen událostí `journalWritten` per zdroj. Přidat
`shpd-ds accbal-regenerate [--all | --doc <id> | --fiscal-year <id>] [--dry-run]`:
projde zdroje v dávkách (deník → `buildDesired` → UPSERT/DELETE), vypíše
počty vložených/aktualizovaných/smazaných pohybů. Použije se i po každé
budoucí změně generátoru (otevřený bod „výkon hromadné re-derivace" v
`accbal.md` §12 tím dostává nástroj).

## 4. Nasazení na existující DS (poznámka do docs, ne kód)

Pořadí: `ds-upgrade` (sloupec + nový index) → ruční `DROP INDEX
unq_stable_key` (jednorázově; u DS, které se resetují, odpadá) →
`accbal-regenerate --all`. Zapsat do `docs/accbal.md` § nasazení a do
poznámek k implementaci; na alfě mutace jen po schválení v chatu.

## 5. Docs

- `accbal.md` §4.2: nahradit blok „Jednoznačnost klíče" novým klíčem a
  důvodem (otevírací/interní doklady); §3.3 sloupec `movement_key`;
  §4.3 UPSERT podle `movement_key`; log rozhodnutí **D13** (viz níže).
- `docs/cli.md`: `accbal-regenerate`.
- `docs/table-definitions.md`: pokud existuje seznam „indexy zrušené
  z definice, na starých DS ručně", přidat `unq_stable_key`; jinak řádek
  v `accbal.md`.

## 6. Testy

PHPUnit jen s úzkým `--filter`.

- `LedgerGeneratorTest`: doklad se třemi řádky na 311 pro tři partnery/VS →
  tři pohyby se správnými částkami; faktura se dvěma řádky téže identity →
  jeden pohyb se součtem (regrese); přeúčtování téhož dokladu → stejná `id`
  pohybů (stabilita), změna VS na řádku → starý pohyb DELETE, nový INSERT;
  `NULL` vs. `NULL` v SS = stejná identita (hash), `NULL` vs. `''` po
  normalizaci totéž.
- `CaseQueryTest` (integrační) beze změny — projde, jakmile pohyby nesou
  správné identity.
- `AccbalRegenerateCommandTest`: dry-run nic nezapíše; `--all` nad fixturou
  se slitým pohybem ho rozdělí.
- `BankPaymentRoutingTest`, `CashPaymentCaseTest` beze změny chování.

## Commit strategie

1. Klíč pohybu + `movement_key` + index + generátor + `LedgerGeneratorTest`.
2. `accbal-regenerate` + test + `docs/cli.md`.
3. `accbal.md` (§3.3, §4.2, §4.3, D13, nasazení) + hlavička tasku `hotovo`
   + index.

## Hotovo když

- [x] otevírací doklad s N pohledávkami dá N pohybů s částkou každé
      pohledávky; součet pohybů dokladu = obrat dokladu na saldo-účtu
      (integrační `LedgerGeneratorTest::testOpeningDocumentGivesMovementPerIdentity`)
- [ ] `accbal-regenerate --all` na `e8w1-i` rozdělí 98 dokladů, na `btpg-p`
      ~3 300; poté `SELECT` „doklady se slitými identitami" (dotaz
      v poznámkách k implementaci) vrací 0 — **zbývá** (na 4l3j proběhlo:
      10 pohybů, 0 slitých, 11 osiřelých fixtur smazáno)
- [x] testy výše zelené, `unq_stable_key` mimo definici, `movement_key`
      unique
- [x] docs a index tasků ve stejném commitu jako kód

## Rozhodnutí k designu (potvrzená)

- ✓ **D13** Identita pohybu = zdroj + platební identita řádku (partner, VS,
  SS, měna), řádky stejné identity v jednom zdroji se sčítají. Nahrazuje
  rozhodnutí #6 v `accbal.md` §11 (stabilní klíč per zdroj + účet).
- ✓ `movement_key` jako hash je jen pro unikátnost pohybu v DB; klíč
  případu zůstává n-tice sloupců (D11).
- ✓ Hromadná re-derivace přes CLI, ne reimport DS.

## Poznámky k implementaci (2026-09-14)

Commity: `a65d0dd` klíč + `movement_key` + generátor + testy, `6492bbc`
`accbal-regenerate`, třetí docs + tento task.

Odchylky od zadání (schválené v chatu před implementací):

- **`movement_key` je `varchar(40)` nullable**, ne `CHAR(40) NOT NULL`.
  Typový systém definic `char` nemá; hlavně ale `ds-upgrade` na
  existujícím DS provede `ADD COLUMN` (všechny řádky prázdné) a hned
  `CREATE UNIQUE INDEX`, který by na duplicitách spadl. S `NULL` index
  vznikne bez kolize a regenerace klíč dopíše. Generátor klíč zapisuje
  vždy; `NULL` = pohyb z doby před D13.
- **Nový index `idx_source (source_kind, source_id)`** — po ručním
  `DROP INDEX unq_stable_key` by dotaz generátoru „pohyby zdroje" neměl
  index (`idx_doc_head` jde přes jiný sloupec).
- Pořadí syncu **DELETE → UPDATE → INSERT** (dřív INSERT před DELETE):
  v transakci ekvivalentní, při migraci se nový řádek nepotká se starým
  `NULL`-klíčovým řádkem téhož zdroje.
- `LedgerGenerator::generate()` vrací `{inserted, updated, deleted}` a má
  `$dryRun`; nastavení saldokont memoizované per instance (dávka = jeden
  dotaz). Fixtury integračních testů vkládají do ledgeru bez klíče — smí
  (nullable), produkční zápis má jen generátor.
- Při jednorázové regeneraci na starém DS se `id` pohybů změní (starý
  řádek bez klíče DELETE, nový INSERT). Persistované odkazy nejsou
  (`row_id` případu se počítá živě).
- `accbal-regenerate` nevysílá `journalWritten` (deník se nemění); po ní
  případně `accbal-match`. Zdroje = deník ∪ ledger, takže osiřelý pohyb bez
  deníku se smaže — na 4l3j to bylo 11 fixtur z dřívějších testů.

Postup na existujícím DS a kontrolní SELECT: `docs/accbal.md` §4.6.
Na `e8w1-i` a `btpg-p` (a poté na alfě) čeká:

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-upgrade
# ALTER TABLE economy_accbal_ledger DROP INDEX unq_stable_key;
shpd-ds accbal-regenerate --all --dry-run
shpd-ds accbal-regenerate --all
# kontrolní SELECT z accbal.md §4.6 → 0 řádků
```
