# Modul: Zařízení (core.devices)

Registrovaná zařízení nativní aplikace Shipardu (macOS, iPadOS, iOS, později
i hub bez okna), jejich periferie a podepsaná volání zařízení na server —
bez session, jen podpisem výzvy klíčem zařízení. Kontrakt mezi aplikací,
webovou stranou a serverem je v [docs/native-bridge.md](../../../docs/native-bridge.md)
(§7 Zařízení ↔ server); tenhle modul je jeho serverová část (#177 task 3).

Záznam zařízení plní roli **pracoviště** ze starého Shipardu: jedno místo
pro název, režim, pokladnu, role tiskáren a čteček, automatické zamčení
a (od tasku 6) zařazené uživatele. Samostatný číselník pracovišť se
nezavádí; v rozhraní zůstává název „Zařízení“ — sedí i na osobní Mac.

Modul je v `install.base`. Endpointy `/_devices/*` žijí v jádru
(`src/Api/Controller/DevicesController.php`) a na zdroji dat bez modulu
odpovídají 404.

## Závislosti

- `core.system`

Na číselnících modul nezávisí: pokladnu zařízení (`cash_desk`) přidává
`economy.codebooks` jako extension sloupec
([extensions/core_devices_devices.jsonc](../../economy/codebooks/extensions/core_devices_devices.jsonc)).
Zdroj dat bez číselníků sloupec nemá a formulář prvek nevykreslí;
`GET /_devices/self` pokladnu nevrací (U16).

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_devices_devices](tables/core_devices_devices.jsonc) | Zařízení: název, režim, automatické zamčení; identita (`device_id`), veřejný klíč a časy registrace a posledního kontaktu |
| [core_devices_peripherals](tables/core_devices_peripherals.jsonc) | Periferie zařízení podle hlášení aplikace (`peripheral_id`, druh, připojení, stav, `present`) a jejich role |
| [core_devices_challenges](tables/core_devices_challenges.jsonc) | Jednorázové výzvy pro podpis; bez Document třídy |

- Všechny tři tabulky jsou jen pro administrátory (`adminOnly`) a záznamy
  v nich zakládá jen aplikace (`systemManaged`).
- Zařízení a periferie jsou v `keepOnReset` — registraci by po `ds-reset`
  musel administrátor opakovat na každém zařízení. Výzvy reset zahazuje.
  Pokladna zařízení může po resetu ukazovat na neexistující pokladnu.
- Identita (`device_id`, `peripheral_id`, `challenge`) má collation
  `utf8mb4_bin`: výzva je base64url a `deviceId` součást podepisovaného
  řetězce — porovnávají se přesně, ne podle české abecedy.
- Stavy zařízení `core.devices.docStates`: **Aktivní** (40, editovatelné
  bez přechodu stavu) a **Zneplatněné** (70, jen ke čtení). Zneplatnění je
  konečné a `device_id` je unikátní i přes zneplatněná zařízení — nová
  registrace téhož zařízení znamená nové `deviceId` a nový záznam.

## Podepsaná volání

```
1. POST /_devices/challenge {deviceId, purpose}  → výzva (32 bajtů base64url, 60 s)
2. podpis řetězce  shipard-device-v1|<účel>|<deviceId>|<výzva>   (ECDSA P-256, SHA-256)
3. volání s hlavičkami X-Shipard-Device, X-Shipard-Challenge, X-Shipard-Signature
```

- Výzva je jednorázová a vázaná na účel a zařízení; zařízení smí mít nejvýš
  5 nevyužitých platných výzev (`RATE_LIMITED`). Spotřeba je atomický
  `UPDATE … WHERE used_at IS NULL` rozhodnutý počtem ovlivněných řádků —
  ze souběžných pokusů uspěje jeden.
- Účel určuje **routa**, ne klient: `register`, `self`, `peripherals`
  (`DevicePurpose`). Výzvu `register` vydá server jen se session
  administrátora, ostatní jen registrovanému aktivnímu zařízení.
- Pořadí ověření: zařízení existuje → je aktivní → výzva se spotřebuje →
  podpis sedí. Výzva se spotřebuje před ověřením podpisu, takže jedna výzva
  je jeden pokus. Chyby (`DEVICE_UNKNOWN`, `DEVICE_REVOKED`,
  `CHALLENGE_INVALID`, `SIGNATURE_INVALID`) nenesou podrobnosti.
- Úspěšné volání zapíše `last_seen_at` a z nepovinné hlavičky
  `X-Shipard-App-Version` verzi aplikace.
- V režimu jen pro čtení fungují `challenge` a `self`; registrace
  a hlášení periferií vrací `DS_READ_ONLY`.
- Volání zařízení mají vlastní limit požadavků (`RateLimitMiddleware`):
  120 za minutu na `deviceId` a strop 1200 za minutu na IP adresu — zařízení
  jedné provozovny tak nesdílejí anonymní limit 60 za minutu a střídání
  vymyšlených ID limit neobejde. Jedna synchronizace jsou čtyři požadavky.

Hlášení periferií (`PUT /_devices/self/peripherals`) nese vždy úplný
seznam: známá periferie se aktualizuje, nová založí a chybějící dostane
`present = 0` — nemaže se a role jí zůstává. Sloupec `role` hlášení
nepřepisuje.

## Role periferií

Role (`core.devices.peripheralRoles`) přiřazuje administrátor v záznamu
zařízení; podle ní server směruje tiskové úlohy (`core.prints`,
`PrintJobRouter` — [docs/prints.md](../../../docs/prints.md) § 14.2). Pravidla
role jsou data — `kinds` (kterému druhu periferie jde přiřadit) a `single`
(na zařízení nejvýš jednou) — a čte je jen `PeripheralRoles`; kód se neptá
na klíč role. Ve verzi 1 je jediná role `receipt`.

Pravidla hlídá `PeripheralDocument`, tedy formulář. Generické CRUD (`PATCH`)
jde mimo dokumenty a jedinečnost role neověří — kdo podle role hledá
periferii, musí počítat i s víc řádky.

**Vazba na tiskové úlohy.** Modul `core.prints` na tomto modulu závisí
(opačně ne): `PrintJobRouter` hledá podle `deviceId` aktivní zařízení
(`DeviceRequestAuthenticator::activeDevice()`) a jeho přítomnou tiskárnu
s rolí. Zařízení bez tiskárny s rolí, s nepřítomnou tiskárnou nebo s víc
tiskárnami s rolí úlohu nedostane — `NO_PERIPHERAL_FOR_ROLE`,
`PERIPHERAL_NOT_PRESENT`, `ROLE_AMBIGUOUS`; první nalezenou router nikdy
nevybírá. Tabulka úloh `core_prints_jobs` odkazuje na řádek zařízení
i periferie.

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [DeviceKey.php](src/DeviceKey.php) | Veřejný klíč (SPKI DER, jen P-256) a ověření podpisu; jediné místo, kde se skládá podepisovaný řetězec |
| [ChallengeService.php](src/ChallengeService.php) | Vydání, atomická spotřeba a úklid výzev |
| [DevicePurpose.php](src/DevicePurpose.php) | Účely výzev a které chtějí session administrátora |
| [DeviceRequestAuthenticator.php](src/DeviceRequestAuthenticator.php) | Ověření podepsaného volání → `DeviceContext` |
| [DeviceRegistrationService.php](src/DeviceRegistrationService.php) | Registrace a zneplatnění — jediné místo, kde záznam zařízení vzniká a končí |
| [PeripheralSync.php](src/PeripheralSync.php) | Hlášení periferií: tvar podle kontraktu, párování podle `peripheral_id` |
| [DeviceException.php](src/DeviceException.php) | Chyby s kódem a HTTP stavem z kontraktu |
| [DeviceDocument.php](src/DeviceDocument.php) | Úprava nastavení aktivního zařízení; záznam nejde založit, smazat ani mu změnit stav |
| [PeripheralDocument.php](src/PeripheralDocument.php) | Změna role periferie a její pravidla |
| [DeviceModes.php](src/DeviceModes.php), [PeripheralRoles.php](src/PeripheralRoles.php) | Čtení číselníků režimů a rolí |
| [DevicesViewer.php](src/DevicesViewer.php) | Nastavení → Zařízení: seznam bez Přidat, detail s registrací a periferiemi |
| [DevicesForm.php](src/DevicesForm.php) | Formulář zařízení s podtabulkou periferií |
| [Actions/RevokeDeviceAction.php](src/Actions/RevokeDeviceAction.php) | Akce záznamu Zneplatnit (jen administrátor) |
| [Command/](src/Command/) | Příkazy `device-list`, `device-revoke`, `device-challenges-prune` |

Frontend: panel **Toto zařízení** (`frontend/src/components/devices/`) —
viz [docs/frontend.md](../../../docs/frontend.md) → Nativní most.

## Příkazy a cron

| Příkaz | Popis |
|---|---|
| `shpd-ds device-list [--json]` | Přehled zařízení: název, režim, platforma, stav, poslední kontakt, `deviceId` |
| `shpd-ds device-revoke <deviceId> [--yes]` | Zneplatnění bez UI (ztracené zařízení, nedostupná aplikace) |
| `shpd-ds device-challenges-prune` | Smaže použité a prošlé výzvy — úloha cronu ve slotu `five-minutes`, i v režimu jen pro čtení |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.devices.docStates` | [config/docStates.jsonc](config/docStates.jsonc) | Stavy zařízení |
| `core.devices.modes` | [config/modes.jsonc](config/modes.jsonc) | Režimy `personal` / `shared` |
| `core.devices.peripheralRoles` | [config/peripheralRoles.jsonc](config/peripheralRoles.jsonc) | Role periferií a jejich pravidla |
| `core.devices.viewerLabels` | [config/viewerLabels.jsonc](config/viewerLabels.jsonc) | Texty vieweru a akce Zneplatnit |
| `core.devices.formTexts` | [config/formTexts.jsonc](config/formTexts.jsonc) | Texty formuláře |

Politika PINu `auth.pin` zdroje dat ([docs/auth.md](../../../docs/auth.md))
žije v jádru (`Shipard\Core\Auth\PinPolicy`); modul ji jen vrací
v `GET /_devices/self`.

## Co modul zatím nedělá

- Zařazení uživatelů, PIN, zamčení a `device_id` na session — task 6;
  `GET /_devices/self` proto vrací `users: []`.
- Výměna hardwaru („Registrovat jako náhradu za…“, převzetí nastavení ze
  zneplatněného zařízení) — mimo verzi 1.
