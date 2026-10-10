# Standard samostatných komponent

Pravidla pro komponenty, které nejsou součástí repozitáře `shipard/shipard`, ale
tvoří s ním jeden systém — dnes `mail-router`, dále generátor
UI videa (#48) a PDF rendering (#34).

Cíl: vývojář (i Claude Code) přijde do libovolného repozitáře komponenty
a najde stejnou strukturu, stejná jména dokumentů a stejnou sadu CLI příkazů
jako v `shpd`. Rozdělené repozitáře nesmí znamenat rozdělené standardy.

> **Kontext:** Přesun komponent do monorepa je zvažovaný v issue #47
> a **odložený**. Tenhle dokument popisuje standard pro **samostatné
> repozitáře**. Je napsaný tak, aby byl na této otázce nezávislý — když se
> později rozhodne pro monorepo, mění se jen umístění adresářů, ne obsah
> pravidel.

---

## 1. Tři kategorie komponent

Jeden kontrakt na všechno by byl zředěný o výjimky. Komponenty se proto dělí
na tři kategorie s různými požadavky:

| Kategorie | Charakteristika | Dnes |
|---|---|---|
| **Runtime služba** | Dlouho běžící démon. Systemd unity, config v `/etc/`, systémový uživatel, stav v `/var/lib/`. | `mail-router` (`ai-analyzer` zrušen #85 D24) |
| **Nástroj** | Spouštěný příležitostně, ručně nebo z CI. Bez systemd, bez dedikovaného uživatele, běží ze zdrojového adresáře. | generátor videa (#48) |
| **Vendorovaná infrastruktura** | Cizí software, žádný náš kód. Jen deployment descriptor a provozní dokumentace. | Gotenberg (#34) |

Kapitoly 2–9 popisují **runtime službu** — nejnáročnější případ. Kapitola 10
říká, co z toho platí pro nástroj, kapitola 11 pro vendorovanou
infrastrukturu.

---

## 2. Struktura repozitáře

```
<repo>/
├── bin/
│   └── shpd-<name>            # jediný vstupní bod (bash) — viz kap. 4
├── <package>/                 # zdrojový kód (Python package, snake_case)
├── deploy/
│   ├── packages.txt           # OS balíčky — viz kap. 6
│   ├── config.example.yaml    # komentovaná šablona configu
│   ├── systemd/               # unit soubory
│   └── README.md              # volitelně: detailní deployment poznámky
├── docs/
│   ├── README.md              # index dokumentace (povinný)
│   ├── architecture.md
│   ├── operations.md
│   └── troubleshooting.md
├── scripts/
│   └── tasks-index.py         # kopie z shpd
├── tasks/
│   ├── README.md              # konvence tasků (kopie z shpd, zkrácená)
│   └── *.md
├── tests/
├── CLAUDE.md                  # povinný — viz kap. 3
├── AGENTS.md -> CLAUDE.md     # symlink
├── README.md                  # rozcestník, ne dokumentace
├── LICENSE
└── pyproject.toml             # nebo package.json
```

Kořenový `install.sh` **standard nemá** — jeho práci dělá verb `install`
(kap. 4). Bootstrap se dokumentuje v `README.md`.

### Cesty na cílovém stroji

| Cesta | Obsah | Vlastník |
|---|---|---|
| `/opt/shipard-<name>/src/` | git clone repozitáře | `shipard-<name>` |
| `/opt/shipard-<name>/venv/` | virtuální prostředí | `shipard-<name>` |
| `/etc/shipard-<name>/` | config, `sources.d/` a podobné (0750) | `shipard-<name>` |
| `/var/lib/shipard-<name>/` | perzistentní stav (SQLite, fronta) | `shipard-<name>` |
| `/run/shipard-<name>/` | sockety — vytváří systemd přes `RuntimeDirectory=` | `shipard-<name>` |
| `/usr/bin/shpd-<name>` | symlink na `…/src/bin/shpd-<name>` | root |

**Clone patří pod `/opt/shipard-<name>/src/`** — deterministická cesta je
podmínka toho, aby verb `upgrade` mohl udělat `git pull`. Klonovat kamkoli
a instalovat odtud (dnešní stav) upgrade neumožňuje.

---

## 3. `CLAUDE.md`

Povinný. Obsah v tomto pořadí:

1. **Projekt** — co komponenta dělá, jednou až dvěma větami; jazyk a verze
   runtime; hlavní závislosti.
2. **Vztah k `shpd`** — které datové kontrakty komponenta drží a kde jsou
   dokumentované (viz kap. 12). Tohle je nejdůležitější sekce: bez ní agent
   změní klienta bez znalosti kontraktu.
3. **Dokumentace** — tabulka s odkazy na `docs/*`, ve stylu `shpd/CLAUDE.md`,
   s instrukcí „přečti PŘED implementací".
4. **Architektura** — strom zdrojových adresářů s jednořádkovým popisem
   a směrem toku závislostí.
5. **Klíčové konvence** — co je v tomto repozitáři jinak než by agent čekal.
6. **Příkazy pro vývoj** — lint, testy, lokální spuštění.
7. **Otevřené úkoly** — odkaz na `tasks/`.

`AGENTS.md` je symlink na `CLAUDE.md`.

---

## 4. CLI

Jediný vstupní bod je `bin/shpd-<name>`. **Musí to být bash**, ne skript
v jazyce komponenty — spouští se i ve stavu, kdy venv ještě neexistuje nebo je
rozbitý. Interpretované podpříkazy (admin operace nad daty) volá přes
`venv/bin/…`.

### Povinné verby

| Verb | Chování | Root | Mutuje |
|---|---|---|---|
| `install` | Idempotentní první instalace: uživatel → adresáře → venv → závislosti → symlink `/usr/bin` → config ze šablony (jen pokud chybí) → systemd unity → `daemon-reload`. **Službu nestartuje.** | ano | ano |
| `upgrade` | Nasazení nové verze — viz níže. | ano | ano |
| `status` | Verze, git hash, stav unitů, klíčové runtime ukazatele. | ne | ne |
| `doctor` | Kontrola prostředí bez zásahu: uživatel a práva, existence adresářů, OS balíčky, validita configu, dosažitelnost `shpd`. | ne | ne |
| `config-check` | Validace configu proti schématu. Bez sítě, bez systemd. | ne | ne |
| `logs` | Tenký wrapper nad `journalctl -u …` (`-f`, `-n <N>`). | ne | ne |

Verby specifické pro komponentu (např. `stats`, `lookup-sync`, `queue`) jsou
povolené, ale musí být v `--help` vizuálně oddělené od standardní sady.

### `upgrade` — sekvence

```
pre-flight (čistý worktree, ne detached HEAD)
  → git pull --ff-only  (výpis příchozích commitů)
  → reinstalace závislostí       [jen při změně pyproject.toml / lock]
  → refresh systemd unitů        [jen při změně deploy/systemd/**]
  → daemon-reload                [jen když se unity změnily]
  → restart / reload služby      [jen při změně kódu]
  → status
```

Podmíněné kroky jsou podstata — bez nich upgrade zbytečně restartuje službu
při každém commitu do dokumentace. Vzor je `shpd-server upgrade`, který totéž
dělá pro composer a frontend build.

| Opce | Význam |
|---|---|
| `--dry-run` | Fetch, výpis příchozích commitů a plánu kroků. Nic nemění. |
| `--full` | Vynutí všechny podmíněné kroky bez ohledu na diff. |

Selhání kroku běh zastaví; žádný automatický rollback. Závěrečné hlášení
`Upgraded <old> → <new> (<N> commits)`.

### Exit kódy

`0` úspěch (včetně varování), `1` chyba. `doctor` vrací `1` jen při skutečném
problému — varování nesmí shodit exit kód, protože `doctor` se volá z `upgrade`
a z monitoringu.

---

## 5. Config

- Umístění `/etc/shipard-<name>/config.yaml`, mode `0640`, vlastník
  `shipard-<name>`.
- Šablona `deploy/config.example.yaml` — komentovaná, s vyplněnými
  bezpečnými defaulty. `install` ji kopíruje **jen když cíl neexistuje**.
- Config **nikdy** v repozitáři a nikdy v `git`. Platí i pro tokeny
  v provozní dokumentaci — do `docs/` jen prefix, nikdy celá hodnota.
- Validace configu je implementovaná jednou a volá se ze tří míst: při startu
  démona, z `config-check` a z `doctor`.

---

## 6. OS balíčky

`deploy/packages.txt` — jeden balíček na řádek, komentáře `#`, prázdné řádky
ignorované:

```
# Python runtime
python3
python3-venv
# Postfix integrace
postfix
```

Čte ho verb `install`. Napojení na `shpd/scripts/install-packages.sh` se
záměrně **neřeší** — dokud jsou repozitáře oddělené, all-in-one dev server
prostě zavolá `install` každé komponenty.

---

## 7. Systemd

- Unity v `deploy/systemd/`, jména `shipard-<name>*.service`.
- Víc procesů jedné komponenty = `shipard-<name>.target`, který je sdružuje
  (vzor: `mail-router`).
- Instaluje a refreshuje je `install` / `upgrade`, nikdy ne ruční `cp`.
- `RuntimeDirectory=` pro `/run/…` — nikdy nepředvytvářet v instalaci.
- Timery a `path` unity patří sem taky (vzor: `lookup-sync` v `mail-router`;
  historicky `sources-sync.timer` a `reload.path` ve zrušeném `ai-analyzer`).

---

## 8. Verzování a logování

**Verze** je v `pyproject.toml` (resp. `package.json`) a je autoritativní pro
balíček. Pro diagnostiku ale nestačí — `status` proto vypisuje **verzi
i krátký git hash**:

```
shipard-mail-router <verze> (<commit>)
```

Bez toho není na alfě jednoznačně určitelné, co běží.

**Logování** jde do journalu (stdout/stderr démona), strukturovaně, jeden
záznam na řádek, s polem `event=<název>`. Provozní dokumentace musí uvádět
konkrétní `event` hodnoty, na které se dá grepovat — troubleshooting bez nich
je nepoužitelný.

---

## 9. Testy a tasky

- **Testy**: každý datový kontrakt vůči `shpd` musí mít test proti fake
  serveru (`tests/fakes/`), ne jen unit test serializace. Právě na hranici
  kontraktu vznikají tiché chyby.
- **Tasky**: `tasks/*.md` podle konvence z `shpd/tasks/README.md`, index
  generuje `scripts/tasks-index.py` (kopie z `shpd`).
- **Jazyk dokumentace a tasků: čeština**, shodně s pravidlem v
  [`documentation.md`](documentation.md). Kód, commit messages a log eventy
  zůstávají v angličtině.

---

## 10. Nástroj

Z kapitol výše **platí**: struktura repozitáře (bez `deploy/systemd/`),
`CLAUDE.md`, dokumentační triáda s indexem, `deploy/packages.txt`, verzování,
testy, tasky, čeština.

**Neplatí**: systémový uživatel, `/etc/`, `/var/lib/`, systemd, verby
`logs` a `status` v podobě z kap. 4.

Povinné verby nástroje: `install` (závislosti do lokálního prostředí
v repozitáři, ne do `/opt/`), `upgrade`, `doctor`, plus vlastní pracovní
verby. Nástroj běží ze zdrojového adresáře pod běžným uživatelem.

---

## 11. Vendorovaná infrastruktura

Žádný náš kód, tedy žádné CLI ani `CLAUDE.md`. Povinné je pouze:

- **deployment descriptor** ve verzované podobě (compose / quadlet / Ansible
  fragment) — nikdy ne „naklikáno na serveru",
- **provozní dokumentace** v `shpd/docs/operations/<name>.md`: co to je, proč
  to používáme, jak se to instaluje a upgraduje, jak se pozná, že to nejede,
- **pinnutá verze image** — nikdy `latest`.

---

## 12. Kontrakty vůči `shpd`

Datový kontrakt mezi `shpd` a komponentou žije **v `shpd`** — je to jeho API,
ne API komponenty. Dokumentuje se v `shpd/docs/` (vzor:
[`mail/api-contract.md`](mail/api-contract.md)) a `CLAUDE.md` komponenty na něj
odkazuje.

Pravidla:

1. **Změna kontraktu se dokumentuje před implementací klienta**, ne po ní.
2. Dokument kontraktu má **verzi** a sekci s historií změn.
3. Commit message na obou stranách nese stejné číslo issue — je to jediná
   dnešní vazba mezi těmi dvěma commity.
4. Přejmenování polí je změna kontraktu, i když se „nic nemění". Precedent:
   názvy polí ve schématu AI výstupu musí přesně odpovídat výměnnému formátu,
   protože klient (model, runner) nic nepřejmenovává — nesoulad se projeví tichým prázdným
   `_rawOutput`, ne chybou.

Absence atomicity těchto dvou commitů je jediný reálný důvod, proč zůstává
issue #47 otevřené.

---

## 13. Checklist souladu

Komponenta je v souladu se standardem, když:

- [ ] `bin/shpd-<name>` existuje a je bash
- [ ] všech šest povinných verbů funguje, `--help` je dělí od vlastních
- [ ] `upgrade --dry-run` vypíše plán a nic nezmění
- [ ] `doctor` na zdravém stroji vrací 0, na rozbitém 1
- [ ] clone je v `/opt/shipard-<name>/src`, `upgrade` z něj umí `git pull`
- [ ] `deploy/packages.txt` je úplný (ověřeno na čistém kontejneru)
- [ ] `CLAUDE.md` existuje, má sekci o kontraktech vůči `shpd`
- [ ] `docs/README.md` indexuje triádu, dokumentace je česky
- [ ] `status` vypisuje verzi i git hash
- [ ] každý kontrakt vůči `shpd` má test proti fake serveru
- [ ] `tasks/README.md` a `scripts/tasks-index.py` na místě

## 14. Stav souladu

| Komponenta | Kategorie | Stav |
|---|---|---|
| `ai-analyzer` | runtime služba | **zrušena** (#85 D24, 2026-10-10) — repozitář archivován, analýzu pošty dělá `shpd` v procesu |
| `mail-router` | runtime služba | nesouladná — chybí `CLAUDE.md`, CLI, prázdné `tasks/` |
| generátor videa (#48) | nástroj | nevzniklo — píše se přímo podle standardu |
| Gotenberg (#34) | vendorovaná infra | dle standardu — pin + unit template `docs/render/shpd-render.service`, provoz `docs/operations/render-service.md`, klient `src/Core/Render/` (`docs/render.md`); rollout na alfě zbývá |

---

[← docs/README.md](README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
