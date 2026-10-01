---
title: Odpisy majetku
summary: Jak na kartě nastavit daňové a účetní odpisy, zařadit majetek, zadat technické zhodnocení nebo snížení hodnoty, přerušit odpisy, vyřadit majetek a hromadně potvrdit odpisy za období.
keywords: [odpisy, odpisy majetku, daňové odpisy, účetní odpisy, odpisový plán, plán odpisů, odpisová skupina, rovnoměrné odpisy, zrychlené odpisy, mimořádné odpisy, zařazení majetku, zařadit majetek, datum zařazení, vstupní cena, pořizovací cena, technické zhodnocení, TZ, snížení hodnoty, přerušení odpisů, přerušit odpisy, vyřazení majetku, vyřadit majetek, polovina odpisu, poloviční odpis, zůstatková cena, oprávky, odpisy za období, odepsat, roční odpis, měsíční odpisy, četnost odpisů, počáteční stav, přechod z jiného systému, převod majetku, hospodářský rok, události majetku, historie majetku]
related: [majetek/zauctovani-majetku.md, majetek/evidence-majetku.md, majetek/naklady-na-majetek.md, majetek/nastaveni-majetku.md, co-dnes-nejde.md, slovnicek.md, majetek/prehledy-majetku.md]
---

# Odpisy majetku

Dlouhodobý majetek se v Shipardu odepisuje ze **karty majetku**: karta
nese nastavení odpisů, hodnotová historie je řada **událostí** (zařazení,
technické zhodnocení, odpis, vyřazení…) a plán odpisů si Shipard z nich
spočítá sám — nikde se neopisuje ručně. Účetní odpisy, zařazení
i vyřazení pak Shipard zaúčtuje jedním dokladem za období, viz
[Zaúčtování majetku](zauctovani-majetku.md).

## Kdy to potřebuješ

Pořídil jsi stroj, auto nebo software a chceš vědět, kolik letos
odepíšeš daňově a kolik účetně. Na konci roku (nebo měsíce) chceš odpisy
všech karet potvrdit najednou. Do majetku jsi investoval (technické
zhodnocení), nebo ho prodáváš či likviduješ. Nebo přecházíš do Shipardu
z jiného systému a majetek je už rozepsaný.

## Postup

**Nastavení odpisů na kartě**

1. Otevři kartu dlouhodobého majetku (viz
   [Evidence majetku](evidence-majetku.md)) a přejdi na záložku **Odpisy**.
2. Vyber **Daňovou metodu** — nabídka odpovídá pravidlům země: rovnoměrný
   a zrychlený odpis, mimořádné odpisy, u nehmotného majetku časový odpis
   nebo *Podle účetních odpisů*; *Neodepisuje se daňově* pro majetek bez
   daňového odpisu. U rovnoměrné a zrychlené metody vyber **Odpisovou
   skupinu**.
3. Vyber **Účetní metodu**: *Stejně jako daňové* (účetní odpis kopíruje
   daňový vzorec), nebo *Časová (rovnoměrně po měsících)* s **Dobou
   účetního odpisování** v měsících (5 let = 60).
4. Ulož kartu a dej **V pořádku** — události jdou potvrzovat jen u karty
   v tomto stavu.

**Zařazení**

1. V detailu karty dej **Zařadit**.
2. Vyplň **Datum** zařazení a **Vstupní cenu** a dej **Potvrdit**. Když
   kartu nesou řádky pořízení na fakturách, jsou obě pole předvyplněná
   součtem a datem poslední faktury — viz
   [Majetek na dokladech](naklady-na-majetek.md).
3. Karta dostane **Datum pořízení** a na záložkách **Daňové odpisy**
   a **Účetní odpisy** uvidíš plán: období, výpočet, odpis, oprávky
   a zůstatek. Řádky označené **Plán** jsou spočítané dopředu, **Potvrzeno**
   jsou hotové odpisy.

**Odpisy za období**

1. V seznamu **Majetek → Majetek** dej **Odpisy za období** (pro jednu
   kartu je totéž tlačítko **Odepsat** v jejím detailu).
2. Vyber **Daňové odpisy** a **Účetní rok**.
3. Zkontroluj náhled — karty s částkou a výpočtem, součet, dole karty,
   které se odepsat nedají, s důvodem.
4. Dej **Potvrdit odpisy**. Odpisy vzniknou jako potvrzené události;
   opakované spuštění je nezaloží dvakrát.

Účetní odpisy všech karet se potvrzují volbou **Účetní odpisy
a zaúčtování** — rovnou s účetním dokladem, viz
[Zaúčtování majetku](zauctovani-majetku.md). **Odepsat** v detailu karty
nabízí **Účetní odpisy** jen té karty; odpis se potvrdí a zaúčtuje ho až
dávka za období.

**Technické zhodnocení a snížení hodnoty**

1. V detailu karty dej **Technické zhodnocení**, nebo **Snížení hodnoty**.
2. Vyplň **Datum** a **Částku** a dej **Potvrdit**. Plán se od toho data
   přepočítá; snížení jde nejvýš do zůstatkové ceny.

**Přerušení daňových odpisů**

1. V detailu karty dej **Přerušit odpisy** (jen u rovnoměrné a zrychlené
   metody).
2. Vyplň **Datum** v účetním roce, který chceš vynechat, a dej **Potvrdit**.
   Ten rok se do pořadí let nepočítá, odpisování se o rok prodlouží.
   Účetní odpisy běží dál.

**Vyřazení**

1. V detailu karty dej **Vyřadit**.
2. Vyplň **Datum** vyřazení. Volba **Polovina ročního odpisu** je
   předvybraná, když ji zákon dovoluje (majetek byl v evidenci na začátku
   roku); odškrtni ji, když chceš rok vyřazení bez daňového odpisu.
3. Dej **Potvrdit**. Shipard založí poslední odpisy obou okruhů (daňový
   za rok vyřazení, účetní do měsíce vyřazení), zapíše **Datum vyřazení**
   a kartu přesune do stavu **V archívu**.

**Počáteční stav při přechodu z jiného systému**

1. Založ kartu s nastavením odpisů, dej **V pořádku** a v detailu použij
   **Počáteční stav — daňový** a pak **Počáteční stav — účetní**.
2. **Datum** je první den účetního roku, od kterého Shipard pokračuje;
   vyplň **Datum původního zařazení**, **Vstupní cenu** (včetně dosavadních
   zhodnocení), **Oprávky** a **Uplatněné roky odpisů** (u účetního okruhu
   a časových metod **Odepsané měsíce**). Když byla cena zvýšena
   technickým zhodnocením, zaškrtni **Zvýšená vstupní cena**.
3. Dej **Potvrdit**. Plán pokračuje správnou sazbou; když oprávky
   neodpovídají počtu let, ukáže se varování — nic to neblokuje.

**Četnost účetních odpisů**

V **Nastavení aplikace → Majetek → Odpisy** vyber **Četnost účetních
odpisů**: *Ročně* (výchozí) nebo *Měsíčně*. Daňové odpisy jsou vždy roční.

## Na co narazíš

**Historie se rozebírá od konce.** Potvrzenou událost jde opravit nebo
smazat, jen když za ní ve stejném okruhu není potvrzený odpis; stejně
nejde přidat zpětně zhodnocení před už potvrzený odpis. Nejdřív zruš
pozdější odpisy (na kartě záložka **Události**, u řádku **Otevřít** →
**Opravit** / **Smazat**).

**Datum události musí ležet v založeném účetním roce.** Zařazení,
zhodnocení, odpis ani vyřazení s datem před prvním (nebo za posledním)
účetním rokem nepotvrdíš. Majetek zařazený dřív, než v Shipardu účtuješ,
zadej jako **Počáteční stav**.

**Daňovou metodu po prvním potvrzeném daňovém odpisu nezměníš.** Účetní
metodu změnit můžeš, plán se přepočítá od posledního potvrzeného odpisu.

**Datum pořízení a vyřazení dlouhodobého majetku nepíšeš na kartu** —
plní je zařazení a vyřazení. Kartu dlouhodobého majetku proto nejde
poslat do archívu bez potvrzeného vyřazení.

**Zrušené vyřazení vrátí kartu do stavu V opravě** a smaže odpisy, které
vyřazení založilo k datu vyřazení. Odpisy za dřívější období, které
vyřazení doplnilo, zůstanou — když je nechceš, smaž je od konce.

**Nabídka metod záleží na datu zařazení.** Mimořádné odpisy platí jen pro
majetek pořízený v zákonem daných letech, časový odpis nehmotného majetku
jen do roku 2020. Karta bez zařazení nabízí všechno; při zařazení se
nastavení proti datu ověří a případně tě pošle zpět na záložku Odpisy.

**Krátký účetní rok (přechod na hospodářský rok)** dává u rovnoměrné
a zrychlené metody polovinu ročního odpisu — i za jediný měsíc; delší
než 12 měsíců plný odpis.

**Zamčený účetní měsíc** odpisy do něj nepustí — ani hromadné, ani
poslední při vyřazení.

**Zaúčtovaná událost je zamčená** — opravit nebo smazat ji jde až po
zrušení zaúčtování období, viz [Zaúčtování majetku](zauctovani-majetku.md).
Zrušit nejde ani vyřazení, jehož odpisy jsou zaúčtované.

## Souvisí

- [Zaúčtování majetku](zauctovani-majetku.md)
- [Evidence majetku](evidence-majetku.md)
- [Nastavení majetku](nastaveni-majetku.md)
- [Přehledy majetku](prehledy-majetku.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
- [Slovníček](../slovnicek.md)
