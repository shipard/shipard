# Nativní aplikace — task 2: most ve frontendu, simulace, stránka Most, pole bez oprav (#177)

**Stav:** hotovo — implementováno 2026-11-27 v šesti commitech (#177 D6, D10, D21, D22, D29, D32, D33, U11–U15); pole bez oprav ověřená ručně v Safari 2026-11-27; odchylky od zadání jsou na konci

> Task pro Claude Code (6 commitů). Design: issue #177. **Navazuje na
> task 1** (`tasks/native-bridge-contract.md`) — implementovat až po něm,
> kontrakt `docs/native-bridge.md` musí existovat. Žádná serverová část
> kromě jedné položky v `DevDashboardController` (stránka Most); zařízení,
> tiskové úlohy a PIN jsou tasky 3, 4 a 6.

## Před implementací přečti

- `docs/native-bridge.md` — celý (kontrakt verze 1 z tasku 1)
- `docs/frontend.md` §1–3 a oddíl Vývojářský dashboard (`/_dev/`) —
  struktura, co dashboard smí importovat (ne `t()` aplikace ani
  `api/client.js`), routy vs. `SPA_PAGES`, slovník `src/dev/i18n/`
- `docs/design-system.md` §6 — tokeny, jednotky, kontrolní skripty
  (`check-css-tokens.py`, `check-css-units.py`, `check-contrast.py`)
- `CLAUDE.md` → JavaScript: v kódu jen ASCII uvozovky

## Kontext

Webová aplikace mluví s nativní aplikací přes `window.shipardNative`
(D21). Tenhle task připraví webovou stranu tak, aby šla vyvíjet a testovat
**bez aplikace i bez tiskárny**:

1. obálku mostu a odběr čteček, na kterých budou stát kasa a další
   obrazovky,
2. náhled ESC/POS — z bajtů tiskové úlohy udělá čitelnou účtenku,
3. simulaci mostu v prohlížeči (D29) včetně skutečného klíče zařízení,
   aby šla v tasku 3 registrace vyzkoušet proti serveru bez aplikace,
4. stránku **Most** ve vývojářském dashboardu, která ověří most kterékoli
   platformy proti dev serveru (D29),
5. vypnutí automatických oprav v jednořádkových polích (D10) — WebKit
   na Macu i iPadu je dnes přepisuje i v Safari.

## Upřesnění (potvrzená 2026-11-26)

- **U11** — Simulace se zapíná příznakem `shpd_native_mock = "1"`
  v `localStorage`, který nastavuje stránka Most ve vývojářském dashboardu
  (`/_dev/` existuje jen ve vývoji a ve vývojovém režimu sdílí origin
  s aplikací). Kód simulace je samostatný chunk načtený dynamickým importem
  jen při zapnutém příznaku a jen když `window.shipardNative` neexistuje —
  skutečný most má vždy přednost. Zapnutá simulace je v aplikaci vždy
  vidět (pruh a panel). Režim serveru do `/_app/info` nepřidáváme
  (#126 U5 ho záměrně nevystavuje).
- **U12** — Simulace v tomto tasku pokrývá: `info`; `device.register`
  se **skutečným** klíčem P-256 z WebCrypto a podpisem podle U4 (server ho
  ověří stejně jako u aplikace); `device.sync` jako podepsané
  `GET /_devices/self` a `PUT /_devices/self/peripherals` (D32; do tasku 3
  skončí chybou serveru);
  `peripherals.list`; `print.submit` s náhledem účtenky místo tisku;
  události `shipard:scan` a `shipard:peripheral` z ovládacího panelu.
  `device.enroll`, `session.lock`, `session.take` a `auth.external` vrací
  `NOT_SUPPORTED` — doplní je tasky 6 a 7. Simulace hlásí
  `platform: "mock"` a `keyStorage: "mock"` (hodnoty jen pro vývoj,
  dokumentované v `docs/native-bridge.md`). Identita simulace je pro každý
  zdroj dat zvlášť (D33).
- **U13** — Náhled ESC/POS je čistá funkce ve frontendu nad pevnou
  podmnožinou příkazů (níže). Neznámý příkaz se v náhledu označí, náhled
  nespadne. Kódové stránky CP852 (vlastní tabulka — prohlížeče ji
  nepodporují) a Windows-1250 (`TextDecoder`). `EscPosRenderer` v tasku 4
  musí zůstat v téhle podmnožině, nebo náhled rozšířit i s testem.
- **U14** — Automatické opravy (D10): jednořádková textová pole ve
  sdílených komponentách (`Input`, `LookupInput`, `ComboInput`,
  `NumberInput`, `DateInput`) a raw `<input>` v aplikaci (přihlášení,
  paleta příkazů, hledání a filtry vieweru, …) mají `autocorrect="off"`,
  `autocapitalize="off"`, `spellcheck="false"`. Víceřádkový `TextArea`
  (volný text) opravy ponechává. Hlídá to test nad `.svelte` soubory.
  Rozlišení podle druhu sloupce (kód × jméno) nezavádíme — jednořádkové
  pole v účetní aplikaci nese skoro vždy údaj, ne větu.
- **U15** — Odběr čteček (D6) je zásobník: událost dostane poslední
  přihlášený odběratel (obrazovka navrchu), po jeho odhlášení zase
  předchozí; bez odběratele se událost zahodí.

## Rozsah

**V rozsahu:** `frontend/src/native/` (obálka, odběr čteček, validátory
kontraktu, náhled a zkušební účtenka ESC/POS, simulace), panel simulace
v aplikaci, stránka Most v `/_dev/`, atributy pro WebKit ve vstupních
polích, testy, `docs/frontend.md`, `docs/native-bridge.md` (simulace,
stav implementace), řádek v `tasks/README.md`.

**Mimo rozsah:** serverové endpointy `/_devices/*` a `/_print-jobs`
(tasky 3, 4), napojení přihlášení na `session.take` (task 6), použití
čteček v konkrétních obrazovkách (kasa — vlastní návrh), Swift aplikace.

## Část A — obálka mostu (`frontend/src/native/bridge.js`)

Bez importů z `api/client.js` a `t()` — používá ji i vývojářský dashboard.

- `isNativeAvailable()` — `typeof window.shipardNative?.call === 'function'`.
- `nativeCall(method, params)` → `Promise`; odmítnutí vždy normalizuje
  na `NativeBridgeError` (`code`, `message`, `details`). Chybějící most =
  `NOT_AVAILABLE` (kód jen frontendu, ne kontraktu); odpověď mimo tvar
  kontraktu = `INVALID_RESPONSE`.
- `nativeInfo({refresh})` — `info` s pamětí; `hasCapability(name)` nad
  posledním `info`.
- `onNativeEvent(name, handler)` → funkce pro odhlášení; poslouchá
  `shipard:<name>` na `window`, handleru předá `event.detail`.
- Store `stores/native.svelte.js` — `available`, `info`, `capabilities`;
  načte se při startu aplikace, start na něm nečeká.

**Odběr čteček** (`native/scanRouter.js`, U15): `onScan(handler)` →
odhlášení; jeden posluchač `shipard:scan` rozdává události vrcholu
zásobníku; `subscriberCount()` pro panel simulace a testy.

**Validátory kontraktu** (`native/contract.js`): seznamy
`CAPABILITIES`, `ERROR_CODES`, `PLATFORMS`, `MODES`; čisté funkce
`validateInfo(obj)`, `validatePeripheral(obj)`, `validateError(obj)` →
seznam nálezů `{path, message}`. Používá je simulace (sama sebe kontroluje
v testech) a stránka Most.

## Část B — ESC/POS (`frontend/src/native/escpos/`)

**Náhled** — `decodeEscPos(bytes, {codePage, columns})` →
`{lines: [{text, align, bold, size}], marks: [{at, kind, data}]}`.
Podmnožina příkazů (U13):

| Příkaz | Bajty | Náhled |
|---|---|---|
| inicializace | `ESC @` | reset stylu |
| odřádkování | `LF` | nový řádek |
| posun o n řádků | `ESC d n` | n prázdných řádků |
| zarovnání | `ESC a n` | `left` / `center` / `right` |
| tučně | `ESC E n` | `bold` |
| velikost | `GS ! n` | `size` `{w, h}` |
| kódová stránka | `ESC t n` | přepne dekódování (tabulka čísel stránek ↔ kódování, minimálně CP852 a Windows-1250) |
| ořez | `GS V …` | značka `cut` |
| QR | `GS ( k …` (uložení + tisk) | značka `qr` s obsahem |
| šuplík | `ESC p …` | značka `drawer` |

Neznámý příkaz → značka `unknown` s hex bajty, dekódování pokračuje.
Řádek delší než `columns` se v náhledu zalomí jako na papíře.

**Zkušební účtenka** — `buildTestReceipt({codePage, columns, at})` →
`Uint8Array`: inicializace, kódová stránka, nadpis na střed tučně,
řádek „Příliš žluťoučký kůň úpěl ďábelské ódy“ (kontrola diakritiky),
dělicí čára na šířku, datum a čas, QR s `https://shipard.dev`, ořez.
Používá ji panel simulace i stránka Most.

**Komponenta** `components/native/ReceiptPreview.svelte` — vykreslí
výsledek `decodeEscPos` jako proužek účtenky (neproporcionální písmo,
šířka podle `columns`, značky jako popisky: ✂ ořez, QR, šuplík, neznámý
příkaz). Jen tokeny design systému.

## Část C — simulace (`frontend/src/native/mock/`)

- **Spuštění** (`main.js`, před `mount`): když most chybí a příznak je
  `"1"`, `await import('./native/mock/install.js')` a `install()`.
- **`install.js`** — vytvoří `window.shipardNative` s `call()` podle
  kontraktu a označením `__mock: true`; `info.dataSource` je
  `location.origin + API_BASE_URL` (D33); parametry kontroluje jako
  skutečná aplikace (`INVALID_PARAMS`, limity U8, `DUPLICATE_JOB` — paměť
  posledních 100 `jobId`); události vysílá jako `CustomEvent` na `window`.
- **Stav** (`state.js`) v `localStorage` (`shpd_native_mock_state`):
  režim pro každý zdroj dat (`unregistered` / `personal` / `shared`, D33), capabilities (výchozí
  `device`, `peripherals`, `print.escpos`, `scanner`), periferie (výchozí
  tiskárna `mock-receipt` s `profileId: "escpos-80-cp852"`, 48 znaků,
  a čtečka `mock-scanner` s `reads: ["barcode", "rfid"]`), stav periferií.
- **Klíč zařízení** (`deviceKey.js`): `deviceId` (UUID) a pár ECDSA P-256
  z WebCrypto, uložený jako JWK v `localStorage`
  (`shpd_native_mock_device:<adresa API>`) — jen pro vývoj. Identita je pro
  každý zdroj dat zvlášť (D33), klíčem je `location.origin + API_BASE_URL`:
  ve vývoji sdílejí všechny zdroje dat jeden origin, bez adresy v klíči by
  si je simulace pletla. `DEVICE_REVOKED` ze serveru smaže jen identitu
  tohoto zdroje dat. `sign(purpose, challenge)`
  podepíše `shipard-device-v1|<účel>|<deviceId>|<výzva>` (U4) a podpis
  WebCrypto (r‖s) převede na DER; `publicKeySpki()` → base64 SPKI DER.
- **`device.sync`** — `POST /_devices/challenge` (účel `self`) a podepsané
  `GET /_devices/self` přes `API_BASE_URL` a hlavičky `X-Shipard-*`; režim
  z odpovědi uloží do stavu. Potom druhá výzva (účel `peripherals`)
  a podepsané `PUT /_devices/self/peripherals` s periferiemi simulace
  (D32). Simulace sync spustí i po přepnutí stavu periferie v panelu. Chyba sítě nebo serveru → `NETWORK_ERROR` /
  `SERVER_ERROR` (do tasku 3 očekávané).
- **`print.submit`** — po kontrole parametrů uloží úlohu do seznamu náhledů
  (posledních 20) a vrátí `{status: "sent"}`; periferie ve stavu `offline`
  → `PERIPHERAL_UNAVAILABLE`.
- **Panel** `components/native/NativeMockPanel.svelte` (vykreslí `App`,
  jen když je most `__mock`): trvalý pruh „Simulace nativní aplikace“
  a sbalitelný panel — zdroj dat (`info.dataSource`), režim, stav periferií (přepnutí vyšle
  `shipard:peripheral`), čtečka (pole + výběr `barcode` / `rfid` + Enter
  vyšle `shipard:scan`; vedle počet odběratelů ze `scanRouter`), tlačítko
  **Zkušební účtenka** (přes `print.submit`), seznam náhledů tisku
  (`ReceiptPreview`, stažení bajtů jako `.bin`), **Vypnout simulaci**
  (smaže příznak a načte stránku). Texty přes `t()` (`native.mock.*`,
  cs + en).

## Část D — stránka Most ve vývojářském dashboardu (`/_dev/bridge`)

- `DevDashboardController::SPA_PAGES` += `bridge`, `routes.js`
  `['bridge', 'bridge']`, položka lišty s ikonou (`icons.js`, např.
  `faPlug`), texty ve slovníku dashboardu (cs + en). Nic z `t()` aplikace
  ani `api/client.js` — jen `native/bridge.js`, `native/contract.js`,
  `native/escpos/*` a `ReceiptPreview`, pokud nic z toho netahá.
- **Stav mostu**: přítomen / nepřítomen. Bez mostu stránka vysvětlí, že
  se otevírá v nativní aplikaci (vývojová sestavení dovolí přidat origin
  dev serveru), a nabídne přepínač simulace pro aplikaci na tomto serveru
  (U11) — v dashboardu samotném se simulace nespouští.
- **Info**: JSON `info`, capabilities jako štítky, nálezy `validateInfo`;
  v dashboardu (mimo zdroj dat) čeká `dataSource: null` (D33).
- **Kontroly kontraktu** (tlačítko Spustit, nic netiskne): `info` projde
  validátorem; neznámá metoda → `UNKNOWN_METHOD`; `print.submit` bez
  parametrů → `INVALID_PARAMS`; `peripherals.list` projde validátorem;
  každá chyba má tvar podle `validateError`. Tabulka kontrola → výsledek
  → podrobnosti.
- **Periferie**: tabulka z `peripherals.list`; u tiskárny **Zkušební tisk**
  (kódová stránka a šířka volitelná na stránce, výchozí CP852 / 48) —
  vedle se ukáže náhled odeslaných bajtů; potom **Ověřit dvojí tisk** pošle
  stejné `jobId` znovu a čeká `DUPLICATE_JOB` (nic se nevytiskne).
- **Události**: živý výpis posledních 50 `shipard:scan` a
  `shipard:peripheral` s časem.

## Část E — pole bez automatických oprav (D10, U14)

- Sdílené komponenty `Input`, `LookupInput`, `ComboInput`, `NumberInput`,
  `DateInput`: `autocorrect="off" autocapitalize="off" spellcheck="false"`
  na `<input>`. `TextArea` beze změny. Pokud typy Svelte některý atribut
  neznají, předat ho spreadem objektu.
- Všechny ostatní textové `<input>` v `frontend/src` (přihlášení, paleta
  příkazů, viewer, nastavení, …) totéž.
- Test `frontend/tests/Unit/inputAutocorrectUsage.test.mjs` (vzor
  `escapeUsage.test.mjs`): každý `<input>` v `.svelte` souborech, který
  není `type` checkbox / radio / file / color / range / hidden / password,
  má všechny tři atributy; výjimky jen s komentářem v kódu na stejném
  místě.

## Testy

`frontend/tests/Unit/native/`:

- `bridge.test.mjs` — normalizace chyb (`NOT_AVAILABLE`,
  `INVALID_RESPONSE`, předání `code` / `details`), `onNativeEvent`
  a odhlášení (`window` jako `EventTarget` v Node).
- `scanRouter.test.mjs` — zásobník, odhlášení, zahození bez odběratele.
- `contract.test.mjs` — validátory nad platnými i rozbitými tvary.
- `escposPreview.test.mjs` — každý příkaz z tabulky, CP852 i Windows-1250
  s diakritikou, zalomení, neznámý příkaz; `buildTestReceipt` →
  `decodeEscPos` vrátí původní texty.
- `deviceKey.test.mjs` — podpis z WebCrypto převedený na DER ověří
  `node:crypto` (`verify` s `dsaEncoding: 'der'`) proti SPKI DER; podepsaný
  řetězec přesně podle U4; převod r‖s → DER i s úvodní nulou.
- `mock.test.mjs` — simulace projde validátory kontraktu, limity U8,
  `DUPLICATE_JOB`, `NOT_SUPPORTED` u metod tasků 6 a 7; identita pro každý
  zdroj dat (dvě adresy API → dvě identity, `DEVICE_REVOKED` smaže jen
  jednu, D33).

`frontend/tests/Unit/dev/routes.test.mjs` — routa `bridge`. PHP
`DevDashboardControllerTest` — `/_dev/bridge` servíruje SPA.

## Dokumentace

- `docs/frontend.md` — Cílové prohlížeče: + Safari na macOS a iPadOS
  (poslední 2 roky) a WKWebView v nativní aplikaci (macOS 14, iPadOS 17;
  D10, D14); adresářová struktura `native/`; nový oddíl **Nativní most**
  (obálka, store, odběr čteček, náhled ESC/POS, simulace a její zapnutí,
  pravidlo U14 a jeho test); v oddílu Vývojářský dashboard stránka Most.
- `docs/native-bridge.md` — nový oddíl **Simulace pro vývoj** (U11–U13,
  hodnoty `mock`, co simulace umí a co ne); v Stavu implementace task 2
  hotový; záznam v Historii změn (beze změny `apiVersion`).
- `tasks/README.md` — řádek tasku 2 v oblasti Nativní aplikace.

## Commity

1. `feat(native): obálka mostu, odběr čteček, validátory kontraktu (#177 D6, D21)` — část A + testy
2. `feat(native): náhled ESC/POS a zkušební účtenka (#177 U13)` — část B + testy
3. `feat(native): simulace mostu pro vývoj (#177 D29, U11, U12)` — část C + testy
4. `feat(dev): stránka Most ve vývojářském dashboardu (#177 D29)` — část D + testy
5. `fix(frontend): jednořádková pole bez automatických oprav (#177 D10, U14)` — část E + test
6. `docs(native): most ve frontendu a simulace, task 2 hotový (#177)` — dokumentace, **Stav** tohoto tasku, `python3 scripts/tasks-index.py`

## Hotovo když

- [x] `npm test`, `npm run check:i18n` a `npm run build` projdou; pre-commit
      kontroly CSS (tokeny, jednotky, kontrast) bez výjimek navíc
- [x] `vendor/bin/phpunit --filter DevDashboardControllerTest` projde
- [x] produkční bundle aplikace neobsahuje simulaci v hlavním chunku
      (dynamický import; ověřeno ve výstupu buildu — `install-*.js`
      a `NativeMockPanel-*.js`, v hlavním chunku jen texty `native.mock.*`)
- [x] na dev serveru: přepínač na `/_dev/bridge` zapne simulaci → aplikace
      ukáže pruh a panel; Zkušební účtenka se objeví v náhledu s
      diakritikou, QR a ořezem; čtečka v panelu ukáže počet odběratelů;
      Vypnout simulaci ji vypne (ověřeno v headless Chromiu)
- [x] `/_dev/bridge` bez mostu i bez simulace nespadne a vysvětlí, co dělat
- [x] v Safari na Macu (nebo iPadu) se v poli formuláře, přihlášení
      a hledání nic neopravuje ani nezvětšuje; v `TextArea` opravy fungují
      — ověřeno ručně 2026-11-27
- [x] `docs/frontend.md` a `docs/native-bridge.md` aktualizované

## Odchylky od zadání

Odsouhlasené při plánování 2026-11-27, nebo vynucené prostředím:

- **Čísla kódových stránek pro `ESC t n`** kontrakt neuváděl — náhled
  používá číslování Epson (18 = CP852, 45 = Windows-1250) a kontrakt je
  nese v §9a; `EscPosRenderer` v tasku 4 se jich musí držet.
- **Styl řádku náhledu**: `lines[]` nese jeden styl na řádek — ten, který
  platil u prvního znaku; změna tučného uprostřed řádku se neukáže.
  Přesně plný řádek se láme až dalším znakem (čára na šířku + `LF` je
  jeden řádek).
- **Prostředí simulace jde parametrem** (`install({apiBase, storage, fetch,
  crypto, target})`) místo čtení globálů — testy běží v Node a podpis
  zařízení půjde stejnou funkcí použít ve fixture tasku 3.
- **Zabezpečený kontext**: WebCrypto prohlížeč nabízí jen na `https`,
  `localhost` a `127.0.0.1`. Na `http://<privátní adresa>` simulace
  capability `device` nenabízí (`device.register` a `device.sync` →
  `NOT_SUPPORTED`) a panel to říká; UUID proto vzniká z `getRandomValues`,
  ne z `crypto.randomUUID()`.
- **Panel simulace** se načítá dynamickým importem stejně jako simulace
  (jinak by s `ReceiptPreview` a dekodérem skončil v hlavním chunku);
  navíc umí zapínat a vypínat capabilities. Pruh je vpravo dole nad
  dialogy, ne přes celou šířku — shell má výšku okna a pruh nahoře by ho
  posunul.
- **Automatický sync po přepnutí periferie** dělá simulace jen
  u registrovaného zařízení; neregistrované podepsaná volání nemá.
- **Část E i ve vývojářském dashboardu**: test prochází celé `src` včetně
  `src/dev` (stránka Most se otevírá ve WebView). Dvě výjimky
  s komentářem: otázka asistentovi na dashboardu (`ChatLauncher` — věta,
  ne údaj) a `OptionRow` (typ je vždy radio nebo checkbox).
- **Kontroly kontraktu** na stránce Most volají most napřímo, ne přes
  `nativeCall` — obálka by chybějící zprávu nebo kód dorovnala. Kontrola
  bez potřebné capability je „přeskočeno“. Čisté funkce
  `frontend/src/dev/bridge/checks.js` s testem `tests/Unit/dev/bridge.test.mjs`.
- Navíc proti zadání: `native/bytes.js` (base64, UUID) s testem,
  `validatePeripheralList`, `isNativeMock()`, konstanta `NATIVE_MOCK_FLAG`
  v `bridge.js` (příznak čte `main.js` i dashboard, aniž by tahaly kód
  simulace).
- Zadání odkazuje na `CLAUDE.md → JavaScript: v kódu jen ASCII uvozovky`;
  taková sekce v `CLAUDE.md` není — kód drží ASCII uvozovky jako
  oddělovače řetězců, typografické jsou jen v textech slovníků.
