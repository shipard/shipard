# Task: Místo plnění přijatého dokladu podle DIČ dodavatele

**Stav:** částečně — implementováno 2026-09-30 (2 commity), zbývá ověření na dev zdroji

**Issue:** #86 (navazuje na `exchange-received-reverse-charge.md`)

**Cíl:** U přijatého dokladu určí `vat.place` (tuzemsko / intrakomunitární
plnění / třetí země) systém podle **prefixu DIČ dodavatele**, ne model podle
adresy. Dodavatel se sídlem mimo EU, který fakturuje pod DIČ jiného
členského státu, pak dostane `intracom` a kód `cz-217` (ř. 5 + ř. 43),
ne `cz-417` (ř. 12 + ř. 43).

Návaznost: po `exchange-received-reverse-charge.md` (D1 — kód DPH určuje
systém ze signálů) zůstal `vat.place` jediný signál, který rozhoduje
čistě model. Tento task ho převádí na stejný princip.

## Před implementací přečti

- `tasks/exchange-received-reverse-charge.md` — algoritmus derivace kódu,
  tabulka chování, Pasti
- `docs/exchange-format.md` — `vat.place` (~ř. 223), §
  „Odvození kódu DPH u přijatých dokladů“ (~ř. 747), tabulka issues (~ř. 933)
- `modules/world/trade/README.md` + `config/tradeUnions.jsonc` — členství
  a DIČ prefixy s platností
- `modules/core/exchange/src/Document/DocumentApplier.php` —
  `vatContext()` (~ř. 1411), `decideRowVatCode()` (~ř. 1519),
  `appendVatHeaderIssues()` (~ř. 1735), transform `vat_place` (~ř. 1155)
- `modules/core/mail/docs/ai-prompts.md` — changelog, verze

## Kontext — diagnostika

Přijatá faktura za SaaS službu, EUR, doložka o přenesení daně, DPH 0.
Prompt v4.6.0 vrátil (anonymizováno, hodnoty polí přesně):

| pole | hodnota |
|---|---|
| `supplier.country` / `address.country` | `us` |
| `supplier.vatId` | irské DIČ (prefix `IE`, 11 znaků — ne `EU…` z OSS) |
| `vat.place` | `thirdCountry` |
| `vat.reverseCharge` | `true` |
| `rows[].vat.supplyKind` | `services` |

Derivace kódu podle toho správně vybrala `cz-417` (DOVOZ/Vstup/Ostatní,
pár `cz-407` na ř. 12). Pro ř. 5 přiznání ale rozhoduje, zda službu
poskytuje **osoba registrovaná k dani v jiném členském státě** — dodavatel
fakturuje pod irským DIČ a plnění vykazuje v irském souhrnném hlášení
s naším DIČ, finanční správa ho páruje proti ř. 5. Správně je `intracom`
→ `cz-217` / `cz-207`. Výše daně i nároku (ř. 43) je stejná, mění se
řádek výstupu.

Příčina: pravidlo promptu „`intracom` = dodavatel z jiného státu EU,
`thirdCountry` = dodavatel mimo EU“ model čte podle adresy. U amerických
SaaS dodavatelů s irskou, nizozemskou nebo lucemburskou registrací se to
bude opakovat.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — `vat.place` u přijatého dokladu odvodí applier z prefixu DIČ
  dodavatele:** prefix naší země → `domestic`, prefix jiného členského
  státu unie naší registrace → `intracom`. Bez DIČ (nebo s prefixem,
  který unie nezná) platí hodnota z AI. Rozpor s neprázdnou hodnotou z AI
  → warning `vat_place_derived`.
- ✓ **D2 — seznam členských států** je už v cfgItem `world.trade.unions`
  (`modules/world/trade/config/tradeUnions.jsonc`): `members` s
  `joinedAt`/`leftAt`, `taxPrefixes` s `country`, `validFrom`/`validTo`
  (včetně `EL` → `gr`, `GB` do 2020-12-31, `XI`). Nový seznam se
  nezakládá; chybí jen PHP čtení (dnes cfgItem používá jen
  `VatRegistrationsForm`/`Viewer` pro nabídku regionů).
- ✓ **D3 — prompt v4.6.1:** pravidlo `vat.place` přepsat na „podle DIČ
  dodavatele, ne podle adresy“. Doplněk k D1, ne náhrada — zmenšuje počet
  případů s warningem.

**Upřesnění při psaní tasku (potvrzena při plánování 2026-09-30):**

- **`XI` (Severní Irsko) platí jen pro zboží** — v datech je to dnes jen
  textová `note`. Doplní se datové pole `"supplyKinds": ["goods"]` na
  prefix `XI`; derivace ho čte obecně (žádné `=== 'XI'` v kódu). Všechny
  položkové řádky `goods` → `intracom`; kterýkoli řádek `services` →
  `thirdCountry` (služby ze Severního Irska jsou služby z UK); řádek
  s `supplyKind` null → bez derivace (platí AI).
- **Prefix, jehož platnost skončila** (`GB` po 2020-12-31) → `thirdCountry`
  — unie ho zná, jen už k DUZP není členem. Prefix, který unie vůbec nemá
  (`US`, `CHE`, `NO`, `EU` z režimu OSS mimo Unii) → bez derivace.

## Algoritmus derivace (D1, D2)

Nová třída `modules/core/exchange/src/Document/VatPlaceDerivation.php`,
závisí jen na `TradeUnionResolver` (níže). Vstup: země naší registrace,
datum (DUZP, fallback datum vystavení), `supplier.vatId`,
`customer.vatId`, `supplyKind` položkových řádků. Výstup
`{place: ?string, prefix: ?string, reason: ?string}`.

1. **Normalizace DIČ:** uppercase, odstranit vše mimo `[A-Z0-9]`; prefix =
   první dva znaky, jen když jsou `[A-Z]{2}`. Jinak `null`.
2. **Pojistka proti prohozeným stranám:** normalizované DIČ dodavatele =
   normalizované DIČ odběratele → `null` (model dal naše DIČ k dodavateli;
   odvození by dalo chybně `domestic`).
3. **Unie naší země** k datu (`members[země]`, `joinedAt ≤ datum`,
   `leftAt` null nebo `≥ datum`). Žádná → `null`. Víc unií naše země mít
   může (obecná struktura) — použít tu, jejíž `taxPrefixes` prefix zná;
   víc takových = `null` s důvodem.
4. **Prefix v `taxPrefixes` unie:** chybí → `null`.
   Mimo `validFrom`/`validTo` k datu → `thirdCountry`.
5. **Omezení `supplyKinds`** na záznamu prefixu (zatím jen `XI`): všechny
   položkové řádky v seznamu → pokračuj; kterýkoli mimo seznam →
   `thirdCountry`; kterýkoli null → `null` s důvodem.
6. `country` záznamu = naše země → `domestic`, jinak `intracom`.

`modules/world/trade/src/TradeUnionResolver.php` (namespace
`Shipard\Module\World\Trade`, vzor `VatRateResolver`: `ConfigRuntime`
v konstruktoru, `cfgItem('world.trade.unions')`, cache):

- `unionsOf(string $country, string $date): list<string>` — klíče unií,
  kde je země k datu členem;
- `taxPrefix(string $union, string $prefix): ?array` — záznam prefixu
  (`country`, `validFrom`, `validTo`, `supplyKinds`), bez vyhodnocení data;
- `isPrefixValid(array $entry, string $date): bool`.

Rozhraní drží jen čtení dat; rozhodování o místě plnění patří do
`VatPlaceDerivation`, ne do `world.trade`.

## Chování v applieru

Jen když `vatContext()` má `derive = true` (`selfParty: customer` a naše
registrace DPH) — vystavené a účetní doklady a zdroj neplátce beze změny.

`vatContext()` spočítá místo **jednou** a vrátí ho v kontextu
(`place`, `placeSource: "vatId" | "ai" | null`, `placePrefix`);
klíč cache doplnit o `supplier.vatId` a `customer.vatId`. Efektivní místo
= odvozené, jinak `vat.place` z canonicalu. Použije se na **třech**
místech:

- `decideRowVatCode()` — derivace i kontrola souladu kódu (dnes dostává
  `$vat['place']` z canonicalu);
- transform `vat_place` hlavičky (~ř. 1155) — dnes čte canonical;
- `appendVatHeaderIssues()`.

| `vat.place` z AI | derivace | výsledek |
|---|---|---|
| null | místo | odvozené, **bez issue** |
| stejné | místo | beze změny |
| jiné (platné) | místo | odvozené + warning `vat_place_derived` |
| neznámá hodnota (`eu`) | místo | odvozené + `vat_place_derived` (bez `vat_place_unknown`) |
| cokoli | `null` | dnešní chování (`vat_place_unknown` u neznámé hodnoty) |

Zpráva `vat_place_derived` (na `vat.place`): „Místo plnění „{AI}“
z návrhu nahrazeno „{odvozené}“ podle DIČ dodavatele (prefix {XX}).“
Názvy míst česky podle `docs.core.vatPlaces` (`name:cs`) — Tuzemsko,
Intrakomunitární plnění, Zahraničí; neznámou hodnotu z AI uvést, jak přišla.

Canonical se nemění (vzor `VatModeDerivation`) — korekce je jen
v `_resolve` a v uloženém dokladu.

## Co je potřeba udělat

### Commit 1 — čtení unií, derivace místa, applier, docs

- `tradeUnions.jsonc`: na `XI` pole `"supplyKinds": ["goods"]`
  (poznámku `note` ponechat). `modules/world/trade/README.md`: popis pole
  a nové třídy.
- `TradeUnionResolver` + `VatPlaceDerivation` podle algoritmu, docblocky
  s odkazem na tento task.
- `DocumentApplier`: efektivní místo ve `vatContext()`, tři místa použití,
  issue podle tabulky; konstrukce v továrně (~ř. 210) —
  `new VatPlaceDerivation(new TradeUnionResolver($config))`.
- `docs/exchange-format.md`: komentář `vat.place` (~ř. 223 — u přijatého
  dokladu přebíjí DIČ dodavatele), nová podsekce „Místo plnění přijatého
  dokladu (`VatPlaceDerivation`)“ **před** odvozením kódu (kód na místu
  závisí) s tabulkou chování; `vat_place_derived` do tabulky issues
  (~ř. 933).

Testy:

- `tests/Unit/Module/World/Trade/TradeUnionResolverTest.php` nad
  **skutečným** `tradeUnions.jsonc` (vzor `VatReverseCodeRatesTest`):
  `cz` je k 2026 v `eu`, k 2003 v žádné; `EL` → `gr`; `GB` platí
  2020-06-30, ne 2021-01-01; `XI` má `supplyKinds`.
- `tests/Unit/Module/Core/Exchange/Document/VatPlaceDerivationTest.php`:
  `IE…` (země dodavatele `us`) → `intracom`; `CZ…` → `domestic`;
  `DE 123 456 789` s mezerami → `intracom`; `EL…` → `intracom`;
  `GB…` 2026 → `thirdCountry`, 2020-06 → `intracom`; `XI` zboží →
  `intracom`, `XI` služby → `thirdCountry`, `XI` + null → `null`;
  `US`/`CHE-…`/`EU…`/bez DIČ → `null`; DIČ dodavatele = DIČ odběratele →
  `null`; naše země mimo unii → `null`.
- `DocumentApplierTest` (fiktivní dodavatelé a částky):
  scénář z diagnostiky (`us` + `IE…`, AI `thirdCountry`,
  `reverseCharge`, `services`) → `cz-217`, `vat_place` 1,
  `vat_place_derived`; totéž s AI `null` → bez issue; bez DIČ → zůstane
  `thirdCountry` → `cz-417`; bez `vat.place` (ISDOC) s `DE…` →
  `intracom`; `place: "eu"` + `IE…` → `vat_place_derived`, ne
  `vat_place_unknown`; vystavený doklad (`selfParty: supplier`) beze
  změny; prohozené DIČ → bez derivace.
- regrese zelená: `VatCodeDerivationTest`, `testReceivedDocument*`,
  `testDeclaredRecap*`, testy D2/D3/D5 z předchozího tasku.

Ověření: `php -l` na změněných souborech,
`vendor/bin/phpunit --filter 'TradeUnionResolverTest|VatPlaceDerivationTest|VatCodeDerivationTest|DocumentApplierTest|DocumentApplierNoItemTest'`.

### Commit 2 — prompt v4.6.1, help, uzavření

`modules/core/mail/profiles/czech_general.jsonc`:

- `prompt_version` → `v4.6.1` (změna textu bez změny schématu = patch;
  `AIAnalyzerProvisioner` porovnává `version_compare`, sync proběhne),
  v textu `source.promptVersion`.
- Pravidlo `"vat".place` nahradit: určuj ho podle **DIČ (VAT ID)
  dodavatele, ne podle adresy** — prefix `CZ` → `domestic`, prefix jiného
  státu EU (včetně `EL` = Řecko) → `intracom`, i když má dodavatel sídlo
  mimo EU; dodavatel bez DIČ z EU → podle sídla (ČR `domestic`, jiný stát
  EU `intracom`, jinak `thirdCountry`). Výčet povolených hodnot zůstává.

Testy verzí: `ProfileSchemaDriftTest` (ř. 89) a `AIAnalyzerProvisionerTest`
(ř. 177) → `v4.6.1`; stale kontroly (ř. 113 a 141–145) doplnit o `v4.6.0`.

`modules/core/mail/docs/ai-prompts.md`: nadpis a zmínky verze →
`v4.6.1`, příklad bumpu (~ř. 182), changelog `### v4.6.1` — důvod
(diagnostika výše, bez identifikace dodavatele), odkaz na tento task
a na `vat_place_derived`.

Uživatelská dokumentace (stejný commit):

- `help/posta/kontrola-vytezeni.md` — odstavec **Reverse charge**
  (~ř. 172): místo plnění (EU / zahraničí) určí Shipard podle DIČ
  dodavatele, ne podle adresy — firma se sídlem mimo EU, která fakturuje
  pod DIČ z EU, je plnění z EU (u služeb *Základní - služby EU*). Když se
  místo z návrhu změnilo, ukáže to upozornění v náhledu.
- Názvy ověřit ve zdroji popisků (`docs.core.vatPlaces` `name:cs`,
  `vat-cz.jsonc` `name:cs`). Pak `python3 scripts/help-index.py`.

`**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření`)
+ `python3 scripts/tasks-index.py`.

Ověření: `vendor/bin/phpunit --filter 'ProfileSchemaDriftTest|AIAnalyzerProvisionerTest|AiProfileReloadCommandTest|DsUpgradeCommandTest'`,
na konci celá sada lokálně.

### Ověření na dev zdroji

Zdroj s režimem *volný*, zpráva je v chatu (mimo repo).

1. `vendor/bin/shpd-ds ds-upgrade` → profil `czech_general` má
   `prompt_version = v4.6.1`.
2. Bez nové analýzy (stará v4.6.0 s `thirdCountry`): náhled návrhu ukáže
   `cz-217` a warning `vat_place_derived` — ověřuje D1 nezávisle na
   promptu.
3. „Znova analyzovat“ → nová analýza vrátí `vat.place = intracom`,
   náhled bez warningu (ověřuje D3).
4. „Vystavit koncept“ → `vat_place` 1, rekapitulace `cz-217` + pár
   `cz-207`; živé výstupy DPH ř. 5 a ř. 43, ne ř. 12.
5. Regrese: doklady z předchozího tasku (EU dodavatelé s DIČ své země)
   dál `cz-217`, bez `vat_place_derived`.

## Poznámky z implementace (2026-09-30)

- `core.exchange` dostal explicitní závislost `world.trade` — cfgItem byl
  na DS dostupný jen tranzitivně (docs.core → economy.codebooks);
  `TradeUnionResolver` při chybějící cfgItem vrací prázdno, ne výjimku.
- Doklad bez DUZP i data vystavení → bez derivace (členství v unii se
  vyhodnocuje k datu), stejně jako derivace kódu.
- `XI` se smíšenými řádky: kterýkoli řádek mimo `supplyKinds` →
  `thirdCountry` má přednost před řádkem bez druhu plnění (ten by dal
  null); smíšené doklady jsou stejně mimo rozsah derivace kódu.
- Náhled návrhu zobrazuje **Místo plnění** z canonicalu (jako režim DPH
  u `vat_mode_derived`) — odvozené místo uživatel vidí jen ve warningu
  a na vystaveném dokladu. Frontend beze změny.
- Řádek tabulky „neznámá hodnota (`eu`)“ je dosažitelný jen mimo schema
  validaci (enum) — test přes reflexi `appendVatHeaderIssues`.

## Mimo rozsah

- Ověření DIČ ve VIES (platnost registrace) — derivace věří prefixu.
- Režim OSS mimo Unii (`EU…`) — u B2B faktur se nemá objevit; zůstává
  hodnota z AI.
- Stálá provozovna dodavatele v ČR s českým DIČ vedle zahraničního — AI
  vrací jedno DIČ, derivace bere to, které přišlo.
- Vystavené doklady, `DocumentExporter` (místo se exportuje z hlavičky).

## Pasti

- **Tři místa čtení `vat.place`** — kód řádků, hlavička, issues. Přehlédnuté
  jedno = doklad s `vat_place` 0 a kódem `cz-217` (nebo obráceně) a kód
  neprojde kontrolou souladu místa.
- **Klíč cache `vatContext()`** dnes neobsahuje strany dokladu — bez
  doplnění DIČ vrátí cache starý kontext.
- **Místo před kódem:** `VatCodeDerivation` a kontrola souladu musí dostat
  efektivní místo; jinak derivace dá `cz-217`, ale kontrola ho vyhodnotí
  jako rozpor s `thirdCountry` a vyrobí `vat_code_derived` zpět na `cz-417`.
- **`EL` není ISO kód** — Řecko je v DIČ `EL`, v zemích `gr`; mapuje to
  `taxPrefixes`, ne kód.
- **`XI` a `supplyKinds`** — nepsat výjimku pro Severní Irsko do PHP.
- **Prohozené strany** — model občas dá naše DIČ k dodavateli; bez pojistky
  (krok 2) by vyšlo `domestic` s tuzemskými kódy u zahraničního dokladu.
- **Země dodavatele se nepřepisuje** — `supplier.country` (adresa) zůstává,
  jak přišla; derivace mění jen místo plnění.
- **Prompt:** `prompt_template` je jeden JSON řetězec, verze promptu na
  více místech, sync profilu jen při vyšší verzi — viz Pasti
  v `exchange-contact-name.md`. Editace přes Python
  (`io.open(..., encoding='utf-8')`, `assert s.count(old) == 1`).
- **Fixture a docs:** jen fiktivní dodavatelé a DIČ tvaru `IE1234567X`,
  žádná data z diagnostiky.

## Hotovo když

- [x] `tradeUnions.jsonc` má `supplyKinds` na `XI`; README `world.trade`
      popisuje pole i `TradeUnionResolver`.
- [x] `TradeUnionResolver` a `VatPlaceDerivation` s testy nad skutečnými
      daty.
- [x] Applier: efektivní místo na všech třech místech, `vat_place_derived`
      podle tabulky; testy nové i regresní zelené.
- [x] `docs/exchange-format.md`: sémantika `vat.place`, podsekce derivace
      místa, issue v tabulce.
- [x] Prompt v4.6.1, testy verzí, changelog.
- [x] Help `kontrola-vytezeni.md` aktualizovaný, `help-index.py` prošel.
- [ ] Ověření na dev zdroji: doklad dodavatele mimo EU s DIČ z EU má
      `cz-217` a ř. 5.
