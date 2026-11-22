---
title: Kód číselné řady a registrace DPH
summary: Co je kód řady dokladů a kód registrace DPH, kde ho najdeš, kdy ho měnit a proč se neplete s kódem v čísle dokladu.
keywords: [kód řady, kód číselné řady, kód registrace, kód registrace DPH, číselné řady dokladů, registrace DPH, kód v čísle dokladu, jedinečný kód, kód už má řada, kód už má registrace, duplicitní kód, změnit kód řady, přejmenovat řadu, invno, cash, datová sada, import]
related: [faktury-vydane/odesilatel-faktur.md, prvni-nastaveni.md, zakazky/nastaveni-zakazek.md]
---

# Kód číselné řady a registrace DPH

Každá číselná řada dokladů a každá registrace DPH má svůj **kód** — krátké
jedinečné označení, podle kterého ji Shipard pozná při přenosu dat mezi
zdroji dat a při importu. Kód vzniká sám; většinou ho nemusíš řešit.

## Kdy to potřebuješ

- Zakládáš druhou číselnou řadu stejného typu dokladu nebo druhou
  registraci DPH a chceš, aby měla srozumitelný kód.
- Uložení hlásí, že **kód už má** jiná řada nebo registrace.
- Pleteš si **Kód řady** s polem **Kód v čísle dokladu (%C)**.

## Postup

**Kód číselné řady:**

1. Otevři **Nastavení → Účetnictví → Číselné řady dokladů** a otevři řadu,
   nebo dej **Přidat**.
2. Pole **Kód řady** je hned pod názvem. U nové řady se vyplní samo, jakmile
   vybereš **Typ dokladu** (u řady pokladny až po výběru pokladny).
3. Návrh můžeš přepsat. Když pole necháš prázdné, Shipard kód doplní při
   uložení.
4. Ulož.

Jak návrh vypadá:

| Řada | Kód |
|---|---|
| první řada typu dokladu | kód typu, například `invno` u vydaných faktur |
| další řada téhož typu | `invno-2`; s vyplněným kódem v čísle dokladu `invno-` a ten kód |
| řada pokladny | `cash-` a kód pokladny, u prodejek `cashreg-` a kód pokladny |

**Kód registrace DPH:**

1. Otevři **Nastavení → Účetnictví → Registrace DPH** a otevři registraci,
   nebo dej **Přidat**.
2. Pole **Kód registrace** je hned pod názvem. U nové registrace se vyplní
   podle země — `cz`, u druhé registrace téže země `cz-2`.
3. Návrh můžeš přepsat a uložit.

Kód jde změnit i u řady nebo registrace ve stavu **V pořádku** — nemusíš
ji kvůli tomu převádět do stavu **V opravě**. Doklady o svou řadu ani registraci změnou
kódu nepřijdou.

## Na co narazíš

**Kód řady není kód v čísle dokladu.** Pole **Kód v čísle dokladu (%C)**
se dosazuje do čísla dokladu a dvě řady ho smí mít stejný. **Kód řady** se
do čísla dokladu nepropisuje a musí být v celém seznamu řad jedinečný.
Změna kódu řady čísla dokladů nezmění.

**„Kód už má řada …“** Kód musí být jedinečný a nezáleží na velikosti
písmen — `FV` a `fv` je tentýž kód. Počítají se i řady a registrace
v koši; hláška v tom případě řekne, že je záznam v koši.

**Které znaky kód smí mít.** Písmena bez diakritiky, číslice, tečku,
pomlčku a podtržítko; začínat musí písmenem nebo číslicí. Mezera, zavináč
a dvojtečka nejdou.

**Kód nejde vymazat.** Řada i registrace kód mít musí. Prázdné pole projde
jen u záznamu, který kód ještě nemá — tomu ho Shipard doplní.

**Návrh kódu se u nové řady mění s typem dokladu.** Když po vyplnění kódu
změníš **Typ dokladu** nebo pokladnu, Shipard kód navrhne znovu a tvůj
text přepíše. Vlastní kód proto piš až nakonec.

**Přejmenování pokladny kód řady nezmění.** Řada si nechá kód s původním
kódem pokladny, stejně jako kód v čísle dokladu. Když ti to vadí, přepiš
kód řady ručně.

**Kdy kód měnit raději ne.** Když na řadu nebo registraci odkazuje datová
sada nebo import z jiného systému, najdou ji jen pod kódem, který znají.
Po změně kódu ji nenajdou a hlásí chybu.

## Souvisí

- [Z jaké adresy faktury odcházejí](../faktury-vydane/odesilatel-faktur.md) — další nastavení číselné řady dokladů
- [První nastavení zdroje dat](../prvni-nastaveni.md) — založení Registrace DPH
- [Nastavení zakázek](../zakazky/nastaveni-zakazek.md) — číselné řady zakázek (mají vlastní kód řady, který jde do čísla zakázky)
