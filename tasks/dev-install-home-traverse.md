# Průchod nginx ke checkoutu v domovském adresáři

**Stav:** naplánováno — #96 D11

## Cíl

Na čisté instalaci (Ubuntu 24.04 i 26.04, Multipass, WSL) `doctor` hlásí
„All checks passed“, ale nginx vrací na `/app/…` **500**:

```
stat() "/opt/shipard/shpd/public/app/index.html" failed (13: Permission denied)
rewrite or internal redirection cycle while internally redirecting to "/app/index.html"
```

`/opt/shipard/shpd` je v dev módu symlink na checkout v
`/home/<user>/sw/shpd`. Ubuntu od 21.04 zakládá domovské adresáře
s `HOME_MODE 0750` (`/etc/login.defs`), takže `www-data` přes
`/home/<user>` neprojde. `docs/operations/permissions.md` přitom počítá
s tím, že cesta ke checkoutu průchozí je — na starších strojích (domov
`0755`) to platilo, nebo se to opravilo ručně.

Oprava: instalační skript cestu zprůchodní, `doctor` ji kontroluje
a `fix-permissions` ji umí srovnat.

## Před implementací přečti

- `scripts/install-packages.sh` (kroky Filesystem layout a symlink
  `/opt/shipard/shpd`)
- `src/Core/Server/PermissionSpec.php`
- `src/Command/Server/DoctorCommand.php`, `src/Command/Server/FixPermissionsCommand.php`
- `docs/operations/permissions.md`

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D11** — v dev módu dostanou nadřazené adresáře checkoutu, kterým
  chybí, bit `o+x` (jen průchod, ne výpis obsahu) — typicky `/home/<user>`
  → `0751`, stejně jako `/opt/shipard`. Žádné přidávání `www-data` do
  skupiny uživatele (kontrakt „žádný group hack“).

## Co je potřeba udělat

### 1. `scripts/install-packages.sh`

V development módu, po vytvoření symlinku `/opt/shipard/shpd`:

- projít nadřazené adresáře `$PROJECT_DIR` směrem ke kořeni (bez kořene
  `/`); u každého, kterému chybí `x` pro others:
  - vlastník = shipard user → `chmod o+x`, vypsat „Granted traverse (o+x):
    <cesta>“;
  - jiný vlastník → **neměnit**, skončit chybou s vysvětlením (nginx
    nedosáhne na checkout, přesuň checkout nebo uprav práva ručně) —
    skript nesahá do cizích adresářů;
- sám checkout a `public/` se nemění (git je zakládá s `0775`/`0755`).

V produkčním módu je `/opt/shipard/shpd` reálný adresář — krok se
přeskočí.

Idempotentní: na stroji, kde je vše průchozí, nic nevypíše a nic nezmění.

### 2. Kontrakt — `PermissionSpec`, `doctor`, `fix-permissions`

- **Kontrola v `doctor`** (oba módy): ze `realpath('/opt/shipard/shpd/public')`
  projít všechny nadřazené adresáře; chybí-li někde `o+x`, hlásit **chybu**
  (✗, nenulový exit) s cestou a příkazem k opravě. Vhodné místo: sekce
  s kontrolou cest, nebo vedle `checkNginxRouting`.
- **`fix-permissions`**: tutéž opravu nabídnout (včetně `--dry-run`) se
  stejným omezením jako skript — jen adresáře vlastněné shipard userem;
  u cizích vypsat varování.
- Jestli logika patří do `PermissionSpec` (dynamické položky odvozené
  z cesty checkoutu), nebo do samostatné pomocné třídy sdílené `doctor`
  a `fix-permissions`, rozhodni podle toho, co je v kódu čistší — jen ať
  není duplikovaná.

### 3. Testy

Unit test pro výpočet „které nadřazené adresáře nejsou průchozí“ nad
dočasnou adresářovou strukturou (bez sudo, bez reálného `/home`).

### 4. Dokumentace

`docs/operations/permissions.md`, sekce „Proč `0751` na `/opt/shipard/`“:
doplnit, že v dev módu musí být průchozí i cesta ke checkoutu
(`/home/<user>` → `0751`), proč (Ubuntu ≥ 21.04 `HOME_MODE 0750`), co
dělá install skript, `doctor` a `fix-permissions`. Řádek do tabulky
kontraktu pro „nadřazené adresáře checkoutu (dev)“.

## Commit strategie

1. `install-packages, doctor: průchod nginx ke checkoutu v domovském adresáři (#96 D11)`
   — skript, kontrakt, `doctor`, `fix-permissions`, testy
2. `docs: průchozí cesta ke checkoutu v permission kontraktu (#96 D11)` —
   `permissions.md`, hlavička tasku, `tasks/README.md` (oblast „Server,
   CLI a provoz“) + `python3 scripts/tasks-index.py`

## Ověření

- PHPUnit úzce (`--filter` na nový test, případně `DoctorCommand`)
- čerstvá Multipass VM (24.04 i 26.04): postup z `DEVELOPERS.md` →
  `curl -s -o /dev/null -w '%{http_code}' http://localhost/app/index.html`
  vrátí 200
- na VM, kde už instalace proběhla (domov `0750`): `doctor` hlásí ✗,
  `sudo shpd-server fix-permissions` opraví, `doctor` zelený
- dev server, kde je domov už průchozí: skript i `doctor` beze změny

## Hotovo když

- [ ] čistá instalace na 24.04 i 26.04: `/app/index.html` → 200 bez ruční
      úpravy práv
- [ ] `doctor` neprůchozí cestu odhalí (✗) a `fix-permissions` ji opraví
- [ ] skript nemění adresáře cizích vlastníků
- [ ] opakované spuštění skriptu i `doctor` na průchozím stroji beze změny
- [ ] `permissions.md`, hlavička tasku a index aktualizované

## Mimo rozsah

- Šifrování secrets na ARM (#96 D12) — `tasks/secrets-openssl-cipher.md`.
- Bootstrap pro Multipass / WSL.
