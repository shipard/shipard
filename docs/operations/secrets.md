# Secrets v DS

Adresář `secrets/` zdroje dat nese dva druhy tajemství: **klíč** per-DS
šifrování citlivých dat v databázi (`secrets.key`, §1–4; API klíče,
přihlašovací údaje, refresh tokeny — všechny `encrypted_text` sloupce
v jakémkoli modulu) a **tajemství konfigurace** zdroje dat — heslo
k databázi, heslo relay pošty a `clientSecret` OIDC providerů, co tajemství,
to soubor (§5; dřív v `config/main.json`).

Plný design: [`tasks/ds-encrypted-secrets.md`](../../tasks/ds-encrypted-secrets.md)
(šifrování) a [`tasks/ds-secrets-split.md`](../../tasks/ds-secrets-split.md)
(tajemství konfigurace, #126 B1).

## 1. Co se šifruje a čím

- **Algoritmus:** AES-256-GCM přes OpenSSL (`openssl_encrypt` /
  `openssl_decrypt`) — funguje na x86 i ARM; formát je kompatibilní
  s ciphertexty z dřívější implementace přes libsodium
- **Klíč:** 32 bytes, jeden per DS, soubor `{ds_path}/secrets/secrets.key`
- **Permissions:** soubor `0600 shipard:shipard`, adresář `0700 shipard:shipard`
- **Formát ciphertextu v DB:** `v1:{nonce_b64}:{tag_b64}:{ciphertext_b64}`,
  sloupec typu `text NULL`
- **Sensitive automaticky:** typ `encrypted_text` implikuje `sensitive: true`
  (derivace v `ColumnDefinition::fromArray`) — API guard tak ciphertext nikdy
  nepustí ven (`stripSensitive`) ani dovnitř (`rejectSensitiveInput`), i když
  jsonc flag chybí. Explicitní `"sensitive": true` v jsonc je od teď volitelná
  explicitnost; `"sensitive": false` u encrypted_text derivace záměrně přebije.

Klíč žije v rámci adresáře DS, takže přežije migraci přes tarball + DB dump
bez extra zásahu admina.

## 2. Threat model

Šifrování chrání primárně proti **izolované expozici DB**.

| Vektor                                         | Chráněno? |
|------------------------------------------------|-----------|
| DB backup leaknutý mimo server                 | ✓         |
| SQL injection (leakuje jen DB obsah)           | ✓         |
| Admin omylem ukáže DB dump v chatu/mailu       | ✓         |
| Filesystem dump / tarball leaknutý             | ✗         |
| Running server compromise (shell access)       | ✗         |

Útočník s plným přístupem k filesystemu DS získá i klíč — je ve stejném
adresáři. Záměrný tradeoff za provozní jednoduchost. Pro silnější model
(HSM, externí KMS) by se musela vzdát tarball-based migrace.

## 3. Životní cyklus klíče

### Vytvoření
- `bin/shpd-server ds-create --name ...` vygeneruje klíč automaticky
- `bin/shpd-ds ds-upgrade` na DS bez klíče (legacy DS před zavedením
  encrypted_text) jej dovytvoří, loguje informational message

### Backup
`secrets/` adresář **musí** být součástí každé backup strategie. Tarball
DS adresáře jej zahrnuje automaticky. Po rozbalení na cílovém serveru
je nutné ověřit permissions:

```bash
chmod 0700 /opt/shipard/data-sources/{id}/secrets
chmod 0600 /opt/shipard/data-sources/{id}/secrets/secrets.key
```

Po obnovení DB + filesystému spusť `bin/shpd-ds ds-secrets-health` v
adresáři DS pro ověření.

**Ztráta `secrets.key` = ztráta všech šifrovaných dat.** Admin musí
znovu zadat všechny secrets ručně přes příslušné `*-set-key` CLI příkazy
(za každý konzument).

### Rotace
`bin/shpd-ds ds-secrets-rotate` v adresáři DS vygeneruje nový klíč,
re-encryptne všechny `encrypted_text` sloupce v transakci, atomicky
přepne klíčový soubor a starou verzi uloží do `secrets.key.{ISO8601}.bak`.

```bash
bin/shpd-ds ds-secrets-rotate --dry-run    # zobrazí, co by se rotovalo
bin/shpd-ds ds-secrets-rotate
```

Pořadí kroků:

1. Vygeneruje nový klíč v paměti
2. Pre-write `secrets.key.tmp` (0600) + fsync — ověří, že disk je writable;
   vlastník a skupina tmp souboru podle `config/main.json`
   (`Core\Server\FileOwnership::matchTo()`), takže pod `sudo` nevznikne
   klíč `root:root`, který by runtime (shipard-user) nepřečetl. Když
   vlastníka nastavit nejde, rotace skončí **tady** — DB i `secrets.key`
   zůstávají beze změny
3. `BEGIN` DB tranzakce
4. Pro každý `encrypted_text` sloupec: SELECT non-null řádky, decrypt
   starým klíčem, encrypt novým, UPDATE
5. `COMMIT` DB
6. `mv secrets.key secrets.key.{TIMESTAMP}.bak`
7. `mv secrets.key.tmp secrets.key`

Pokud krok 4 selže → DB rollback, soubor netknut, žádné změny.

Pokud kroky 6 nebo 7 selžou po commitu (vzácné — disk full, perms),
příkaz vypíše recovery návod. Stará `.bak` může být po ověření manuálně
smazána. `.bak` vzniká přejmenováním starého klíče, vlastníka tedy dědí
a žádnou opravu nepotřebuje.

Rotace provedená starší verzí pod `sudo` (před 2026-10-12) zanechala
`secrets.key` jako `root:root` — zdroj dat pak nerozšifruje nic
(`SecretsKeyMissingException` nebo nečitelný klíč v logu). Opraví
`sudo shpd-server fix-permissions` (rekurzivní kontrola `secrets/`,
[permissions.md](permissions.md)), potom `ds-secrets-health`.

Rotace by měla být dělána zřídka — typicky jen při suspected key
compromise nebo provozní policy. Žádný scheduled task v MVP.

### Health check
`bin/shpd-ds ds-secrets-health` v adresáři DS:

- Klíčový soubor existuje a má správnou velikost
- Klíčový soubor `0600`, adresář `0700`
- Všechny `encrypted_text` sloupce ve všech tabulkách jsou dekódovatelné
- Vypíše seznam řádků s nečitelným ciphertextem (korupce / mismatch)

Exit codes:
- `0` — vše OK
- `1` — warning (špatné permissions)
- `2` — error (chybějící klíč, nečitelný ciphertext)

Týdenní kontrola integrity přes cron:

```cron
0 3 * * 1 cd /opt/shipard/data-sources/<id> && /opt/shipard/bin/shpd-ds \
    ds-secrets-health || mail -s "secrets health failed" admin@example.com
```

## 4. Migrace mezi servery

Kompletní flow pro přenos DS na jiný server:

```bash
# Zdrojový server
DS_ID=abcd-efgh-ijkl-mnop
tar czf "ds-${DS_ID}.tgz" -C /opt/shipard/data-sources "${DS_ID}"
mysqldump "$(echo ${DS_ID} | tr - _)" > "ds-${DS_ID}.sql"

# Přenos
scp "ds-${DS_ID}".{tgz,sql} target-server:/tmp/

# Cílový server
tar xzf "/tmp/ds-${DS_ID}.tgz" -C /opt/shipard/data-sources/
chown -R shipard:shipard "/opt/shipard/data-sources/${DS_ID}"
chmod 0700 "/opt/shipard/data-sources/${DS_ID}/secrets"
chmod 0600 "/opt/shipard/data-sources/${DS_ID}/secrets/secrets.key"
mysql ... < "/tmp/ds-${DS_ID}.sql"

# Ověření
cd "/opt/shipard/data-sources/${DS_ID}"
bin/shpd-ds ds-secrets-health
```

Pokud `secrets/` chybí v tarballu (např. starý backup před zavedením
mechanismu), `ds-upgrade` nový klíč vytvoří, ale **stará data v
`encrypted_text` sloupcích nebudou dekódovatelná**. Admin musí znovu
zadat všechny secrets.

## 5. Tajemství konfigurace zdroje dat

`config/main.json` je čistá konfigurace — tajemství žijí v `secrets/`,
**co tajemství, to soubor** (#126 D1, D2). Název souboru je odkaz,
`main.json` žádné odkazy nenese; nové tajemství v budoucnu = nový soubor,
nikdy klíč v `main.json`.

| Soubor v `secrets/` | Dřív v `main.json` | Zapisuje | Čte |
|---------------------|--------------------|----------|-----|
| `db.password` | `database_password` | `ds-create`, migrace v `ds-upgrade` | `DataSourceConfig::getDatabasePassword()` |
| `mail-relay.password` | `mail.relay.password` | `shpd-ds ds-secret set`, migrace | `DataSourceConfig::getMailRelay()` |
| `oidc-<id>.client-secret` | `auth.providers[].clientSecret` | `hosting-sync`, `shpd-ds ds-secret set`, migrace | `DataSourceConfig::getAuthPolicy()` |

- `<id>` je `id` providera (`[a-z0-9-]+`). Přípona `.client-secret` se
  nekříží s `oidc-op.key` hostingu.
- Obsah souboru je holá hodnota bez konce řádku; při čtení se odstraní
  jedno koncové `\n` / `\r\n` (soubor uložený editorem), prázdný soubor =
  tajemství chybí. Čte se **líně** (`Core\Config\DataSourceSecrets`) — kód,
  který potřebuje jen konfiguraci (`ds-list`, dispatcher cronu), do
  `secrets/` nesáhne.
- Zápis výhradně `SecretsFileWriter::write()` (adresář `0700`, soubor
  `0600`, fsync + rename, vlastník podle kořene zdroje dat i pod `sudo`).
  **Bez šifrování** — stejný threat model jako `secrets.key` (§2): chrání
  proti izolované expozici `main.json` a jeho kopií, ne proti přístupu
  k filesystemu. Práva hlídá `shpd-server doctor` (`secrets/` rekurzivně,
  obsah nejvýš `0600`), opraví `fix-permissions`.
- **Záloha:** `secrets/` je součástí tarballu zdroje dat (§4,
  [migration-guide.md](../migration-guide.md)); heslo k databázi při obnově
  bere správce z `secrets/db.password`.

**Ruční zadání** (`shpd-ds ds-secret`, [cli.md](../cli.md)): `ds-secret`
vypíše tabulku Name / Used by / Status bez hodnot — stav souboru
(`present` / `missing` / `missing (optional)`) a k němu případně
`; main.json value ignored (run ds-upgrade)`; `ds-secret set
mail-relay.password` a `ds-secret set oidc-<id>.client-secret` berou hodnotu
jen ze skrytého dotazu nebo ze stdin, nikdy z argumentu, a totéž tajemství
z `main.json` odeberou (jinak by ho příští `ds-upgrade` přesunul přes
novou hodnotu). `db.password` takto nastavit nejde — musí sedět na
uživatele databáze.

**Migrace** (#126 D4, B2 U3): krok 0 `shpd-ds ds-upgrade` (`Core\Server\
DataSourceSecretsMigrator`) tajemství z `main.json` zapíše do `secrets/`
a teprve potom klíče z `main.json` odebere — pád mezi tím nechá hodnotu
v obou souborech a další běh migraci dokončí; bez tajemství v `main.json`
je krok no-op. Liší-li se hodnota v souboru, přepíše ji hodnotou
z `main.json` s varováním. Z `main.json` odebírá nad čerstvým obsahem pod
zámkem a jen hodnotu, kterou do souboru zapsal: `database_password`
a `mail.relay.password` podle hodnoty, `clientSecret` providera podle `id`
**a** hodnoty, ne podle pořadí v poli. Změní-li `main.json` mezi čtením
a zápisem jiný zapisovatel (`hosting-sync`, ruční úprava), hodnota tam
zůstane s varováním `… changed in config/main.json during migration — left
there, run ds-upgrade again` a další běh ji přesune. Migrátor zůstává
natrvalo (obnova starší zálohy přinese starý `main.json`).

**Hodnota v `main.json`** (#126 B2, U1): `DataSourceConfig` tajemství
z `main.json` nečte — hodnota, která tam zůstala (obnova starší zálohy,
ruční zápis), se za běhu **ignoruje** a zdroj dat běží s tajemstvím ze
souboru; chybí-li i soubor `db.password`, výjimka to řekne včetně poznámky
o klíči v `main.json` (nikdy hodnotu). `doctor` (sekce *Data source
secrets*) ji hlásí jako chybu `✗ … still in config/main.json — ignored`,
`ds-upgrade` ji přesune a soubor přepíše, `ds-secret list` ukáže stav.
Pravidlo pro AI: `main.json` je bez tajemství a Claude ho číst smí,
`secrets/` nikdy ([ai-workflow.md](../ai-workflow.md) §3).

## 6. Troubleshooting

| Symptom                                     | Pravděpodobná příčina           | Řešení                              |
|---------------------------------------------|---------------------------------|-------------------------------------|
| `SecretsKeyMissingException`                | `secrets.key` neexistuje        | `ds-upgrade` nebo restore z backupu |
| `SecretsKeyInsecureException` perms 0644    | umask při tar extract           | `chmod 0600 secrets.key`            |
| `SecretsKeyInsecureException` perms 0640    | tým-přístup omylem              | `chmod 0600 secrets.key`            |
| `InvalidCiphertextException` v `health`     | klíč nesedí na ciphertext       | restore správného `secrets.key`     |
| `ds-secrets-rotate` vrací CRITICAL          | disk full / perms při rename    | viz output, manuální mv             |
| `ds-secrets-rotate`: `Refusing to rotate…`  | běh pod jiným uživatelem než root a než vlastník DS — tmp klíč nelze předat vlastníkovi | spustit pod `sudo` nebo jako vlastník DS; nic se nezměnilo |
| `secrets.key` je `root:root` po rotaci      | rotace starší verzí pod `sudo`  | `sudo shpd-server fix-permissions`, pak `ds-secrets-health` |
| `AES-256-GCM is not available`              | PHP bez rozšíření OpenSSL       | doinstalovat / povolit `openssl`    |
| `Database password missing — expected secrets/db.password` | heslo není v `main.json` ani v souboru (ztracený `secrets/` při obnově) | obnovit `secrets/` ze zálohy, nebo nové heslo uživateli databáze a ručně do `secrets/db.password` (0600, vlastník zdroje dat) |
| `doctor`: `… still in config/main.json — run shpd-ds ds-upgrade` | zdroj dat ještě neprošel migrací | `sudo shpd-ds ds-upgrade` (nebo `shpd-server ds-upgrade-all`) |
| `doctor`: `auth provider '<id>' has no client secret` | provider bez `secrets/oidc-<id>.client-secret` | `shpd-ds ds-secret set oidc-<id>.client-secret` |

## 7. Implementační reference

- `src/Core/Security/DsSecretCipher.php` — cipher třída + `generateKey()` + `healthCheck()`
- `src/Core/Database/SchemaIntrospector.php` — vyhledávání `encrypted_text` sloupců
- `src/Command/DataSource/DsSecretsHealthCommand.php`
- `src/Command/DataSource/DsSecretsRotateCommand.php`
- `src/Core/Security/SecretsFileWriter.php` — atomický zápis do `secrets/`
  s vlastníkem podle kořene DS (`alignOwnership()`: root chownuje, ne-root
  varuje) — `ds-create`, `ds-upgrade`, klíče hostingu
- `src/Core/Server/FileOwnership.php` — vlastník tmp klíče při rotaci podle
  `config/main.json` (`dataSourceReference()`), selhání = výjimka; totéž
  pro `config/state.json` ([ds-state.md](../ds-state.md) §3)
- `tests/Fixtures/Module/Test/Secrets/TestSecretDocument.php` — kanárkový vzor pro
  Document classes, které mají `encrypted_text` sloupec
- `src/Core/Config/DataSourceSecrets.php` — líné čtení tajemství konfigurace
  (`db.password`, `mail-relay.password`, `oidc-<id>.client-secret`, §5);
  `DataSourceSecretSlot` = co `main.json` očekává, bez hodnot (`ds-secret list`,
  `doctor`)
- `src/Core/Server/DataSourceSecretsMigrator.php` — krok 0 `ds-upgrade`
- `src/Command/DataSource/DsSecretCommand.php` — `shpd-ds ds-secret`
