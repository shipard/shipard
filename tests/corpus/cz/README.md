# Korpus testovacích dokladů — vlastní firma v ČR

Generované fiktivní přijaté doklady s očekávaným výsledkem v kanonickém
formátu `shpd.docs.document.v1` (#103 F1). Popis, pravidla a postup „jak
přidat doklad“: [`docs/test-corpus.md`](../../../docs/test-corpus.md).

```bash
python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities] [--fetch-rates] [--no-datasets]
```

- `identities.jsonc` — profily vlastní firmy a dodavatelé (fiktivní, ověřené v ARES a VIES),
- `data/NNNN-<slug>.jsonc` — datový soubor dokladu, **jediný ručně psaný vstup**,
- `exchange-rates/<validFrom>-cz.cnb.daily.json` — skutečné denní lístky ČNB pro účetní
  data dokladů v cizí měně a rezervy z předchozího pracovního dne (`tasks/testing-rates-isdoc.md`
  D72); plní je jen `build.py --fetch-rates` (síť), build je jinak jen čte a z nich počítá
  očekávání v Kč a sekci `exchangeRates/` sad,
- `templates/*.html` — šablony korpusu (vlastní kopie, demo se nemění),
- `profiles/<profil>/` — základ profilu pro datovou sadu (`profile.jsonc`,
  `setup/*.jsonc`: nastavení, registrace DPH, vlastní účet, pokladna), ručně psaný
  (`tasks/testing-phase2.md`),
- `vat-reporting.jsonc` — mapování variant na řádky přiznání DPH a oddíly
  kontrolního hlášení (`tasks/testing-phase3.md` D28, schválené) — z něj build
  počítá `expected/vat.json` a `expected/costs.json` sad (v Kč, doklad v cizí měně
  kurzem k účetnímu datu; kurz per doklad v `expected/exchange-rates.json`),
- `profiles/<profil>/approved/general-ledger.json` — schválený snímek hlavní
  knihy pro `dataset-verify` (vzniká z `--write-ledger-snapshot`, schvaluje člověk),
- `out/<profil>/*.pdf`, `expected/<profil>/*.json`, `build-state.json` — výstupy buildu,
  commitované. **Očekávaný výsledek se nikdy needituje ručně** — změna = úprava
  datového souboru nebo generátoru (`tools/invoice-generator/`) a nový build.

Po korpusu build sestaví datové sady
[`tests/datasets/cz/<profil>/`](../../datasets/cz/) (zpráva s PDF a ideální
analýzou per buňka, u podoby `pdfIsdoc` s ISDOC jako druhou přílohou;
kurzovní lístky pro doklady v cizí měně; `--no-datasets` přeskočí) —
generované, commitované, needitují se.

Strážce: `vendor/bin/phpunit --filter CorpusExpectedTest` (korpus i sady).
