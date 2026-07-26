# Fáze 23: Import období DPH (`VatPeriodsRunner`)

**Status:** ✅ Implementováno

## Cíl

Doplnit do importní pipeline chybějící runner pro **období DPH**. Dnes
importujeme registrace k DPH (`VatRegistrationsRunner`), ale období nikoli —
Fáze 02 to vědomě odložila (viz `02-codebooks.md`, sekce „Otevřené body /
rozhodnutí", bod 7) s předpokladem, že `VatPeriodsProvisioner` v novém Shipardu období
dogeneruje při `ds-upgrade`.

Ten předpoklad neplatí ze dvou důvodů:

1. Provisioner generuje jen **aktuální + příští kalendářní rok**. Naimportované
   doklady sahají 10–15 let do historie.
2. Provisioner běží **jen v `ds-upgrade`**, a registrace vznikají až později
   importem přes REST CRUD. Na alfě je proto `economy_codebooks_vat_periods`
   prázdná ve všech 4 DS a **všech ~46 tis. dokladů má `vat_period = NULL`**
   (`fiscal_year`/`fiscal_month` naopak 100 % vyplněné — resolvery při importu
   běží správně, jen nemají v čem hledat).

Po dokončení této fáze platí: po `ds-reset` + plném importu má každý doklad
s vyplněným `vat_duzp` a `vat_registration` dohledané `vat_period`, protože
období odpovídají reálné historii ze starého Shipardu — včetně změn frekvence
a neúplných vstupních období, které generátor vyrobit nedokáže.

## Návaznost

- **Závisí na:** Fáze 01 (infrastruktura, `BaseCodebookRunner`, `CrudClient`,
  `LocalIdMap`), Fáze 02 (`VatRegistrationsRunner` — FK na registraci).
- **Musí běžet před:** Fáze 05 (`DocsRunner`) — `DocDocument::resolveVatPeriodId()`
  dohledává období při ukládání dokladu; období musí existovat dřív.
- **Souvisí:** `nov_shipard/tasks/economy-vat-periods-overlap-idempotence.md`
  (D7 — překryvová idempotence provisioneru; nutná, aby první `ds-upgrade`
  po importu nezaložil duplicitní překrývající se období).
- **Backfill existujících dokladů se neřeší** — cílový scénář je `ds-reset`
  + plný import.

## Zdrojová data (ověřeno na reálných DS)

Průzkum `e10doc_base_taxperiods` ve všech 12 starých DS na tomto hostu:

- `periodType` je **všude jen 0 (řádné)** — ani jedno opravné (1) nebo
  dodatečné (2) období.
- `vatReg` je **vždy vyplněný** (žádné NULL/0).
- Období sahají typicky do **2027-12-31** — starý Shipard je předgeneroval
  dopředu. Import proto pokryje i aktuální + příští rok a provisioner není
  po `ds-reset` vůbec potřeba.

Dva ze čtyř alfa zdrojů jsou na tomto hostu:

| Zdroj | DB | Období | Rozsah | Poznámka |
|---|---|---|---|---|
| lefreal (CZ24154547) | `33271805401633` | 193 | 2011-11-02 … 2027-12-31 | první období **60denní** (`2011-11-02 … 2011-12-31`, `fullName = "2011/4Q"`) — neúplné vstupní období od data registrace; zbytek měsíční |
| msi-zlin (CZ46343504) | `msiu70160` | 192 | 2012-01-01 … 2027-12-31 | `docState`: 20× 4000, 160× 9000, 12× 9800 (celý rok 2012 smazaný); nejstarší nesmazané `2013-01-01` = přesně nejstarší `vat_duzp` v cílovém DS |

Zdroje pro **nsa-firma (CZ63478714)** a **nsa-finmago (CZ24219029)** na tomto
hostu nejsou, jejich data neověřena. Firma je jediný DS s čtvrtletní
registrací (`tax_period_kind = 2`) — u ní se dá čekat jiná struktura než
měsíční, právě proto je D7 potřeba.

Formát názvů ve zdroji: `fullName` = `"2012/1"` / `"2011/4Q"`, `id` = `"2012/01"`
(nový model ekvivalent `id` nemá — zahazujeme).

## Scope

### V rozsahu

- Nový `VatPeriodsRunner extends BaseCodebookRunner` nad `e10doc_base_taxperiods`
- Subcommand `vat-periods` v dispatcheru + usage text
- Zařazení do `AllCodebooksRunner::SEQUENCE` hned za `VatRegistrationsRunner`
- Normalizace názvu období na konvenci nového Shipardu s fallbackem na `fullName`
- Diagnostika pokrytí v summary (mezery, překryvy, rozsah per registrace)
- Derivace `valid_from` registrace z reálných období (úprava `VatRegistrationsRunner`)
- Oprava rozporné věty o `vat_periods` v `02-codebooks.md`

### Mimo rozsah

- Opravná (`periodType = 1`) a dodatečná (`periodType = 2`) období — nový model
  je nemá jak reprezentovat (`docs_core_heads.vat_period` je 1:1 na řádné
  období). Runner je přeskočí s warningem.
- Backfill `vat_period` u už naimportovaných dokladů — cílový scénář je
  `ds-reset` + plný import.
- Změna `VatPeriodsProvisioner` — samostatná mini-PRD v `nov_shipard`.
- `forget --entity vat-period` — číselníkové entity aliasy záměrně nemají
  (re-import číselníků = `ds-reset`).

## Rozhodnutí k designu (potvrzená)

✓ **D1 — samostatný runner**, ne `afterRowImported` child jako fiscal months.
Období jsou vlastní stará tabulka s vlastním `ndx`, ne per-parent dotaz;
`LocalIdMap::ENTITY_VAT_PERIOD` už v enumu existuje (zatím nepoužitý). Umožní
i samostatné spuštění.

✓ **D2 — filtr `periodType = 0`, `docState != 9800`.** `periodType` se
**nefiltruje v SQL**, ale v `mapRow` → non-zero hodnota dá `warn` + `skipped`
(`unsupported`). Tiché zahazování nechceme.

✓ **D3 — název: normalizovat s fallbackem.** Období přesně odpovídající
kalendářnímu měsíci → `"MM/YYYY"`, přesně kvartálu → `"QN/YYYY"`, jinak
převzít `fullName`. Dá konzistenci s provisionerem pro 99 % období a věrnost
pro anomálie (`"2011/4Q"`).

✓ **D4 — věrné mapování `docState`** přes `BaseCodebookRunner::DOC_STATE_MAP`
(9000 → 70 V archívu, 4000 → 40). U msi-zlin skončí 160 období v archívu.
Semanticky správné (uzavřená období) a na dohledávání to nemá vliv —
`resolveVatPeriodId()` filtruje jen `docState != 90`.

✓ **D5 — FK na registraci `required: true`.** Nemapovaný `vatReg` vyhodí
`ImportException`. Období bez registrace je nevaliditní (`VatPeriodDocument`
ji vyžaduje) a znamenalo by, že `VatRegistrationsRunner` neproběhl.

✓ **D6 — diagnostika pokrytí v summary** per registrace: počet, rozsah, počet
mezer a překryvů. Mezera = doklady, které v tom okně zůstanou s `vat_period
= NULL`; překryv = nedeterministické dohledání (`resolveVatPeriodId` má
`LIMIT 1` bez `ORDER BY`).

✓ **D8 — `valid_from` registrace derivovat** z `MIN(start)` nesmazaných řádných
období dané registrace, s fallbackem na dnešní `2010-01-01`.

**Implementační poznámka (mimo D-body):** `locked` se importuje vždy jako `0`.
Starý model ekvivalent nemá a zamčení historických období by blokovalo
jakoukoli pozdější editaci dokladů. Pokud to chceš jinak, řekni před
implementací.

## Změny po souborech

### 1. NOVÝ: `modules/imports/newShipard/libs/runners/VatPeriodsRunner.php`

Konvence old_shipard: **tabulátory**, složené závorky na novém řádku, bez
`declare(strict_types=1)` — drž se stylu `VatRegistrationsRunner.php`.

```php
<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

final class VatPeriodsRunner extends BaseCodebookRunner
{
	/** Pokrytí per starý vatReg ndx — plněno v mapRow, reportováno v run(). */
	private array $coverage = [];   // [oldVatReg => list<array{begin: string, end: string}>]

	protected function entityType(): string  { return LocalIdMap::ENTITY_VAT_PERIOD; }
	protected function targetTable(): string { return 'economy_codebooks_vat_periods'; }
	protected function entityLabel(): string { return 'vat-period'; }
	...
}
```

**`sourceQuery()`** — `periodType` se nefiltruje (viz D2):

```php
return [
	'SELECT [ndx], [fullName], [vatReg], [periodType], [start], [end], [docState]'
	. ' FROM [e10doc_base_taxperiods]'
	. ' WHERE [docState] != %i', 9800,
	' ORDER BY [vatReg], [start]',
];
```

**`mapRow(array $oldRow): ?array`**:

1. `periodType != 0` → `warn("vat-period {$oldNdx}: periodType={$pt} (opravné/dodatečné) není v novém modelu podporováno, skipping")`, `return null`.
2. `$begin = $this->dateToString($oldRow['start'])`, `$end = $this->dateToString($oldRow['end'])`.
   Pokud je kterýkoli `null` → `warn` + `return null` (`VatPeriodDocument`
   vyžaduje oba; v reálných datech nenastane, ale `taxperiods.start/end`
   jsou nullable).
3. `$newRegId = $this->resolveFk(LocalIdMap::ENTITY_VAT_REGISTRATION, (int) ($oldRow['vatReg'] ?? 0), true)`
   — `required: true` (D5).
4. Zaznamenat pokrytí: `$this->coverage[(int) $oldRow['vatReg']][] = ['begin' => $begin, 'end' => $end]`.
5. Vrátit payload:

```php
return [
	'vat_registration' => $newRegId,
	'name'             => $this->derivePeriodName($begin, $end, (string) ($oldRow['fullName'] ?? '')),
	'date_begin'       => $begin,
	'date_end'         => $end,
	'locked'           => 0,
];
```

`docState` **nenastavovat** — `processRow` ho doplní přes `mapDocState()`
(D4). `docStateMain` neposílat vůbec (system field, server dopočítá).

**`derivePeriodName(string $begin, string $end, string $fullName): string`** (D3):

```
$b = new \DateTimeImmutable($begin);
$e = new \DateTimeImmutable($end);

// přesně kalendářní měsíc?
if ($b->format('d') === '01' && $e->format('Y-m-d') === $b->modify('last day of this month')->format('Y-m-d'))
	return sprintf('%02d/%04d', (int) $b->format('n'), (int) $b->format('Y'));

// přesně kalendářní kvartál?
$m = (int) $b->format('n');
if ($b->format('d') === '01' && in_array($m, [1, 4, 7, 10], true)
	&& $e->format('Y-m-d') === $b->modify('+3 months -1 day')->format('Y-m-d'))
	return sprintf('Q%d/%04d', intdiv($m - 1, 3) + 1, (int) $b->format('Y'));

// anomálie → původní název (name je varchar(20))
$name = trim($fullName);
if ($name === '')
	return $b->format('Y-m-d');
return mb_strlen($name) > 20 ? mb_substr($name, 0, 20) : $name;
```

Pozor: `modify('last day of this month')` na `DateTimeImmutable` vrací nový
objekt — nepřepisuje `$b`.

**`run(): bool`** — override pro D6, vzor `FiscalYearsRunner::run()`:

```php
public function run(): bool
{
	$ok = parent::run();
	$this->reportCoverage();
	return $ok;
}
```

**`reportCoverage(): void`** (D6) — pro každý starý `vatReg` v `$this->coverage`:

- setřídit intervaly podle `begin` (v `sourceQuery` už `ORDER BY [vatReg], [start]`,
  ale nespoléhat na to)
- projít po sobě jdoucí páry:
  - `next.begin > prev.end + 1 den` → **mezera**, vyjmenovat okno
  - `next.begin <= prev.end` → **překryv**, vyjmenovat obě období
- vypsat `summary()` řádek:
  `  coverage vatReg=1 (new id=1): 193 period(s), 2011-11-02 … 2027-12-31, gaps=0, overlaps=0`
- každou mezeru / překryv vypsat jako `warn()`, ale **maximálně prvních 10**
  z každé kategorie, pak `warn("… a další N")` — jinak by log u rozbitého
  zdroje utekl
- `new id` dohledat přes `$this->idMap()->lookup(LocalIdMap::ENTITY_VAT_REGISTRATION, $oldVatReg)`;
  pokud `null`, vypsat `?` (nastane jen v dry-runu registrací)

Pokud je `$this->coverage` prázdné → `summary('  coverage: no periods imported.')`.

Pozn.: `mapRow` se volá i v dry-runu (`processRow` ho zavolá před `isDryRun()`
checkem), takže `vat-periods --dry-run` dá plnou diagnostiku pokrytí bez
jediného zápisu. To je hlavní ověřovací nástroj této fáze.

### 2. `modules/imports/newShipard/libs/runners/AllCodebooksRunner.php`

Do `SEQUENCE` hned za `VatRegistrationsRunner::class`:

```php
private const SEQUENCE = [
	VatRegistrationsRunner::class,
	VatPeriodsRunner::class,          // NOVÉ — FK na registrace, musí být za nimi
	FiscalYearsRunner::class,
	...
];
```

Doplnit do docblocku nad `SEQUENCE`: dosud tam stojí, že jediná závislost
uvnitř Fáze 02 je `AccountsRunner` → `BankAccountsRunner`. Přidat druhou:
`VatRegistrationsRunner` → `VatPeriodsRunner` (ENTITY_VAT_REGISTRATION).

### 3. `modules/imports/newShipard/libs/ImportApp.php`

1. Dispatcher — nový case za `vat-registrations` (řádek ~138):

```php
case 'vat-periods':       return (new runners\VatPeriodsRunner($this->context()))->run();
```

2. `printUsage()` — do bloku „Phase 02 — codebooks" za `vat-registrations`:

```php
echo "    vat-periods       VAT periods (taxperiods, periodType=0). Needs vat-registrations FIRST.\n";
```

### 4. `modules/imports/newShipard/libs/runners/VatRegistrationsRunner.php` (D8)

V `mapRow()` nahradit natvrdo daný `valid_from`:

```php
'valid_from' => self::DEFAULT_VALID_FROM,
```

za

```php
'valid_from' => $this->deriveValidFrom($oldNdx),
```

Nová private metoda:

```php
/**
 * `valid_from` registrace = nejstarší reálné řádné období DPH ve starém DS.
 * Fallback DEFAULT_VALID_FROM, když registrace žádná období nemá.
 *
 * Validita registrace se nikde nevaliduje proti dokladům
 * (DocumentApplier::resolveVatRegistrationFor() matchuje jen podle země
 * a docState), takže doklad starší než derivované `valid_from` o registraci
 * nepřijde. Vliv má jen na VatPeriodsProvisioner (co smí dogenerovat)
 * a na čitelnost záznamu v UI.
 */
private function deriveValidFrom(int $oldRegNdx): string
{
	$min = $this->db()->query(
		'SELECT MIN([start]) FROM [e10doc_base_taxperiods]'
		. ' WHERE [vatReg] = %i', $oldRegNdx,
		' AND [docState] != %i', 9800,
		' AND [periodType] = %i', 0,
	)->fetchSingle();

	$derived = $this->dateToString($min);
	if ($derived === null)
	{
		$this->debug("vat-registration {$oldRegNdx}: no source tax periods, valid_from = " . self::DEFAULT_VALID_FROM);
		return self::DEFAULT_VALID_FROM;
	}

	$this->debug("vat-registration {$oldRegNdx}: valid_from derived from tax periods = {$derived}");
	return $derived;
}
```

`DEFAULT_VALID_FROM` konstanta zůstává (fallback) — upravit její docblock:
už to není „bezpečný default pro všechny historické doklady", ale „fallback,
když registrace nemá ve starém DS žádná období".

Ověření `fetchSingle()` na `MIN()`: vrací `Dibi\DateTime` nebo `null`,
`dateToString()` obojí zvládá.

### 5. `modules/imports/newShipard/tasks/02-codebooks.md`

Sekce „**Mimo scope Fáze 02**" obsahuje větu, která je v rozporu s bodem 7
v „Otevřených otázkách" i se skutečným kódem:

> - VAT periods pro **future roky** — to dělá `VatPeriodsProvisioner` v
>   novém Shipardu automaticky. Fáze 02 importuje **jen historii** (řádná
>   období ze starého `taxperiods`).

Fáze 02 neimportovala **žádná** období. Nahradit za:

> - VAT periods — Fáze 02 neimportuje období vůbec (ani historii, ani future
>   roky) a spoléhá na `VatPeriodsProvisioner` v novém Shipardu. Ten
>   předpoklad se neosvědčil → doplněno ve **Fázi 23**
>   ([23-vat-periods-runner.md](23-vat-periods-runner.md)).

A do bodu 7 „Otevřených otázek" doplnit na konec:

> **Vyřešeno ve Fázi 23** — `VatPeriodsRunner` importuje reálná historická
> období z `taxperiods` (`periodType = 0`).

### 6. `modules/imports/newShipard/tasks/README.md`

Řádek do tabulky fází (**tvoje starost, neupravuji**):

| 23 | [23-vat-periods-runner.md](23-vat-periods-runner.md) | … | Import období DPH (`VatPeriodsRunner`) z `e10doc_base_taxperiods` (`periodType=0`) → `economy_codebooks_vat_periods` přes generický CRUD. Řeší `vat_period = NULL` u všech dokladů. Normalizace názvů `MM/YYYY` / `QN/YYYY` s fallbackem na `fullName`, věrné mapování `docState` (9000→70), diagnostika mezer a překryvů. + derivace `valid_from` registrace z reálných období. |

## Testy a ověření

V `modules/imports/newShipard` není PHPUnit — ověřuje se dry-runem a SQL
kontrolou cílového DS. Pořadí:

**1. Dry-run na lefreal (`33271805401633`)** — známá data, 193 období:

```
shpd-app cli-action --action=imports.newShipard/import vat-periods --dry-run -v
```

Očekávat:
- `Found 193 source rows.` (0 smazaných v tomto DS)
- 193× `skipped (dry-run)`, 0 `failed`
- coverage řádek: `193 period(s), 2011-11-02 … 2027-12-31, gaps=0, overlaps=0`
- v payloadech: 192 názvů ve formátu `MM/YYYY`, **jeden** jako `2011/4Q`
  (období `2011-11-02 … 2011-12-31` neodpovídá měsíci ani kvartálu)

**2. Dry-run na msi-zlin (`msiu70160`)**:

- `Found 180 source rows.` (192 minus 12× `docState=9800`)
- coverage: `180 period(s), 2013-01-01 … 2027-12-31, gaps=0, overlaps=0`
- **mezera se nesmí objevit** — smazaný rok 2012 je na začátku rozsahu, ne
  uprostřed

**3. Ostrý běh po `ds-reset`** (plný `all`), pak na cílovém DS:

```sql
-- počty a stavy: očekávat většinu v 70 (V archívu)
SELECT docState, COUNT(*), MIN(date_begin), MAX(date_end)
FROM economy_codebooks_vat_periods GROUP BY docState;

-- hlavní metrika úspěchu: doklady s DUZP a registrací, které nemají období
SELECT COUNT(*) FROM docs_core_heads
WHERE vat_duzp IS NOT NULL AND vat_registration IS NOT NULL
  AND vat_registration <> 0 AND (vat_period IS NULL OR vat_period = 0);

-- kontrola překryvů v cíli (musí být 0 řádků)
SELECT a.id, b.id, a.date_begin, a.date_end, b.date_begin, b.date_end
FROM economy_codebooks_vat_periods a
JOIN economy_codebooks_vat_periods b
  ON a.vat_registration = b.vat_registration AND a.id < b.id
 AND a.date_begin <= b.date_end AND a.date_end >= b.date_begin
WHERE a.docState != 90 AND b.docState != 90;

-- D8: valid_from musí odpovídat nejstaršímu období
SELECT r.id, r.valid_from, MIN(p.date_begin)
FROM economy_codebooks_vat_registrations r
LEFT JOIN economy_codebooks_vat_periods p ON p.vat_registration = r.id
GROUP BY r.id, r.valid_from;
```

Zbytková nenulová hodnota u druhého dotazu je legitimní jen tam, kde doklad
padne mimo rozsah zdrojových období (u msi-zlin doklady s DUZP v roce 2012,
pokud tam nějaké jsou — smazaná období se neimportují). Coverage warningy
z bodu 1–2 to mají odhalit dopředu.

**4. Idempotence:** druhý běh `vat-periods` bez resetu → `created=0,
skipped=N (already-imported)`.

## Commit strategie

1. `feat(imports): add VatPeriodsRunner for historical VAT periods`
   — nový runner, `SEQUENCE`, dispatcher, usage
2. `feat(imports): derive vat registration valid_from from source tax periods`
   — D8 v `VatRegistrationsRunner`
3. `docs(imports): PRD 23 + fix vat_periods scope note in phase 02`
   — tento PRD + oprava `02-codebooks.md`

## Hotovo když

- [ ] `vat-periods --dry-run -v` na lefreal: 193 řádků, `gaps=0`, `overlaps=0`,
      jeden název `2011/4Q`, ostatní `MM/YYYY`
- [ ] `vat-periods --dry-run -v` na msi-zlin: 180 řádků (12 smazaných
      vyfiltrováno), `gaps=0`
- [ ] Ostrý `vat-periods` založí období, `docState` odpovídá zdroji
      (9000 → 70, 4000 → 40)
- [ ] Názvy: měsíc → `MM/YYYY`, kvartál → `QN/YYYY`, anomálie → původní `fullName`
- [ ] Druhý běh je no-op (`created=0`, vše `already-imported`)
- [ ] `all-codebooks` spustí `vat-periods` hned za `vat-registrations`
- [ ] Nemapovaný `vatReg` shodí runner s `ImportException` (ověřit uměle —
      např. `forget --entity` na registrace neexistuje, takže stačí code review)
- [ ] `periodType != 0` dá `warn` + `skipped/unsupported` (v reálných datech
      nenastane — ověřit code review nebo umělým řádkem v testovacím DS)
- [ ] Coverage summary vypisuje per registraci počet, rozsah, gaps, overlaps
- [ ] D8: `valid_from` registrace = `MIN(date_begin)` jejích období
      (lefreal `2011-11-02`, msi-zlin `2013-01-01`)
- [ ] Po plném `ds-reset` + `all`: dotaz na doklady bez `vat_period` vrací 0
      (nebo jen doklady mimo rozsah zdrojových období, vysvětlené coverage
      warningem)
- [ ] Kontrolní dotaz na překryvy v cíli vrací 0 řádků
- [ ] `02-codebooks.md` už netvrdí, že Fáze 02 importuje historii období
