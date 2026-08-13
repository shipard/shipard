# Task 25: Zápis parametrů vrstvy C (settings) do nového Shipardu

**Stav:** k implementaci
**Návaznost:** `shpd:docs/ds-setup.md` §7.2 + rozhodnutí D9 (import zapisuje
parametry sám, žádný backfill); protistrana
`shpd:tasks/setup-parameters-skip-provisioning.md` (guard provisionerů na
`skipProvisioning` — **musí být nasazená na cíli dřív, než se tenhle runner
poprvé pustí naostro**, jinak zápis klíčů doseeduje fiskální roky).

## Cíl

Přeimportovaný DS dnes přijde s kompletními daty, ale s prázdnou
`core_system_settings` — checklist v novém Shipardu proto hlásí nerozhodnuté
parametry vrstvy C, přestože odpovědi jsou ve starých datech. Import je má
zapsat sám:

| Klíč | Hodnota | Odvození ze staré DB |
|---|---|---|
| `economy.accountChart` | `none` (konstanta) | osnovu dodává `AccountsRunner`; `none` = „vlastní osnova, neseedovat" |
| `economy.homeCurrency` | ISO 4217 lowercase | měna fiskálního roku pokrývajícího dnešek (replika `Utils::homeCurrency`); fallback poslední rok; fallback `czk` |
| `economy.fiscalYearStartMonth` | int 1–12 | měsíc `start` téhož fiskálního roku; fallback `1` |
| `economy.vatAgenda` | bool | `true` ⇔ existuje aspoň jedna registrace k DPH; jinak **explicitně `false`** (absence klíče = nerozhodnuto → checklist by svítil dál) |

Zápis jde přes `POST /api/v1/_setup/parameters` — jedinou validovanou
vzdálenou cestu (`LayerCParameters::validate()`, all-or-nothing, tělo
`{"values": {...}}`). Endpoint po uložení vrací stav checklistu a pole
`warnings`; na DS se `skipProvisioning` provisionery přeskočí (guard z
návazného tasku) a přijde o tom informativní varování — to je očekávané.

## Scope

- Nový `libs/runners/SettingsRunner.php` (subkomanda `settings`).
- Zařazení jako **první fáze** `AllRunner` (před `All codebooks`).
- Dispatch + help v `ImportApp`, dokumentace v `README.md`.

**Mimo scope:** změny na straně nového Shipardu (samostatný task tam),
jakékoli jiné settings klíče než čtyři výše, `--skip-settings` volba
(fáze je levná a idempotentní; přidá se, jen kdyby se ukázala potřeba).

## Změny po souborech

### `libs/runners/SettingsRunner.php` (nový)

`final class SettingsRunner extends ImportRunner` — **ne**
`BaseCodebookRunner`: nejde o import řádků, žádná `LocalIdMap`, žádná
idempotence per záznam (opakovaný POST týchž hodnot je idempotentní sám
o sobě, po vzoru hlavičkového komentáře `AccbalSettingsRunner`).

Struktura:

```php
public function run(): bool
{
    $values = $this->deriveValues();   // čte jen starou DB
    // log: přehled odvozených hodnot (info, jedna řádka na klíč + zdroj)

    if ($this->isDryRun()) {
        $this->info('[dry-run] settings: POST /_setup/parameters se přeskakuje.');
        return true;
    }

    $resp = $this->http()->post('/_setup/parameters', ['values' => $values]);
    // warnings z odpovědi → $this->warn() (očekávané: provisioning vypnutý)
    // z odpovědi zalogovat počet zbývajících položek checklistu (items)
    return true;   // HTTP chyby vyhazuje HttpClient → zachytit, err(), return false
}
```

**Odvození (`deriveValues()`), vše nad starou DB přes `$this->db()`:**

1. Fiskální rok — nejdřív pokrývající dnešek, pak poslední:

   ```php
   $today = date('Y-m-d');
   $row = $this->db()->query(
       'SELECT [start], [currency] FROM [e10doc_base_fiscalyears]',
       ' WHERE [docState] != %i', 9800,
       ' AND [start] <= %d', $today, ' AND [end] >= %d', $today,
       ' ORDER BY [start] DESC')->fetch();
   if (!$row)
       $row = /* totéž bez podmínky na dnešek, ORDER BY [start] DESC, fetch() */;
   ```

   - `homeCurrency` = `strtolower(trim($row['currency']))`; prázdné/žádný
     řádek → `'czk'` + `warn()` (stejný přístup jako `FiscalYearsRunner::mapRow`).
   - `fiscalYearStartMonth` = `(int) $row['start']->format('n')` (Dibi vrací
     DateTime; ošetřit i string); žádný řádek → `1` + `warn()`.

2. `vatAgenda` — stejný diskriminátor jako `VatRegistrationsRunner::sourceQuery()`
   (pozor: `taxType`, **ne** `taxArea` — viz komentář tam):

   ```php
   SELECT COUNT(*) FROM [e10doc_base_taxRegs]
    WHERE [taxType] = 'vat' AND [docState] != 9800
   ```

   `> 0` → `true`, jinak `false`.

3. `accountChart` = `'none'`.

Návratový tvar (booleany jako booleany — endpoint je normalizuje sám):

```php
return [
    'economy.accountChart'         => 'none',
    'economy.homeCurrency'         => $currency,
    'economy.fiscalYearStartMonth' => $startMonth,
    'economy.vatAgenda'            => $vatAgenda,
];
```

Hlavičkový komentář třídy: proč `none` (osnova z importu, seed nikdy),
proč explicitní `false` u vatAgenda (D2: absence = nerozhodnuto), odkaz na
`shpd:docs/ds-setup.md` §7.2 a tento task.

### `libs/runners/AllRunner.php`

Nová první fáze — parametry mají na cíli existovat od začátku (runtime
čtenáři `homeCurrency`/`vatAgenda` se chovají konzistentně už během importu
a parametrizace přežije i přerušený běh):

```php
['Layer C settings', fn() => (new SettingsRunner($this->context))->run()],
['All codebooks',    ...],
```

Aktualizovat i hlavičkový komentář třídy s pořadím fází.

### `libs/ImportApp.php`

- `dispatch()`: `case 'settings': return (new runners\SettingsRunner($this->context()))->run();`
  (logicky k `accbal-settings` — obojí je konfigurace, ne migrovaná data).
- Help: řádek pro `settings` v seznamu subkomand + aktualizace popisu pořadí
  fází `all` (`settings → codebooks → accbal-settings → …`).

### `README.md`

- V Rychlém startu upravit větu s pořadím fází `all`.
- Do Reference přidat krátkou sekci `settings` (co zapisuje, odkud odvozuje,
  že opakovaný běh je neškodný, `--dry-run` jen vypíše hodnoty).

## Testy / ověření

Modul nemá PHPUnit — ověřuje se ručně na testovacím DS:

1. `shpd-ds-import settings --dry-run` — vypíše čtyři odvozené hodnoty,
   nic nezapíše (`ds-setting list` na cíli beze změny).
2. `shpd-ds-import settings` — na cíli `bin/shpd-ds ds-setting list` ukáže
   všechny čtyři klíče se správnými hodnotami; v logu runneru varování
   o vypnutém provisioningu (očekávané) a počet zbývajících položek
   checklistu.
3. Druhý běh `settings` — beze změny, žádná chyba (idempotence).
4. `GET /_setup/checklist` (nebo panel dsSetup) — `parameters` bez `null`,
   položky `undecided_*` nesvítí.
5. Kontrola dat na cíli (read-only SQL): v `economy_codebooks_fiscal_years`
   nepřibyl žádný rok navíc oproti importovaným.

## Commit strategie

Jeden commit: `imports/newShipard: task 25 — layer C settings runner`
(runner + AllRunner + ImportApp + README).

## Hotovo když

- [ ] `shpd-ds-import settings` zapíše čtyři klíče na cílový DS; `--dry-run`
      nezapisuje nic.
- [ ] `all` běží s fází `Layer C settings` jako první; opakovaný běh je
      idempotentní.
- [ ] Odvození: `homeCurrency` + `fiscalYearStartMonth` z fiskálního roku
      pokrývajícího dnešek (fallbacky viz výše), `vatAgenda` z existence
      registrací, `accountChart` vždy `none`.
- [ ] `vatAgenda` se zapisuje i jako `false` — po importu není žádný ze čtyř
      klíčů nerozhodnutý.
- [ ] Na cílovém DS nepřibyla žádná seedovaná data (fiskální roky, osnova) —
      předpokládá nasazený guard z `shpd:tasks/setup-parameters-skip-provisioning.md`.
- [ ] README a help `ImportApp` aktualizované.
