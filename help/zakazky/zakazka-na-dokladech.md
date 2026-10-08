---
title: Zakázka na dokladech
summary: Jak zapnout pole Zakázka na fakturách, pokladních a účetních dokladech, kdy ji dát na hlavičku a kdy jen na řádek, a kde pak zakázku najdeš v účetním deníku.
keywords: [zakázka na dokladech, zakázka na faktuře, zakázka na řádku, zakázka z hlavičky, zapnout zakázky, dimenze na dokladech, dimenze zakázka, náklady zakázky, výnosy zakázky, náklady na zakázku, výnosy ze zakázky, deník zakázky, deník podle zakázky, filtr zakázka, zakázka v účetním deníku, kontace zakázka, přiřadit doklad k zakázce, doklad k zakázce, faktura k zakázce, zaúčtování na zakázku]
related: [zakazky/zakazky.md, zakazky/nastaveni-zakazek.md, uctarna/strediska.md, uctarna/tisk-kontace.md, co-dnes-nejde.md]
---

# Zakázka na dokladech

Zakázka je vedle střediska a majetku třetí dimenze účetních zápisů:
vybereš ji na dokladu a po zaúčtování ji nesou zápisy v účetním deníku.
Z nich detail zakázky skládá její **Deník**.

## Kdy to potřebuješ

Chceš vidět náklady a výnosy jednotlivé zakázky — faktury, které ses
zákazníkovi vystavil, a nákupy, které jsi pro zakázku udělal. Dokud
zakázky na dokladech nezapneš, doklady se chovají jako dřív a pole na
nich není.

## Postup

**Zapnutí zakázky na dokladech**

1. Otevři **Nastavení aplikace → Účetnictví → Dimenze na dokladech**.
2. **Zakázka na dokladech** nastav na *Ano* a dej **Uložit**.
3. Na **Faktuře přijaté**, **Faktuře vydané**, **Zálohové faktuře vydané**,
   **Pokladním dokladu** a **Účetním dokladu** se objeví pole **Zakázka**
   — na hlavičce i na každém řádku. Nabízí zakázky **V pořádku**
   a **V opravě**; hledá podle čísla, názvu i zákazníka.

**Zakázka na dokladu**

1. Týká-li se celý doklad jedné zakázky, vyber ji na hlavičce. Řádky bez
   vlastní zakázky ji převezmou.
2. Týká-li se zakázky jen část dokladu, vyber ji jen na tom řádku.
   Zakázka na řádku má přednost před zakázkou z hlavičky.
3. Dej **V pořádku**. Každý zápis v deníku teď nese zakázku — řádky
   dokladu podle svého výběru, zápisy za DPH a za závazek nebo pohledávku
   podle hlavičky.

**Kde zakázku najdeš**

1. V detailu zakázky na záložce **Deník**: obraty po účetních letech
   rozdělené na náklady, výnosy a ostatní účty, pod nimi jednotlivé zápisy
   s odkazem na doklad. Tlačítko **Otevřít v deníku** otevře účetní deník
   s filtrem na tuto zakázku přes všechny roky.
2. V **Účetním deníku** napiš do filtru **Zakázka** číslo nebo část
   názvu. Sloupec **Zakázka** je u každého zápisu.
3. Na záložce **Zaúčtování** v detailu dokladu a na vytištěné
   [Kontaci](../uctarna/tisk-kontace.md) je sloupec **Zakázka**, když ji
   některý zápis nese.

## Na co narazíš

**Vypnutím nastavení se nic neztratí.** Pole **Zakázka** z dokladů zmizí,
vybrané zakázky ale zůstanou a do deníku se propisují dál.

**Deník zakázky nesčítá podzakázky.** Ukazuje jen zápisy, které nesou
přímo ji; náklady podzakázek najdeš v jejich detailu.

**Ukončenou nebo zrušenou zakázku na nový doklad nevybereš** — nabídka
má jen zakázky V pořádku a V opravě. Na starých dokladech zůstává.

**Bankovní výpis zakázku nenese** a přehledy po zakázkách zatím nejsou —
dnes jen Deník na zakázce a filtr v účetním deníku. Viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Zakázky](zakazky.md)
- [Nastavení zakázek](nastaveni-zakazek.md)
- [Střediska](../uctarna/strediska.md)
- [Tisk kontace](../uctarna/tisk-kontace.md)
