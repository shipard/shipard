# Korpus testovacích dokladů — vlastní firma v ČR

Generované fiktivní přijaté doklady s očekávaným výsledkem v kanonickém
formátu `shpd.docs.document.v1` (#103 F1). Popis, pravidla a postup „jak
přidat doklad“: [`docs/test-corpus.md`](../../../docs/test-corpus.md).

```bash
python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities]
```

- `identities.jsonc` — profily vlastní firmy a dodavatelé (fiktivní, ověřené v ARES a VIES),
- `data/NNNN-<slug>.jsonc` — datový soubor dokladu, **jediný ručně psaný vstup**,
- `templates/*.html` — šablony korpusu (vlastní kopie, demo se nemění),
- `out/<profil>/*.pdf`, `expected/<profil>/*.json`, `build-state.json` — výstupy buildu,
  commitované. **Očekávaný výsledek se nikdy needituje ručně** — změna = úprava
  datového souboru nebo generátoru (`tools/invoice-generator/`) a nový build.

Strážce: `vendor/bin/phpunit --filter CorpusExpectedTest`.
