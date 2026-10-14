# Datové sady pro demo scénáře

Zatím prázdné — sady sem přijdou ve formátu z
[`docs/datasets.md`](../../docs/datasets.md) (CLI `dataset-dump` /
`dataset-seed`, [`tasks/dataset-phase1.md`](../../tasks/dataset-phase1.md)).
Testovací sady z korpusu dokladů (bez vazby na video) leží
v [`tests/datasets/`](../../tests/datasets/).

Do té doby běží scénáře v [`../scenarios/`](../scenarios/) nad tím, co na
cílové instanci zrovna je. Pole `dataset` ve scénáři je proto prozatím
jen dokumentační vazba — runner na něj nesahá.

## Až sady vzniknou

Obsah se odvodí ze scénářů S1/S2/S5 (`shpd-web/docs/demo-scenare.md`):
předanalyzovaná došlá pošta s PDF fakturami, pár osob, rozpracovaná
fronta na dashboardu, dokumenty ve spisovně.

Sada vzniká **od začátku z fiktivních dat** — vymyšlení klienti, faktury,
přílohy. Nikdy dump ani anonymizace ostrého datového zdroje: video je
veřejný artefakt a tenhle adresář taky.
