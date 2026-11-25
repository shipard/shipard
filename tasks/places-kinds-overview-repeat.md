# Druhy míst: kontrakt detailu — „Typ místa“ opakuje titulek (#169)

**Stav:** hotovo — 2026-11-25 (nález z ověření #172 C1); ověřeno nad dvěma volnými zdroji dat s tabulkami Míst

> Drobný task pro Claude Code (1 commit). Nález z integrační sady při
> ověřování `tasks/module-ui-extensions.md`; s tím taskem nesouvisí.

## Co se děje

`tests/Integration/Api/ViewerDetailContractTest::testOverviewDoesNotRepeatHeader`
nad volným zdrojem dat po `ds-upgrade` (tabulky Míst, #169) padá:

```
viewer base.places.kinds: položka „Typ místa“ v Přehledu opakuje titulek hlavičky (5 z 5 vzorků)
```

**Příčina:** `PlaceKindsProvisioner` seeduje pro každý typ místa
(`base.places.types`) jeden druh se stejným jménem jako typ (Budova / typ
Budova, Podlaží / typ Podlaží, …). Titulek detailu je název druhu
(`displayPattern`), Přehled ukazuje typ — u seedovaných druhů je to tentýž
text. Stejná situace jako u druhů položek (`economy.items.kinds`, Služba /
typ Služba), které test už povoluje.

Údaj v Přehledu je správně: typ řídí hierarchii a chování míst a uživatel
druh přejmenuje (Kancelář, Zasedací místnost), takže se texty rozejdou.
Kontrola test pouští právě kvůli systematickému opakování ve vzorku.

## Co je potřeba udělat

- V `tests/Integration/Api/ViewerDetailContractTest.php` doplň do
  `OVERVIEW_REPEAT_ALLOWED` položku `'base.places.kinds' => ['Typ místa']`
  s komentářem ve stylu ostatních (seedované druhy míst se jmenují stejně
  jako typ, uživatelské druhy ne).
- Viewer ani seed neměň.
- Commit: `test(places): kontrakt detailu povoluje typ místa shodný s názvem druhu (#169)`
  včetně `**Stav:**` tohoto tasku a `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `testOverviewDoesNotRepeatHeader` nad volným zdrojem dat s tabulkami
      Míst prochází
- [x] ostatní testy `ViewerDetailContractTest` beze změny výsledku
