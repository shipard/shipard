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

Přehled, třídu dat, účel a původ každého zdroje dává `shpd-server ds-list`
(`--json` pro Clauda) — tabulka se tu nevede (#126 D10). Třída `free` =
ukázková/seedovaná data, Claude smí resetovat a zapisovat; `realCopy` =
čtení volné, mutace jen po schválení v chatu; zdroj bez třídy je `realCopy`.
Na `free` přepínám jen já v terminálu: `cd <adresář zdroje> && sudo shpd-ds
ds-class set free --purpose="…"`. Zdroj `realCopy` má mít vypnuté periodické
úlohy (`shpd-server ds-cron`).

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
