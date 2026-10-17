# `ds-delete` a úprava přehledu zdrojů dat ve vývojářském dashboardu

**Stav:** naplánováno — design schválen 2026-10-17 (#103 D68–D71), čeká na implementaci

## Kontext / Cíl

Na vývojových strojích se hromadí zdroje dat: testovací, evaluační,
zkušební po taskách. Smazat je dnes nejde, `ds-delete` je v roadmapě
(M5) a ještě neexistuje. Přehled zdrojů dat ve vývojářském dashboardu
má navíc zbytečný sloupec Databáze (duplikuje ID) a řadu tlačítek,
z nichž se běžně používá jen Otevřít.

**Cíl:**

1. `shpd-server ds-delete <id>` pro **volné** zdroje dat mimo produkci
   (D68, D69).
2. Mazání z dashboardu (D70).
3. Úklid přehledu zdrojů dat: bez sloupce Databáze, akce v kebab menu
   (D71).

Mazání na produkci a hostingu (záloha před smazáním, vazba na portál)
**není** součástí tasku. Je to samostatný návrh pro M5.

## Návaznost

- **#103** D68–D71 (oddíl na konci); F6b D58 (úlohy), D60 (`DevActionGuard`)
- **#126** — třída dat (`free` / `realCopy`), `ds-class`, `DataSourceInventory`
- `docs/roadmap.md` M5 — řádek „Příkaz `ds-delete`“
- `docs/cli.md` → `ds-create`, `ds-list`, `ds-class`; `docs/dev/dev-daily.md` § 1

## Před implementací přečti

- `src/Command/Server/DsCreateCommand.php` (co se zakládá: adresář,
  `config/`, `secrets/`, databáze, DB uživatel), `src/Core/Database/DatabaseManager.php`
- `src/Cli/ServerApplicationFactory.php`
- `src/Core/Config/DataSourceConfig.php` (`getDatabaseName()`,
  `getDatabaseUser()`), `DataSourceInventory`, `DataClass`
- `src/Core/DevJobs/JobStore.php` (`activeJobFor()`), `DevActionGuard`,
  `src/Api/Controller/DevEvalController.php` (úlohy `reseed` / `create` jako vzor)
- `frontend/src/dev/pages/DataSourcesPage.svelte`, `LoadDatasetDialog.svelte`,
  `frontend/src/components/ui/Popover.svelte` a jeho použití jako kebab menu
  v aplikaci (`components/form/FormStateBar.svelte`, `components/viewer/Viewer.svelte`)

## 1. Příkaz `ds-delete` (D68, D69)

```bash
sudo shpd-server ds-delete <id> [--dry-run] [--yes]
```

**Preflight** (nic se nemění, exit 1 se zprávou):

- server v režimu `production` → odmítnout;
- zdroj dat neexistuje nebo nemá čitelnou konfiguraci → odmítnout;
- třída dat není `free` → odmítnout s návodem: „Reálnou kopii nejdřív
  vědomě přepni: `shpd-ds ds-class set free`“;
- na zdroji dat běží úloha vývojářského dashboardu (`JobStore::activeJobFor()`)
  → odmítnout s odkazem na úlohu.

**Co smaže** (v tomto pořadí; `--dry-run` jen vypíše):

1. **Granty ostatních účtů** na databázi zdroje dat (např. `claude_ro`
   na vývojovém serveru). `DROP DATABASE` je v MariaDB neodebere. Najdi
   je v `mysql.db` pro dané `Db` a odeber `REVOKE ALL PRIVILEGES`
   (kromě DB uživatele zdroje dat, ten se maže celý). Selhání je jen
   varování.
2. **Databázi** (`DROP DATABASE IF EXISTS`).
3. **DB uživatele** zdroje dat (`DROP USER IF EXISTS`).
4. **Adresář zdroje dat** (rekurzivně: `config/`, `secrets/`, soubory, …).

**Potvrzení:** interaktivně opsat ID zdroje dat. `--yes` potvrzení
přeskočí (neinteraktivní běh, dashboard), preflight ale ne.

**Opakovatelnost:** po částečném selhání jde příkaz pustit znovu. Chybějící
databáze nebo uživatel nejsou chyba, adresář se maže až na konci.

**Výstup:** kroky a souhrn. Logy (`/opt/shipard/log`) a adresáře úloh
dashboardu se nemažou, jsou to historie.

## 2. Mazání z dashboardu (D70)

- V kebab menu řádku zdroje dat položka **Smazat…** jen u třídy `free`.
- Dialog: co se smaže (databáze, DB uživatel, adresář, granty) a varování
  „Nevratné“. Potvrzení **opsáním názvu zdroje dat**.
- Úloha `delete` (infrastruktura úloh z F6b): `ds-delete <id> --yes`.
  Po doběhnutí se řádek ze seznamu ztratí.
- API ověří stejnou pojistku jako ostatní spouštěcí akce (`DevActionGuard`:
  development, `free`) a navíc „žádná běžící úloha na zdroji dat“ (409).
  Úloha `delete` drží zámek zdroje dat jako ostatní.

## 3. Přehled zdrojů dat (D71)

- **Sloupec Databáze pryč.** API `GET /_dev/api/data-sources` může
  `database_name` dál vracet.
- **Sloupec akcí:** jen tlačítko **Otevřít** a kebab menu (⋮) s položkami
  - Logy,
  - Upgrade,
  - Nahrát datovou sadu (jen `free` a s checkoutem `tests/`),
  - Kopírovat ID,
  - Smazat… (jen `free`).

  Neaktivní položky se neukazují.
- Kebab menu postav na `Popover` a stejném vzoru jako v aplikaci:
  ovládání klávesnicí a zavření klikem mimo nebo klávesou Esc.

## Kroky

1. `ds-delete` (preflight, `--dry-run`, mazání, potvrzení) + unit testy
   s falešným `DatabaseManager` a dočasným adresářem (odmítnutí `realCopy`,
   produkce a běžící úlohy; `--dry-run` nic nezmění; opakovatelnost).
2. API: úloha `delete` + testy (403 na `realCopy`, 409 při běžící úloze).
3. UI: přehled bez sloupce Databáze, kebab menu, dialog smazání.
4. Ověření (níže), dokumentace, hlavička tasku.

## Hotovo když

- [ ] unit testy příkazu a API zelené (úzké `--filter`)
- [ ] na dev serveru: **nový zkušební zdroj dat** (založ si ho, nemaž
      cizí) smazaný z CLI s `--dry-run` a pak naostro; databáze, DB
      uživatel, granty i adresář pryč (`SHOW DATABASES`, `mysql.user`,
      `mysql.db`, `ls`)
- [ ] druhý zkušební zdroj dat smazaný z dashboardu (kebab → Smazat… →
      opsat název → úloha → řádek zmizí)
- [ ] `ds-delete` na reálné kopii a v režimu `production` odmítnuté
      (testem, ne živě)
- [ ] přehled zdrojů dat: bez sloupce Databáze, Otevřít + kebab menu
      se všemi položkami
- [ ] `docs/cli.md` (`ds-delete`), `docs/dev/dev-daily.md` § 1 (kebab menu,
      mazání), `docs/roadmap.md` M5 (ds-delete pro vývoj hotový, produkce
      a hosting zbývá)
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Reálné kopie se nikdy nemažou** bez vědomého přepnutí třídy. Žádný
  `--force`.
- **Ověřuj na vlastních zkušebních zdrojích dat.** Zdroje dat, které
  na vývojovém serveru už jsou, maže člověk.
- **Produkce:** `ds-delete` se tam nespustí ani s `--yes`.

## Rozhodnutí k designu (potvrzená)

- ✓ **D68 — `shpd-server ds-delete <id>`** smaže granty ostatních účtů,
  databázi, DB uživatele a adresář zdroje dat; `--dry-run`; potvrzení
  opsáním ID, `--yes` pro neinteraktivní běh; tvrdé smazání bez koše.
- ✓ **D69 — Jen třída `free`, mimo režim `production`, bez běžící úlohy**
  dashboardu. Reálnou kopii jen po vědomém `ds-class set free`. Mazání
  na produkci a hostingu je samostatný návrh (M5).
- ✓ **D70 — Mazání z dashboardu** položkou kebab menu u volných zdrojů
  dat, potvrzení opsáním názvu, úloha na pozadí, pojistka v API.
  Hromadné mazání ne.
- ✓ **D71 — Přehled zdrojů dat:** bez sloupce Databáze; Otevřít + kebab
  menu (Logy, Upgrade, Nahrát datovou sadu, Kopírovat ID, Smazat…).
