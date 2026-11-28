# Nativní aplikace — task 4a: skládání ESC/POS, profily tiskáren, účtenka prodejky (#177)

**Stav:** naplánováno — rozhodnutí #177 D26, U5, U13 a upřesnění U27–U35 (schválená 2026-11-28), připraveno k implementaci

> Task pro Claude Code (7 commitů). Design: issue #177. Jen serverová část
> skládání tisku pro účtenkové tiskárny a rozšíření náhledu ve frontendu;
> **tiskové úlohy, směrování a tisk z UI jsou task 4b**. Výsledek jde
> ověřit bez aplikace i bez tiskárny: CLI vyrobí bajty a náhled ESC/POS
> (task 2) z nich ukáže účtenku.

## Před implementací přečti

- `docs/prints.md` §1–§6, §10 (jak přidat tisk), §11 (testy)
- `docs/native-bridge.md` §6 (profily tiskáren), §9a (simulace, náhled
  ESC/POS a čísla kódových stránek)
- `frontend/src/native/escpos/decode.js` a `codePages.js` — podmnožina
  příkazů, kterou renderer nesmí překročit (U13)
- `modules/docs/cashRegister/prints/receipt/` a
  `modules/docs/core/prints/_layout/` — A4 prodejka, katalogy překladů
- `src/Core/Prints/` — `PrintRunner`, `PrintFormat`, `PrintRenderer`,
  `PrintDefinitionLoader`, sandbox a `PrintTwigExtension`

## Kontext

Tisk na účtenkovou tiskárnu je další **výstupní formát tisků** (D26):
stejný builder a stejná `PrintData` jako PDF, vlastní šablona a renderer,
který z ní složí bajty ESC/POS podle profilu tiskárny. Starý Shipard
vystačil s jedním univerzálním ovladačem pro všechny tiskárny (úzké
i široké, LAN, Wi-Fi, Bluetooth), vždy v kódové stránce CP852:

```
reset          ESC @                    27 64
boldOn / Off   ESC E 128 / ESC E 0      27 69 128 / 27 69 0
condensedOn    ESC ! 7 / ESC ! 0        27 33 7 / 27 33 0
twiceOn / Off  GS ! 17 / GS ! 0         29 33 17 / 29 33 0
cutPaper       GS V 65 5                29 86 65 5
openDrawer     ESC p 48 55 121          27 112 48 55 121
cp852          FS . + ESC t 18          28 46 27 116 18
```

Tenhle task z něj vychází (U27, U28). POK z evidence tržeb a uživatelské
texty na účtence přijdou později jako obecný aparát pro všechny tisky,
logo (rastrový obrázek) také.

## Upřesnění (potvrzená 2026-11-28)

- **U27** — Jeden univerzální ovladač jako ve starém Shipardu. Každá
  účtenka začíná `ESC @`, `FS .` (zruší režim dvoubajtových znaků, ve
  kterém startují tiskárny pro asijský trh) a `ESC t 18` (CP852).
  Kódová stránka ve v1 **jen CP852**; tabulka Windows-1250 v náhledu
  zůstává, renderer ji nemá.
- **U28** — Bajty příkazů nejsou v kódu, ale v **definici ovladače** jako ve
  starém Shipardu: cfgItem `core.prints.printerDrivers`, ve v1 jeden ovladač
  `escpos-generic`, na který oba profily odkazují klíčem `driver`. Výchozí
  hodnoty podle normy Epson:
  - tučně `ESC E 1` / `ESC E 0` — starý Shipard posílal `128`; podle normy
    rozhoduje nejnižší bit, `1` funguje na tiskárnách podle normy
    i benevolentních;
  - malé písmo (font B) `ESC ! 1` / `ESC ! 0` — starý `7` má bity 1–2
    nedefinované;
  - velikost `GS ! n` (dvojitá = `0x11`), ořez `GS V 65 5`, šuplík
    `ESC p 48 55 121`, inicializace a kódová stránka podle U27.

  Když fyzická tiskárna potřebuje jiné bajty, mění se definice, ne kód.
  Test hlídá, že výchozí ovladač zůstává v podmnožině náhledu (U13);
  ovladač, který ji opustí, musí rozšířit i náhled.
- **U29** — Katalog profilů ve v1 jen dva, oba CP852 a s funkcemi `cut`
  a `drawer` (jako starý ovladač — tiskárna bez nože nebo šuplíku příkaz
  ignoruje), bez `qr`:

  | Profil | `columns` | `columnsSmall` | `dotsPerLine` |
  |---|---|---|---|
  | `escpos-80-cp852` | 42 | 56 | 512 |
  | `escpos-58-cp852` | 32 | 42 | 384 |

  Šířky jsou parametry profilu v definici. Na široké tiskárně měl starý
  Shipard 42 znaků; tiskárny s 576 body tisknou 48 / 64 — když 42 v praxi
  nesedí, mění se definice nebo přibude profil.

  Neznámé `profileId` → `escpos-58-cp852` (nejužší, vejde se všude)
  a varování. Pole `columnsSmall` je v kontraktu nové (§6, přidání pole
  verzi nemění). `models` zůstává prázdné, dokud profil někdo na
  konkrétním modelu neověří.
- **U30** — Náhled ve frontendu (task 2) se rozšíří o `FS .` (bez výstupu)
  a `ESC ! n` (bit 0 = malé písmo, zalomení podle `columnsSmall`);
  `decodeEscPos` dostane volbu `columnsSmall`. Renderer tak zůstává
  v podmnožině náhledu (U13).
- **U31** — Šablona účtenky je Twig v sandboxu, jehož výstup je XML
  se značkami níže. Neznámá značka nebo atribut = chyba renderu s číslem
  řádku. QR v profilu bez funkce `qr` se vytiskne jako text.
- **U32** — Kódování textu vlastní tabulkou CP852 v PHP (ne `iconv`, které
  se chová podle knihovny systému). Nezlomitelná mezera z formátovacích
  filtrů se tiskne jako mezera, ale řádek se na ní nezalomí. Znak mimo
  CP852 se převede bez diakritiky (NFD a odstranění značek), jinak `?`;
  `€` → `EUR`. Kodér je postavený na víc kódových stránek (tabulka na
  stránku); ve v1 jen CP852, obecné řešení pro další stránky později.
- **U33** — Společné vzory `tests/Fixtures/EscPos/`: bajty (`*.bin`)
  a očekávaný náhled (`*.preview.json` — řádky se stylem a značky). PHP
  renderer je musí vyrobit **bajt po bajtu**, JS náhled z nich přečíst
  **očekávané řádky**. Renderer a náhled se tak nerozejdou potichu.
- **U34** — Účtenka prodejky nese **stejné údaje daňového dokladu jako A4
  prodejka**: titulek podle varianty (stejné klíče `title.*`), dodavatel
  s IČO a DIČ, odběratel jen když ho doklad má, číslo, datum vystavení,
  DUZP podle stejné podmínky jako A4, pokladna, položky se sazbou DPH,
  u plátce rekapitulace DPH, celkem, způsob úhrady, zaokrouhlení,
  vystavil. Stornovaná prodejka nese text vodoznaku (`meta.watermark`) jako
  výrazný řádek nahoře. Bez loga, uživatelských textů a POK.
- **U35** — Deklarace tisku dostane klíč `output`: `pdf` (výchozí) nebo
  `escpos`. Tisk s `escpos` nesmí mít `paper`, `sendPurpose` ani
  `textSlots` (chyba loaderu). `PrintFormat` dostane `escpos` (bajty)
  a `markup` (výstup šablony, jen CLI); `escpos` a `markup` jen pro tisky
  s `output: escpos`, `pdf` a `html` jen pro ostatní. REST `/_prints`
  formát `escpos` nenabízí — účtenka jde na tiskárnu tiskovou úlohou
  (task 4b).

## Rozsah

**V rozsahu:** kodér ESC/POS a CP852, šablonový jazyk a sazba účtenky,
`EscPosRenderer`, katalog profilů a `GET /_devices/printer-profiles`,
výstup `escpos` v `PrintRunner` a CLI, účtenka prodejky
`docs.cashRegister.receiptPos`, zkušební účtenka profilu (pro task 4b),
rozšíření náhledu, společné vzory, dokumentace.

**Mimo rozsah:** tiskové úlohy `/_print-jobs`, směrování podle role,
tisk z UI, Zkušební tisk a šuplík v panelu, druhá tiskárna v simulaci
(task 4b); QR na účtence, logo, uživatelské texty, POK, Windows-1250.

## Šablonový jazyk účtenky (U31)

```xml
<receipt>
  <text align="center" bold="1" size="2x2">Prodejka – daňový doklad</text>
  <text align="center">Firma s.r.o. · IČO 12345678 · DIČ CZ12345678</text>
  <line/>
  <row><l>Rohlík tukový</l></row>
  <row font="small"><l>  2 × 3,50 · 12 %</l><r>7,00</r></row>
  <line char="="/>
  <row bold="1" size="2x1"><l>Celkem</l><r>7,00 Kč</r></row>
  <feed lines="2"/>
  <cut/>
</receipt>
```

| Značka | Atributy | Výstup |
|---|---|---|
| `receipt` | — | kořen; renderer před obsah dá inicializaci (U27) |
| `text` | `align` (`left` / `center` / `right`), `bold` (`0`/`1`), `size` (`WxH`, 1–8), `font` (`normal` / `small`) | text zalomený podle šířky (slova; slovo delší než řádek se rozdělí) |
| `row` | `bold`, `size`, `font` | dvojice `l` (vlevo, zalamuje se) a `r` (vpravo, na posledním řádku levého textu; nevejde-li se, na samostatný řádek vpravo); `r` je nepovinné |
| `line` | `char` (jeden znak, výchozí `-`) | dělicí čára na celou šířku v aktuálním písmu |
| `feed` | `lines` (1–10) | `ESC d n` |
| `qr` | — (obsah = data) | `GS ( k`, bez funkce `qr` v profilu text |
| `cut` | — | příkaz ořezu z ovladače, bez funkce `cut` posun o 3 řádky |
| `drawer` | — | příkaz šuplíku z ovladače, bez funkce `drawer` nic |

Šířka řádku: `columns`, s `font="small"` `columnsSmall`, vydělená šířkou
znaku z `size`. Styl platí pro celý prvek (náhled nese jeden styl na řádek)
a po prvku se vrací na výchozí. Prázdné řádky a mezery mezi značkami se
ignorují. Escapování: Twig autoescape `html` — výstup hodnot je platné XML.

## Server

Třídy v `src/Core/Prints/EscPos/`:

- `EscPosDriver` — definice ovladače z cfgItem `core.prints.printerDrivers`
  (`modules/core/prints/config/printerDrivers.jsonc`); jediné místo, odkud
  kodér bere bajty příkazů (U27, U28). Profil v
  `printerProfiles.jsonc` odkazuje na ovladač klíčem `driver`.
- `Cp852` — tabulka a `encode(string): string` podle U32.
- `PrinterProfile`, `PrinterProfiles` — čtení cfgItem
  `core.prints.printerProfiles` (`modules/core/prints/config/printerProfiles.jsonc`),
  `get(?string $id): array{profile, warning}` se záložním profilem (U29).
- `ReceiptMarkupParser` — XML → strom prvků, validace značek a atributů (U31).
- `ReceiptLayout` — sazba: zalomení, `row`, `line`, šířky podle profilu
  a písma; výstup řádky se stylem + příkazy (`feed`, `qr`, `cut`, `drawer`).
- `EscPosRenderer` — `render(string $markup, PrinterProfile): EscPosOutput`
  (`bytes`, `warnings`); parser → sazba → kodér.
- `PrinterTestReceipt` — zkušební účtenka profilu: název a parametry
  profilu, pravítko na šířku v obou písmech, „Příliš žluťoučký kůň úpěl
  ďábelské ódy“ velkými i malými, tučně, dvojitá velikost, čas, ořez.
  Pro druh úlohy `test` v tasku 4b; šuplík = příkaz `openCashDrawer`
  ovladače.

**Napojení na tisky** (U35):

- `PrintDefinitionLoader` čte `output`, kontroluje zakázané klíče.
- `PrintFormat` += `Escpos`, `Markup`; `PrintRunner::run(..., PrintFormat,
  ?language, ?PrinterProfile)` — u `escpos` / `markup` vykreslí šablonu
  `page.escpos.twig` ve stejném sandboxu a s katalogy jako PDF, `escpos`
  ji předá `EscPosRenderer`. `renderData()` (hotová `PrintData`) totéž.
- **Detail záznamu**: `ViewerController::detail()` — akce `print` a `send`
  berou jen tisky s `output: pdf`. Bez toho by akce Tisk u prodejky nabídla
  i `receiptPos` a pokusila se z něj udělat PDF. Tisky `escpos` dostanou
  v detailu vlastní akci až v tasku 4b.
- **CLI** `print-run`: `--format=escpos --profile=<id> --output=<soubor>`
  (bez `--profile` záložní profil s varováním), `--format=markup` na
  stdout; s `--data` beze změny. Nový příkaz `print-escpos-test
  --profile=<id> --output=<soubor>` (zkušební účtenka).

**`GET /_devices/printer-profiles`** — podepsané volání (účel
`printer-profiles`, kontrakt §7.2) v `DevicesController`, odpověď
`{profiles: [...]}` v tvaru kontraktu §6 + `columnsSmall`. Katalog čte
kontroler z konfigurace; modul `core.devices` na `core.prints` závislost
nedostane.

## Účtenka prodejky (U34)

Deklarace v `modules/docs/cashRegister/config/prints.jsonc`:
`docs.cashRegister.receiptPos` („Účtenka“ / „Receipt“), stejná tabulka,
`filter`, `docStates`, `watermarks` a builder jako A4 prodejka,
`output: "escpos"`, `template: "@docs.cashRegister/receiptPos"`,
`catalogs: ["@docs.core/_layout"]`, `order` za A4 prodejkou.

Šablona `modules/docs/cashRegister/prints/receiptPos/page.escpos.twig`:

```
[STORNO — meta.watermark, size 2x2, jen když je]
název dodavatele (tučně, na střed), adresa, IČO / DIČ
[odběratel: název, adresa, IČO / DIČ — jen když je]
titulek (size 2x1, na střed)
číslo · datum a čas vystavení · [DUZP] · pokladna
────
položka: popis
         množství × jednotková cena · sazba DPH        celkem   (font small)
────
[rekapitulace DPH u plátce: sazba, základ, DPH, celkem (font small)]
Celkem (tučně, size 2x1)                              částka měna
způsob úhrady, [zaokrouhlení]
Vystavil: jméno
poděkování (katalog receiptPos)
feed + cut
```

Popisky z `@docs.core/_layout` (`title.*`, `label.*`, `totals.*`), vlastní
jen texty účtenky (`messages.jsonc`: poděkování, popisky sloupců).
Formátování čísel a dat přes `PrintTwigExtension` jako u PDF.

## Frontend — náhled (U30)

`frontend/src/native/escpos/decode.js`: `FS .` (bez výstupu, žádná značka
`unknown`), `ESC ! n` (bit 0 → `font: 'small'` ve stylu řádku, limit
`columnsSmall`), volba `columnsSmall` (výchozí podle `columns`: 48 → 64,
42 → 56, 32 → 42). `ReceiptPreview` vykreslí malé písmo užší. Simulace
a stránka Most předají `columnsSmall` z profilu; vestavěný profil simulace
`escpos-80-cp852` se srovná s katalogem (42 / 56).

## Testy

- `EscPosDriverTest`, `Cp852Test` — výchozí ovladač bajt po bajtu a jen
  s příkazy z podmnožiny náhledu; všechna
  česká a slovenská písmena v obou velikostech, NBSP, `€`, znak mimo stránku.
- `ReceiptMarkupParserTest`, `ReceiptLayoutTest` — neznámá značka
  a atribut, zalomení slov, dlouhé slovo, `row` s dlouhým levým i pravým
  textem, NBSP nezalamuje, malé písmo a `size` mění šířku, `line`.
- `PrinterProfilesTest` — oba profily, záložní s varováním.
- **Vzory (U33)** `tests/Fixtures/EscPos/`: zkušební účtenka obou profilů
  a účtenka prodejky z fixture `PrintData` v `tests/Fixtures/Prints/`
  (plátce i neplátce, storno, odběratel) pro oba profily. PHP test
  porovná bajty, `frontend/tests/Unit/native/escposFixtures.test.mjs`
  dekóduje tytéž soubory a porovná s `*.preview.json`. Skript na
  přegenerování vzorů vedle nich (jen vědomě, s kontrolou diffu).
- `PrintDefinitionLoaderTest` — `output`, zakázané klíče u `escpos`.
- `PrintRunCommandTest` — `--format=escpos`, `--format=markup`, `--profile`.
- Endpoint `printer-profiles` — podpis, účel, tvar odpovědi.

## Dokumentace

- `docs/prints.md` — nová kapitola **Tisk na účtenkovou tiskárnu**:
  deklarace `output`, šablonový jazyk, sazba, ovladač, kodér a CP852, profily,
  CLI, vzory, jak přidat tisk pro účtenkovou tiskárnu; tabulka tisků
  v úvodu + `docs.cashRegister.receiptPos`.
- `docs/native-bridge.md` — §6 (`columnsSmall`, záložní profil, příklad
  profilu `escpos-80-cp852` na 42 / 56 / 512),
  §9a (`FS .`, `ESC !`), Stav implementace, Historie změn.
- `docs/frontend.md` — náhled ESC/POS.
- `tasks/README.md` — řádek tasku 4a.

## Commity

1. `feat(prints): ovladač ESC/POS a kódová stránka CP852 (#177 U27, U28, U32)`
2. `feat(prints): šablonový jazyk a sazba účtenky (#177 U31)`
3. `feat(prints): katalog profilů tiskáren, GET /_devices/printer-profiles (#177 U5, U29)`
4. `feat(prints): výstup escpos v PrintRunner, CLI, zkušební účtenka (#177 U35)`
5. `feat(docs): účtenka prodejky pro účtenkovou tiskárnu (#177 U34)` — deklarace, šablona, katalog, vzory
6. `feat(native): náhled ESC/POS — FS . a malé písmo, společné vzory (#177 U30, U33)`
7. `docs(prints): tisk na účtenkovou tiskárnu, task 4a hotový (#177)` — dokumentace, **Stav**, `python3 scripts/tasks-index.py`

## Hotovo když

- [ ] cílené PHPUnit (`--filter 'EscPos|Cp852|Receipt|PrinterProfile|PrintDefinitionLoader|PrintRunCommand'`)
      a `npm test` projdou; vzory sedí bajt po bajtu i v náhledu
- [ ] `shpd-ds print-run docs.cashRegister.receiptPos <id> --format=escpos
      --profile=escpos-80-cp852 --output=/tmp/u.bin` na dev DS vyrobí
      účtenku; `--format=markup` ukáže šablonu
- [ ] `print-escpos-test` pro oba profily
- [ ] náhled souboru z CLI (stránka Most nebo test) ukáže diakritiku,
      malé písmo a ořez bez značek `unknown`
- [ ] na skutečné tiskárně (úzká i široká) zkušební účtenka a účtenka
      prodejky — ruční ověření, bajty jde poslat např. `nc <ip> 9100 < u.bin`
- [ ] A4 prodejka beze změny (stávající testy tisků projdou); akce Tisk
      v detailu prodejky nabízí dál jen A4 tisky (test `ViewerController`)
- [ ] dokumentace aktualizovaná
