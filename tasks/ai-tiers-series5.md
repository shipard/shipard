# AI: výchozí modely úrovní na řadu 5 (M4)

**Stav:** částečně — přepnutí úrovní, testy a dokumentace hotové 2026-10-16 (#103 D47–D50); čekají ověřovací běhy (§ 3) po schválení útraty

## Kontext / Cíl

Evaluace F4 a F4b (`tasks/testing-phase4.md`, `tasks/testing-phase4b.md`
§ 5–6) porovnala pět modelů nad sadou plátce na promptu v4.8.0. Sonnet 5.5
vyšel nejlépe ze všech: shoda polí 94,8 % proti 94,1 % u Sonnetu 4.6, dvakrát
víc dokladů bez odchylky, žádný zkomolený IBAN a žádné selhání. Je přitom
2,25× rychlejší a o 22 % levnější. Opus 5.5 a Fable 5.1 nepřinesly nic navíc
a Haiku 5.5 se na extrakci nehodí.

**Cíl:** přepnout výchozí modely úrovní v `modules/core/ai/config/tiers.jsonc`
pro přímé API Anthropic na řadu 5. Roadmapa M4 („přechod výchozího modelu
na řadu 5 po evaluaci“) tím bude splněná. Platforma `bedrock-eu` už dnes
jede na Sonnetu 5.5 ve všech úrovních, takže se přímé API srovná s EU cestou.

Úrovně neslouží jen extrakci:

| Úroveň | Funkce |
|--------|--------|
| `balanced` | `mail.extraction`, `exchange.bookingHistory`, `chat.default` |
| `fast` | `exchange.contentTags`, `dashboard.summary` |
| `strong` | dnes žádná funkce |

## Návaznost

- **#103** D47–D50 (oddíl na konci); F4 D34–D41, F4b D42–D46
- `docs/roadmap.md` M4 — řádek „AI modely“
- `docs/ai.md` — katalog, úrovně, přepis vyřazených modelů
- `modules/core/ai/src/AiFunctionProvisioner.php` — řádek nastavení funkce
  nese `model` jen tehdy, když se liší od modelu úrovně. Zdroje dat bez
  vlastního přepisu se tedy přepnou samy, zdroje dat s přepisem si svůj
  model ponechají.

## Změny

### 1. Úrovně (`tiers.jsonc`)

| Úroveň | `model` dnes → nově | `platformModels.bedrock-eu` |
|--------|---------------------|-----------------------------|
| `fast` | `claude-sonnet-4-6` → **`claude-sonnet-5-5`** | `claude-sonnet-5-5` (beze změny) |
| `balanced` | `claude-sonnet-4-6` → **`claude-sonnet-5-5`** | `claude-sonnet-5-5` (beze změny) |
| `strong` | `claude-sonnet-4-6` → **`claude-opus-5-5`** | → **`claude-opus-5-5`**, pokud ho katalog pro `bedrock-eu` nabízí. Jinak beze změny a zapsat do tasku |

Ověřeno 2026-10-16: katalog nabízí `claude-opus-5-5` i pro Bedrock
(`platformIds.bedrock`), `strong` má na `bedrock-eu` Opus 5.5. Položky
`platformModels` jsou po přepnutí nadbytečné (Bedrock všechny tři modely
nabízí), zůstávají výslovně, aby je `ModelCatalogFileTest` dál hlídal.

- **`effort` / `thinking`:** beze změny, platí výchozí hodnoty deklarace
  modelu. `xhigh` dal +0,1 % v rámci šumu za +23 % ceny (F4b § 6).
- **Teplota:** deklarace funkcí mají `temperature 0` a tvarovač ji u řady 5
  vynechá. Ověř to unit testem pro každou funkci z tabulky výše.
  *Ověřeno:* teplotu 0 deklaruje jen `mail.extraction`, ostatní čtyři
  funkce jen `maxTokens` — `AiFunctionsTierFileTest` nad skutečnými
  deklaracemi, katalogem a mapou hlídá tabulku úroveň → funkce, model
  řady 5 na obou platformách, `TEMPERATURE_OMITTED` jen u vytěžení
  a žádnou úpravu u ostatních.
- **Haiku 5.5 pro štítky** zatím ne. Měřil se jen spolu s haiku extrakcí
  (bod 3).

### 2. Testy a kód

- Testy, které čekají model úrovně `claude-sonnet-4-6` (katalog, resolver,
  `ai-test`, `ai-eval`, tvarovač…), uprav na nové úrovně. Testy, které
  4.6 používají jako konkrétní model (přepis vyřazených modelů, ceník),
  nech být.
- `EvalCostEstimator`: zkontroluj, jestli `claude-sonnet-4-6` nepoužívá
  jako záložní model. Pokud ano, nahraď ho modelem úrovně.
- Sonnet 4.6 zůstává v katalogu (aktivní nejméně do 17. 2. 2027).
  Zdroje dat s výslovným přepisem na 4.6 dál fungují.

### 3. Ověřovací běhy (evaluace)

Na eval zdroji dat `vg39-l` (volný, bez použitých dokladů,
profil v4.8.0) po `ds-upgrade`:

1. **`baseline` × 3.** Varianta bere model z úrovně, takže teď změří
   Sonnet 5.5 jako výchozí. Výsledek musí sedět se `sonnet-5-5-tags-2`
   z F4b § 5 v rámci šumu. Pokud ne, zastav se.
2. **`sonnet-5-5-haiku-tags` × 3** (nová varianta: `call.model` z úrovně,
   `contentTags.model` `claude-haiku-5-5`). Změří, jestli Haiku stačí
   na štítky, když extrakci dělá Sonnet 5.5. Podklad pro budoucí přepnutí
   `fast` (D49).

**Před během zastav a vypiš odhad ceny** (orientačně ~6 USD za oba běhy).
**Pouštěj až po schválení v chatu.** Výsledky commitni a vyhodnoť v tasku
ve formátu F4b § 5: tabulka, `ai-eval-compare` proti `sonnet-5-5-tags-2`,
doporučení pro `fast`.

## Kroky

1. `tiers.jsonc` (+ ověření `bedrock-eu` pro Opus 5.5 v katalogu).
2. Testy a kód, unit test teploty pro funkce úrovní.
3. Dokumentace:
   - `docs/ai.md` (úrovně a jejich modely, odkaz na evaluaci),
   - `docs/roadmap.md` M4 (přechod na řadu 5 hotový, termín 17. 2. 2027
     už není blokující),
   - `modules/core/mail/docs/ai-analysis.md` a `docs/dev/local-dev.md`
     (zmínky o výchozím Sonnetu 4.6).
4. Varianta `sonnet-5-5-haiku-tags`, `ds-upgrade` na eval zdroji dat,
   odhad ceny, **zastavit se**.
5. Po schválení běhy, vyhodnocení, commit výsledků, hlavička tasku.

## Hotovo když

- [x] `tiers.jsonc`: `fast` a `balanced` Sonnet 5.5, `strong` Opus 5.5
      (`bedrock-eu` podle katalogu — Opus 5.5) — 2026-10-16
- [x] testy úrovní, resolveru, tvarovače, `ai-test` a `ai-eval` zelené
      (úzké `--filter` i celá sada) — 2026-10-16, nový
      `AiFunctionsTierFileTest`
- [ ] `ai-test` na eval zdroji dat ukáže u funkcí z tabulky model řady 5
- [ ] `baseline` × 3 v rámci šumu se `sonnet-5-5-tags-2`;
      `sonnet-5-5-haiku-tags` × 3 vyhodnocený
- [x] dokumentace (`docs/ai.md`, `docs/roadmap.md`, `ai-analysis.md`, `local-dev.md`,
      navíc `help/nastaveni-ai.md` a komentáře v `models.jsonc` / `tiers.jsonc`) — 2026-10-16
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Zdroje dat s přepisem modelu** se nepřepnou. Je to záměr, uživatel
  si model vybral sám. Nepřepisuj jim nastavení.
- **Ostrá data:** přepnutí platí po nasazení pro všechny zdroje dat
  na přímém API bez přepisu, tedy i pro asistenta (`chat.default`)
  a historii účtování. Ty se neměřily, ale na `bedrock-eu` už Sonnet 5.5
  běží.
- **Útrata až po schválení.**

## Rozhodnutí k designu (potvrzená)

- ✓ **D47 — `balanced` → Sonnet 5.5** s výchozím `thinking` / `effort`
  (přímé API; `bedrock-eu` už 5.5 má).
- ✓ **D48 — `strong` → Opus 5.5.** Úroveň dnes nic nepoužívá, ale „silný“
  má znamenat nejsilnější praktický model.
- ✓ **D49 — `fast` zatím → Sonnet 5.5.** Haiku 5.5 pro štítky až po běhu
  kombinace se Sonnet 5.5 extrakcí; úspora je malá, cenu dělá extrakce.
- ✓ **D50 — Ověřovací běhy po přepnutí:** `baseline` (= nový výchozí)
  a kombinace Sonnet 5.5 + Haiku 5.5 pro štítky, po schválení útraty.
