---
title: Když AI přečte fakturu špatně
summary: Kde se která chyba opravuje, kdy návrh spíš zamítnout a co z chyby nahlásit.
keywords: [špatně přečteno, chyba AI, oprava, nesedí částka, špatný dodavatel, špatná sazba, zamítnout, znovu analyzovat, nahlásit chybu]
related: [posta/kontrola-vytezeni.md, posta/prijem-posty.md, co-dnes-nejde.md]
---

# Když AI přečte fakturu špatně

Stane se to a je to normální stav alfy. Otázka je, kde se která chyba
opravuje — a jak zařídit, aby příště byla menší.

## Kdy to potřebuješ

Při kontrole návrhu jsi našel rozdíl proti faktuře: nesedí částka, sazba,
datum, dodavatel nebo položky.

## Postup

1. **Rozhodni podle druhu chyby.** Každá se opravuje jinde:

   | Co je špatně | Kde to opravíš |
   |---|---|
   | Dodavatel, položka, jednotka, **kód DPH** řádků, **Místo plnění**, **Režim DPH** | Přímo v náhledu, ještě před vystavením |
   | Částky, datumy, texty, počty řádků | Až v dokladu, po **Vystavit koncept** |
   | Typ dokladu, nebo to faktura vůbec není | **Zamítnout** |
   | Nečitelná příloha | **Znovu analyzovat** |

2. **Reference oprav hned v náhledu.** U dodavatele, položky nebo účtu
   klikni na **Vybrat existujícího…** a najdi správný záznam, nebo zvol
   **Vytvořit nového**. Kód DPH řádku změníš kliknutím na odznak u sazby
   ve sloupci **DPH** — nabídka zná jen kódy, které k místu plnění a datu
   dávají smysl; u více řádků naráz použij tlačítko v hlavičce sloupce.
   **Místo plnění** a **Režim DPH** přepneš v hlavičce náhledu (volba
   *Automaticky* vrátí, co určil Shipard). **DPH rekapitulace** a **Součty**
   se po každé volbě hned přepočítají. Vyplatí se to, protože podle
   dodavatele se dohledává i zbytek.

3. **Zbytek oprav v dokladu.** Dej **Vystavit koncept** — ne **Vystavit
   a uzavřít**, uzavřený doklad se needituje. Doklad vznikne jako
   **Koncept** a **hned se ti otevře** v editačním formuláři, takže ho
   nemusíš nikde hledat. V Konceptu je editovatelné všechno — částky,
   datumy, řádky, sazby. Oprav a ulož.

   Oprava dvou polí je skoro vždycky rychlejší než zadání celé faktury
   ručně, takže i dost pomýlený návrh se vyplatí použít.

4. **Když je špatně skoro všechno, zamítni.** Typicky když AI určila jiný
   typ dokladu nebo přečetla něco úplně jiného. Dej **Zamítnout** s důvodem
   a fakturu zadej ručně — u přijaté faktury přes **Nákup**.

5. **U nečitelné přílohy zkus Znovu analyzovat.** Dialog nabídne i volbu
   profilu; nech výchozí, pokud ti nepíšeme jinak. Když druhý pokus skončí
   stejně, je podklad nečitelný i pro člověka — zadej doklad ručně.

6. **Nahlas, co AI přečetla špatně.** Tohle je pro nás nejcennější zpětná
   vazba z celé alfy. Nejužitečnější je napsat:

   - **co bylo špatně a co tam mělo být** (třeba „sazba u druhého řádku",
     „datum splatnost místo vystavení"),
   - **kolik procent Jistoty** návrh měl,
   - **jaká faktura to byla** — jazyk, počet položek, kolik sazeb DPH,
     jestli ceny byly s DPH nebo bez, jestli šlo o samovyměření,
   - **datum a přibližný čas** doručení zprávy.

   Jak a kam hlásit — a co do veřejného hlášení nepatří — je
   v [TESTERS.md](../../TESTERS.md).

## Na co narazíš

**Hodnoty se v náhledu opravit nedají, a je to úmyslně.** Náhled je záznam
toho, co AI z faktury přečetla — kdyby se přepisoval, ztratili bychom
možnost porovnat, co model vrátil, s tím, co bylo správně. Proto se opravuje
až doklad.

**Tyhle dvě chyby už známe, hlásit je nemusíš.** Faktury s cenami včetně
DPH (daň dvakrát) a zaokrouhlení celkové částky — podrobnosti
v [Co Shipard dnes neumí](../co-dnes-nejde.md). Pokud u nich narazíš na
něco *jiného*, než co je tam popsané, ozvi se. Kód DPH u samovyměření
(reverse charge) už návrh určuje sám; když skončí chybou kódu DPH, nebo
zvolí jiný kód, než na fakturu patří, vyber kód přímo v náhledu u řádku.
Co v nabídce není (zahraniční DPH, přenesení daňové povinnosti mimo
stavební práce a přílohu 5), je vyjmenované tamtéž a doklad založíš ručně.

**Čím víc dokladů od dodavatele máš, tím méně chyb.** Shipard doplňuje
položky, sazby a účty z tvých dřívějších dokladů od téhož partnera. První
faktura od nového dodavatele je nejhorší; pátá bývá skoro bez práce.

**Když u řádku přiřadíš správnou položku, Shipard si to může zapamatovat.**
Spojení mezi dodavatelovým kódem položky a tvou položkou se ukládá při
přechodu dokladu z **Konceptu** na **Potvrzeno** — příští faktura od téhož
dodavatele pak řádek přiřadí sama.

**Opakovanou chybu u jednoho dodavatele řeší ISDOC.** Když od něj dostáváš
faktury pravidelně a AI je čte špatně, popros ho o ISDOC — ten se nečte
strojově „od pohledu", ale přebírá se přesně. Viz
[Příjem pošty](prijem-posty.md).

**Zamítnutí není konečné.** Zamítnutý návrh zůstane u zprávy, ale
**Znovu analyzovat** ti vytvoří nový. Zpráva ani přílohy se nemažou.

**AI vytěží jen hlavní dokument zprávy.** Když e-mail nese víc dokumentů
(faktura + smlouva, dvě faktury), návrh vznikne jen z toho hlavního —
ostatní uvidíš na kartě jako poznámku a založíš je ručně. Není to chyba
čtení; viz [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Kontrola vytěženého dokladu](kontrola-vytezeni.md) — jak chybu najít
- [Příjem pošty](prijem-posty.md) — co posílat, aby bylo čtení přesnější
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
- [Pro testery](../../TESTERS.md) — pravidla hlášení
