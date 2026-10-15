# Korpus testovacích dokladů — vlastní firma v ČR

Generované fiktivní přijaté doklady s očekávaným výsledkem v kanonickém
formátu `shpd.docs.document.v1` (#103 F1). Popis, pravidla a postup „jak
přidat doklad“: [`docs/test-corpus.md`](../../../docs/test-corpus.md).

```bash
python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities] [--no-datasets]
```

- `identities.jsonc` — profily vlastní firmy a dodavatelé (fiktivní, ověřené v ARES a VIES),
- `data/NNNN-<slug>.jsonc` — datový soubor dokladu, **jediný ručně psaný vstup**,
- `templates/*.html` — šablony korpusu (vlastní kopie, demo se nemění),
- `profiles/<profil>/` — základ profilu pro datovou sadu (`profile.jsonc`,
  `setup/*.jsonc`: nastavení, registrace DPH, vlastní účet, pokladna), ručně psaný
  (`tasks/testing-phase2.md`),
- `vat-reporting.jsonc` — mapování variant na řádky přiznání DPH a oddíly
  kontrolního hlášení (`tasks/testing-phase3.md` D28, schválené) — z něj build
  počítá `expected/vat.json` a `expected/costs.json` sad,
- `profiles/<profil>/approved/general-ledger.json` — schválený snímek hlavní
  knihy pro `dataset-verify` (vzniká z `--write-ledger-snapshot`, schvaluje člověk),
- `out/<profil>/*.pdf`, `expected/<profil>/*.json`, `build-state.json` — výstupy buildu,
  commitované. **Očekávaný výsledek se nikdy needituje ručně** — změna = úprava
  datového souboru nebo generátoru (`tools/invoice-generator/`) a nový build.

Po korpusu build sestaví datové sady
[`tests/datasets/cz/<profil>/`](../../datasets/cz/) (zpráva s PDF a ideální
analýzou per buňka; `--no-datasets` přeskočí) — generované, commitované,
needitují se.

Strážce: `vendor/bin/phpunit --filter CorpusExpectedTest` (korpus i sady).
