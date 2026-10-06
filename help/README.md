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
| [Kontakty a účely odesílání](osoby/kontakty-a-ucely.md) | Jak u odběratele nastavit, na které e-maily chodí faktury — kontakty osoby a jejich účely odesílání. |
| [Založení osoby](osoby/zalozeni-osoby.md) | Jak přidat dodavatele nebo odběratele — natažením české firmy z registru podle IČO, nebo ručně. |

### Položky

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Obsahové štítky a položky k založení](polozky/obsahove-stitky.md) | Jak AI třídí náklady z faktur do kategorií, co s kartami v sekci Položky k založení na Dashboardu a kde spravovat štítky a pravidla dodavatelů. |
| [Založení položky](polozky/zalozeni-polozky.md) | Jak přidat položku do katalogu, co je povinné, co Shipard doplní sám a co na položce vědomě není. |

### Pošta

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Když AI přečte fakturu špatně](posta/kdyz-ai-cte-spatne.md) | Kde se která chyba opravuje, kdy návrh spíš zamítnout a co z chyby nahlásit. |
| [Kontrola vytěženého dokladu](posta/kontrola-vytezeni.md) | Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout. |
| [Odeslaná pošta](posta/odeslana-posta.md) | Kde najdeš všechno, co z Shipardu odešlo e-mailem — komu, kdy, s jakými přílohami a jak odeslání dopadlo; jak zprávu odeslat znovu, archivovat nebo smazat. |
| [Příjem pošty](posta/prijem-posty.md) | Jak dostat fakturu do Shipardu, co se s ní pak děje a jak si poradit s poštou, která faktura není. |

### Faktury přijaté

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Dokončení dokladu](faktury-prijate/dokonceni-dokladu.md) | Co se děje po Vystavit koncept — od Konceptu přes Potvrzeno k V pořádku a co se tím spustí. |
| [Oprava dokladu](faktury-prijate/oprava-dokladu.md) | Jak opravit nebo zrušit přijatou fakturu, která už je ve stavu V pořádku, a čemu se přitom vyhnout. |

### Faktury vydané

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Z jaké adresy faktury odcházejí](faktury-vydane/odesilatel-faktur.md) | Jak nastavit adresu a jméno odesílatele e-mailů s doklady — výchozí adresu zdroje dat a jinou adresu pro doklady jedné číselné řady. |
| [Odeslání faktury e-mailem](faktury-vydane/odeslani-faktury.md) | Jak fakturu, zálohovou fakturu, pokladní doklad nebo prodejku poslat odběrateli e-mailem přímo z Shipardu — komu odejde, co je v příloze a co dělat, když odběratel nahlásí jinou adresu. |
| [Texty na tiscích](faktury-vydane/texty-na-tiscich.md) | Jak na faktury a další doklady přidat vlastní text — oznámení na pár dní nebo trvalý text na konci dokladu — a jak si přepsat předmět a text e-mailu, kterým doklad odchází. |
| [Tisk faktury](faktury-vydane/tisk-faktury.md) | Jak z hotové faktury nebo zálohové faktury dostat PDF — náhled, stažení, co na dokladu je, tisk stornovaného dokladu a proč se koncept netiskne. |
| [Vystavení faktury](faktury-vydane/vystaveni-faktury.md) | Jak vystavit fakturu odběrateli — od Přidat po V pořádku. |
| [Kdo doklad vystavil](faktury-vydane/vystavil-na-dokladu.md) | Kde se bere jméno u „Vystavil“ v zápatí dokladu, jak ho na dokladu změnit nebo vynechat a čí jméno ponesou doklady vystavené bez přihlášeného uživatele. |
| [Vzhled tištěných dokladů](faktury-vydane/vzhled-tisku.md) | Jak dát fakturám a dalším tištěným dokladům firemní barvu v hlavičce a přesunout logo vlevo nebo vpravo — jedno nastavení pro všechny tisky. |
| [Zálohová faktura](faktury-vydane/zalohova-faktura.md) | Kdy vystavit zálohovou fakturu (proformu) místo faktury, proč není daňovým dokladem a jak ji vystavit — od Přidat po V pořádku. |

### Pokladna

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Platba kartou, přes bránu a dobírkou](pokladna/platba-kartou-branou-dobirkou.md) | Jak nastavit platební terminál, platební bránu a způsob dopravy s protistranou, aby prodej kartou, přes bránu nebo na dobírku vytvořil pohledávku za tím, kdo ti peníze skutečně pošle. |
| [Pokladní doklad](pokladna/pokladni-doklad.md) | Jak zapsat příjem nebo výdej hotovosti či platbu kartou na pokladně — včetně úhrady faktury —, jak doklad vytisknout a co k tomu musí být nastavené. |
| [Prodejka](pokladna/prodejka.md) | Jak zapsat prodej za hotové, kartou, přes bránu nebo na dobírku na pokladně bez faktury, jak udělat vratku a jak prodejku vytisknout. |

### Majetek

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Evidence majetku](majetek/evidence-majetku.md) | Jak založit kartu majetku, kdy je věc drobný a kdy dlouhodobý majetek, co je cizí majetek, jak karta dostane inventární číslo a jak majetek vyřadit. |
| [Majetek na dokladech](majetek/naklady-na-majetek.md) | Jak přiřadit fakturu, pokladní nebo účetní doklad ke kartě majetku, jak z řádku pořízení rovnou založit kartu a kde na kartě uvidíš pořízení, náklady a výnosy. |
| [Nastavení majetku](majetek/nastaveni-majetku.md) | Kde nastavíš typy majetku, skupiny typů, účetní skupiny majetku a prefixy inventárních čísel — a co z toho karta majetku přebírá. |
| [Odpisy majetku](majetek/odpisy-majetku.md) | Jak na kartě nastavit daňové a účetní odpisy, zařadit majetek, zadat technické zhodnocení nebo snížení hodnoty, přerušit odpisy, vyřadit majetek a hromadně potvrdit odpisy za období. |
| [Přehledy majetku](majetek/prehledy-majetku.md) | Pět přehledů majetku — sestava odpisů, přírůstky a úbytky, daňové odpisy pro přiznání, soupis majetku a kontrola evidence proti účetnímu deníku — kdy který použít a co dělat, když kontrola hlásí nesoulad. |
| [Zaúčtování majetku](majetek/zauctovani-majetku.md) | Jak účetní odpisy, zařazení, technické zhodnocení a vyřazení majetku zaúčtovat jedním účetním dokladem za období, co se kam účtuje a jak zaúčtování období zrušit. |

### Účtárna

| Stránka | Co v ní najdeš |
|---------|----------------|
| [Podání DPH](uctarna/dph-podani.md) | Jak z živého výpočtu udělat podání, vyrobit soubor pro daňový portál a mít trvalý záznam toho, co jsi za období odevzdal. |
| [Živé výstupy DPH](uctarna/dph-zive-vystupy.md) | Jak si přečíst živé přiznání k DPH, kontrolní hlášení a souhrnné hlášení za zvolené období a co znamenají upozornění pod tabulkou. |
| [Export reportu do Excelu nebo CSV](uctarna/export-reportu.md) | Jak stáhnout hlavní knihu, výsledovku, rozvahu nebo výstup DPH jako sešit pro Excel nebo jako CSV a co ve staženém souboru najdeš. |
| [Když se doklad nezaúčtuje](uctarna/kdyz-se-doklad-nezauctuje.md) | Co znamenají hlášky u chyby účtování, kde se která spravuje a proč doklad nemusíš rozebírat. |
| [Tisk kontace](uctarna/tisk-kontace.md) | Jak k dokladu vytisknout Kontaci — PDF s účetními zápisy, kterými je doklad zaúčtovaný — a co na ní je. |
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
