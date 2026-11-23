# Task: Druh zdroje dokladu „Import ze starého Shipardu“

**Stav:** naplánováno — zadání 2026-11-23 (#166 etapa 6, drobnost na nové straně)

**Issue:** #166 (etapa 6)

**Cíl:** Doklady z importu ze starého Shipardu nesou
`source.kind: "import.oldShipard"`, ale číselník druhů zdroje dokladů ten
klíč nezná — má ho jen číselník položek. Doklad se uloží, jen u něj chybí
popisek zdroje a ve filtru přehledu dokladů se nedá vybrat.

## Před implementací přečti

- `modules/docs/core/config/sourceKinds.jsonc` (`docs.core.sourceKinds`)
- `modules/economy/items/config/sourceKinds.jsonc` — vzor záznamu
  `import.oldShipard`
- `modules/docs/core/src/DocsHeadsViewer.php` — filtr zdroje dokladu
- `docs/exchange-format.md` — § 5 (blok `source`), § 12 Source lineage

## Co je potřeba udělat

1. Do `docs.core.sourceKinds` přidat `import.oldShipard` se stejnými
   názvy jako u položek („Import ze starého Shipardu“), vedle
   `import.flexibee` a `import.pohoda`.
2. `docs/exchange-format.md` § 5 a § 12: doplnit klíč do výčtu druhů
   zdroje.
3. Test filtru zdroje (`DocsHeadsViewerSourceKindFilterTest`) nech být,
   pokud výčet z konfigurace nečte; jinak doplň.
4. `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

Na zdroji s třídou dat `free` po `ds-upgrade`: filtr zdroje v přehledu
dokladů nabízí „Import ze starého Shipardu“.

## Mimo rozsah

Runner importu (starý Shipard, `imports/newShipard` task 52) — klíč posílá
už dnes.

## Hotovo když

- [ ] klíč v `docs.core.sourceKinds` a v dokumentaci
- [ ] cílené testy, stav tasku
