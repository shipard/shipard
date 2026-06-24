# 13 — Pre-flight guard clearing infrastruktury + zúžení „Peníze na cestě"

> PRD pro jednu Claude Code session (**old_shipard**). Pojistka, aby `all`
> nezačal importovat doklady/transakce na DS, kterému chybí clearing
> infrastruktura, + úprava migračního saldo JSONu, ať neoverlapuje s clearingem.
> Návaznost: `shpd:docs/accbal.md` §4.5 + rozhodnutí #18; clearing infrastrukturu
> samotnou zajišťuje **nová strana** (`shpd:tasks/accbal-clearing-infrastructure.md`).

## Kontext

Clearing účty `261200`/`261300` a saldo skupina `unmatched_payments` se na
migrovaném DS vytvoří bezpodmínečně v `ds-upgrade` na nové straně
(`ClearingInfrastructureProvisioner`). Tahle session přidává **na starou stranu**
dvě věci:

1. **Pre-flight guard** v `AllRunner`: kdyby cílový DS přesto infrastrukturu
   neměl (zapomenutý `ds-upgrade` po deployi, starý build), `all` jinak doběhne
   a tiše rozbije clearing — bankovní engine sype `accounting_state=2` a matcher
   pak najde nula kandidátů. Guard to promění v **hlasitou chybu hned na začátku**,
   než se naimportuje první doklad/transakce.

2. **Zúžení skupiny „Peníze na cestě"** v `accbalSettings.json`. Ta dnes mapuje
   holý prefix `261`, který přes `LIKE 261%` chytne i 261200/261300 → na
   clearingových řádcích deníku by vznikl **dvojitý saldo pohyb** (jeden ve
   skupině „Peníze na cestě", jeden v `unmatched_payments`). Zúžit na `261100`.
   A `unmatched_payments` do JSONu **nepřidávat** — skupinu vlastní nová strana
   (jinak `unq_code` kolize při importu).

## Návaznost

- **Prerekvizita (nová strana):** `ClearingInfrastructureProvisioner` nasazený a
  na cílovém DS proběhlý `ds-upgrade` (viz `shpd:tasks/accbal-clearing-infrastructure.md`).
- **Návaznost na task 12** (`12-accbal-settings.md`): `accbalSettings.json` je
  ručně laděný migrační soubor; tahle session jen upraví skupinu „Peníze na cestě".

## Před implementací přečti

- `modules/imports/newShipard/libs/runners/AllRunner.php` — `run()` (smyčka fází:
  codebooks → persons → items → **Documents** → **Bank statements** → mail);
  `$this->info/err/ok`, `$this->context`, `$this->http()`
- `modules/imports/newShipard/libs/CrudClient.php` — `findOneBy($table, $column,
  $value)` (GET list `filter[{col}]=eq:` + `limit=1` → řádek nebo null); pozn.
  „pokud nový Shipard filter pro sloupec nepodporuje, vrátí 400"
- `modules/imports/newShipard/data/accbalSettings.json` — skupina
  `money_in_transit` (účet `261`)
- `shpd:docs/accbal.md` §4.5 — proč infra patří nové straně a proč overlap vadí

## Scope

**Uvnitř:** pre-flight `assertClearingInfrastructure()` v `AllRunner` (před
fázemi); zúžení `money_in_transit` v `accbalSettings.json` na `261100`.

**Mimo:** zajištění infrastruktury (to je nová strana); přidávání
`unmatched_payments` do JSONu (zakázané — viz Kontext); cokoli v jiných runnerech.

## Co implementovat

### A. Pre-flight guard v `AllRunner`

Na **začátku** `run()`, **před** smyčkou fází, zavolat
`assertClearingInfrastructure()`; při chybějící infrastruktuře `err(...)` +
`return false` (tvrdě abortovat **bez ohledu na `--continue-on-error`** — je to
chyba setupu cíle, ne chyba datového řádku).

`assertClearingInfrastructure(): bool` přes `new CrudClient($this->http())`:

- `findOneBy('economy_accounting_accounts', 'number', '261200')` — null → chybí
- `findOneBy('economy_accounting_accounts', 'number', '261300')` — null → chybí
- `findOneBy('economy_accbal_balances', 'code', 'unmatched_payments')` — null → chybí

Při jakémkoli chybějícím prvku vypsat jasnou hlášku s remediací, např.:

```
Cíl nemá clearing infrastrukturu (chybí: 261200 / unmatched_payments).
Spusť na CÍLOVÉM DS `bin/shpd-ds ds-upgrade` s aktuálním buildem nového
Shipardu (ClearingInfrastructureProvisioner) a import spusť znovu.
```

Guard kryje obě navazující fáze (Documents i Bank statements) jediným bodem na
začátku — clearing infrastruktura má být na cíli přítomná už z `ds-upgrade`,
takže ji lze ověřit dřív, než `all` cokoli naimportuje.

> Ověř, že generický list endpoint dovolí `filter[number]` na
> `economy_accounting_accounts` a `filter[code]` na `economy_accbal_balances`.
> Pokud některý filter vrátí 400 (nepodporovaný sloupec), je to prerekvizita na
> nové straně — nahlásit a doladit tam; neobcházet slabším checkem.

### B. Zúžení „Peníze na cestě" v `accbalSettings.json`

Ve skupině `money_in_transit` změnit `account_number` obou řádků z `261` na
`261100` (generická peníze na cestě = převody mezi vlastními účty; 261200/261300
patří clearingu, který řeší `unmatched_payments`). `unmatched_payments` do
souboru **nepřidávat**.

## Hotovo když

- `… all` na DS bez clearing infrastruktury **hned zkraje** spadne s jasnou
  hláškou a **nezaloží** žádný doklad/transakci.
- `… all` na DS s infrastrukturou proběhne beze změny chování (guard projde tiše).
- `accbalSettings.json`: `money_in_transit` na `261100`, žádný řádek na holém
  `261`, žádná skupina `unmatched_payments`.
- Po `accbal-settings --import` + běhu matcheru na migrovaných datech nevznikají
  dvojité pohyby na clearingu (smoke: clearingový řádek deníku → právě jeden
  saldo pohyb, ve skupině `unmatched_payments`).
- PHP lint OK.

## Doporučené pořadí

1. `assertClearingInfrastructure()` + zapojení na začátek `AllRunner::run()`.
2. Úprava `accbalSettings.json` (261 → 261100).
3. Smoke: `all` proti DS bez infra (čekej abort) i s infra (čekej průchod).

## Rozhodnutí ✓

1. **Guard v `AllRunner`, hard-fail na začátku** — promění tichý no-op matcheru
   v hlasitou chybu před importem dokladů/transakcí (D4 ✓).
2. **Check přes `CrudClient::findOneBy`** (261200/261300 number + unmatched_payments
   code) — žádný nový endpoint na nové straně. *(David ✓)*
3. **`unmatched_payments` zůstává mimo migrační JSON** (vlastní ho nová strana);
   `money_in_transit` zúžen na `261100` kvůli prefix-overlapu. *(David ✓)*

## Otevřené body

- **Filter whitelist** — pokud list endpoint nepodporuje `filter[number]` /
  `filter[code]`, doplnit na nové straně (prerekvizita), ne obcházet.
- **Samostatný `bank-statements` běh** mimo `all` guard nemá — tam ale chybějící
  účty selžou hlasitě už v enginu (`accounting_state=2`), takže tiché selhání
  hrozí jen u matcheru spuštěného po importu; guard v `all` je hlavní ochrana.
