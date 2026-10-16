# AI: výchozí modely úrovní na řadu 5 (M4)

**Stav:** hotovo — 2026-10-16 (#103 D47–D50): úrovně na řadě 5, ověřovací běhy v rámci šumu, Haiku 5.5 pro štítky nestojí za přepnutí (D49 trvá, § 3a)

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

### 3a. Výsledky ověřovacích běhů (2026-10-16)

Dva běhy nad sadou `cz-vat-payer` (hash `427e8322`, prompt v4.8.0), eval
zdroj dat `vg39-l` po `ds-upgrade` (`ai-test` tam ukázal všech pět funkcí
na `claude-sonnet-5-5` přes serverové připojení, u vytěžení úpravu
„teplota 0 se neposílá“), 20 zpráv × 3 = 60 volání na variantu, git
`f9355aee`. Schválení útraty v chatu (~5,5 USD); skutečnost 5,67 USD.
Odhady příkazu 3,73 + 2,86 USD: první podle posledního běhu `baseline`
(ještě Sonnet 4.6 — odhad bere poslední běh téže varianty bez ohledu
na model), druhý z tokenů nového běhu `baseline` téže řady. Pro srovnání
sloupce z F4b § 5.

| Metrika | baseline F4b (Sonnet 4.6) | sonnet-5-5-tags F4b | **baseline** po přepnutí | **sonnet-5-5-haiku-tags** |
|---------|---------------------------|---------------------|--------------------------|---------------------------|
| Soubor `tests/eval/cz-vat-payer/…` | `2026-10-15-baseline-3` | `2026-10-15-sonnet-5-5-tags-2` | `2026-10-16-baseline` | `2026-10-16-sonnet-5-5-haiku-tags` |
| Model extrakce / štítků | 4.6 / 4.6 (z úrovně) | 5.5 / 5.5 (přepis) | 5.5 / 5.5 (z úrovně) | 5.5 (z úrovně) / Haiku 5.5 (přepis) |
| Volání / úspěšných / selhání | 60 / 60 / 0 | 60 / 60 / 0 | 60 / 60 / 0 | 60 / 60 / 0 |
| Shoda polí celkem, průměr [min–max] (norm.) | 94,1 % [94,1–94,2] (1,3 %) | 94,8 % [94,8–94,9] (1,1 %) | **94,8 %** [94,8–94,9] (1,0 %) | 94,8 % [94,8–94,8] (1,0 %) |
| Hlavička / dodavatel / odběratel | 93,5 / 90,4 / 98,2 % | 93,9 / 92,0 / 99,6 % | 94,2 / 91,9 / 99,7 % | 94,0 / 91,9 / 99,8 % |
| DPH / řádky / rekapitulace / součty | 99,7 / 92,3 / 100 / 100 % | 99,8 / 92,9 / 100 / 100 % | 99,6 / 92,9 / 100 / 100 % | 99,7 / 92,8 / 100 / 100 % |
| Štítek dokladu | 100 % | 100 % | 100 % | 100 % |
| Výjimky štítků řádků | 88,4 % [87,8–89,6] | 91,5 % [91,5–91,5] | 91,0 % [90,3–91,5] | 90,2 % [89,6–90,5] |
| Klasifikace zprávy | 100 % | 100 % | 100 % | 100 % |
| Doklady bez odchylky | 5,0 % | 10,0 % | 10,0 % | 10,0 % |
| Správné částky / počet řádků / klasifikace / štítek | 100 / 100 / 100 / 100 % | 100 / 100 / 100 / 100 % | 100 / 100 / 100 / 100 % | 100 / 100 / 100 / 100 % |
| Cena běhu (na doklad) — jen extrakce | 3,73 USD (0,0621) | 2,89 USD (0,0482) | 2,85 USD (0,0475) | 2,82 USD (0,0471) |
| Tokeny na doklad (vstup / výstup) | 9,6 k / 2,2 k | 11,5 k / 2,5 k | 11,5 k / 2,4 k | 11,5 k / 2,4 k |
| Čas na doklad | 31,3 s | 13,9 s | 13,2 s | 13,0 s |
| Stabilních uzlů z 85 (věcně) | 80 | 80 | 80 | 77 |
| Odhad → skutečnost | 4,14 → 3,73 (90 %) | 3,13 → 2,89 (92 %) | 3,73 → 2,85 (76 %) | 2,86 → 2,82 (99 %) |

**`ai-eval-compare` proti `sonnet-5-5-tags-2`** (na jedno opakování;
„mimo šum“ = rozdíl nad rozpětím opakování obou běhů):

- **`baseline` × 3** — celkem 94,8 → 94,9 % (+0,0, v rámci šumu), všechny
  skupiny v rámci šumu kromě DPH −0,2 %: `vat.reverseCharge` 0 → 1
  / opak. — doklad 0802 (dodavatel mimo ČR) dal ve všech třech
  opakováních `null` místo `false`; `sonnet-5-5-tags-2` ho měl 0×,
  `sonnet-5-5-haiku-tags` 1× ze 3 — nedeterminismus jednoho dokladu,
  ne vliv přepnutí. `payment.paymentReference` 2,7 → 1 zlepšené,
  `notes.onDocument` 9 → 9,7 zhoršené. Cena 0,963 → 0,949 USD / opak.
  (−1,5 %), čas 13,9 → 13,2 s na doklad. **Kritérium bodu 1 splněno:**
  model z mapy úrovní dává totéž co výslovný přepis na Sonnet 5.5.
- **`sonnet-5-5-haiku-tags` × 3** — celkem 94,8 → 94,8 % (−0,1, v rámci
  šumu), štítek dokladu 100 %, **výjimky štítků řádků 91,5 → 90,2 %
  (−1,3 %, mimo šum)**: Haiku přidává výjimky navíc — 0701 řádek 1
  `office.supplies` 3× (Sonnet 0×), 0402 řádek 2 `services.waste` 3×
  (Sonnet 5.5 také 3×, nový `baseline` 2×), 1002 řádek 22
  `office.equipment` 1×; chybějící výjimky na řádcích 25–28 dokladů 1001
  / 1002 mají všechny varianty stejně (mezera promptu štítků, ne
  modelu). Stabilních uzlů 77 z 85 (Sonnet 80). Proti novému `baseline`
  jsou výjimky −0,8 % v rámci šumu (3,3 → 4 odchylky / opak.);
  `customer.taxId` 1,3 → 0,7 „zlepšené“ je rozptyl extrakce, ne štítků.
- **Cena štítků v běhu není vidět:** `cost.usd` a tokeny souboru výsledků
  nesou jen volání extrakce (`AnalysisComputation`; `ContentTagClassifier`
  tokeny ani cenu nevrací), rozdíl 2,85 → 2,82 USD je jen kratší výstup
  extrakce. Podle typické velikosti volání štítků z `EvalCostEstimator`
  (1 500 / 120 tokenů) stojí štítky na Sonnet 5.5 ≈ 0,004 USD a na Haiku
  5.5 ≈ 0,0002 USD na doklad — úspora nejvýš 8 % ceny dokladu
  (0,048 USD). Podklad #118: evidovat tokeny volání štítků ve výsledku
  evaluace, aby byla cena štítků měřitelná.

**Doporučení pro `fast`:** zůstat na Sonnet 5.5 (D49 trvá). Haiku 5.5
pro štítky ztrácí 1,3 % na výjimkách řádků (jedna výjimka navíc na
dokladu 0701 systematicky), má méně stabilních uzlů a úspora je nejvýš
8 % ceny dokladu; `dashboard.summary` se neměřil. Vrátit se k tomu, až
prompt štítků bude řešit výjimky navíc (0701, 0402) nebo až objem udělá
z 8 % částku, která stojí za řeč.

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
- [x] `ai-test` na eval zdroji dat ukáže u funkcí z tabulky model řady 5
      — 2026-10-16, všech pět na `claude-sonnet-5-5`
- [x] `baseline` × 3 v rámci šumu se `sonnet-5-5-tags-2` (+0,0 %);
      `sonnet-5-5-haiku-tags` × 3 vyhodnocený (§ 3a) — 2026-10-16, 5,67 USD
- [x] dokumentace (`docs/ai.md`, `docs/roadmap.md`, `ai-analysis.md`, `local-dev.md`,
      navíc `help/nastaveni-ai.md` a komentáře v `models.jsonc` / `tiers.jsonc`) — 2026-10-16
- [x] hlavička tasku, `python3 scripts/tasks-index.py` — 2026-10-16

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
  *Ověřeno 2026-10-16 (§ 3a): Haiku pro štítky −1,3 % na výjimkách řádků
  za úsporu nejvýš 8 % ceny dokladu — `fast` zůstává na Sonnet 5.5.*
- ✓ **D50 — Ověřovací běhy po přepnutí:** `baseline` (= nový výchozí)
  a kombinace Sonnet 5.5 + Haiku 5.5 pro štítky, po schválení útraty.
  *Hotovo 2026-10-16 (§ 3a), 5,67 USD.*
