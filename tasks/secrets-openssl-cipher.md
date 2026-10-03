# Šifrování secrets přes OpenSSL — podpora ARM (Apple silicon, Graviton)

**Stav:** hotovo — #96 D12; 2026-10-03 ověřeno na ARM VM (Ubuntu 24.04 i 26.04: `ds-create` + `ds-upgrade`) a na x86 dev serveru (`ds-secrets-health` — data zapsaná přes sodium se dešifrují)

## Cíl

Na ARM (`aarch64`) nejde založit datový zdroj. `ds-create` i `ds-upgrade`
končí na:

```
Failed to initialise secrets key: libsodium AES-256-GCM is not available on this CPU/build.
```

Ubuntu 24.04 i 26.04 dodávají libsodium **1.0.18**, která AES-256-GCM umí jen
na x86 (AES-NI). CPU na ARM AES instrukce má (`Features: … aes pmull`), ale
libsodium je nepoužije. Týká se to každého Multipass VM na Macu s Apple
silicon, Windows na ARM a ARM serverů — tedy hlavní cílovky lokálního vývoje
(#96 D2).

`DsSecretCipher` přejde z `sodium_crypto_aead_aes256gcm_*` na OpenSSL
(`openssl_encrypt` / `openssl_decrypt`, `aes-256-gcm`), který je v PHP
všude a na x86 i ARM hardwarově akcelerovaný.

## Před implementací přečti

- `src/Core/Security/DsSecretCipher.php` (celý)
- `tests/Unit/Core/Security/DsSecretCipherTest.php`
- `docs/operations/secrets.md`
- `tasks/ds-encrypted-secrets.md` (původní design, jen pro kontext)

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D12** — jen OpenSSL, **bez fallbacku na sodium** (jedna cesta kódu).
  Formát ciphertextu `v1:<nonce>:<tag>:<ct>` se nemění, klíče ani data se
  nemigrují.

## Kompatibilita — ověřeno

AES-256-GCM je standard; libsodium i OpenSSL dávají pro stejný klíč, nonce
(12 B), prázdné AAD a tag 16 B **bajtově identický** výstup. Ověřeno na x86:
sodium → OpenSSL dešifruje, OpenSSL → sodium dešifruje, šifrování dává
identické bajty. `DsSecretCipher` ukládá nonce, tag a ciphertext zvlášť,
takže na OpenSSL API sedí přímo.

**Testovací vektor** (vygenerovaný současnou sodium implementací):

| | |
|---|---|
| klíč | `str_repeat("\x01", 32)` |
| nonce | `str_repeat("\x02", 12)` |
| plaintext | `shipard-test-vector-ěščř` (UTF-8) |
| výsledek | `v1:AgICAgICAgICAgIC:5p4KFImYykTbVLyMGqemWA==:dL6gOSslpdCnqc+8cdGn0dbao8IvKkjQGQolYg==` |

## Co je potřeba udělat

### 1. `DsSecretCipher`

- `encrypt()`: `openssl_encrypt($plaintext, 'aes-256-gcm', $key,
  OPENSSL_RAW_DATA, $nonce, $tag, '', 16)`; nonce `random_bytes(12)`.
  `false` z OpenSSL → výjimka (`\RuntimeException` se zprávou z
  `openssl_error_string()`, bez klíče a plaintextu).
- `decrypt()`: `openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
  $nonce, $tag, '')`; `false` = selhání integrity → stávající
  `InvalidCiphertextException` se stejnou zprávou jako dnes. Validace
  prefixu, base64 a délek nonce/tagu zůstává.
- Konstanty `SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES` / `_ABYTES` nahradit
  vlastními (`NONCE_BYTES = 12`, `TAG_BYTES = 16`) — kód nesmí na sodium
  záviset vůbec.
- `assertSodiumAesAvailable()` → `assertAesGcmAvailable()`: `extension_loaded('openssl')`
  a `aes-256-gcm` v `openssl_get_cipher_methods()`. Zpráva bez zmínky
  o libsodium a „hardware AES“.
- Docblock třídy aktualizovat.

### 2. Testy

V `DsSecretCipherTest`:

- **vektor z tabulky výše** — `fromKey()` + `decrypt()` vrátí plaintext
  (kompatibilita s ciphertexty zapsanými sodium implementací);
- šifrování se stejným klíčem a nonce dává přesně vektor — nonce do
  `encrypt()` neinjektovat veřejným API; stačí test přes privátní pomocnou
  metodu nebo přímé `openssl_encrypt` se stejnými parametry, jak bude
  čistší;
- stávající testy (round-trip, různé ciphertexty pro stejný plaintext,
  poškozený tag/ct, špatný klíč, chybné formáty) beze změny procházejí.

### 3. `composer.json`

Do `require` přidat `"ext-openssl": "*"`.

### 4. Dokumentace

`docs/operations/secrets.md`:
- řádek „Algoritmus“: AES-256-GCM přes OpenSSL (`openssl_encrypt`),
  formát kompatibilní s ciphertexty z dřívější implementace přes libsodium;
- tabulka problémů: řádek „AES-256-GCM not available“ přepsat (příčina:
  PHP bez rozšíření OpenSSL).

## Commit strategie

1. `secrets: AES-256-GCM přes OpenSSL místo libsodium — podpora ARM (#96 D12)`
   — kód, testy, `composer.json`
2. `docs: secrets přes OpenSSL (#96 D12)` — `secrets.md`, hlavička tasku,
   `tasks/README.md` (oblast „Server, CLI a provoz“) +
   `python3 scripts/tasks-index.py`

## Ověření

- PHPUnit úzce: `vendor/bin/phpunit --filter DsSecretCipherTest`
- dev server (x86): `shpd-ds ds-secrets-health` v adresáři existujícího
  vývojového DS — stará data se dešifrují (read-only)
- ARM: na čisté Multipass VM (Ubuntu 24.04 / 26.04, Apple silicon)
  `shpd-server ds-create --name … --language cs --country cz`
  a `shpd-ds ds-upgrade` doběhnou

## Hotovo když

- [x] `grep -rn --include=*.php "sodium_" src` nic nenajde
- [x] testovací vektor se dešifruje a test šifrování ho reprodukuje
- [x] `DsSecretCipherTest` zelený
- [x] `ds-secrets-health` na existujícím DS dev serveru OK
- [x] na ARM VM projde `ds-create` + `ds-upgrade`
- [x] `secrets.md` aktualizovaný, hlavička tasku a index

## Mimo rozsah

- Změna formátu (`v2`), rotace klíčů, jiný algoritmus.
- Práva k checkoutu pro nginx (#96 D11) — `tasks/dev-install-home-traverse.md`.
