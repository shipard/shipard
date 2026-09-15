---
title: Platba kartou, přes bránu a dobírkou
summary: Jak nastavit platební terminál, platební bránu a způsob dopravy s protistranou, aby prodej kartou, přes bránu nebo na dobírku vytvořil pohledávku za tím, kdo ti peníze skutečně pošle.
keywords: [platba kartou, platební terminál, terminál, platební brána, brána, dobírka, dobírkou, dopravce, způsob dopravy, plátce, osoba pro saldokonto, kdo mi zaplatí, pohledávka za terminálem, pohledávka za bránou, pohledávka za dopravcem, tržba kartou, vyúčtování úhrad, peníze na cestě, GoPay, Comgate, PPL, Zásilkovna]
related: [pokladna/prodejka.md, pokladna/pokladni-doklad.md, faktury-vydane/vystaveni-faktury.md, uctarna/kdyz-se-doklad-nezauctuje.md, co-dnes-nejde.md]
---

# Platba kartou, přes bránu a dobírkou

Když zákazník zaplatí kartou, přes platební bránu nebo na dobírku, peníze
ti nepošle on, ale provozovatel terminálu, brána nebo dopravce — a to
obvykle za víc prodejů najednou. Shipard proto u takového dokladu vede
pohledávku za tímto **plátcem**, ne za zákazníkem. Tahle stránka říká, co
k tomu nastavit a co pak uvidíš v saldokontu.

## Kdy to potřebuješ

- Máš na pokladně platební terminál a zákazníci platí kartou.
- Prodáváš přes e-shop s platební bránou.
- Posíláš zboží na dobírku a dopravce ti vybrané peníze posílá hromadně.

Pokud zákazník platí hotově nebo převodem, nic z toho nastavovat nemusíš.

## Postup

1. **Založ terminál nebo bránu** v **Nastavení → Účetnictví → Platební
   terminály a brány** a dej **Přidat**. Vyplň **Kód**, **Název** a
   **Druh** — *Platební terminál* nebo *Platební brána*. U terminálu vyber
   **Pokladnu**, ke které patří (brána pokladnu nemá). V poli **Osoba pro
   saldokonto** vyber firmu, která ti tržby posílá — provozovatele
   terminálu nebo brány; když ji v evidenci nemáš, založíš ji přímo odsud.
   Zaškrtni **Výchozí**, má-li se u karty doplňovat sám (u každé pokladny
   může být výchozí jeden terminál, mezi bránami jedna výchozí). Ulož ho do
   stavu **V pořádku** — bez osoby pro saldokonto to nejde.

2. **Založ způsob dopravy** v **Nastavení → Účetnictví → Způsoby dopravy**:
   **Kód**, **Název** a **Osoba pro saldokonto** = dopravce, který vybírá
   dobírky. Vlastní rozvoz nebo osobní odběr osobu pro saldokonto nemají.

3. **Na dokladu zvol Způsob platby.** Na prodejce, příjmovém pokladním
   dokladu i vydané faktuře:

   - *Kartou* — objeví se pole **Platební terminál / brána** předvyplněné
     výchozím terminálem pokladny (na faktuře výchozím terminálem vůbec);
     můžeš vybrat jiný.
   - *Platební bránou* — v tomtéž poli vyber bránu; bez ní doklad neuložíš.
   - *Dobírkou* — vyber **Způsob dopravy** s dopravcem.

   Pole **Plátce** se doplní samo protistranou terminálu, brány nebo
   dopravce a je jen ke čtení. U převodu nebo zápočtu je plátcem partner
   dokladu; kdyby měl platit někdo jiný, zaškrtni **Zadat plátce ručně**
   a vyber ho.

4. **Potvrď doklad.** Zaúčtuje se pohledávka za plátcem s variabilním
   symbolem rovným číslu dokladu. V **Účtárna → Saldokonto** ji najdeš ve
   skupině **Pohledávky** pod plátcem (terminálem, bránou, dopravcem), ne
   pod zákazníkem — u zákazníka žádná otevřená položka nevznikne.

## Na co narazíš

**Doklad nejde uložit: „Doklad nemá plátce".** Prodejka nebo příjmový
pokladní doklad kartou, dobírkou či bránou musí mít, za kým pohledávka
vznikne. Buď doplň terminál, bránu nebo dopravu s vyplněnou osobou pro
saldokonto, nebo zadej partnera dokladu — pak je plátcem on.

**Nemáš žádný terminál.** Karta na pokladně funguje i bez něj: plátcem je
partner dokladu, takže u anonymního prodeje kartou partnera vyplnit musíš.

**Vydaná faktura kartou nemá pokladnu**, proto se nabízí výchozí terminál
z kterékoli pokladny; vyber ten správný, když jich máš víc.

**Ruční plátce se nepřepíše, dokud nezvolíš kartu, bránu nebo dobírku.**
Ty mají přednost: protistrana terminálu, brány nebo dopravce ruční volbu
nahradí a přepínač **Zadat plátce ručně** zmizí.

**Přijatá faktura** plátce sama neodvozuje — terminál ani doprava se na
ní nezadávají, ruční plátce ale zvolit můžeš.

**Vyúčtování od terminálu nebo brány** (souhrn tržeb mínus poplatky, který
ti provozovatel pošle) Shipard zatím sám nevytvoří — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md). Do té doby pohledávky za
plátcem uzavřeš účetním dokladem: každou prodejku z vyúčtování na stranu
DAL účtu pohledávek s tímtéž plátcem a variabilním symbolem, celou dávku
na stranu MD účtu ostatních pohledávek (315), který pak spáruje připsání
od brány.

## Souvisí

- [Prodejka](prodejka.md) — prodej na místě kartou nebo hotově
- [Pokladní doklad](pokladni-doklad.md) — příjem kartou, úhrada faktury kartou
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md) — způsob platby
  na vydané faktuře
- [Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — vyúčtování od brány
