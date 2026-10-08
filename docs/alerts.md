# Alerts (`core.alerts`)

Systém upozornění pro uživatele — oznamuje problémy, které systém detekoval,
ale uživatel by je sám neviděl: chybějící základní nastavení, nespárované
bankovní platby, IoT zařízení s vybitou baterií, doklady v opravě dlouho atd.

Tento dokument popisuje **subsystém Alerts** kompletně — od JSONC definice
checku přes PHP třídu, reconciliation logiku, životní cyklus alertu až po API
endpointy a CLI příkazy.

> **Související**: [modules.md](modules.md), [doc-states.md](doc-states.md)
> (alerty doc-state **nepoužívají** — mají vlastní lifecycle).

---

## 1. Přehled

Alerts oddělují dva pojmy:

- **Check** — definice kontroly. Statická entita: JSONC záznam v `alertChecks`
  v `module.jsonc` + PHP třída, která dědí z `AlertCheck`. Identifikuje se
  globálně unikátním `id` (`<group>.<module>.<slug>`).
- **Alert** *(synonymum: Finding)* — konkrétní instance problému. Dynamická
  entita: řádek v tabulce `core_alerts_alerts`. Jeden check vyrobí 0..N
  alertů. Identita alertu uvnitř checku = `(check_id, finding_key)`.

Z toho plyne důležitý invariant: dva běhy téhož checku, které najdou stejný
problém, **musí vrátit stejný `finding_key`**. Reconciler ten klíč používá
pro UPDATE existujícího řádku místo INSERT nového. Singleton checky (problém
buď je, nebo není) používají `finding_key = ""`.

Aktivní alerty se zobrazují i jako karty feedu dashboardu — v sekci
**Upozornění** bez ohledu na závažnost (setup karta „Dokončit nastavení"
v Položkách k založení, #101), vč. agregace mnoha alertů jednoho checku
do skupinové karty, viz [`docs/dashboard.md` §5.2](dashboard.md).

---

## 2. Architektura

```
                    ┌────────────────────────┐
                    │   module.jsonc files   │
                    │  (alertChecks blocks)  │
                    └───────────┬────────────┘
                                │ build at boot
                                ▼
                  ┌─────────────────────────┐
                  │  AlertCheckRegistry     │
                  │  (per request, lazy)    │
                  └────────────┬────────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
    ┌──────────────────┐  ┌────────────┐  ┌──────────────┐
    │ AlertReconciler  │  │ AlertsCtrl │  │ CLI commands │
    │ runCheck(id)→Res │  │ HTTP API   │  │ run/prune    │
    └─────────┬────────┘  └─────┬──────┘  └──────┬───────┘
              │                 │                │
              └────────┬────────┴────────────────┘
                       │
                       ▼
           ┌────────────────────────────┐
           │  core_alerts_alerts        │
           │  core_alerts_check_states  │
           └────────────────────────────┘
```

Klíčové: **`AlertReconciler` je jediný zápisovatel z run cesty.** Uživatelské
API endpointy (`snooze`/`dismiss`/`unsnooze`) píší přímo, **neproží** přes
reconciler.

### Třídy

| Třída | Soubor | Účel |
|---|---|---|
| `AlertCheck` (abstract) | `src/Core/Alerts/AlertCheck.php` | Base class pro konkrétní checky. Ctor: `$db`, `$config`, `$language`. Vrací `AlertFinding[]`. |
| `AlertFinding` (readonly VO) | `src/Core/Alerts/AlertFinding.php` | Jeden nález z checku. Validace: severity whitelist, neprázdný title, max 1 primary action. |
| `AlertCheckDefinition` (readonly VO) | `src/Core/Alerts/AlertCheckDefinition.php` | Parsovaný `alertChecks[]` záznam (id, name, class, severity, interval, intervalSeconds, enabled, tags, moduleId, navSection). |
| `AlertCheckRegistry` | `src/Core/Alerts/AlertCheckRegistry.php` | Agreguje `ModuleDefinition->alertChecks` přes všechny moduly, lokalizuje, detekuje duplicity. |
| `AlertReconciler` | `src/Core/Alerts/AlertReconciler.php` | Srdce systému. `runCheck()`, `getDueCheckIds()`. |
| `AlertRunResult` (readonly VO) | `src/Core/Alerts/AlertRunResult.php` | Návrat z `runCheck()` — status, new/updated/resolved counts, durationMs, errorMessage. |
| `IntervalParser` | `src/Core/Alerts/IntervalParser.php` | `5m`/`1h`/`7d` → sekundy. |

---

## 3. JSONC schéma `alertChecks`

V `module.jsonc`:

```jsonc
"alertChecks": [
    {
        "id":          "base.persons.missing_own_person",   // globálně unikátní
        "name":        "Own Person is missing",             // lokalizováno (name:cs/:en)
        "name:cs":     "Chybí vlastní Osoba",
        "description": "Detects missing own legal entity",  // volitelně, lokalizováno
        "class":       "Shipard\\Module\\Base\\Persons\\Checks\\MissingOwnPersonCheck",
        "severity":    "warning",     // info|warning|error, default warning
        "interval":    "1h",          // 5m / 1h / 30m / 1d / 7d
        "enabled":     true,          // default true
        "navSection":  "accounting",  // volitelně — badge stavů sekcí (viz níže)
        "tags":        ["setup"]      // volné značky
    }
]
```

Validace v `ModuleDefinition::fromArray()` (uvnitř modulu — duplicit ID detekce)
a `AlertCheckRegistry` (napříč moduly — duplicit ID detekce, severity whitelist,
interval parsing).

**`navSection`** (volitelné, formát `[a-z_][a-zA-Z0-9_]*` — id sekce z `navSections.jsonc`, např. `workOrders`) — atribuce karet
checku pro **badge stavů sekcí** v sidebaru (UI shells Fáze 3,
`GET /_ui/section-badges`): id sekce z `global.navSections` nebo sentinel
`_top`. `AlertsSource` ho propisuje do individuální i skupinové karty
feedu; check bez pole se do badge nepočítá (opt-in). Setup checkům
(`tags: ["setup"]`) pole nedávej — agregují se do jediné setup karty,
kam se hodnota z definice nepropisuje.

---

## 4. PHP API checku

Konkrétní check:

```php
namespace Shipard\Module\Base\Persons\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

final class MissingOwnPersonCheck extends AlertCheck
{
    public function run(): array
    {
        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM base_persons_persons'
            . ' WHERE is_own = %i AND docState IN %in',
            1, [10, 40],
        );

        if ($count > 0) {
            return [];   // OK
        }

        return [new AlertFinding(
            findingKey: '',                                  // singleton
            title: $this->language === 'cs'
                ? 'Chybí vlastní Osoba'
                : 'Own Person is missing',
            severity: 'warning',
            actions: [[
                'id'    => 'create_own_person',
                'label' => 'Add own Person',
                'kind'  => 'open_form',
                'target' => ['table' => 'base_persons_persons', 'mode' => 'create',
                             'preset' => ['is_own' => true]],
                'primary' => true,
            ]],
        )];
    }
}
```

### Per-row check

Druhý reálný check, `docs.core.stale_in_repair`, vrací jeden `AlertFinding`
**na každý doklad** visící ve stavu `docState = 80` (V opravě) déle než 24 h:

```php
final class StaleInRepairCheck extends AlertCheck
{
    public function run(): array
    {
        $threshold = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_number], [doc_text], [doc_state_changed_at]
             FROM [docs_core_heads]
             WHERE [docState] = 80
               AND [doc_state_changed_at] IS NOT NULL
               AND [doc_state_changed_at] < %s',
            $threshold,
        );
        $findings = [];
        foreach ($rows as $row) {
            $findings[] = new AlertFinding(
                findingKey: (string) $row['id'],   // ID dokladu — stabilní identita
                title: "Doklad {$row['doc_number']} je v opravě …",
                subjectTableId: 401,               // docs_core_heads.tableId
                subjectRowId: (int) $row['id'],
                actions: [['id' => 'open_doc', 'kind' => 'open_form',
                           'target' => ['table' => 'docs_core_heads',
                                        'mode' => 'edit',
                                        'id' => (int) $row['id']],
                           'primary' => true]],
            );
        }
        return $findings;
    }
}
```

Rozdíly oproti singleton checku:

- **`findingKey` = ID záznamu** (string). Reconciler dedupuje napříč běhy:
  alert pro doklad #42 zůstává stejný řádek v `core_alerts_alerts`, jen se
  inkrementuje `seen_count` / aktualizuje `last_seen_at`.
- **Auto-resolve** je zadarmo: jakmile uživatel doklad přepne zpět do 40 nebo
  na 90, řádek z výsledku checku zmizí, reconciler ho vyhodnotí jako
  resolved (`alert_state = 70`).
- **`subjectTableId` + `subjectRowId`** propisují vazbu na konkrétní záznam —
  viewer z toho staví odkaz / preset pro form. Hodnoty se musí oba nastavit
  nebo oba nechat `null` (validace v `AlertFinding::__construct`).
- Vstup pro detekci je sloupec `doc_state_changed_at` v `docs_core_heads`,
  udržovaný `DocDocument::trackStateChange()` při každém saveu. Backfill
  v `DsUpgradeCommand` zajistí, že existující řádky před přidáním sloupce
  nezůstanou `NULL` (jinak by je SQL predikát ignoroval).

Další per-row check stejného střihu: `base.registry.expirations`
(`RegistryExpirationAlertCheck`, base.registry) — expirace dokumentů
Spisovny dle `valid_to` a `docKinds.expiration.warnDaysBefore`, severity
error/warning/info dle blízkosti termínu, `finding_key = doc_{id}`.
Detaily v README modulu.

**Konvence pro implementery:**

- `$this->db` — `DataSourceConnection` pro raw SQL.
- `$this->config` — `ConfigRuntime` pro čtení cfgItems / doc-state konfigurací.
- `$this->language` — jazyk DS (`DataSourceConfig::getDefaultLanguage()`).
  Lokalizaci `title`/`message`/`actions[].label` **dělá check sám** —
  alerty nejsou znovu-lokalizovány za běhu vieweru.
- Pokud check hodí výjimku, reconciler ji odchytí, zapíše `last_run_error`
  a **NEresolvuje** existující alerty (alerty zůstanou v aktuálním stavu).

---

## 5. Identita nálezu — `finding_key`

Klíč musí být **deterministický** v rámci checku — stejný problém = stejný
klíč = jeden řádek v `core_alerts_alerts`. Pro různé typy checků:

| Typ checku | finding_key konvence | Příklad |
|---|---|---|
| Singleton (problém buď je, nebo není) | `""` (prázdný řetězec) | `base.persons.missing_own_person` |
| Per-row (jeden alert per dotčený záznam) | `(string) $id` (table je v `subjectTableId`) | `"1234"` pro doklad #1234 |
| Per-(row, podtéma) | `<table>:<id>:<topic>` | `iot_devices:42:battery` |
| Per-typ problému | `<typ>` (slug) | `unmatched_bank_payments` (singleton-ish — 1 alert nese počet) |

**Není to UUID.** Klíč je opaque pro DB, ale check si ho musí umět znovu
vygenerovat na další běh. Pokud bys generoval UUID a zapomněl ho persistovat,
reconciler by při každém běhu vytvořil nový alert a předchozí by auto-resolvoval.

---

## 6. Reconciliation logic

`AlertReconciler::runCheck($checkId)` flow:

1. Načti `AlertCheckDefinition` z registru. Není? → `status=error`.
2. Zajisti řádek v `core_alerts_check_states` (lazy insert přes `INSERT IGNORE`).
3. Kontrola `enabled` (v JSONC i v state row) → `status=skipped` pokud false.
4. **Lock**: pokud `is_running=1` a `running_since` mladší než 5 min → `status=skipped`.
   Pokud starší → override + `ErrorLogger::warn`, pokračovat.
5. Vzít lock (`is_running=1`).
6. Instancovat třídu, zavolat `$check->run()` — pokud hodí výjimku:
   - `last_run_status='error'` + `last_run_error=$e->getMessage()`
   - **Existujících alertů se nedotknout** (žádný auto-resolve).
   - `next_run_at = NOW + interval` (i u chyby zachovat schedule).
   - Uvolnit lock, vrátit `STATUS_ERROR`.
7. Sjednocení findings s DB (v jedné transakci):
   - SELECT existing open alerts (`alert_state IN (10, 20)`).
   - Pro každý nový finding:
     - Existuje? → UPDATE (last_seen, seen_count++, refresh content).
       Pokud byl Snoozed a `snoozed_until < NOW` → přepnout na Active.
     - Neexistuje? → INSERT (state=Active, first_seen=last_seen=NOW, seen_count=1).
   - Pro každý existující open alert, který nový run NEVRÁTIL → UPDATE
     na state=Resolved + `resolved_at=NOW`.
8. Update `check_states`: `last_run_*`, `next_run_at`, uvolnit lock.

**Idempotence**: Reconciler nesmí nikdy vytvořit dva řádky pro stejné
`(check_id, finding_key)`. Lock je dostatečný pro náš use case (cron + occasional
manuální spuštění); plnohodnotný advisory lock v MariaDB neimplementujeme.

---

## 7. Stavy alertů (`core.alerts.alertStates`)

| Hodnota | Stav | Open? | Význam |
|---|---|---|---|
| 10 | Active | ✓ | Vidí ho reconciler, vidí ho uživatel v default vieweru. |
| 20 | Snoozed | ✓ | Uživatel ho dočasně potlačil, `snoozed_until` říká do kdy. |
| 70 | Resolved | ✗ | Reconciler ho auto-vyřešil (problém zmizel). |
| 80 | Dismissed | ✗ | Uživatel řekl "tohle mě nezajímá". |

**Resolved** a **Dismissed** jsou terminální — reconciler je nikdy nevzkřísí.
Pokud problém v dalším běhu znovu nastane, vznikne **nový řádek** se stejným
check_id+finding_key (předchozí Resolved/Dismissed zůstává pro audit).

`Dismiss` ≠ "navždy ignoruj" — je to "teď to nechci řešit". Pro permanentní
suppress slouží snooze s velmi dlouhou dobou, nebo `enabled=false` v `core_alerts_check_states`.

---

## 8. Snooze

UI nabízí pevné volby **1h / 4h / 1d / 1w** (viz `frontend/src/api/alerts.js`
`SNOOZE_PRESETS`). Backend přijme libovolnou ISO 8601 duration nebo shipard
suffix:

```bash
# ISO 8601:
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"duration":"PT1H"}'
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"duration":"P7D"}'

# Shipard suffix:
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"duration":"4h"}'

# Sugar pro běžné jednotky:
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"minutes":30}'
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"hours":1}'
curl -X POST /api/v1/_alerts/alerts/42/snooze -d '{"days":7}'
```

**Limity**: min 5 minut, max 365 dní.

**Expirace**: reconciler v dalším běhu zkontroluje `snoozed_until` a pokud
už uplynulo, alert přepne zpět na Active. **Žádný background job** —
expirace se vyhodnotí jen když check potvrdí, že problém stále existuje.

**Setup alerty snooze ani dismiss nepodporují.** Alerty checků
s `tags: ["setup"]` vrací na oba endpointy **409** s kódem `SETUP_ALERT`
— položka checklistu zmizí sama doplněním nastavení a odklikat ji by bylo
proti principu „stav se dopočítává, nepamatuje“
([`ds-setup.md`](ds-setup.md) D3/D13). Guard je fail-open: alert
s `check_id`, který v registry není, se nezakazuje.

---

## 9. `actions` JSON schéma

`actions` je pole akcí, které UI nabídne uživateli (typicky tlačítka v detailu
alertu). Schéma:

```json
[
    {
        "id":     "create_own_person",
        "label":  "Add own Person",
        "kind":   "open_form",
        "target": {
            "table":  "base_persons_persons",
            "mode":   "create",
            "preset": {"is_own": true}
        },
        "primary": true
    }
]
```

Podporované `kind` v MVP:

| kind | target tvar | Význam |
|---|---|---|
| `open_form` | `{table, mode: "create"\|"edit", id?, preset?}` | Otevři form pro vytvoření/úpravu záznamu. |
| `open_viewer` | `{viewerId, recordId?, viewGroup?, filters?}` | Naviguj do daného vieweru (a předvyber záznam). Cíl patří do `target` — karta feedu čte jen `action.target`, ne klíče na úrovni akce. |
| `open_panel` | `{panelId}` | Přepni do Nastavení a otevři panel (`navigationStore.navigateToPanel`). Používá agregovaná setup karta feedu (`ds-setup.md` D8). |
| `open_report` | `{reportId, params?}` | Otevři report z navigace (`navigationStore.navigateToReport`); `params` = období a parametry reportu ve tvaru deep-linku (`{fiscalYear, monthFrom, monthTo, …}`). Obsluhuje karta feedu i detail upozornění; report, který uživatel v navigaci nemá, se neotevře. První uživatel: `economy.assets.journal_mismatch` → Kontrola evidence × deník za dotčený rok. |

`primary: true` označuje hlavní akci — frontend ji vyrenderuje jako primary button.
Maximálně **jedna** akce s `primary: true` per alert (validuje `AlertFinding` ctor).

`label` je **už lokalizováno** v jazyce DS — generuje ho `$check->run()`.
Žádné `:cs`/`:en` v JSONu na úrovni alertu.

**Frontend implementace akcí je out of scope pro MVP** — frontend zatím akci
buď přesměruje na existující URL (`open_viewer`), nebo zobrazí toast.

---

## 10. CLI

### `shpd-ds alerts-run`

```
shpd-ds alerts-run                       # spustí všechny due checky
shpd-ds alerts-run --check=<id>          # konkrétní check, ignoruje schedule
shpd-ds alerts-run --all                 # všechny enabled, ignoruje schedule
```

Výstup:
```
Shipard alerts run

Running 1 check(s):
[FOUND] base.persons.missing_own_person     (1 findings — 1 new, 0 updated, 0 resolved, 9ms)

Summary: 0 ok, 1 found, 0 error, 0 skipped. 1 alerts found (1 new).
```

Exit codes:
- `0` — všechny checky `ok`, `found` nebo `skipped`
- `1` — alespoň jeden `error`

### `shpd-ds alerts-prune`

```
shpd-ds alerts-prune                # default --days=90
shpd-ds alerts-prune --days=30
shpd-ds alerts-prune --dry-run
```

Smaže z `core_alerts_alerts` Resolved/Dismissed alerty starší než `--days`.
Reconciler sám nikdy nemaže — toto je jediná cesta, jak se uzavřené alerty
dostanou z tabulky pryč.

---

## 11. HTTP API

Všechny endpointy pod prefixem `/api/v1/_alerts/` (Bearer token přes
`AuthMiddleware`, stejně jako ostatní endpointy):

| Method | Path | Popis |
|---|---|---|
| GET    | `/_alerts/registry`                          | Seznam zaregistrovaných checků + runtime info (lastRunAt, lastRunStatus, lastRunFindings, isRunning). |
| POST   | `/_alerts/run-due`                           | Spustí všechny due (a dosud nikdy nespuštěné) enabled checky. Vrátí souhrn `checksRun`, `totalFindings`, `newFindings`, `stats`, `results[]`. Analog `shpd-ds alerts-run` bez argumentů. |
| POST   | `/_alerts/checks/{checkId}/run`              | Synchronní re-run jednoho checku. Vrátí `AlertRunResult` + aktuální seznam open alertů. |
| POST   | `/_alerts/alerts/{id}/snooze`                | Body: `{"duration": "PT1H"}` nebo `{"hours":1}`. Nastaví state=Snoozed + `snoozed_until`. |
| POST   | `/_alerts/alerts/{id}/dismiss`               | Nastaví state=Dismissed + `dismissed_at`. |
| POST   | `/_alerts/alerts/{id}/unsnooze`              | Vrátí Snoozed → Active, vynuluje `snoozed_until`. |

Plus standardní CRUD `/api/v1/core_alerts_alerts` (pro list/detail) a viewer
endpointy `/_ui/viewer/core.alerts.alerts/...`.

State machine guards (HTTP 409 při porušení):
- `snooze`/`dismiss` pouze z Active nebo Snoozed
- `unsnooze` pouze ze Snoozed

---

## 12. Cron

`alerts-run` běží každých 5 minut přes systémový dispatcher —
slot `five-minutes` generovaného `/etc/cron.d/shipard` (`shpd-server cron
--slot=five-minutes`, per DS; soubor spravuje `shpd-server upgrade`,
živost hlídá `shpd-server doctor`). Žádná ruční cron konfigurace není
potřeba. Detaily viz [`cli.md`](cli.md) § `cron` a
[`operations/production.md`](operations/production.md) §10.

Jednotlivé checky mají vlastní `interval` (typicky 1h+), runner přeskakuje
ty, kde `next_run_at > NOW`. Když nic není due, exit 0.

Setup checky (`tags: ["setup"]`) mají záměrně krátký `interval` (`5m`,
tedy každý běh slotu) — jsou to levné `COUNT`/settings dotazy a karta
„Dokončit nastavení“ ve feedu čerpá z tabulky alertů, delší interval by
jen zvětšoval prodlevu mezi doplněním nastavení a zmizením karty
([`ds-setup.md`](ds-setup.md) D12).

`alerts-prune` běží týdně (slot `weekly`).

---

## 13. Otevřené body do dalších iterací

Following are explicitně **out of scope MVP**:

- Bank transactions unmatched check
- IoT low battery check
- Dashboard widget s agregací alertů (top severity, count by check)
- Per-user alert subscriptions / visibility
- Email digesty (denní/týdenní souhrn)
- Frontend tlačítka snooze/dismiss/runCheck v ViewerRow (klik na akce zatím
  jen toast — routing/preset až později)
- Tab bar pro alerts viewer (filter dropdown stačí)
- Bulk operace (snooze/dismiss víc alertů najednou)
- Notifikace v reálném čase (WebSocket / SSE)

---

[← Dokumentace](README.md)
