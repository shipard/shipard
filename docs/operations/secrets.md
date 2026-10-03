# Šifrované secrets v DS

Per-DS šifrovací mechanismus pro citlivá data v databázi (API klíče,
přihlašovací údaje, refresh tokeny). Všechny `encrypted_text` sloupce
v jakémkoli modulu používají tuto infrastrukturu.

Plný design: [`tasks/ds-encrypted-secrets.md`](../../tasks/ds-encrypted-secrets.md).

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
2. Pre-write `secrets.key.tmp` (0600) + fsync — ověří, že disk je writable
3. `BEGIN` DB tranzakce
4. Pro každý `encrypted_text` sloupec: SELECT non-null řádky, decrypt
   starým klíčem, encrypt novým, UPDATE
5. `COMMIT` DB
6. `mv secrets.key secrets.key.{TIMESTAMP}.bak`
7. `mv secrets.key.tmp secrets.key`

Pokud krok 4 selže → DB rollback, soubor netknut, žádné změny.

Pokud kroky 6 nebo 7 selžou po commitu (vzácné — disk full, perms),
příkaz vypíše recovery návod. Stará `.bak` může být po ověření manuálně
smazána.

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

## 5. Troubleshooting

| Symptom                                     | Pravděpodobná příčina           | Řešení                              |
|---------------------------------------------|---------------------------------|-------------------------------------|
| `SecretsKeyMissingException`                | `secrets.key` neexistuje        | `ds-upgrade` nebo restore z backupu |
| `SecretsKeyInsecureException` perms 0644    | umask při tar extract           | `chmod 0600 secrets.key`            |
| `SecretsKeyInsecureException` perms 0640    | tým-přístup omylem              | `chmod 0600 secrets.key`            |
| `InvalidCiphertextException` v `health`     | klíč nesedí na ciphertext       | restore správného `secrets.key`     |
| `ds-secrets-rotate` vrací CRITICAL          | disk full / perms při rename    | viz output, manuální mv             |
| `AES-256-GCM is not available`              | PHP bez rozšíření OpenSSL       | doinstalovat / povolit `openssl`    |

## 6. Implementační reference

- `src/Core/Security/DsSecretCipher.php` — cipher třída + `generateKey()` + `healthCheck()`
- `src/Core/Database/SchemaIntrospector.php` — vyhledávání `encrypted_text` sloupců
- `src/Command/DataSource/DsSecretsHealthCommand.php`
- `src/Command/DataSource/DsSecretsRotateCommand.php`
- `tests/Fixtures/Module/Test/Secrets/TestSecretDocument.php` — kanárkový vzor pro
  Document classes, které mají `encrypted_text` sloupec
