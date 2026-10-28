# Konec přechodného čtení tajemství z `config/main.json` (#126 task B2)

**Stav:** hotovo — implementováno 2026-10-28 (5 commitů + ruční úprava `deny`, oddíl „Hotovo“), ověřeno na vývojářském stroji; zbývá nasazení na testovací server a hosting (běžný upgrade + `doctor`)

> PRD pro Claude Code (5 commitů). Design: issue #126, komentář
> „Rozhodnutí (2026-10-16)“, body D4 a D5. Navazuje na task B1
> (`tasks/ds-secrets-split.md`, oddíl „Navazuje — B2“): tajemství už žijí
> v `secrets/`, krok 0 `ds-upgrade` je přesouvá a `doctor` hlásí, co v
> `main.json` zůstalo. Tento task přechodné čtení odstraní, uvolní pravidlo
> pro AI a dodělá dvě drobnosti z ověření B1.

## Kontext

B1 nechal v `DataSourceConfig` přechodné čtení: hodnota tajemství v
`config/main.json` má přednost před souborem v `secrets/` (B1 U1). Platilo
to, dokud `ds-upgrade` neproběhl na všech serverech. 2026-10-28 je sekce
*Data source secrets* v `doctor` čistá na vývojovém stroji i na testovacím
serveru; hosting a ostatní vývojářské stroje potvrdil člověk.

Po B2 je `main.json` čistá konfigurace: tajemství se čtou jen ze `secrets/`,
`main.json` smí číst i Claude a `secrets/` zůstává tabu natrvalo (D5).

## Před implementací přečti

- Issue #126 — komentář „Rozhodnutí (2026-10-16)“, body D4 a D5
- `tasks/ds-secrets-split.md` (B1 — oddíly 2, 3, 5, 6 a „Hotovo“)
- `docs/operations/secrets.md` §5
- `src/Core/Config/DataSourceConfig.php`, `DataSourceSecrets.php`
- `src/Core/Server/DataSourceSecretsMigrator.php`, `MainConfigFile.php`
- `src/Command/Server/DoctorCommand.php` (`checkDataSourceSecrets`)
- `src/Command/DataSource/DsSecretCommand.php`
- `docs/ai-workflow.md` §3 a §8, `.claude/settings.json`

## Scope

**Uvnitř:** čtení tajemství jen ze `secrets/`; `doctor` hlásí tajemství
v `main.json` jako chybu; stavy a hlášky `ds-secret`; migrátor odebírá
z `main.json` podle `id` a hodnoty; přepis testovacích fixtur; uvolnění
pravidla pro AI v repozitáři; dokumentace.

**Mimo:**

- migrátor v `ds-upgrade` zůstává natrvalo a dál přesouvá (D4) — obnova
  starší zálohy přinese starý `main.json`,
- přednost hodnoty z `main.json` **při migraci** zůstává (B1 U3, U2 níže),
- tajemství v `/etc/shipard/server.json` (B1 U8),
- pravidla mimo repozitář — soukromý `dev-env` a instrukce Projektu na
  claude.ai upravuje člověk (oddíl 5, U7).

## 1. Čtení jen ze `secrets/` (D4, U1)

`DataSourceConfig`:

- `getDatabasePassword()` — jen `secrets/db.password`. Klíč
  `database_password` v `main.json` se **ignoruje**. Chybí-li soubor,
  `\RuntimeException` jako dnes
  (`Database password missing — expected secrets/db.password (run shpd-ds ds-upgrade)`);
  je-li klíč v `main.json`, hláška to doplní
  (`… — config/main.json still has database_password, run shpd-ds ds-upgrade to move it`).
  Nikdy hodnotu.
- `getMailRelay()` — `password` z `main.json` se před
  `MailRelayConfig::fromArray()` vždy odebere a doplní se jen ze souboru;
  bez souboru relay zůstane bez hesla jako dnes.
- `getAuthPolicy()` — `clientSecret` z `main.json` se každému providerovi
  odebere a doplní se jen ze souboru; provider s neplatným `id` zůstane bez
  tajemství a `AuthPolicy` ho odmítne jako dnes.
- `getSecretSlots()` a `getSecretsInMainConfig()` beze změny tvaru —
  `inMainConfig` teď znamená „hodnota zůstala v `main.json` a ignoruje se“.
- Docblock třídy a metod bez zmínek o přechodném období.

Žádná výjimka jen proto, že tajemství zůstalo v `main.json`: zdroj dat
s tajemstvím v souboru dál běží, `doctor` ho hlásí a `ds-upgrade` ho uklidí.

## 2. `doctor` a `ds-secret` (U4)

**`doctor`, sekce Data source secrets:**

- tajemství v `main.json` → `✗ <id>: <názvy> still in config/main.json — ignored; run shpd-ds ds-upgrade`
  a **počítá se jako chyba** (jedna za zdroj dat, vstupuje do exit kódu
  `doctor`),
- chybí-li `db.password` v souboru a klíč je v `main.json`, stačí ten jeden
  řádek — `ds-upgrade` vyřeší obojí,
- souhrn: `N data source(s) keep ignored secrets in config/main.json (shpd-server ds-upgrade-all moves them)`,
- ostatní kontroly (chybějící `db.password` = `✗`, provider bez tajemství
  = `⚠`, relay s `username` bez hesla = `⚠`) beze změny.

**`shpd-ds ds-secret`:**

- `list` — sloupec Status nese stav souboru a k němu případně ignorovanou
  hodnotu: `present`, `missing`, `missing (optional)`,
  `present; main.json value ignored (run ds-upgrade)`,
  `missing; main.json value ignored (run ds-upgrade)`.
  Závěrečná poznámka: `N secret(s) still in config/main.json — ignored; shpd-ds ds-upgrade moves them to secrets/ and overwrites the files.`
- `set` dál odebírá stejné tajemství z `main.json` — jinak by ho příští
  `ds-upgrade` přesunul přes nově zadanou hodnotu (U2). Hláška při
  neúspěšném odebrání: `… could not be removed from config/main.json — it is ignored now, but the next ds-upgrade would move it over the new value; remove it by hand.`

## 3. Migrátor podle `id` a hodnoty (U3)

`MainConfigFile::patch()` čte `main.json` znovu pod zámkem; migrátor dnes
v callbacku odebírá `clientSecret` podle **indexu** providera z prvního
čtení. Kdyby mezi čtením a zápisem `main.json` změnil jiný zapisovatel
(`hosting-sync`, ruční úprava), mohl by odebrat tajemství jinému providerovi,
než jaké uložil do souboru.

Nově callback pracuje nad čerstvým obsahem:

- `database_password` a `mail.relay.password` odebere, jen když se hodnota
  shoduje s tou, kterou migrátor zapsal do souboru,
- providery hledá podle `id`: `clientSecret` odebere tomu, jehož
  `oidcClientSecret(id)` migrátor zapsal **a** jehož hodnota se shoduje;
  pořadí v poli nehraje roli (`$movedProviders` zaniká),
- hodnota změněná mezi čtením a zápisem, nebo provider mezitím odebraný či
  přejmenovaný: v `main.json` se nic neodebere a výsledek nese varování
  `<název> changed in config/main.json during migration — left there, run ds-upgrade again`,
- `SecretsMigrationResult` uvádí jako přesunutá jen tajemství, která
  z `main.json` skutečně zmizela.

Soubor zapsaný se starší hodnotou nevadí: další běh ho podle U2 přepíše
hodnotou z `main.json`.

Pro test souběžné změny přidej šev mezi zápisem souborů a patchem (např.
chráněnou metodu volanou po zápisu souborů, kterou test přepíše a
`main.json` změní).

## 4. Testovací fixtury (U6)

Asi 40 testů zakládá `config/main.json` s `database_password` (některé
i s `clientSecret` nebo `mail.relay.password`). Po B2 by to byl tvar, který
`doctor` hlásí jako chybu, a fixtury by klamaly o formátu.

- Ze všech fixtur, které zapisují **`config/main.json`**, tajemství zmizí.
  Výjimka: testy, které starý tvar ověřují záměrně — migrátor, ignorování
  v `DataSourceConfig`, `doctor`, `ds-secret`, krok 0 `ds-upgrade`.
- Test, který heslo opravdu potřebuje, ho zapíše do `secrets/db.password`
  (zjevně falešná hodnota); obdobně OIDC a relay.
- Pole předávaná přímo do `AuthPolicy::fromArray()` / `OidcProviderConfig`
  (testy auth) se nemění — `clientSecret` tam je oprávněně, je to už
  sloučená konfigurace.
- Bez společného pomocníka — každý test má vlastní zakládání adresáře,
  stačí odebrat klíč.
- Kontrola na konci: `grep -rl --include=*.php database_password tests`
  vrátí jen testy z výjimky výše; seznam uveď v oddílu „Hotovo“.

## 5. Pravidlo pro AI (D5, U5)

V repozitáři:

- **`.claude/settings.json`** — z `deny` zmizí
  `Read(//opt/shipard/data-sources/*/config/main.json)`;
  `Read(//opt/shipard/data-sources/*/secrets/**)` zůstává.
- **`CLAUDE.md`**, oddíl *Konfigurace na serveru*: `main.json` je bez
  tajemství a smí ho číst i Claude; `secrets/` nikdy (ani skriptem). Zmizí
  „do tasku B2 má hodnota v `main.json` přednost“. Řádek tabulky
  dokumentace u `docs/operations/secrets.md`: místo „přechodná přednost
  `main.json` do tasku B2“ → „hodnota, která zůstala v `main.json`, se
  ignoruje a `doctor` ji hlásí chybou (B2)“.
- **`docs/ai-workflow.md` §3**, odrážka *Secrets*: nikdy nečíst `secrets/`
  zdrojů dat. `config/main.json` je od #126 B2 bez tajemství a číst se
  smí; přehled všech zdrojů dat a jejich třídy dál dává
  `shpd-server ds-list --json`. Výjimka: zdroj dat, u kterého `doctor` hlásí
  tajemství v `main.json` (typicky po obnově starší zálohy), se do
  `ds-upgrade` nečte. Věta o pojistce v Claude Code se vztáhne jen
  na `secrets/`.
- **`docs/ai-workflow.md` §8**, text instrukcí Projektu: odrážka
  `Nikdy nečti config/main.json zdrojů dat ani secrets/; …` →
  `Nikdy nečti secrets/ zdrojů dat. config/main.json číst smíš (je bez tajemství); přehled zdrojů dat ber z ds-list --json.`
- Projdi `grep -rn --include=*.md 'main.json' CLAUDE.md DEVELOPERS.md CLAUDE.local.example.md docs`
  a oprav každé další pravidlo, které čtení `main.json` zakazuje.

Mimo repozitář (ne Claude Code): soukromý `dev-env` (`alpha.md` —
pravidlo „nečti `main.json`“, `old-shipard.md` — srovnání s `main.json`)
připravil Claude v chatu, commitne člověk spolu s nasazením B2; instrukce
Projektu na claude.ai přepíše člověk podle nového §8.

## 6. Dokumentace

- `docs/operations/secrets.md` §5 — odstavec „Přechodné období“ nahradí
  „Hodnota v `main.json`“: za běhu se ignoruje, `doctor` ji hlásí `✗`,
  `ds-upgrade` ji přesune a soubor přepíše (U2), `ds-secret list` ukáže
  stav. Odstavec Migrace: odebírání podle `id` a hodnoty, varování při
  souběžné změně (U3). Věta o pravidle pro AI: `main.json` číst smí,
  `secrets/` nikdy.
- `docs/cli.md` — `doctor` (`✗` místo `⚠`, zmínka o B2 pryč), `ds-upgrade`
  krok 0 (odebírání podle `id` a hodnoty, nové varování), `ds-secret`
  (stavy z oddílu 2).
- `docs/auth.md` (ř. ~51) — místo „do tasku B2 má hodnota v `main.json`
  přednost“: hodnota, která v `main.json` zůstala, se ignoruje.
- `docs/operations/permissions.md` (ř. ~36) — poznámka u `main.json` bez
  zmínky o B2.
- `docs/migration-guide.md` (obnova, krok 3) — beze změny postupu; doplnit,
  že u zálohy z doby před migrací se zdroj dat k databázi nepřipojí, dokud
  krok 5 (`ds-upgrade`) heslo nepřesune.
- `docs/modules.md` (§9, ukázka `config/main.json`, ř. ~1075) — ukázka je ve
  starém tvaru `database.password`; přepsat na dnešní klíče
  (`database_name`, `database_user`, bez hesla) s odkazem na
  `docs/operations/secrets.md` §5.
- `tasks/ds-secrets-split.md` — hlavička bez „zbývá … potom task B2“
  (B1 je hotový), v oddílu „Navazuje — B2“ odkaz na tento task.
- `tasks/README.md` — řádek v oblasti Server, CLI a provoz pod
  `ds-secrets-split.md`.

## 7. Testy

PHPUnit jen s úzkým `--filter` (CLAUDE.md).

- `DataSourceConfigTest` — testy přednosti `main.json` (B1 U1) nahradí
  testy ignorování u všech tří tajemství: soubor vyhraje nad jinou hodnotou
  v `main.json`; bez souboru výjimka s doplněnou hláškou (heslo k databázi),
  relay bez hesla, provider bez tajemství → chyba `AuthPolicy`. Hláška ani
  výjimka nikdy nenese hodnotu.
- `DataSourceSecretsMigratorTest` — prohozené pořadí providerů mezi čtením
  a patchem (odebere se správnému), změněná hodnota (zůstane + varování,
  není ve výsledku jako přesunutá), provider mezitím odebraný; stávající
  testy dál projdou.
- `DoctorCommandTest` — tajemství v `main.json` = `✗` a nenulový exit,
  jeden řádek při chybějícím souboru i klíči v `main.json`.
- `DsSecretCommandTest` — nové stavy `list`, hláška `set`.
- Přepsané fixtury (oddíl 4) projdou bez změny asercí.
- Žádný test ani výstup nesmí obsahovat skutečnou hodnotu tajemství.

## Commity

1. **Konec přechodného čtení** — `DataSourceConfig`, `doctor`, `ds-secret`,
   testy (oddíly 1, 2; U1, U4).
2. **Migrátor podle `id` a hodnoty** — šev, testy (oddíl 3; U3).
3. **Testovací fixtury** — bez tajemství v `main.json` (oddíl 4; U6).
4. **Pravidlo pro AI** — `.claude/settings.json`, `CLAUDE.md`,
   `docs/ai-workflow.md` §3 a §8 (oddíl 5; D5, U5).
5. **Dokumentace** (oddíl 6), hlavička tohoto tasku a `ds-secrets-split.md`,
   `python3 scripts/tasks-index.py`.

## Nasazení a ověření

Bezpečnost nasazení: krok 0 `ds-upgrade` běží před připojením k databázi
a migrátor zůstává, takže i stroj, který B1 přeskočil, se při běžném
upgradu (`scripts/dev-update.sh` → `ds-upgrade-all`) srovná. Zdroj dat
s heslem jen v `main.json` se mezi nasazením kódu a `ds-upgrade` k databázi
nepřipojí — proto se B2 nasazuje až po čistém `doctor` všude (splněno).

**Vývojářský stroj** po upgradu:

1. `shpd-server doctor` — sekce Data source secrets `All secrets in secrets/ ✓`.
2. `shpd-ds ds-secret` na libovolném zdroji — `db.password present`.
3. Aplikace jde otevřít a přihlásit (na zdroji s OIDC providerem i přes něj).
4. V Claude Code: čtení `config/main.json` zdroje dat projde, čtení souboru
   v `secrets/` je zamítnuté.

**Testovací server a hosting:** běžný upgrade, potom `doctor`.

## Hotovo když

- [x] `DataSourceConfig` nečte tajemství z `main.json`; hodnota tam se
      ignoruje (oddíl 1)
- [x] `doctor` hlásí tajemství v `main.json` jako chybu, `ds-secret` nové
      stavy a hlášky (oddíl 2)
- [x] migrátor odebírá podle `id` a hodnoty, souběžná změna = varování
      (oddíl 3)
- [x] fixtury bez tajemství v `main.json` mimo testy starého tvaru (oddíl 4)
- [x] `deny` jen pro `secrets/` (řádek v `.claude/settings.json` odebral
      člověk ručně), pravidlo v `CLAUDE.md` a `docs/ai-workflow.md` §3 a §8
      (oddíl 5)
- [x] dokumentace podle oddílu 6, hlavičky tasků, index
- [x] cílené testy zelené, na konci celá sada lokálně

## Hotovo (2026-10-28)

Pět commitů podle oddílu „Commity“. Odchylky a upřesnění proti PRD:

- **`.claude/settings.json`** v pěti commitech beze změny: odebrání řádku
  `Read(//opt/shipard/data-sources/*/config/main.json)` z `deny` je úprava
  vlastních oprávnění, kterou Claude Code v auto režimu zamítne. Řádek
  odebral člověk ručně, commit spolu s touto hlavičkou.
- Do commitu 1 šly i dvě fixtury z oddílu 4, bez nichž by sada nebyla
  zelená: `OidcControllerTest` (client secrety providerů `test` a `github`
  do `secrets/`) a `DataSourceConfigTest::testLoadValidConfig` (heslo ze
  `secrets/db.password`).
- Šev migrátoru je chráněná metoda `beforeConfigPatch(string $dsDir)`
  (třída už není `final`); testy ji přepisují anonymní podtřídou.
  `<název>` ve varování `… changed in config/main.json during migration` je
  název souboru tajemství (`db.password`, `oidc-<id>.client-secret`), stejně
  jako u ostatních varování migrátoru.
- Fixtura `doctor` (`createDataSourceFixture`) má výchozí heslo
  v `secrets/db.password`; `passwordInMainJson: true` = starý tvar jen pro
  testy, které ho hlásí, `secretFiles: []` = heslo nikde. Aserce „ostatní
  klíče beze změny“ (`DsClassCommandTest`, `MainConfigPatcherTest`) jdou
  přes `database_user`. Po smazání řádku se v zarovnaných blocích srovnalo
  zarovnání `=>` (jen whitespace).
- `grep -rl --include=*.php database_password tests` vrací devět souborů:
  testy starého tvaru `DataSourceConfigTest` (ignorování, sloty),
  `DataSourceSecretsMigratorTest`, `DoctorCommandTest`, `DsSecretCommandTest`,
  `DsUpgradeCommandTest` (krok 0), `DsCreateCommandTest`
  (`assertArrayNotHasKey`) — a **strážci úniku** `DsListCommandTest`,
  `DataSourceInventoryTest`, `DevDashboardControllerTest`, které tajemství
  do `main.json` dávají schválně a ověřují, že výpis je nevypíše (po obnově
  starší zálohy je nemigrovaný `main.json` reálný, #126 D10). Ty nejsou
  v seznamu výjimek PRD a zůstávají záměrně.
- `tasks/README.md` měl řádek tohoto tasku v oblasti *Server, CLI a provoz*
  už z PRD; změnil se jen generovaný souhrn.

Ověření 1–4 z oddílu „Nasazení a ověření“ dělá člověk (`ds-upgrade` je
mutace zdroje `realCopy`; změna `settings.json` se projeví po restartu
session Claude Code).

**Ověření (2026-10-28):** vývojářský stroj po `ds-upgrade` — `doctor`
čistý (vlastník i Claude v chatu, exit 0, připojení k databázím
z `secrets/db.password`), `ds-secret` na volném zdroji `db.password present`,
cílené testy (353) zelené. Pravidla v `dev-env` upravil Claude v chatu (U7).

## Rozhodnutí k designu (potvrzená)

D4 a D5 jsou v #126 (komentář „Rozhodnutí (2026-10-16)“). Upřesnění
U1–U7 potvrzena 2026-10-28:

- ✓ **U1** Hodnota tajemství, která zůstala v `main.json`, se za běhu
  ignoruje — žádná přednost a žádná výjimka jen kvůli ní. Zdroj dat běží
  s tajemstvím ze souboru, `doctor` ji hlásí a `ds-upgrade` ji uklidí.
- ✓ **U2** Při migraci má hodnota z `main.json` přednost dál (B1 U3): po B2
  ji tam může dát jen člověk ručně nebo obnova starší zálohy, takže je to
  čekající změna, kterou `ds-upgrade` provede. `ds-secret` na to upozorní.
- ✓ **U3** Migrátor odebírá z `main.json` jen hodnotu, kterou zapsal do
  souboru: providera hledá podle `id` a u všech tří tajemství porovná
  hodnotu. Změněnou hodnotu nechá a varuje, další běh ji přesune.
- ✓ **U4** `doctor`: tajemství v `main.json` je chyba (`✗`, exit kód).
  `ds-secret list` ukáže stav souboru a k němu ignorovanou hodnotu.
- ✓ **U5** Pravidlo pro AI: `main.json` se smí číst přímo, přehled zdrojů
  dat dál přes `ds-list --json`, `secrets/` nikdy. Zdroj dat, u kterého
  `doctor` hlásí tajemství v `main.json`, se do `ds-upgrade` nečte.
- ✓ **U6** Fixtury: tajemství zmizí ze všech `main.json` mimo testy starého
  tvaru; test, který heslo potřebuje, ho zapíše do `secrets/`. Bez
  společného pomocníka.
- ✓ **U7** Mimo repozitář: `dev-env` (`alpha.md`, `old-shipard.md`)
  připraví Claude v chatu a commitne člověk; instrukce Projektu na
  claude.ai přepíše člověk podle nového §8.
