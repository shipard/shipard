# Design system — popisky nad pole na úzké šířce, strop sloupce popisků (D117–D119)

**Stav:** naplánováno — #157, D117–D119

## Kontext / Cíl

Sloupec editačního formuláře (`components/form/FormColumn.svelte`) je
mřížka `grid-template-columns: max-content 1fr` a popisek má
`white-space: nowrap` (`FormFieldRow.svelte`). Sloupec popisků je tedy
široký jako nejdelší popisek ve sloupci a pole dostane zbytek. Na úzké
šířce se pod sebe skládají jen sloupce sekce (`FormSection`,
`@container form-section (max-width: 30rem)`, #133 D43), popisky a pole
nikdy. Na 360 px má sloupec odhadem ~280 px; popisek o 20 znacích
(~145 px) nechá poli ~120 px. Z 1 289 popisků sloupců v definicích
tabulek má 125 aspoň 20 znaků, 27 aspoň 25 znaků.

Dlouhý popisek zužuje pole i na desktopu v sekci se dvěma sloupci vedle
sebe (~540 px na sloupec).

Skupina polí vedle sebe (`FormInline`, jen formuláře Osoby, adresy
a účtu osoby) se dnes rozpadá pod sebe podle **okna**
(`layoutStore.isMobile`), ne podle šířky sekce — v úzkém modalu nebo
panelu na desktopu zůstane vedle sebe.

Pravidlo „popisky nad pole“ pod `30rem` už uvádí `docs/design-system.md`
§8 Responzivita a implementuje ho stránka Nastavení (`SettingsPage.svelte`)
a změna hesla (`ChangePasswordPanel.svelte`) vlastními mřížkami, editační
formuláře ne.

**Cíl:** pod `30rem` šířky sekce popisek nad polem a pole přes celou
šířku sloupce; na širší šířce sloupec popisků nejvýš 40 % s popiskem
zarovnaným nahoru k poli; skupina `inline` se přepíná podle šířky sekce;
stejná pravidla v ostatních mřížkách popisek–pole.

**Mimo rozsah:**

- zkracování dlouhých popisků v definicích tabulek a katalozích textů;
- mřížky jen ke čtení (detail záznamu — `PropertyList`, `PropertyGroups`,
  `FailureCard`, souhrn v hlavičce `Modal`u);
- patička náhledu dokladu a zavírací tlačítka na telefonu — #155;
- názvy tabů a sekcí PHP formulářů — #154.

## Návaznost

- **#157** — D117–D119 (komentář s auditem a rozhodnutími)
- **#133** — D42 (breakpoint shellu), D43 (`@container form-section`,
  práh `30rem`)
- **#156** — D106, D110 (`--shpd-control-height`, písmo ovládacích prvků)

## Před implementací přečti

- `docs/design-system.md` — §8 Responzivita (prahy, jak převést obsah na
  kontejner, pojmenovaný kontejner `form-section`), Výška ovládacího
  prvku
- `docs/edit-forms.md` — sekce o `FormColumn` (mřížka popisků), inline
  skupina na mobilu, tabulka komponent `form/`
- `frontend/src/components/form/FormColumn.svelte`, `FormFieldRow.svelte`,
  `FormInline.svelte`, `FormElement.svelte`, `FormSection.svelte`
- `frontend/src/components/settings/SettingsPage.svelte` (vzor popisku
  nahoře s odsazením na střed pole a popisků nad pole pod `30rem`)
- `frontend/src/components/account/ChangePasswordPanel.svelte`
- `scripts/check-css-units.py` (povolené prahy `@container`)

## Rozhodnutí (potvrzená v #157)

- ✓ **D117** Pod prahem `30rem` kontejneru `form-section` (stejně jako
  skládání sloupců sekce) jsou popisky nad pole: sloupec o jedné stopě,
  popisek vlevo nad polem, smí se zalomit; mezera popisek–pole
  `space-xs`, mezi dvojicemi `space-md`. Stejné hodnoty stránka
  Nastavení, změna hesla a další mřížky popisek–pole.
- ✓ **D118** Na širší šířce sloupec popisků nejvýš **40 %** šířky sloupce
  sekce (`fit-content(40%)`); delší popisek se zalomí, zůstává zarovnaný
  doprava. Popisek a pole zarovnané **nahoru** (`align-items: start`,
  ne účaří): první řádek popisku na svislém středu pole (horní odsazení
  `(výška ovládacího prvku − 1lh) / 2`, vzor stránky Nastavení), další
  řádky pod ním; pole se k popisku nenatahuje (výška z tokenu),
  víceřádkové pole má první řádek popisku u svého prvního řádku. Totéž
  zarovnání pro nezalomené popisky a stránku Nastavení (jeden způsob).
- ✓ **D119** Skupina `inline` se pod prahem D117 rozpadne na samostatná
  pole pod sebou, každé s plným popiskem nad polem (jako dnes na
  telefonu), ale podle šířky sekce, ne okna — přepne se spolu s popisky.
  Malé popisky vedle polí jen na široké šířce.

## 1. Sloupec formuláře (D117, D118)

`FormColumn.svelte` a `FormFieldRow.svelte`:

- mřížka `fit-content(40%) minmax(0, 1fr)` místo `max-content 1fr`;
  `align-items: start` místo `baseline`;
- popisek: bez `nowrap`, zarovnaný doprava, horní odsazení
  `calc((var(--shpd-control-height) - 1lh) / 2)` — stejný výraz jako
  `SettingsPage` (výška se na telefonu mění tokenem, výraz platí sám);
  řádkování popisku tak, aby dva řádky nebyly roztahané (ověř snímkem);
- `@container form-section (max-width: 30rem)`: mřížka o jedné stopě,
  popisek vlevo, bez horního odsazení; mezera popisek–pole `space-xs`,
  mezi dvojicemi `space-md` (popisek a pole jsou sourozenci v mřížce —
  `row-gap` `space-xs` a horní okraj popisku, nebo jiné řešení se stejným
  výsledkem; první popisek ve sloupci bez okraje);
- sloupec složený jen z komponent (`--fill`) beze změny;
- prvky přes obě stopy (oddělovač, `content`, komponenta, zaškrtávátko,
  sub-tabulka) beze změny — ověř, že v jedné stopě nic nepřetéká;
- hvězdička povinného pole zůstává na konci popisku i při zalomení;
  chyba a nápověda pod polem beze změny.

**Pozor na pole, jejichž první řádek není ve výšce ovládacího prvku**
(víceřádkové pole, výběr více hodnot s čipy, lookup s druhým řádkem,
datum ve Firefoxu): první řádek popisku má sedět u prvního řádku pole.
Kde výraz nesedí, oprav to v komponentě pole (stejné horní odsazení
prvního řádku jako jednořádkové pole), ne výjimkou v popisku; co nejde,
zapiš do Odchylek.

## 2. Skupina `inline` (D119)

`FormInline.svelte`:

- větvení podle `layoutStore.isMobile` nahradit přepnutím podle
  kontejneru `form-section` (`@container form-section (max-width:
  30rem)`): na široké šířce vnější popisek + skupina vedle sebe s malými
  popisky (dnešní desktop), pod prahem každé pole s plným popiskem nad
  polem;
- obě podoby vykreslí jedno DOM, přepíná CSS (žádný stav z JS) —
  například plné popisky dalších polí skryté na široké šířce a malé
  popisky skryté pod prahem; `id` a `for` popisků zůstávají platné
  v obou podobách (žádné duplicitní `id`);
- na široké šířce zarovnání podle D118 (vnější popisek nahoru, malé
  popisky na střed pole).

## 3. Ostatní mřížky popisek–pole (D117, D118)

- `settings/SettingsPage.svelte` — strop `fit-content(40%)`, zalomení
  popisku; pod `30rem` mezery podle D117 (dnes popisek těsně nad polem,
  mezi poli `md`).
- `account/ChangePasswordPanel.svelte` — stejná pravidla.
- Audit dalších mřížek `max-content` s popiskem a polem (grep
  `--include=*.svelte` po složkách `components/*`): `viewer/SendDialog.svelte`,
  `settings/VatRegistrationPrefillDialog.svelte`,
  `registry/RegistryImportWizard.svelte` a další nalezené. Mřížka
  popisek–ovládací prvek → pravidla D117 a D118; mřížka jen ke čtení
  nebo jiný účel → Odchylky s důvodem.

## 4. Dokumentace

- `docs/design-system.md`:
  - §8 nový odstavec **Popisky a pole** (u Výšky ovládacího prvku nebo
    v Responzivitě): strop 40 % a zalomení (D118), zarovnání nahoru
    s odsazením na střed pole, popisky nad pole pod `30rem` a mezery
    (D117), skupina `inline` (D119), platí pro formuláře, Nastavení,
    změnu hesla a dialogy z auditu;
  - tabulka prahů (řádek `30rem`) — odkaz na odstavec;
  - §6 Anti-patterny: mřížka popisků `max-content` bez stropu; popisek
    `nowrap` ve formuláři; rozpad skupiny polí podle okna.
- `docs/edit-forms.md` — popis mřížky `FormColumn` (dnes `max-content
  1fr`, synchronizace šířky popisků), inline skupina na mobilu (přepíná
  šířka sekce, ne okno), tabulka komponent `form/`.
- `tasks/README.md` — řádek tasku do oblasti design systému.

## Commity

1. `fix(frontend): sloupec formuláře — strop popisků, zarovnání nahoru, popisky nad pole (#157, D117, D118)` — krok 1
2. `fix(frontend): skupina polí podle šířky sekce (#157, D119)` — krok 2
3. `fix(frontend): Nastavení, změna hesla a dialogy podle pravidel popisků (#157, D117, D118)` — krok 3
4. `docs: popisky a pole na úzké a široké šířce (#157, D117–D119)` — krok 4 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `check-css-tokens.py`, `check-css-units.py`, `check-contrast.py`;
  `cd frontend && npm run build`, `npm test`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis, světlý
  vzhled; tmavý jen snímkem), šířky **360 px, 390 px a 1280 px**
  a **úzký modal na desktopu** (vnořený formulář přes „+ Vytvořit nový“
  v lookupu, případně okno ~700 px). U každého formuláře šířka sloupce
  popisků a polí (`getBoundingClientRect`) před / po a poloha prvního
  řádku popisku vůči středu pole (rozdíl ≤ 1 px):
  - nastavení AI funkce, řada dokladů, schránka pošty, karta majetku,
    účetní skupina majetku;
  - Osoba, adresa a účet osoby (skupiny `inline` — obě podoby, přepnutí
    na prahu sekce);
  - faktura přijatá a vydaná (sekce se dvěma sloupci, tab Nastavení),
    registrace DPH;
  - stránka Nastavení (Aplikace), změna hesla, dialogy z auditu.
- Na 1280 px v sekci o jednom sloupci se šířka sloupce popisků nemění;
  v sekci se dvěma sloupci je nejvýš 40 %.
- Na 360 px: pole přes celou šířku sloupce, popisek nad ním, nic
  nepřetéká; hvězdička, chyba (uložení s prázdným povinným polem)
  a nápověda na svém místě.
- Snímky před / po: nastavení AI funkce (360 px, 1280 px), Osoba
  (360 px), faktura s dvousloupcovou sekcí (1280 px).

## Hotovo když

- na 360 px (a v úzkém modalu / panelu) mají pole ve všech editačních
  formulářích celou šířku sloupce s popiskem nad sebou; skupiny `inline`
  se rozpadají podle šířky sekce;
- na široké šířce sloupec popisků nejvýš 40 % sloupce sekce, delší
  popisek zalomený, první řádek na středu pole, pole se nenatahuje;
  sekce o jednom sloupci v modalu formuláře beze změny;
- Nastavení, změna hesla a dialogy z auditu podle stejných pravidel,
  výjimky v Odchylkách;
- `docs/design-system.md` a `docs/edit-forms.md` odpovídají
  implementaci.

## Odchylky

_(doplní implementace)_
