# Instalace prostředí pro video runner

Runner potřebuje tři věci: **Node 24+**, **Chromium od Playwrightu**
a **ffmpeg**. Nic dalšího — záznam běží headless přes CDP screencast, takže
žádný X server ani window manager ve hře není.

Dokument je psaný prostředí-agnosticky — konkrétní příkazy jsou pro
Debian/Ubuntu, na jiné distribuci se liší jen názvy balíčků.

---

## 1. Systémové balíčky

```bash
sudo apt install ffmpeg fonts-liberation fonts-noto-core
```

| Balíček | K čemu |
|---------|--------|
| `ffmpeg` | kompozice raw záznamu, vypálení titulků, škálování. **Povinné.** |
| `fonts-liberation`, `fonts-noto-core` | viz níže. |

### Fonty jsou součást zadání, ne detail

Videa se musí mezi sebou vzhledově shodovat. Když se aplikace na jednom
stroji vykreslí v Liberation Sans a na druhém spadne na DejaVu Sans,
rozejdou se šířky textů, zalomení a tím i celý layout — a to je vidět
ve chvíli, kdy vedle sebe na webu stojí dvě videa natočená jinde.

Minimální sada je proto **`fonts-liberation` + `fonts-noto-core`**.
Ověření, že v systému je proporcionální bezpatkový font (ne jen
`DejaVu` a `Noto Mono`):

```bash
fc-list : family | tr ',' '\n' | sort -u | grep -Ei 'liberation|noto sans'
```

Titulky se vypalují stejným fontem jako běží aplikace — pokud se sada
fontů změní, přegeneruj i starší videa (`compose` nad uloženým raw,
netočí se znovu).

---

## 2. Node 24+

```bash
node --version   # musí být v24 nebo vyšší
```

Runner používá `process.loadEnvFile()` a `node:util` `parseArgs`, obojí
bez závislostí — starší Node spadne.

> **Pozor na nvm.** Když je Node nainstalovaný přes `nvm`, **není na
> PATH v neinteraktivním shellu** — tedy v cronu, v systemd unitě
> a v čemkoli, co spustí `sh -c`. Projeví se to jako `node: command not
> found` ve chvíli, kdy se runner poprvé pustí jinak než z terminálu.
> Řešení: buď systémový Node (`apt`, NodeSource), nebo v cronu/unitě
> volat plnou cestu (`$(nvm which 24)`), nebo v unitě nastavit
> `Environment=PATH=...`. Až vznikne `install.sh`, musí s tím počítat.

---

## 3. Závislosti podprojektu a Chromium

```bash
cd tools/video-runner
npm install
```

Chromium se instaluje **ve dvou krocích** — systémové závislosti jako root,
samotný prohlížeč pod svým účtem. `npx` se pod `sudo` ale nepouští:

```bash
npx playwright install-deps --dry-run chromium   # jako běžný uživatel: vypíše, co chybí
sudo apt install <balíčky z výpisu>              # instaluje se ručně
npx playwright install chromium                  # prohlížeč do ~/.cache/ms-playwright
```

> **Proč ne `sudo npx playwright install-deps chromium`.** `sudo` zahodí
> tvůj PATH a nahradí ho hodnotou `secure_path` z `/etc/sudoers`, kde nvm
> být nemůže → `sudo: npx: command not found`. Obejití plnou cestou narazí
> o krok dál: `npx` má shebang `#!/usr/bin/env node` a `env` hledá `node`
> v tom už resetovaném PATH → `/usr/bin/env: 'node': No such file or
> directory`. Nouzově to jde přebít přes `sudo env "PATH=$PATH" npx …`, ale
> není proč: `install-deps` nedělá nic jiného než `apt install` pevného
> seznamu, takže je průhlednější vytáhnout seznam přes `--dry-run`
> a nainstalovat ho sám.

**Autoritativní je vždy výstup `--dry-run`** — Playwright ho generuje podle
distribuce a své verze. Referenční stav pro Ubuntu 24.04 s desktopovými
balíčky (Playwright 1.62): systémové `.so` knihovny už v systému jsou
a chybí jen fonty pro pokrytí CJK, emoji a cyrilice:

```bash
sudo apt install fonts-freefont-ttf fonts-ipafont-gothic fonts-noto-color-emoji \
                 fonts-tlwg-loma-otf fonts-unifont fonts-wqy-zenhei \
                 xfonts-cyrillic xfonts-scalable
```

Pro česká videa je nepotřebuješ, ale bez nich Chromium hlásí varování —
a hlavně se má dev stroj vzhledově chovat stejně jako budoucí `ns-media`.

Proč ne jedním `sudo npx playwright install --with-deps chromium`: pod
`sudo` by se prohlížeč stáhl do `/root/.cache/ms-playwright`, kde ho běh
pod tvým účtem nenajde. Instalace prohlížeče se pouští **z
`tools/video-runner/`**, aby se stáhla verze Chromia odpovídající zdejšímu
připnutému Playwrightu.

---

## 4. Konfigurace

```bash
cp .env.example .env
$EDITOR .env
```

Vyplň `SHPD_BASE_URL`, `SHPD_LOGIN` a `SHPD_PASSWORD`. `.env`
i `.storage-state.json` jsou v `.gitignore` — uložená session je stejně
citlivá jako heslo.

`.env` je potřeba jen pro **ruční použití** runneru z terminálu. Soubor je
volitelný: proměnné už nastavené v prostředí mají přednost a `shpd-ds
video-run` předává runneru všechno prostředím (adresu instance, session,
adresáře, pevný čas i mapování hostitele), takže na serveru žádný `.env`
nevzniká. Přehled proměnných je v `README.md` → Konfigurace.

---

## 5. Ověření

```bash
node bin/video-runner.mjs --help          # vypíše verby
node bin/video-runner.mjs login           # vyrobí .storage-state.json
node bin/video-runner.mjs check ../../demo/scenarios/s1-prijata-faktura.jsonc
```

`check` projede scénář bez záznamu a vrátí 0. Nenulový kód znamená, že
scénář na něco nesedí — hláška řekne který krok a jaký selektor; totéž
strojově zapíše `VIDEO_WORK_DIR/<id>/result.json`.

Nakonec záznam a kompozice v jednom běhu:

```bash
node bin/video-runner.mjs build ../../demo/scenarios/s1-prijata-faktura.jsonc
```
