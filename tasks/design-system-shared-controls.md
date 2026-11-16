# Design system — panel, volitelný řádek, segmentový přepínač, nadpis stránky

**Stav:** hotovo — 2026-11-16 (#149, D75–D78); ručně ověřeno 2026-11-16 (Odchylky 14); neověřeno: Portál (jen alfa), předmět přenesení, dialog převodu bankovních účtů (jen na čerstvém zdroji dat); nálezy řeší `tasks/design-system-shared-controls-fixes.md` (D79, D80)

## Kontext / Cíl

Po uzavření #133 zůstaly čtyři druhy prvků, které si každá obrazovka kreslí
po svém (audit v komentáři #149):

- **vložené šedé panely** — sekce formuláře, nabídka položek, položka
  průvodce zdrojem dat, paleta vzhledu, souhrn předvyplnění registrace DPH,
  nápověda a „aktuální volba“ v rozhodovacích panelech importu, rozbalený
  detail karty feedu; tři různé rámečky, odsazení i zaoblení, panel v panelu;
- **volitelné řádky** — dialog Přidat firmu z registru (v tasku původně
  chybně „import ze Spisovny“), převod bankovních účtů, kód DPH,
  předmět přenesení, kandidáti partnera; každý jiný stav pod kurzorem,
  vybraný stav jen u poloviny, zakázaný 0,7 / 0,6;
- **segmentový přepínač** — pět kopií v Nastavení a reportech + pilulky
  v nahrávání pošty; výběr oranžovou `accent` a tloušťkou 500 (mimo §11);
- **nadpisy stránek** — Dashboard, Přehled firmy, Štítky obsahu, Portál,
  Reporty; velikost xl mimo role, tloušťka 700 mimo §11.

**Cíl:** čtyři sdílené komponenty v `components/ui/` a převod všech výskytů
z auditu na ně, kapitoly v `docs/design-system.md`.

**Mimo rozsah:** prvek `html` ve formulářích (#150 — rekapitulace DPH,
fakturační údaje a nápovědy formulářů přijdou na řadu tam), kontrast tónů
na plochách mimo pozadí stránky (#153), štítky uvnitř řádků dialogu z registru a
převodu účtů (vlastní třídy `__badge` — badge systém §5, samostatně),
kódové bloky (role `code`, D30), obrazovky s kartou uprostřed
(`CenteredScreen`, `StatusScreen`), titulek dialogu (`Modal`).

## Návaznost

- **#149** — audit a D75–D78 (komentář v issue)
- **#133** — D15, D16 (tloušťky), D35 (karta, nebo ne), D36–D38 (nadpis
  karty, ikona tématu), D37 (karta feedu)
- **#150**, **#153** — navazují

## Před implementací přečti

- `docs/design-system.md` §8 Karty (D35), §9 bg-secondary vs bg-hover,
  §10 Jednotky a mezery, §11 Typografie, §12 Nadpis sekce
- `frontend/src/styles/variables.css` (tokeny barev, mezer, zaoblení, rolí písma)
- `frontend/src/components/ui/Card.svelte`, `SectionHeading.svelte`,
  `DetailHeader.svelte`, `Checkbox.svelte`, `Icon.svelte`
- `frontend/src/components/form/FormSection.svelte`
- výskyty k převodu — seznam v krocích níže

## Rozhodnutí (potvrzená v #149)

- ✓ **D75 Panel** (`ui/Panel.svelte`) — vložená šedá plocha, ne karta
  (D35): pozadí `--shpd-color-bg-secondary`, rámeček
  `--shpd-color-border-subtle`, zaoblení md, odsazení md, bez stínu a
  vnějšího okraje. Varianta `compact`: bez rámečku, odsazení sm, zaoblení
  sm. Tón `info`: `--shpd-color-primary-soft` bez rámečku. Panel v panelu
  se nepoužívá. `FormSection` stojí na panelu a ponechá si husté odsazení
  (`--shpd-form-section-padding-y` × lg). Rozbalený detail karty feedu
  panelem není — bez pozadí a vlastního odsazení.
- ✓ **D76 Volitelný řádek** (`ui/OptionRow.svelte`) — dvě varianty podle
  místa. **Velký** (volba s popisem, potvrzuje se tlačítkem): odsazení md,
  zaoblení md, ukazatel volby (radio / checkbox) vždy vlevo, název body
  strong, popis meta šedě, obsah vpravo. **Kompaktní** (jednořádková volba
  aplikovaná kliknutím): odsazení xs × sm, zaoblení sm, bez ukazatele.
  Vybraný: rámeček `--shpd-color-primary` + pozadí `primary-soft`; pod
  kurzorem `bg-hover`; zakázaný průhlednost 0,6.
- ✓ **D77 Segmentový přepínač** (`ui/SegmentedControl.svelte`) — oddělená
  tlačítka s mezerou xs (zalamují se), `radiogroup` / `radio`; vybraná
  volba jako D76 (`primary` + `primary-soft`, tloušťka normal). Barvou
  výběru je v celé aplikaci `primary`, `accent` zůstává barvou značky.
- ✓ **D78 Nadpis stránky** (`ui/PageHeader.svelte`) — `h1`, ikona
  z navigace (obdoba D38; bez ikony v navigaci bez ikony, žádný fallback),
  obsah vpravo se zalomí pod nadpis. **V toku stránky**: nová typografická
  role `page` = xl strong. **V liště**: tvar hlavičky detailu, role title,
  linka dole.

## 1. Panel (D75)

`ui/Panel.svelte`:

- props `variant` (`default` | `compact`), `tone` (`neutral` výchozí |
  `info`), `as` (`div` výchozí, `section`, `dl`, …), zbylé atributy
  (`data-testid`, `role`, `class` pro rozložení obsahu) na kořen — obdoba
  `Card`.
- vzhled přesně podle D75; v dark módu bez úprav (tokeny se přepínají).
- vnitřní rozložení dává obsah; rodič kořen přes vlastní třídu nestyluje
  vzhled (pozadí, rámeček, odsazení) — co panel potřebuje, je prop.

`FormSection` postavit na `Panel` (`as="section"` nebo jak sedí dnešní
markup) s vlastním odsazením sekce formuláře — buď prop `padding`
(`md` výchozí | `form`), nebo jediná povolená výjimka v `FormSection`;
rozhodni podle toho, co je čistší, a zapiš do Odchylek. `container-type`
a `container-name: form-section` musí zůstat na prvku, který má šířku
sekce (komentář v `FormSection.svelte`, `FormAttachmentsView`).

Převod (default):

- `settings/AccountingItemsOffer.svelte` — `.shpd-items-offer`; vnitřní
  `.shpd-items-offer__summary` ztrácí box (rámeček, pozadí, odsazení,
  zaoblení), zůstává `role="status"` a text
- `settings/DsSetup.svelte` — `.shpd-ds-setup__item`
- `settings/DsThemeField.svelte` — `.shpd-ds-theme-field__swatches`
- `settings/VatRegistrationPrefillDialog.svelte` — `.shpd-vat-prefill__summary`
  (`<dl>` — `as="dl"`)

Převod (compact): `exchange/ResolveDecisionPanel.svelte` —
`.shpd-resolve__hint`.

Převod (tón info): `.shpd-resolve__current` (`ResolveDecisionPanel`),
`.shpd-vatcode__current` (`VatCodeDecisionPanel`),
`.shpd-rcsubject__current` (`ReverseChargeDecisionPanel`).

Karta feedu (`dashboard/FeedCard.svelte`): `.shpd-feed-card__details` bez
pozadí, odsazení a zaoblení; mezera nad (`margin-top: sm`), mezera mezi
řádky a vzhled řádků (D37) beze změny.

## 2. Volitelný řádek (D76)

`ui/OptionRow.svelte`:

- props `size` (`large` | `compact`), `selected`, `disabled`, `indicator`
  (`radio` | `checkbox` | `none`; výchozí `radio` u `large`, `none` u
  `compact`), `name`, `value`, `onselect`; snippety `children` (název),
  `description`, `aside` (obsah vpravo).
- **Sémantika:** velký řádek s ukazatelem je `<label>` s nativním
  `<input type="radio|checkbox">` (klik kamkoli v řádku volí, klávesnice
  přes input; checkbox přes sdílený `Checkbox`, je-li to čisté);
  kompaktní řádek je tlačítko s `role` podle rodiče — rozhodovací panely
  mají dnes `role="listbox"` / `role="option"` s `aria-selected`; to
  zachovat (prop `role`, výchozí `radio` s `aria-checked`).
- vzhled podle D76; `:focus-visible` obrys `primary` jako dnes v dialogu z registru;
  zakázaný `opacity: 0.6` + `cursor: not-allowed`.

Převod:

- `registry/RegistryImportWizard.svelte` — `.shpd-registry-wizard__row`:
  velký s radio (skupina řádků je `radiogroup`, dnešní `aria-pressed`
  zaniká). Pořadové číslo řádku zaniká — na jeho místě je ukazatel.
  Štítky (`__row-badges`) do `aside`. Záložní `rgba()` u vybraného stavu
  zaniká s CSS řádku.
- `settings/BankAccountBridgeDialog.svelte` — `.shpd-bank-bridge__row`:
  velký s checkboxem (výběr více účtů); štítek „publikovaný“ zůstává
  v názvu. Záložní `rgba()` u `.shpd-bank-bridge__badge` nahradit jen
  tokenem.
- `exchange/VatCodeDecisionPanel.svelte` — `.shpd-vatcode__option`: kompaktní
- `exchange/ReverseChargeDecisionPanel.svelte` — `.shpd-rcsubject__option`:
  kompaktní
- `exchange/ResolveDecisionPanel.svelte` — `.shpd-resolve__candidate`:
  kompaktní; vybraný stav podle toho, co panel vybral (dnes ho nemá —
  pokud kandidát po kliknutí hned zmizí / panel se zavře, `selected`
  nepotřebuje; zapiš do Odchylek)

## 3. Segmentový přepínač (D77)

`ui/SegmentedControl.svelte`:

- props `options` (`[{value, label, icon?, disabled?}]`), `value`
  (bindable), `label` (`aria-label` skupiny), `disabled`, `size`
  (`md` výchozí; pilulky v nahrávání pošty bez nové velikosti — viz níže).
- `role="radiogroup"`, tlačítka `role="radio"` + `aria-checked`, šipky
  vlevo/vpravo přesouvají výběr (roving tabindex) — zkontroluj, co dnešní
  kopie dělají, a zachovej nejlepší z nich.
- vzhled: tlačítko odsazení xs × md, rámeček `border`, zaoblení md,
  pozadí `bg`, písmo body; pod kurzorem `bg-hover`; vybrané rámeček
  `primary` + `primary-soft`, tloušťka normal (500 i 600 zanikají);
  mezera mezi tlačítky xs, `flex-wrap`.
- fajfka u vybrané volby (dnes vzhled a rozvržení) zaniká — stav nese
  barva a `aria-checked`; ikona volby (`icon`) jen tam, kde je dnes
  součástí popisku.

Převod: `settings/ThemeModeSegments.svelte`, `settings/ShellSegments.svelte`,
`settings/LanguageField.svelte`, segmenty v `settings/DsSetup.svelte`
(`.shpd-ds-setup__segment`), formát exportu v `reports/ReportsPage.svelte`
(`.shpd-reports__format-segment`), režimy v `dashboard/MailUploadModal.svelte`
(`.shpd-mail-upload__mode` — zaoblení full a plná `primary` zanikají).
Komponenty `ThemeModeSegments` a `ShellSegments` mohou zůstat jako tenké
obaly s konkrétními volbami, nebo zaniknout — podle počtu míst, kde se
používají.

## 4. Nadpis stránky (D78)

Token role: `--shpd-font-size-page: var(--shpd-font-size-xl);` ve
`variables.css` (u ostatních rolí, s komentářem).

`ui/PageHeader.svelte`:

- props `title`, `icon` (objekt ikony nebo nic), `variant` (`flow`
  výchozí | `bar`), snippet `aside` (obsah vpravo — akce, datum „k“,
  výběr období).
- `flow`: `h1` role `page` (velikost `--shpd-font-size-page`, tloušťka
  strong), ikona před nadpisem jako v `SectionHeading` (D38: `Icon
  size="md"`, šedá, mezera sm, `aria-hidden`); řádek `flex-wrap`, `aside`
  vpravo, při nedostatku místa pod nadpisem; žádný vnější okraj — mezeru
  dává stránka.
- `bar`: `h1` role title strong, odsazení, pozadí a linka dole jako
  `DetailHeader` (sdílet hodnoty, ne kopírovat literály — `DetailHeader`
  má dnes `0.75rem`; pokud jde sjednotit na tokeny bez vizuální změny
  detailu, sjednoť, jinak nech a zapiš).

Převod:

- `dashboard/Dashboard.svelte` — `flow`, akce v `aside`
- `overview/CompanyOverview.svelte` — `flow`, ikona jako dnes, datum „k“
  v `aside`
- `settings/ContentTagsSettings.svelte` — `flow`, `h2` → `h1`; popis pod
  nadpisem zůstává obsahem stránky
- `portal/PortalContent.svelte` — `flow`, tlačítko v `aside`
- `reports/ReportsPage.svelte` — `bar`, výběr období a formát v `aside`

Ikona: z položky navigace, odkud stránka vznikla (stejná jako v menu,
D38); stránka bez ikony v navigaci (pravděpodobně Portál) nadpis bez
ikony — žádný fallback `resolveIcon`. Zjisti, odkud ji `ContentArea` /
stránka dostane, a zapiš do Odchylek, kde se ikona bere.

## 5. Dokumentace

`docs/design-system.md`:

- §8 nové podkapitoly **Vložené panely** (D75), **Volitelné řádky** (D76),
  **Segmentový přepínač** (D77), **Nadpis stránky** (D78) — ve stylu
  podkapitoly Karty: komponenta, vzhled tokeny, varianty, kde se používá,
  pravidlo „nový = sdílená komponenta, ne vlastní třída“.
- §8 Karty, D35: odkaz na nové podkapitoly místo výčtu.
- §11 tabulka rolí: nový řádek `page` (xl, strong, nadpis stránky v toku);
  úvodní věta „čtyři velikosti“ upravit na skutečný stav (role page přidává
  xl, který ve škále je). Tloušťka 500 a 700 nikde v převedených
  komponentách.
- §3 Stavové barvy / Borders & focus: jedna věta, že barvou výběru je
  `primary` (D77), `accent` je barva značky.
- §13 Soubory: nové komponenty.

`tasks/README.md` — řádek v tabulce Design system, `python3
scripts/tasks-index.py`.

## Commity

1. `Panel` + převod panelů a karty feedu (D75)
2. `OptionRow` + převod volitelných řádků (D76)
3. `SegmentedControl` + převod přepínačů (D77)
4. role `page`, `PageHeader` + převod nadpisů (D78)
5. dokumentace + stav tasku + index

Každý commit prochází pre-commitem (`check-css-units.py` a ostatní) a
`npm run build` ve `frontend/`.

## Ověření

V prohlížeči na dev zdroji dat, světlý i tmavý vzhled, šířka okna
plná i 360 px:

1. Formulář libovolného záznamu — sekce vypadají jako dřív (husté odsazení,
   slabý rámeček); náhled příloh ve formuláři přepíná sloupce jako dřív.
2. Nastavení → nabídka položek (bez boxu v boxu), průvodce zdrojem dat,
   paleta vzhledu, předvyplnění registrace DPH — panely stejné.
3. Dashboard → karta feedu, rozbalený detail bez šedého pozadí.
4. Dialog Přidat firmu z registru — řádky s radio, výběr myší i klávesnicí, zakázaný
   řádek; převod bankovních účtů — checkboxy, klik na řádek přepíná.
5. Náhled importu dokladu — kód DPH, předmět přenesení, kandidáti partnera:
   kompaktní řádky, vybraný stav, „aktuální volba“ modře.
6. Segmenty: vzhled, rozvržení, jazyk, průvodce zdrojem dat, formát
   exportu reportu, režim nahrání pošty — vybraná volba modře, šipky
   přesouvají výběr.
7. Nadpisy: Dashboard, Přehled firmy, Štítky obsahu, Portál (stejná
   velikost a tloušťka), Reporty (lišta jako hlavička detailu); na 360 px
   se obsah vpravo zalomí pod nadpis.
8. `grep` po převedených třídách (`shpd-items-offer`, `shpd-ds-setup__item`,
   `__segment`, `__current`, `__option`, `__candidate`, `__row`,
   `__title`) — CSS výskyty pryč, zůstávají jen třídy rozložení.

## Hotovo když

- [x] čtyři komponenty v `components/ui/` podle D75–D78
- [x] všechny výskyty z kroků 1–4 převedené, jejich vlastní CSS vzhledu pryč
- [x] role `page` ve `variables.css` a §11
- [x] `docs/design-system.md` §8, §11, §3, §13
- [x] ověření 1–8 prošlo, nálezy v Odchylkách
- [x] `**Stav:**` aktualizovaný, `scripts/tasks-index.py`

## Odchylky od zadání

1. **`Panel` má prop `padding`** (`md` | `form`) — čistší než výjimka ve
   `FormSection`: odsazení zůstává vlastností panelu a `FormSection` ho
   nepřebíjí vlastní třídou. Třída `shpd-form-section` na kořeni panelu
   nese jen pojmenovaný kontejner a roztažení sekce s fill sloupcem
   (`:global`, kořen cizí komponenty třídu rozsahu nenese). Stejně nesou
   rozložení třídy `shpd-vat-prefill__summary` (mřížka `dl`),
   `shpd-ds-theme-field__swatches` (`max-width`) a `__current` / `__hint`
   rozhodovacích panelů (řádek, písmo).
2. **„Aktuální volba“ je `compact` + `info`** (zadání uvádí jen tón) —
   výchozí varianta by ji v popoveru nafoukla na odsazení a zaoblení md.
   Svislé odsazení nápovědy a „aktuální volby“ je sm místo dnešních xs.
3. **Rámeček panelů v Nastavení** (nabídka položek, položka průvodce,
   paleta, souhrn DPH) je `border-subtle` místo dosavadního `border` —
   důsledek D75, ne „stejné“ doslova. Položka průvodce je `Panel as="li"`.
4. **Ukazatel volby kreslí `OptionRow` sám** stejnými tokeny jako
   `Checkbox` (asi 20 řádků CSS navíc). Sdílený `Checkbox` je sám
   `<label>` s `margin-bottom: md` a do řádku vložit nejde.
5. **Řádek s ukazatelem není celý `<label>`:** kořen je `div`, label
   s ukazatelem a textem je roztažený přes řádek (`::after`) a `aside`
   leží mimo něj. Důvod: převod bankovních účtů má v řádku radio
   „výchozí“ s vlastním `<label>` a vnořený label je neplatný.
   Neinteraktivní obsah `aside` (štítky) klik propustí na řádek.
6. **Kandidáti partnera** mají `selected` z aktuální volby
   (`currentUserAction === useExisting:<id>`) — platí, ať popover po
   kliknutí zůstane, nebo ne — a seznam je nově `listbox` s `aria-label`
   z klíče `exchange.preview.decide.candidates`.
7. **Výsledky hledání v registru:** seznam je `div role="radiogroup"` (ne `ul`),
   `aria-label` z titulku dialogu — klíč pro skupinu v katalogu není.
   Řádek s prázdným `aside` (bez štítků) má vpravo prázdný prvek
   s mezerou md. Zakázaný řádek má 0,6 místo 0,7; pod kurzorem jen
   `bg-hover` (rámeček `primary` pod kurzorem zanikl).
8. **`SegmentedControl` má navíc `onchange(value)`** (průvodce a reporty
   volají funkci) a hodnoty smí být `null` / boolean. Dosavadní kopie
   šipky neřešily — roving tabindex (šipky, Home / End) je nový; šipka
   výběr rovnou mění, u jazyka tedy znovu načte stránku a v průvodci
   ukládá. `ThemeModeSegments` a `ShellSegments` zůstaly jako tenké obaly
   (po dvou použitích), `LanguageField` volá komponentu přímo. Průvodce
   váže `bind:value={draft[key]}` — po chybě uložení se volba vrátí.
9. **Popisek skupiny (`aria-label`):** pole Nastavení ho berou
   z `field.label` (nový prop `label` na `ThemeField`, `DsThemeField`,
   `ShellField`, `DsShellField`, `LanguageField`), průvodce
   z `setup.param.*`. **Formát čísel reportu zůstal bez popisku** — klíč
   pro něj v katalogu není a nové texty zadání zakazuje.
10. **Kde se bere ikona nadpisu:** z `icon` aktivní položky navigace.
    `stores/navigation.svelte.js` ji dosud zahazoval — nově ji nese
    `navigate()`, výchozí položka, deep-link a proklik do reportu
    i `DASHBOARD_ITEM`; `Sidebar.handleItemClick` ji předává (dřív ne —
    nález z ověření; recents palety ze sidebaru tím dostaly ikonu také)
    a `ContentArea` dává `item` i Dashboardu. Stránka předává
    `item?.icon ? resolveIcon(item.icon) : undefined`. **Portál ikonu
    v navigaci má** (`database`), v nadpisu ji proto má také — zadání
    čekalo opak. Ad-hoc cíle (`navigateToPanel`) ikonu nenesou.
11. **Lišta `bar`:** svislé odsazení je nový layout token
    `--shpd-bar-padding-y` (0.75rem) sdílený s `DetailHeader` — detail se
    nemění, vodorovná mezera `gap: 0.75rem` v `DetailHeader` zůstala
    literálem. Lišta reportů má svisle 12 px místo 16 px a ovládací prvky
    jsou vpravo (dřív hned za nadpisem).
12. **Ověření** (Playwright nad volným zdrojem dat `zey0-g…`, světlý
    i tmavý vzhled, 1500 a 360 px; dialogy průvodce a hledání v registru
    s podvrženými odpověďmi API): body 1–8 prošly — sekce formuláře má
    stejné vypočtené styly jako dřív a kontejner `form-section` skládá
    sloupce na 360 px; žádný panel v panelu ani na `bg-secondary`
    podkladu. **Neověřeno v prohlížeči:** Portál (na stroji není zdroj
    dat s hostingem), předmět přenesení a kandidáti partnera (demo sada
    je nemá — stojí na stejném `OptionRow` jako ověřený kód DPH),
    přepínač vzhledu v Nastavení účtu (účet sleduje výchozí vzhled;
    tentýž obal je ověřený v Nastavení aplikace). Barva textu „aktuální
    volby“ (`primary` na `primary-soft`) má v tmavém vzhledu slabý
    kontrast — beze změny, patří do #153.
13. **Štítky uvnitř řádků** (dialog z registru, bankovní účty) beze změny včetně
    tloušťky 500 — mimo rozsah (badge systém §5); u štítku účtu jen
    `rgba()` za token.
14. **Ruční ověření v prohlížeči** (2026-11-16, člověk): segmenty
    v Nastavení, panely v Nastavení (slabší rámeček čitelný), reporty
    včetně telefonu (ovládací prvky zalomené pod nadpis), dialog Přidat
    firmu z registru, kandidáti partnera v náhledu importu a paleta
    příkazů `ok`. Nálezy: šipky v segmentovém přepínači rovnou mění
    volbu — jazyk znovu načte stránku, průvodce ukládá (D79); popis ve
    volitelném řádku (adresa, IČO, DŤČ, účet) je meta šedě a moc malý,
    odporuje §11 (D80); tlačítko „Vytvořit z registru“ v kartě strany
    náhledu importu na střední šířce přetéká (nesouvisí s tímto taskem,
    `Button` má `nowrap`). Mimo rozsah: tlačítko Export v liště reportů
    má jinou velikost než ostatní ovládací prvky — sjednotit později.
    Kontrast „aktuální volby“ v tmavém vzhledu předán do #153.

## Pasti

- **`data-testid`** na převáděných prvcích musí zůstat (e2e a videa) —
  komponenty je předávají na kořen / na tlačítko volby.
- **`FormSection` a container query** — jméno `form-section` sdílí
  `FormAttachmentsView`; kontejner nesmí skončit na vnitřním obalu.
- **Pozadí ve formuláři** — sekce formuláře leží na `bg`, panely
  v dialozích taky; žádný panel nesmí skončit na `bg-secondary` podkladu
  (šedá na šedé) — kdyby se to někde stalo, zapiš do Odchylek.
- **Dark mód** — `bg-secondary` je tmavší než `bg`, `bg-hover` světlejší
  (§9); pod kurzorem vždy `bg-hover`, ne `bg-secondary`.
- **Tloušťky** — `<strong>` a `<h1>` mají výchozí 700; vždy
  `--shpd-font-weight-strong`.
- **px v CSS** zastaví pre-commit; mezery a zaoblení jen tokeny.
- **Žádné nové texty** — popisky a `aria-label` z existujících klíčů
  katalogu.
