# Modul `core.alerts`

Subsystém upozornění pro uživatele — oznamuje problémy, které systém detekoval,
ale uživatel by je sám neviděl (chybějící nastavení DS, doklady visící v opravě,
neúspěšná synchronizace atd.).

Tento modul poskytuje **pouze infrastrukturu**:

- Tabulky `core_alerts_alerts` a `core_alerts_check_states`
- cfgItems: `severities` (info/warning/error), `alertStates` (Active/Snoozed/
  Resolved/Dismissed), `checkRunStatuses` (ok/found/error)
- `AlertsViewer` — read-only viewer alertů s filtrem `alert_state`

**Konkrétní checky** registrují jiné moduly přes `alertChecks` blok v jejich
`module.jsonc` (typicky závisí na `core.alerts`). První takový check je
`base.persons.missing_own_person` v modulu `base.persons`.

## Struktura

```
modules/core/alerts/
├── module.jsonc                  # registrace tabulek, cfgItems, vieweru
├── README.md                     # tento soubor
├── tables/
│   ├── core_alerts_alerts.jsonc        # 408
│   ├── core_alerts_alerts.md           # popis schématu + lifecycle
│   ├── core_alerts_check_states.jsonc  # 409, hideFromNavigation
│   └── core_alerts_check_states.md
├── config/
│   ├── severities.jsonc           # 10 info / 20 warning / 30 error + style klíče
│   ├── alertStates.jsonc          # 10/20/70/80 + isOpen flag
│   ├── checkRunStatuses.jsonc     # ok / found / error
│   ├── viewerDefaults.jsonc       # toolbar + row akce labely
│   └── viewerDetailLabels.jsonc   # detail taby
├── forms/
│   └── core_alerts_alerts.jsonc   # read-only form (zatím se v UI nepoužívá)
└── src/
    ├── AlertsViewer.php           # viewer s severity/state badge, filter
    └── AlertDocument.php          # tenký — žádné hooky (vše řeší reconciler)
```

Backend infrastruktura (sdílená, ne specifická pro modul) žije v
`src/Core/Alerts/`:

- `AlertCheck` (abstract base)
- `AlertFinding` / `AlertCheckDefinition` / `AlertRunResult` (readonly VO)
- `AlertCheckRegistry` / `AlertReconciler`
- `IntervalParser`

API kontroler: `src/Api/Controller/AlertsController.php`.

CLI: `shpd-ds alerts-run`, `shpd-ds alerts-prune` — třídy v `src/Command/`,
registrace polem `commands` v `module.jsonc`; úlohy cronu (`alerts-run`
ve slotu `five-minutes` s pořadím 900, `alerts-prune` ve slotu `weekly`)
deklaruje `cronJobs` tamtéž (#172).

## Kompletní spec

Viz [`docs/alerts.md`](../../../docs/alerts.md) — architektura, JSONC schéma
`alertChecks`, PHP API checku, reconciliation logic, stavy, snooze, actions,
CLI, HTTP API a cron.
