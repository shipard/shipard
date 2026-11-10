# Chat — stránka konverzací roluje celá, pole zprávy uříznuté

**Stav:** naplánováno — 2026-11-10 (#133 Další nesoulady)

## Kontext / Cíl

Bod checklistu #133 „Další nesoulady“, nalezený při ručním ověření #138
task 4 (chyba tam byla už před ním). Na stránce chatu (položka navigace
typu `chat`, `ChatView`) jsou po otevření konverzace pole zprávy
a tlačítko Odeslat dole uříznuté a roluje celá obsahová oblast i se
seznamem konverzací. Platí na desktopu (písmo prohlížeče 16 i 20 px)
i na telefonu.

Z kódu příčina jasná není: řetěz výšky `ContentArea` → `ChatView` →
`ChatThread` vypadá jako u vieweru, který funguje. Vyloučené jsou stavové
pruhy nad obsahem (ukázková sada je nemá) a mobilní lišta nad vláknem
(chyba je i na desktopu, kde lišta není).

**Cíl:** seznam konverzací a vlákno rolují každé zvlášť jako seznam
a detail vieweru; pole zprávy je vždy celé vidět dole; obsahová oblast
(`.shpd-content`) na stránce chatu neroluje. Platí ve všech třech
shellech, na desktopu i telefonu, při písmu 16 i 20 px.

**Postup:** nejdřív změřit v prohlížeči, který prvek přetéká, teprve
pak opravit — žádná oprava naslepo.

**Mimo rozsah:**

- boční chat panel (`ChatPanel`) — funguje; mění se jen tehdy, když
  oprava `ChatThread` umožní zrušit jeho přebití (krok 2);
- vzhled bublin, karet sekce, pole zprávy (barvy, písmo, hex) — jiné
  body checklistu #133;
- automatické rolování na konec vlákna — chování beze změny.

## Návaznost

- **#133** — D42 (breakpoint shellu `48rem`, mobilní přepínání), D43;
  checklist „Další nesoulady“ (bod Chat)
- **#138** task 4 — při jeho ověření chyba nalezena; task 5 (D58) —
  vzor řetězu výšky vieweru

## Před implementací přečti

- `docs/design-system.md` §8 Layout konvence — Viewer (seznam + detail),
  Responzivita
- `frontend/src/components/layout/ContentArea.svelte` (`.shpd-content`
  je rolovací kontejner, `overflow-y: auto`)
- `frontend/src/components/shells/SidebarShell.svelte`,
  `ClassicShell.svelte`, `WildShell.svelte` (`.shpd-shell__main` a obdoby)
- `frontend/src/components/chat/ChatView.svelte`, `ChatThread.svelte`,
  `ConversationList.svelte`, `ChatInput.svelte`, `ChatPanel.svelte`
  (komentář „ChatThread má height: 100 % — dej mu určenou výšku flex
  itemu“ a přebití `:global(.shpd-thread)`)
- `frontend/src/components/viewer/Viewer.svelte` — `.shpd-viewer`,
  `.shpd-viewer__body` (funkční vzor)
- jak se v předchozích taskech #138 ověřovalo v Chromiu přes Playwright
  nad zdrojem dat `free`: `tasks/design-system-dimensions.md` (Ověření,
  písmo 20 px přes CDP `Page.setFontSizes`),
  `tasks/design-system-narrow-detail.md`

## Rozhodnutí

Nové rozhodnutí D se nečeká — jde o chybu proti existujícímu pravidlu
(seznam a detail rolují každý zvlášť, §8). Kdyby měření ukázalo, že
oprava mění sdílené pravidlo layoutu (např. obsahová oblast přestane
rolovat pro všechny typy obsahu), zastav se a popiš to v odchylkách —
rozhodne se v #133.

## 1. Reprodukce a měření

Zdroj dat s třídou `free` (`shpd-server ds-list --json`) se stránkou
chatu v navigaci a aspoň jednou konverzací. Pokud žádná není, založ ji
v UI jednou zprávou (`free` zdroj má ve vývoji serverové AI připojení);
pokud je potřeba vlákno delší než okno, pošli pár zpráv. Do veřejných
textů jen prefix ID zdroje.

Chromium (Playwright), přihlášení jako v předchozích taskech #138.
Kombinace:

| Okno | Písmo | Shell |
|------|-------|-------|
| 1280 × 800 | 16 px | sidebar, classic, wild |
| 1280 × 800 | 20 px | sidebar |
| 390 × 844 | 16 px | sidebar (mobil: seznam → vlákno) |

Pro každou kombinaci dvakrát — **před otevřením konverzace** (jen
stránka chatu) a **po** (vlákno načtené, po automatickém rolování na
konec) — změř u každého prvku řetězu:

`.shpd-shell__main` (a obdoby v classic / wild), `.shpd-content`,
`.shpd-chat`, `.shpd-chat__list`, `.shpd-convlist`,
`.shpd-convlist__items`, `.shpd-chat__thread`, `.shpd-chat__mobile-bar`
(mobil), `.shpd-thread`, `.shpd-thread__messages`, `.shpd-thread__scope`
(když je), `.shpd-chat-input`

hodnoty: `getBoundingClientRect()` (top, height), `clientHeight`,
`scrollHeight`, `scrollTop`, a z `getComputedStyle` `height`,
`min-height`, `flex`, `overflow-y`, `display`.

Hledej hlavně:

- **který prvek je vyšší než jeho rodič** — první takový v řetězu shora
  je příčina;
- **nenulový `scrollTop` u prvku s `overflow: hidden`** — takový prvek
  se dá rolovat programově (`scrollTop`, `scrollIntoView`, `focus()`);
  na stránce to vypadá jako „roluje celá oblast“, i když výšky sedí;
- **rozdíl před / po otevření** — co se při načtení konverzace mění
  (výška seznamu, karty sekce `SectionCards`, štítek rozsahu, výška pole
  zprávy).

Výsledek měření zapiš do sekce **Zjištění** na konci tohoto tasku
(tabulka prvků s výškami pro jednu desktopovou a jednu mobilní
kombinaci + jednou větou příčina) dřív, než začneš opravovat.

**Známý dílčí problém (ověř měřením):** na mobilu je v
`.shpd-chat__thread` nad `ChatThread` lišta „zpět na seznam“ a
`.shpd-thread` má `height: 100%` — dohromady o výšku lišty víc než
sloupec. Na desktopu lišta není, takže tohle samo chybu na desktopu
nevysvětlí.

## 2. Oprava

Podle zjištění. Směr, pokud měření nepřinese nic, co by ho vyvracelo:

- `ChatThread` jako flex item rodiče (`flex: 1; min-height: 0`) místo
  `height: 100%`; rodiče (`.shpd-chat__thread`, `.shpd-chat-panel__body`)
  jsou flex sloupce. Pak lišta na mobilu nepřetéká a `ChatPanel` už
  nepotřebuje přebití `:global(.shpd-thread)` — smaž ho i s komentářem.
- Řetěz výšky stránky chatu stejný jako u vieweru (`.shpd-viewer`):
  pokud `.shpd-chat` potřebuje jiné nastavení vůči `.shpd-content`,
  udělej ho stejně jako viewer, ne vlastní cestou.
- Pokud příčinou je programové rolování (nenulový `scrollTop` u prvku
  s `overflow: hidden`), oprav místo, které roluje (rolovat jen
  `.shpd-thread__messages`), ne CSS kolem.

Oprava nesmí změnit:

- automatické rolování vlákna na konec při nové zprávě a při streamu;
- mobilní přepínání seznam / vlákno (D42);
- boční `ChatPanel` (otevření z launcheru dashboardu, přežití navigace);
- ostatní typy obsahu v `ContentArea` (viewer, dashboard, nastavení,
  reporty) — `.shpd-content` se pro ně nemění.

## 3. Dokumentace

- `docs/design-system.md` §8 — u Vieweru (seznam + detail) jedna věta,
  že stránka chatu používá stejný řetěz výšky (seznam a vlákno rolují
  každé zvlášť, pole zprávy přilepené dole); pokud se zjištění týká
  obecné pasti (např. `height: 100%` uvnitř flex sloupce s dalším
  prvkem), krátce ji zapsat jako pravidlo.
- Checklist #133: bod Chat se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `fix(frontend): stránka chatu — seznam a vlákno rolují zvlášť (#133)`
   — oprava + sekce Zjištění v tasku
2. `docs: řetěz výšky stránky chatu (#133)` — krok 3 + `**Stav:**`
   tohoto tasku + `python3 scripts/tasks-index.py`

Je-li oprava jen pár řádků CSS, smí být oba kroky v jednom commitu.

## Ověření

- `cd frontend && npm run build`; `python3 scripts/check-css-units.py`,
  `python3 scripts/check-css-tokens.py`.
- Měření z kroku 1 znovu po opravě, všechny kombinace: `.shpd-content`
  na stránce chatu má `scrollHeight == clientHeight` a `scrollTop 0`;
  `.shpd-chat-input` je celé uvnitř okna; žádný prvek s
  `overflow: hidden` nemá nenulový `scrollTop`; vlákno delší než okno
  roluje jen `.shpd-thread__messages`, dlouhý seznam konverzací jen
  `.shpd-convlist__items`. Hodnoty po opravě zapsat do Zjištění.
- Nová zpráva (nebo stream) vlákno dorolí na konec.
- Mobil: seznam → konverzace → zpět; lišta a pole zprávy vidět, nic
  nepřetéká.
- Boční `ChatPanel` z launcheru dashboardu: vlákno roluje uvnitř panelu,
  pole zprávy dole, přežije navigaci na viewer.
- Viewer, dashboard, Nastavení a report v obsahové oblasti rolují jako
  předtím (snímky před a po při 1280 px).

## Hotovo když

- [ ] Sekce Zjištění popisuje příčinu podloženou měřením
- [ ] Na stránce chatu neroluje obsahová oblast; seznam konverzací
      a vlákno rolují každé zvlášť; pole zprávy je celé vidět — ve třech
      shellech, na desktopu i telefonu, při písmu 16 i 20 px
- [ ] `ChatThread` funguje v `ChatView` i `ChatPanel` bez přebití zvenku
      (pokud to zjištění nevyvrátí — pak důvod v odchylkách)
- [ ] Automatické rolování na konec, mobilní přepínání a boční panel
      beze změny
- [ ] Dokumentace podle kroku 3, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- Prvek s `overflow: hidden` je pořád rolovací kontejner: `scrollTop`,
  `scrollIntoView()` i `focus()` ho posunou, jen ho uživatel nemůže
  vrátit kolečkem. Výšky pak sedí a obsah je přesto posunutý.
- `height: 100%` uvnitř flex sloupce, kde je i jiný prvek (lišta, štítek),
  dá dohromady víc než 100 %. Ve flex sloupci patří `flex: 1;
  min-height: 0`.
- Flex item má výchozí `min-height: auto` = výška obsahu; bez
  `min-height: 0` (nebo `overflow` jiného než `visible`) se nezmenší pod
  obsah a přeteče rodiče.
- Mobilní režim se řídí šířkou okna (`layoutStore.isMobile`, `48rem`) —
  v Playwrightu nastav velikost okna před načtením stránky, ne až potom.
- Měř až po dokončení automatického rolování vlákna (po `$effect`
  a vykreslení zpráv) — jinak se `scrollTop` zachytí v půlce.

## Zjištění

*(doplní implementace v kroku 1 a v Ověření)*
