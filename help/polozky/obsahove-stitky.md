---
title: Obsahové štítky a položky k založení
summary: Jak AI třídí náklady z faktur do kategorií, co s kartami v sekci Položky k založení na Dashboardu a kde spravovat štítky a pravidla dodavatelů.
keywords: [obsahové štítky, obsahový štítek, položky k založení, položka k založení, založit položku, karta položky, nová kategorie, kategorie nákladů, otagování položek, štítky položek, pravidla dodavatelů, pravidlo dodavatele, pohonné hmoty, předvyplnění účtu, klasifikace dokladu, ISDOC]
related: [polozky/zalozeni-polozky.md, posta/kontrola-vytezeni.md, slovnicek.md]
---

# Obsahové štítky a položky k založení

Když Shipard čte fakturu — ať už ji vytěžila AI, nebo přišla ve formátu
ISDOC — a nenajde k řádkům položku podle tvé historie, zkusí doklad
zařadit podle obsahu — do kategorie jako *Pohonné hmoty* nebo
*Software a SaaS*. Kategorii říkáme **obsahový štítek**. Štítek se pak
překládá na tvou účetní položku: návrh v náhledu dokladu dostane rovnou
položku i účet.

## Kdy to potřebuješ

- Na **Dashboardu** se v sekci **Položky k založení** objevila karta
  s názvem kategorie (třeba *Pohonné hmoty*) a chceš vědět, co udělá
  tlačítko **Založit položku**.
- Návrh dokladu má u řádku jen účet, ale žádnou položku, a chceš, aby se
  příště předvyplňovala položka.
- Chceš zkontrolovat, které kategorie už máš pokryté, nebo hromadně
  otagovat položky po migraci.

## Postup

### Karta v sekci Položky k založení

1. Karta se ukáže v sekci **Položky k založení** úplně nahoře na
   Dashboardu, když nějaké doklady čekají na kategorii, pro kterou ještě
   nemáš otagovanou položku. Název karty je název kategorie; v podtitulku
   vidíš, kolik dokladů čeká a jaká položka se navrhne. Ve stejné sekci
   bývá i karta **Dokončit nastavení** — obojí odblokuje další práci,
   proto je to nahoře.
2. Klikni na **Založit položku** — položka vznikne rovnou otagovaná
   a s účtem. U kategorie **Zboží / materiál na sklad** si místo toho
   vybereš mezi **Jako materiál (501…)** a **Jako zboží (504…)** podle
   toho, jak nakoupené věci účtuješ.
3. Karta zmizí sama. Když teď otevřeš čekající návrh dokladu, řádky už
   nabízejí novou položku — bez opakované analýzy.

### Přehled a otagování v Nastavení

1. Otevři **Nastavení → Položky → Obsahové štítky**.
2. Horní tabulka ukazuje všechny kategorie a jejich stav: **otagované
   položky** (kategorie je pokrytá), **výchozí účet z nabídky** (položka
   zatím není — tlačítkem **Založit položku** ji založíš) a **bez
   mapování** (kategorie se nenavrhuje).
3. Sekce **Neotagované položky** nabízí položky, kterým jde štítek
   přiřadit podle jejich účtu. Zaškrtni, co dává smysl, a potvrď
   tlačítkem **Otagovat vybrané**. Položky, jejichž účtu odpovídá víc
   štítků, návrh nedostanou — otaguješ je ručně ve formuláři položky
   (pole **Obsahové štítky** v sekci Účetnictví).

### Pravidla dodavatelů

1. Když doklad zařazený AI dokončíš (**Vystavit a uzavřít** v náhledu
   návrhu, nebo z **Konceptu** do **V pořádku**), Shipard si zapamatuje **IČO
   dodavatele → štítek** — příště se stejný dodavatel zařadí okamžitě
   a bez AI. Pravidla najdeš v **Nastavení → Položky → Pravidla
   obsahových štítků**.
2. U pravidla vidíš štítek, IČO s názvem partnera, původ (**Ruční** /
   **Naučené**) a kolikrát zasáhlo. Otevřením pravidla můžeš štítek
   změnit — pravidlo se tím označí jako **Ruční** a učení ho už nemění.
3. Špatné pravidlo smažeš v detailu tlačítkem **Smazat pravidlo**.
   Smazání je trvalé, ale o nic nepřijdeš — pravidlo se může znovu
   naučit z dalšího dokončeného dokladu.

## Na co narazíš

**Kategorie bez mapování jsou schválně.** Třeba *Ostatní (bez zařazení)*
žádnou položku nenavrhne nikdy — doklad si vždycky prohlédneš sám. To
není chyba, ale pojistka proti slepému účtování.

**Založení z průchodu frontou.** Když spustíš **Projít frontu** a nějaké
položky k založení čekají, průchod začne dialogem **Nejdřív založte
položky** se stejnými tlačítky jako na kartách — viz
[Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md).

**Návrh „jen účet" je platný.** Když kategorie nemá otagovanou položku,
návrh řádku nese aspoň účet z nabídky. Takový doklad použiješ volbou
**Jen účet — bez položky** v náhledu — viz
[Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md).

**Dodavatel s pestrým sortimentem pravidlo nedostane.** Když od stejného
IČO chodí pokaždé něco jiného (hobbymarket), naučené pravidlo se samo
smaže — jednou kategorií by škodilo.

**Faktury ISDOC se zařazují taky.** Fakturu ve formátu ISDOC Shipard
převezme bez čtení AI, ale položky k řádkům hledá stejně — když je nenajde
v historii, zařadí doklad podle obsahu a karta v sekci **Položky
k založení** se objeví i pro něj. Dokončený ISDOC doklad učí pravidlo dodavatele jako každý jiný.
Zařazení proběhne chvíli po doručení, ne v okamžiku nahrání.

**Hromadné založení výchozích položek** je na jiném místě — v panelu
**Nastavení zdroje dat**, sekce s nabídkou účetních položek. Stránka
Obsahové štítky na něj odkazuje.

## Souvisí

- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md) — jak
  vypadá návrh se štítkem v náhledu
- [Založení položky](zalozeni-polozky.md) — ruční založení a pole
  Obsahové štítky
- [Slovníček](../slovnicek.md)
