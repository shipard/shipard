# Uživatelská dokumentace Shipardu

Jak se v Shipardu dělají věci. Návody, ne popis vnitřností — technické
specifikace jsou v [`docs/`](../docs/README.md).

Shipard je ve stavu **alfa**, takže dokumentace vzniká postupně: nejdřív
zpracování došlé pošty a přijaté faktury, tedy to, co teď zkoušejí testeři.
Co chybí, se dozvíš na **podpora@shipard.cz** — a není hloupá otázka.

Tyhle stránky slouží dvěma čtenářům: tobě a vestavěnému AI asistentovi
(**Chat** v aplikaci). Když se zeptáš jeho, odpovídá z téhož textu.

> **Poprvé tady?** Jak si říct o přístup, co si vyzkoušet a jak nahlásit
> chybu je v [TESTERS.md](../TESTERS.md).

---

<!-- OBSAH:BEGIN — generováno scripts/help-index.py, needituj ručně -->

## Obsah

### Základy

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Co Shipard dnes neumí](co-dnes-nejde.md) | Poctivý seznam chybějících funkcí a míst, kde ještě nemusí souhlasit čísla. |
| [Co Shipard umí](co-shipard-umi.md) | Úplný přehled agend, které v aplikaci jsou — a u kterých z nich už je napsaný návod. |
| [Instalace aplikace na telefon nebo počítač](instalace-aplikace.md) | Jak si Shipard přidat na plochu telefonu nebo nainstalovat jako aplikaci do počítače — a co to znamená. |
| [Informace o zdroji dat](o-zdroji-dat.md) | Kde zjistíš název a IČO vlastní firmy, adresu pro příjem pošty, plátcovství DPH, ID zdroje dat a kolik místa zabírají data a přílohy. |
| [První nastavení zdroje dat](prvni-nastaveni.md) | Jak čerstvý zdroj dat nastavit přes kartu Dokončit nastavení — vlastní firma, DPH, bankovní účet, účtová osnova, fiskální rok a domácí měna. |
| [Slovníček](slovnicek.md) | Co která věc v Shipardu znamená a jak se jmenuje v rozhraní. |
| [Začínáme](zaciname.md) | Co dělat po prvním přihlášení — dokončit nastavení, dostat do Shipardu první fakturu a zorientovat se v levém panelu. |

### Osoby

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Založení osoby](osoby/zalozeni-osoby.md) | Jak přidat dodavatele nebo odběratele — natažením české firmy z registru podle IČO, nebo ručně. |

### Položky

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Obsahové štítky a karta Nová kategorie](polozky/obsahove-stitky.md) | Jak AI třídí náklady z faktur do kategorií, co s kartou Nová kategorie na Dashboardu a kde spravovat štítky a pravidla dodavatelů. |
| [Založení položky](polozky/zalozeni-polozky.md) | Jak přidat položku do katalogu, co je povinné, co Shipard doplní sám a co na položce vědomě není. |

### Došlá pošta

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Když AI přečte fakturu špatně](posta/kdyz-ai-cte-spatne.md) | Kde se která chyba opravuje, kdy návrh spíš zamítnout a co z chyby nahlásit. |
| [Kontrola vytěženého dokladu](posta/kontrola-vytezeni.md) | Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout. |
| [Příjem pošty](posta/prijem-posty.md) | Jak dostat fakturu do Shipardu, co se s ní pak děje a jak si poradit s poštou, která faktura není. |

### Faktury přijaté

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Dokončení dokladu](faktury-prijate/dokonceni-dokladu.md) | Co se děje po Vystavit koncept — od Konceptu přes Potvrzeno k V pořádku a co se tím spustí. |
| [Oprava dokladu](faktury-prijate/oprava-dokladu.md) | Jak opravit nebo zrušit přijatou fakturu, která už je ve stavu V pořádku, a čemu se přitom vyhnout. |

### Faktury vydané

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Vystavení faktury](faktury-vydane/vystaveni-faktury.md) | Jak vystavit fakturu odběrateli — od Přidat po V pořádku — a proč ji z Shipardu zatím nedostaneš na papír. |
| [Zálohová faktura](faktury-vydane/zalohova-faktura.md) | Kdy vystavit zálohovou fakturu (proformu) místo faktury, proč není daňovým dokladem a jak ji vystavit — od Přidat po V pořádku. |

### Pokladna

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Platba kartou, přes bránu a dobírkou](pokladna/platba-kartou-branou-dobirkou.md) | Jak nastavit platební terminál, platební bránu a způsob dopravy s protistranou, aby prodej kartou, přes bránu nebo na dobírku vytvořil pohledávku za tím, kdo ti peníze skutečně pošle. |
| [Pokladní doklad](pokladna/pokladni-doklad.md) | Jak zapsat příjem nebo výdej hotovosti či platbu kartou na pokladně — včetně úhrady faktury — a co k tomu musí být nastavené. |
| [Prodejka](pokladna/prodejka.md) | Jak zapsat prodej za hotové, kartou, přes bránu nebo na dobírku na pokladně bez faktury a jak udělat vratku. |

### Účtárna

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Podání DPH](uctarna/dph-podani.md) | Jak z živého výpočtu udělat podání, vyrobit soubor pro daňový portál a mít trvalý záznam toho, co jsi za období odevzdal. |
| [Živé výstupy DPH](uctarna/dph-zive-vystupy.md) | Jak si přečíst živé přiznání k DPH, kontrolní hlášení a souhrnné hlášení za zvolené období a co znamenají upozornění pod tabulkou. |
| [Když se doklad nezaúčtuje](uctarna/kdyz-se-doklad-nezauctuje.md) | Co znamenají hlášky u chyby účtování, kde se která spravuje a proč doklad nemusíš rozebírat. |
| [Uzamčení období](uctarna/uzamceni-obdobi.md) | Jak po podání DPH uzamknout tvrzení nebo celý fiskální měsíc, co zámek zastaví, jak vypadá zamčený doklad a jak zámek zase sundat. |

<!-- OBSAH:END -->

---

## Když něco nejde

1. Podívej se do [Co Shipard dnes neumí](co-dnes-nejde.md) — možná to
   zatím fakt nejde a není to tvoje chyba.
2. Zkus se zeptat asistenta v **Chatu**.
3. Napiš na **podpora@shipard.cz**, nebo
   [založ hlášení na GitHubu](https://github.com/shipard/shipard/issues/new/choose)
   (pozor, hlášení jsou veřejná — pravidla jsou v [TESTERS.md](../TESTERS.md)).

---

[← README.md](../README.md) · [Pro testery](../TESTERS.md) · [Technická dokumentace](../docs/README.md)
