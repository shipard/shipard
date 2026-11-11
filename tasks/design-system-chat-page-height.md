# Chat — stránka konverzací roluje celá, pole zprávy uříznuté

**Stav:** hotovo — 2026-11-11 (#133 Další nesoulady); příčina byla ve stavových pruzích v `ContentArea`, ne v chatu — pruhy přesunuty nad rolovací oblast (varianta A, viz Zjištění a Odchylky); ověřeno v Chromiu s podvrženými daty chatu, ruční ověření chatu na zdroji s pruhem „kopie reálných dat“ prošlo

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

- [x] Sekce Zjištění popisuje příčinu podloženou měřením
- [x] Na stránce chatu neroluje obsahová oblast; seznam konverzací
      a vlákno rolují každé zvlášť; pole zprávy je celé vidět — ve třech
      shellech, na desktopu i telefonu, při písmu 16 i 20 px
- [x] `ChatThread` funguje v `ChatView` i `ChatPanel` bez přebití zvenku
      (pokud to zjištění nevyvrátí — pak důvod v odchylkách)
- [x] Automatické rolování na konec, mobilní přepínání a boční panel
      beze změny
- [x] Dokumentace podle kroku 3, `**Stav:**` aktualizovaný, index tasků
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

### Měření před opravou (2026-11-11)

Chromium (Playwright), volný zdroj dat `zey0-g`, build z HEAD. Server nemá
AI klíč, chat na volných zdrojích neodpoví — data chatu (5 konverzací,
vlákno 8 zpráv s tabulkami, odpověď na novou zprávu jako SSE) jsou proto
podvržená přes `page.route` na `/_chat/conversations…`; stránka, shell
a komponenty jsou skutečné. Stavové pruhy jsou navozené úpravou odpovědi
`/_app/info` (`realCopy: true`, `cronEnabled: false`).

**Příčina:** stavový pruh nad obsahem (`.shpd-content__banners`) leží uvnitř
rolovací `.shpd-content` a kořen stránky pod ním má `height: 100%` — obsah
je dohromady o výšku pruhů vyšší než oblast, takže spodek (pole zprávy)
je uříznutý a `.shpd-content` roluje i se seznamem konverzací. Řetěz výšky
samotného chatu je v pořádku.

**Bez pruhů chyba není** — po otevření konverzace, ve všech kombinacích:

| Kombinace | `.shpd-content` client / scroll | `.shpd-chat` | pole zprávy dole | roluje jen |
|---|---|---|---|---|
| 1280 × 800, 16 px, sidebar | 800 / 800 | 800 | 800, celé v okně | `.shpd-thread__messages` |
| 1280 × 800, 16 px, classic | 744 / 744 | 744 | 800, celé v okně | `.shpd-thread__messages` |
| 1280 × 800, 16 px, wild | 756 / 756 | 756 | 800, celé v okně | `.shpd-thread__messages` |
| 1280 × 800, 20 px, sidebar | 800 / 800 | 800 | 800, celé v okně | `.shpd-thread__messages` |
| 390 × 844, 16 px, sidebar | 788 / 788 | 788 | 844, celé v okně | `.shpd-thread__messages` |

Žádný prvek s `overflow: hidden` nemá nenulový `scrollTop`, žádný prvek
řetězu není vyšší než rodič, nová zpráva vlákno dorolí na konec.

**S pruhy** (oba pruhy; jeden pruh „kopie reálných dat“ má 31 px a dělá
totéž v menším) — 1280 × 800, 16 px, sidebar, po otevření konverzace:

| Prvek | top | výška | client | scroll | scrollTop | overflow-y | flex |
|---|---|---|---|---|---|---|---|
| `.shpd-shell__main` | 0 | 800 | 800 | 800 | 0 | hidden | 1 1 0% |
| `.shpd-content` | 0 | 800 | 800 | **883** | 0 | auto | 1 1 0% |
| `.shpd-content__banners` | 0 | 83 | 83 | 83 | 0 | visible | — |
| `.shpd-chat` | **83** | 800 | 800 | 800 | 0 | hidden | — |
| `.shpd-chat__list` | 83 | 800 | 800 | 800 | 0 | hidden | 0 0 auto |
| `.shpd-convlist` | 83 | 800 | 800 | 800 | 0 | hidden | — |
| `.shpd-convlist__items` | 140 | 743 | 743 | 743 | 0 | auto | 1 1 0% |
| `.shpd-chat__thread` | 83 | 800 | 800 | 800 | 0 | hidden | 1 1 0% |
| `.shpd-thread` | 83 | 800 | 800 | 800 | 0 | hidden | — |
| `.shpd-thread__messages` | 83 | 701 | 701 | 1987 | 1286 | auto | 1 1 0% |
| `.shpd-chat-input` | 784 | 99 | 98 | 98 | 0 | visible | — |

Pole zprávy končí na 883 px v okně vysokém 800 px. Po odeslání zprávy
(fokus v poli) má `.shpd-content` `scrollTop` 83 — celá stránka chatu
včetně seznamu konverzací je posunutá pod přilepené pruhy.

390 × 844, 16 px, sidebar, po otevření konverzace (pruhy se lámou, 146 px):

| Prvek | top | výška | client | scroll | scrollTop |
|---|---|---|---|---|---|
| `.shpd-shell__main` | 56 | 788 | 788 | 788 | 0 |
| `.shpd-content` | 56 | 788 | 788 | **934** | 0 |
| `.shpd-content__banners` | 56 | 146 | 146 | 146 | 0 |
| `.shpd-chat` | **202** | 788 | 788 | 788 | 0 |
| `.shpd-chat__thread` | 202 | 788 | 788 | 788 | 0 |
| `.shpd-chat__mobile-bar` | 202 | 41 | 40 | 40 | 0 |
| `.shpd-thread` | 243 | 747 | 747 | 747 | 0 |
| `.shpd-thread__messages` | 243 | 648 | 648 | 3427 | 2779 |
| `.shpd-chat-input` | 891 | 99 | 98 | 98 | 0 |

Pole zprávy končí na 990 px v okně vysokém 844 px; po odeslání má
`.shpd-content` `scrollTop` 146.

**Totéž má viewer** (Faktury přijaté, 1280 × 800): bez pruhů
`.shpd-content` 800 / 800, s jedním pruhem 800 / 831, se dvěma 800 / 883;
`.shpd-viewer` končí na 831, resp. 883 px. U vieweru je to méně vidět —
uříznutý je jen spodek seznamu a detailu.

**Známý dílčí problém se nepotvrdil:** na mobilu má `.shpd-thread` 747 px
= sloupec 788 px − lišta 41 px. `height: 100%` se jako flex item s
`overflow: hidden` zmenší, nic nepřetéká.

**Pruh pojistky pošty dělá totéž i bez stavových pruhů:** Odeslaná pošta
na ukázkové sadě měla `.shpd-content` 800 / 831 (pruh 31 px).

### Oprava a měření po ní (2026-11-11)

- `ContentArea`: kontejner `.shpd-content__banners` (stavové pruhy + pruh
  pojistky pošty) je sourozenec **před** `<main class="shpd-content">`,
  `flex-shrink: 0`, bez `position: sticky`.
- `ChatThread`: `.shpd-thread` je `flex: 1; min-height: 0` místo
  `height: 100%`; přebití `:global(.shpd-thread)` v `ChatPanel`
  a `SectionAssistant` smazáno.

Stránka chatu po otevření konverzace a po odeslání zprávy — v každém řádku
`.shpd-content` `scrollTop` 0, žádný prvek s `overflow: hidden` nemá
nenulový `scrollTop`, roluje jen `.shpd-thread__messages` a po nové zprávě
je na konci:

| Kombinace | bez pruhů: `.shpd-content` client / scroll | s oběma pruhy: client / scroll | pole zprávy dole (obojí) |
|---|---|---|---|
| 1280 × 800, 16 px, sidebar | 800 / 800 | 717 / 717 | 800, celé v okně |
| 1280 × 800, 16 px, classic | 744 / 744 | 682 / 682 | 800, celé v okně |
| 1280 × 800, 16 px, wild | 756 / 756 | 694 / 694 | 800, celé v okně |
| 1280 × 800, 20 px, sidebar | 800 / 800 | 697 / 697 | 800, celé v okně |
| 390 × 844, 16 px, sidebar | 788 / 788 | 642 / 642 | 844, celé v okně |

- Mobil seznam → konverzace → zpět: lišta 41 px, vlákno 747 px (s pruhy
  601 px), pole zprávy v okně, po návratu seznam přes celou šířku.
- 40 konverzací: roluje jen `.shpd-convlist__items` (743 / 2280, s pruhy
  660 / 2280), `.shpd-content` ne.
- Boční `ChatPanel` z launcheru dashboardu (desktop i mobil): vlákno
  vyplní tělo panelu, roluje uvnitř a je na konci, pole zprávy u spodní
  hrany okna; panel přežije navigaci na viewer.
- AI záložka wild shellu: totéž — vlákno vyplní tělo, pole zprávy dole.
- Ostatní obsah při 1280 px, snímky před a po: **bez pruhů** dashboard
  (nahoře i dorolovaný), viewer, report a Nastavení pixelově shodné;
  Odeslaná pošta má viewer o 31 px nižší (dřív přetékal). **S pruhy**
  dashboard a Nastavení shodné až na vyhlazení písma (12–42 px
  z milionu), rolují dál v `.shpd-content` (dashboard 717 / 858); viewer,
  report a Odeslaná pošta už nepřetékají (717 / 717, resp. 686 / 686).
- `npm run build`, `check-css-units.py`, `check-css-tokens.py` prošly.

## Odchylky od zadání

- **Zadání vylučovalo stavové pruhy** („ukázková sada je nemá“) — na
  ukázkové sadě se ale chyba neprojeví vůbec. Projeví se na zdroji dat
  s pruhem (kopie reálných dat, vypnutý cron, jen ke čtení). Na kterém
  zdroji dat byla chyba původně nalezena, měření neověřilo — volné zdroje
  na vývojovém serveru nemají AI klíč, chat tam neodpoví.
- **Oprava je ve sdíleném `ContentArea`, ne v chatu** — příčina se týká
  každého obsahu s `height: 100%` (změřeno u chatu, vieweru, reportu
  a Odeslané pošty). Podle sekce Rozhodnutí se práce zastavila; z možností
  - **A — pruhy mimo rolovací oblast**: `ContentArea` vykreslí stavové
    pruhy a pruh pojistky pošty před `<main class="shpd-content">`, ne
    uvnitř; rodiče jsou ve všech třech shellech flex sloupce, pruhy
    nepotřebují `position: sticky`. Pravidla `.shpd-content` ani žádný
    typ obsahu se nemění, pruhy zůstávají stále vidět;
  - **B — `.shpd-content` jako flex sloupec**, kořeny obsahu `flex: 1;
    min-height: 0` místo `height: 100%` (zasahuje každý typ obsahu)

  byla 2026-11-11 vybrána **A**. Číslo rozhodnutí v #133 zatím nemá.
- **Pruh pojistky pošty** dřív roloval s obsahem (ležel pod přilepenými
  stavovými pruhy), teď je s nimi v jednom kontejneru nad obsahem a je
  vidět stále.
- **`SectionAssistant`** (AI záložka wild shellu) měl stejné přebití
  `:global(.shpd-thread)` jako `ChatPanel`; zadání ho nezmiňuje, smazáno
  obojí.
- **Seznam konverzací beze změny** — `.shpd-chat__list` a `.shpd-convlist`
  (`height: 100%` bez sourozence) měření nevyvrátilo, zůstávají.
- **Data chatu byla při měření podvržená** (server nemá AI klíč) — chat na
  zdroji dat s pruhem „kopie reálných dat“ pak prošel ručním ověřením.
