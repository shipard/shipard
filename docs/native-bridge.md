# Nativní aplikace — kontrakt mostu a zařízení

**Status:** verze 1 — webová strana a simulace pro vývoj hotové (task 2), server a aplikace neimplementovány (#177); stav v [§10](#10-stav-implementace)
**Verze:** 1 (2026-11-27) — viz [Historie změn](#11-historie-změn)
**Implementace:** web `frontend/src/native/` (task 2); server `core.devices`,
`core.prints` (tasky 3, 4, 6); aplikace `shipard-app:apple/` (task 5)

Zdroj pravdy pro obě strany: webová aplikace a server žijí v tomto repu,
nativní aplikace v repu `shipard/shipard-app`. Změna kontraktu se zapisuje
sem **před** implementací klienta ([services.md](services.md) §12).

## 1. Přehled

Nativní aplikace Shipardu (macOS a iPadOS) je okno s WKWebView, ve kterém
běží běžný Shipard, a k tomu device hub pro periferie — tiskárny účtenek
a čtečky. Webová aplikace s nativní částí mluví přes most
`window.shipardNative`. Nativní část navíc sama volá několik endpointů
serveru; tato volání podepisuje klíčem zařízení.

### Vrstvy a odpovědnosti

| Vrstva | Odpovědnost |
|---|---|
| Webová aplikace (SPA) | UI, kasa a ostatní obrazovky, REST se session uživatele, vznik tiskové úlohy a hlášení jejího výsledku, rozhodnutí, která obrazovka dostane událost čtečky |
| Most `window.shipardNative` | úzké rozhraní mezi JS a nativní částí: volání metod s odpovědí, události z nativní části |
| Nativní aplikace | okno a WebView, profily uživatelů, zámek a zadání PINu, nastavení periferií a přenos dat k nim, klíč zařízení, podepsaná volání serveru |
| Server | `core.devices` (zařízení, periferie, výzvy, zařazení uživatelé, PIN), `core.prints` (tiskové úlohy, skládání ESC/POS, katalog profilů tiskáren), autentizace a session |

**Logika je na serveru, aplikace přenáší.** Skládání ESC/POS, směrování na
periferii podle role i stav tiskových úloh drží server; aplikace doručí
bajty tiskárně a ohlásí, jak to dopadlo. Aplikace funguje **jen online**.

### Režimy zařízení

| Režim | Význam |
|---|---|
| `unregistered` | zařízení server nezná; most funguje, podepsaná volání ne |
| `personal` | registrované zařízení jednoho uživatele, běžné přihlášení |
| `shared` | sdílené pracoviště — víc uživatelů, profily, zamčení a PIN (§8) |

Režim určuje registrace (§7.3). Zneplatnění zařízení administrátorem je
konečné: aplikace zahodí `deviceId` i klíč pro tento zdroj dat a vrací se
v něm do `unregistered`.

### Zařízení a zdroje dat

Identita zařízení — `deviceId` a klíč — i stav registrace platí **pro jeden
zdroj dat** (#177 D33). Osobní zařízení pracuje přes portál hostingu s víc
zdroji dat a v každém má jinou identitu:

- aplikace drží `deviceId`, klíč a registraci pro každý zdroj dat zvlášť;
  zdroj dat rozlišuje podle **adresy jeho API** stejným pravidlem jako
  webová aplikace (`frontend/src/api/config.js`): v produkci origin
  (`https://firma.example.com/api/v1`), ve vývoji origin s ID zdroje dat
  v cestě (`http://10.0.0.5/test-test-test-test/api/v1`);
- `info` a ostatní metody pracují se zdrojem dat **aktuální stránky**;
  `info.dataSource` vrací jeho adresu, aby webová aplikace mohla ověřit, že
  se obě strany shodnou;
- zneplatnění v jednom zdroji dat zruší jen identitu pro tento zdroj dat.

Společný `deviceId` by dovolil propojit zdroje dat různých firem přes jedno
zařízení a zneplatnění v jednom zdroji dat by zrušilo registraci ve všech
ostatních. Sdílené pracoviště (`shared`) patří jednomu zdroji dat.

Stránka mimo zdroj dat (vývojářský dashboard) dostane v `info`
`dataSource` a `deviceId` `null` a `mode` `unregistered`; metody
`device.*` tam vrací `NOT_SUPPORTED`.

### Co je ve verzi 1

| Ve verzi 1 | Mimo verzi 1 |
|---|---|
| lokální cesta: tiskárna a čtečka dosažitelné ze zařízení, na kterém běží webová aplikace | vyzvedávání úloh hubem bez okna, Mac jako hub pro jiná zařízení |
| macOS a iPadOS | Windows, Android |
| výsledek tisku hlásí webová aplikace přes REST | kanál server → zařízení v reálném čase (realtime brána) |
| zdroje dat na hostingu | zdroj dat mimo hosting s ručně zadanou adresou |

Kontrakt s tím, co je mimo verzi 1, nesmí být v rozporu: tisková úloha je
záznam na serveru pro obě cesty doručení a periferie hlásí serveru nativní
část sama, takže hub bez okna nepotřebuje webovou aplikaci.

## 2. Most

### 2.1 Vložení

Skript mostu vkládá nativní část na začátek dokumentu, **jen do hlavního
rámce a jen pro povolené originy** — domény hostingu (`hosting.baseDomain`).
Vývojová sestavení aplikace dovolí přidat origin ručně, včetně `http` na
privátní adrese (vývojový server, [rest-api.md](rest-api.md) §2).

Mimo povolené originy `window.shipardNative` **neexistuje**. Webová
aplikace most zjišťuje přítomností objektu, ne podle prohlížeče.

### 2.2 Volání

```js
const result = await window.shipardNative.call(method, params);
```

- `method` — název metody (§3), `params` — JSON objekt, lze vynechat.
- Vrací `Promise`; výsledek je JSON objekt.
- Původ volající stránky kontroluje nativní část **u každého volání**.

Most je úzký: nenabízí soubory ani libovolnou síť. Nativní část se připojuje
jen k periferiím, které jsou nastavené v aplikaci, a k serveru.

### 2.3 Chyby

`Promise` se zamítne objektem `{code, message, details?}` — `code` velkými
písmeny jako v REST ([rest-api.md](rest-api.md) §4), `message` je text pro
vývojáře (ne pro uživatele), `details` je nepovinný objekt.

Obecné kódy platí pro každou metodu:

| Kód | Význam |
|---|---|
| `UNKNOWN_METHOD` | aplikace metodu nezná (starší aplikace, překlep) |
| `INVALID_PARAMS` | parametry chybí nebo neodpovídají kontraktu |
| `NOT_SUPPORTED` | metodu aplikace zná, ale tady ji nenabízí — chybí capability nebo to nedovoluje režim zařízení |
| `NOT_REGISTERED` | metoda potřebuje registrované zařízení |
| `DEVICE_REVOKED` | server zařízení zneplatnil; aplikace zahodila `deviceId` i klíč pro tento zdroj dat |
| `NETWORK_ERROR` | server nebo periferie nejsou dosažitelné |
| `SERVER_ERROR` | server odpověděl chybou, kterou kontrakt u metody neuvádí; `details` nese `status` (HTTP) a `code` serveru |
| `CANCELLED` | uživatel nativní dialog zavřel |
| `INTERNAL_ERROR` | neočekávaná chyba aplikace |

Kódy jednotlivých metod jsou u metod (§3). Chybu serveru z §7.2, kterou
kontrakt u metody jmenuje, vrací most pod stejným kódem. Výjimka:
`DEVICE_UNKNOWN` hlásí most jako `NOT_REGISTERED`.

### 2.4 Verze

`apiVersion` je celé číslo, dnes **1**.

- Přidání metody, pole, hodnoty výčtu nebo capability verzi **nemění**.
  Obě strany neznámá pole ignorují a s neznámou hodnotou výčtu se
  vyrovnají (neznámý `transport` periferie není chyba).
- Odebrání metody nebo pole a změna významu = nová verze.
- Webová aplikace se řídí seznamem `capabilities`, **ne** platformou ani
  verzí aplikace — aplikace z App Store může být starší než web.

### 2.5 Capabilities

Seznam vrací `info`. Metodu nebo událost smí webová aplikace použít, jen
když je její capability v seznamu.

| Capability | Metody a události |
|---|---|
| — (vždy) | `info` |
| `device` | `device.register`, `device.sync`, `device.enroll` |
| `peripherals` | `peripherals.list`, událost `shipard:peripheral` |
| `print.escpos` | `print.submit` s formátem `escpos` |
| `print.pdf` | `print.submit` s formátem `pdf` |
| `scanner` | událost `shipard:scan` |
| `profiles` | `session.lock`, `session.take`, událost `shipard:session` |
| `auth.external` | `auth.external` |

### 2.6 Události

Nativní část posílá události jako `CustomEvent` na `window`: název
`shipard:<událost>`, `detail` je JSON objekt (§4).

- Doručují se jen do **aktivního okna aktivního profilu**.
- Nativní část je **nefrontuje** — kdo neposlouchá, nedostane.
- Kterou obrazovku událost čtečky dostane, rozhoduje webová aplikace.

## 3. Metody

### 3.1 `info`

Capability: žádná, dostupná vždy. Bez parametrů. Webová aplikace ji volá
při startu a po `device.sync`.

```json
{
  "apiVersion": 1,
  "app": { "version": "1.0.0", "build": "17" },
  "platform": "ipados",
  "osVersion": "18.2",
  "dataSource": "https://firma.example.com/api/v1",
  "deviceId": "0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10",
  "keyStorage": "secureEnclave",
  "mode": "shared",
  "profile": { "id": "5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02", "userId": 12 },
  "capabilities": ["device", "peripherals", "print.escpos", "scanner", "profiles"]
}
```

| Pole | Popis |
|---|---|
| `apiVersion` | verze kontraktu (§2.4) |
| `app.version`, `app.build` | verze a číslo sestavení aplikace — totéž, co okno O aplikaci |
| `platform` | `macos` \| `ipados`; simulace pro vývoj hlásí `mock` (§9a) |
| `osVersion` | verze operačního systému |
| `dataSource` | adresa API zdroje dat aktuální stránky (§1, Zařízení a zdroje dat); `null` mimo zdroj dat |
| `deviceId` | UUID zařízení pro tento zdroj dat; vzniká při první práci se zdrojem dat, po zneplatnění aplikace vytvoří nové; `null` mimo zdroj dat |
| `keyStorage` | kde je klíč zařízení: `secureEnclave` \| `keychain` (Mac bez Secure Enclave, §7.1); simulace pro vývoj hlásí `mock` (§9a) |
| `mode` | v tomto zdroji dat: `unregistered` \| `personal` \| `shared` (§1) |
| `profile` | aktivní profil (§8); `null` mimo režim `shared` |
| `profile.id` | UUID profilu na tomto zařízení |
| `profile.userId` | ID uživatele ve zdroji dat; `null`, dokud uživatel profilu není zařazený |
| `capabilities` | co aplikace na tomto zařízení a v tomto režimu nabízí (§2.5) |

### 3.2 `device.register`

Capability `device`. Podepíše výzvu serveru klíčem zařízení. Volá ji webová
aplikace v průběhu registrace (§7.3). Metoda **jen podepisuje** — stav
aplikace se změní až následným `device.sync`.

Parametry:

```json
{ "challenge": "p3mX0uJ8m1nq2Yb7c5VtR9kLwZ4aQeH6sD0fGiNoPxE" }
```

Výsledek:

```json
{
  "deviceId": "0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10",
  "algorithm": "ES256",
  "publicKey": "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE",
  "signature": "MEUCIQDf"
}
```

| Pole | Popis |
|---|---|
| `challenge` | výzva z `POST /_devices/challenge` s účelem `register` |
| `deviceId` | shodné s `info.deviceId` |
| `algorithm` | vždy `ES256` |
| `publicKey` | veřejný klíč zařízení, SPKI DER v base64 (příklad je zkrácený) |
| `signature` | podpis řetězce s účelem `register` (§7.1), DER v base64 (příklad je zkrácený) |

Chyby: `INVALID_PARAMS`.

### 3.3 `device.sync`

Capability `device`. Bez parametrů. Aplikace:

1. podepsaným `GET /_devices/self` obnoví stav registrace — režim, název,
   zařazené uživatele, politiku PINu, automatické zamčení;
2. pošle aktuální periferie podepsaným `PUT /_devices/self/peripherals`;
3. vrátí aktualizované `info` (tvar §3.1).

Webová aplikace ji volá po registraci a po změně zařízení v Shipardu.
Aplikace sync spouští i sama — při startu a při změně periferií.

Chyby: `NOT_REGISTERED` (v tomto zdroji dat zařízení registrované není —
server odpověděl `DEVICE_UNKNOWN`), `DEVICE_REVOKED`, `NETWORK_ERROR`,
`NOT_SUPPORTED` (stránka mimo zdroj dat).

### 3.4 `device.enroll`

Capability `device`, jen v režimu `shared`. Zařadí přihlášeného uživatele
na zařízení (§8.2). Aplikace otevře **nativní dialog**: `pinSet: false` →
nastavení PINu (zadává se dvakrát), `pinSet: true` → ověření stávajícího
PINu. PIN se zadává jen v nativním UI a do JS se nikdy nevrací.

Parametry:

```json
{ "ticket": "k7Q2f9mVx1Lr8dTzB4nWc6Hy", "user": { "id": 12, "name": "Jana Nováková" }, "pinSet": true }
```

Výsledek:

```json
{ "status": "enrolled" }
```

| Pole | Popis |
|---|---|
| `ticket` | jednorázový lístek z `POST /_devices/enroll-ticket` |
| `user.id` | ID přihlášeného uživatele; aplikace ho uloží k aktivnímu profilu |
| `user.name` | jméno pro nativní dialog a zamčenou obrazovku |
| `pinSet` | z odpovědi `enroll-ticket`: má už uživatel PIN |
| `status` | vždy `enrolled` |

Chyby: `CANCELLED`, `PIN_POLICY` (nový PIN neodpovídá politice),
`PIN_INVALID` (stávající PIN nesouhlasí; `details.attemptsLeft`),
`TICKET_INVALID`, `NOT_SUPPORTED` (mimo režim `shared`).

### 3.5 `peripherals.list`

Capability `peripherals`. Bez parametrů. Vrací periferie nastavené
v aplikaci.

```json
{
  "peripherals": [
    { "id": "p-1a2b", "kind": "printer", "name": "Účtenky – pult", "transport": "tcp", "status": "ready", "profileId": "escpos-80-cp852" },
    { "id": "p-3c4d", "kind": "scanner", "name": "Čtečka u kasy", "transport": "ble", "status": "offline", "reads": ["barcode"] }
  ]
}
```

| Pole | Popis |
|---|---|
| `id` | ID periferie; stabilní v rámci zařízení |
| `kind` | `printer` \| `scanner` |
| `name` | název, který periferii dal uživatel v aplikaci |
| `transport` | `tcp` \| `ble` \| `mfi` \| `usb` \| `serial` |
| `status` | `ready` \| `offline` \| `error` |
| `profileId` | jen tiskárna: ID profilu z katalogu serveru (§6) |
| `reads` | jen čtečka: co čte — `["barcode"]` nebo `["rfid"]` |

Periferie se vyhledávají, párují a nastavují v aplikaci, ne ve webové
aplikaci.

### 3.6 `print.submit`

Capability `print.escpos` nebo `print.pdf` podle formátu. Předá data
tiskové úlohy (§5) periferii.

Parametry:

```json
{ "jobId": "7f3c2a90-1d5e-4b68-8c11-9a0e4d7b2f35", "peripheralId": "p-1a2b", "format": "escpos", "data": "G0BIZWxsbwo=", "copies": 1, "reprint": false }
```

Výsledek:

```json
{ "status": "sent" }
```

| Pole | Popis |
|---|---|
| `jobId` | ID tiskové úlohy ze serveru |
| `peripheralId` | cílová periferie (`peripherals.list`) |
| `format` | `escpos` \| `pdf` |
| `data` | data úlohy v base64; nejvýš 5 MB po dekódování |
| `copies` | počet kopií, 1–10; nepovinné, výchozí 1 |
| `reprint` | `true` = vědomé opakování už poslané úlohy; nepovinné, výchozí `false` |
| `status` | vždy `sent` — data **předaná tiskárně, ne vytištěno** |

| Chyba | Kdy |
|---|---|
| `PERIPHERAL_NOT_FOUND` | aplikace periferii s tímto ID nemá |
| `PERIPHERAL_UNAVAILABLE` | periferie není připojená |
| `UNSUPPORTED_FORMAT` | periferie nebo aplikace formát neumí |
| `DUPLICATE_JOB` | stejné `jobId` už aplikace poslala a `reprint` není `true` |
| `PAYLOAD_TOO_LARGE` | data přes 5 MB |
| `PRINTER_ERROR` | tiskárna ohlásila chybu; `details` může nést `paperOut: true`, `coverOpen: true`, když je tiskárna hlásí |
| `TIMEOUT` | tiskárna data nepřevzala včas |

### 3.7 `session.lock`

Capability `profiles`. Bez parametrů. Zamkne aktivní profil (§8.4).
Výsledek je prázdný objekt `{}`. Mimo režim `shared` `NOT_SUPPORTED`.

### 3.8 `session.take`

Capability `profiles`. Bez parametrů. Vrátí session, kterou aplikace
získala odemčením PINem a webová aplikace si ji ještě nevzala:

```json
{
  "session": {
    "token": "shpd_st_eyJhbGciOiJIUzI1NiJ9",
    "expires_at": "2026-11-27T15:30:00+01:00",
    "user": { "id": 12, "login": "jana.novakova", "full_name": "Jana Nováková" }
  }
}
```

Když žádná nečeká, vrací `{ "session": null }`. Tvar `session` je stejný
jako `data` odpovědi `POST /_auth/login` ([rest-api.md](rest-api.md) §6);
webová aplikace ji uloží stejně jako po přihlášení. Session se vydá
**jednou** — druhé volání vrátí `null`.

Webová aplikace volá `session.take` po události `shipard:session`
**i při startu**: po uspání profilu nebo pádu procesu WebView se stránka
načítá znovu a událost by propadla.

### 3.9 `auth.external`

Capability `auth.external`. Otevře přihlášení v systémovém prohlížeči
(`ASWebAuthenticationSession`) — poskytovatelé identity přihlášení ve
vloženém WebView odmítají.

Parametry:

```json
{ "url": "https://firma.example.com/api/v1/_auth/oidc/start?provider=entra", "callbackScheme": "shipard" }
```

Výsledek:

```json
{ "callbackUrl": "shipard://auth?code=Zk3vT8qLm1" }
```

| Pole | Popis |
|---|---|
| `url` | adresa, která se otevře; `https` na povoleném originu (§2.1) |
| `callbackScheme` | schéma návratové adresy; ve verzi 1 vždy `shipard` |
| `callbackUrl` | adresa, na kterou se prohlížeč vrátil, se vším, co k ní server připojil |

Chyby: `CANCELLED`, `INVALID_PARAMS`.

Tvar volání je pevný. Průběh na serveru — návratové schéma, `return_to`
a předání handoff kódu ([auth.md](auth.md) → OIDC flow) — doplní task 7.

## 4. Události

### `shipard:scan`

Capability `scanner`. Čtečka něco načetla.

```json
{ "peripheralId": "p-3c4d", "kind": "barcode", "data": "8594000123456", "symbology": "ean13", "at": "2026-11-27T10:15:42+01:00" }
```

| Pole | Popis |
|---|---|
| `peripheralId` | čtečka (`peripherals.list`) |
| `kind` | `barcode` \| `rfid` |
| `data` | načtená hodnota jako text |
| `symbology` | typ kódu malými písmeny (`ean13`, `ean8`, `code128`, `qr`, …; seznam není uzavřený), `null`, když ho čtečka nehlásí nebo jde o RFID |
| `at` | čas načtení, ISO 8601 se zónou |

Čtečky v režimu klávesnice fungují i bez aplikace a tuhle událost
neposílají.

### `shipard:peripheral`

Capability `peripherals`. Změnil se stav periferie.

```json
{ "peripheralId": "p-1a2b", "status": "offline" }
```

`status` má hodnoty jako v `peripherals.list`.

### `shipard:session`

Capability `profiles`. Aplikace má po odemčení novou session. `detail` je
prázdný objekt `{}` — událost je jen zvonek, session si webová aplikace
vezme přes `session.take` (§3.8).

## 5. Tisková úloha

Tisková úloha je záznam na serveru. Ve verzi 1 ji doručuje webová aplikace
přes most (lokální cesta):

```
1. SPA  → server   POST /_print-jobs {printId, recordId, deviceId, role}
                   server najde periferii podle role na záznamu zařízení,
                   složí data podle profilu tiskárny a úlohu uloží
        ← server   {jobId, peripheralId, format, data}
2. SPA  → most     print.submit {jobId, peripheralId, format, data}
        ← most     {status: "sent"} nebo chyba
3. SPA  → server   POST /_print-jobs/{jobId}/result {status, errorCode}
```

| Pole | Popis |
|---|---|
| `printId` | ID tisku z deklarace `prints` ([prints.md](prints.md) §2) |
| `recordId` | záznam, nad kterým tisk vzniká |
| `deviceId` | zařízení, na kterém se tiskne (`info.deviceId`) |
| `role` | role periferie; ve verzi 1 jen `receipt` |
| `jobId` | UUID úlohy |
| `peripheralId` | periferie zařízení s danou rolí |
| `format` | `escpos` \| `pdf` |
| `data` | data úlohy v base64, vyrenderovaná při vzniku úlohy |
| `status` (výsledek) | `sent` \| `failed` |
| `errorCode` | u `failed` kód chyby z `print.submit`; u `sent` `null` |

- **Stavy na serveru:** `created` → `sent` | `failed`. Úloha bez výsledku
  je pro uživatele „neověřeno“; znovu ji pošle jen vědomou akcí
  (`reprint: true`).
- **Ochrana proti dvojímu tisku** je na obou stranách: aplikace si pamatuje
  posledních 100 `jobId` a stejné ID bez `reprint` odmítne
  (`DUPLICATE_JOB`), server drží stav úlohy.
- **Otevření šuplíku** je také úloha — bez papíru.
- **Formáty:** `escpos` (účtenková tiskárna) a `pdf`; který aplikace umí,
  říkají capabilities `print.escpos` a `print.pdf`.

Endpointy `/_print-jobs`, úklid starých úloh a skládání ESC/POS popíše
task 4 v [prints.md](prints.md); tady je jen tvar, který prochází mostem.

## 6. Periferie a profily tiskáren

- **Popis periferie** je tvar z `peripherals.list` (§3.5). Serveru ho hlásí
  **nativní část sama** podepsaným `PUT /_devices/self/peripherals`
  v rámci `device.sync`. Webová aplikace periferie nehlásí — endpoint se
  session by dovolil přepsat periferie cizího zařízení a hub bez okna
  webovou aplikaci nemá.
- **Role periferie** (`receipt`) se přiřazuje v Shipardu na záznamu
  zařízení, ne v aplikaci.
- **Katalog profilů tiskáren** je na serveru. Aplikace ho čte podepsaným
  `GET /_devices/printer-profiles`, nabízí v nastavení periferie a ukládá
  si jen `profileId`. Server tak skládá ESC/POS podle vlastních dat, ne
  podle parametrů z aplikace.

Profil tiskárny:

```json
{
  "id": "escpos-80-cp852",
  "name": "ESC/POS 80 mm, CP852",
  "columns": 48,
  "dotsPerLine": 576,
  "codePage": "cp852",
  "features": ["cut", "drawer", "qr"],
  "models": ["Epson TM-T20III"]
}
```

| Pole | Popis |
|---|---|
| `id` | ID profilu, které si aplikace ukládá u periferie |
| `name` | název pro výběr v nastavení periferie |
| `columns` | znaků na řádek |
| `dotsPerLine` | šířka tisku v bodech |
| `codePage` | kódová stránka textu |
| `features` | co tiskárna umí: `cut` (ořez), `drawer` (šuplík), `qr` (QR kód příkazem tiskárny) |
| `models` | modely, na kterých je profil ověřený |

Neznámé `profileId` u periferie: server použije obecný profil a úlohu
označí varováním.

## 7. Zařízení ↔ server

Podepsaná volání míří na API zdroje dat aktuální stránky (`info.dataSource`,
§1) a nesou identitu zařízení pro tento zdroj dat (`/api/v1`,
[rest-api.md](rest-api.md) §2). Odpovědi mají
běžnou obálku `{success, data}` / `{success, error}`
([rest-api.md](rest-api.md) §4); tvary níže jsou obsah `data`.

### 7.1 Klíč a podpis

| | |
|---|---|
| Algoritmus | ECDSA P-256 se SHA-256 (`ES256`) |
| Veřejný klíč | SPKI DER v base64 |
| Podpis | DER v base64 |
| Podepisovaný řetězec | `shipard-device-v1\|<účel>\|<deviceId>\|<výzva>` (UTF-8) |
| Uložení klíče | Secure Enclave; kde není (starší Mac s Intelem), Keychain — říká to `info.keyStorage` |

**Výzva** je 32 náhodných bajtů v base64url. Je jednorázová, platí 60 s
a je vázaná na účel a zařízení; zařízení smí mít nejvýš 5 nevyužitých
platných výzev. Každé podepsané volání potřebuje vlastní výzvu.

**Podepsané volání** nese hlavičky:

| Hlavička | Obsah |
|---|---|
| `X-Shipard-Device` | `deviceId` |
| `X-Shipard-Challenge` | výzva |
| `X-Shipard-Signature` | podpis řetězce s účelem endpointu |

Session uživatele podepsané volání nenese. Výjimka je registrace: podpis
jde v těle požadavku a volá ji webová aplikace se session administrátora.

### 7.2 Endpointy

| Endpoint | Ověření | Účel výzvy |
|---|---|---|
| `POST /_devices/challenge` | viz níže | — |
| `POST /_devices/register` | session administrátora | `register` |
| `GET /_devices/self` | podpis | `self` |
| `GET /_devices/printer-profiles` | podpis | `printer-profiles` |
| `PUT /_devices/self/peripherals` | podpis | `peripherals` |
| `POST /_devices/enroll-ticket` | session uživatele | — |
| `POST /_devices/enroll` | podpis | `enroll` |
| `POST /_devices/pin-login` | podpis | `pin-login` |
| `POST /_devices/lock` | podpis | `lock` |

**`POST /_devices/challenge`** — tělo `{deviceId, purpose}`, odpověď
`{challenge, expiresIn}` (`expiresIn` v sekundách). Účel `register` vydá
server jen se session administrátora. Ostatní účely jen registrovanému
aktivnímu zařízení, bez session — jinak `DEVICE_UNKNOWN` nebo
`DEVICE_REVOKED` a výzva nevznikne. Šestá nevyužitá výzva zařízení končí
`RATE_LIMITED`.

**`POST /_devices/register`** — registrovat smí ve verzi 1 jen
administrátor zdroje dat. Tělo:

```json
{
  "deviceId": "0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10",
  "algorithm": "ES256",
  "publicKey": "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE",
  "challenge": "p3mX0uJ8m1nq2Yb7c5VtR9kLwZ4aQeH6sD0fGiNoPxE",
  "signature": "MEUCIQDf",
  "name": "Pokladna 2",
  "mode": "shared",
  "platform": "ipados",
  "appVersion": "1.0.0"
}
```

`deviceId`, `algorithm`, `publicKey` a `signature` jsou výsledek
`device.register`, `platform` a `appVersion` z `info`, `name` a `mode`
(`personal` | `shared`) zadává administrátor. Odpověď 201:
`{deviceId, name, mode, state}`.

**`GET /_devices/self`** — stav registrace:

```json
{
  "mode": "shared",
  "name": "Pokladna 2",
  "state": "active",
  "users": [ { "id": 12, "name": "Jana Nováková" } ],
  "pinPolicy": { "enabled": true, "minLength": 4, "maxLength": 8, "maxAttempts": 5 },
  "autoLockMinutes": 10
}
```

| Pole | Popis |
|---|---|
| `mode` | `personal` \| `shared` |
| `name` | název zařízení ze záznamu v Shipardu |
| `state` | ve verzi 1 vždy `active` — zneplatněné zařízení dostane `DEVICE_REVOKED`; další hodnoty smí přibýt |
| `users` | uživatelé zařazení na zařízení (zamčená obrazovka, §8.3); mimo režim `shared` prázdné |
| `pinPolicy` | politika PINu zdroje dat (§8.5) |
| `autoLockMinutes` | zamčení po nečinnosti v minutách; `null` = bez automatického zamčení |

Pokladnu odpověď nenese — číselník pokladen patří `economy.codebooks`
a kasa ji čte ze záznamu zařízení.

**`GET /_devices/printer-profiles`** — `{profiles: [...]}`, profil viz §6.

**`PUT /_devices/self/peripherals`** — tělo `{peripherals: [...]}` v tvaru
`peripherals.list` (§3.5), vždy **úplný seznam**. Odpověď `{count}` — počet
přijatých periferií. Periferii, která v hlášení chybí, server nemaže, jen
ji označí jako nepřítomnou; přiřazená role zůstává.

**`POST /_devices/enroll-ticket`** — session uživatele, zařízení v režimu
`shared`. Tělo `{deviceId}`, odpověď `{ticket, expiresIn, pinSet}`: lístek
je jednorázový, platí 5 minut (`expiresIn` v sekundách) a je vázaný na
uživatele a zařízení; `pinSet` říká, jestli uživatel už PIN má.

**`POST /_devices/enroll`** — tělo `{ticket, pin}`. Uživatel bez PINu si ho
tím nastaví, uživatel s PINem ho ověří. Odpověď `{status: "enrolled"}`.

**`POST /_devices/pin-login`** — tělo `{userId, pin}`, odpověď
`{token, expires_at, user}` v tvaru `POST /_auth/login`.

**`POST /_devices/lock`** — tělo `{userId}`, odpověď `{invalidated}` —
počet session uživatele na tomto zařízení, které server zneplatnil.

### Chybové kódy

| Kód | HTTP | Kdy |
|---|---|---|
| `DEVICE_UNKNOWN` | 401 | server zařízení s tímto `deviceId` nezná |
| `DEVICE_REVOKED` | 403 | zařízení je zneplatněné |
| `DEVICE_EXISTS` | 409 | registrace `deviceId`, které už je aktivní |
| `CHALLENGE_INVALID` | 401 | výzva neexistuje, vypršela, už byla použitá, nebo patří jinému účelu či zařízení |
| `SIGNATURE_INVALID` | 401 | podpis nesedí, nebo klíč není P-256 |
| `TICKET_INVALID` | 401 | lístek neexistuje, vypršel, už byl použitý, nebo patří jinému zařízení |
| `PIN_DISABLED` | 403 | zdroj dat nemá PIN zapnutý |
| `PIN_POLICY` | 422 | nový PIN neodpovídá politice |
| `PIN_INVALID` | 401 | PIN nesouhlasí; `details.attemptsLeft` = zbývající pokusy |
| `PIN_LOCKED` | 403 | vyčerpané pokusy — dál jen plné přihlášení |
| `USER_NOT_ENROLLED` | 403 | uživatel není na tomto zařízení zařazený |
| `RATE_LIMITED` | 429 | příliš mnoho nevyužitých výzev |

Chyby ověření podpisem nenesou podrobnosti, které by pomohly hádat.

### 7.3 Registrace

```
admin v Shipardu: „Registrovat toto zařízení“ (název, režim)
1. SPA → server   POST /_devices/challenge {deviceId, purpose: "register"}
2. SPA → most     device.register {challenge}
3. SPA → server   POST /_devices/register {… výsledek kroku 2, name, mode, platform, appVersion}
4. SPA → most     device.sync
```

Po kroku 4 vrací `info` nový režim. Zneplatnění je konečné: nová
registrace téhož zařízení v tomto zdroji dat znamená nové `deviceId`
a nový záznam; registrace v jiných zdrojích dat zůstávají.

## 8. Profily, zařazení, PIN, zamčení

Platí pro režim `shared`. Sdílené pracoviště patří jednomu zdroji dat.

### 8.1 Profil

Profil je uživatel na tomto zařízení s **vlastním úložištěm WebView**
(oddělené cookies a `localStorage`). Aktivní je jeden profil. Aplikace
drží živých jen několik WebView, ostatní uspí — uspaný profil se při
návratu načítá znovu, proto webová aplikace volá `session.take` i při
startu.

### 8.2 Zařazení

Uživatel se na zařízení zařadí sám, jedním plným přihlášením:

```
1. plné přihlášení v novém profilu (heslo nebo auth.external)
2. SPA: info říká mode = shared a profile.userId = null
3. SPA → server   POST /_devices/enroll-ticket {deviceId}
4. SPA → most     device.enroll {ticket, user, pinSet}
5. aplikace: nativní dialog PINu
6. aplikace → server   POST /_devices/enroll {ticket, pin}   (podpis)
```

PIN je jeden na uživatele a platí na všech zařízeních, kde je zařazený —
proto druhé zařazení PIN jen ověřuje. Administrátor může uživatele ze
zařízení odebrat.

### 8.3 Odemčení

```
1. zamčená obrazovka: uživatelé z GET /_devices/self
2. uživatel zadá PIN v nativním UI
3. aplikace → server   POST /_devices/challenge, POST /_devices/pin-login (podpis)
4. aplikace podrží session pro profil
5. aplikace → SPA   událost shipard:session
6. SPA → most       session.take
```

PIN ověřuje server spolu s klíčem registrovaného zařízení a jen pro
uživatele zařazené na tomto zařízení.

### 8.4 Zamčení

Spouští ho `session.lock` z webové aplikace, tlačítko v aplikaci nebo
nečinnost (`autoLockMinutes`). Aplikace zavolá `POST /_devices/lock`
a server zneplatní session uživatele s `device_id` tohoto zařízení —
aplikace k tomu nepotřebuje aktuální token, který webová aplikace
průběžně mění. WebView profilu zůstává v paměti pod nativním zámkem.

### 8.5 Session a politika PINu

- **Session** nese `auth_method` (`local`, `oidc`, `pin`, `emergency`)
  a `device_id`. Vynucení plného ověření u citlivých akcí přijde později.
- **Politika PINu** je v auth politice zdroje dat ([auth.md](auth.md)),
  klíč `auth.pin` v `main.json`:

  | Klíč | Výchozí | Popis |
  |---|---|---|
  | `enabled` | `false` | PIN se musí ve zdroji dat výslovně zapnout |
  | `minLength` | 4 | nejmenší počet číslic |
  | `maxLength` | 8 | největší počet číslic |
  | `maxAttempts` | 5 | po tolika chybách na zařízení `PIN_LOCKED` — dál jen plné přihlášení |

## 9. Bezpečnost

| Hrozba | Ochrana |
|---|---|
| XSS ve webové aplikaci | most je úzký — žádné soubory, síť jen k periferiím nastaveným v aplikaci; PIN nikdy neprochází JS |
| Vyzrazený PIN | platí jen na registrovaném zařízení a jen pro uživatele na něm zařazené; počet pokusů omezuje server |
| Zařízení bez dozoru | zamčení zneplatní session na serveru, ne jen zakryje okno |
| Odcizené zařízení | administrátor zařízení zneplatní; klíč uložený v Secure Enclave nejde ze zařízení vynést |
| Propojení zdrojů dat přes zařízení | identita zařízení je pro každý zdroj dat jiná (§1) |
| Přehrání požadavku | výzva je jednorázová, platí 60 s a je vázaná na účel a zařízení |
| Cizí stránka v aplikaci | most jen pro povolené originy, jen v hlavním rámci, původ se kontroluje u každého volání |
| Doplňky prohlížeče čtoucí session | do aplikace nejdou instalovat |

## 9a. Simulace pro vývoj

Webová aplikace má simulaci mostu v prohlížeči (#177 D29, upřesnění
U11–U13; kód `frontend/src/native/mock/`, popis v
[frontend.md](frontend.md) → Nativní most). Slouží vývoji a testům webové
strany a serveru bez aplikace a bez tiskárny; **do produkce nepatří**
a skutečný most má vždy přednost.

**Zapnutí.** Příznak `shpd_native_mock = "1"` v `localStorage`, který
nastavuje stránka Most ve vývojářském dashboardu (`/_dev/bridge` existuje
jen ve vývoji a sdílí origin s aplikací). Kód simulace je samostatný chunk
načtený jen při zapnutém příznaku a jen když `window.shipardNative`
neexistuje. Zapnutá simulace je v aplikaci vždy vidět (pruh a panel).
Režim serveru `/_app/info` nevystavuje, simulace se podle něj neřídí.

**Hodnoty jen pro vývoj.** Simulace hlásí `platform: "mock"`
a `keyStorage: "mock"` a nese značku `window.shipardNative.__mock === true`.
Server je u zařízení přijímá (task 3); aplikace je nikdy nepošle.

| Metoda / událost | Simulace |
|---|---|
| `info` | podle kontraktu; `dataSource` = `location.origin` + adresa API stránky, `profile` vždy `null` |
| `device.register` | **skutečný** klíč ECDSA P-256 z WebCrypto a podpis podle §7.1 — server ho ověří stejně jako u aplikace |
| `device.sync` | podepsané `GET /_devices/self` a `PUT /_devices/self/peripherals` (dvě výzvy, účely `self` a `peripherals`); režim z odpovědi si uloží. Chyby: `NETWORK_ERROR`, `SERVER_ERROR` s `details {status, code}`, `DEVICE_UNKNOWN` → `NOT_REGISTERED`, `DEVICE_REVOKED` smaže identitu tohoto zdroje dat |
| `peripherals.list` | tiskárna `mock-receipt` (`profileId: "escpos-80-cp852"`, 48 znaků) a čtečka `mock-scanner` (`reads: ["barcode", "rfid"]`) |
| `print.submit` | kontrola parametrů a limitů jako aplikace (`INVALID_PARAMS`, `PAYLOAD_TOO_LARGE`, `PERIPHERAL_NOT_FOUND`, `UNSUPPORTED_FORMAT`, `DUPLICATE_JOB` s pamětí 100 `jobId`, `PERIPHERAL_UNAVAILABLE`); místo tisku náhled účtenky |
| `shipard:scan`, `shipard:peripheral` | z ovládacího panelu simulace |
| `device.enroll`, `session.lock`, `session.take`, `auth.external` | `NOT_SUPPORTED` — doplní tasky 6 a 7 |

- **Identita je pro každý zdroj dat zvlášť** (§1): `deviceId` a klíč jako
  JWK v `localStorage` pod klíčem `shpd_native_mock_device:<adresa API>`.
  Ve vývoji sdílejí všechny zdroje dat jeden origin — bez adresy v klíči by
  si je simulace pletla. Klíč v `localStorage` je čitelný pro JS; proto jen
  vývoj.
- **Zabezpečený kontext.** WebCrypto (`crypto.subtle`) prohlížeč nabízí jen
  na `https`, `localhost` a `127.0.0.1`. Na vývojovém serveru přes
  `http://<privátní adresa>` simulace klíč nemá: `info` vrací `deviceId`,
  ale capability `device` chybí a `device.register` i `device.sync` končí
  `NOT_SUPPORTED`. Registraci proti serveru zkoušej přes `127.0.0.1`
  nebo `https`.
- Registrované zařízení po přepnutí stavu periferie v panelu samo
  synchronizuje (aplikace sync spouští při změně periferií, §3.3).

### Náhled ESC/POS

Simulace a stránka Most ukazují místo tisku **náhled** — z bajtů úlohy
čitelnou účtenku. Náhled je čistá funkce ve frontendu nad pevnou
podmnožinou příkazů; `EscPosRenderer` na serveru (task 4) musí zůstat
v ní, nebo náhled rozšířit i s testem.

| Příkaz | Bajty | Náhled |
|---|---|---|
| inicializace | `ESC @` | reset stylu a kódové stránky |
| odřádkování | `LF` | nový řádek |
| posun o n řádků | `ESC d n` | n prázdných řádků |
| zarovnání | `ESC a n` | `left` / `center` / `right` |
| tučně | `ESC E n` | `bold` |
| velikost | `GS ! n` | `size` `{w, h}` |
| kódová stránka | `ESC t n` | přepne dekódování |
| ořez | `GS V …` | značka `cut` |
| QR | `GS ( k …` (uložení dat funkce 80, tisk funkce 81) | značka `qr` s obsahem |
| šuplík | `ESC p …` | značka `drawer` |

Neznámý příkaz dá značku `unknown` s hex bajty a dekódování pokračuje.

Čísla kódových stránek pro `ESC t n` (číslování Epson):

| `codePage` profilu | `n` |
|---|---|
| `cp852` | 18 |
| `windows-1250` | 45 |

## 10. Stav implementace

| Část | Task | Stav |
|---|---|---|
| Kontrakt (tento dokument) | 1 — `tasks/native-bridge-contract.md` | hotovo |
| Most ve frontendu, simulace mostu, testovací stránka v `/_dev/` | 2 — `tasks/native-frontend-bridge.md` | hotovo |
| `core.devices`, registrace, podepsaná volání, hlášení periferií | 3 — `tasks/native-devices-registration.md` | neimplementováno |
| Tiskové úlohy, ESC/POS, katalog profilů tiskáren | 4 | neimplementováno |
| Aplikace pro macOS a iPadOS (`shipard-app:apple/`) | 5 | neimplementováno |
| Profily, zařazení, PIN, zamčení | 6 | neimplementováno |
| `auth.external` — přihlášení přes systémový prohlížeč | 7 | neimplementováno |
| Vydávání (App Store, notarizace, aktualizace) | 8 | neimplementováno |

## 11. Historie změn

| Verze | Datum | Změna |
|---|---|---|
| 1 | 2026-11-27 | První verze: #177 D21–D32, upřesnění U1–U10, U16, U20. |
| 1 | 2026-11-27 | Identita a registrace zařízení pro každý zdroj dat zvlášť, `info.dataSource` (#177 D33); `DEVICE_UNKNOWN` se na mostu hlásí jako `NOT_REGISTERED`. Před implementací klienta, verze beze změny. |
| 1 | 2026-11-27 | Oddíl Simulace pro vývoj (§9a, #177 U11–U13): hodnoty `mock` u `platform` a `keyStorage`, podmnožina ESC/POS pro náhled a čísla kódových stránek pro `ESC t` (18, 45). Webová strana mostu hotová (task 2). Verze beze změny. |

---

[← docs/README.md](README.md) · [services.md](services.md) · [rest-api.md](rest-api.md)
