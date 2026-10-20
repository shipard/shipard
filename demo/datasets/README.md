# Demo datové sady

Sady s „žitými“ daty pro ukázku aplikace (přehledy, saldokonto, výsledovka,
Dashboard) a pro screenshoty a videa webu (`shpd-web/docs/demo-scenare.md`).
Formát sady je týž jako u `dataset-dump` / `dataset-seed`
([`docs/datasets.md`](../../docs/datasets.md)); testovací sady z korpusu
dokladů (bez vazby na video) leží v [`tests/datasets/`](../../tests/datasets/).

Sada vzniká **generátorem z ručně psaného příběhu firmy** (#131 D3–D7):
příběh jsou pravidla, generátor je deterministicky rozvine na doklady,
zprávy a dokumenty. Výstupní složka sady se **needituje ručně** — změna
patří do příběhu a sada se přegeneruje. Vše je fiktivní od začátku: žádný
dump ani anonymizace ostrého zdroje dat, video i tento adresář jsou veřejné.

```
demo/datasets/
  build.py                 # vstupní bod generátoru
  templates/               # HTML šablony dokumentů spisovny (smlouva, pojistka, nájem)
  stories/<název>/         # příběh firmy — ručně psané soubory (viz níže)
    build-state.json       # hash HTML → PDF; generátor renderuje jen změněné
  <název>/                 # vygenerovaná sada (manifest, setup/, persons/, items/, docs/, registry/, mail/)
```

## Sady

| Sada | Příběh | Obsah |
|---|---|---|
| `it-servis/` | `stories/it-servis/` | Zelený Terminál s.r.o., malá IT firma, měsíční plátce DPH, leden–říjen 2026: 14 odběratelů (2 SK, 1 DE v EUR s přenesením daňové povinnosti), 123 vydaných faktur a zálohová faktura (4 koncepty v měsíci kotvy, 3 placené hotově), 48 přijatých faktur od 7 dodavatelů jako zpracované zprávy s PDF, 39 prodejek, 20 výdajových pokladních dokladů, 9 mzdových dokladů, 6 dokumentů spisovny (dvě smlouvy končí do 30 dnů), 3 otevřené návrhy faktur a e-mail s lhůtou pro kartu K vyřízení |

## Generování

```bash
python3 demo/datasets/build.py                                   # stories/it-servis → it-servis/
python3 demo/datasets/build.py --anchor 2027-01-20 --out /tmp/it-servis-posun
python3 demo/datasets/build.py --verify-identities               # ARES / VIES (síť)
```

- Druhý běh beze změny příběhu nic nezapíše (`git status` čistý): JSON se
  zapisuje jen při změně bajtů, PDF se renderuje přes Gotenberg
  (`GOTENBERG_URL`, `docs/render.md`) jen když se změní HTML nebo soubor
  chybí — hash drží `stories/<název>/build-state.json`. Chromium nedává
  bajtově stejné PDF, proto se PDF commitují a cache je součást repa.
- `--anchor` posune celou sadu v čase (D7): data příběhu jsou relativní
  ke kotvě (měsíc vůči měsíci kotvy, den v měsíci, splatnost ve dnech),
  `manifest.created` = kotva o půlnoci UTC, registrace DPH od prvního dne
  dat. Posunutá sada patří do dočasné složky (`--out`), do repa jen sada
  s výchozí kotvou. Posun o jiný počet měsíců než násobek tří mění počet
  čtvrtletních dokladů (čtvrtletí jsou kalendářní).
- `--verify-identities` ověří fiktivní identity: IČO v ARES neexistují,
  názvy mají 0 zásahů, DIČ ve VIES neplatná; při úspěchu zapíše
  `verifiedAt` do `partners.jsonc`. VIES pro CZ bývá přetížené
  (`MS_MAX_CONCURRENT_REQ`) — pak příkaz skončí kódem 2 a nic nezapíše.
  CZ DIČ s volným IČO platné být nemůže; rozhodující je ARES a VIES pro
  zahraniční DIČ.

Generátor žije v balíku `invoicegen` (`tools/invoice-generator/`,
`README.md` tamtéž): `story.py` (loader a validace), `expand.py`
(rozvinutí), `doccalc.py` (výpočet jako `DocDocument`), `dumpshape.py`
(tvar dumpu), `demo.py` (sestavení). Testy: `cd tools/invoice-generator &&
python3 -m unittest`.

## Formát příběhu (`stories/<název>/`)

Čísla jen celá nebo s desetinnou tečkou (loader je čte jako `Decimal`).
Data relativně: `month` = posun od měsíce kotvy (0 = měsíc kotvy,
záporné = minulost), `day` = den v měsíci (delší než měsíc se ořízne),
`dueDays`, `afterAnchorDays`, `daysBeforeAnchor`.

| Soubor | Obsah |
|---|---|
| `story.jsonc` | `name`, `title`, `description`, `anchor`, `monthsBefore`; `own` — identita vlastní firmy (kanonický tvar jako `tests/corpus/cz/identities.jsonc`) + `language`, `bankAccountCode`, `cashDeskCode` (kódy ze `setup/`); `rates` — kurz cizí měny per měsíc (D13); `invoicing` — den vystavení, splatnost, texty paušálů; `billing[]` — opakovaná fakturace per odběratel (`every` month / quarter / once, `item`, `price`, `qty`, `priceFrom`, `docType: proformaIssued`, `conceptInAnchorMonth`); `adhoc[]` — jednorázové faktury (řádky s `qty` nebo `hours: [lo, hi]`, `payment: cash`, `state: concept` jen v měsíci 0); `cashRegister` — dny prodejek, zboží, rozsah kusů; `cashExpenses[]` — výdajové pokladní doklady na položky z nabídky osnovy; `payroll` — den, částky (`figures`, přepisy per měsíc), kontační řádky (loader hlídá MD = D) |
| `partners.jsonc` | `verifiedAt`; `customers` (identita + `language`, `currency`, `vatTreatment` standard / reverseCharge, kontakt s `sendPurposes`, `payment` = chování plateb — D8, fáze 1 nepromítá); `suppliers` (identita + `template` a `accent` šablony `demo/invoices/templates/`, `docNumber` vzor čísla dodavatele, `invoices` pravidlo faktur: `every` / `months`, `day`, `dueDays`, `anchorMonth` processed / open / skip, `period: previousMonth`, `rounding: czk`, řádky s kódem nabídky účetních položek) |
| `items.jsonc` | vlastní prodejní položky: kód, název, druh (`service` / `stock`), jednotka (`core.units`), cena, sazba, výnosový účet. Účetní položky pro přijaté faktury přidá seed z nabídky osnovy (`provision.accountingItems: "all"`) |
| `registry.jsonc` | dokumenty spisovny: druh, šablona z `templates/`, šanon, partner, `refNumber`, `created` {month, day, time}, `validFrom`, `validTo` ({afterAnchorDays} / {years} / {month, day}), `summary`, `kindFields` podle druhu (promote klíče doplní generátor) |
| `mail.jsonc` | zprávy k řešení bez dokladu (`kind: other`) s pozorností (`attention`, `actionNote`, `dueInDays` od kotvy); návrhy faktur vznikají z `anchorMonth: open` u dodavatelů |
| `setup/*.jsonc` | číselníky ve tvaru dumpu (všechny sloupce vč. `null`): nastavení, bankovní účet, pokladna, registrace DPH, šanony, střediska (prázdné), schránka. `@period.start` v datu = první den dat |

Pravidla, která loader vynucuje: kontrolní součty IČO / účtů / IBAN / DE
DIČ, domény jen `.example` (D15), žádný překryv s identitami dema
(`demo/invoices/suppliers.jsonc`) ani korpusu (`tests/corpus/cz/identities.jsonc`),
odkazy partner ↔ položka ↔ nabídka osnovy, měsíce v období, vyvážené
mzdy, a **jednoznačné české řazení jmen** — o pořadí souborů osob
(`ORDER BY full_name` v `utf8mb4_czech_ci`) nesmí rozhodovat jen diakritika,
velikost písmen ani interpunkce; takové jméno autor přejmenuje.

## Jak sadu naseedovat a ověřit

Z vývojářského dashboardu: formulář **Nový zdroj dat** (pole **Data**,
skupina **Demo sady**) nebo akce **Nahrát datovou sadu** nad existujícím
volným zdrojem dat; sekce Testování demo sady nevypisuje. Z terminálu:

```bash
php bin/shpd-server ds-create --name "Demo IT servis" --language cs --country cz --no-cron
cd /opt/shipard/data-sources/<id>
php /cesta/k/repu/bin/shpd-ds dataset-seed /cesta/k/repu/demo/datasets/it-servis -y
php /cesta/k/repu/bin/shpd-ds dataset-dump /tmp/it-servis-dump --force --name it-servis \
    --title "Demo — IT servis" --description "<description z manifestu>"
diff -r /cesta/k/repu/demo/datasets/it-servis /tmp/it-servis-dump   # jen manifest.created
```

Sada je s dumpem naseedovaného zdroje dat **bajtově shodná** kromě
`manifest.created` (D10); každý další rozdíl je chyba generátoru, ne dumpu.
Očekávaná varování seedu: založení účetních položek z nabídky a chybějící
IČO německého odběratele. Dump varuje per EUR doklad, že kurz není
v lístcích — kurz nese doklad sám (D13), lístky sada nemá.

Výchozí schránka: `ds-upgrade` ji zakládá s adresou podle ID zdroje dat,
sada ji přepíše pevnou `demo-it-servis@shipard.email`, aby dump vyšel
stejně na každém zdroji dat. Demo zdroj dat nemá přijímat skutečnou poštu;
kdo to potřebuje, opraví adresu v Nastavení.

## Co se nepřenáší / fáze 2

Bankovní výpisy, zakázky a majetek přidá fáze 2 (#131 D2) — chování
plateb odběratelů (`payment` v `partners.jsonc`) se pak promítne do
výpisů; dnes zůstávají všechny faktury kromě hotovostních otevřené.
Úhrady řádkem pokladního dokladu sada nemá (řádkový partner se do formátu
nepřenáší, `docs/datasets.md` § 4).
