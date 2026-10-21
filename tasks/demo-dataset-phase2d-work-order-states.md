# Demo sada „IT servis“ — fáze 2d: stavy periodických zakázek

**Stav:** naplánováno — #131 D43–D45

## Kontext / Cíl

Fáze 2b převedla paušály příběhu na periodické zakázky, ale všechny běží
celým oknem příběhu. Fáze 2d doplní bod odložený v 2b: jeden paušál
**ukončený** uprostřed okna a jeden **nový**, zahájený uprostřed okna.
V agendě Zakázky tak bude vidět stav Ukončeno (archivní skupina prohlížeče)
a v tabu Fakturace zakázka, jejíž evidence začíná až uvnitř okna.

Mechanika je hotová — obálka `shpd.dataset.workOrder.v1` nese stav
`finished` i `dateEnd`, `PeriodCalendar` vystaví poslední období celé
(bez krácení) a první období je to, které obsahuje „fakturovat od“;
fakturuje jen zakázka V pořádku. **Mění se jen příběh, generátor a sada.**

## Návaznost

- **#131** — D1–D42 (komentáře v issue), tento task **D43–D45**
- `tasks/demo-dataset-phase2b-work-orders.md` — zakázky v sadě, D32
  (faktury jako z periodické fakturace), D34 (`since`, zahájení před oknem)
- `tasks/demo-dataset-economy.md` — cíle ekonomiky D25 a `test_economy.py`
- `docs/work-orders.md` §5.4 (stavy, D18), §5.5 (periodická fakturace)

## Před implementací přečti

- `demo/datasets/stories/it-servis/story.jsonc` (`billing`),
  `partners.jsonc` (odběratelé, chování plateb), `demo/datasets/README.md`
- `tools/invoice-generator/invoicegen/story.py` (validace `billing`,
  `since`), `expand.py` (zakázky a faktury z předpisu), `economy.py`
- `modules/economy/workOrders/src/Invoicing/PeriodCalendar.php`
  (`duePeriods`, datum ukončení), `src/WorkOrderDocument.php` (koncové
  stavy, `date_end`)

## Rozsah

**V rozsahu:**

- příběh: ukončený paušál (D43), nový odběratel s paušálem (D44)
- generátor: zahájení uvnitř okna, ukončení se stavem Ukončeno, validace
- souhrn ekonomiky (zakázky podle stavu), přegenerovaná sada, testy,
  dokumentace (D45)

**Mimo rozsah:**

- změny formátu sady, seederu, exportéru nebo PHP — kdyby byly potřeba,
  zastav se, zapiš do Odchylek a zeptej se
- upomínky, krácení období, Zrušeno (nerealizovaná zakázka)

## Návrh

### D43 — Ukončený paušál

- Odběratel `stranka` (paušál 4 800 Kč měsíčně, chování plateb `never`):
  smlouva ukončena pro neplacení **ke konci měsíce −3**.
- Zakázka ve stavu **Ukončeno** (`finished`), `dateEnd` = poslední den
  měsíce −3. Evidence období končí měsícem −3; faktury za měsíce −2 až 0
  (včetně konceptu v měsíci kotvy) v sadě nejsou.
- Dosavadní neuhrazené faktury zůstávají v saldokontu (dluh bývalého
  zákazníka); chování `never` se nemění.
- Story: nový klíč u pravidla `billing`, např. `until` (posun měsíce
  ukončení, poslední den měsíce) a `state` se odvodí (ukončení před kotvou
  = Ukončeno). Přesný tvar navrhne implementace; validace odmítne `until`
  mimo okno a `until` před prvním fakturovaným obdobím.

### D44 — Nový paušál

- Nový fiktivní tuzemský odběratel (plátce DPH, platí včas — chování
  `onTime`), záznam v `partners.jsonc` podle ostatních (fiktivní IČO a DIČ,
  telefon `000…`, žádný reálný subjekt).
- Měsíční paušál `SPRAVA-IT` kolem 8 000 Kč od **měsíce −5**: zahájení
  i „fakturovat od“ první den měsíce. `since` dnes musí být před oknem
  (D34) — rozšířit tak, aby `since` uvnitř okna znamenalo zahájení
  i první fakturované období v tom měsíci.
- Příznak `conceptInAnchorMonth` se přesune z ukončeného odběratele na
  nového, aby karta „ke kontrole“ dál ukazovala dva koncepty z periodické
  fakturace.
- Číslo zakázky: řada `S` je průběžná; nové zakázce patří další číslo
  podle pořadí, v jakém generátor čísla přiděluje (u stávajících zakázek
  se čísla nesmí přečíslovat — ověř diff sady).

### D45 — Ekonomika a ověření

- Cíle D25 platí dál (`test_economy.py`: zisk 8–15 % výnosů i po
  odpisech majetku, nejvýš 3 ztrátové měsíce, zahraničí 10–20 %, BU1 nad
  nulou). Vyjde-li něco mimo, upraví se cena nového paušálu — ne jiné
  částky příběhu.
- Případy saldokonta a banky zůstanou stejného druhu (pozdní, částečné,
  nikdy, nespárované platby); ubudou jen neuhrazené faktury ukončeného
  odběratele za měsíce −2 až 0.
- Souhrn z `build.py` vypíše periodické zakázky podle stavu (V pořádku /
  Ukončeno) a u nové a ukončené zakázky první a poslední vystavené období.

## Kroky (commity)

1. **Příběh a generátor** — `until`, `since` uvnitř okna, stav Ukončeno,
   nový odběratel, přesun konceptu; unit testy: validace `until` a `since`,
   evidence období ukončené a nové zakázky (i nad posunutou kotvou),
   žádná faktura po ukončení, cíle D25.
2. **Sada a dokumentace** — přegenerovaná sada, ověření,
   `demo/datasets/README.md` (zakázky podle stavu), komentář v
   `story.jsonc`, `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

Čerstvý volný zdroj dat, `dataset-seed`, `dataset-dump`, `diff -r` jako
v 2b (jen `manifest.created`), a dál:

- seed bez nových varování;
- `work-orders-invoice-run --dry-run --date=<kotva>`: nic k vystavení
  a nic k dohánění;
- ukončená zakázka: stav Ukončeno, datum ukončení, evidence do měsíce −3,
  žádná vydaná faktura odběratele po měsíci −3; v prohlížeči zakázek
  v archivní skupině;
- nová zakázka: evidence od měsíce −5, koncept v měsíci kotvy, odběratel
  v Adresáři;
- karta / upozornění „ke kontrole“: dva koncepty z periodické fakturace;
- saldokonto: neuhrazené faktury ukončeného odběratele do měsíce −3,
  ostatní případy beze změny druhu (počty do Odchylek);
- Výsledovka odpovídá souhrnu, D25 splněno.

Ověřovací zdroj dat nech Anně a zapiš prefix.

## Pasti

- **Ukončená zakázka a seed.** Seeder zakázek nastaví cílový stav přes
  applier; ověř, že Ukončeno s datem ukončení projde validací (datum
  ukončení ≥ zahájení) a že evidence období se k ukončené zakázce zapíše
  (krok po dokladech).
- **Posun kotvy:** měsíce −5 a −3 se posouvají s kotvou; ukončení musí
  zůstat před kotvou a nový paušál v okně i nad posunutou kotvou.
- **Pořadí a čísla:** nový odběratel a nová zakázka posunou pořadí souborů
  v `persons/` a `workOrders/` a čísla dokladů; stávající zakázky nesmí
  změnit číslo.
- **Koncepty v měsíci kotvy** mají zástupná čísla `!` + id — přesun
  příznaku posune předpočítané id (D31 2b).
- **Fiktivní data** platí dál.

## Hotovo když

- [ ] ukončená zakázka (Ukončeno, datum ukončení, evidence do měsíce −3)
- [ ] nová zakázka od měsíce −5 s konceptem v měsíci kotvy
- [ ] `--dry-run` ke kotvě nevystaví nic, dump → seed → dump shodný
- [ ] souhrn ekonomiky splňuje D25, test cílů projde
- [ ] druhý běh `build.py` nic nezmění; unit testy generátoru projdou
- [ ] ověření podle oddílu Ověření, výsledky v Odchylkách
- [ ] dokumentace aktualizovaná
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D43** — paušál odběratele, který nikdy neplatí, ukončený pro
  neplacení ke konci měsíce −3; Ukončeno, evidence do měsíce −3
- ✓ **D44** — nový tuzemský odběratel s měsíčním paušálem od měsíce −5,
  převezme koncept v měsíci kotvy
- ✓ **D45** — cíle D25 dál (úprava jen ceny nového paušálu), stejné druhy
  případů saldokonta a banky, `--dry-run` ke kotvě nic, bez změn formátu
  a PHP
