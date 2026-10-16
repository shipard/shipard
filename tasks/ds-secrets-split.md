# Tajemství zdroje dat mimo `config/main.json` (#126 task B1)

**Stav:** hotovo — implementováno 2026-10-16 (6 commitů, oddíl „Hotovo“); zbývá nasazení `ds-upgrade-all` na vývojových strojích, testovacím serveru a hostingu, potom task B2

> PRD pro Claude Code (6 commitů). Design: issue #126, komentář
> „Rozhodnutí (2026-10-16)“, body D1–D5. Task A (`tasks/ds-data-class.md`)
> je hotový; `ds-list` už tajemství nevypisuje (allowlist). Tento task (B1)
> tajemství přesune a nechá přechodné čtení z `main.json`; odstranění
> přechodu a uvolnění pravidla pro AI (D4 konec, D5) je samostatný task B2
> (oddíl „Navazuje — B2“). Navazuje na `SecretsFileWriter`
> (`tasks/hosting-fix-secrets-owner-and-gw-errors.md`) a `MainConfigFile`
> (`tasks/hosting-sync-main-json-owner.md`).

## Kontext

`config/main.json` zdroje dat dnes nese vedle běžné konfigurace i tři druhy
tajemství: heslo k databázi, heslo relay pošty a `clientSecret` OIDC
providerů. Kvůli nim se soubor nesmí číst (pravidlo pro Clauda,
`deny` v `.claude/settings.json`) a s každou kopií konfigurace putují
hesla. Tajemství se přesunou do `secrets/` — co tajemství, to soubor —
a `main.json` zůstane čistá konfigurace.

Přesun dělá `ds-upgrade` (běží při každém upgradu na všech serverech).
Dokud neproběhne všude, čte `DataSourceConfig` tajemství přechodně
i z `main.json`.

## Před implementací přečti

- Issue #126 — komentář „Rozhodnutí (2026-10-16)“, body D1–D5
- `docs/operations/secrets.md` (threat model `secrets/`)
- `docs/auth.md` (`auth.providers`), `docs/mail/outbound.md` (`mail.relay`),
  `docs/hosting.md` § auth providers, `docs/operations/hosting-adopt-existing.md`
- `docs/migration-guide.md` (záloha a obnova)
- `src/Core/Config/DataSourceConfig.php`
- `src/Core/Auth/AuthPolicy.php`, `OidcProviderConfig.php`,
  `src/Core/Mail/MailRelayConfig.php`
- `src/Core/Security/SecretsFileWriter.php`, `DsSecretCipher.php`
  (`secretsDirPath`)
- `src/Core/Server/MainConfigFile.php`, `MainConfigPatcher.php`
  (`mergeAuthProvider`), `HostingSyncRunner.php` (`patchAuthProvider`)
- `src/Command/Server/DsCreateCommand.php`,
  `src/Command/DataSource/DsUpgradeCommand.php`,
  `src/Command/DataSource/DsClassCommand.php` (vzor příkazu s akcemi),
  `src/Command/Server/DoctorCommand.php`
- `src/Core/Server/PermissionSpec.php` (`secrets/` rekurzivně, `0600`)

## Scope

**Uvnitř:** soubory tajemství v `secrets/`; líné čtení v `DataSourceConfig`
s přechodným čtením z `main.json`; migrace v `ds-upgrade`; zápis tajemství
v `ds-create` a `hosting-sync` rovnou do `secrets/`; příkaz
`shpd-ds ds-secret` pro ruční zadání; sekce `doctor`; testy; dokumentace.

**Mimo:**

- odstranění přechodného čtení z `main.json`, `doctor` chyba, uvolnění
  pravidla pro AI a `deny` (task B2),
- tajemství v `/etc/shipard/server.json` (relay heslo serveru, admin
  databáze) — soubor je root `0600`, Claude ho nečte,
- šifrování souborů tajemství (D2 — stejný threat model jako `secrets.key`),
- rotace hesla k databázi,
- `hosting-oidc-client --secret` (hodnota v argumentu, samostatná věc),
- změna práv `main.json` (zůstává `0600`).

## 1. Tajemství a soubory (D1, D2)

| Soubor v `secrets/` | Dřív v `main.json` | Zapisuje | Čte |
|---------------------|--------------------|----------|-----|
| `db.password` | `database_password` | `ds-create`, migrace | `DataSourceConfig::getDatabasePassword()` |
| `mail-relay.password` | `mail.relay.password` | `ds-secret set`, migrace | `DataSourceConfig::getMailRelay()` |
| `oidc-<id>.client-secret` | `auth.providers[].clientSecret` | `hosting-sync`, `ds-secret set`, migrace | `DataSourceConfig::getAuthPolicy()` |

- `<id>` je `id` providera (`[a-z0-9-]+`, validuje `OidcProviderConfig`
  i `mergeAuthProvider`). Přípona `.client-secret` se nekříží
  s `oidc-op.key` hostingu.
- Obsah souboru je holá hodnota bez konce řádku. Při čtení se odstraní
  jedno koncové `\n` nebo `\r\n` (soubor uložený editorem), jinak se
  hodnota nemění; prázdný soubor = tajemství chybí (U2).
- Zápis výhradně `SecretsFileWriter::write()` (adresář `0700`, soubor
  `0600`, fsync, rename, vlastník podle kořene zdroje dat). Bez šifrování.
- `main.json` žádné odkazy nenese — název souboru je odkaz (D2). Ostatní
  klíče `mail.relay` (`host`, `port`, `username`, …) a providerů zůstávají
  v `main.json`.
- Nové tajemství v budoucnu = nový řádek tabulky a soubor, nikdy klíč
  v `main.json` (D1).

## 2. Čtení (D3)

Nová třída `Shipard\Core\Config\DataSourceSecrets` (`src/Core/Config/`):

- konstanty `DB_PASSWORD = 'db.password'`,
  `MAIL_RELAY_PASSWORD = 'mail-relay.password'`; statická
  `oidcClientSecret(string $providerId): string` → `oidc-<id>.client-secret`;
  `isValidName(string $name): bool`,
- `__construct(string $dataSourceDir)`, `get(string $name): ?string` —
  čte soubor až při volání, výsledek cachuje; chybějící nebo prázdný
  soubor = `null`; nečitelný soubor = `\RuntimeException` s cestou (nikdy
  s obsahem),
- `has(string $name): bool` — jen existence a neprázdnost, obsah nečte
  (pro `doctor` a `ds-secret list`).

`DataSourceConfig`:

- `load()` přestane vyžadovat `database_password`
  (`required` = `id`, `name`, `database_name`, `database_user`, `created`).
- `getDatabasePassword()` — hodnota podle přednosti níže; když chybí
  obojí, `\RuntimeException`
  `Database password missing — expected secrets/db.password (run shpd-ds ds-upgrade)`.
- `getMailRelay()` — doplní `password` do pole předaného
  `MailRelayConfig::fromArray()`; chybí-li obojí, zůstane `null` jako dnes.
- `getAuthPolicy()` — před `AuthPolicy::fromArray()` doplní každému
  providerovi `clientSecret`. Hláška `OidcProviderConfig` pro chybějící
  tajemství zmíní soubor (`… is missing clientSecret (secrets/oidc-<id>.client-secret)`).
- **Přednost v přechodném období (U1):** je-li hodnota v `main.json`,
  platí ta (zdroj dat ještě neprošel migrací, nebo ji tam člověk vložil
  — migrace ji vždy přesune a přepíše soubor); jinak soubor. Po B2 jen
  soubor.
- Tajemství se čte líně: kód, který potřebuje jen konfiguraci (dispatcher
  cronu, `DataSourceModules`, `ds-class`, `DataSourceInventory`), do
  `secrets/` nesáhne.
- `hasSecretsInMainConfig(): bool` a `getSecretsInMainConfig(): list<string>`
  (názvy souborů, ne hodnoty) — pro `doctor` a `ds-secret list`.

## 3. Migrace v `ds-upgrade` (D4)

Nová třída `Shipard\Core\Server\DataSourceSecretsMigrator`:

- `migrate(string $dsDir): MigrationResult` — jen soubory, bez databáze
  a bez `DataSourceConfig` (funguje i na zdroji, jehož konfigurace je
  jinak rozbitá).
- Postup:
  1. přečíst `main.json` (nečitelný / nevalidní → chyba, nic se nemění),
  2. posbírat tajemství podle tabulky v oddílu 1 (prázdné hodnoty
     přeskočit),
  3. každé zapsat `SecretsFileWriter::write()`; existuje-li soubor
     s jinou hodnotou, přepíše ho hodnotou z `main.json` a vrátí
     varování (U3),
  4. teprve po zápisu všech souborů `MainConfigFile::patch()` odebere
     klíče z `main.json` (u `mail.relay` jen `password`, u providerů jen
     `clientSecret`).
- Pád mezi kroky 3 a 4 nechá tajemství v obou souborech se stejnou
  hodnotou; další běh migraci dokončí. Bez tajemství v `main.json` je
  migrace no-op (idempotence).
- Provider s neplatným `id`: tajemství nechá v `main.json` a vrátí
  varování (soubor nejde pojmenovat; `AuthPolicy` ho odmítne tak jako
  tak) (U5).
- Výsledek nese jen názvy přesunutých tajemství a varování, nikdy
  hodnoty.

`DsUpgradeCommand`: nový krok **0 — před** sestavením `DataSourceConfig`.
Výpis i bez `-v`: `[INFO] Moved N secret(s) from config/main.json to secrets/: db.password, …`;
varování jako `[WARN]`; chyba migrace = `[ERROR]` a exit 1 (konfigurace
zůstane, jak byla). Migrátor zůstává natrvalo — obnova starší zálohy
přinese starý `main.json` (D4).

## 4. Zapisovatelé

- **`ds-create`:** heslo k databázi zapíše `SecretsFileWriter::write($dsDir, 'db.password', …)`
  **před** zápisem `main.json`; do `main.json` se nedostane. Selhání =
  exit 1 s hláškou (stejně jako dnes `secrets.key`).
- **`hosting-sync`** (`patchAuthProvider`): `clientSecret` z fronty zapíše
  do `oidc-<id>.client-secret` (`SecretsFileWriter`), **potom** sloučí
  providera bez `clientSecret` do `main.json`. Selhání zápisu = krok
  failed, `main.json` beze změny.
- **`MainConfigPatcher::mergeAuthProvider()`** odmítne providera
  s klíčem `clientSecret` (`\InvalidArgumentException`) — pojistka, aby
  žádný budoucí zapisovatel tajemství do `main.json` nevrátil (D1).
- Výpis `ds-create` a log `hosting-sync` hodnoty nikdy neobsahují.

## 5. `shpd-ds ds-secret` (U4)

Ruční zadání tajemství, které dnes správce píše do `main.json` (GitHub,
Google, „Shipard ID“ z `hosting-oidc-client --generate`, relay pošty).

```
shpd-ds ds-secret                                  list (výchozí akce)
shpd-ds ds-secret set mail-relay.password
shpd-ds ds-secret set oidc-<id>.client-secret
```

- Nový `DsSecretCommand` v `src/Command/DataSource/`, registrace
  v `DsApplicationFactory`; struktura podle `DsClassCommand`.
- **list:** tabulka `Name | Used by | Status` — `db.password` (vždy),
  `mail-relay.password` (když je v `main.json` sekce `mail.relay`),
  `oidc-<id>.client-secret` pro každý provider. Stav `present`,
  `missing`, `in main.json (run ds-upgrade)`. Ostatní soubory v
  `secrets/` jen počtem (`secrets.key` apod.). Nikdy hodnoty ani délky.
- **set:** jen `mail-relay.password` a `oidc-<id>.client-secret`
  (validní `id`; není-li provider v `main.json`, zapíše a varuje).
  `db.password` odmítne — musí sedět na uživatele databáze.
  - Hodnota **nikdy z argumentu**. Interaktivní terminál → skrytý dotaz
    (`Question::setHidden`); jinak se čte stdin do EOF a odstraní se jedno
    koncové `\n`. Prázdná hodnota = exit 1.
  - Zápis `SecretsFileWriter::write()` (varování vlastnictví vypíše);
    je-li stejné tajemství ještě v `main.json`, odebere ho
    `MainConfigFile::patch()` (jinak by podle U1 mělo přednost).
  - Výstup: `Secret <name> written.` — bez hodnoty.
- Exit 1: není adresář zdroje dat, neznámá akce nebo název, `db.password`,
  prázdná hodnota, chyba zápisu.

## 6. `doctor` (U6)

Nová sekce **Data source secrets** (za „Data source DB connections“).
Pro každý zdroj dat:

- tajemství ještě v `main.json` → `WARN` `… run shpd-ds ds-upgrade` (v B2
  se z toho stane chyba),
- chybí `db.password` (v souboru ani v `main.json`) → `FAIL`,
- provider bez tajemství → `WARN` (přihlášení přes něj selže),
- `mail.relay` s `username` bez hesla → `WARN`.

`doctor` čte jen přítomnost klíčů v `main.json` a existenci souborů
(`DataSourceSecrets::has()`), hodnoty nikdy. Souhrn sekce: počet zdrojů
s tajemstvím v `main.json` — podle něj se pozná, kdy je server připravený
na B2.

## 7. Dokumentace

- `docs/operations/secrets.md` — nový oddíl „Tajemství konfigurace
  zdroje dat“: tabulka z oddílu 1, threat model (stejný jako
  `secrets.key`, nešifrováno, `0600`), záloha (`secrets/` je v tarballu),
  `ds-secret`, migrace v `ds-upgrade`.
- `docs/migration-guide.md` — obnova krok 3: heslo z `secrets/db.password`;
  u zálohy z doby před migrací z `main.json` a po obnově `ds-upgrade`
  tajemství přesune.
- `docs/auth.md`, `docs/hosting.md`, `docs/operations/hosting-adopt-existing.md`
  — příklady providerů bez `clientSecret` + `shpd-ds ds-secret set
  oidc-<id>.client-secret`.
- `docs/mail/outbound.md` — `mail.relay` zdroje dat bez `password`
  + `ds-secret set mail-relay.password`; `server.json` beze změny.
- `docs/cli.md` — `ds-create` (heslo do `secrets/`), `ds-upgrade` (krok 0),
  nový `ds-secret`, `doctor` (nová sekce).
- `CLAUDE.md` — řádek o `main.json` (bez tajemství, tajemství
  v `secrets/`), seznam příkazů `shpd-ds` (`ds-secret`), popis
  `docs/operations/secrets.md` v tabulce dokumentace.
- `docs/ai-workflow.md` §3 — jedna věta: tajemství se stěhují do
  `secrets/`; pravidlo „nečíst `main.json`“ platí do B2.
- `tasks/README.md` — řádek v oblasti Server, CLI a provoz.

## 8. Testy

PHPUnit jen s úzkým `--filter` (CLAUDE.md).

- `DataSourceSecretsTest` (nový) — názvy, `get()` (koncový `\n`, `\r\n`,
  prázdný soubor, chybějící soubor, nečitelný soubor → výjimka bez
  obsahu), `has()`.
- `DataSourceConfigTest` — `load()` bez `database_password`; přednost
  `main.json` → soubor (U1) u všech tří tajemství; chybějící heslo →
  hláška se souborem; `getAuthPolicy()` s tajemstvím ze souboru; líné
  čtení (bez volání getteru se `secrets/` neotevře).
- `DataSourceSecretsMigratorTest` (nový) — přesun všech tří druhů, jen
  `password` z `mail.relay`, jen `clientSecret` z providera; rozdílná
  hodnota v souboru (U3); neplatné `id` (U5); idempotence; simulovaný
  pád po zápisu souborů; nevalidní `main.json`; výsledek bez hodnot.
- `DsUpgradeCommandTest` — krok 0 před `DataSourceConfig`, výpis, chyba
  migrace = exit 1.
- `DsCreateCommandTest` — heslo v `secrets/db.password`, `main.json` bez
  `database_password`.
- `HostingSyncRunnerTest` — tajemství v souboru, provider v `main.json`
  bez `clientSecret`, selhání zápisu nechá `main.json`.
- `MainConfigPatcherTest` — `mergeAuthProvider` s `clientSecret` →
  výjimka.
- `DsSecretCommandTest` (nový) — list (stavy, žádné hodnoty), set ze
  stdin, skrytý dotaz, `db.password` odmítnut, prázdná hodnota,
  odebrání z `main.json`, neznámý provider = varování.
- `DoctorCommandTest` — nová sekce (WARN / FAIL podle oddílu 6).
- Žádný test ani výstup nesmí obsahovat skutečnou hodnotu tajemství
  — v testech jen zjevně falešné hodnoty.

## Commity

1. **Čtení tajemství** — `DataSourceSecrets`, `DataSourceConfig` (líné
   čtení, přednost, `load()` bez hesla), testy (D2, D3).
2. **Migrace** — `DataSourceSecretsMigrator`, krok 0 v `ds-upgrade`,
   testy (D4).
3. **Zapisovatelé** — `ds-create`, `hosting-sync`, pojistka
   v `mergeAuthProvider`, testy (D1).
4. **`ds-secret`** — příkaz, registrace, help, testy (U4).
5. **`doctor`** — sekce Data source secrets, testy (U6).
6. **Dokumentace** (oddíl 7) a hlavička tasku.

## Nasazení a ověření

**Vývojářský stroj** (po `scripts/dev-update.sh`, který pouští
`ds-upgrade-all`):

1. `shpd-server doctor` — sekce Data source secrets bez tajemství
   v `main.json`.
2. Na libovolném zdroji `shpd-ds ds-secret` — `db.password present`.
3. Aplikace jde otevřít a přihlásit (heslem; na testovacím zdroji
   hostingu i přes OIDC providera).
4. Nový zdroj dat přes `ds-create` — `ds-secret` hlásí `db.password present`,
   `doctor` čistý.

Claude při ověřování čte jen výstup `doctor` a `ds-secret list`, nikdy
soubory v `secrets/` ani `main.json` (D5 platí do B2).

**Testovací server a hosting:** běžný upgrade (`ds-upgrade-all`), potom
`doctor`. Až je sekce Data source secrets čistá na všech serverech,
může se psát B2.

## Navazuje — B2

Samostatný task (U7), až `doctor` na všech serverech (vývojářské stroje,
testovací server, hosting) nehlásí tajemství v `main.json`:

- `DataSourceConfig` přestane číst tajemství z `main.json`; `doctor`
  je hlásí jako `FAIL` (D4),
- testovací fixtury zapisují tajemství do `secrets/`,
- pravidlo pro AI: `main.json` se smí číst, `secrets/` nikdy —
  `CLAUDE.md`, `docs/ai-workflow.md` §3 a §8, pravidla týmového
  prostředí a `deny` v `.claude/settings.json` (D5),
- migrátor v `ds-upgrade` zůstává.

Mimo stávající kód heslo k databázi z `main.json` nic nečte (žádný
skript ani nástroj importu — ověřeno 2026-10-16), B2 tedy nic mimo repo
nerozbije.

## Hotovo (2026-10-16)

Šest commitů podle oddílu „Commity“. Odchylky a upřesnění proti PRD:

- `ds-create` zapíše `secrets/db.password` ještě **před** založením
  databáze (PRD: před `main.json`) — selhání zápisu nenechá založeného
  uživatele databáze bez hesla v souboru.
- Sloty tajemství (`DataSourceConfig::getSecretSlots()`,
  `DataSourceSecretSlot {name, usedBy, required, inMainConfig}`) jsou
  jediný zdroj pro `ds-secret list` i sekci `doctor`; `mail-relay.password`
  je povinné jen s `username` (`list` ukáže `missing (optional)`, `doctor`
  mlčí). Provider s neplatným `id` se ve slotech objeví také
  (`AuthPolicy` ho odmítne) — `doctor` ho hlásí jako tajemství v `main.json`,
  dokud správce `id` neopraví.
- Varování migrace u neplatného `id` providera uvádí index položky
  (`auth.providers[2] has an invalid id …`), ne `id` — to může být cokoli.
- Krok 0 `ds-upgrade` běží vždy nad skutečným `config/main.json` (i
  s injektovaným `DataSourceConfig` v testech); šev `createSecretsMigrator()`.
- `ds-secret set` ze skrytého dotazu odstraní koncový řádek stejně jako
  ze stdin (`QuestionHelper` bez trimu ho nechává).
- `doctor`: zdroj s nečitelným `main.json` se v sekci jen vynechá
  (`· <id>: config unusable — see Data source DB connections above`), chybu
  už počítá sekce DB connections. Souhrn `All secrets in secrets/ ✓` jen bez
  varování.
- `PermissionSpec` beze změny — `secrets/` je hlídaný rekurzivně s `0600`;
  `docs/operations/permissions.md` dostal řádek o nových souborech.
- Testovací fixtury (`DoctorCommandTest`, `HostingSyncRunnerTest`,
  `DsClassCommandTest`, …) dál píší `database_password` do `main.json` —
  přechodné čtení to pokrývá; přepis na `secrets/` je součást B2.

Ověření 1–4 z oddílu „Nasazení a ověření“ na vývojovém stroji dělá člověk
(`ds-upgrade` je mutace zdroje `realCopy`, `ds-create` chce `sudo`).

## Rozhodnutí k designu (potvrzená)

D1–D5 jsou v #126 (komentář „Rozhodnutí (2026-10-16)“). Upřesnění
U1–U8 potvrzena 2026-10-16:

- ✓ **U1** V přechodném období má hodnota v `main.json` přednost před
  souborem — je buď ještě nepřesunutá, nebo ji tam vložil člověk a je
  novější; `ds-upgrade` ji přesune a soubor přepíše. Po B2 platí jen
  soubor.
- ✓ **U2** Soubor obsahuje holou hodnotu; při čtení se odstraní jedno
  koncové `\n` / `\r\n`, prázdný soubor = tajemství chybí.
- ✓ **U3** Liší-li se hodnota v `main.json` a v souboru, migrace přepíše
  soubor hodnotou z `main.json` a vypíše varování.
- ✓ **U4** Nový `shpd-ds ds-secret list|set` pro ruční tajemství (OIDC
  providery, relay pošty); hodnota jen ze skrytého dotazu nebo stdin,
  nikdy z argumentu; `db.password` jím nastavit nejde.
- ✓ **U5** Provider s neplatným `id`: migrace jeho tajemství nechá
  v `main.json` a varuje.
- ✓ **U6** `doctor` dostane sekci Data source secrets (tajemství
  v `main.json` = WARN v B1, chybějící heslo k databázi = FAIL, provider
  bez tajemství = WARN); čte jen přítomnost, nikdy hodnoty.
- ✓ **U7** Konec přechodu (B2) je samostatný task, který se napíše, až
  `doctor` na všech serverech nehlásí tajemství v `main.json`.
- ✓ **U8** Tajemství v `/etc/shipard/server.json` jsou mimo scope.
