# Migrace DS mezi servery

Krátký checklist pro přenos celého data-source na jiný server. Kompletní
postup u jednotlivých subsystémů (zejména secrets) je v `docs/operations/`.

## Co tvoří DS

```
/opt/shipard/data-sources/{id}/
├── config/main.json           konfigurace (název DB, DB uživatel, moduly) — bez tajemství
├── config/configuration/      kompilovaná konfigurace (regenerovatelná)
├── secrets/secrets.key        per-DS šifrovací klíč  ← KRITICKÉ
├── secrets/db.password        heslo k databázi (+ mail-relay.password, oidc-<id>.client-secret)
├── att/                       přílohy
└── cache/                     thumbnails (regenerovatelné)
```

Plus DB schema `{id_with_underscores}` v MariaDB.

## Backup

Před dumpem přepni DS do maintenance — API, cron i příjem pošty se
zastaví (503, mail-router frontuje), dump je konzistentní a `state.json`
odjede v tarballu, takže DS je na cílovém serveru zavřený automaticky
(viz [ds-state.md](ds-state.md)):

```bash
cd /opt/shipard/data-sources/${DS_ID} && sudo shpd-ds ds-state maintenance --on --reason=migration
```

```bash
DS_ID=abcd-efgh-ijkl-mnop
DS_DB="$(echo "${DS_ID}" | tr - _)"

# Tarball — zahrnuje secrets/, config/, att/
tar czf "ds-${DS_ID}-$(date -u +%Y%m%dT%H%M%SZ).tgz" \
    -C /opt/shipard/data-sources "${DS_ID}"

# DB dump
mysqldump --single-transaction "${DS_DB}" \
    > "ds-${DS_DB}-$(date -u +%Y%m%dT%H%M%SZ).sql"
```

⚠️ **`secrets/secrets.key` je v tarballu.** Tarball obsahuje data, kterými
se odemyká DB. Zacházej s ním minimálně tak opatrně, jako s DB dumpem.
Backup ukládej zašifrovaný (např. age, GPG), ne plain.

⚠️ **Bez `secrets/secrets.key` jsou všechna `encrypted_text` data ztracená.**
Pokud máš starý backup z doby před zavedením secrets, příští `ds-upgrade`
sice vygeneruje nový klíč, ale ciphertexty z původního klíče **nikdy
nedekryptuješ**.

## Restore na nový server

```bash
DS_ID=abcd-efgh-ijkl-mnop
DS_DB="$(echo "${DS_ID}" | tr - _)"

# 1. Filesystem
tar xzf "ds-${DS_ID}-...tgz" -C /opt/shipard/data-sources/

# 2. Owner + permissions (umask při extract default-uje špatně)
chown -R shipard:shipard "/opt/shipard/data-sources/${DS_ID}"
chmod 0700 "/opt/shipard/data-sources/${DS_ID}/secrets"
chmod 0600 "/opt/shipard/data-sources/${DS_ID}/secrets/secrets.key"

# 3. Database (admin musí mít CREATE DATABASE / CREATE USER); heslo je
#    v secrets/db.password (holá hodnota). Záloha z doby před migrací
#    tajemství (#126 B1) ho má ještě v main.json (database_password) —
#    pak ho vezmi odtud a krok 5 (ds-upgrade) ho přesune do secrets/.
mysql -e "CREATE DATABASE \`${DS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mysql -e "CREATE USER 'shpd_$(echo ${DS_ID:0:9} | tr -d -)'@'localhost' \
    IDENTIFIED BY '$(cat /opt/shipard/data-sources/${DS_ID}/secrets/db.password)'"
mysql -e "GRANT ALL ON \`${DS_DB}\`.* TO 'shpd_...'@'localhost'"

# 4. Restore data
mysql "${DS_DB}" < "ds-${DS_DB}-...sql"

# 5. Sanity check
cd "/opt/shipard/data-sources/${DS_ID}"
bin/shpd-ds ds-state             # očekávej maintenance on (migration) — DS je zatím zavřený
bin/shpd-ds ds-upgrade           # idempotentní, dorovná schema pokud zaostává; tajemství ze starého main.json přesune do secrets/
bin/shpd-ds ds-secrets-health    # ověří, že secrets.key sedí na ciphertext v DB
bin/shpd-ds ds-secret            # db.password present (a relay / OIDC tajemství, má-li je DS)

# 6. Jen na neprodukčním serveru (vývojářský stroj, testovací kopie): vypnout
#    periodické úlohy, dokud je DS zavřený — kopie nemá nic vystavovat ani
#    posílat (#115 D12; automatiku přinesou až nástroje obnovy)
bin/shpd-ds ds-state cron --off

# 7. Otevřít — až po úspěšném sanity checku a přepnutí DNS / domains.json
bin/shpd-ds ds-state maintenance --off
```

`maintenance --off` vrátí DS do lifecycle stavu, ve kterém byl na zdroji
(`read_only` zůstane `read_only`). Na zdrojovém serveru DS **neotvírej** —
dvě živé kopie by přijímaly poštu obě.

Pokud `ds-secrets-health` vrátí non-zero exit:
- **Exit 1 (warnings)** — typicky špatné permissions po extract. Příkaz
  vypíše konkrétní `chmod` recommendation.
- **Exit 2 (errors)** — `secrets.key` chybí, je poškozený, nebo nesedí
  na ciphertext v DB. Restore správný backup; pokud není k dispozici,
  všechny `encrypted_text` sloupce musíš znovu naplnit přes admin UI.

## Související dokumentace

- `docs/operations/secrets.md` — kompletní popis secrets infrastruktury,
  threat model, troubleshooting, rotace klíče
- `docs/architecture.md` — celkový pohled na vrstvy systému
- `docs/modules.md` — modulový systém a `ds-upgrade`
