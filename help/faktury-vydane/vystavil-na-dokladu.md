---
title: Kdo doklad vystavil
summary: Kde se bere jméno u „Vystavil“ v zápatí dokladu, jak ho na dokladu změnit nebo vynechat a čí jméno ponesou doklady vystavené bez přihlášeného uživatele.
keywords: [vystavil, kdo doklad vystavil, kdo vystavil fakturu, jméno na faktuře, jméno v zápatí, vystavila, autor dokladu, autora dokladu, změnit autora, bez autora, jiné jméno na faktuře, špatné jméno na faktuře, fakturu vystavil kolega, autor automaticky vystavených dokladů, automaticky vystavené doklady, doklad z e-shopu, doklad z jiného programu, neaktivní uživatel, bývalý kolega na faktuře]
related: [faktury-vydane/vystaveni-faktury.md, faktury-vydane/tisk-faktury.md, faktury-vydane/vzhled-tisku.md, uctarna/tisk-kontace.md, faktury-prijate/oprava-dokladu.md, co-dnes-nejde.md]
---

# Kdo doklad vystavil

Každý doklad si pamatuje, kdo ho vystavil. Na tištěném dokladu je to řádek
**Vystavil** se jménem — vpravo v zápatí, nad číslem strany.

## Kdy to potřebuješ

Na faktuře je jiné jméno, než má být, nebo tam žádné nechceš. Nebo ti
doklady zakládá jiný program a potřebuješ určit, čí jméno ponesou.

## Postup

**Jméno na jednom dokladu:**

1. Otevři doklad a přejdi na záložku **Nastavení** — úplně vpravo, za
   **Přílohami**.
2. V poli **Vystavil** vyber uživatele. U nového dokladu jsi předvybraný
   ty. Volba **Bez autora** znamená, že doklad řádek **Vystavil** mít
   nebude.
3. Dej **Uložit**.

Pole má každý doklad — faktura vydaná i přijatá, zálohová faktura,
pokladní doklad, prodejka i účetní doklad.

**Doklady vystavené bez přihlášeného uživatele** — typicky ty, které do
Shipardu zakládá jiný program:

1. Otevři **Nastavení → Účetnictví → Doklady**.
2. V poli **Autor automaticky vystavených dokladů** vyber uživatele a ulož.
   Prázdné pole znamená doklady bez autora.

**Jiný autor pro doklady jedné číselné řady:**

1. Otevři **Nastavení → Účetnictví → Číselné řady dokladů** a otevři řadu.
2. V sekci **Automaticky vystavené doklady** vyber uživatele v poli
   **Autor automaticky vystavených dokladů**. Volba **Podle nastavení**
   znamená autora z **Nastavení → Účetnictví → Doklady**.
3. Ulož.

Řada má přednost před nastavením. Když doklad zakládá přihlášený člověk,
je autorem vždycky on — nastavení ani řada se nepoužijí.

## Na co narazíš

**Řádek Vystavil na dokladu chybí.** Doklad nemá autora — pole **Vystavil**
je prázdné. Tak vypadají doklady založené dřív, než Shipard autora
evidoval, a doklady vystavené bez přihlášeného uživatele, pro které není
nastavený autor.

**Pole Vystavil nejde změnit.** Doklad ve stavu **V pořádku** je jen ke
čtení, stejně jako jeho ostatní pole. Vrať ho do stavu **V opravě**, autora
změň a doklad znovu potvrď — viz
[Oprava dokladu](../faktury-prijate/oprava-dokladu.md).

**V nabídce je u jména „neaktivní“.** Doklad vystavil uživatel, který už
do Shipardu nesmí. Jméno na dokladu zůstává; pro nové doklady ho ale
vybrat nejde — nabídka obsahuje jen aktivní uživatele a toho, kdo je na
dokladu právě uvedený.

**Na dokladu je jméno po přejmenování uživatele jiné.** Jméno se bere
z uživatele v okamžiku tisku. Když uživatele přejmenuješ, ponesou nové
jméno i starší doklady, které vystavil.

**Doklad z došlé pošty má jako autora toho, kdo návrh potvrdil.** Přijatá
faktura vlastní tisk nemá; jméno se objeví na její
[Kontaci](../uctarna/tisk-kontace.md).

**Shipard sám doklady zatím nevystavuje.** Hromadná ani pravidelná
fakturace není, takže **Autor automaticky vystavených dokladů** se dnes
týká jen dokladů, které zakládá jiný program.

**Podpis ani razítko na dokladu nejsou** — jen jméno. Viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Vystavení faktury](vystaveni-faktury.md) — celý postup vystavení
- [Tisk faktury](tisk-faktury.md) — co na vytištěném dokladu je
- [Vzhled tištěných dokladů](vzhled-tisku.md) — logo a barva hlavičky
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — jak změnit
  doklad, který už je V pořádku
