# Datové sady z korpusu testovacích dokladů

Generované sady `shpd.dataset.v1` (`docs/datasets.md`) — jedna na profil
vlastní firmy z korpusu [`tests/corpus/cz/`](../corpus/cz/) (#103 F2,
`tasks/testing-phase2.md`, `docs/test-corpus.md` § Datové sady):

| Sada | Vlastní firma | Osnova | DPH |
|------|---------------|--------|-----|
| `cz/vat-payer/` | s.r.o. | podnikatelská (`default`) | měsíční plátce od 1. 1. 2026 |
| `cz/non-payer/` | s.r.o. | podnikatelská (`default`) | neplátce |
| `cz/association/` | spolek (z. s.) | nezisková (`npo`) | neplátce |

Každá buňka matice korpusu je v sadě **došlá zpráva s PDF a ideální
analýzou**: očekávaný výsledek jako snapshot analýzy, zpráva K řešení
s otevřeným návrhem — bez volání AI. Dodavatele sada nenese, založí je
Použít návrhu.

```bash
php bin/shpd-server ds-create --name "Korpus plátce" --language cs --country cz --no-cron
cd /opt/shipard/data-sources/<id> && shpd-ds dataset-seed /cesta/k/repu/tests/datasets/cz/vat-payer -y
```

**Needitovat ručně.** Sady staví `python3 tests/corpus/cz/build.py` ze
základu profilu (`tests/corpus/cz/profiles/<profil>/`), korpusu
a generátoru (`tools/invoice-generator/invoicegen/dataset.py`); strážce
`vendor/bin/phpunit --filter CorpusExpectedTest` hlídá soulad s korpusem.
