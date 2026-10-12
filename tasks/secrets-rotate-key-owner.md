# `ds-secrets-rotate` — vlastník nového klíče při běhu pod rootem

**Stav:** hotovo — implementováno 2026-10-12 (2 commity); ověření pod `sudo` zůstává na člověku stejně jako u `tasks/ds-state-file-ownership.md` (postup níže); `.bak` opravu nepotřebuje, sjednocení `SecretsFileWriter::alignOwnership()` na `FileOwnership` jen sdílený `ownerSpec()`, zbytek follow-up

> Jednorázový task pro Claude Code. Nález z kroku 4 tasku
> `tasks/ds-state-file-ownership.md` (2026-10-12). Souvisí:
> `tasks/hosting-fix-secrets-owner-and-gw-errors.md` (vlastník souborů
> v `secrets/` z CLI), `docs/operations/secrets.md`.

## Kontext

`DsSecretsRotateCommand` zapisuje nový klíč vlastní metodou
`writeTmpKeyFile()` do `secrets/secrets.key.tmp` (0600, fsync) a po
commitu DB ho přes `rename` vymění za `secrets/secrets.key`. Vlastníka
nikdy nenastaví.

`docs/cli.md` i runbook uvádějí příkaz se `sudo`. Pod rootem tedy nový
klíč vznikne jako `root:root 0600`. PHP-FPM a cron (shipard-user) ho
nepřečtou — zdroj dat nerozšifruje žádný `encrypted_text` sloupec (AI
klíče, hesla SMTP, API klíče), a to až po úspěšném přešifrování celé DB
novým klíčem. `doctor` to odhalí přes rekurzivní kontrolu `secrets/`
(`contentsMaxMode`), ale až po škodě; `fix-permissions` to opraví.

Soubory zakládané `SecretsFileWriter` (`secrets.key` při `ds-create`,
klíče hostingu) tuhle díru nemají — `alignOwnership()` je pod rootem
chownuje podle kořene DS. Rotace zapisovač nepoužívá (potřebuje tmp
soubor před transakcí a výměnu až po commitu).

## Co se udělalo

### 1. Vlastník tmp klíče před transakcí

`executeRotation()` hned po zápisu tmp souboru (krok 1) volá
`FileOwnership::matchTo($tmpFile, FileOwnership::dataSourceReference($dsDir))`.
Selhání → tmp smazán, výpis `Refusing to rotate: the new key would be
unreadable for the data source owner.` + věta výjimky s radou `chown`
+ `Nothing was changed`, exit `FAILURE`. Transakce v DB se nezačne, klíč
zůstává. Vzorem je `config/main.json` (ne starý klíč — ten už může být
`root:root` z dřívější rotace a chybu by přenesl dál).

`.bak` vzniká přejmenováním starého klíče, vlastníka dědí — bez opravy.

### 2. Jedno pravidlo vzoru

`FileOwnership::dataSourceReference(dsDir)`: `config/main.json` →
adresář `config/` → adresář DS. `DataSourceState::save()` ho používá
místo vlastní privátní metody (chování stejné).

### 3. Šev pro testy

Čtvrtý volitelný parametr konstruktoru `DsSecretsRotateCommand`
(`?FileOwnership $ownership`), testovatelná podtřída ho předává. Testy
bez roota přes `tests/Fixtures/Core/Server/RecordingFileOwnership`.

### 4. `SecretsFileWriter`

Jen `ownerSpec()` deleguje na `FileOwnership::ownerSpec()`. Přepis
`alignOwnership()` na `FileOwnership` by změnil kontrakt (varování místo
výjimky, vzor kořen DS místo `main.json`) u `ds-create`, `ds-upgrade`
a klíčů hostingu — mimo rozsah, follow-up.

## Testy

- `DsSecretsRotateCommandTest`: tmp klíč dostane vlastníka podle
  `main.json` před jakoukoli změnou DB (zaznamenané `chown`/`chgrp`,
  první dotaz na `main.json`); selhání `chown` → `FAILURE`, `begin` ani
  `updateWhere` se nezavolají, klíč, `.tmp` a `.bak` beze změny.
- `FileOwnershipTest`: pořadí vzoru `main.json` → `config/` → DS.
- Stávající testy rotace (reálná `FileOwnership`, shodný vlastník) beze
  změny.

## Ověření pod rootem — postup pro člověka

`sudo` na vývojovém stroji chce heslo. Na **volném** zdroji dat
(`060z-b`, `vlm9-y`), ne na kopii reálných dat:

```bash
cd /opt/shipard/data-sources/<volný-ds>
sudo shpd-ds ds-secrets-rotate --dry-run
sudo shpd-ds ds-secrets-rotate
ls -l secrets/            # secrets.key i .bak: <shipard-user>:<skupina> 0600
shpd-ds ds-secrets-health # bez sudo, exit 0
```

Před opravou by `secrets.key` byl `root:root` a `ds-secrets-health` bez
`sudo` by selhal na čtení klíče.

## Dokumentace

- `docs/operations/secrets.md` §3 Rotace (krok 2, oprava starších
  rotací), §5 Troubleshooting (dva řádky), §6 reference
- `docs/operations/permissions.md` § Soubory zapisované pod rootem
- `docs/cli.md` § `ds-secrets-rotate`
- `docs/ds-state.md` tabulka tříd (`dataSourceReference`)

## Hotovo když

- [x] tmp klíč dostane vlastníka zdroje dat před transakcí; selhání
      zastaví rotaci bez změny DB i klíče
- [x] testy bez roota, `php -l`, cílené testy, celá sada
- [ ] ověření pod `sudo` na volném zdroji dat — **čeká na člověka**
- [x] dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py`
