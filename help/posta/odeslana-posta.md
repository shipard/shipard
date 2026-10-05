---
title: Odeslaná pošta
summary: Kde najdeš všechno, co z Shipardu odešlo e-mailem — komu, kdy, s jakými přílohami a jak odeslání dopadlo; jak zprávu odeslat znovu, archivovat nebo smazat.
keywords: [odeslaná pošta, odeslané zprávy, odeslané e-maily, co odešlo, komu jsem poslal fakturu, kdy odešla faktura, historie odeslání, odeslat znovu, poslat znovu, zpráva ve frontě, ve frontě, neodesláno, odesláno, stav odeslání, pokusy o odeslání, archivovat zprávu, smazat odeslanou zprávu, obnovit zprávu, přílohy odeslané zprávy, sekce odeslaná pošta u dokladu, zachyceno neodesláno, přesměrováno, pošta je zachycená, pošta je přesměrovaná, testovací server neposílá poštu, žlutý pruh pošta]
related: [faktury-vydane/odeslani-faktury.md, faktury-vydane/odesilatel-faktur.md, posta/prijem-posty.md, co-dnes-nejde.md]
---

# Odeslaná pošta

**Odeslaná pošta** je přehled zpráv, které z Shipardu odešly — dnes jsou to
doklady poslané odběratelům. U každé vidíš příjemce, text, přílohy a stav
odeslání.

## Kdy to potřebuješ

Chceš vědět, jestli a komu faktura odešla. Nebo ji potřebuješ poslat znovu
ve stejné podobě.

## Postup

**Zprávy jednoho dokladu:** otevři detail dokladu (třeba v **Prodej →
Faktury vydané**). Pod přehledem dokladu je sekce **Odeslaná pošta** —
u každé zprávy datum, příjemci, stav a náhledy příloh. Kliknutím na řádek
zprávy otevřeš její formulář.

**Všechny zprávy:** otevři **Odeslaná pošta** v menu, hned pod **Došlou
poštou**. V seznamu je předmět, osoba a adresy příjemce, doklad, ke kterému
zpráva patří, a stav odeslání. Hledat jde podle předmětu, adresy, dokladu
i osoby. Tlačítko **Otevřít záznam** v detailu zprávy tě dovede k dokladu.

**Odeslat znovu:** otevři zprávu a dej **Odeslat znovu**. Odejde stejná
zpráva — stejní příjemci, stejný text, stejné přílohy.

**Archivovat nebo smazat:** ve formuláři zprávy dej **Archivovat** nebo
**Smazat**. Zpráva zmizí ze sekce u dokladu; v přehledu ji najdeš na
záložce **Archiv** nebo **Koš** a tlačítkem **Obnovit** ji vrátíš.

## Stav odeslání

| Stav | Co znamená |
|---|---|
| **Odesláno** | Poštovní server zprávu převzal |
| **Ve frontě** | Zpráva čeká na odeslání; Shipard to zkouší opakovaně, několik hodin |
| **Neodesláno** | Odeslat se nepodařilo ani po opakování; důvod je u zprávy |

Ve formuláři zprávy je pod stavem rozbalovací seznam **Pokusy o odeslání**
s časem a odpovědí poštovního serveru.

## Na co narazíš

**Zprávu nejde upravit.** Je to záznam o tom, co odešlo — předmět, text,
příjemce ani přílohy změnit nejde. Když potřebuješ poslat něco jiného nebo
jinam, dej u dokladu znovu **Odeslat**; vznikne nová zpráva.

**Odeslat znovu je neaktivní.** Zpráva právě čeká ve frontě, nebo je
v archivu či koši — tu nejdřív **Obnov**.

**V přehledu není tlačítko Přidat.** Zprávy vznikají jen odesláním dokladu.
Napsat z Shipardu samostatný e-mail zatím nejde.

**„Odesláno“ neznamená „doručeno“.** Shipard ví, že zprávu převzal
poštovní server; jestli ji příjemce dostal a přečetl, nezjistí.

**Zprávu nejde odstranit nadobro.** **Smazat** ji přesune do **Koše**,
odkud jde obnovit.

**U zprávy je štítek „Zachyceno — neodesláno“ nebo „Přesměrováno na …“.**
Pracuješ na testovacím nebo vývojovém serveru: pošta tam nejde skutečným
příjemcům, aby se při zkoušení nic neposlalo zákazníkům. Zpráva má stav
**Odesláno**, ale buď neodešla vůbec, nebo odešla jen na adresu ze štítku
(štítek **Příjemci omezeni pojistkou** znamená, že odešla jen části
příjemců). Upozorňuje na to i žlutý pruh nad přehledem a v okně **Odeslat
e-mailem**. V ostrém provozu se štítek ani pruh neobjeví.

## Souvisí

- [Odeslání faktury e-mailem](../faktury-vydane/odeslani-faktury.md) — jak zpráva vznikne
- [Z jaké adresy faktury odcházejí](../faktury-vydane/odesilatel-faktur.md) — odesílatel
- [Příjem pošty](prijem-posty.md) — pošta, která přichází
