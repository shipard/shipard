# Demo sada „IT servis“ — fáze 2d: stavy periodických zakázek

**Stav:** hotovo — 2026-10-21, #131 D43–D45

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

- [x] ukončená zakázka (Ukončeno, datum ukončení, evidence do měsíce −3)
- [x] nová zakázka od měsíce −5 s konceptem v měsíci kotvy
- [x] `--dry-run` ke kotvě nevystaví nic, dump → seed → dump shodný
- [x] souhrn ekonomiky splňuje D25, test cílů projde
- [x] druhý běh `build.py` nic nezmění; unit testy generátoru projdou
- [x] ověření podle oddílu Ověření, výsledky v Odchylkách
- [x] dokumentace aktualizovaná
- [x] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Odchylky a zjištění při implementaci (2026-10-21)

- **Tvar v příběhu.** `until` = posun měsíce ukončení v okně, ukončení
  poslední den měsíce; stav se odvozuje: ukončení před kotvou = Ukončeno,
  jinak V pořádku s plánovaným ukončením (`until: 0` validace pustí, příběh
  nepoužívá). `since` smí být i v okně (`<= 0`): zahájení = první den
  měsíce a „fakturovat od“ = `max(zahájení, první období v okně)`; u
  čtvrtletní zakázky začátek čtvrtletí, do kterého měsíc spadá (leží-li
  před oknem, první čtvrtletí v okně jako dřív). Faktury zakázky vznikají
  jen za období s počátkem mezi „fakturovat od“ a ukončením — zrcadlo
  `PeriodCalendar::duePeriods`, které `test_workorders.py` porovnává
  s portem `due_periods(…, date_end)` i nad posunutými kotvami.
- **Validace navíc:** `until` v okně a ne před prvním fakturovaným obdobím,
  `since` / `until` jen u periodických pravidel, `conceptInAnchorMonth` jen
  u zakázky, která v měsíci kotvy ještě fakturuje, `priceFrom.month` po
  prvním fakturovaném období a nejpozději v měsíci ukončení.
- **Přesun funkcí.** `date_start_for` a `invoice_from` žijí v `timeline.py`
  (validace příběhu je potřebuje; `workorders.py` importuje `model` →
  `story`, takže by vznikl cyklus), `workorders.py` je dál re-exportuje;
  nová `Period.offset_of()` a `quarter_start()`.
- **Nový odběratel** „Veterinární klinika Tlapka s.r.o.“ (`tlapka`, plátce,
  platí včas, 8 000 Kč měsíčně od května): fiktivní IČO z pásma 8x/9x
  ověřené v ARES (`build.py --verify-identities`, 70 kontrol, 0 selhání,
  `verifiedAt` 2026-10-21), účet s mod 11 + IBAN, telefon `000`, doména
  `.example`. V `partners.jsonc` i v `billing` je **na konci**: pořadí
  odběratelů určuje deterministické hodiny ad hoc faktur a řada `S` je
  průběžná — stávající zakázky S0001–S0013 mají stejné číslo, nová je
  S0014. Ukončený paušál knihkupectví je **S0007** (ne S0008 — pravidlo
  `once` zálohové faktury zakázku nemá).
- **Čísla dokladů:** nové faktury od května se vkládají chronologicky, takže
  vydané faktury od 1260066 dál mají jiné číslo než dřív — tím se změnily
  VS plateb v bankovních výpisech od května (BU1) a od června (BU2) a
  čísla dokladů v evidenci období ostatních zakázek; částky beze změny.
  Zástupná čísla konceptů měsíce kotvy: cukrárna `!0000000156` jako dřív,
  veterina `!0000000157` (pozici po knihkupectví převzala).
- **Ekonomika (D25 dál platí, cena paušálu zůstala 8 000):** výnosy
  2 119 521, náklady 1 836 635, výsledek +282 886 = 13,3 % (po odpisech
  serveru +258 886 = 12,2 %), ve ztrátě jen červen, zahraničí 16,8 %, zboží
  1,35×, BU1 300 000 → minimum 191 134 (jako po 2c) → 446 207. Souhrn
  `build.py` nově vypisuje „periodické zakázky: 13 V pořádku, 1 Ukončeno“
  a řádek pro každou zakázku zahájenou v okně nebo ukončenou (stav,
  zahájení / ukončení, první a poslední vystavené období). Druhý běh
  `build.py` nic nezmění (0 zapsáno, 68 PDF beze změny).
- **Ověření** (čerstvý volný DS `53qb-u…` „Demo IT servis 2d“, ponechán
  Anně k ručnímu průchodu — agenda Zakázky s archivní skupinou, tab
  Fakturace S0014, karta ke kontrole; po kontrole `shpd-server ds-delete`):
  seed 3 varování jako dřív (účetní položky z nabídky, dvě chybějící IČO
  zahraničních firem), `diff -r` jen `manifest.created`;
  `work-orders-invoice-run --dry-run --date=2026-10-20` = 0 k vystavení,
  0 čeká, 0 selhalo, 0 zablokováno; zakázky 13 periodických V pořádku +
  1 Ukončeno + 2 projekty Ukončeno; 115 období `issued` s dokladem
  i otiskem, bez `result`; 113 faktur V pořádku + 2 koncepty se zdrojem
  periodická fakturace, 119 dokladů s dimenzí na hlavičce; **S0007**
  stav 70, zahájení 2025-09-01, ukončení 2026-07-31, `inv_from`
  2026-01-01, 7 období leden–červenec, faktury jen leden–červenec
  (7 × 5 808 Kč, všechny neuhrazené), deník 6xx 33 600; **S0014** stav 40,
  zahájení = `inv_from` 2026-05-01, 6 období květen–říjen, 5 faktur
  V pořádku + koncept `!0000000157`, deník 6xx 40 000; deník 1 383 řádků,
  MD = D, 0 chybových, 331 s dimenzí zakázky; 240 transakcí zaúčtovaných;
  261100, 261300, 331 / 336 / 342 na nule, 343217 MD = 343207 D
  (9 797,37), 221100 = 446 207,21 = closing BU1; saldokonto Pohledávky
  132 případů — 117 uzavřených, 15 otevřených (7 neuhrazených faktur
  ukončeného odběratele leden–červenec + 8 stejného druhu jako dřív:
  pozdní, částečné, nespárované, 1 před splatností), Závazky 59
  uzavřených + 1 se splatností za kotvou, Nespárované platby 3 záměrné +
  případ mezd uzavřený, Přijaté zálohy 1 otevřená; Výsledovka Od počátku
  roku: náklady 1 836 636,56, výnosy 2 119 527,49 → +282 890,93 (haléřová
  zaokrouhlení prodejek jako dřív); `alerts-run --all` (cron vypnutý):
  `economy.workorders.invoices_to_review` „2 koncepty z periodické
  fakturace ke kontrole“, `period_failed` / `catchup_blocked` /
  `period_waiting` 0.
- Ruční průchod v aplikaci — Anna na DS `53qb-u…`.

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D43** — paušál odběratele, který nikdy neplatí, ukončený pro
  neplacení ke konci měsíce −3; Ukončeno, evidence do měsíce −3
- ✓ **D44** — nový tuzemský odběratel s měsíčním paušálem od měsíce −5,
  převezme koncept v měsíci kotvy
- ✓ **D45** — cíle D25 dál (úprava jen ceny nového paušálu), stejné druhy
  případů saldokonta a banky, `--dry-run` ke kotvě nic, bez změn formátu
  a PHP
