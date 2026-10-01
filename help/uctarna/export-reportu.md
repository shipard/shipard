---
title: Export reportu do Excelu nebo CSV
summary: Jak stáhnout hlavní knihu, výsledovku, rozvahu nebo výstup DPH jako sešit pro Excel nebo jako CSV a co ve staženém souboru najdeš.
keywords: [export, exportovat, stáhnout report, stažení reportu, do Excelu, excel, xlsx, csv, tabulka, tabulkový procesor, LibreOffice, uložit report, vytisknout report, tisk reportu, PDF reportu, hlavní kniha do Excelu, výsledovka do Excelu, rozvaha do Excelu, v tisících, desetinná čárka, středník]
related: [uctarna/dph-zive-vystupy.md, co-shipard-umi.md, co-dnes-nejde.md, majetek/prehledy-majetku.md]
---

# Export reportu do Excelu nebo CSV

Každý report ze skupiny **Reporty** — hlavní knihu, výsledovku, rozvahu
i živé výstupy DPH — si stáhneš jako soubor a dál s ním pracuješ
v tabulkovém procesoru.

## Kdy to potřebuješ

- Chceš si čísla z reportu dál zpracovat: přepočítat, zafiltrovat, porovnat
  s jiným obdobím, poslat účetní nebo auditorovi.
- Potřebuješ report **vytisknout** — Shipard reporty sám netiskne, vytiskneš
  stažený sešit.

## Postup

1. V levém menu otevři skupinu **Reporty** a vyber report.
2. Nahoře zvol **Období** a u účetních reportů úroveň detailu
   (**Analyticky** / **Synteticky**). Počkej, až se report načte.
3. Klikni na tlačítko **Export** a vyber formát:
   - **Excel (XLSX)** — sešit pro Excel nebo LibreOffice,
   - **CSV** — prostá tabulka pro další strojové zpracování.
4. Soubor se stáhne do počítače. Jmenuje se podle reportu a období,
   například `hlavni-kniha-2026-05.xlsx`.

Stáhne se přesně ten report, který máš na obrazovce — stejné období
i stejná úroveň detailu.

## Co je v souboru

**Excel (XLSX)** má na listu **Report** nahoře úvod: název reportu, období,
úroveň detailu, kdy byl soubor vytvořen a název firmy. Pod ním je tabulka:

- čísla jsou opravdová čísla — můžeš je sčítat a formátovat,
- součtové řádky jsou tučně a řádky jsou odsazené podle úrovně (třída,
  skupina, účet) stejně jako na obrazovce,
- sloupec, který report ukazuje jako **MD** / **D** / **Zůstatek**, je
  v souboru rozdělený na tři sloupce.

Když report hlásí chyby nebo varování, je to napsané v úvodu a sešit má
druhý list **Zprávy** — u každé zprávy je číslo řádku na listu Report,
kterého se týká.

**CSV** obsahuje jen tabulku: první řádek jsou názvy sloupců, hodnoty jsou
oddělené středníkem a čísla mají desetinnou čárku, takže se v Excelu
s českým nastavením otevře rovnou správně.

## Na co narazíš

- **Čísla jsou vždy přesná.** Přepínač **V tisících** mění jen zobrazení na
  obrazovce — do souboru jdou částky na haléře. Když je chceš v tisících,
  vyděl si je v tabulce.
- **Nuly jsou v souboru vypsané.** Na obrazovce jsou nulové hodnoty prázdné,
  v souboru je `0`, aby sloupec zůstal číselný.
- **CSV nenese upozornění reportu.** Chyby a varování pod tabulkou se do CSV
  nedostanou; když je potřebuješ mít u čísel, stáhni **Excel (XLSX)**.
- **Tlačítko Export je šedé**, dokud se report nenačte nebo když se
  nepodařilo ho spočítat.
- Čas vytvoření v úvodu sešitu je uvedený s časovou zónou — může se lišit
  od času na tvých hodinkách.
- **PDF ani tisk přímo ze Shipardu nejsou** a seznamy (faktury, osoby,
  deník…) takhle stáhnout nejde — viz
  [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Živé výstupy DPH](dph-zive-vystupy.md)
- [Co Shipard umí](../co-shipard-umi.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
