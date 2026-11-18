# Design system — jeden zásobník vrstev pro Esc, klik mimo, boční chat (D121–D123)

**Stav:** naplánováno — #160, D121–D123

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

_(doplní implementace)_
