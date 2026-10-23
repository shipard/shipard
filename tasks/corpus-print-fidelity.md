# Korpus: co je v očekávání, je na PDF — kódy položek, spisová značka, dlouhý doklad

**Stav:** hotovo — 2026-10-23 (#103 D102–D105); zbývá přeseedování evaluačních zdrojů dat z dashboardu, úprava promptu a nové evaluace

## Kontext / Cíl

Evaluace všech tří sad z 2026-10-23 (`tests/eval/*/2026-10-23-baseline.json`)
ukázaly systematické odchylky, které nejsou chybou modelu, ale sady:

- **E1** — `rows[].item.supplierCode` a `rows[].item.ean` jsou v očekávání,
  ale PDF korpusu je netiskne (šablony nemají sloupec kódu). Na cestě
  přes AI 54–59 chybějících polí na běh, u plátce zhruba dvě třetiny všech
  chybějících. Porušuje to papírovou projekci (D14, `docs/test-corpus.md`
  § 8: `null` = na dokladu není, tedy ne-`null` = na dokladu je).
- **E2** — `supplier.courtRegistration` v identitách nese popisek
  („Spisová značka C 990321 vedená u …“); model vrací hodnotu bez popisku
  podle vzoru v promptu. Popisek patří do tisku, ne do hodnoty.
- **E3** — v dlouhém dokladu (1001, 1002) jsou „Kávové filtry“ a
  „Kelímky papírové“ v bloku úklidu; model filtry ve všech sadách a po
  obou cestách označí jako občerstvení. Položka je nejednoznačná, ne model.

**Cíl:** opravit E1–E3 v korpusu a zavést kontrolu v buildu, která chybu
typu E1 a E2 najde sama (D105). Po přegenerování se změní hash sad —
následuje úprava promptu a nové evaluace (#103, pořadí z komentáře
2026-10-23).

E4 (doprava u EU dodání zboží jako služba) se **neřeší** — čeká na
vyjádření účetních v #123.

## Návaznost

- **#103** D102–D105 (komentář 2026-10-23), D14 (papírová projekce),
  D90 (pořadí #132 → evaluace)
- **#123** — E4, účetní revize sad
- `docs/test-corpus.md` § 3, § 4, § 8, § 12, § 13, § 15

## Před implementací přečti

- `docs/test-corpus.md` § 3, § 4, § 8, § 11, § 12, § 15
- `tests/corpus/cz/templates/*.html` (`cs-a`, `cs-b`, `cs-c`, `en`,
  `receipt`), `tools/invoice-generator/invoicegen/corpusprint.py`
  (`template_values`, řádky, `supplierCourtReg`), `corpus.py` (řádek 242),
  `expected.py`
- `tests/corpus/cz/identities.jsonc` (`courtRegistration`),
  `tests/corpus/cz/data/1001-long-document.jsonc`, `1002-long-document.jsonc`
- `tools/invoice-generator/invoicegen/checks.py`

## Rozhodnutí

- **D102 — Kódy položek se tisknou (E1, varianta a).** Šablony faktur
  (`cs-a`, `cs-b`, `cs-c`, `en`) mají sloupec kódu položky (`Kód` /
  `Code`), když má kód aspoň jeden řádek dokladu; EAN se tiskne u řádku,
  který ho má (např. drobně pod názvem: `EAN 4260999100013`). Účtenka
  (`receipt`) kódy netiskne — data účtenek kódy mít nesmí (kontrola
  D105 to pohlídá).
- **D103 — Spisová značka bez popisku (E2).** Hodnota `courtRegistration`
  v identitách je jen značka a soud („C 990321 vedená u Krajského soudu
  v Ostravě“); popisek přidá šablona (`cs-*`: „Spisová značka:“ nebo
  souvětí o zápisu v obchodním rejstříku). Německé identity („Amtsgericht
  …, HRB …“) beze změny.
- **D104 — Dlouhý doklad bez nejednoznačných položek (E3).** Kávové
  filtry a papírové kelímky patří do bloku občerstvení (výjimky řádků
  `people.catering`). Projít oba bloky dlouhého dokladu a další
  nejednoznačné položky přesunout nebo nahradit jednoznačnými; hlavní
  štítek dokladu zůstává `office.cleaning` s jasnou většinou řádků.
- **D105 — Build hlídá, že identifikátory z očekávání jsou na PDF.**
  Pro každý doklad: hodnoty `docNumber`, IČO, DIČ / VAT ID, číslo účtu,
  IBAN, VS, `courtRegistration`, kontaktní e-mail, telefon, web,
  `rows[].item.supplierCode` a `rows[].item.ean` (ne-`null` v očekávání)
  se musí najít v textu vygenerovaného PDF (`pdftotext`, porovnání bez
  bílých znaků). Nenalezená hodnota = chyba buildu s cestou pole
  a souborem. Ne-`null` hodnota, kterou PDF nenese, je chyba sady (D14).

## 1. Kódy a EAN na PDF (D102)

- Sloupec kódu ve čtyřech šablonách faktur, jen když ho doklad potřebuje
  (doklad bez kódů vypadá jako dnes). Šířky sloupců upravit tak, aby se
  dlouhý doklad (44 řádků) vešel jako dnes a nic se nezalamovalo hůř.
- EAN u řádku, který ho má.
- Vizuální kontrola: vyrenderovat aspoň `0101`, `0601`, `1001` a jednu
  účtenku a projít PNG první strany (`pdftoppm`) — žádné přetečení
  a překryv.

## 2. Spisová značka (D103)

- `identities.jsonc`: hodnoty bez „Spisová značka “; kontrola v
  `validate_identities`, že hodnota popiskem nezačíná.
- Šablony `cs-*`: popisek před hodnotou.

## 3. Dlouhý doklad (D104)

- `1001-long-document.jsonc`, `1002-long-document.jsonc`: filtry
  a kelímky do bloku občerstvení; projít oba bloky a nejednoznačné
  položky vyřešit. Do závěrečné zprávy seznam přesunutých / nahrazených
  položek.

## 4. Kontrola v buildu (D105)

- Nová kontrola v `checks.py` (nebo modulu, který build volá po renderu
  PDF), unit test na nalezenou i nenalezenou hodnotu.
- Kontrolu pustit **před** úpravami 1–3 nad současným korpusem a výsledek
  (počet nálezů podle pole) dát do závěrečné zprávy — ověří, že by E1
  a E2 našla. Pokud najde i něco jiného, sepsat do oddílu Nálezy
  a neopravovat bez schválení v chatu.

## 5. Přegenerování

- Build korpusu a sad (§ 12). Hash sad se změní — evaluační zdroje dat
  bude potřeba přeseedovat (dělá člověk z dashboardu).
- `shpd-ds dataset-verify` nad všemi třemi sadami na zdrojích dat
  s účelem „korpus“ (ne evaluačních): kontroly 1–6 `ok`.

## Kroky

1. Kontrola D105 + unit test, běh nad současným korpusem (očekávané
   nálezy E1, E2) → commit
   `feat(corpus): build hlídá, že identifikátory z očekávání jsou na PDF (#103 D105)`
   — build v tomto commitu kontrolu jen hlásí, neselže (přepínač
   `--fidelity=warn` nebo ekvivalent); krok 4 ji přepne na chybu.
2. Kódy a EAN v šablonách (D102) → commit
   `feat(corpus): kód položky a EAN na PDF dokladů korpusu (#103 D102)`
3. Spisová značka bez popisku (D103) + dlouhý doklad (D104) → commit
   `fix(corpus): spisová značka bez popisku, jednoznačné položky dlouhého dokladu (#103 D103, D104)`
4. Kontrola D105 jako chyba buildu, přegenerovaný korpus a sady →
   commit `fix(corpus): přegenerovaný korpus a sady, kontrola věrnosti tisku jako chyba (#103 D102–D105)`
5. Dokumentace (§ 3 identity, § 8 projekce — odkaz na D105, § 12 build,
   § 15 pasti), `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs(corpus): věrnost tisku korpusu (#103 D102–D105)`

## Hotovo když

- [x] Build selže, když je ne-`null` identifikátor z očekávání mimo text
      PDF (unit test); nad finálním korpusem projde bez nálezů.
- [x] Výsledek kontroly nad korpusem před opravou je v závěrečné zprávě
      (počty podle pole).
- [x] PDF faktur s kódy mají sloupec kódu, EAN u řádku; vizuální
      kontrola čtyř dokladů bez přetečení.
- [x] `courtRegistration` v identitách bez popisku, šablony `cs-*` popisek
      tisknou.
- [x] Dlouhý doklad: filtry a kelímky v bloku občerstvení, seznam změn
      v závěrečné zprávě.
- [x] `vendor/bin/phpunit --filter 'CorpusExpectedTest|ProjectionTest|IsdocCorpusRoundTripTest'`,
      testy generátoru.
- [x] `dataset-verify` nad třemi sadami (zdroje dat „korpus“): kontroly
      1–6 `ok`.
- [x] Dokumentace, `**Stav:**`, index tasků.

## Pasti

- **Dataset-dump musí dál sedět bajtově** se sadou (F2).
- **ISDOC kódy už nese** (`SellersItemIdentification`) — beze změny,
  jen ověřit, že round-trip test dál projde.
- **Evaluační zdroje dat nepoužívat** pro ověření — přeseedování by
  rozbilo případnou probíhající evaluaci.
- **`pdftotext` láme dlouhé hodnoty** (IBAN, web) přes řádky nebo vkládá
  mezery do skupin — proto porovnání bez bílých znaků; pokud i tak
  hodnota nesedí kvůli dělení slov, sepsat a řešit v šabloně, ne
  výjimkou v kontrole.

## Nálezy

Implementace 2026-10-23 (pět commitů na `stable`).

- **Kontrola D105 nad korpusem před opravou** (52 buněk, `pdftotext` bez
  `-layout`, porovnání bez bílých znaků): 342 hodnot mimo text PDF —
  `rows[].item.supplierCode` 332, `rows[].item.ean` 6,
  `rows[].item.description` 4; **nic jiného**. Číslo dokladu, IČO, DIČ,
  účet, IBAN, BIC, VS / KS, e-mail, telefon, web i spisová značka na PDF
  seděly (IBAN po čtveřicích a telefon s mezerami řeší odstranění bílých
  znaků, ligatury ani dělení slov nevadily). Nad finálním korpusem 0 nálezů.
- **D105 E2 najít nemůže:** „Spisová značka C …“ byla v identitách i na PDF
  doslova, substring seděl. E2 hlídá nové pravidlo `validate_identities`
  (hodnota nesmí popiskem začínat). Pole tvaru „popisek + hodnota“ chce
  vlastní pravidlo, ne D105 (zapsáno v `docs/test-corpus.md` § 15).
- **`rows[].item.description` je čtvrté pole stejného druhu jako E1**
  (0501 / 0502 „Billing period …“, 0801 / 0802 „12 seats, …“ — netisklo
  se; baseline plátce 2× missing, 2× wrong). Schváleno v chatu: zahrnuto
  do kontroly D105 a tiskne se pod názvem položky jako EAN.
- **Dlouhý doklad (D104):** přesun do bloku občerstvení s `people.catering`
  (množství, cena, 21 % beze změny) — „Kávové filtry č. 4, 100 ks“ (KF-4)
  a „Kelímky papírové 200 ml, 100 ks“ (KP-200) v 1001 i 1002. Nahrazení
  úklidovými položkami (jen text a kód, množství / cena / sleva zůstaly,
  součty dokladů se nezměnily; schváleno v chatu): „Míchátka dřevěná,
  1000 ks“ MD-1000 → „Hadry na podlahu, 10 ks“ HP-10 (1001 i 1002);
  „Ubrousky papírové 33×33, 100 ks“ UB-33 → „Drátěnky kovové, 10 ks“ DK-10
  a „Odvápňovač kávovaru 1 l“ OD-1 → „Leštěnka na nábytek 400 ml“ LN-400
  (1002). Poměr úklid : občerstvení 26 : 18 (1001), 28 : 20 (1002); výjimky
  řádků 26–43 (1001), 28–47 (1002).
- **Snímek hlavní knihy** (`dataset-verify` kontrola 6) se přesunem filtrů
  a kelímků změnil přesně o jejich hodnotu: 501100 Spotřeba materiálu
  −787,10 Kč a 513900 Náklady na reprezentaci +787,10 Kč u plátce; u neplátce
  a spolku −952,39 / +952,39 Kč (vč. DPH; spolek 513100). Nové snímky
  zapsány z `--write-ledger-snapshot` po přeseedování zdrojů „korpus“
  (`ob9b`, `r1lf`, `wp66`); kontroly 1–6 `ok`.
- **Test rekonstrukce evaluace** (`EvalOutputReconstructionTest`) porovnával
  běh 2026-10-16 s *živým* očekáváním sady — po D104 má 1002 o dvě výjimky
  řádků víc a počty neseděly. Očekávání k běhu je teď zmrazené ve fixtuře
  `tests/Fixtures/Eval/cz-vat-payer-2026-10-16/` (štíhlé zprávy bez
  `analysisJson`); dva testy `CanonicalComparatorTest` nad dlouhým dokladem
  přepsány na nové indexy výjimek.
- **Nález modelu, ne sady:** `supplier.contact.web` v baseline chybí 11 / 7 /
  7× — web na PDF je (kontrola D105 to potvrzuje). Patří k úpravě promptu.
- Přegenerováno 46 PDF (6 účtenek beze změny), 18 ISDOC (spisová značka
  v `RegisterIdentification`), tři sady; hash sad se změnil — evaluační
  zdroje dat přeseeduje člověk z dashboardu, běhy z 23. 10. nejsou „nad
  aktuální sadou“.
