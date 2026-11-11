# Design system — tabulka reportu: sloupec Účet, stav a zprávy (D63, D64)

**Stav:** hotovo — 2026-11-11 (#133 Další nesoulady, D63, D64); varování nese tón jen ikonou, chyba i textem (kontrast, viz Odchylky); „Účet“ je i nad čísly řádků Přiznání k DPH (viz Odchylky); ověřeno v Chromiu nad demo sadou IT servis a s podvrženými zprávami, ruční ověření na dev serveru prošlo (viz Ruční ověření)

## Kontext / Cíl

Dva body checklistu #133 „Další nesoulady“, oba v `ReportView.svelte`:

- **Odsazení a číslo účtu.** Název se odsazuje o 1 rem na úroveň (třída 1,
  skupina 2, syntetika 3, analytika 4) a číslo účtu stojí ve stejné buňce
  se šířkou 4,5 em — název analytického řádku začíná zhruba 140 px od
  kraje. Export (`ReportTabularizer`) má přitom číslo ve vlastním sloupci
  „Účet“. Druhou část bodu — držící hlavičku — vyřešila #138 (D57), ručně
  ověřeno.
- **Štítek a barevné řádky.** Stav reportu je vlastní štítek nad tabulkou,
  řádky se zprávou mají barevné pozadí a text, zprávy pod čarou barvy
  z rodiny `--shpd-color-alert-*`. Kvůli tomu má `ReportView` dočasnou
  výjimku v kontrole tokenů (D61).

**Cíl:**

1. Číslo účtu ve vlastním sloupci „Účet“ jako v exportu; v účtových
   reportech bez odsazení názvu (D63).
2. Stav reportu jako `Notice`, řádek se zprávou jen barvou textu a ikonou,
   zprávy pod čarou bez `alert-*` (D64).
3. `ReportView` vypadne z výjimky kontroly tokenů; výjimka i konstanta
   zmizí.

**Mimo rozsah:**

- backend a export — `account` posílá backend u všech řádků, které ho mají,
  i u mezisoučtů (prefix třídy, skupiny, syntetiky); `ReportTabularizer`
  a XLSX / CSV beze změny;
- výběr období (`PeriodPicker`, `VatPeriodPicker`), stránka reportů
  (`ReportsPage`) kromě toho, co si vyžádá změna `ReportView`;
- přepínač „v tisících“, drill-down odkazy (`link`, `cellLinks`) —
  chování beze změny, jen se přesunou s buňkou;
- hex barvy a ostatní chyby u prvku mimo report — jiné body checklistu.

## Návaznost

- **#133** — D57 (držící hlavička, `TableScroll fill`), D40 (`Notice`),
  D61 (hláška × chyba u prvku, kontrola tokenů), D63, D64; checklist
  „Další nesoulady“ (body Reporty a Štítek a barevné řádky)
- **#83** D72 — export (`ReportTabularizer`: sloupec Účet, jen když účet
  má některý řádek)
- `tasks/design-system-notices.md` — zavedení výjimky
  `ALERT_TEMPORARY_EXCEPTIONS`

## Před implementací přečti

- `docs/reports.md` §3.1 (tvar `ReportResult`, `level`), §3.3 (stav
  a zprávy, `rowRef`), §10 (levely), §15.2 (pravidla převodu exportu),
  §16.5 (drill-down)
- `docs/design-system.md` §8 Hlášky a pruhy (D60, D61), §8 tabulky
  (`TableScroll`, D57)
- `frontend/src/components/reports/ReportView.svelte` — celé
- `src/Core/Reports/Export/ReportTabularizer.php` — `hasAccount`, `cells()`
- `frontend/src/components/ui/Notice.svelte`
- `scripts/check-css-tokens.py` — `ALERT_TEMPORARY_EXCEPTIONS`

## Rozhodnutí (potvrzená v #133)

- ✓ **D63** Report: číslo účtu ve vlastním sloupci „Účet“ vlevo před
  názvem (jako export, jen když účet má některý řádek); číslo mají
  i mezisoučty (prefix třídy, skupiny, syntetiky — backend ho posílá už
  dnes, export beze změny). V účtových reportech se název neodsazuje
  (hierarchii nese délka čísla a tučné mezisoučty); odsazení podle úrovně
  zůstává u reportů bez účtů.
- ✓ **D64** Report podle D61: stav reportu nad tabulkou je `Notice`
  (`danger` / `warning`), ne štítek; řádek se zprávou je chyba u prvku —
  barva textu v tónu a ikona před názvem, bez barevného pozadí (pozadí jen
  mezisoučty); seznam zpráv pod tabulkou zůstává, barvy textu tónu místo
  `alert-*`. `ReportView` tím vypadne z výjimky kontroly D61.

## 1. Sloupec Účet (D63)

- `hasAccount` = některý řádek má neprázdné `account` (stejné pravidlo
  jako `ReportTabularizer`).
- Je-li `hasAccount`: první sloupec hlavičky „Účet“ (nový i18n klíč
  `reports.column.account`, cs „Účet“, en „Account“ — stejně jako
  `ReportExportLabels`), buňka `shpd-report__td--account`: zarovnání
  vlevo, `font-variant-numeric: tabular-nums`, `white-space: nowrap`,
  barva textu jako dnes `__account` (`text-secondary`), u mezisoučtů
  a součtu dědí tučnost řádku. Řádek bez účtu (součet, dopočtený řádek)
  má buňku prázdnou. Druhý sloupec je název.
- Hlavička: u dvouřádkové hlavičky (`hasSides`) má „Účet“ `rowspan` jako
  sloupec názvu; prázdná buňka hlavičky nad názvem zůstává.
- **Odsazení:** je-li `hasAccount`, název se neodsazuje (padding jako
  ostatní buňky, žádný `padding-left` podle `level`). Není-li, odsazení
  podle `level` zůstává jako dnes.
- Šířky: sloupec Účet se drží obsahu (žádná pevná `min-width`);
  `min-width: 14rem` názvu (#138) zůstává — nově počítá bez čísla, název
  tak má víc místa. Na 390 px ověř, že Účet + název se vejdou bez
  lámání čísla a zbytek roluje do strany v `TableScroll`.
- `.shpd-report__account` (inline span) zmizí.

## 2. Stav reportu (D64)

- `.shpd-report__badge` → `Notice` nad tabulkou: `tone` `danger` při
  `status === 'errors'`, `warning` při `warnings`; text z dnešních klíčů
  `reports.status.errors` / `reports.status.warnings`; ikonu nedávej
  (hláška ikonu nepotřebuje; dnešní ikona byla náhrada tónu štítku).
- Mezeru pod hláškou dává rodič (`.shpd-report` je flex sloupec — `gap`
  nebo obal), ne `margin` na `Notice`; dnešní `margin-bottom` štítku
  zmizí s třídou.
- Hláška nesmí ubrat tabulce schopnost rolovat (`flex: none` na obalu
  hlášky, tabulka dál `TableScroll fill`).

## 3. Řádek se zprávou (D64)

- Třídy `__tr--error` / `__tr--warning` zůstávají (řídí je
  `rowSeverity`), mění se vzhled: **bez pozadí**, text buněk řádku
  v barvě tónu — `--shpd-color-danger` / `--shpd-color-warning`, stejně
  jako ostatní chyby u prvku (`Input`, `QueueCategoriesPrompt`,
  `ImageSlotField`).
- Ikona tónu (`iconAlert` / `iconWarning`, `Icon size="sm"`) před názvem
  ve sloupci názvu, `aria-hidden` (význam nese seznam zpráv); řádek
  dostane `title` s textem první zprávy, která na něj ukazuje.
- Mezisoučet se zprávou: pozadí mezisoučtu (`bg-hover`) zůstává, tón jen
  textem a ikonou.
- **Kontrast varování:** změř `--shpd-color-warning` na `--shpd-color-bg`
  i na pozadí mezisoučtu, světlý i tmavý vzhled (velikost písma reportu je
  `sm`). Pod 4,5 : 1 nech text řádku v běžné barvě a tón nese jen ikona
  — zapiš do odchylek s naměřenými hodnotami; rozhodne se při ověření
  (tón textu pro varování pak patří k bodu hex barev v #133). `danger`
  měř taky.

## 4. Zprávy pod čarou (D64)

Seznam `.shpd-report__messages` zůstává (pozice, strop výšky, vlastní
rolování, ikony). Barvy textu: `error` → `--shpd-color-danger`,
`warning` → `--shpd-color-warning` (se stejným pravidlem kontrastu jako
v kroku 3 — pod 4,5 : 1 text běžnou barvou, tón jen ikona), `info` →
`text-secondary` beze změny. Žádný token `alert-*`.

## 5. Kontrola tokenů

`scripts/check-css-tokens.py`: `ReportView` z `ALERT_TEMPORARY_EXCEPTIONS`
smazat; prázdnou konstantu i s komentářem a její použití odstranit
(výjimka pro `frontend/src/dev/` v `ALERT_EXEMPT_DIRS` zůstává).
`grep -n -- "--shpd-color-alert-\|--shpd-color-state-error" frontend/src/components/reports/ReportView.svelte`
→ nic.

## 6. Dokumentace

- `docs/reports.md` §3.1 (věta o `level`: renderer z něj odvozuje
  odsazení jen u reportů bez účtů; u účtových nese hierarchii sloupec
  Účet — D63) a §3.3 (renderer: stav jako `Notice`, řádek se zprávou
  barvou textu a ikonou, zprávy pod čarou — D64; dnes „červené řádky“).
- `docs/design-system.md` §8 Hlášky a pruhy: věta o dočasné výjimce
  `ReportView` zmizí; mezi příklady chyby u prvku doplnit řádek reportu.
- Komentáře v `ReportView.svelte` a `check-css-tokens.py` podle nového
  stavu.
- Checklist #133: oba body se zaškrtnou při ověření (chat), ne v tasku.

## Commity

1. `refactor(frontend): číslo účtu v reportu ve vlastním sloupci (#133, D63)`
   — krok 1
2. `refactor(frontend): stav a zprávy reportu podle D61 (#133, D64)` —
   kroky 2–4
3. `chore: ReportView bez výjimky v kontrole tokenů hlášek (#133, D61)` —
   krok 5
4. `docs: tabulka reportu (#133, D63, D64)` — krok 6 + `**Stav:**` tohoto
   tasku + `python3 scripts/tasks-index.py`

## Ověření

- `python3 scripts/check-css-tokens.py`, `check-css-units.py`,
  `cd frontend && npm run build`, `npm run check:i18n`, `npm test`.
- Chromium (Playwright) nad zdrojem dat `free` s účetními daty, světlý
  i tmavý vzhled, 1280 a 390 px, snímky:
  - **Hlavní kniha** analyticky a synteticky (dvouřádková hlavička):
    sloupec Účet u účtů i mezisoučtů, název neodsazený, hlavička po
    odrolování drží oba řádky (D57 beze změny), drill-down z řádku
    funguje;
  - **Rozvaha** a **Výsledovka**: sloupec Účet, dopočtené řádky a součet
    s prázdnou buňkou Účet;
  - report **bez účtů** (majetek — odpisový plán nebo pohyby; DPH):
    žádný sloupec Účet, odsazení podle úrovně jako dřív;
  - report se **stavem `errors` a `warnings`** a zprávami vázanými na
    řádek: podvrhni odpověď API (`page.route`) s `status`, `messages`
    a `rowRef` na existující řádky — hláška nad tabulkou, řádky bez
    pozadí s ikonou a tónem textu, zprávy pod čarou; tabulka dál roluje
    v obalu.
- Export XLSX / CSV Hlavní knihy před a po: soubory shodné (kromě času
  vytvoření).
- Kontrast z kroku 3 zapsat do odchylek (hodnoty pro oba vzhledy).

## Hotovo když

- [x] Účtové reporty mají sloupec Účet (i mezisoučty), název bez
      odsazení; reporty bez účtů beze změny
- [x] Stav reportu je `Notice`; řádek se zprávou bez pozadí, s ikonou
      a tónem textu (nebo jen ikonou podle měření kontrastu)
- [x] Zprávy pod čarou bez tokenů `alert-*`
- [x] `ReportView` není v žádné výjimce kontroly tokenů; kontrola
      prochází
- [x] Export beze změny
- [x] Dokumentace podle kroku 6, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- `border-collapse: separate` a držící `thead` (D57) — nový sloupec musí
  mít `th` s pozadím jako ostatní, jinak při rolování prosvítá obsah.
- Pravidlo `.shpd-report__tr:has(+ .shpd-report__tr--total)` (čára nad
  součtem) cílí na `.shpd-report__td` — nová buňka Účet musí mít třídu
  `shpd-report__td`, jinak čára v jednom sloupci zůstane.
- Index řádku v `rowSeverity` (`rowRef` = `rows.<index>`) je index do
  `result.rows` — přidání sloupce na něm nic nemění, nefiltruj řádky
  před `#each`.
- `Notice` nemá vnější okraj; mezera mezi hláškou a tabulkou je věc
  rodiče.
- Kontrola tokenů jde celým stromem — commit 3 až po commitu 2.

## Odchylky od zadání

- **Kontrast (krok 3 a 4).** Kontrast barvy tónu vůči pozadí, naměřeno
  v Chromiu z vykreslených barev (písmo reportu `sm`), shodně na 1280
  i 390 px:

  | tón | vzhled | řádek na `bg` | mezisoučet (`bg-hover`) | zpráva pod čarou |
  |-----|--------|---------------|-------------------------|------------------|
  | `danger` | světlý | 4,83 | 4,54 | 4,54 |
  | `danger` | tmavý | 3,97 | 3,36 | 4,49 |
  | `warning` | světlý | 3,19 | 2,99 | 2,99 |
  | `warning` | tmavý | 6,96 | 5,89 | 7,86 |

  Zadání čekalo problém jen u varování; pod 4,5 : 1 je i chyba v tmavém
  vzhledu. Rozhodnuto při plánování: **chyba** nese tón ikonou i barvou
  textu (`--shpd-color-danger`) v obou vzhledech — stejně jako `Input`
  a ostatní chyby u prvku, tmavá hodnota tokenu patří k bodu hex barev
  v #133; **varování** má text běžnou barvou a tón jen v ikoně
  (`--shpd-color-warning`), v obou vzhledech, v řádku i ve zprávě pod
  čarou. Ikona varování má ve světlém vzhledu 3,19 : 1 (na mezisoučtu
  2,99) — hranice 3 : 1 pro grafiku, taky k bodu hex barev.
- **Přiznání k DPH není report bez účtů.** Živé přiznání posílá v poli
  `account` číslo řádku přiznání (1, 5, 40, …), takže dostane sloupec
  s hlavičkou „Účet“ — stejně jako dnes export. Rozvržení je správné
  (číslo ve vlastním sloupci, název neodsazený), hlavička ne. Oprava je
  mimo rozsah (backend: popisek sloupce podle reportu, pro UI i export).
  Reporty bez účtů ověřené místo DPH: Sestava odpisů a Přírůstky a úbytky
  (majetek).
- **Smíšený report.** Kontrola evidence × deník (majetek) má účty jen
  u části řádků; podle D63 se neodsazuje žádný název, i řádky karet pod
  nadpisem „Nesoulady po kartách“. Čitelné (nadpisy skupin jsou tučné),
  ale odsazení karet zmizelo.
- **Šířka sloupce Účet.** „Drží se obsahu“ potřebovalo `width: 1%`
  s `nowrap` — tabulka má `width: 100%` a report s pár sloupci (Výsledovka)
  jinak sloupci Účet přidělil volnou šířku (115 px místo 64).
- **Ikona a dlouhý název s odkazem.** Název s drill-down je tlačítko
  (inline-block); dlouhý název spadl pod ikonu. Ikona a název jsou proto
  flex řádek zarovnaný nahoru (`.shpd-report__marked`). Samostatný commit
  `fix(frontend)` — pátý proti čtyřem v zadání.
- **Odkaz v řádku s chybou** zůstává v barvě odkazu; tón nese ikona
  a ostatní buňky řádku.
- **Drill-down z Hlavní knihy** zadání chce ověřit, ale Hlavní kniha
  odkazy v řádcích nemá (`link` posílají jen reporty majetku, §16.5).
  Ověřeno na Sestavě odpisů: klik na název otevře náhled karty.
- **`docs/reports.md` §12** (Frontend) popisoval „červené řádky“ a „štítek
  stavu“ — upraveno spolu s §3.1 a §3.3, i když ho krok 6 nejmenuje.

## Ověření v Chromiu (2026-11-11)

Playwright nad volným zdrojem dat `zey0-g…` (demo IT servis), 11 scénářů
× světlý / tmavý × 1280 / 390 px = 44 snímků, bez chyb stránky a bez
vodorovného rolování stránky:

- Hlavní kniha analyticky a synteticky, Výsledovka, Rozvaha: sloupec Účet
  i u mezisoučtů, název začíná hned za číslem (64 px, synteticky 48 px),
  číslo se neláme ani na 390 px; prázdná buňka Účet u součtů
  a dopočtených řádků; čára nad součtem ve všech sloupcích; po odrolování
  drží oba řádky hlavičky.
- Sestava odpisů, Přírůstky a úbytky: bez sloupce Účet, odsazení
  8 / 24 / 40 px jako dřív.
- Podvržená odpověď API (`status`, `messages`, `rowRef` na detail
  i mezisoučet, 30 zpráv): `Notice` `danger` (role `alert`) / `warning`
  (role `status`) 8 px nad tabulkou, řádky bez pozadí (mezisoučet si drží
  své), ikona s `aria-hidden`, `title` = první zpráva řádku, zprávy pod
  čarou se stropem výšky a vlastním rolováním, tabulka dál roluje v obalu.
- Skutečné varování: Kontrola evidence × deník.
- Export Hlavní knihy před a po: CSV bajtově shodné, XLSX se liší jen
  časem vytvoření.

V Chromiu neověřeno: Firefox, telefon, jiné rozložení než sidebar,
angličtina — první tři pokrylo ruční ověření níže.

## Ruční ověření (2026-11-11)

Firefox na desktopu a telefon, čeština; rozložení sidebar, classic i wild
— vše v pořádku:

- Hlavní kniha analyticky i synteticky, Rozvaha, Výsledovka: sloupec Účet
  i u mezisoučtů, název neodsazený, prázdná buňka Účet u dopočtených
  řádků a součtů, hlavička po odrolování drží.
- Sestava odpisů: bez sloupce Účet, odsazení jako dřív.
- Kontrola evidence × deník: hláška „Varování“ nad tabulkou, řádek
  s ikonou bez pozadí, zpráva pod čarou.
- Přiznání k DPH — živě: hlavička „Účet“ nad čísly řádků je (odchylka
  výše, oprava mimo rozsah).
- Tmavý vzhled v pořádku; na telefonu se číslo účtu neláme a tabulka
  roluje do strany ve svém obalu.

Angličtina ověřena dodatečně (hlavička „Account“ v Hlavní knize). Ručně neověřeno: stav `errors` (na zdroji není report
s chybou — pokrylo jen Chromium s podvrženou odpovědí API).
