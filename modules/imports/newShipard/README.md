# imports.newShipard

Importer dat ze starého Shipardu do nového Shipardu přes HTTPS REST API.

Dokument má dvě části: **Rychlý start** (jak import rozjet) a **Reference**
(subkomandy, options a implementační poznámky k jednotlivým fázím).

---

## Rychlý start

### Předpoklady

- Nový Shipard dostupný přes HTTPS.
- Starý Shipard: PHP 8.1+ (kvůli `readonly` promoted properties) s rozšířeními
  `curl` a `pdo_sqlite`:

  ```bash
  apt install php8.3-sqlite3
  ```

- Ve starém DS nastavený vlastník v `config/appOptions.core.json`
  (`options.core.ownerPerson`) — podle něj se při importu osob v novém
  Shipardu automaticky označí vlastní firma (`is_own=1`), kterou vyžaduje
  import dokladů. Viz [Doklady](#doklady).

### 1. Nový Shipard — uživatel a API klíč

```bash
cd /path/to/new/shipard/data-source
shpd-ds user-create \
    --login=_legacy_importer \
    --password="$(openssl rand -hex 32)" \
    --name="Legacy Importer (system)" \
    --email=legacy-importer@local
```

Heslo je jen placeholder — importer se přihlašuje API klíčem, ne heslem.
Náhodný `openssl rand -hex 32` jen zajistí, že účet nepůjde zneužít
interaktivním loginem.

```bash
shpd-ds api-key-create --user=_legacy_importer --name=legacy-import --ip=<starý-shipard-IP>
```

Zachyť plaintext klíče — bude zobrazen jen jednou.

### 2. Starý Shipard — modul a konfigurace

V DS rootu starého Shipardu:

1. Do `config/modules.json` doplň `"imports/newShipard"` do existujícího JSON
   pole (jinak cliAction skončí s "Invalid moduleId").

2. Vytvoř config s URL a API klíčem:

   ```bash
   cd /var/lib/shipard/data-sources/<dsid>
   cat > config/import-newShipard.json <<'JSON'
   {
       "target": {
           "baseUrl": "https://<new-shipard-host>/api/v1",
           "apiKey": "shpd_ak_..."
       }
   }
   JSON
   chmod 0600 config/import-newShipard.json
   ```

   Soubor obsahuje API klíč — `chmod 0600` je nutný (modul varuje na stderr,
   pokud má soubor jiná práva). Všechny volby viz [Konfigurace](#konfigurace).

3. Doporučený alias (dále v textu se používá):

   ```bash
   alias shpd-ds-import='shpd-app cli-action --action=imports.newShipard/import'
   ```

   Plná forma: `shpd-app cli-action --action=imports.newShipard/import <subcommand> [options]`,
   spouští se z DS adresáře.

### 3. Ověření spojení

```bash
shpd-ds-import status
```

Zkontroluje config, HTTP připojení a lokální mapu; očekávaný výstup končí
`✓ Status OK.`

### 4. Import

```bash
shpd-ds-import all
```

Orchestrátor spustí všechny fáze v pořadí závislostí — včetně parametrů
vrstvy C (úplně první), nastavení saldokont (za číselníky) a závěrečného
„vzdáleného" spárování úhrad:
**settings → codebooks → accbal-settings → persons → items → docs →
bank-statements → mail → match**. Jeden příkaz tak nahradí dřívější ruční
kroky (parametry setup checklistu, nastavení saldokont, vlastní import
a ruční `accbal-match --all` na cílovém serveru).

Užitečné volby:

- `--dry-run` — bez zápisů do cílového Shipardu; fáze Match vrátí read-only
  plán párování (nic nemění).
- `--from=YYYY-MM-DD` / `--to=YYYY-MM-DD` — omezí doklady, výpisy a poštu
  na období.
- `--continue-on-error` — pokračovat i po selhání řádku/fáze.
- `--reset` — před během smazat lokální mapu (čistý re-import).
- `--skip-accbal-settings` / `--skip-match` — vynechat příslušnou fázi.

Import je idempotentní — druhý běh už naimportované záznamy přeskočí (vč. saldo
skupin per kód; viz [Idempotence a re-import](#idempotence-a-re-import)).

Nastavení saldokont je součástí `all`; samostatná subkomanda `accbal-settings`
zůstává pro `--dump` (stará DB → JSON) a vlastní `--file`
(viz [Nastavení saldokont](#nastavení-saldokont-accbal-settings)).

---

## Reference

### Subkomandy

| Subkomanda          | Fáze | Popis                                                 |
| ------------------- | ---- | ----------------------------------------------------- |
| `status`            | 01   | Sanity check — config, HTTP připojení, lokální mapa.  |
| `vat-registrations` | 02   | Registrace k DPH (jen `taxType='vat'`).               |
| `fiscal-years`      | 02   | Fiskální roky + fiskální měsíce (sub-import).         |
| `bank-accounts`     | 02   | Vlastní bankovní spojení.                             |
| `cost-centers`      | 02   | Střediska.                                            |
| `warehouses`        | 02   | Sklady.                                               |
| `cash-desks`        | 02   | Pokladny.                                             |
| `number-series`     | 02   | Číselné řady dokladů (jen typy známé v novém DS).     |
| `item-kinds`        | 02   | Druhy položek (s mapováním na seedované system_code). |
| `units`             | 02   | Měrné jednotky (`witems` units).                      |
| `accounts`          | 08   | Účtový rozvrh (`e10doc_debs_accounts`).               |
| `all-codebooks`     | 02   | Všechny číselníky v pořadí závislostí.                |
| `persons`           | 03   | Osoby (lidé + firmy) přes exchange applier.           |
| `items`             | 04   | Položky (zboží, služby) přes exchange applier.        |
| `docs`              | 05   | Doklady — faktury (`invni`/`invno`) a účetní doklady (`cmnbkp`). Viz [Doklady](#doklady). |
| `bank-statements`   | 11   | Bankovní výpisy (`docType='bank'`). Viz [Bankovní výpisy](#bankovní-výpisy). |
| `mail`              | 07   | Došlá pošta (`wkf` issues, `issueType=1`). Viz [Pošta](#pošta). |
| `all`               | 06   | Orchestrace fází v pořadí závislostí (vč. settings, accbal-settings a závěrečného párování). |
| `settings`          | 25   | Parametry vrstvy C (homeCurrency, fiscalYearStartMonth, vatAgenda, accountChart) odvozené ze staré DB; **první fáze `all`**. Viz [Parametry vrstvy C](#parametry-vrstvy-c-settings). |
| `accbal-settings`   | 12   | Nastavení saldokont (`--dump`/`--import`); **součást `all`**, samostatně pro `--dump` / vlastní `--file`. Viz [Nastavení saldokont](#nastavení-saldokont-accbal-settings). |
| `export-booking-history` | 27 | Export agregované účetní historie přijatých faktur do JSONL (`shpd.economy.booking-history.v1`). Lokální soubor, nic neposílá na cíl. Viz [Export účetní historie](#export-účetní-historie-export-booking-history). |
| `export-general-ledger` | 15b | Export agregované hlavní knihy do `ReportResult` JSONu pro `report-diff`. Lokální soubor, nic neposílá na cíl. Viz [Export hlavní knihy](#export-hlavní-knihy-export-general-ledger). |
| `forget`            | 10   | Zapomenout LocalIdMap mapování jedné entity. Viz [Idempotence a re-import](#idempotence-a-re-import). |
| `reset`             | 06   | Smazat celou lokální mapu (`import-newShipard.sqlite`) a skončit. |

### Společné options

- `--verbose`, `-v` — verbose výstup (HTTP requesty + per-row debug na stderr).
- `--dry-run` — neprovádět zápisy do cílového Shipardu.
- `--continue-on-error` — pokračovat i když jednotlivý řádek selže (default: stop).
- `--limit=N` — zpracuj jen prvních N řádků (jen exchange runnery, vhodné pro testing).
- `--no-throttle` — vypne klientský throttling mezi requesty (viz
  [Rate limiting](#rate-limiting)). Vhodné pro testování chování serveru pod zátěží.
- `--dump-payload` — vypíše canonical JSON posílaný na exchange apply
  (exchange runnery). Failnuté řádky dumpují payload + response body automaticky.
- `--reset` — před během smazat lokální mapu (čistý re-import; ekvivalent
  subkomandy `reset` + běh).

### Options podle fází

- `--from=YYYY-MM-DD` / `--to=YYYY-MM-DD` — filtr období; `docs`/`mail`/`bank-statements`/`all`/`export-booking-history`.
  - `docs`: `dateAccounting` (datum zaúčtování — zajišťuje kompletní fiskální
    období, na rozdíl od data vystavení).
  - `mail`: `dateIncoming` (datum doručení).
  - `bank-statements`: `datePeriodEnd` (konec období výpisu).
  - `export-booking-history`: `dateAccounting` dokladu.
  - Nevalidní formát se ignoruje s warningem.
- `--target-state=10` — jen `docs`; přebije celou stavovou mapu (viz
  [Doklady](#doklady)) a importuje vše jako koncept. Testovací běhy.
- `--chunk-months=N` — velikost chunků importu dokladů v měsících (default 1);
  `docs`/`all`.
- `--skip-accbal-settings` / `--skip-match` — jen `all`; vynechat fázi nastavení
  saldokont, resp. závěrečné vzdálené párování (`POST /_accbal/match`).
- `--require-linked-doc` — jen `mail`; importovat jen zprávy s dohledatelným
  dokladem, obecnou korespondenci přeskočit. Default vypnuto (best-effort).
- `--no-attachments` — přeskočit upload PDF příloh; `mail`/`bank-statements`.
- `--entity=doc|person|item|message` — jen `forget`; která entita se má z mapy zapomenout.
- `--dump` / `--import` / `--file=PATH` — jen `accbal-settings`; viz
  [Nastavení saldokont](#nastavení-saldokont-accbal-settings).
- `--out=PATH` — jen `export-booking-history`; cílový JSONL soubor (default
  `booking-history-<dsid>.jsonl` v aktuálním adresáři).
- `--fiscal-year=X` / `--month-from=N` / `--month-to=N` / `--acc-ring=20[,40]` /
  `--output=PATH` — jen `export-general-ledger`; viz
  [Export hlavní knihy](#export-hlavní-knihy-export-general-ledger).

### Konfigurace

Soubor `config/import-newShipard.json` v DS rootu:

```jsonc
{
    "target": {
        "baseUrl": "https://abcd-efgh-ijkl-mnop.shipard.app/api/v1",
        "apiKey": "shpd_ak_1234567890abcdef1234567890abcdef",
        "timeout": 30,

        // Rate limiting (volitelné, defaulty stačí pro většinu situací):
        "throttleMs":   80,    // pauza mezi requesty (ms); 0 = off
        "maxRetries":   3,     // počet retry pokusů pro 429 / 5xx / network
        "retryDelayMs": 1000   // base delay pro exp. backoff (ms)
    },
    "options": {
        "verbose": false,
        "dryRun": false,
        "batchSize": 100
    }
}
```

Pole `target.baseUrl` a `target.apiKey` jsou povinné. Volitelné:

| Klíč | Typ | Default | Rozsah | Popis |
|---|---|---|---|---|
| `target.timeout` | int | 30 | 1–300 | curl timeout v sekundách |
| `target.throttleMs` | int | 80 | 0–10000 | minimum pauza mezi requesty v ms |
| `target.maxRetries` | int | 3 | 0–10 | počet retry pokusů |
| `target.retryDelayMs` | int | 1000 | 100–60000 | base delay pro exp. backoff v ms |

Sekce `options` je volitelná.

**Bezpečnost:** soubor obsahuje API klíč — nastavte `chmod 0600`. Modul
varuje na stderr, pokud má soubor jiná práva.

### Doklady

Importují se **faktury přijaté (`invni`), vydané (`invno`) a účetní doklady
(`cmnbkp`)**. Bankovní výpisy migruje samostatná fáze
[`bank-statements`](#bankovní-výpisy); ostatní typy (pokladní, objednávky,
dodací listy) jsou mimo scope.

**Stavová mapa.** Starý `docState` se mapuje na cílový stav:

| Starý stav              | Nový stav                                            |
| ----------------------- | ---------------------------------------------------- |
| 1000 Nově rozpracováno  | 10 Koncept (bez čísla)                               |
| 1200 Potvrzeno          | ✗ nemapuje se — tvrdá chyba dokladu (viz pre-flight) |
| 4000 Hotovo             | 40 V pořádku (+ zaúčtování)                          |
| 4100 Stornováno         | 30 Storno (s číslem, bez deníku)                     |
| 8000 V opravě           | 40 V pořádku (finalizovat + zaúčtovat)               |

Neznámý starý stav = tvrdá chyba řádku (žádný tichý default).
`--target-state=10` celou mapu přebije a importuje vše jako koncept.

Nový Shipard **stav Potvrzeno (20) zrušil** — stavový model dokladů je
Koncept (10) → V pořádku (40), plus V opravě (80), Storno (30). Starý stav
1200 proto v mapě není vůbec: import se spouští nad zdrojem, kde už žádný
takový doklad není, a jeho výskyt je chyba dat, ne stav k mapování. Roli
„editovatelný doklad s číslem" přebral stav 80 (V opravě), který import
používá jako parkovací strop nezaúčtovatelných `cmnbkp` (viz níže);
exchange ho přijme jen v kombinaci s `applyOptions.importNumber`, mimo
migraci je tedy nedosažitelný.

**Pre-flight před ostrým importem.** Na každém zdrojovém DS ověřit, že
žádný doklad ve scope není ve stavu 1200:

```sql
SELECT docType, COUNT(*) FROM e10doc_core_heads
 WHERE docState = 1200 AND docType IN ('invni','invno','cmnbkp')
 GROUP BY docType;
```

Očekáván prázdný výsledek. Nález → doklady ve zdroji dořešit (potvrdit do
4000, nebo vrátit do 1000) **před** importem; runner je jinak odmítne tvrdou
chybou per doklad (s `--continue-on-error` pokračuje dalším). Filtr na
`docType` je podstatný — ve stejné tabulce žijí i bankovní výpisy a další
typy dokladů, kterých se zrušení stavu 20 netýká (výpisy mají vlastní enum
`[10,40]`, jejich 1200 se dál mapuje na koncept).

**Vlastní firma (selfParty).** Faktury používají `selfParty` resolution, která
v cílovém Shipardu hledá firmu označenou `is_own = 1`. Označuje se
**automaticky** při importu osob (Fáze 03): řádek odpovídající
`options.core.ownerPerson` z `config/appOptions.core.json` starého DS dostane
`isOwn=true` (jen typ Firma; vlastní firma bez IČO projde s warningem, ale
applier ji při docState 40 odmítne — `own_company_id_required`). `DocsRunner`
existenci `is_own=1` ověří pre-flightem a bez ní abortuje. Ruční fallback,
kdyby automatika neproběhla:

```sql
UPDATE base_persons_persons SET is_own = 1 WHERE company_id = '<vlastní-IČO>';
```

**Pořadí importu:** codebooks → persons → items → **docs**. Doklady spoléhají
na to, že partneři (osoby) a položky už v cíli jsou; jinak je applier
zkusí vytvořit (`autoCreateMode: safe`), což vede k neúplným záznamům.

**Účetní doklady (`cmnbkp`)** jsou strukturálně jiné než faktury:

- Žádný obchodní směr — `selfParty`/`supplier`/`customer` jsou `null`.
  Hlavičkový partner je nepovinný; když je vyplněn, předá se jen jako pin
  přes LocalIdMap (bez side-create).
- Řádky jsou **kontace** (účet + strana MD/DAL + částka + per-řádková saldo
  identita), ne položky. Hlavičkové symboly `cmnbkp` nemá — saldo identita
  žije na řádcích.
- Bez DPH (`useTax:0`; applier `vat_mode` vynutí na 0).
- Vlastní číslo dokladu jde do `importNumber` (ne do `partner_doc_number`);
  číselná řada se dohledává přes cfg `e10.docs.dbCounters.cmnbkp.<dbCounter>.docKeyId`.
- Doklady s neúčtovatelnými operacemi (majetek, kurzové rozdíly) se
  naimportují kompletní (číslo i řádky), ale stav se stropne na 80
  (V opravě — s číslem, editovatelný, nezaúčtovaný) + warning. Na nové
  straně na ně navíc upozorňuje alert `docs.core.stale_in_repair`.

**Známá omezení:**

- **Středisko a sklad se ztrácí** — `docs_core_heads` v novém Shipardu zatím
  nemá `cost_center` ani `warehouse`. Importované doklady tato data nenesou.
- **Partner se páruje přes LocalIdMap, ne podle jména** — doklad ukazuje na
  přesnou migrovanou osobu přes `_resolve.{customer|supplier}.userAction =
  useExisting:<id>` (mapování staré ndx → nové id). Partner proto musí být
  naimportovaný **před** doklady (`persons` před `docs`); jinak ho importér
  v LocalIdMap nenajde a spadne zpět na párování přes IČO/jméno — u fyzické
  osoby bez IČO (a se stejnojmenným záznamem) to skončí `unresolved_required`
  → doklad `skipped`. Viz [Párování záznamů a deduplikace](#párování-záznamů-a-deduplikace).
- **Vydané faktury (`invno`) — vlastní bankovní účet a dvoukrokový import.**
  Nový Shipard u vydané faktury vyžaduje ve stavech s číslem (40/80) vlastní
  `bank_account` (kam má zákazník zaplatit; `IssuedInvoiceDocument::validate`).
  Exchange formát ho ale neumí přenést. Proto se `invno` vkládá nejdřív jako
  **koncept (10)** a runner ho v `afterApplied` povýší na cílový stav spolu
  s účtem, který dohledá ze starého `myBankAccount` přes LocalIdMap (Fáze 02
  `bank-accounts`). **Bez naimportovaných bank-accounts** (nebo když starý
  doklad nemá `myBankAccount`) zůstane vydaná faktura konceptem (10) +
  warning. Přijatých faktur (`invni`), účetních dokladů (`cmnbkp`) ani
  `--target-state=10` se to netýká.
- **Číslo vydané faktury** — applier dává `docNumber` do `partner_doc_number`
  a vlastní `doc_number` přiděluje number_series až při přechodu 10→20. Runner
  proto až **po povýšení** přepíše vygenerované číslo na původní (non-fatal při
  unique konfliktu).
- **Řádky typu sada** (`rowType`) se importují tak, jak jsou — rozložené sady
  mohou vytvořit duplicitní řádky. K ověření na reálných datech.

**Konverze polí na řádcích:**

- **Kódy DPH** — starý formát `EUCZ{NNN}` se převádí na nový `cz-{NNN}`
  (deterministicky, prefix `EUCZ` → `cz-`). Kódy `EUCZ000` (nedaňový řádek)
  a `EUCZ113` (artefakt zdroje) v novém Shipardu neexistují → mapují se na
  `null` (řádek bez kódu DPH). Mapování je **CZ-only**; kódy jiných zemí se
  pošlou beze změny a applier je případně odmítne. `vat.pct` se posílá souběžně
  jako fallback. Zdroj pravdy: `nov_shipard:modules/world/vat/config/vat-cz.jsonc`.
- **Jednotka `none`** — systémová jednotka starého Shipardu pro řádky bez
  jednotky se mapuje na `null` (prázdný sloupec `unit`), aby applier nehlásil
  `unit_not_found`.

### Bankovní výpisy

Migrace bankovních výpisů (`e10doc_core_heads` s `docType='bank'` + řádky)
přes exchange formát `shpd.bank.statement.v1`
(`POST /_exchange/bank/statement/apply`).

**Prerekvizita:** naimportované `bank-accounts` (Fáze 02) — vlastní účet
výpisu se dohledává přes LocalIdMap ze starého `myBankAccount`.

Oproti dokladům je import podstatně jednodušší — bez číselných řad, čísel
a selfParty. Runner posílá jen fakta transakcí (`operation=null`, znaménková
částka); zaúčtování i párování partnera dělá nová strana.

- **Stavová mapa:** rozpracované výpisy (1000/1200) → koncept (10; cílový
  enum zná jen 10 a 40, proto 1200→10), hotové (4000/8000) → 40 (zaúčtovat).
  **Stornované výpisy (4100) se nemigrují** (soft-skip — jejich transakce by
  jinak zaúčtovaly zrušené pohyby). Neznámý stav = tvrdá chyba výpisu.
- **Saldo/párování se nemigruje** — transakce hotových výpisů se zaúčtují
  novým enginem na clearing účty (261200/261300). Nenulové zůstatky clearingu
  po migraci jsou očekávané.
- **Idempotence** přes `ENTITY_BANK_STATEMENT` (klíč = staré ndx hlavičky);
  transakce navíc deduplikuje nová strana (`external_id`/fingerprint).
- **PDF přílohy** výpisů se migrují k novému výpisu (table_id 415); vypínač
  `--no-attachments`.
- `--from`/`--to` filtrují na `datePeriodEnd` (chunkování netřeba — výpisů
  je málo).

### Pošta

Import došlé pošty ze starého Shipardu (`wkf_core_issues`, `issueType=1` =
Došlá pošta) do nového (`core_mail_incoming_messages`). Ostatní typy issues
(úkoly, diskuze, …) se ignorují.

**Prerekvizita — endpoint.** Zprávy nelze založit generickým CRUD (`message_id`
se generuje v `beforeSave`). Import volá dedikovaný `POST /_mail/import`
nového Shipardu (viz `nov_shipard:tasks/mail-phase4-import-endpoint.md`).

**Pořadí importu:** pošta je poslední **datová** fáze řetězce
settings → codebooks → accbal-settings → persons → items → docs →
bank-statements → **mail** → match. Doklady se musí importovat **před** poštou — vazba
zpráva↔doklad se resolvuje přes
`LocalIdMap` (`ENTITY_DOC`). Pro ostrý import dělej doklady (celý rozsah) před
poštou; zprávy naimportované před svým dokladem se zpětně nepřelinkují (skip
přes `ENTITY_MESSAGE`).

**Schránky.** Pro každou sekci (`wkf_base_sections`), do které padá importovaná
pošta, vznikne schránka `core_mail_mailboxes` (idempotentně přes
`ENTITY_MAILBOX`). `mailbox_id` = `section.shipardEmailId`, fallback
`sec-{ndx}`; `email_address` = `{mailbox_id}@imported.invalid`. Plochá struktura
(strom sekcí se zahazuje), `is_default=false`, `docState=40` (aktivní). Zprávy
v sekci 0 / bez sekce jdou do default schránky DS.

**Vazba na doklad** je autoritativně v `e10_base_doclinks`
(`linkId='e10docs-inbox'`, doklad=`src`, zpráva=`dst`; 1 doklad : N zpráv). Více
vazeb → první + warning. **Best-effort:** zpráva se importuje vždy, i když se
doklad nedohledá (`target` NULL, počítadlo `unlinked`). `--require-linked-doc`
takové zprávy přeskočí.

**Mapovaná pole:**

- `primary_type` — navázaná faktura přijatá (`invni`) → `invoiceReceived`,
  jinak `other`.
- `docState` — navázaná zpráva → **40** (Zpracovaná), nenavázaná → **10** (Nová).
- `source_type` — starý `issues.source` → nový: `0`(Ručně)→1, `1`(E-mail)→2,
  `2`(API)→3, `3`(Test)→1.
- `sender_email` — `systemInfo` (`email.from[0]` / `webForm.from`) → e-mail
  autora (osoby, z `e10_base_properties`) → placeholder `unknown@imported.invalid`
  (validní, projde validací endpointu).
- `sender_person` — autor zprávy přes `LocalIdMap` (`ENTITY_PERSON`).

**Přílohy** se nahrají k nové zprávě (table_id 303) přes obecný klient Fáze 07a
(dedup přes SHA-256 — druhý běh → `duplicate`). Vypínač `--no-attachments`.

### Parametry vrstvy C (settings)

Zápis čtyř parametrů setup checklistu (`core_system_settings`) na cílový DS —
konfigurace, ne migrovaná data. Bez nich by checklist nového Shipardu hlásil
nerozhodnuté parametry, přestože odpovědi jsou ve starých datech. Běží jako
**první fáze `all`** i samostatně (`settings`):

| Klíč | Hodnota | Odvození ze staré DB |
|---|---|---|
| `economy.accountChart` | `none` (konstanta) | osnovu dodává `accounts`; `none` = „vlastní osnova, neseedovat" |
| `economy.homeCurrency` | ISO lowercase | měna fiskálního roku pokrývajícího dnešek; fallback poslední rok, fallback `czk` |
| `economy.fiscalYearStartMonth` | 1–12 | měsíc `start` téhož fiskálního roku; fallback `1` |
| `economy.vatAgenda` | bool | existuje aspoň jedna registrace k DPH (`taxType='vat'`); jinak **explicitní `false`** |

Zápis přes `POST /_setup/parameters` (all-or-nothing validace). Opakovaný běh
je neškodný — stejné hodnoty se jen znovu uloží; `--dry-run` hodnoty pouze
vypíše, nic neposílá. Žádná LocalIdMap.

**Prerekvizita na cílové straně:** guard provisionerů na `skipProvisioning`
(`shpd:tasks/setup-parameters-skip-provisioning.md`) musí být nasazený, jinak
zápis klíčů doseeduje fiskální roky/osnovu. Varování „provisioning je na DS
vypnutý" v logu runneru je proto **očekávané**; runner navíc zaloguje počet
zbývajících položek checklistu.

### Nastavení saldokont (accbal-settings)

Konfigurace saldokont (skupiny + jejich účty), ne migrovaná data. Běží jako
**fáze `all`** (za číselníky, přes `runImport()`) i jako samostatná subkomanda
`accbal-settings` — ta vyžaduje právě jeden režim:

- `--dump` — stará DB (`e10doc_accBal_balances` + `…balancesAccounts`) → JSON
  v seed tvaru nového Shipardu (skupiny s vnořenými účty). Mapování je čistý
  rename; staré nastavení dobropisy nemá, dump je proto přirozeně bez nich.
- `--import` — JSON → generický CRUD do `economy_accbal_balances` /
  `economy_accbal_balance_accounts`. FK účtů řeší vnoření v JSONu, žádná
  LocalIdMap.

Cesta JSONu: default `modules/imports/newShipard/data/accbalSettings.json`
(verzovaný, ručně laděný); override `--file=PATH`.

**Idempotence per kód skupiny.** Před vytvořením se skupina hledá na cíli přes
`findOneBy('economy_accbal_balances', 'code', …)`; když existuje, přeskočí se
včetně účtů (`skipped` v souhrnu). Druhý běh `all` tedy saldo skupiny
neduplikuje. **Omezení:** změna uvnitř *existující* skupiny (ruční doladění
JSONu) se nepromítne — přepis znamená `ds-reset` cílového DS a import znovu.
V `all` je fáze volitelná přes `--skip-accbal-settings`.

### Export účetní historie (export-booking-history)

Jediná subkomanda, která **nic neposílá na cíl** — vyexportuje ze staré DB
agregovanou účetní historii přijatých faktur do JSONL souboru formátu
`shpd.economy.booking-history.v1` a tím skončí. Soubor pak zpracuje nový
Shipard (`shpd-ds booking-history --input=<file>`): report kvality zdroje
a taxonomie obsahových štítků, seed pravidel `IČO → štítek` a reverzní
otagování položek.

```bash
cd /var/lib/shipard/data-sources/<dsid>
shpd-app cli-action --action=imports.newShipard/import export-booking-history \
    [--out=cesta.jsonl] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
```

**Nepotřebuje `config/import-newShipard.json`** (žádný HTTP klient, žádná
LocalIdMap, žádný zápis do staré DB) — proto se odbočuje ještě před načtením
configu a jde spustit i na DS, který se nikam neimportuje. Log jde do
`log/export-booking-history-<timestamp>.log`.

**Co se vybírá:**

| Vrstva | Podmínka |
|---|---|
| doklady | `e10doc_core_heads`, `docType='invni'`, **`docState=4000`** (Hotovo = zaúčtováno) |
| řádky | `e10doc_core_rows`, `operation=1099998` (Účetní položka), `item>0` |
| období | volitelně `--from`/`--to` na `dateAccounting` dokladu |

Filtr stavu je **záměrně jiný než u `docs`** (ten bere vše kromě smazaných):
koncepty (1000), potvrzené (1200), rozpracované v opravě (8000) ani storna
(4100) zaúčtovaná fakta nereprezentují. Kolik dokladů takto vypadlo, vypíše
souhrn na konci běhu.

**Co jde do záznamu:** `companyId` (IČO dodavatele z `e10_base_properties`,
jen číslice; bez IČO → `null`), `account` (`e10_witems_items.debsAccountId`
**tak jak je** — žádný resolve na nové ndx, exportují se fakta, ne mapování),
`itemCode`/`itemName`, `rowText`, `docCount`/`rowCount`,
`totalAmount` (suma `taxBaseHc`, tj. základ v domácí měně), `firstDate`/`lastDate`.
Agregační klíč je čtveřice `{companyId, account, itemCode, rowTextNorm}`, kde
`rowTextNorm` = trim + collapse whitespace + lowercase (do souboru se
neposílá); `rowText` nese **nejčetnější originální variantu** textu.

**Export neinterpretuje:** žádné štítky, žádná znalost taxonomie nového
Shipardu (přepočet novou verzí taxonomie proto nevyžaduje nový export)
a **žádné filtrování degenerovaných textů** (prázdné, shodné s názvem
položky…) — jejich podíl je na nové straně metrika kvality zdroje.
`chartVariant` je vždy `unknown`, protože e10 variantu osnovy nezná.

Soubor se píše do `<out>.tmp` a až hotový se přesune na cílovou cestu, takže
přerušený běh nezůstane jako zdánlivě platný vstup. Čte se po dávkách dokladů
(`--batch`, default 500) — hlavičky keysetem po PK, řádky dotazem
`document IN (…)`; agregace pak běží v paměti (řádově tisíce klíčů). Export
78 tis. dokladů trvá jednotky sekund. Kanonická specifikace
formátu je na nové straně: `shpd:docs/booking-history-format.md`.

Mimo scope v1 (připraveno konstantami v `BookingHistoryExporter`):
`--doc-types` pro výdajové pokladní lístky a zavěšení exportu do sekvence
`all`.

### Export hlavní knihy (export-general-ledger)

Druhá subkomanda, která **nic neposílá na cíl**: z účetního deníku staré DB
udělá agregovanou hlavní knihu v minimálním `ReportResult`-kompatibilním JSONu
a tím skončí. Slouží ke kontrole importu v M3 — „stejný report za stejné
období musí sedět". Kanonický kontrakt výstupu: `shpd:docs/reports.md` §7.4.

```bash
# 1. stará strana
cd /var/lib/shipard/data-sources/<dsid>
shpd-app cli-action --action=imports.newShipard/import export-general-ledger \
    --fiscal-year=2025 --month-from=7 --month-to=7
#    → log/general-ledger-<dsid>-2025-07-07.json

# 2. nová strana, TOTÉŽ období
bin/shpd-ds report-run economy.accounting.generalLedger \
    --fiscal-year=2025 --month-from=7 --month-to=7 > new.json

# 3. porovnání (nepotřebuje DS, čte jen dva soubory)
bin/shpd-ds report-diff old.json new.json    # exit 0 shoda, 1 rozdíly
```

Starý reportovací engine se neoživuje: shoda reportů se redukuje na shodu
agregovaného deníku per účet a období, protože novou stranu počítá vždy tentýž
builder nad novým deníkem. Diff hlavní knihy proto pokrývá i výsledovku
a rozvahu — derivují z týchž detail řádků.

**Nepotřebuje `config/import-newShipard.json`** (jako `export-booking-history`
se odbočuje před načtením configu). Read-only, jen SELECTy. Log jde do
`log/export-general-ledger-<timestamp>.log`.

| Argument | Význam |
|---|---|
| `--fiscal-year=X` | **povinné**; název fiskálního roku ze staré DB (`fullName`), nebo kalendářní rok jeho začátku. Bez shody vypíše nabídku dostupných roků. |
| `--month-from=N`, `--month-to=N` | **pořadí běžného fiskálního měsíce v roce** (1..N dle data začátku), ne kalendářní měsíc. Default celý rok. |
| `--acc-ring=20[,40]` | účetní okruhy (20 Výchozí, 40 Zásoby); default `20`. |
| `--output=PATH` | cílový soubor; default `log/general-ledger-<dsid>-<rok>-<od>-<do>.json`. |

**Parametry musí sedět s novou stranou.** `--fiscal-year` se matchuje primárně
podle názvu roku, protože právě ten `FiscalYearsRunner` posílá do nového
`economy_codebooks_fiscal_years.name` a právě ten bere `report-run
--fiscal-year`. `--month-from/--month-to` jsou pořadová čísla běžných
fiskálních měsíců — stejná sémantika jako `monthFrom`/`monthTo` na nové straně;
u kalendářního fiskálního roku vycházejí na kalendářní měsíc, u posunutého ne.
Vybraný interval se proto vypisuje i kalendářně (`měsíce 7–7 = 2025-07 …
2025-07`) a log rovnou obsahuje odpovídající příkaz `report-run`.

**Výpočet** zrcadlí `GeneralLedgerBuilder` nové strany: `opening` = všechna
období roku před intervalem (**včetně otevíracího**, bez uzavíracího — počáteční
stavy jsou v deníku jako každé jiné účtování a nic se nedopočítává),
`turnover` = interval, `closing` = opening + turnover. `balance` = **md − d
syrově**, bez otáčení dle povahy účtu (obě strany stejně, diff porovnává
md/d/balance zvlášť). Účet s nulovým opening i turnover se neemituje. Řádky
jsou seřazené podle čísla účtu, takže dva exporty jdou diffovat i mezi sebou.

**Filtr stavu dokladu: `docState IN (4000, 8000)`.** Do nového deníku se účtuje
výhradně doklad ve stavu 40 a odchod ze 40 jeho řádky maže; na 40 mapuje
`DocsRunner` staré stavy 4000 (Hotovo) a 8000 (V opravě). Storno 4100 (→ 30)
ani koncepty deník na nové straně nemají. Řádky deníku bez dohledatelné
hlavičky se **zahrnují** a hlásí — tiše zahodit část deníku je horší než
viditelný rozdíl v diffu.

**Souhrn běhu je zároveň prověřením předpokladů** (nejčastější zdroj falešných
rozdílů):

- histogram řádků deníku roku **per docState dokladu** i **per účetní okruh**,
  s příznakem zahrnuto/vyloučeno a s částkami,
- řádky roku mimo jeho fiskální měsíce (`fiscalMonth` = 0 / cizí) — ty by
  nevzal žádný interval,
- vyrovnanost `Σmd == Σd` v každém sloupci a agregáty bez čísla účtu.

Na zrcadlených zdrojových DS (2026-08) mají všechny prověřené roky v deníku
výhradně řádky stavu 4000 a výhradně okruh 20 — default `--acc-ring=20` tam
nic neztrácí. Až nová strana dostane skladové účtování, přidá se
`--acc-ring=20,40`.

**Výstup** obsahuje sloupce `opening`/`turnover`/`closing`, detail řádky
(`kind: detail`, `level: 4`, klíč `account`) a jeden kontrolní součet
(`kind: total`, label `Celkem`) — `ReportDiff` ho porovná jen tehdy, má-li ho
i druhá strana, takže neškodí, a jinak slouží jako rychlá kontrola
vyrovnanosti. Subtotaly se negenerují (diff je ignoruje, derivují z detailů).
Soubor se píše do `<out>.tmp` a až hotový se přesouvá na cílovou cestu.

Rozdíly proti nové straně jsou **nález pro M3, ne bug exportéru** — pokud
souhrn hlásí vyrovnaný deník a součty sedí na starou hlavní knihu v UI.

### Rate limiting

Nový Shipard má API rate limit **1000 requestů / 60 s per API klíč**
(viz `nov_shipard:src/Api/Middleware/RateLimitMiddleware.php`). Importer má
tři vrstvy obrany, aby se do něj nedostal:

1. **Proaktivní throttling** — minimum interval mezi requesty (default 80 ms
   = ~12.5 req/s, 25% rezerva pod limit). Měřeno přes `microtime(true)` od
   posledního requestu — pokud aplikace mezi nimi dělá DB queries / mapování,
   čekání už probíhalo "samo" a throttle nic nepřidá.

2. **Respect `_retry_after` při 429** — pokud server přesto vrátí 429
   RATE_LIMITED, klient přečte `error.details[].field='_retry_after'` (sekundy)
   z body a počká přesně tu dobu. Pokud `_retry_after` chybí, fallback na
   exp. backoff.

3. **Exponential backoff pro 5xx a network errory** — při 500-599, network
   timeoutu nebo DNS chybě čekáme `retryDelayMs * 2^(attempt-1)` (1s, 2s, 4s,
   …, cap 30 s).

Maximum počet retry pokusů je `maxRetries` (default 3). Po jejich vyčerpání
runner zafailuje per řádek (`--continue-on-error` umožní pokračovat).
**4xx errory kromě 429** (validation, schema_invalid, 404, ...) **NEretryjeme**
— jsou fatální, vyžadují opravu zdrojových dat.

Verbose log (`-v`) zobrazí každý retry: `[http] retry 1/3 after 6 s (HTTP 429: RATE_LIMITED)`.

### Párování záznamů a deduplikace

Resolvery nového Shipardu páruje příchozí záznam přes business klíče a jako
poslední možnost přes **fuzzy shodu jména** (`name LIKE %…%`). To je správné
pro obecný výměnný flow (AI extrakce, externí feedy), ale pro **migraci**
škodlivé: dvě genuinně různé položky/osoby téhož jména (např. „Parkovné" jako
služba vs. účetní položka; nebo dvě fyzické osoby stejného jména) by se slily
do jedné. Migrace má přitom autoritativní staré ID, takže párování podle jména
vypíná:

- **`items` a `persons`** posílají `applyOptions.matchStrategy = "identifiersOnly"`
  → resolver páruje jen přes identifikátory (kód položky; IČO/DIČ osoby) a při
  neshodě **vytvoří nový** záznam místo slití podle jména. Idempotenci mezi
  běhy drží `LocalIdMap` (už naimportované záznamy se přeskočí, viz
  [Idempotence a re-import](#idempotence-a-re-import)), ne fuzzy shoda jména.
- **`docs`** pinnou partnera i řádkové položky na konkrétní migrovaný záznam
  přes `_resolve.{customer|supplier}.userAction` a `_resolve.rows[i].item.userAction
  = "useExisting:<nové-id>"` (id z `LocalIdMap`). Applier pak nehledá podle
  kódu/jména — což by po zrušení slučování bylo nejednoznačné. Když partner /
  položka v `LocalIdMap` není (nebyly naimportované), pin se vynechá a applier
  spadne zpět na standardní párování. Hlavičkový partner `cmnbkp` je pin-only —
  bez hitu v mapě zůstane prázdný.

Default `matchStrategy` (`identifiersAndName`, resp. vynecháno) zachovává
původní chování s fuzzy shodou jména — pro neimportní použití formátu.

> **Důsledek pro pořadí importu:** `persons` a `items` musí proběhnout **před**
> `docs`, aby byly v `LocalIdMap` k dispozici pro pinning. Při re-importu po
> opravě zdrojových dat zapomeň příslušné mapování (`forget --entity=…`)
> a importuj znovu v pořadí persons → items → docs.

### Idempotence a re-import

Runner respektuje LocalIdMap: pokud `(entity_type, old_ndx)` mapping existuje,
záznam se přeskočí (status `skipped`, reason `already-imported`). Druhý běh
nevytváří duplicity ani neaktualizuje existující data — záznamy v novém
Shipardu jsou po vložení ve stavu `docState=40` (V pořádku), který je
readOnly.

Re-import po opravě zdrojových dat:

- **Cílený** — zapomenout mapování jedné entity, ostatní zachovat:

  ```bash
  shpd-ds-import forget --entity=doc   # doc|person|item|message
  ```

- **Kompletní** — smazat celou lokální mapu:

  ```bash
  shpd-ds-import reset          # jen smaže mapu a skončí
  shpd-ds-import all --reset    # smaže mapu a rovnou importuje
  ```

Nový import pak založí nové záznamy v novém Shipardu paralelně se starými.
Pro tabulky s unique `code` (bank-accounts / cost-centers / warehouses /
cash-desks) v takovém případě hrozí konflikt — ručně smaž staré nové
záznamy přes UI nebo si zaveď distinct kódy. Nejčistší cesta pro plný
re-import je `ds-reset` cílového DS.

### Lokální stav

- `<DS>/import-newShipard.sqlite` — SQLite s mapováním `old_ndx → new_id`
  per entitní typ. Vytváří se automaticky při prvním běhu (`chmod 0600`).
- Žádný stav v MySQL DS DB — modul nemá `tables` v `module.json`.

### Historie fází

- [x] Fáze 01 — bootstrap (modul, dispatcher, HTTP klient, lokální mapa), `status`.
- [x] Fáze 02 — číselníky.
- [x] Fáze 03 — osoby.
- [x] Fáze 04 — položky.
- [x] Fáze 05 — doklady (faktury; 05b import-mód čísel) + účetní doklady (`cmnbkp`).
- [x] Fáze 06 — orchestrátor `all`, `reset`.
- [x] Fáze 07 — pošta (07a obecný klient příloh, 07b došlá pošta).
- [x] Fáze 08 — účtový rozvrh.
- [x] Fáze 10 — maintenance `forget`.
- [x] Fáze 11 — bankovní výpisy.
- [x] Fáze 12 — nastavení saldokont (`accbal-settings`).
- [x] Fáze 15 — saldokonto v `all`: fáze accbal-settings (za číselníky) +
  závěrečné vzdálené párování přes `POST /_accbal/match`; idempotence saldo
  skupin per kód, per-call HTTP timeout, `--skip-accbal-settings`/`--skip-match`.
- [x] Fáze 25 — parametry vrstvy C (`settings`): zápis `economy.accountChart`/
  `homeCurrency`/`fiscalYearStartMonth`/`vatAgenda` přes `POST /_setup/parameters`;
  první fáze `all`.
- [x] Fáze 15b — export hlavní knihy (`export-general-ledger`): agregovaný deník
  do minimálního `ReportResult` JSONu pro `shpd-ds report-diff` (kontrola
  importu v M3). Číslo tasku 15 je v `tasks/` použité dvakrát — tohle je
  `15-general-ledger-export.md`. Viz
  [Export hlavní knihy](#export-hlavní-knihy-export-general-ledger).
- [x] Fáze 27 — export účetní historie (`export-booking-history`): agregovaná
  historie účtování přijatých faktur do JSONL `shpd.economy.booking-history.v1`
  pro `shpd-ds booking-history` na nové straně. Viz
  [Export účetní historie](#export-účetní-historie-export-booking-history).
- [x] Fáze 28 — import bez stavu Potvrzeno (20): nový Shipard stav 20 zrušil,
  starý `docState` 1200 zmizel z mapy (výskyt = tvrdá chyba dokladu, viz
  pre-flight) a nezaúčtovatelné `cmnbkp` se parkují na stavu 80 (V opravě).
