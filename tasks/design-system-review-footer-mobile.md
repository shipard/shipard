# Design system — patička náhledu dokladu na telefonu, sdílený kebab, zavírací tlačítko v modalu (D113–D116)

**Stav:** hotovo — #155, D113–D116 (2026-11-18); ručně ověřeno 2026-11-18 (viz Odchylky → Ruční ověření)

## Kontext / Cíl

Náhled dokladu z pošty (`exchange/DocumentExchangePreviewModal.svelte`)
má v patičce až pět tlačítek: **Zavřít**, **Přeskočit** (jen ve frontě),
**Zamítnout**, **Vystavit koncept**, **Vystavit a uzavřít** (u dokumentu
Spisovny místo posledních dvou **Zařadit**). Na telefonu je modal přes
celou obrazovku a patička dává každému tlačítku stejný díl šířky
(`Modal.svelte`, `@media` shellu: `.shpd-modal__footer > * { flex: 1 }`);
popisek tlačítka se nezalamuje, takže tlačítka přetékají. Po #156 D110
(písmo 16 px, odsazení `space-lg`) se na 390 px nevejdou dvě tlačítka ze
čtyř (`tasks/design-system-controls-fixes.md` → Odchylky). Hrubý odhad:
místo ~342 px, Vystavit koncept ~170 px, Vystavit a uzavřít ~185 px —
vedle sebe se nevejdou ani obě hlavní akce.

Kebab (⋮ → bublina s akcemi) je dnes v aplikaci dvakrát s vlastním kódem:
stavová lišta formuláře (`form/FormStateBar.svelte`) a horní lišta na
telefonu (`layout/MobileTopBar.svelte`).

Tlačítka, která jen zavírají dialog (Zavřít / Zrušit — stejný účinek
jako × v hlavičce), jsou v patičkách asi patnácti dialogů; na telefonu
zabírají místo hlavním akcím. Pravidlo pro ně zatím není.

Drobnost z ověření: tlačítka **Vytvořit novou položku / osobu**
a **Vytvořit z registru: …** v panelu rozhodnutí
(`exchange/ResolveDecisionPanel.svelte`, `.shpd-resolve__create`) mají
`display: flex; align-items: baseline` bez svislého centrování — popisek
sedí nahoře, na telefonu (výška 40 px) zhruba o 3 px. Tlačítka Vynechat
řádek a Hledat v registru… (bez flexu) jsou vycentrovaná.

**Cíl:** na 360 i 390 px je v patičce náhledu vidět hlavní akce a kebab,
nic nepřetéká; kebab je jedna komponenta; zavírací tlačítka patiček na
telefonu skrývá `Modal` podle jednoho pravidla; popisek tlačítek panelu
rozhodnutí svisle uprostřed.

**Mimo rozsah:**

- rozložení formulářů na úzké šířce (popisky nad pole) — #157;
- akce v hlavičce detailu dokladu na telefonu (zalamují se, nic se
  neořezává — `tasks/design-system-controls-fixes.md`);
- `DevKebabMenu` vývojářského dashboardu (dashboard sdílí jen vybrané
  komponenty, `CLAUDE.md` → Vývojářský dashboard);
- desktop: patička náhledu i ostatních dialogů beze změny.

## Návaznost

- **#155** — D113–D116 (komentář s rozhodnutími)
- **#156** — D106–D110 (výška a písmo ovládacích prvků),
  `tasks/design-system-controls-fixes.md` → Odchylky (měření přetečení)
- **#133** — D42 (breakpoint shellu 48rem)

## Před implementací přečti

- `docs/design-system.md` — Modální dialogy (konvence, mobil),
  Responzivita (D42, media query jen pro shell), §5 Tlačítka, odstavec
  Výška ovládacího prvku
- `frontend/src/components/ui/Modal.svelte` (patička, `@media` shellu)
- `frontend/src/components/ui/Button.svelte`, `ui/Popover.svelte`,
  `ui/ConfirmDialog.svelte`
- `frontend/src/components/form/FormStateBar.svelte` (kebab, `inKebab`,
  `kebabItems`), `layout/MobileTopBar.svelte` (kebab)
- `frontend/src/stores/layout.svelte.js` (`isMobile`, `MOBILE_BREAKPOINT`)
- `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  (patička, `data-testid`), `exchange/ResolveDecisionPanel.svelte`
- `tests/e2e/dataset-walkthrough.spec.mjs` (čte `review-*` testid)
- `help/posta/kontrola-vytezeni.md` (tlačítka náhledu, odstavec o telefonu)

## Rozhodnutí (potvrzená v #155)

- ✓ **D113** Patička náhledu dokladu na telefonu: jediná viditelná akce
  **Vystavit a uzavřít** (u Spisovny **Zařadit**) a kebab ⋮; v kebabu
  **Vystavit koncept**, **Zamítnout** (destruktivní, v menu červeně)
  a **Přeskočit** (jen ve frontě). **Zavřít** na telefonu není (D116).
  Dva řádky tlačítek ne. Pořadí ⋮ a hlavní akce jako ve stavové liště
  formuláře. Desktop beze změny.
- ✓ **D114** Kebab (tlačítko ⋮, bublina, položky) jako sdílená komponenta
  v `ui/`; používá ji stavová lišta formuláře, patička náhledu a horní
  lišta na telefonu.
- ✓ **D115** O telefonu rozhoduje `layoutStore.isMobile` (breakpoint
  shellu, stejný jako roztažená patička `Modal`u). Bez container query.
- ✓ **D116** Na telefonu patička modalu nenese tlačítko, které jen zavírá
  (Zavřít / Zrušit — stejný účinek jako ×); zavírá × v hlavičce.
  Tlačítko se označí jako zavírací a skryje ho `Modal` (pravidlo na
  jednom místě); zbude-li v patičce jen zavírací tlačítko, patička na
  telefonu zmizí. **Výjimka:** potvrzovací dialog (`ConfirmDialog`, dotaz
  na důvod zamítnutí) — Zrušit je tam odpověď na otázku.

## 1. Sdílený kebab (D114)

Nová komponenta `frontend/src/components/ui/KebabMenu.svelte` (název
uprav podle konvence `ui/`, ať je jeden):

- props: `items` — `{key, label, danger?, disabled?, testid?, run}`,
  `disabled` (celé tlačítko), `placement` (`top` / `bottom`, výchozí
  `top`), `ariaLabel` (výchozí `t('form.moreActions')`), `testid`
  tlačítka ⋮;
- tlačítko ⋮ = čtverec běžné výšky ovládacího prvku s rámečkem jako
  dnes ve `FormStateBar` (`--shpd-control-height`, `border-control`);
  nesmí se roztáhnout v patičce `Modal`u na telefonu (`flex: 1` patičky —
  kebab má `flex: none`, ověř, že pravidlo `Modal`u nepřebije);
- položka menu nese `data-testid={item.testid}`; klik zavře bublinu
  a pak zavolá `run()` (pozor na asynchronní akci — `docs/design-system.md`
  past s `closeMenu()`); `danger` = text v tónu `danger-text`; neaktivní
  položka se vykreslí neaktivní (ne skrytá) — rozhodne volající;
- styl položek převezmi z `FormStateBar` (`__kebab-menu`, `__kebab-item`).

Převést na komponentu:

- `form/FormStateBar.svelte` — tlačítko, bublina, položky; logika
  `inKebab` / `kebabItems` zůstává ve `FormStateBar`;
- `layout/MobileTopBar.svelte` — kebab detailu (placement `bottom`).

Chování obou míst beze změny; staré třídy a styly kebabu z obou
komponent odstraň.

## 2. Zavírací tlačítko v patičce modalu (D116)

- `Button` dostane prop (např. `dismiss`), který vykreslí značku
  (`data-modal-dismiss`); žádná vlastní logika kliku.
- `Modal.svelte` v `@media` shellu skryje tlačítka se značkou v patičce
  a skryje celou patičku, když v ní nic jiného není (např.
  `.shpd-modal__footer:not(:has(> :not([data-modal-dismiss])))`; ověř
  ve Firefoxu i Chromiu). Mimo telefon beze změny.
- Média query zůstává v `Modal.svelte` (shell, D42) — žádné `isMobile`
  v jednotlivých dialozích kvůli tomuhle pravidlu.

**Audit:** všechny patičky `Modal` (`grep -rn --include=*.svelte
"#snippet footer"` po složkách `frontend/src/components/*`). Označit
každé tlačítko, které **jen zavírá** — volá totéž co `onClose` modalu
(× i Esc), včetně stráže neuložených změn. Známá místa (ověř, seznam
není úplný): náhled tisku, odeslání e-mailem (Zrušit i Zavřít po
odeslání), nahrání pošty, reanalýza ve vieweru, nastavení hesla, běh
odpisů, průvodce importem z registru, dva dialogy v Nastavení
(předvyplnění registrace DPH, propojení bankovního účtu), nový zdroj dat
v portálu, náhled dokladu (Zavřít).

**Neoznačovat:**

- `ConfirmDialog` a dotaz na důvod zamítnutí (`RejectReasonPrompt`) —
  výjimka D116;
- tlačítko, které dělá víc než zavření (zruší běžící operaci, vrátí krok
  průvodce, zahodí rozpracovaná data jinak než ×) — zapiš do Odchylek
  s důvodem;
- jiné potvrzovací dotazy (přejmenování / smazání v seznamu konverzací
  chatu apod.) — posuď podle výjimky a zapiš do Odchylek.

## 3. Patička náhledu dokladu (D113, D115)

`exchange/DocumentExchangePreviewModal.svelte`:

- desktop (`!layoutStore.isMobile`): beze změny;
- telefon: viditelná jen hlavní akce (**Vystavit a uzavřít**, u Spisovny
  **Zařadit**) a kebab; v kebabu Vystavit koncept, Zamítnout (`danger`),
  Přeskočit (jen ve frontě) — `disabled` jako u tlačítek; Zavřít
  označené jako zavírací (D116), na telefonu ho skryje `Modal`;
- pořadí hlavní akce a ⋮ jako ve `FormStateBar`;
- `data-testid` zachovat: tlačítka na desktopu i položky kebabu nesou
  `review-skip`, `review-reject`, `review-apply-draft`; tlačítko ⋮
  vlastní testid (`review-more`);
- hláška `review-save-error` v patičce — ověř, že na telefonu nerozbije
  řádek (případně ji zapiš do Odchylek);
- kebab na Spisovně: Zamítnout a Přeskočit (Vystavit koncept tam není);
  když je položek nula, kebab se nevykreslí.

## 4. Tlačítka „vytvořit“ v panelu rozhodnutí

`exchange/ResolveDecisionPanel.svelte`, `.shpd-resolve__create`:
popisek svisle uprostřed tlačítka i při `min-height` 40 px. Zachovat
dnešní účel: při zalomení dlouhého popisku (název firmy) zůstane ikona
„+“ u prvního řádku na jeho účaří. Například mřížka
`grid-template-columns: auto 1fr; align-items: baseline;
align-content: center` místo flexu. Jiné komponenty tento vzor nemají
(ověřeno grepem — flex s `align-items: baseline` a `min-height` výšky
ovládacího prvku).

## 5. Dokumentace a nápověda

- `docs/design-system.md`:
  - Modální dialogy → Mobil: zavírací tlačítko patičky (D116) — prop
    `Button`, skrytí v `Modal`u, prázdná patička, výjimka potvrzovacích
    dialogů;
  - nový odstavec **Akce na telefonu** (u tlačítek nebo v Responzivitě):
    kebab `ui/KebabMenu` (D114), co jde do kebabu — destruktivní
    a vedlejší akce, viditelná zůstává hlavní (zelená / primární) akce,
    rozhodnutí podle `layoutStore.isMobile` (D115), vzor stavová lišta
    formuláře a patička náhledu (D113);
  - §6 Anti-patterny: vlastní kebab mimo sdílenou komponentu; tlačítko
    Zavřít / Zrušit na telefonu bez značky zavíracího tlačítka.
- `docs/frontend.md` — seznam komponent `ui/`, pokud ho obsahuje.
- `help/posta/kontrola-vytezeni.md` — na telefonu jsou Vystavit koncept,
  Zamítnout a Přeskočit pod tlačítkem **Další akce** (⋮; popisek ověř
  v `frontend/src/i18n/cs.js`), zavírá se křížkem; `python3
  scripts/help-index.py`.
- `tasks/README.md` — řádek tasku do oblasti design systému.

## Commity

1. `feat(frontend): sdílený kebab ve stavové liště a horní liště (#155, D114)` — krok 1
2. `feat(frontend): zavírací tlačítko patičky modalu se na telefonu skrývá (#155, D116)` — krok 2 vč. auditu
3. `fix(frontend): patička náhledu dokladu na telefonu s kebabem (#155, D113, D115)` — krok 3
4. `fix(frontend): popisek tlačítek vytvořit v panelu rozhodnutí uprostřed (#155)` — krok 4
5. `docs: akce na telefonu a zavírací tlačítko modalu (#155, D113–D116)` — krok 5 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `check-css-tokens.py`, `check-css-units.py`, `check-contrast.py`;
  `cd frontend && npm run build`, `npm test`, `npm run check:i18n`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis, světlý
  i tmavý vzhled), šířky **360 px, 390 px a 1280 px**; u každého místa
  šířka a pozice tlačítek (`getBoundingClientRect`) — nic nepřesahuje
  okno ani nemá oříznutý popisek:
  - patička náhledu dokladu (přijatá faktura z fronty i mimo frontu;
    dokument Spisovny) — telefon: hlavní akce + ⋮, obsah kebabu;
    desktop: všechna tlačítka jako dnes;
  - kebab ve stavové liště formuláře faktury a v horní liště detailu na
    telefonu — beze změny chování;
  - dialogy z auditu na telefonu — zavírací tlačítko skryté, × zavírá,
    dialog jen se zavíracím tlačítkem bez patičky; `ConfirmDialog`
    a dotaz na důvod zamítnutí se Zrušit;
  - panel rozhodnutí (položka, partner, dlouhý název z registru) — popisek
    „Vytvořit …“ svisle uprostřed (rozdíl horní a dolní mezery ≤ 1 px),
    zalomený popisek s ikonou u prvního řádku.
- `tests/e2e/dataset-walkthrough.spec.mjs` běží na desktopu (1440 px) —
  ověř, že testid `review-*` dál najde.
- Snímky před / po: patička náhledu (390 px), náhled tisku (390 px),
  panel rozhodnutí (390 px).

## Hotovo když

- na 360 a 390 px jsou v patičce náhledu vidět hlavní akce a ⋮, ostatní
  akce v kebabu, nic nepřetéká; na desktopu beze změny;
- `data-testid` tlačítek náhledu zachované (v kebabu na položkách);
- `FormStateBar`, `MobileTopBar` a náhled používají jednu komponentu
  kebabu;
- zavírací tlačítka patiček z auditu jsou označená, na telefonu skrytá;
  výjimky a neoznačená místa v Odchylkách s důvodem;
- popisek tlačítek „Vytvořit …“ v panelu rozhodnutí svisle uprostřed;
- `docs/design-system.md` a nápověda odpovídají implementaci.

## Odchylky

**Kebab (D114)**

- `KebabMenu` má navíc prop `variant`: `control` (výchozí, čtverec
  s rámečkem jako ve `FormStateBar`) a `plain` — bez rámečku, barvu dědí
  z lišty. `plain` bere horní lišta na telefonu: tlačítko ⋮ tam sedí na
  barvě sidebaru a rámeček ovládacího prvku s tmavým textem by neseděl.
  Rozměr a podklad při hoveru dává lišta vlastnostmi `--shpd-kebab-size`
  a `--shpd-kebab-hover-bg` (definuje je `MobileTopBar`).
- `flex: none` tlačítka je zapsané selektorem s prvkem
  (`button.shpd-kebab`), aby mělo vyšší specificitu než `flex: 1` patičky
  `Modal`u — s jednou třídou by rozhodovalo pořadí CSS v bundlu.
- Viditelné změny v horní liště: druhé ťuknutí na ⋮ bublinu zavře (dřív
  zůstala otevřená) a položky mají odsazení stavové lišty (výška 41 px
  místo 37 px). Stavová lišta formuláře je beze změny na pixel (390 px
  i 1280 px, porovnání snímků).
- Bublina je nově potomkem lišty (dřív sourozenec); `position: fixed`, na
  rozvržení lišty nemá vliv.
- Esc s otevřeným kebabem zavře bublinu **i** modal pod ní (oba
  poslouchají klávesu zvlášť). Ve stavové liště formuláře to tak bylo už
  dřív, v náhledu dokladu je to nově. Neopraveno — týká se `Popover`
  v `Modal` obecně (i panelu rozhodnutí), ne kebabu.

**Zavírací tlačítko (D116) — audit**

- Označeno `dismiss`: náhled tisku (Zavřít), odeslání e-mailem (Zrušit
  i Zavřít po odeslání), nahrání pošty, reanalýza ve vieweru, nastavení
  hesla, běh odpisů (Zrušit / Zavřít po zaúčtování), průvodce importem
  z registru (Zrušit na kroku hledání), předvyplnění registrace DPH,
  propojení bankovního účtu, nový zdroj dat v portálu, náhled dokladu
  (Zavřít), přejmenování konverzace chatu (Zrušit).
- Neoznačeno:
  - `ConfirmDialog`, `RejectReasonPrompt` — výjimka D116;
  - smazání konverzace chatu — potvrzovací dotaz postavený přímo na
    `Modal`, Zrušit je odpověď (stejný důvod jako `ConfirmDialog`);
  - Zpět na kroku náhledu průvodce importem z registru — vrací krok;
  - `QueueCategoriesPrompt` — v patičce je jen Pokračovat.
- Přejmenování konverzace chatu je označené: je to malý formulář jako
  nastavení hesla, ne dotaz.
- Skrytí jde přes potomky patičky (ne jen přímé děti) — v náhledu tisku je
  tlačítko v obalu snippetu. Prázdná patička se pozná podle přímých dětí.
- `FormDialog`, `ViewerDetailModal` a `ThemePanel` patičku `Modal`u
  nemají; dialogy vývojářského dashboardu (`DevDialog`) jsou mimo rozsah.

**Patička náhledu (D113)**

- Hláška `review-save-error` řádek na telefonu rozbíjela (vedle hlavní
  akce a ⋮ by jí na 360 px zbylo necelých 90 px). Má vlastní řádek **nad**
  tlačítky; patička má proto na telefonu vlastní obal na celou šířku
  (`.shpd-exchange-modal__footer-box`, vzor náhledu tisku). Tlačítka
  zůstávají v jednom řádku. Na desktopu beze změny.
- Výčet akcí kebabu je čistá funkce `exchange/reviewFooter.js` s testem
  (`tests/Unit/reviewFooter.test.mjs`) — nad rámec zadání.
- Zavřít se vykresluje i na telefonu (skryté `Modal`em), `review-close`
  zůstává v DOM.

**Dokumentace**

- Vedle `help/posta/kontrola-vytezeni.md` upravena i
  `help/faktury-vydane/tisk-faktury.md` (okno Tisk se na telefonu zavírá
  křížkem).
- Zmínky o kebabu přes `Popover` upraveny i v `docs/edit-forms.md`.

**Ověření**

Chromium (Playwright), zdroj dat `zey0-g` (demo sada IT servis), šířky
360, 390 a 1280 px, světlý i tmavý vzhled.

| Místo | 360 px | 390 px | 1280 px |
|---|---|---|---|
| Patička náhledu — před | 2 tlačítka mimo okno (ve frontě 3) | 2 mimo okno | 4 (5) tlačítek |
| Patička náhledu — po | Vystavit a uzavřít 264 px + ⋮ 40 px | 294 px + ⋮ 40 px | beze změny na pixel |
| Kebab náhledu | Vystavit koncept, Zamítnout (červeně); ve frontě + Přeskočit | totéž | není |
| Náhled tisku | Stáhnout 312 px, Zavřít skryté | Stáhnout 342 px | Zavřít + Stáhnout |
| Odeslání po odeslání | patička skrytá, × zavírá | totéž | Zavřít |
| Tlačítko „Vytvořit …“ — mezery nad / pod popiskem | 9 / 9 px (před 6 / 12) | 9 / 9 px | 7 / 7 px (před 6 / 8) |

- Zalomený popisek „Vytvořit z registru: …“ (4 řádky na telefonu, 3 na
  desktopu): mezery 6 / 6 px, ikona u prvního řádku jako dřív.
- Naživo otevřené dialogy z auditu: náhled tisku, odeslání e-mailem (před
  i po odeslání), nahrání pošty, průvodce importem z registru, náhled
  dokladu; výjimky: neuložené změny formuláře, neuložená rozhodnutí
  náhledu (`ConfirmDialog`) a dotaz na důvod zamítnutí — Zrušit / Zůstat
  na telefonu vidět. Ostatní označené dialogy (reanalýza, nastavení
  hesla, běh odpisů, dva dialogy Nastavení, nový zdroj dat, přejmenování
  konverzace) naživo otevřené nebyly — mají stejnou značku a totéž
  pravidlo `Modal`u.
- Podvržená data (demo sada je nemá): dokument Spisovny (cíl náhledu
  přepsaný v odpovědi serveru), hláška o neuloženém rozhodnutí (uložení
  rozhodnutí vrací chybu), rychlé založení z registru s dlouhým názvem,
  odeslání e-mailu (odpověď serveru podvržená, nic se neodeslalo).
- Kebab ve stavové liště formuláře faktury a v horní liště detailu na
  telefonu: stejné položky jako před změnou, v tmavém vzhledu destruktivní
  položka v tónu `danger-text`.
- `shpd-ds dataset-e2e` nad sadou `cz/association` (desktop): v pořádku,
  bez nálezů — `review-close` i `review-apply-final` spec dál najde.
- `check-css-tokens.py`, `check-css-units.py`, `check-contrast.py`,
  `npm run build`, `npm test`, `npm run check:i18n` — v pořádku.
- Automatické ověření běželo jen v Chromiu — Firefox pro Playwright na
  vývojovém stroji není (pravidlo prázdné patičky stojí na `:has()`,
  Firefox od verze 121) — a jen v emulované šířce, ne na telefonu.

**Ruční ověření (2026-11-18)**

- Náhled dokladu přes **Zkontrolovat** i **Projít frontu**: všechna
  tlačítka jsou tam, kde mají být.
- **Tisk** a **Odeslat** na faktuře vydané: Zavřít / Zrušit vidět není.
- Potvrzovací dialogy: Zrušit / Zůstat mají.
- Tlačítko „Vytvořit …“ v panelu rozhodnutí náhledu: popisek je
  vycentrovaný.
