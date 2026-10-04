# Dev dashboard — prázdný seznam zdrojů dat odkazuje na + New DS

**Stav:** naplánováno — #96 D26

## Cíl

Na čerstvé instalaci (bootstrap, `docs/local-dev.md`) ukáže dev dashboard
prázdnou tabulku s hláškou:

```
No data sources found. Run sudo shpd-server ds-create --name <n> --language cs --country cz
```

Nováčka posílá do terminálu, přestože o řádek výš je tlačítko **+ New DS**,
které udělá všechno (ds-create, ds-upgrade, admin, seed). Rada je navíc
chybná: `sudo` není potřeba a samotný `ds-create` bez `ds-upgrade` a
`user-create` použitelný zdroj dat nevytvoří.

## Před implementací přečti

- `src/Api/Controller/DevDashboardController.php` — funkce `setEmptyRow()`
  v inline JS stránky se seznamem a hlavička s odkazem `+ New DS`

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D26** — prázdný stav seznamu odkazuje na formulář + New DS; CLI
  příkaz z hlášky zmizí. Texty dashboardu zůstávají anglicky (jako zbytek
  dev dashboardu).

## Co je potřeba udělat

V `setEmptyRow()` nahradit text a `<code>` blok za:

> No data sources yet. Create one with **+ New DS** — tick „Seed test
> data“ to get sample records.

kde „+ New DS“ je odkaz na `/_dev/ds-create/` (stejný cíl jako v hlavičce).
Text a odkaz skládat přes DOM (`createTextNode`, `createElement('a')`), ne
přes `innerHTML`, stejně jako stávající kód. Pokud se popisek zaškrtávátka
ve formuláři jmenuje jinak než „Seed test data“, použít skutečný název.

Pokud existuje test, který na text prázdného stavu kontroluje, upravit ho.

## Commit strategie

1. `dev dashboard: prázdný seznam DS odkazuje na + New DS (#96 D26)` —
   kód, hlavička tasku, `tasks/README.md` (oblast „Dev dashboard“) +
   `python3 scripts/tasks-index.py`

## Hotovo když

- [ ] prázdný dashboard ukazuje odkaz na + New DS, žádný CLI příkaz
- [ ] odkaz vede na formulář vytvoření DS
- [ ] hlavička tasku a `tasks/README.md` aktualizované
