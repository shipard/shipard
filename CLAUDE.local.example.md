# CLAUDE.local.md — můj stroj

<!--
Šablona osobního souboru. Zkopíruj na CLAUDE.local.md (je v .gitignore)
a vyplň. Popis a pravidla: docs/ai-workflow.md §7. Drž ho krátký — Claude
Code ho načítá do každé session; delší text dej do souboru, který odsud
importuješ přes @cesta. Nepotřebné sekce smaž.
-->

## Stroj

- `project_id` checkoutu v MCP mostu: `shipard`
- Node: `export PATH=$HOME/.nvm/versions/node/<verze>/bin:$PATH`

## Zdroje dat na dev serveru

Režim: `volný` = ukázková/seedovaná data, Claude smí resetovat a zapisovat;
`reálná kopie` = čtení volné, mutace jen po schválení v chatu. Zdroj, který
tu chybí, je `reálná kopie`.

| DS ID | účel | původ | režim |
|-------|------|-------|-------|
| `4l3j-z0bz-kz39-echj` | ukázková data, ruční zkoušení UI | `dataset-seed` | volný |
| `<ds-id>` | integrační testy | `ds-create` + `dataset-seed` | volný |
| `<ds-id>` | ověřování importu | import ze starého Shipardu | reálná kopie |

- Adresář zdroje: `/opt/shipard/data-sources/<ds-id>`
- Databáze: read-only uživatel `claude_ro`, heslo v `<cesta k souboru>`
  (`export MYSQL_PWD="$(cat <cesta>)"`); název DB = ID s podtržítky.
- Integrační testy: `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<ds-id>`

## Moje workflow

<!-- volitelné: jak nasazuješ, reimportuješ, co ověřuješ po změně -->

## Importy

<!-- volitelné: soubory ze soukromého shipard/dev-env, např.
@~/sw/dev-env/dev-env.md
-->
