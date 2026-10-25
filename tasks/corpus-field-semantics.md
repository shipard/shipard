# Korpus: platební reference, období z řádků, jednoznačné výjimky štítků

**Stav:** naplánováno — zadání 2026-10-25 (#103 D110, D112, D113)

## Kontext / Cíl

Po opravě věrnosti tisku a promptu v4.9.0 (#103, evaluace 2026-10-25)
zbyly odchylky, které nejsou chybou modelu, ale nejasnou definicí pole
v očekávání. Tento task opravuje stranu sady; pravidla promptu řeší
`tasks/prompt-field-rules.md` (D109–D111). Po obou tascích jedno kolo
evaluací.

| Odchylka | Doklady | Oprava tady |
|----------|---------|-------------|
| `payment.paymentReference` — doklad žádá „Please quote the invoice number with your payment“, očekávání má `null` | 0501, 0502 | D110: očekávání = číslo dokladu |
| `dates.periodFrom` / `periodTo` — období je jen v textu jediného řádku („12 seats, 09/01/2026 – 09/30/2026“), očekávání má `null` | 0801, 0802 | D112: očekávání = období řádků |
| výjimka štítku řádku „Likvidace původních výplní“ (údržba × odpad) | 0402 | D113: jednoznačný text |
| výjimka štítku řádku „Cestovné lektora“ (školení × jízdné) | 0702 | D113: jednoznačný text |

## Návaznost

- **#103** D110, D112, D113 (komentář 2026-10-25), D14 (papírová
  projekce), D104 (vzor pro jednoznačné položky), D105 (věrnost tisku)
- `tasks/prompt-field-rules.md` — druhá polovina D110 (pravidlo promptu)

## Před implementací přečti

- `docs/test-corpus.md` § 4 (data variant), § 8 (projekce), § 12 (build), § 15
- `tests/corpus/cz/data/0501-eu-services.jsonc`, `0502-…`,
  `0801-third-country-services.jsonc`, `0802-…`,
  `0402-domestic-reverse-charge.jsonc`, `0702-domestic-non-payer-supplier.jsonc`
- `tools/invoice-generator/invoicegen/expected.py`, `corpusprint.py`,
  `tests/corpus/cz/templates/en.html` (tisk `paymentReference` a období)

## Rozhodnutí

- **D110 (strana sady) — Platební reference je to, co doklad jako
  referenci uvádí.** Když doklad výslovně žádá uvést číslo faktury
  s platbou, `payment.paymentReference` = číslo dokladu. **PDF 0501 a 0502
  se nemění** — pokyn zůstává jen větou v poznámce, žádný nový řádek
  „Reference“ (jinak by zmizel právě testovaný případ).
- **D112 — Společné období řádků je období dokladu.** Když doklad nemá
  období v hlavičce a všechny jeho řádky nesou stejné období, je to
  `dates.periodFrom` / `periodTo` dokladu. **PDF 0801 a 0802 se nemění** —
  období zůstává jen v textu řádku.
- **D113 — Nejednoznačné řádky přeformulovat** (vzor D104), částky,
  sazby a kódy přenesení beze změny:
  - 0402 „Likvidace původních výplní“ (bez výjimky, `premises.maintenance`)
    → text jednoznačně stavební práce, např. „Demontáž původních výplní“;
  - 0702 „Cestovné lektora“ (výjimka `travel.fares`) → text jednoznačně
    jízdné, např. „Jízdenky lektora vlakem, zpáteční“.

## 1. Mechanismus v datech

- Očekávání se dál **odvozuje z dat** (`expected.py`), nikdy ruční úpravou
  `expected/`. Pro D110 a D112 potřebují data hodnotu, kterou šablona
  netiskne jako samostatný údaj. Zvolit jeden srozumitelný mechanismus
  (např. `paymentReference` + příznak, že se netiskne; `dates.periodFrom`
  s příznakem „jen v řádcích“), zdokumentovat v § 4 a pokrýt unit testem.
- Kontrola v buildu: hodnota s příznakem „netiskne se samostatně“ se musí
  v textu PDF přesto najít v nějaké podobě — reference = číslo dokladu
  (je na PDF), období = text řádku (ověřit, že řádek obsahuje obě data
  v tištěném tvaru). Jinak chyba buildu — stejný duch jako D105.

## 2. Texty řádků (D113)

- Přepsat texty v 0402 a 0702 (včetně vnořené podoby řádku, je-li v datech
  — 0402 má řádek dvakrát). Do závěrečné zprávy staré → nové texty.

## 3. Definice polí v dokumentaci

- `docs/test-corpus.md` § 8: krátký oddíl „Význam sporných polí“ —
  `notes.onDocument` (volný text poznámky; zákonné doložky DPH sem
  nepatří, D109), `payment.paymentReference` (D110), `dates.taxPointDate`
  (jen uvedené na dokladu, D111), `dates.periodFrom` / `periodTo` (D112).
  Stejné znění použije prompt (`tasks/prompt-field-rules.md`) — jeden
  zdroj významu, prompt na něj odkazuje v changelogu.
- Ověřit, že očekávání u ostatních dokladů definicím odpovídá (žádná
  zákonná doložka v `notes.onDocument`, žádné DUZP, které na dokladu není).
  Nesoulad sepsat do oddílu Nálezy a neopravovat bez schválení v chatu.

## 4. Přegenerování

- Build korpusu a sad (§ 12), hash sad se změní.
- `shpd-ds dataset-verify` nad třemi sadami na zdrojích dat s účelem
  „korpus“ (ne evaluačních): kontroly 1–6 `ok`; snímek hlavní knihy se
  nesmí změnit (změny jsou jen v textech a v polích, která se neúčtují) —
  pokud se změní, zastavit a sepsat.

## Kroky

1. Mechanismus v datech + kontrola v buildu + unit testy → commit
   `feat(corpus): hodnoty očekávání netištěné samostatně — reference a období z řádků (#103 D110, D112)`
2. Data 0501, 0502, 0801, 0802, 0402, 0702, přegenerovaný korpus a sady → commit
   `fix(corpus): platební reference, období z řádků, jednoznačné texty řádků (#103 D110, D112, D113)`
3. Dokumentace (§ 4, § 8, § 15), `**Stav:**`, `python3 scripts/tasks-index.py`
   → commit `docs(corpus): význam sporných polí projekce (#103 D109–D113)`

## Hotovo když

- [ ] Očekávání 0501 / 0502: `paymentReference` = číslo dokladu; 0801 /
      0802: období dokladu = období řádku; PDF těchto čtyř dokladů
      beze změny (porovnat text `pdftotext` před a po).
- [ ] 0402 a 0702 s jednoznačnými texty; částky a součty beze změny.
- [ ] Build hlídá, že netištěná hodnota je na PDF v jiné podobě (unit test).
- [ ] § 8 má význam sporných polí; kontrola ostatních dokladů proti
      definicím v závěrečné zprávě.
- [ ] `vendor/bin/phpunit --filter 'CorpusExpectedTest|ProjectionTest|IsdocCorpusRoundTripTest|CanonicalComparatorTest'`,
      testy generátoru.
- [ ] `dataset-verify` nad třemi sadami (zdroje „korpus“): kontroly 1–6
      `ok`, snímek hlavní knihy beze změny.
- [ ] `**Stav:**`, index tasků.

## Pasti

- **Evaluační zdroje dat nepoužívat.**
- **ISDOC u 0402 a 0702** nese texty řádků — přegeneruje se s buildem;
  round-trip test musí projít.
- **Uložené výsledky evaluací nepřepisovat** — běhy nad starší sadou
  zůstávají, jak jsou.

## Nálezy

_(doplní implementace)_
