# AI — sekce AI v Nastavení, přehled modelů, test volání, nápověda (#85 fáze 1c)

**Stav:** naplánováno — rozhodnutí D49–D52 potvrzena 2026-10-11 (#85), připraveno k implementaci

## Status / cíl

Po fázi 1b má správce dvě místa, kde se nastavuje AI: **AI připojení**
a **AI funkce**. Obě ale leží v Nastavení pod Ostatní → Pošta mezi
schránkami a frontou odchozí pošty, i když se týkají také asistenta
a shrnutí Dashboardu. Chybí přehled, jaké modely systém zná, a způsob,
jak ověřit, že nastavení skutečně funguje — dnes se to pozná až podle
selhané analýzy.

Cíl: **vlastní sekce AI v Nastavení** (připojení, funkce, přehled
modelů), u funkce vidět **co se modelu skutečně pošle**, **test volání**
jedním příkazem a **nápověda** pro správce.

GitHub Issue: shipard/shipard#85 (D8; fáze 1c).

## Návaznost

- `tasks/ai-functions.md` — viewery `core.ai.backends`
  a `core.ai.functions`, `AiFunctionResolver`, původ hodnot v
  `ResolvedAiCall`. **Předpoklad**, hotovo.
- `tasks/ai-model-catalog.md` — katalog, `RequestShaper`.

## Před implementací přečti

- `docs/ai.md`; `docs/frontend.md` (Nastavení, viewery);
  `docs/modules.md` (`settingsItems`, sekce nastavení).
- `modules/install/base/config/settingsSections.jsonc`,
  `src/Api/Controller/SettingsController.php`.
- `modules/core/ai/src/` — `AiFunctionsViewer` (`renderDetail()`),
  `AIBackendsViewer`, `ModelOptions`.
- `src/Core/Ai/` — `AiFunctionResolver`, `ResolvedAiCall`,
  `RequestShaper`, `ModelCatalog`.
- `help/README.md` — pravidla uživatelské dokumentace; `scripts/help-index.py`.

## Rozhodnutí k designu (potvrzená)

### Směr (#85, zamčeno 2026-10-10)

- ✓ **D8** — Sekce AI v Nastavení: připojení a funkce; AI profily pošty
  zůstávají u Pošty.

### Prováděcí (#85, potvrzeno 2026-10-11)

- ✓ **D49 — Sekce `ai`.** Nová sekce nejvyšší úrovně v
  `settingsSections` (název „AI“, před „Ostatní“). Položky:
  1. **Připojení** (`core.ai.backends`),
  2. **Funkce** (`core.ai.functions`),
  3. **Modely** — nový přehled katalogu jen pro čtení (D50).

  Z Ostatní → Pošta obě stávající položky mizí; AI profily pošty, běhy
  analýz a claimy tam zůstávají.
- ✓ **D50 — Přehled modelů** (`core.ai.modelCatalog`, jen čtení): modely
  s úrovní z katalogu — název, úroveň, cena vstup / výstup, stav
  (aktivní, končí dne…, skončil), které úrovně na něj dnes ukazují.
  V detailu schopnosti (teplota, režimy thinking, úrovně effortu, strop
  výstupu, tool smyčka asistenta) a ID na platformách. Modely jen s cenou
  se nezobrazují.
- ✓ **D51 — Detail funkce říká, co se pošle.** K dnešním efektivním
  hodnotám s původem přibude:
  - **úpravy požadavku** — co `RequestShaper` pro efektivní model změní
    („teplota se neposílá — model ji nepřijímá“),
  - **stav resolveru** — chyba, kterou by volání skončilo (chybí
    připojení, klíč, model není na platformě, model nezvládne nástroje),
    česky a s radou, kde to opravit.
- ✓ **D52 — Test volání.** Nový příkaz `shpd-ds ai-test [--function <id>]`:
  pro jednu nebo všechny funkce provede rozhodnutí resolveru a pošle
  modelu minimální požadavek (pár tokenů). Vypíše připojení, model, čas,
  tokeny a cenu, nebo chybu ve stejném znění jako v UI. Návratový kód
  nenulový, když některá funkce selže. V UI stejný test jako akce
  „Otestovat“ nad funkcí, **pokud** viewery Nastavení umějí akci
  s voláním API bez zásahu do frameworku; jinak zůstane jen CLI
  a v detailu funkce odkaz na příkaz.

## Scope

### V rozsahu

- Sekce `ai` a přesun položek (D49); přehled modelů (D50).
- Rozšíření detailu funkce (D51).
- `ai-test` (D52).
- Nápověda:
  - nová stránka `help/` „AI — připojení, funkce a modely“ (pro správce:
    kde vzít klíč, co je úroveň modelu, jak funkci přepnout na jiný
    model, co znamenají upozornění a chyby, jak nastavení ověřit),
  - `prvni-nastaveni.md` (krok s klíčem AI, odkaz), `slovnicek.md`
    (připojení, AI funkce, úroveň modelu), `co-dnes-nejde.md` (asistent
    na modelech řady 5),
  - `scripts/help-index.py`.
- Dokumentace: `docs/ai.md` (sekce Nastavení a `ai-test`), `docs/cli.md`,
  `CLAUDE.md` (seznam CLI), `docs/dev/local-dev.md` („ověř AI příkazem
  `ai-test`“).

### Mimo rozsah

- Změna oprávnění k nastavení AI.
- Přehled spotřeby a nákladů AI per funkce.
- Výchozí připojení pro nové zdroje dat na vývojářském serveru (#103).
- Evaluace (odloženo do #103).

## Kroky

Každý krok je samostatný commit.

### 1. Sekce a přehled modelů

- `settingsSections.jsonc` (base; ověřit, co s položkou udělá instalace
  bez sekce `ai` — hosting `core.ai` nemá, nic se nesmí rozbít).
- `core.ai` `module.jsonc`: `settingsItems` do sekce `ai`; nový viewer
  `core.ai.modelCatalog` nad cfgItemem (vlastní `selectRows()` jako
  `AiFunctionsViewer`), bez formuláře a bez akcí.
- Testy: `SettingsControllerTest` (sekce a pořadí položek),
  test vieweru modelů (řazení podle úrovně, stav „končí“, mapování
  úrovní).

### 2. Detail funkce

- Jedno místo, které pro funkci vrátí „co by se stalo“: rozhodnutí
  resolveru bez dešifrování klíče (nebo zachycená `AiFunctionException`)
  + seznam úprav z `RequestShaper`. Použije ho viewer i `ai-test`.
- Texty chyb a úprav v `cs` / `en` na jednom místě (cfgItem nebo
  překladová tabulka podle konvence modulu), ne v kódu vieweru.
- Test: funkce bez připojení, bez klíče, s modelem řady 5 a teplotou
  v deklaraci, s vlastním modelem.

### 3. `ai-test`

- `src/Command/DataSource/AiTestCommand.php`, registrace, nápověda.
  Požadavek: krátká instrukce („Reply with OK.“), `max_tokens` nejmenší,
  jaký model přijme s vypnutým nebo výchozím thinkingem; u funkcí
  s `tools` bez nástrojů (testuje se dosažitelnost, ne tool smyčka).
  Volba `--json`.
- Akce „Otestovat“ v UI podle D52.
- Test příkazu s falešným klientem: úspěch, chyba resolveru, chyba API
  (401, 404, strop útraty), návratové kódy.

### 4. Nápověda a dokumentace

- Stránky podle „V rozsahu“; `python3 scripts/help-index.py`.
- `**Stav:**` + `python3 scripts/tasks-index.py`.

## Ověření (zdroj dat v režimu `volný`)

1. Nastavení má sekci AI se třemi položkami; v Ostatní → Pošta už
   připojení ani funkce nejsou.
2. Přehled modelů: Sonnet 4.6 s označením, že na něj ukazují všechny tři
   úrovně; Sonnet 4.5 „končí 30. 11. 2026“.
3. Detail funkce `mail.extraction` na výchozím nastavení: žádná úprava.
   Po přepnutí na `claude-sonnet-5-5`: „teplota se neposílá“. Vrátit.
4. `chat.default` přepnutý na Sonnet 5.5: detail ukazuje chybu
   s vysvětlením; vrátit.
5. `shpd-ds ai-test` — pět řádků OK s časem a cenou; se špatným klíčem
   srozumitelná chyba a nenulový návratový kód.
6. Asistent odpoví na „jak nastavím jiný model pro štítky“ z nové
   stránky nápovědy.

## Pasti

- **Instalace bez `core.ai`** (hosting) nesmí zobrazit prázdnou sekci
  AI ani spadnout.
- **Detail nesmí dešifrovat klíč** jen kvůli zobrazení — stačí vědět, že
  je uložený.
- **`ai-test` stojí peníze** (zlomky centu na funkci) a na hostovaném
  zdroji dat jde přes gateway do společného limitu — neplánovat ho do
  cronu, nevolat automaticky z UI.
- **Jeden zdroj textů.** Hláška z detailu, z `ai-test` a z chyby asistenta
  má být stejná věta, ne tři varianty.

## Hotovo když

- [ ] Kroky 1–4 jako samostatné commity, `php -l`, cílené testy, celá
      sada, `npm run build` při změně frontendu.
- [ ] Ověření 1–6 provedeno, výsledek do #85.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` a `help-index.py`
      spuštěné.
