# Majetek Fáze 5 — přehledy a kontroly

**Stav:** naplánováno — D65–D72 potvrzena 2026-10-01; prerekvizita `tasks/reports-export.md`

> PRD pro jednu Claude Code session (7 commitů). Design: `docs/assets.md`
> §1 (invarianty), §4 D65–D72, §5; `docs/reports.md`; issue #83.

## Kontext

Majetek po fázi 4 eviduje, odepisuje, účtuje a nese vazbu na doklady,
ale chybí přehledy, se kterými pracuje účetní: sestava odpisů, přírůstky
a úbytky, podklad daňových odpisů pro přiznání, soupis majetku a hlavně
**kontrola, že evidence souhlasí s deníkem**. Reporty vzniknou v doméně
reportů (`docs/reports.md`) — tím dostanou UI s výběrem období,
deep-link, REST, MCP (`report_list` / `report_run`), CLI `report-run`,
`report-diff` a export XLSX / CSV (`tasks/reports-export.md`) bez další
práce. Tisk karty a sestav počká na tiskovou doménu (D68).

## Cíl

1. Hromadné načítání pro plán mnoha karet (D71).
2. Pět reportů (D66) v sekci Majetek (D65).
3. Kontrola evidence × deník jako report a dva alerty (D67), varování
   na kartě.
4. Drill-down z reportů (D70).
5. Dokumentace (vč. odchylky od D3 reportů).

## Před implementací přečti

- `docs/reports.md` celé — hlavně §3 (`ReportResult`, stav a zprávy),
  §4 (deklarace), §5 (období, `FiscalRange`), §6 (zdroj dat = deník —
  tady vědomá odchylka, D65), §10–§14 (upřesnění, text/date sloupce)
- `modules/economy/accounting/config/reports.jsonc` + builder
  `modules/economy/accounting/src/Reports/GeneralLedgerBuilder.php`
  (vzor builderu, `SubtotalAggregator`)
- `modules/economy/vat/config/reports.jsonc` (text/date sloupce, detailní
  řádky dokladů)
- `docs/assets.md` §1, §4 (D13, D38, D49, D63, D65–D72), §5
- `modules/economy/assets/src/AssetPlanService.php`,
  `AssetAcquisitionService.php`, `Depreciation/*`
- `docs/alerts.md`, checky z fáze 4 (`economy.assets.*`)
- `modules/world/assets/config/assets-cz.jsonc` (`taxReturnGroup`)

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** tisk karty a reportů, PDF (tisková doména, D68); odložená daň
(fáze 8, D69); osoba a místo v soupisu (fáze 7); filing DPPO (podání);
import (fáze 6).

## 1. Hromadné načítání (D71)

`AssetPlanService::plansFor(list<int> $assetIds, ?string $asOf)`:
karty, potvrzené události a účetní roky / měsíce jedním dotazem na typ
(ne per karta), pravidla země jednou; výsledek `array<int, {card, tax:
Plan, acc: Plan}>`. Test výkonu (fake provider): 1 000 karet s ~20
událostmi do několika sekund; v testu jen hrubá horní mez, aby nebyl
křehký.

## 2. Reporty (D65, D66)

`modules/economy/assets/config/reports.jsonc` + klíč `reports`
v `module.jsonc`; `navSection: "assets"`, `navOrder` 50+; buildery
v `modules/economy/assets/src/Reports/`. Období `fiscal` — rok (u
přírůstků a úbytků i měsíc / čtvrtletí / pololetí). Stav k datu =
poslední den zvoleného období (parametr typu datum v jádru reportů
nevzniká). Seskupení přes enum parametr `groupBy`. Mezisoučty
a celkem přes `SubtotalAggregator` (money sloupce, text/date ignoruje).

### 2.1 `economy.assets.depreciationSchedule` — Sestava odpisů

- Období: rok. Parametry: `groupBy` (`accountingGroup` default,
  `taxRule`, `type`, `none`), `category` (`all` default, `longTerm`,
  `nondepreciable`).
- Řádek per dlouhodobá karta v evidenci v roce (zařazená do konce roku,
  nevyřazená před začátkem roku): inv. č. (text), název (label),
  skupina / metoda (text), vstupní cena, daňové oprávky na začátku,
  daňový odpis roku (uplatněný), daňové oprávky na konci, daňová ZC;
  totéž účetně; rozdíl účetní − daňový odpis roku.
- Hodnoty z plánů (potvrzené + plán do konce roku, plánované řádky
  odlišit zprávou `info` u karty, je-li rok ještě neodepsaný).

### 2.2 `economy.assets.movements` — Přírůstky a úbytky

- Období: měsíc / čtvrtletí / pololetí / rok. Parametr `kind` (`all`,
  `additions`, `disposals`).
- Detailní řádky událostí v období: datum (date), inv. č., název, druh
  události (text), částka; u vyřazení i oprávky a ZC k vyřazení. Navíc
  drobný majetek: karty s datem pořízení / vyřazení v období a cenou.
  Mezisoučty po druhu události.

### 2.3 `economy.assets.taxDepreciationReturn` — Daňové odpisy pro DPPO

- Období: rok. Řádky po `taxReturnGroup` (1–6, nehmotný do 2020, metoda
  `accounting`) s počtem karet a součtem **uplatněných** daňových odpisů
  (bez `claim_unrecorded`), celkem; pod tím účetní odpisy celkem
  a rozdíl účetní − daňový (podklad pro úpravu základu daně).
- Zpráva `warning`, je-li v roce karta s neodepsaným (jen plánovaným)
  daňovým odpisem — čísla nejsou finální.

### 2.4 `economy.assets.journalCheck` — Kontrola evidence × deník

- Období: rok. Dvě části (oddělené řádkem `subtotal` s popiskem):
  1. **Po účtech** účetních skupin (účty majetku, pořízení, oprávek,
     odpisů, ZC): zůstatek / obrat podle evidence (zaúčtované události)
     vs. deník s dimenzí `asset`, vs. deník **bez** dimenze na týchž
     účtech, rozdíl.
  2. **Po kartách** jen nesoulady: (a) zaúčtované události ≠ řádky
     deníku s dimenzí karty (MD / DAL po účtech); (b) **pořízení ≠
     zařazení + TZ** — zůstatek účtu pořízení (04x) s dimenzí karty ≠ 0
     u karty se zařazením / počátečním stavem, nebo pořízení bez
     zařazení starší než 30 dní (nález fáze 4); (c) potvrzené
     nezaúčtované události starší než konec předchozího období.
- Každý nesoulad = zpráva `error` (a, b) nebo `warning` (c, deník bez
  dimenze) s `rowRef`; `status` reportu tím říká, jestli kontrola
  prošla.
- Logika ve službě `AssetJournalCheck` (sdílí report i alerty).

### 2.5 `economy.assets.register` — Soupis majetku

- Období: rok / měsíc (stav ke konci). Parametry `groupBy` (`type`
  default, `category`, `accountingGroup`), `foreign` (`include` default,
  `exclude`, `only`).
- Karty v evidenci k datu (pořízené do data, nevyřazené k datu), vč.
  drobného a cizího: inv. č., název, typ, druh, datum pořízení, vstupní
  cena (dlouhodobý z událostí, drobný z karty), účetní ZC k datu,
  vlastník u cizího.

## 3. Alerty a karta (D67)

- `economy.assets.journal_mismatch` — `AssetJournalCheck` pro aktuální
  a předchozí účetní rok, nesoulady (a); severity `error`, `interval
  1d`, akce otevře report Kontrola s rokem.
- `economy.assets.acquisition_mismatch` — nesoulad (b) starší než
  **30 dní** (konstanta / nastavení, výchozí 30); severity `warning`;
  akce otevře kartu.
- Přehled karty: varovný řádek, má-li karta nesoulad (a) nebo (b)
  (stejná služba, jen pro jednu kartu).

## 4. Drill-down (D70)

- Řádek karty → `open_viewer` / `open_detail` na kartu majetku.
- Řádek zaúčtované události → doklad (`open_detail`).
- Řádek účtu v kontrole → deník s filtrem účtu, období a dimenze.
- Pokud jádro reportů drill-down z řádku ještě neumí, doplnit obecně
  (pole `link` na `ReportRow`: `{kind, target}` stejného tvaru jako
  akce detailu vieweru) a vykreslit v `ReportView`; `docs/reports.md`.

## 5. Dokumentace

- `docs/reports.md` — reporty mimo deník (D65: evidence jako zdroj,
  deník jen kontrolní), drill-down (pokud vznikl).
- `docs/assets.md` §5.6 (reporty, kontrola), §7 oblast 5 → hotovo.
- `help/majetek/prehledy-majetku.md` (nová) — pět přehledů, kdy který,
  kontrola a co dělat při nesouladu (typické příčiny: ruční zápis na
  02x/08x bez karty, doplatek pořízení po zařazení, nezaúčtované
  období), export do Excelu; `help/co-dnes-nejde.md` — tisk karty
  a sestav až s tiskem; `python3 scripts/help-index.py`.

## Testy

- `AssetPlanService::plansFor` — shoda s plánem per karta, počet dotazů.
- Buildery všech pěti reportů na fake datech (in-memory provider /
  subclassing): řádky, mezisoučty, `groupBy`, okrajové karty (zařazení
  v posledním dni roku, vyřazení v prvním dni, počáteční stav, drobný,
  cizí).
- `AssetJournalCheckTest` — každý typ nesouladu (a), (b), (c), deník
  bez dimenze, čistý stav = žádné zprávy.
- alert checky.
- `vendor/bin/phpunit --filter 'Assets|Report'`; frontend build, pokud
  vznikl drill-down.

## Task breakdown

1. **Hromadné načítání** — `plansFor` + testy.
2. **Sestava odpisů a Daňové odpisy pro DPPO** — deklarace, buildery,
   testy.
3. **Přírůstky a úbytky, Soupis** — buildery, testy.
4. **Kontrola evidence × deník** — `AssetJournalCheck`, builder, testy.
5. **Alerty a varování na kartě** — dva checky, Přehled karty + testy.
6. **Drill-down** — `ReportRow.link` (je-li potřeba), odkazy
   v builderech, `ReportView`.
7. **Dokumentace** — help, `docs/assets.md`, `docs/reports.md`, Stav
   tasku + `python3 scripts/tasks-index.py`.

*Hotovo celé když* na ukázkovém DS (`4l3j-z0bz-kz39-echj`) všech pět
reportů jde v sekci Majetek otevřít, vyexportovat do XLSX a spustit
přes `report-run`; kontrola ukáže nesoulad karty „Smoke 4 fréza“
(pořízení 04x ≠ zařazení) a po opravě (TZ nebo oprava zařazení
a zaúčtování) projde bez chyb; alert nesouladu pořízení se objeví
a zmizí; součet daňových odpisů pro DPPO sedí se Sestavou odpisů.

## Rozhodnutí k designu (potvrzená)

- ✓ D65 Reporty majetku v doméně reportů, zdroj evidence, deník kontrolní.
- ✓ D66 Pět reportů (sestava odpisů, přírůstky a úbytky, daňové odpisy
  pro DPPO, kontrola evidence × deník, soupis).
- ✓ D67 Alerty nesouladu deníku a pořízení (práh 30 dní), varování na kartě.
- ✓ D68 Tisk karty a reportů až s tiskovou doménou.
- ✓ D69 Odložená daň ve fázi 8.
- ✓ D70 Drill-down na kartu, doklad a deník.
- ✓ D71 Hromadné načítání plánů.
- ✓ D72 Export přes `tasks/reports-export.md` (obecně pro všechny reporty).
