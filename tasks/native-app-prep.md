# Nativní aplikace — příprava na aplikaci: platforma `ios` a repo `shipard-app` v dokumentaci (#177)

**Stav:** naplánováno — #177 D34, D36, D41 (2026-11-28); patří před ruční ověření tasku 5a na iPhonu

> Task pro Claude Code (3 commity). Design: issue #177. Aplikace vzniká
> v repu `shipard/shipard-app` (`shipard_app:tasks/apple-phase1.md`,
> task 5a) a na iPhonu hlásí `platform: "ios"`, které server dnes odmítne.
> Kontrakt se mění **před** implementací klienta (`docs/services.md` §12),
> proto jde tenhle task před ověřením 5a. Fixture z CryptoKitu
> a stav tasku 5a v kontraktu přinese až `tasks/native-app-fixture.md`
> po 5a. Hotové tasky `native-*.md` jsou historie a nemění se.

## Před implementací přečti

- `docs/native-bridge.md` — §1, §2.4 (přidání hodnoty výčtu verzi nemění),
  §3.1, §7.2, §10, §11
- `docs/services.md` §9, §11a, §14
- `docs/ai-workflow.md` §4, §6, §8
- `docs/frontend.md` — cílové prohlížeče (začátek dokumentu) a kap. 13.5
  Nativní most
- `modules/core/devices/README.md`

## Rozhodnutí (#177, potvrzená 2026-11-28)

- **D34** — Aplikace žije v `shipard/shipard-app`, její tasky tam (řada
  `apple-phase<N>`: 5a kostra, most a identita; 5b síťové tiskárny;
  5c Bluetooth a čtečky). V hlavním repu zůstává kontrakt a serverová
  strana.
- **D36** — Minimum aplikace: macOS 26, iPadOS 26 a iOS 26 (aktuální
  hlavní verze a jedna předchozí).
- **D41** — iPhone patří do v1, ve stejném cíli aplikace jako iPad;
  platforma `ios`.
- Commity v `shipard-app` jsou česky jako v `shpd` — standard komponent
  (`docs/services.md` §9) to dnes říká opačně a opraví se.

## Co je potřeba udělat

### 1. Kontrakt `docs/native-bridge.md`

- `platform`: `macos` \| `ipados` \| `ios` (§3.1; simulace dál `mock`).
  Tvar těla `POST /_devices/register` (§7.2) se nemění.
- Přehled (§1) a tabulka „Co je ve verzi 1“: macOS, iPadOS a iOS.
- Odkazy do repa aplikace sjednotit na prefix `shipard_app:` (`project_id`
  z mapy projektů, `tasks/README.md` → Referencování externích
  repozitářů) — dnes je tam `shipard-app:apple/`.
- §10 řádek aplikace: „Aplikace pro macOS, iPadOS a iOS
  (`shipard_app:apple/`) | 5 | rozpracováno — 5a
  `shipard_app:tasks/apple-phase1.md` (kostra, most, identita), 5b síťové
  tiskárny, 5c Bluetooth a čtečky“.
- Historie změn: „Platforma `ios` (iPhone, #177 D41) — přidání hodnoty
  výčtu verzi nemění (§2.4). Před implementací klienta, verze beze změny.“

### 2. Server

- `DeviceRegistrationService::PLATFORMS` += `ios`; `DevicesViewer` popisek
  `platform.ios` = „iOS“. Sloupec `platform` je `varchar` — schéma beze
  změny.
- `modules/core/devices/README.md` — platformy zařízení.
- Testy: registrace s `ios` uloží platformu, neznámá platforma dál
  `VALIDATION_ERROR`; popisek ve vieweru.

### 3. Web

- `frontend/src/native/contract.js` (`PLATFORMS`) a
  `frontend/src/components/devices/thisDeviceState.js` — hodnota `ios`;
  i18n `devices.platform.ios` = „iOS“ v `cs.js` i `en.js`.
- Testy frontendu: validátor `info` přijme `ios`, panel Toto zařízení ho
  ukáže.
- `docs/frontend.md` — cílové prohlížeče: WKWebView v nativní aplikaci
  macOS 26, iPadOS 26 a iOS 26 (D36; dnes macOS 14, iPadOS 17); kap. 13.5
  „macOS, iPadOS a iOS“.

### 4. Repo `shipard-app` v dokumentaci

- `tasks/README.md`:
  - Mapa projektů: řádek `shipard_app` | `shipard/shipard-app` | Nativní
    aplikace (Swift; macOS, iPadOS, iOS) — v `remote-dev-bridge` není,
    checkout je na Macu vývojáře.
  - Oddíl Nativní aplikace: „macOS, iPadOS a iOS“, věta, že tasky aplikace
    žijí v `shipard_app:tasks/` (řada `apple-phase<N>`), a řádek tohoto
    tasku.
- `docs/services.md`:
  - §9 — commit messages **česky** s prefixem typu a oblasti jako v `shpd`;
    anglicky zůstává kód a log eventy.
  - §11a — ve stromu `apple/  # macOS, iPadOS a iOS`.
  - §14 — řádek `shipard-app`: „vzniká podle standardu — kostra repa
    a task 5a (`shipard_app:tasks/apple-phase1.md`)“.
- `docs/roadmap.md`, `docs/features.md`, `docs/README.md` a `CLAUDE.md` —
  kde se jmenují platformy aplikace: macOS, iPadOS a iOS.
- `docs/ai-workflow.md`:
  - §6 Mapa zdrojů — řádek repa `shipard/shipard-app` (veřejné):
    `CLAUDE.md`, `apple/CLAUDE.md`, `docs/`, `tasks/`; `git clone` na Mac,
    Claude Code načte `CLAUDE.md` sám, v Projektu claude.ai GitHub sync.
  - §4 Nástroje a návyky — Claude v chatu a **připojená složka desktopové
    aplikace** (repo aplikace na Macu): shell je Linuxová VM bez Xcode
    a vidí jen připojenou složku; mazání souborů je v ní vypnuté, proto
    git jen čtecími příkazy s `git --no-optional-locks` (jinak v `.git`
    zůstanou zámky); build a testy pouští Claude Code a chat čte souhrn
    `apple/.build/test-summary.txt`.
  - §8 Instrukce Projektu — do textu instrukcí za „Tvrdá pravidla“
    odstavec níže.

Odstavec do §8:

```markdown
**Nativní aplikace `shipard/shipard-app`** (#177): konvence a postup buildu
jsou v jejím `CLAUDE.md` a `apple/CLAUDE.md` — načti je na začátku práce na
aplikaci, a `CLAUDE.local.md` z checkoutu taky. Checkout je na mém Macu
v připojené složce desktopové aplikace: čteš, hledáš, píšeš tasky a `docs/`;
build a testy pouští Claude Code, ty čteš souhrn `apple/.build/test-summary.txt`.
Git jen čtecí příkazy s `--no-optional-locks`. Zdroj pravdy pro obě strany je
`docs/native-bridge.md` v `shipard/shipard`. Navíc platí:
- Nikdy nečti podpisové certifikáty, profily, Keychain ani klíče
  (Sparkle, App Store Connect); žádné archive, notarizace ani upload.
- Aplikace se ve vývoji připojuje jen ke zdroji dat `free` — registrace
  zařízení i tiskové úlohy jsou zápisy do zdroje dat.
```

## Commity

1. `docs(native): platforma ios a minimum systémů v kontraktu mostu (#177 D36, D41)` — `docs/native-bridge.md`, `docs/frontend.md` (cílové prohlížeče); kontrakt jde první
2. `feat(devices): platforma ios u zařízení (#177 D41)` — server, web, i18n, testy, `modules/core/devices/README.md`
3. `docs: repo shipard-app v mapě projektů, standardu komponent a ai-workflow (#177 D34)` — bod 4, **Stav**, `python3 scripts/tasks-index.py`

## Hotovo když

- [ ] cílené PHPUnit (`--filter 'DeviceRegistration|DevicesViewer|DevicesController'`)
      projde; registrace s `ios` uloží platformu, neznámá platforma vrátí
      `VALIDATION_ERROR`
- [ ] `cd frontend && npm test` projde, `npm run build`
- [ ] viewer Zařízení a panel Toto zařízení ukazují „iOS“ (testy popisků)
- [ ] dokumentace podle bodů 1, 3 a 4; popis aplikace „jen macOS
      a iPadOS“ nezůstal mimo historii (`grep -rn iPadOS --include=*.md docs tasks/README.md CLAUDE.md`)
- [ ] hlavička **Stav** a index tasků aktualizované
- [ ] (člověk) instrukce Projektu v claude.ai přepsané podle
      `docs/ai-workflow.md` §8
