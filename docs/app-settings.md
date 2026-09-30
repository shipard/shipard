# Nastavení aplikace — settings pages + branding

Mechanismus **settings pages** (server-driven stránky vlastností v režimu
Nastavení) a jeho první konzument — stránka **Aplikace** (název, zkrácený
název, ikona, firemní logo).

## 1. Koncept

Settings page je třetí typ položky v Nastavení vedle vieweru a tabulky:
formulář vlastností, jehož hodnoty žijí v key-value tabulce
`core_system_settings` (tableId 3, unique `key`, JSON `value`). Tabulka je
v `keepOnReset` modulu `core.system` — nastavení přežívá `ds-reset`.

Tok dat:

```
module.jsonc (settingsPages + settingsItems)        deklarace
        │
        ▼
GET /_ui/settings/navigation     → nav item { type: 'page', pageId }
   (položky s `visibilityClass` filtruje runtime gate
    NavItemVisibilityGate — viz docs/modules.md, Pole settingsItems)
GET /_ui/settings/page/{pageId}  → { definition, values }
POST /_ui/settings/page/{pageId} → uložení textových polí (whitelist)
        │
        ▼
SettingsStore (PHP) ←→ core_system_settings
```

### SettingsStore — `src/Core/Settings/SettingsStore.php`

Tenká služba nad `core_system_settings`: `get` / `getMany` / `set` /
`delete`, hodnoty JSON-encodované, request-level cache. Konstruktor bere
jen `DataSourceConnection` — volatelná z HTTP i CLI (sestavy, generátor
faktur). `set($key, null)` klíč maže; klíče používají tečkové namespacy.

Od Fáze 3 implementuje rozhraní **`KeyValueStore`** (`get`/`getMany`/`set`/
`delete`), které sdílí s per-user `UserSettingsStore` — `SettingsController`
si vybírá store podle `scope` stránky a nezávisí na konkrétní třídě. Viz
sekci 8.

CLI cesta ke klíčům: **`bin/shpd-ds ds-setting get|set|list`**
([docs/cli.md](cli.md)) — drží whitelist deklarovaných klíčů (settingsPages
scope `ds` + parametry vrstvy C z [docs/ds-setup.md](ds-setup.md) §5.2)
a u parametrů vrstvy C validuje hodnoty při zápisu.

### Pravidla ukládání (`savePage`)

- Ukládají se **jen textová pole definovaná ve stránce** (whitelist);
  klíče mimo definici a `image` pole se tiše ignorují.
- Hodnoty se trimují; validuje se `maxLength` (422 s details per pole).
- **Prázdný string = smazání klíče** → čtenáři padnou na fallback
  (např. `app.name` → `main.json` `name`).

## 2. Klíče `app.*`

| Klíč | Obsah | Fallback |
|------|-------|----------|
| `app.name` | Plný název zdroje dat | `main.json` → `name` (read-only instalační config) |
| `app.shortName` | Titulek tabu prohlížeče + text v sidebaru | `app.name` → `main.json` name |
| `app.icon` | Metadata branding slotu `icon` (favicon, sidebar) | žádná ikona (browser default) |
| `app.companyLogo` | Metadata branding slotu `companyLogo` (login, později sestavy/faktury) | žádné logo |
| `app.theme` | DS-wide výchozí vzhled `{ mode, custom }` (Fáze 4) — platí pro uživatele bez vlastního | žádný → vestavěný Shipard light |
| `app.shell` | DS-wide výchozí shell `{ shell, params }` (UI shells Fáze 4) — platí pro uživatele bez vlastního | žádný → `sidebar` |

Metadata obrázkových klíčů zapisuje upload endpoint:
`{ filename, storedAs, mime, size, hash (sha256, 16 zn.), modified }`.

## 3. Branding sloty

Obrázky jako soubory v adresáři **`branding/`** v kořeni DS (vedle
`att/`), single-slot sémantika — slot drží nejvýš jeden soubor
`branding/{slot}.{ext}`, nový upload smaže starý soubor i s jinou
příponou. Adresář zakládají `ds-create` i `ds-upgrade`; `ds-reset` se ho
nedotýká → branding přežívá reset.

- **Sloty:** `icon`, `companyLogo` — whitelist `BrandingStorage::SLOTS`.
- **Povolené mime:** PNG, JPEG, WebP, SVG; pro slot `icon` navíc ICO.
  Detekce přes `finfo` z obsahu (ne z přípony); SVG bez XML deklarace
  finfo nepozná — fallback kontrola začátku obsahu (`<svg` / `<?xml`).
- **Max velikost:** 2 MB (`BrandingStorage::MAX_FILE_SIZE`).
- Logika v `src/Core/Settings/BrandingStorage.php` — bez závislosti na
  HTTP, logo čte i CLI/generátor sestav.

## 4. Endpointy — `AppController`

| Metoda | URL | Auth | Popis |
|--------|-----|------|-------|
| `GET` | `/_app/info` | **veřejné** | `{ name, shortName, icon, companyLogo, theme, shell }` — icon/logo jako `{url, hash}` nebo `null`; `theme` = DS default `{mode, custom}` nebo `null`; `shell` = DS default `{shell, params}` nebo `null` |
| `GET` | `/_app/branding/{slot}` | **veřejné** | Binární obsah; `Cache-Control: immutable` (cache-busting přes `?h={hash}`); SVG s CSP + nosniff |
| `POST` | `/_app/branding/{slot}` | auth | Multipart upload (pole `file`), vrací metadata |
| `DELETE` | `/_app/branding/{slot}` | auth | Smaže soubor i settings klíč |

Veřejné GET jsou vědomé rozhodnutí — login obrazovka zobrazuje název/logo
a favicon se načítá bez tokenu. Do `/_app/info` nesmí přibýt nic citlivého
(DB jméno, moduly, uživatelé) — `theme` je jen barva sidebaru a `shell`
jen jméno rozložení, veřejné spolu s brandingem. Výjimky z auth: `AuthMiddleware::isExempt()`
matchuje akce `('app', 'info')` a `('app', 'brandingGet')` — zápisové akce
mají vlastní action názvy (`brandingUpload`, `brandingDelete`), takže
exempt nejsou.

## 5. Frontend

- **`stores/appInfo.svelte.js`** — načítá `/_app/info` při bootu
  (`main.js`, před loginem) a po každé změně nastavení/obrázku. `apply()`
  nastavuje `document.title` a `<link rel="icon">`; sidebar/header/login
  čtou store reaktivně. Po loadu navíc tlačí DS defaulty do
  `themeStore.setDsDefault(theme)` a `shellStore.setDsDefault(shell)`
  (push směr appInfo → theme/shell, žádný kruhový import).
- **`components/settings/SettingsPage.svelte`** — render definice
  (label vlevo, input + hint vpravo), Uložit → POST, po uspěchu
  `appInfoStore.load()`.
- **`components/settings/ImageSlotField.svelte`** — náhled + Nahrát/Odebrat,
  upload jde okamžitě (mimo tlačítko Uložit) přes `api/app.js`.
- Nav typ `page`: `ContentArea.svelte` → `<SettingsPage tab={activeItem} />`,
  `navigation.svelte.js` normalizuje `pageId`.

## 6. Jak přidat další stránku / pole

1. Do `module.jsonc` modulu přidej `settingsPages` (id, name+`:cs`/`:en`,
   icon, fields) a `settingsItems: [{ "page": "<id>", "section": "...", "order": N }]`.
   Sekce musí existovat v `modules/install/base/config/settingsSections.jsonc`.
2. Klíče polí namespacuj podle modulu (`mail.signature`, ne `signature`).
3. Textové pole: `type: "text"` (+ `maxLength`, `hint:cs`). Obrázkové pole
   vyžaduje branding slot — slot je potřeba přidat do
   `BrandingStorage::SLOTS` + `SLOT_SETTINGS_KEYS` (whitelist je vědomě
   v PHP, ne v JSONC). Výběr z pevné nabídky: `type: "select"` +
   `options: [{value, label, label:cs, label:en}]` — pole bez nabídky
   parser zahodí, `savePage` přijme jen hodnotu z nabídky (422
   `INVALID_VALUE`), prázdná hodnota klíč smaže (čtenář použije svůj
   default). Definice jde na klienta s lokalizovanými `options` (`value`,
   `label`), renderuje `ui/Select`. První uživatel:
   `economy.assets.accPeriodicity` (stránka Odpisy, `year` / `month`).
4. `vendor/bin/shpd-ds ds-upgrade` v dev DS (kvůli rekompilaci
   settingsSections, pokud přibyla sekce).
5. Hodnoty čti v PHP přes `new SettingsStore($db)->get('klíč')` s fallbackem
   na rozumný default — klíč nemusí existovat.
6. Citlivá stránka, kterou nesmí číst ani ukládat ne-admin (na DS s ne-admin
   uživateli, typicky hosting portál): `"adminOnly": true` na stránce —
   `SettingsController` vrací 403 v `page`/`savePage` a položku skrývá
   z navigace. První uživatel: `hostingOidc` (`hosting.oidc.issuer`).

Další typy polí (`checkbox`, `textarea`) přijdou s první stránkou, která
je potřebuje — nový typ znamená čtyři serverová místa (whitelist
v `ModuleDefinition::fromArray()`, větev v `SettingsController::savePage()`,
propuštění atributů v `localizePageDefinition()`, případně
`DsSettingCommand::STRUCTURED_FIELD_TYPES`) a tři v `SettingsPage.svelte`
(`isSavedViaPage`, `splitValues`, render).

**Parametry vrstvy C (osnova, agenda DPH, fiskální rok, měna) settings
stránku nemají a mít nebudou** — ovládají se v ručně psaném panelu
`dsSetup` (`GET/POST /_setup/*`, komponenta `DsSetup.svelte`), protože
potřebují vysvětlující UI a `vatAgenda` je tříhodnotová
([docs/ds-setup.md](ds-setup.md) D14). Nehledej je mezi field typy.

## 7. Testy

- `tests/Integration/Settings/SettingsStoreTest.php` — get/set/delete,
  JSON round-trip, upsert, cache (vyžaduje `SHIPARD_INTEGRATION_DS_PATH`).
- `tests/Unit/Api/Controller/SettingsControllerTest.php` — page/savePage
  nad reálnou definicí appSettings, mockovaná DB.
- `tests/Unit/Api/Controller/AppControllerTest.php` — info fallbacky,
  upload validace, hlavičky, delete.
- `tests/Unit/Core/Settings/BrandingStorageTest.php` — mime detekce
  (vč. SVG fallbacku), sloty, store/replace/delete.

Spouštění: `vendor/bin/phpunit --filter 'Settings|App|Branding'`.

## 8. Per-user scope + Nastavení účtu (`account` mód)

Settings page nese pole **`scope`** (`ds` | `user`, default `ds`). Určuje,
kam jdou hodnoty:

- `ds` → `SettingsStore` nad `core_system_settings` (sdílené na DS).
- `user` → `UserSettingsStore` nad `core_system_user_settings`, scoped na
  `user_id` (per-uživatel, přenáší se mezi zařízeními).

`SettingsController::storeForPage()` vybere store podle scope; user-scope
stránka vyžaduje přihlášeného uživatele s `userId` (jinak 401).

### `UserSettingsStore` — `src/Core/Settings/UserSettingsStore.php`

Kopie `SettingsStore` scoped na `user_id`; unikát je dvojice
`(user_id, key)`, upsert přes `ON DUPLICATE KEY UPDATE`. Tabulka
`core_system_user_settings` (tableId 105) je v `tables[]` i `keepOnReset[]`
modulu `core.system`. Referenční integrita na `user_id` je na aplikační
úrovni (projekt nepoužívá FOREIGN KEY).

### Field typy `theme` / `shell` / `language`

`language` (a user-scope `theme`/`shell`) jsou řízené widgety vázané na
klientské stores (`themeStore`, `shellStore`, `language`), hodnota se
ukládá na server (zdroj pravdy). Validace v `savePage()`:

- `theme` — objekt `{ mode: light|dark|custom, custom: {…}|null }`.
  **User-scope** (`account.theme`) nese navíc `follow` flag:
  `{follow:true}` (sleduj DS default) nebo `{follow:false, mode, custom}`
  (override); legacy `{mode, custom}` bez follow → override (`follow:false`).
  **DS-scope** (`app.theme`) follow nezná — případný flag se zahodí,
  uloží se jen `{mode, custom}`. Větvení podle `$pageDef['scope']`.
- `shell` — objekt `{ shell, params }`; `shell` proti serverovému
  allowlistu `SettingsController::SHELLS` (`sidebar`, `classic`, `wild`
  — nový shell = přidat jméno), `params` volitelný objekt (v1 passthrough bez
  interpretace). Follow kontrakt identický s theme: user-scope
  `{follow:true}` | `{follow:false, shell, params}` (legacy bez follow →
  override), DS-scope follow zahazuje.
- `language` — string `cs` | `en` | `auto`.

Klíče: user store `account.theme`/`account.shell`/`account.language`,
DS store `app.theme`/`app.shell`.
**User-scope** theme/shell/language jdou na klientu **mimo tlačítko
Uložit** — mění se okamžitě (live preview / soft swap / reload)
a synchronizují přes `POST`. **DS-scope** `app.theme`/`app.shell` se
naopak ukládají **přes tlačítko Uložit** jako běžná hodnota (controlled
`DsThemeField`/`DsShellField`) — DS-wide default nevysílá mezistavy všem
uživatelům. Render větev v `SettingsPage` rozhoduje podle
`definition.scope`: `user` → živý `ThemeField`/`ShellField`, `ds` →
`DsThemeField`/`DsShellField` do `values`.

### DS-wide výchozí vzhled (Fáze 4)

Pole **`app.theme`** [type `theme`] v `appSettings` (scope `ds`) drží
DS-wide default. Efektivní vzhled na klientu: `follow ? (DS default ??
Shipard) : user override`. DS default se na klienta dostane přes `appInfo`
(`/_app/info` → `theme`) → `themeStore.setDsDefault()` (vč. localStorage
anti-flash cache `shpd_ds_theme`). Změna DS defaultu správcem se projeví
u všech follow-uživatelů po jejich příštím loadu; override-uživatelů se
nedotkne. Omezení nastavení DS defaultu jen na správce je zatím mimo
rozsah — smí kdokoli s přístupem do Nastavení aplikace. Detaily efektivního
výpočtu a follow přepínače: [`design-system.md`](design-system.md)
(sekce 9), [`frontend.md`](frontend.md) (sekce *Theme management*).

### DS-wide výchozí shell (UI shells Fáze 4)

Pole **`app.shell`** [type `shell`] v `appSettings` (scope `ds`) drží
DS-wide default rozložení; **`account.shell`** v `accountBasic` (scope
`user`) follow/override volbu uživatele. Efektivní shell na klientu:
`follow ? (DS default ?? 'sidebar') : override`, neznámé jméno padá na
`sidebar` (`utils/shell.js`). DS default jde na klienta přes `appInfo`
(`/_app/info` → `shell`) → `shellStore.setDsDefault()`. Na rozdíl od
theme **bez anti-flash bootstrapu** — shell rozhoduje, který komponentový
strom se mountne, localStorage (`shpd_shell`, `shpd_ds_shell`) je jen
boot cache. Přepnutí je soft swap komponenty v `AppShell` (uživatel
zůstane na aktivní položce); mobil (<768 px) a settings/account módy
volbu ignorují — vždy sidebar-style chrome. Detaily:
[`frontend.md`](frontend.md) (sekce *Shelly*),
[`ui-shells.md`](ui-shells.md) §11/§12.

### Account mód — vlastní navigační strom

Třetí navigační mód `account` (vedle `app` a `settings`) má samostatný
strom:

- Sekce v `modules/install/base/config/accountSections.jsonc`, registrace
  `global.accountSections` v `install.base` `config[]`.
- Položky `accountItems[]` v `module.jsonc` (sdílený parser se
  `settingsItems` — `ModuleDefinition::parseNavItems()`).
- `SettingsController::navigation(..., $kind)` parametrizuje strom
  (`settings` → `global.settingsSections` + `settingsItems`; `account` →
  `global.accountSections` + `accountItems`).
- Endpoint `GET /_ui/account/navigation` (Router → `settings`/
  `accountNavigation`). Page/savePage jdou přes existující
  `/_ui/settings/page/{id}` — lookup stránky je společný, scope řeší
  definice.

První konzument: page `accountBasic` (scope `user`, pole `account.avatar`,
`account.theme`, `account.language`) v `core.system`, sekce **Základní**.

### Panel — klientská komponenta v navigaci

Čtvrtý druh nav položky vedle `viewer|table|page`: **`panel`** — pro obsah,
který nejde poskládat z generických settings fieldů (formuláře s vlastní
logikou, tabulky akcí). Server dodává jen id + lokalizovaný label + ikonu;
vykreslení řeší frontend.

- Registrace v `module.jsonc`: `panels: [{id, name, name:cs, icon}]` +
  položka `{ "panel": "<id>", "section": "...", "order": N }` v
  `accountItems[]` (nebo `settingsItems[]`).
- Panel může vstoupit i do **hlavní navigace**: deklaruje-li `navSection`
  (+ volitelně `navOrder`, `adminOnly`), emituje ho `NavigationController`
  jako root leaf / položku sekce — vzor `hostingPortal` v `hosting.core`
  (viz [modules.md](modules.md) — sekce hlavního sidebaru).
- Navigace emituje `{type: 'panel', panelId, label, icon}`
  (`SettingsController::collectItems()`).
- Frontend: mapa `panelId → komponenta` v `ContentArea.svelte`
  (`panelComponents`); `panelId` protéká přes `Sidebar.handleItemClick` a
  `navigationStore.navigate()`.

První konzument: `accountSecurity` (sekce Základní, ikona `lock`) —
komponenta `components/account/AccountSecurity.svelte` se změnou hesla a
správou relací (auth Fáze 0b, viz [`auth.md`](auth.md)).

Read-only panel `dsAbout` (O zdroji dat, `components/settings/DsAbout.svelte`,
`GET /_ui/ds-about`) má vlastní sekci `about` s `order: 200` — poslední
položka Nastavení. Sekce existuje jen v profilu `install.base`; hosting
profil ji záměrně nemá a `settingsItem` z `core.system` se tam v navigaci
tiše zahodí (položka bez existující sekce), žádný gate není potřeba.
Viz `tasks/ds-about-panel.md` (D1, D6).


### Uživatelský avatar (per-user fotka)

Pole **`account.avatar`** [type `avatar`] v `accountBasic` (scope `user`)
drží fotku přihlášeného uživatele. Zobrazí se v patičce sidebaru vedle jména;
bez nastavené fotky zůstává kolečko s iniciálou.

**Model — per-user „branding slot".** Avatar je hybrid brandingu (binární
soubor) a per-user nastavení (per `user_id`, jen na dané DB). Soubor žije v
`branding/avatars/{userId}.{ext}` — stejný `branding/` strom, který
`ds-reset` nemaže, takže avatar reset přežívá. Metadata
(`{filename, storedAs, mime, hash, modified}`) jdou do
`core_system_user_settings` pod klíč `account.avatar` (scope `user`).

- Logika: `src/Core/Settings/AvatarStorage.php` — single-slot per uživatel
  (nový upload smaže starý i s jinou příponou), validace mime z obsahu
  (PNG/JPEG/WebP; **SVG záměrně nepovolen** — avatar je fotka, ne vektor,
  a vyhneme se XSS ploše), max 2 MB.
- **Downscale při uploadu** na čtvercový 256px JPEG přes `vipsthumbnail
  --smartcrop attention` (stejný libvips jako u příloh). Slot tak drží malý
  soubor místo originálu. Selhání vipsu → fallback kopie originálu.

**Endpointy — `AppController`, vše za auth.** Na rozdíl od brandingu (jehož
GET je veřejný/exempt) je avatar per-uživatel a celý za auth, včetně GET.
Uživatel se bere z `AuthContext` (tokenu), **ne z URL** — žádný `{userId}`
parametr, takže nelze číst cizí avatary.

| Metoda | URL | Popis |
|--------|-----|-------|
| `GET` | `/_app/avatar` | Binární obsah avataru přihlášeného uživatele; `Cache-Control: private` |
| `POST` | `/_app/avatar` | Multipart upload (pole `file`), downscale, vrací metadata |
| `DELETE` | `/_app/avatar` | Smaže soubor i settings klíč |

`AuthMiddleware::isExempt()` avatar akce **nematchuje** (na rozdíl od
`info`/`brandingGet`) — zůstávají za auth.

**Frontend — blob fetch kvůli auth.** Protože GET vyžaduje `Authorization:
Bearer` a `<img src>` hlavičku neposílá, avatar se nenačítá přímo do `img`.
Místo toho `stores/avatar.svelte.js` fetchne blob s hlavičkou a vystaví
`URL.createObjectURL` object URL, na který se naváže `<img>` v sidebaru i
náhled v `AvatarSlotField`. Object URL se při každé výměně revokuje.

- `stores/avatar.svelte.js` — `load()` po loginu (`App.svelte` onSuccess) i
  autentizovaném bootu (`main.js`); `reload()` po uploadu/smazání; `clear()`
  volá `authStore.clearAuth()` při logoutu (revoke blob).
- `components/settings/AvatarSlotField.svelte` — náhled (kulatý) +
  Nahrát/Odebrat, upload jde okamžitě mimo tlačítko Uložit; viditelnost
  Odebrat i náhled se odvozují z `avatarStore.objectUrl` (jediný zdroj
  pravdy). `SettingsPage` má pro `field.type === 'avatar'` vlastní větev.
- `Sidebar.svelte` — patička: `avatarStore.objectUrl ? <img> : iniciála`.

**Testy:**
- `tests/Unit/Core/Settings/AvatarStorageTest.php` — mime (PNG ano, SVG/text
  ne), validace (oversized, SVG), store (JPEG výstup, nahrazení starého,
  izolace uživatelů), deleteUserFiles napříč příponami.
- `tests/Unit/Api/Controller/AppControllerTest.php` — avatar* akce: auth
  gating, 404 (chybí metadata/soubor), upload (unsupported/SVG → 422,
  úspěch → 201 + `1.jpg` + JPEG), delete, `avatarInfo()` URL.

### Frontend (account)

- `navigation.svelte.js` — mód `account`, `accountActiveItem`,
  `enterAccount`/`exitAccount`/`exitToApp`.
- `Sidebar.svelte` — dropdown patky „Nastavení účtu" → `enterAccount`;
  back-bar `mode !== 'app'`; mode-aware fetch `/_ui/account/navigation`.
  **Dropdown vzhledu v patce zanikl (Fáze 4)** — vzhled je nastavení, ne
  rychlý přepínač; sidebar už neotevírá ThemePanel (dělá to `ThemeField`).
- `SettingsPage.svelte` — větve pro `theme` (scope `user` → `ThemeField`
  s follow přepínačem, otevírá ThemePanel přes probublaný `onOpenThemePanel`;
  scope `ds` → `DsThemeField` ukládaný přes Uložit) a `language`
  (`LanguageField`). Uložit tlačítko pro `text` + ds-scope `theme` pole.
  Sdílené UI: `ThemeModeSegments` (segmented control) a `ThemeSwatches`
  (báze/presety/opacity/picker) — používá je ThemeField/DsThemeField
  i ThemePanel.
- `stores/accountPrefs.svelte.js` — po loginu/bootu (`App.svelte`
  onSuccess + `main.js` při autentizovaném startu) `load()` ze serveru,
  aplikuje `themeStore.applyFromServer()`, `shellStore.applyFromServer()`
  a (liší-li se) `language.setMode()`. localStorage zůstává anti-flash /
  boot cache; server vyhrává.
- Server push: `themeStore.setMode/setCustom`, `shellStore.setFollow/
  setOverride` a `language.setMode` zapisují přes `api/account.js`
  (`pushAccountPrefs`) — odděleno od `accountPrefs` storu kvůli kruhovému
  importu.

### Testy (Fáze 3)

- `tests/Unit/Core/Settings/UserSettingsStoreTest.php` — scope, cache, upsert.
- `tests/Unit/Core/Module/ModuleDefinitionTest.php` — scope default/user,
  field typy theme/language, `accountItems`.
- `tests/Unit/Api/Controller/SettingsControllerTest.php` — user-scope
  page/savePage (theme/language validace), account navigation.

Fáze 4: `SettingsControllerTest` — `account.theme` follow tvary
(`{follow:true}`, override, legacy → override), `app.theme` scope ds
(follow zahozen, invalid → 422), `scope` v definici; `AppControllerTest`
— `/_app/info` vrací `theme` (null když nenastaveno).

UI shells Fáze 4 (typ `shell`): `SettingsControllerTest` — `account.shell`
follow/override (params passthrough), neznámé jméno → 422, `wild` projde
(Fáze 6), `app.shell` bez follow, ne-objekt → 422; `AppControllerTest` —
`/_app/info` vrací `shell` (null když nenastaveno); frontend
`tests/Unit/shell.test.mjs` — `resolveShell`/normalizace (vč. `wild`);
`tests/Unit/wildLanding.test.mjs` — přistání záložek wild shellu.
