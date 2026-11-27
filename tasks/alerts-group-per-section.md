# Upozornění — skupinová karta feedu per check a sekce (#181)

**Stav:** hotovo — 2026-11-27 (#181 D1–D5); ověřeno unit testy a sběrem feedu nad volným zdrojem dat s demo sadou a doplňkem Budovy (nálezy dvou sekcí vložené v transakci a vrácené)

> Design: issue #181. Náprava omezení nalezeného při ověření
> `tasks/buildings-phase2.md`. Navazuje na `tasks/dashboard-alert-grouping.md` (skupinové karty)
> a `tasks/periodic-invoicing-sources.md` (#175 U4 — sekce odznaku
> v `context.navSection` nálezu). Změna jádra `core.alerts`; doplněk ani
> zdroj fakturace nejmenuje.

## Problém

Check společný víc agendám (upozornění periodické fakturace: zakázky
i smlouvy doplňku Budovy) dává sekci odznaku do nálezu. Nad tři aktivní
nálezy jednoho checku dělal `AlertsSource` jednu skupinovou kartu a ta sekci
nesla, jen když se v ní shodly všechny nálezy checku. S nálezy ze dvou sekcí
zároveň karta sekci neměla: `GET /_ui/section-badges` nevrátil odznak ani
pro jednu z nich a karta chyběla i ve filtru `GET /_ui/dashboard?section=`.

Ověřeno na volném zdroji dat: 10 nálezů zakázek a 1 nález smlouvy téhož
checku → žádný odznak; po vyřešení nálezů zakázek odznak Budov ano.

## Rozhodnutí

- **D1** — Skupinová karta feedu se tvoří per check **a** sekce z nálezu
  (`GROUP BY check_id, context.navSection`). Nálezy bez sekce v kontextu
  tvoří vlastní skupinu se sekcí checku z `module.jsonc`.
- **D2** — Práh skupiny (víc než 3) platí per dvojice check × sekce. Sekce
  pod prahem má individuální karty i tehdy, když jiná sekce téhož checku má
  skupinovou — karty jedné sekce nezávisí na nálezech jiné.
- **D3** — Id skupinové karty: `alert-group:<check>:<sekce>`; skupina bez
  sekce z nálezu `alert-group:<check>` (jako dřív).
- **D4** — Skupinová karta se sekcí z nálezu nese v podtitulku název sekce
  („10 upozornění · Zakázky“), aby šly rozlišit dvě karty téhož checku.
- **D5** — Kontrakt karty, `FeedCollector` a frontend se nemění. Odznak dál
  počítá karty, ne nálezy.

Zavržená varianta: jedna karta per check a odznaky počítané z nálezů zvlášť
— opravila by jen odznaky (filtr `?section=` čte tutéž `navSection` karty)
a zasáhla by do `FeedCollector` a kontraktu karty.

## Hotovo když

- [x] Check s nálezy ze dvou sekcí nad prahem dá odznak u obou sekcí.
- [x] Check s jednou sekcí a nálezy bez sekce se chovají jako dřív.
- [x] Unit sada prochází.

## Poznámky k implementaci

- Vše je v `modules/core/alerts/src/Feed/AlertsSource.php`. Fáze 1 seskupuje
  podle `check_id` a výrazu `NULLIF(JSON_VALUE(context, '$.navSection'), '')`;
  fáze 2 vybírá individuální řádky oknem
  `COUNT(*) OVER (PARTITION BY check_id, sekce) <= 3` nad týmž výrazem —
  pojistný limit zdroje tak neodřízne jediný nález jedné sekce za stovkami
  nálezů druhé.
- Název sekce do podtitulku jde z `global.navSections` (vč. sekcí modulů);
  sekce, kterou navigace nezná (sentinel `_top`, sekce vypnutého modulu),
  a zdroj dat bez compiled configu dávají podtitulek bez názvu. Nový klíč
  katalogu `core.alerts.feedTexts` → `group.subtitleSection` — bez
  `ds-upgrade` zdroje dat je podtitulek anglicky (fallback), odznaky fungují.
- Dopad na feed: check má nejvýš jednu skupinovou nebo tři individuální
  karty **na sekci** (dřív na check); `sections[]` a `summary.counts`
  počítají karty. Id skupinové karty checku se sekcí z nálezu se změnilo
  (nic ho nečte); AI shrnutí se po změně karet jednou přegeneruje.
- **Odznak počítá karty** (D5): deset nálezů ve skupinové kartě dá odznak
  „1“, tři nálezy „3“. Platí pro všechny skupinové karty od zavedení
  odznaků; počítání po nálezech by byla změna `FeedCollector::sectionBadges()`
  s vlastním rozhodnutím.

Ověření: unit sada celá; `AlertsSourceTest` (dvě sekce nad prahem, sekce
pod prahem vedle skupinové, nálezy bez sekce, práh per sekce, název sekce,
tvar dotazů). Na volném zdroji dat `kdb4-4` (`ds-upgrade`,
`alerts-run --all`): k checku `catchup_blocked` vloženo v transakci
10 nálezů sekce Zakázky a 1, pak 5 nálezů sekce Budovy, sběr feedu týmž
kódem jako `GET /_ui/section-badges` (`FeedCollector::collect()` →
`sectionBadges()`) vrátil odznak `workOrders` i `buildings` a karty
„10 upozornění · Zakázky“ / „5 upozornění · Budovy“; transakce vrácena.
Volání endpointu přes HTTP a pohled do sidebaru v prohlížeči neproběhly.
