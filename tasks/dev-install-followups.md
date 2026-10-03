# Instalace — práva logu a doporučená verze Ubuntu

**Stav:** naplánováno — #96 D15, D16

## Cíl

Poslední dvě drobnosti z ověření čisté instalace na Multipass VM (#96):

1. **`shipard.log` vzniká s `0644`**, kontrakt (`PermissionSpec`) chce
   `0640` → `shpd-server doctor` hlásí ✗ hned poté, co aplikace poprvé
   zapíše do logu. `ErrorLogger` zakládá adresář s `0775` a soubor
   zapisuje přes `file_put_contents` s výchozím umaskem.
2. **Composer z apt na Ubuntu 24.04 (2.7.1)** pod PHP 8.5 vypisuje při
   každém spuštění stovky řádků `Deprecation Notice: Constant E_STRICT is
   deprecated…`. Funguje, ale nováček to čte jako rozbitou instalaci. Na
   26.04 je composer 2.9.5 bez potíží.

## Před implementací přečti

- `src/Core/Logging/ErrorLogger.php`
- `src/Core/Server/PermissionSpec.php` (položky `log/` a `shipard.log`)
- `docs/operations/permissions.md`, `docs/logging.md`
- `DEVELOPERS.md` (Požadavky, kapitola 2)

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D15** — log adresář a `shipard.log` vznikají s právy podle kontraktu
  (`0750` / `0640`), stejně jako adresáře DS (D14). Existující stav srovná
  `fix-permissions`.
- ✓ **D16** — nové instalace (vývojové i produkční) se dělají na **Ubuntu
  26.04**; 24.04 zůstává podporovaná pro stávající servery, které se
  postupně upgradují. Composer z apt na 24.04 se nemění — deprecation
  výpisy jsou neškodné a `DEVELOPERS.md` to řekne.

## Co je potřeba udělat

### 1. `ErrorLogger` (D15)

- Adresář logu zakládat s `0750` (ideálně přes existující pomocnou metodu
  z D14, pokud se hodí i pro cesty mimo DS — jinak lokálně, bez
  refaktoringu).
- Když soubor logu ještě neexistuje, po prvním zápisu (nebo založením
  předem) nastavit `0640`. Existující soubor neměnit — to je práce
  `fix-permissions`.
- Pozor na souběh FPM workerů a CLI: založení a `chmod` musí být
  bezpečné, když soubor mezitím vytvořil jiný proces (žádná chyba, žádné
  přepsání obsahu).
- Pokud log někde rotuje (logrotate šablona v repu, vlastní rotace),
  ověřit, že i nově vzniklý soubor dostane `0640`; pokud rotace v repu
  není, nic nepřidávat.

### 2. Dokumentace (D16)

- `DEVELOPERS.md`, Požadavky: pro **novou** instalaci doporučit Ubuntu
  26.04; 24.04 je podporovaná pro stávající servery.
- `DEVELOPERS.md`, kapitola 2 nebo „Něco nefunguje?“: na 24.04 vypisuje
  composer z apt pod PHP 8.5 mnoho řádků `Deprecation Notice … E_STRICT` —
  neškodné, instalace je v pořádku.
- `docs/operations/production.md`, Předpoklady: totéž doporučení (nové
  servery 26.04).

## Commit strategie

1. `logging: adresář a soubor logu s právy podle kontraktu (#96 D15)` —
   kód + test
2. `docs: nové instalace na Ubuntu 26.04, composer na 24.04 (#96 D16)` —
   `DEVELOPERS.md`, `production.md`, hlavička tasku, `tasks/README.md`
   (oblast „Server, CLI a provoz“) + `python3 scripts/tasks-index.py`

## Ověření

- PHPUnit úzce (`--filter` na test `ErrorLogger`)
- VM bez `shipard.log`: první request / CLI příkaz, který loguje →
  `stat -c '%a' /opt/shipard/log/shipard.log` = `640`, `doctor` bez ✗

## Hotovo když

- [ ] nově vzniklý `shipard.log` má `0640`, nově vzniklý adresář logu `0750`
- [ ] souběžné založení logu nehází chybu
- [ ] `DEVELOPERS.md` a `production.md` doporučují pro nové instalace 26.04
      a zmiňují deprecation výpisy composeru na 24.04
- [ ] hlavička tasku a `tasks/README.md` aktualizované
