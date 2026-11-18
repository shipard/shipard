# Design system — jeden zásobník vrstev pro Esc, klik mimo, boční chat (D121–D123)

**Stav:** hotovo — 2026-11-18 (#160, D121–D123; 5 commitů, odchylky viz Odchylky); scénáře ověřené v Chromiu (Playwright) na 1280 px a 390 px, `dataset-e2e` nad sadou `cz/vat-payer` bez nálezů; ručně ověřeno 2026-11-18 (Firefox, telefon) — nález klepnutí do vloženého dokumentu → `tasks/design-system-iframe-layers.md` (D124)

## Kontext / Cíl

Každá vrstva, která se dá zavřít, poslouchá Esc po svém a o ostatních
neví (rozbor v #160):

| vrstva | dnes |
|---|---|
| `ui/Modal.svelte` | zásobník modalů v modulu (`modalStack`, `isTopOfStack`, `pushModal` — hloubka pro depth-shrink), posluchač na `window`, značka spotřebovaného Esc `consumedEscapes`; klik na překryv zavírá |
| `ui/Popover.svelte` (kebab `ui/KebabMenu`, panel rozhodnutí, výběr období, …) | posluchač na `document`, značku nezná → Esc zavře bublinu **i** modal pod ní; klik mimo zavírá (výjimka pro klik ve vlastním modalu) |
| `ui/LookupInput.svelte` | Esc zavře nabídku jen `preventDefault()` → podle kódu zavře i formulář pod ní |
| `ui/MultiselectInput.svelte` | Esc zavře nabídku a `stopPropagation()` — v pořádku |
| `viewer/ViewerDetailDrawer.svelte` | `svelte:window`, reaguje jen když `!isModalOpen()`; bubliny nevidí |
| `layout/ThemePanel.svelte`, `chrome/UserMenu.svelte` | vlastní posluchač Esc a klik mimo na `document` |
| `chrome/CommandPalette.svelte` | vlastní posluchač Esc (a šipky, Enter) |
| `shells/SidebarShell.svelte` | Esc zavře drawer sidebaru (jen telefon) |
| `chat/ChatPanel.svelte` | nic — zavírá jen ×; komentář v kódu: Esc by kolidoval s modalem nad panelem |

**Cíl:** jeden stisk Esc zavře jednu vrstvu — tu naposledy otevřenou;
klik mimo zavírá jen dočasné vrstvy; boční chat se zavírá Esc.

**Mimo rozsah:**

- Esc uvnitř editace textu, který Esc ruší sám (přejmenování přílohy
  v `AttachmentPanel` — `cancelRename`): zůstává lokální, jen nesmí
  zároveň zavřít vrstvu (ověř);
- vývojářský dashboard (`DevDialog`, `DevKebabMenu`);
- nativní prvky prohlížeče (`<select>`, výběr data) — Esc v nich řeší
  prohlížeč; ověř, že po zavření nativní nabídky Esc nezavře i vrstvu
  (pokud ano, zapiš do Odchylek).

## Návaznost

- **#160** — D121–D123 (komentář s rozborem a rozhodnutími)
- **#155** — D114 (`ui/KebabMenu` nad `Popover`),
  `tasks/design-system-review-footer-mobile.md` → Odchylky (nález Esc)
- `ConfirmDialog` „Neuložené změny“ nad `FormDialog` — důvod značky
  `consumedEscapes` (komentář v `Modal.svelte`)

## Před implementací přečti

- `frontend/src/components/ui/Modal.svelte` — modulový skript (zásobník,
  `isModalOpen`, `consumedEscapes`), `handleKeydown`, `handleOverlayClick`
- `frontend/src/components/ui/Popover.svelte` — `handleKey`,
  `handleDocClick`
- komponenty z tabulky výše
- `docs/design-system.md` §8 Modální dialogy, Akce na telefonu (#155),
  §6 Anti-patterny
- `docs/frontend.md` — sekce o modalech, popoveru, chat panelu

## Rozhodnutí (potvrzená v #160)

- ✓ **D121** Jeden zásobník vrstev pro Esc. Každá vrstva, která se dá
  zavřít, se při otevření zapíše do sdíleného zásobníku; jeden posluchač
  zavře jen naposledy otevřenou vrstvu, další Esc tu pod ní. Patří sem
  modal, bublina, nabídka lookupu a výběru více hodnot, detail v draweru,
  boční chat, panel vzhledu, uživatelské menu, paleta příkazů a drawer
  sidebaru na telefonu. Dnešní zásobník modalů se do něj sloučí. Jeden
  stisk = jedna zavřená vrstva, i když zavření otevře dotaz „Neuložené
  změny“.
- ✓ **D122** Klik mimo zavírá jen dočasné vrstvy — bubliny, nabídky polí,
  uživatelské menu, panel vzhledu, paletu (jako dnes). Postranní panely
  (detail v draweru, boční chat) klik mimo nezavírá — pracuje se vedle
  nich. Modal dál zavírá klik na ztmavené pozadí.
- ✓ **D123** Boční chat zavírá Esc (D121); klik mimo ne (D122).

## 1. Zásobník vrstev (D121)

Nový sdílený modul, např. `frontend/src/components/ui/layers.js` (čistý
JS bez Svelte, ať jde testovat v `npm test`; název uprav podle konvence):

- `openLayer({ onEscape, kind })` → vrátí funkci k odebrání (nebo id);
  vrstva se přidá na vrchol při otevření a odebere při zavření či
  zničení komponenty (`$effect` s úklidem — ne jen v obsluze zavření);
- jeden posluchač `keydown` na `window` (zaregistrovaný jednou, v modulu
  nebo v `AppShell`): na Esc zavolá `onEscape` **jen nejvyšší vrstvy**,
  událost označí jako spotřebovanou / zastaví (`preventDefault`), aby ji
  nezpracoval nikdo další;
- **jeden stisk = jedna vrstva**: vrstva otevřená uvnitř `onEscape`
  (dotaz „Neuložené změny“ z `FormDialog`) se týmž stiskem nezavře —
  dnešní význam `consumedEscapes` zachovat (test na to);
- dotazy pro komponenty, které je potřebují: je vrstva nahoře
  (`isTopLayer`), je otevřený modal (`isModalOpen` — dnes ho čte drawer;
  po převodu drawer na zásobník pravděpodobně zanikne), hloubka modalu
  pro depth-shrink (`pushModal` dnes vrací index — zachovat chování
  vnořených modalů).

Jednotkový test (`frontend/tests/Unit/`): pořadí vrstev, Esc zavře jen
vrchol, odebrání vrstvy ze středu zásobníku (zavřená jinak než Esc),
vrstva otevřená během `onEscape` týmž stiskem nezavřená.

## 2. Převod vrstev (D121)

Převést na zásobník a odstranit vlastní posluchače Esc:

- `Modal` — `modalStack`, `isTopOfStack`, `consumedEscapes` sloučit do
  zásobníku; klávesy jiné než Esc (`onKeydown` pro `FormDialog`: Alt+←/→)
  dál jen pro nejvyšší **modal** — ale ne, když je nad ním otevřená
  bublina nebo nabídka (ověř, že Alt+← v otevřeném lookupu nepřepne
  záznam; pokud dnes přepne, zapiš do Odchylek a oprav, je-li to malé);
- `Popover` — Esc přes zásobník; klik mimo beze změny (D122);
- `LookupInput`, `MultiselectInput` — otevřená nabídka je vrstva;
  ostatní klávesy (šipky, Enter, Tab) zůstávají v komponentě;
- `ViewerDetailDrawer` — vrstva (Esc), bez kliku mimo;
- `ThemePanel`, `UserMenu` — vrstva (Esc), klik mimo beze změny;
- `CommandPalette` — Esc přes zásobník; šipky a Enter zůstávají;
- `SidebarShell` — drawer sidebaru na telefonu je vrstva;
- `ChatPanel` — viz krok 3.

Po převodu grep `--include=*.svelte` na `'Escape'` v `components/`:
zbýt smí jen editace textu (`AttachmentPanel`) a zásobník; každé další
místo do Odchylek s důvodem.

## 3. Boční chat (D122, D123)

`chat/ChatPanel.svelte`: otevřený panel je vrstva — Esc ho zavře, pokud
nad ním není jiná vrstva (modal z akce v odpovědi, bublina). Klik mimo
panel ho nezavírá. Esc při psaní do pole chatu panel zavře (pole nemá
vlastní význam Esc); rozepsaný text zůstává v úložišti chatu, pokud tam
dnes zůstává — ověř a zapiš. Odstranit komentář „Zavírání v1 jen přes ×“.

## 4. Dokumentace

- `docs/design-system.md` — nový odstavec **Zavírání vrstev** (§8 u
  Modálních dialogů): zásobník, Esc zavře naposledy otevřenou vrstvu,
  jeden stisk = jedna vrstva (D121); klik mimo jen dočasné vrstvy,
  postranní panely ne, modal klikem na pozadí (D122); boční chat (D123);
  §6 Anti-patterny: vlastní posluchač Esc v komponentě vrstvy.
- `docs/frontend.md` — modaly a popover (zásobník místo `modalStack`),
  chat panel (zavírání).
- `tasks/README.md` — řádek tasku do oblasti design systému.

## Commity

1. `feat(frontend): zásobník vrstev pro Esc (#160, D121)` — krok 1 s testem
2. `fix(frontend): modal, bubliny a nabídky polí přes zásobník vrstev (#160, D121, D122)` — krok 2 (`Modal`, `Popover`, `LookupInput`, `MultiselectInput`)
3. `fix(frontend): drawer, panely, menu a paleta přes zásobník vrstev (#160, D121, D122)` — krok 2, zbytek
4. `feat(frontend): boční chat se zavírá Esc (#160, D123)` — krok 3
5. `docs: zavírání vrstev (#160, D121–D123)` — krok 4 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `npm test` (nový test zásobníku), `npm run build`, `npm run check:i18n`,
  `check-css-tokens.py`, `check-css-units.py`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis), desktop
  1280 px a telefon 390 px. Každý scénář: po jednom Esc je zavřená právě
  jedna vrstva (nejvyšší), po dalším Esc ta pod ní:
  - náhled dokladu: otevřený panel rozhodnutí → Esc zavře panel, modal
    zůstane; s neuloženým rozhodnutím Esc v panelu **neotevře** dotaz
    „Neuložená rozhodnutí“; kebab ⋮ na telefonu totéž;
  - formulář faktury v modalu: otevřená nabídka lookupu (Dodavatel) →
    Esc zavře nabídku, formulář zůstane; další Esc → dotaz „Neuložené
    změny“ (byl-li formulář změněn) → Esc zavře jen dotaz;
  - vnořený formulář („+ Vytvořit nový“) nad formulářem → Esc zavře jen
    vnořený;
  - výběr období v reportu (bublina mimo modal) → Esc zavře bublinu;
  - detail v draweru s otevřeným modalem z akce detailu → Esc zavře modal,
    další Esc drawer;
  - boční chat: Esc zavře panel; klik do seznamu vedle panel nezavře;
    chat otevřený + formulář otevřený z aplikace → Esc zavře formulář,
    další Esc chat;
  - panel vzhledu, uživatelské menu, paleta příkazů, drawer sidebaru na
    telefonu — Esc a klik mimo jako dřív;
  - přejmenování přílohy — Esc zruší přejmenování, formulář zůstane.
- `shpd-ds dataset-e2e` nad jednou veřejnou sadou (desktop) — průchod
  náhledem bez nálezů.

## Hotovo když

- jeden stisk Esc zavře právě jednu vrstvu, naposledy otevřenou, ve všech
  scénářích ověření;
- žádná komponenta vrstvy nemá vlastní posluchač Esc (výjimky v Odchylkách);
- klik mimo zavírá jen dočasné vrstvy; drawer detailu a boční chat klikem
  mimo zůstávají;
- boční chat se zavírá Esc;
- `docs/design-system.md` a `docs/frontend.md` odpovídají implementaci.

## Odchylky

**Zásobník (krok 1)**

- Modul je `frontend/src/utils/layerStack.js`, ne `components/ui/layers.js`:
  čisté JS testované v `tests/Unit` žije v `utils/`. Nic neimportuje — přes
  `Popover` ho sdílí i bundle vývojářského dashboardu, proto se posluchač
  registruje v modulu při prvním `openLayer()`, ne v `AppShell`.
- API: `openLayer({ kind, onEscape })` → `{ remove, isTop, depth }`,
  `hasLayer(kind)`, `handleEscape(event)` (jádro posluchače kvůli testu).
  `isModalOpen()` zanikl bez náhrady — drawer je vrstva.
- Značka spotřebovaného stisku je `event.defaultPrevented`: zásobník ji po
  zavření vrstvy nastaví a Esc, který ji už nese, nechá být. `consumedEscapes`
  zanikl — posluchač je jediný a vrchol čte jednou, takže vrstva otevřená
  uvnitř `onEscape` se týmž stiskem zavřít nemůže.
- Navíc: držená klávesa (`repeat`) další vrstvy nezavírá (dřív jedno podržení
  Esc zavřelo postupně všechny modaly) a Esc uprostřed skládání znaku
  (`isComposing`) se ignoruje.

**Převod vrstev (krok 2)**

- Alt+←/→: `Modal` pouští `onKeydown` jen modalu, který je naposledy
  otevřenou vrstvou (`isTop()`). V otevřeném lookupu se záznam nepřepínal ani
  dřív — `FormDialog` klávesu ignoruje při fokusu v textovém poli; nově se
  nepřepne ani pod otevřenou bublinou, výběrem více hodnot nebo paletou
  (ověřeno paletou nad dialogem řádku faktury). `onKeydown` teď dostane i Esc
  (dřív ho `Modal` nepředal) — `FormDialog` na něj nereaguje.
- Přejmenování přílohy: podle kódu Esc dřív zrušil přejmenování **a** spustil
  zavření formuláře (obsluha pole i posluchač modalu). Nově `handleRenameKeydown` volá
  `preventDefault()` a formulář zůstává.
- Nabídka lookupu se otevírá fokusem pole, takže zavření formuláře klávesou
  z lookupu jsou dva stisky (odpovídá scénáři ověření). Klik do už
  zaostřeného pole nabídku po Esc znovu neotevře — otevře ji psaní nebo ↓,
  stejně jako dřív po výběru hodnoty.
- Paleta příkazů otevřená nad modalem a uživatelské menu v draweru sidebaru
  na telefonu dřív zavíraly Esc obě vrstvy naráz; teď po jedné.
- Grep `'Escape'` ve `src/` (bez `dev/`): zbývá jen `utils/layerStack.js`
  a `form/AttachmentPanel.svelte`. Navíc proti zadání to hlídá
  `tests/Unit/escapeUsage.test.mjs` (vzor `dateFormatUsage.test.mjs`).
- `DevDialog` (mimo rozsah) má dál vlastní posluchač; kebab dashboardu nad
  sdíleným `Popover` jde přes zásobník, Esc i klik mimo ověřeny.

**Boční chat (krok 3)**

- Rozepsaný text **dnes zavřením panelu zanikal** (lokální stav `ChatInput`,
  panel se odmontuje). Po dohodě navíc proti zadání: text drží
  `chatStore.draft` a zavření panelu (× i Esc) ho nechává.
- Text maže **jen odeslání z pole**, ne nová konverzace ani přepnutí
  konverzace: otevření panelu ze sidebaru samo zakládá novou konverzaci, takže
  mazání při nové konverzaci by text ztratilo právě po nechtěném Esc. Důsledek:
  neodeslaný text zůstane v poli i po přepnutí na jinou konverzaci (pole sdílí
  panel, sekce Chat i asistent sekce).
- Beze změny zůstalo, že znovuotevření panelu ze sidebaru začíná novou
  konverzaci — po nechtěném Esc uprostřed rozhovoru se v panelu neukáže
  předchozí vlákno (je v sekci Chat). S Esc to bude častější než s ×;
  případná změna je na samostatné rozhodnutí.

**Nativní prvky a meze**

- `<select>` ve formuláři: Mezerník + Esc v headless Chromiu zavřel jen
  nativní nabídku, formulář zůstal. Výběr data a chování v dalších
  prohlížečích v headless režimu ověřit nejde — ruční krok.
- Fokus uvnitř `<iframe>` (náhled PDF) Esc do aplikace nepustí; vrstva se
  nezavře, stejně jako dřív.

**Dokumentace (krok 4)**

- Kromě `docs/design-system.md` a `docs/frontend.md` upraveny i
  `docs/edit-forms.md` (Vrstvení modalů, Alt+←/→, Esc v lookupu, tabulka
  komponent), `docs/viewer-grid.md` §6, `docs/chat.md` a `docs/dashboard.md`
  — popisovaly zásobník modalů a zavírání panelu.

## Ověření — výsledek

Chromium (Playwright), demo sada IT servis, po každém stisku spočítané
otevřené vrstvy:

| scénář | 1280 px | 390 px |
|---|---|---|
| náhled dokladu: panel rozhodnutí, selhané uložení → Esc v panelu bez dotazu, pak dotaz „Neuložená rozhodnutí“ a Esc jen dotaz | ✓ | panel ✓ |
| náhled dokladu: kebab ⋮ | — | ✓ |
| nová faktura: nabídka lookupu → formulář → dotaz „Neuložené změny“ → jen dotaz | ✓ | ✓ |
| vnořený formulář „+ Vytvořit nový“ | ✓ | ✓ |
| výběr období v reportu (Esc, klik mimo) | ✓ | — |
| výběr více hodnot (Esc, klik mimo) | ✓ | — |
| detail v draweru + modal z akce (1000 px), klik do seznamu drawer nezavře | ✓ | — |
| boční chat: Esc, Esc při psaní (text zůstane), klik vedle nezavře, formulář nad chatem | ✓ | Esc ✓ |
| paleta (Esc, klik mimo, nad modalem), uživatelské menu, panel vzhledu | ✓ | — |
| drawer sidebaru, uživatelské menu nad ním, klik na překryv | — | ✓ |
| přejmenování přílohy (Došlá pošta) | ✓ | — |
| Alt+←/→ v dialogu řádku pod paletou | ✓ | — |

`shpd-ds dataset-e2e tests/datasets/cz/vat-payer` (desktop): 24 z 24 karet,
0 chyb prohlížeče, `dataset-verify` 6× ok.

## Ruční ověření (2026-11-18, Firefox, telefon)

- Náhled dokladu (panel rozhodnutí, bez dotazu „Neuložená rozhodnutí“),
  nová faktura přijatá (nabídka lookupu → formulář → dotaz → jen dotaz),
  vnořený formulář, kalendář data a rozbalovací výběr ve Firefoxu, výběr
  období v reportu (Esc i klik mimo), detail v draweru (zavře jen Esc,
  klik vedle nic), boční chat (klik vedle nezavírá, Esc zavře, rozepsaný
  text zůstává i v sekci Chat, modal nad chatem zavře Esc jako první),
  paleta, uživatelské menu, panel vzhledu, přejmenování přílohy —
  v pořádku.
- Uživatelské menu: Esc v nabídce jazyka zavře celé menu — nabídka je
  součástí menu, ne vrstva nad ním; ponecháno.
- Telefon: drawer sidebaru, chat, kebab v pořádku. **Nález:** v náhledu
  dokladu v tabu PDF klepnutí mimo bublinu nefunguje (klepnutí zůstane ve
  vloženém dokumentu) → D124, `tasks/design-system-iframe-layers.md`.
- Znovuotevření chatu ze sidebaru začíná novou konverzaci — ponecháno
  (panel pro krátké dotazy, historie v sekci Chat).
- PDF na telefonu se ve vloženém náhledu nevykreslí → #161.
