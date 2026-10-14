# Testování F1: korpus testovacích dokladů s očekávaným výsledkem

**Stav:** hotovo — implementováno 2026-10-14 (7 commitů, 52 buněk, identity ověřené v ARES a VIES 2026-10-14); odchylky od zadání v oddílu *Odchylky při implementaci*

## Kontext / Cíl

Issue #103 (Nové testování) rozdělilo testování na dvě nezávislé poloviny
spojené jedním formátem (#103 D1, D3):

- **AI polovina** porovnává výstup analýzy dokladu s očekávaným výsledkem,
- **účetní polovina** bere očekávaný výsledek jako „ideální analýzu“
  a ověřuje zaúčtování, přiznání DPH a kontrolní hlášení.

Obě potřebují totéž: korpus dokladů, u kterých přesně víme, co je na papíře.

**Cíl F1:** generovaný korpus fiktivních přijatých dokladů v
`tests/corpus/cz/` — pro každý doklad a každý profil vlastní firmy PDF
a očekávaný výsledek v kanonickém formátu `shpd.docs.document.v1`.
Zdrojem pravdy je ručně psaný datový soubor dokladu, PDF i očekávaný
výsledek z něj vyrobí generátor (#103 D4, D13). Částky počítá generátor
sám, nezávisle na výpočtu Shipardu (D5) — chyba výpočtu v Shipardu tak
nemůže souhlasit sama se sebou.

F1 je jen obsah. Sestavení datových sad (profil × korpus, seed s ideální
analýzou) je F2, ověření zaúčtování F3, porovnávač a evaluace AI F4.

## Návaznost

- **#103** — rozhodnutí D1–D18, fáze F1–F8; D12–D18 jsou rozhodnutí tohoto
  tasku (oddíl na konci)
- **`tasks/demo-invoices.md`** — generátor demo faktur, ze kterého F1
  vychází (identity, Gotenberg, kontrolní součty)
- **#119 fáze 1** — generátor ISDOC; podoby „PDF + ISDOC“ dostanou soubor
  ISDOC až s ním (D18). F1 na #119 nečeká.
- **#118** — normalizace a validace údajů v produkční cestě; převezme tvary
  hodnot z oddílu *Tvary hodnot* (D15)
- navazuje **F2** (profily a sestavení sad) a **F4** (evaluace) — obě čtou
  `expected/` a `projection.jsonc`

## Před implementací přečti

- `docs/exchange-format.md` §5 (top-level), §6 (Party), §7 (Row), §8.4
  (odvození kódu DPH přijatých dokladů — proč v očekávání stačí signály
  `vat.place`, `vat.reverseCharge`, `vat.supplyKind`), §9 (`_resolve.contentTag`)
- `modules/core/mail/profiles/czech_general.jsonc` → `prompt_template`,
  oddíly PRAVIDLA a PRAVIDLA PRO ÚČTENKY (prompt v4.7.1) — co má AI vracet;
  pravidla odvozených polí v projekci se z nich přebírají
- `modules/core/exchange/config/contentTags.jsonc` — taxonomie štítků
  a atribut `crossBorderSupply`
- `demo/invoices/README.md`, `demo/invoices/build.py`, `tasks/demo-invoices.md`
- `docs/render.md` — Gotenberg
- `docs/datasets.md` §1 — konvence sad; pro odlišení: očekávaný výsledek
  **není** soubor sady a má vlastní pravidla zápisu

## Rozsah

**V rozsahu:**

- sdílený generátor `tools/invoice-generator/` (kód vytažený z
  `demo/invoices/build.py`); demo kolekce na něj převedená beze změny výstupu
- korpus `tests/corpus/cz/`: identity, datové soubory 10 variant × 2 podoby,
  šablony, build
- tři profily vlastní firmy a realistická matice variant × profil
- očekávaný výsledek (papírová projekce) per doklad × profil a definice
  projekce v `tests/corpus/projection.jsonc`
- tvary hodnot (D15)
- PHPUnit strážce korpusu
- dokumentace `docs/test-corpus.md`

**Mimo rozsah:**

- soubory ISDOC — vstupní bod dodá #119 fáze 1 a build je pak začne vyrábět
- ISDOC vložený do PDF
- nastavení profilů ve zdroji dat, sestavení sad, seed (F2)
- ověření zaúčtování (F3), porovnávač a evaluace (F4)
- normalizace v produkční cestě (#118)
- druhá vlna: dobropis, zálohová faktura s odpočtem zálohy, slovenské
  profily, identifikovaná osoba
- soukromé sady s reálnými doklady

## Struktura

```
tools/invoice-generator/
  README.md
  invoicegen/                  # jen standardní knihovna Pythonu
    jsonc.py                   # load_jsonc (z demo)
    checks.py                  # IČO, mod 11, IBAN, DIČ zemí EU
    compute.py                 # výpočty v Decimal (D5)
    render.py                  # HTML šablona → PDF přes Gotenberg
    expected.py                # papírová projekce + tvary hodnot
  tests/                       # python3 -m unittest
demo/invoices/                 # data, šablony i PDF beze změny;
                               # build.py jen importuje sdílený kód
tests/corpus/
  projection.jsonc             # definice papírové projekce (sdílí F1, PHPUnit, F4)
  cz/                          # země vlastní firmy (D7 — konvence)
    README.md                  # krátce + odkaz na docs/test-corpus.md
    build.py                   # vstupní bod
    identities.jsonc           # profily + dodavatelé, datum ověření
    templates/*.html           # šablony korpusu (vlastní, demo se nemění)
    data/NNNN-<slug>.jsonc     # zdroj dokladu — píše se ručně
    out/<profil>/NNNN-<slug>.pdf          # generované, commitované
    expected/<profil>/NNNN-<slug>.json    # generované, commitované, ručně se NEEDITUJÍ
    build-state.json           # sha256 vyrenderovaného HTML per výstup
```

**Profily** (klíč = název adresáře):

| Klíč | Vlastní firma | Na dokladech jako odběratel |
|------|---------------|-----------------------------|
| `vat-payer` | s.r.o., plátce DPH | název, adresa, IČO, DIČ |
| `non-payer` | s.r.o., neplátce DPH | název, adresa, IČO (DIČ se neuvádí) |
| `association` | spolek (z. s.), neplátce DPH | název, adresa, IČO (DIČ se neuvádí) |

Demo kolekce zůstává v `demo/invoices/` beze změny dat i šablon — je to
veřejný artefakt videa a její PDF se nepřegenerovávají. Šablony korpusu
jsou vlastní kopie a smí se měnit.

## Identity (`identities.jsonc`)

Pravidla jako u demo (`tasks/demo-invoices.md`), zpřísněná:

- všechny subjekty fiktivní a **odlišné od demo identit**;
- **CZ IČO** s validním kontrolním součtem, neexistující v ARES
  (`GET https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`
  → 404) a názvy s nulou zásahů (`POST …/ekonomicke-subjekty/vyhledat`,
  `{"obchodniJmeno": "…"}` → `pocetCelkem: 0`);
- **DIČ zemí EU** s platným kontrolním číslem podle pravidel země (DE:
  ISO 7064 MOD 11,10) a ve VIES neplatné
  (`GET https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{cc}/vat/{číslo}`
  → `isValid: false`);
- **domény jen v TLD `.example`** (RFC 2606) — nikdy nikomu nebudou patřit,
  e-maily a weby na dokladech se nemusí hlídat;
- česká čísla účtů s mod 11, IBAN odpovídá účtu, reálné kódy bank bez
  názvů bank; zahraniční IBAN s platnými kontrolními číslicemi;
- `verifiedAt` = datum ověření. Build kontroluje součty assertem;
  ARES a VIES jen `build.py --verify-identities` (síť, spouští se po změně
  identit). Oba registry jsou z vývojového stroje dostupné (ověřeno
  2026-10-14).

Potřeba: 3 profily, 7 CZ dodavatelů (varianty 1, 2, 3, 4, 7, 9, 10),
2 dodavatelé z DE (5, 6), 1 z USA (8). **Jeden dodavatel na variantu** —
obě podoby varianty jsou dva různé doklady téhož dodavatele.

Tvar záznamu — pole odpovídají Party z canonicalu, hodnoty už v tvaru
z oddílu *Tvary hodnot*:

```jsonc
{
	"verifiedAt": "2026-10-14",
	"profiles": {
		"vat-payer": {
			"name": "…", "country": "cz", "companyId": "…", "taxId": "CZ…", "vatId": "CZ…",
			"address": { "street": "…", "houseNumber": "…", "city": "…", "zip": "…", "country": "cz" },
		},
		// non-payer, association: taxId a vatId null
	},
	"suppliers": {
		"<klíč>": {
			"vatPayer": true,                     // neplátce → na dokladu „Nejsem plátce DPH“, bez DIČ
			"name": "…", "country": "de",
			"companyId": null, "taxId": "DE…", "vatId": "DE…",
			"courtRegistration": "…",             // null, když se na dokladu neuvádí
			"address": { "street": "…", "houseNumber": "…", "city": "…", "zip": "…", "country": "de" },
			"contact": { "name": null, "email": "…@….example", "phone": "+49…", "web": "www.….example" },
			"bankAccount": { "accountNumber": null, "iban": "DE…", "bic": "…" },
		},
	},
}
```

## Varianty a matice

Deset variant první vlny (D16), každá ve dvou podobách (D4): `pdf` (jen PDF)
a `pdfIsdoc` (PDF + ISDOC). Soubor `NNNN`: `0V01` = podoba `pdf`,
`0V02` = podoba `pdfIsdoc` varianty `V` (varianta 10 → `1001`, `1002`).

| V | Slug | Dodavatel | Měna | Šablona | DPH na dokladu (`vatTreatment`) | Štítek | Prověří |
|---|------|-----------|------|---------|-------------------------------|--------|---------|
| 1 | `domestic-goods` | CZ | CZK | `cs-a` | `standard` 21 % | `goods.stock` | extrakce řádků, jednotky m2/ks/bm, sleva na řádku |
| 2 | `domestic-two-rates` | CZ | CZK | `cs-b` | `standard` 21 % + 12 % | `office.supplies`, výjimky řádků 12 % `admin.memberships` | rekapitulace po sazbách, `rowExceptions` |
| 3 | `domestic-rounding` | CZ | CZK | `cs-c` | `standard` 21 %, zaokrouhlení na celé Kč | `premises.electricity` | `totalRounding`, období plnění |
| 4 | `domestic-reverse-charge` | CZ | CZK | `cs-a` | `reverseCharge` (stavební práce, „daň odvede zákazník“) | `premises.maintenance` | `reverseCharge`, `reverseChargeCode: "4"` |
| 5 | `eu-services` | DE | EUR | `en` | `reverseCharge` (čl. 196) | `it.hosting` | `intracom`, `supplyKind: services` |
| 6 | `eu-goods` | DE | EUR | `en` | `exemptIntraCommunity` (čl. 138) | `it.hardware` | `intracom`, `supplyKind: goods`, `reverseCharge: false` |
| 7 | `domestic-non-payer-supplier` | CZ neplátce | CZK | `cs-b` | `noVat` | `services.training` | `vat.mode: none`, bez rekapitulace |
| 8 | `third-country-services` | US | USD | `en` | `noVat` | `it.software` | `thirdCountry`, `supplyKind` bez zmínky o DPH, úhrada kartou |
| 9 | `receipt` | CZ | CZK | `receipt` | `pricesWithVat` 21 % | `vehicle.fuel` | účtenka: bez odběratele a splatnosti, `fromTotal`, `priceCalcMode: fromTotal` u PHM |
| 10 | `long-document` | CZ | CZK | `cs-c` | `standard` 21 % + 12 % | `goods.stock` | 40–60 řádků přes víc stran, úplnost řádků |

**Matice variant × profil (D17).** Identifikovaná osoba se v první vlně
neřeší (Shipard ji zatím nemusí umět) — varianty, kde by neplátce
identifikovanou osobou byl, jsou jen pro plátce.

| V | `vat-payer` | `non-payer` | `association` |
|---|-------------|-------------|---------------|
| 1–3, 7, 9, 10 | ✓ | ✓ | ✓ |
| 4 | ✓ | přepis: běžná faktura 21 %, bez přenesení | přepis jako `non-payer` |
| 5 | ✓ | — (identifikovaná osoba, druhá vlna) | — |
| 6 | ✓ | přepis: DPH dodavatele 19 %, bez DIČ odběratele, bez textu čl. 138 | přepis jako `non-payer` |
| 8 | ✓ | — (identifikovaná osoba, druhá vlna) | — |

Celkem 20 + 16 + 16 = **52 buněk** (PDF + očekávaný výsledek).

## Datový soubor dokladu (`data/NNNN-<slug>.jsonc`)

Rozšíření demo formátu. Píše se ručně, stručně; všechno odvoditelné
dopočítá generátor.

```jsonc
// Varianta 5, podoba pdfIsdoc — služba z EU s přenesením daňové povinnosti.
{
	"variant": "eu-services",
	"form": "pdfIsdoc",                 // "pdf" | "pdfIsdoc"
	"supplier": "<klíč dodavatele>",
	"docNumber": "INV-2026-0388",
	"paymentReference": null,            // VS; null = na dokladu není
	"dates": { "issue": "2026-09-02", "tax": "2026-08-31", "due": "2026-09-16",
	           "periodFrom": "2026-08-01", "periodTo": "2026-08-31" },
	"currency": "EUR",
	"vatTreatment": "reverseCharge",     // viz níže
	"payment": { "method": "bankTransfer", "specificSymbol": null, "constantSymbol": null },
	"contentTag": "it.hosting",
	"rows": [
		{ "text": "Managed Kubernetes cluster", "description": "Billing period 08/2026",
		  "supplierCode": "MK8S-M", "qty": 1, "unit": "ks", "unitPrice": 412.00, "vatPct": 0,
		  "supplyKind": "services" },
		{ "text": "Object storage", "qty": 1.25, "unit": "TB", "unitPrice": 18.40, "vatPct": 0,
		  "supplyKind": "services", "discountPct": 10 },
	],
	"rounding": "none",                  // "none" | "czk"
	"template": "en",
	"notes": { "onDocument": "…" },
	"skipProfiles": { "non-payer": "identifikovaná osoba — druhá vlna",
	                  "association": "identifikovaná osoba — druhá vlna" },
	"overrides": {},                     // { "<profil>": { …částečný datový soubor… } }
}
```

Pole řádku: `text`, `description` (volitelné), `supplierCode`, `ean`
(volitelné), `qty`, `unit`, `unitPrice`, `vatPct` (`null` u `noVat`),
`discountPct` (volitelné), `supplyKind` (u dodavatele mimo ČR povinné),
`reverseChargeCode` (jen tuzemské přenesení), `tag` (výjimka štítku řádku),
`totalPrice` (jen s `priceCalcMode: "fromTotal"`).

**`vatTreatment`** — jak dokument pojednává DPH; odvodí se z něj
`vat.mode`, `vat.reverseCharge`, rekapitulace a text na dokladu:

| Hodnota | Na dokladu | `vat.mode` | `reverseCharge` | Rekapitulace |
|---------|-----------|-----------|-----------------|--------------|
| `standard` | sazby, ceny bez DPH | `fromBase` | `false` | ano |
| `pricesWithVat` | sazby, ceny s DPH (účtenka) | `fromTotal` | `false` | ano |
| `reverseCharge` | 0 % + text o přenesení / reverse charge | `fromBase` | `true` | ano (0 %) |
| `exemptIntraCommunity` | 0 % + text o osvobozeném dodání do EU (čl. 138) | `fromBase` | `false` | ano (0 %) |
| `noVat` | DPH vůbec (neplátce, třetí země) | `none` | `false` | ne |

Sazby se validují podle země dodavatele (`cz`: 21, 12, 0; `de`: 19, 7, 0).

**`overrides`** se slučuje do datového souboru do hloubky (objekty se
slučují, pole a skaláry nahrazují). **`skipProfiles`** = vynechaná buňka
matice, hodnota je důvod (vypisuje ho build a `docs/test-corpus.md`).

## Výpočet (D5)

- `Decimal`, zaokrouhlení `ROUND_HALF_UP` na setiny; žádný `float`
  ve výpočtu.
- Řádek: `qty × unitPrice × (1 − discountPct/100)`, zaokrouhleno.
  S `priceCalcMode: "fromTotal"` je `totalPrice` z dat autoritou;
  build ověří, že se od `qty × unitPrice` liší o méně než 1,00.
- Rekapitulace po sazbách „z hlavičky“ (`calcSource: header`):
  - ceny bez DPH: základ = Σ řádků sazby, daň = základ × sazba;
  - ceny s DPH: celkem = Σ řádků sazby, daň = celkem × sazba / (100 + sazba),
    základ = celkem − daň.
- `noVat`: bez rekapitulace, částka = Σ řádků.
- Zaokrouhlení celkové částky `rounding: "czk"` jako demo (assert pásma
  0,01–1,00).
- Integritní asserty jako demo (Σ řádků = Σ rekapitulace = součty).
- Cizí měna: vše v měně dokladu; kurz na dokladech korpusu není.

## Očekávaný výsledek — papírová projekce (D14)

`expected/<profil>/NNNN-<slug>.json`:

- platný canonical `shpd.docs.document.v1` (projde `SchemaValidator`);
- obsahuje **vždy všechna pole projekce**; `null` = na dokladu není;
  pole mimo projekci chybí;
- klíče v pořadí projekce, JSON s odsazením 2 mezery, UTF-8 bez escapování,
  `\n` na konci; čísla jako JSON number (částky zaokrouhlené na setiny);
- liší se od konvence sad (tam se `null` vynechává) — očekávání není soubor sady.

**Projekce** (`tests/corpus/projection.jsonc` — jediný zdroj pravdy pro
generátor, PHPUnit strážce i porovnávač F4):

- top-level: `format`, `formatVersion` (`"1.0"`), `docType`, `docNumber`,
  `selfParty`, `supplier`, `customer`, `dates` {`issueDate`, `dueDate`,
  `taxPointDate`, `periodFrom`, `periodTo`}, `currency`, `exchangeRate`,
  `vat` {`mode`, `place`, `reverseCharge`}, `payment` {`method`,
  `paymentReference`, `specificSymbol`, `constantSymbol`}, `notes`
  {`onDocument`}, `rows[]`, `vatRecap[]`, `totals` {`totalBase`,
  `totalVat`, `totalAmount`, `totalRounding`}, `_resolve` {`contentTag`
  {`tag`, `rowExceptions[]` {`rowIndex`, `tag`}}}
- Party: `name`, `country`, `companyId`, `taxId`, `vatId`,
  `courtRegistration`, `address` {`street`, `houseNumber`, `city`, `zip`,
  `country`}, `contact` {`name`, `email`, `phone`, `web`}, `bankAccount`
  {`accountNumber`, `iban`, `bic`}
- Row: `rowKind`, `item` {`supplierCode`, `ean`, `name`, `description`},
  `unit`, `quantity`, `unitPrice`, `totalPrice`, `priceCalcMode`,
  `discountPct`, `discountAmount`, `vat` {`pct`, `supplyKind`,
  `reverseChargeCode`}
- VatRecapEntry: `vatPct`, `base`, `tax`, `total`

**Mimo projekci** (systémová pole a metadata AI): `source`, `docText`,
`dates.accountingDate`, `dates.vatObligationDate`,
`vat.registrationCountry|recapSource|calcSource|controlStatementMode`,
`rows[].vat.code`, `vatRecap[].vatCode|isReversePair`,
`rows[].orderPos|operation|computed|description` a účetní pole řádku,
`item.ourCode|sku`, `paymentTermDays`, `bankAccount.currency`,
`address.cityPart|registryCode|displayLine|displayBlock`, `balanceParty`,
`dimensions`, `attachments`, `notes.internal`, z `_resolve` vše kromě
`contentTag.tag` a `contentTag.rowExceptions`.

**Odvozená pole** — pravidla odpovídají promptu v4.7.1:

- `docType`: `invoiceReceived` (i účtenka); `selfParty`: `customer`
- `customer`: identita profilu; u účtenky `null`
- `vat.mode` a `vat.reverseCharge` podle `vatTreatment`
- `vat.place`: podle DIČ dodavatele (prefix `CZ` → `domestic`, prefix
  země EU → `intracom`); bez DIČ podle země sídla (`cz` → `domestic`,
  EU → `intracom`, jinak `thirdCountry`)
- `rows[].vat.supplyKind`: u dodavatele mimo ČR na každém řádku, jinak `null`
- `rows[].vat.pct`: sazba z dokladu (i 0); u `noVat` `null`
- `rows[].vat.reverseChargeCode`: jen u tuzemského přenesení
- `dueDate` a `customer` u účtenky `null`; `payment.method` podle dat
- bez rekapitulace (`noVat`): `vatRecap` `null`, `totals.totalBase`
  a `totalVat` `null`, `totalAmount` = částka k úhradě, `totalRounding` `0`
- `_resolve.contentTag.rowExceptions`: `[{rowIndex (od 0), tag}]` z `tag`
  řádků, prázdné pole, když výjimky nejsou; štítky musí existovat
  v taxonomii
- `exchangeRate`: `null` (kurz na dokladech korpusu není)

**Rovnocenné tvary** (zapsat do `docs/test-corpus.md`, implementuje
porovnávač F4): na straně výstupu `null` ≡ chybějící klíč;
`totalRounding` `0` ≡ `null`; prázdné pole (`rowExceptions`, `vatRecap`)
≡ `null`; objekt se všemi listy `null` (`contact`, `bankAccount`,
`address`, `customer`) ≡ `null`.

## Tvary hodnot (D15)

Kanonický tvar hodnot v očekávaném výsledku. Vychází z toho, co už dnes
dělá produkce (země malými v `IsdocReader`, `CanonicalParty`, promptu;
ulice a číslo zvlášť). Oddíl je zároveň vstup pro #118 — do jeho
dokončení použije porovnávač stejná pravidla na obě strany.

| Hodnota | Tvar | Příklad |
|---------|------|---------|
| země (strana i adresa) | ISO 3166-1 alpha-2 malými | `cz`, `de`, `us` |
| měna | ISO 4217 velkými | `CZK`, `EUR` |
| PSČ | `cz`, `sk`: 5 číslic bez mezery; jinde jak na dokladu, bez okrajových mezer | `76001`, `10115`, `94105` |
| ulice a číslo | kde číslo následuje ulici (`cz`, `de`): `street` bez čísla, `houseNumber` jak na dokladu včetně lomítka; kde číslo ulici předchází (`us`): celý řádek v `street`, `houseNumber` `null` | `Pilinová` + `214/3`; `500 Market Street, Suite 200` + `null` |
| obec | bez PSČ a bez zkratky státu (`us`) | `Zlín`, `San Francisco` |
| IČO | 8 číslic včetně vedoucích nul | `05123456` |
| DIČ / VAT ID | velkými, s prefixem země, bez mezer; u EU `taxId` = `vatId` | `CZ52654150`, `DE123456789` |
| číslo účtu (`cz`) | `[předčíslí-]číslo/kód banky`, bez vedoucích nul, bez mezer | `19-2000145399/0800` |
| IBAN | velkými, bez mezer | `CZ6508000000192000145399` |
| BIC | velkými | `GIBACZPX` |
| e-mail | malými | `fakturace@dodavatel.example` |
| telefon | `+` a číslice, bez mezer | `+420577123456` |
| web | malými, bez schématu a koncového lomítka | `www.dodavatel.example` |
| datum | ISO 8601 | `2026-08-25` |
| částka | číslo zaokrouhlené na setiny | `10330.58` |
| jednotka, názvy, texty | jak na dokladu, bez okrajových mezer | `m2`, `ks` |

Na PDF se hodnoty tisknou tak, jak je tisknou skutečné doklady (PSČ
s mezerou, IBAN po čtveřicích, telefon s mezerami) — rozdíl mezi tiskem
a kanonickým tvarem je přesně to, co má zachytit normalizace (#118).

## Šablony korpusu

`cs-a`, `cs-b`, `cs-c` (kopie demo `a`/`b`/`c`), `en` (anglická faktura
pro DE a US) a `receipt` (účtenka, šířka 80 mm). Rozšíření proti demo:

- blok odběratele bez DIČ a varianta bez odběratele (účtenka);
- sloupec slevy jen tehdy, když ji některý řádek má;
- měna jinak než Kč (kód nebo symbol podle šablony);
- texty k DPH podle `vatTreatment` (přenesení, čl. 196, čl. 138,
  „Nejsem plátce DPH“) a tiskový tvar hodnot (PSČ s mezerou, IBAN po
  čtveřicích);
- období plnění;
- vícestránkový doklad: opakovaná hlavička tabulky, součty a rekapitulace
  na poslední straně, číslování stran.

## Build

`python3 tests/corpus/cz/build.py [--only NNNN] [--force] [--verify-identities]`

1. Validace identit (součty), datových souborů (povinná pole, známé
   varianty, profily, šablony, sazby a štítky — taxonomie se čte
   z `modules/core/exchange/config/contentTags.jsonc`).
2. Pro každou buňku matice: výpočet, očekávaný výsledek (zapisuje se vždy —
   je deterministický), HTML a jeho sha256.
3. PDF se renderuje jen tehdy, když chybí, hash HTML se liší od
   `build-state.json`, nebo s `--force`. PDF z Chromia není bajtově
   deterministické — přegenerování všeho by dělalo šum v diffech.
4. Výpis per buňka: součty, „PDF beze změny / přegenerováno“; u podoby
   `pdfIsdoc` řádek „ISDOC: čeká na generátor (#119)“; vynechané buňky
   s důvodem.

Gotenberg jako demo (`GOTENBERG_URL`). Bez závislostí mimo standardní
knihovnu.

## Strážce korpusu (PHPUnit)

`tests/Unit/Corpus/CorpusExpectedTest.php` — bez sítě a bez Gotenbergu:

- každý `expected/**/*.json` projde `SchemaValidator` proti
  `shpd.docs.document.v1`;
- má přesně klíče z `projection.jsonc` (žádný nechybí, žádný nepřebývá);
- štítky (`tag` i výjimky) jsou v taxonomii;
- aritmetika rekapitulace: `base + tax = total`, `tax = base × pct`
  (±0,01), Σ řádků proti rekapitulaci podle `vat.mode`;
- tvary hodnot z oddílu *Tvary hodnot* jako regulární výrazy per pole —
  pravidla D15 se tím poprvé zapíšou jako kód;
- ke každému očekávanému výsledku existuje PDF a každá buňka matice
  (data × profil bez `skipProfiles`) má očekávaný výsledek.

## Kroky (commity)

1. **Sdílený generátor.** `tools/invoice-generator/` z `demo/invoices/build.py`,
   demo `build.py` jen importuje. Vyrenderované HTML čtyř demo faktur
   musí být bajtově shodné s HTML před refaktoringem (jednorázové
   porovnání); demo PDF se znovu necommitují.
2. **Projekce, výpočet a tvary.** `tests/corpus/projection.jsonc`,
   `expected.py`, rozšíření `compute.py` (`vatTreatment`, sleva,
   ceny s DPH, `fromTotal`, sazby podle země, cizí měna); unit testy
   generátoru (`python3 -m unittest` v `tools/invoice-generator/`).
3. **Identity.** `identities.jsonc`, kontroly součtů, `--verify-identities`
   proti ARES a VIES. Když registry nejsou dostupné, krok se zastaví
   a nahlásí to — identity se neoznačí jako ověřené.
4. **Šablony korpusu.**
5. **Data a výstupy.** 20 datových souborů, build, commit `out/`,
   `expected/`, `build-state.json`.
6. **Strážce korpusu** (PHPUnit).
7. **Dokumentace.** `docs/test-corpus.md`, odkazy, hlavička tasku.

## Hotovo když

- [x] `python3 demo/invoices/build.py` dává stejné HTML jako před refaktoringem
- [x] `python3 tests/corpus/cz/build.py` projde; druhý běh bez `--force`
      nepřegeneruje žádné PDF
- [x] 20 datových souborů, 52 buněk matice s PDF a očekávaným výsledkem
- [x] identity ověřené v ARES a VIES, datum ve `verifiedAt`
- [x] PDF prohlédnutá: každá šablona, dlouhý doklad přes víc stran, účtenka
- [x] `python3 -m unittest` v `tools/invoice-generator/` zelený
- [x] `vendor/bin/phpunit --filter CorpusExpectedTest` zelený
- [x] `docs/test-corpus.md`: struktura, datový soubor, `vatTreatment`,
      matice s důvody vynechání, projekce, odvozená pole, rovnocenné tvary,
      tvary hodnot, postup „jak přidat doklad“
- [x] `docs/README.md` odkazuje na `docs/test-corpus.md`;
      `demo/invoices/README.md` zmiňuje sdílený generátor
- [x] `docs/exchange-format.md` §5 a §6: příklady zemí malými písmeny
      (`"cz"`) v souladu s promptem, `IsdocReader` a `CanonicalParty`
- [x] hlavička tasku aktualizovaná, `python3 scripts/tasks-index.py`

Doporučené, neblokující (člověk nebo Claude v chatu): jedno PDF z každé
varianty projít analýzou na volném dev zdroji dat a porovnat očima
s `expected/` — ověří, že doklady jsou pro model realistické. Stojí
volání AI.

## Pasti

- **Očekávaný výsledek se nikdy needituje ručně.** Změna = úprava
  datového souboru nebo generátoru a nový build.
- **Demo se nemění.** Šablony a data demo jsou vázané na video; korpus
  má vlastní kopie šablon. Identity se nesmí překrývat.
- **Pole projekce nechybí.** Chybějící klíč by znamenal „neporovnávat“ —
  u generovaného korpusu to vždy znamená chybu (hlídá PHPUnit).
- **Pravidla odvozených polí sledují prompt.** Když se prompt změní
  (např. pravidlo `vat.reverseCharge`), musí se upravit generátor
  a korpus přegenerovat — jinak evaluace měří proti zastaralé pravdě.
- **`exemptIntraCommunity` ≠ `reverseCharge`.** Text čl. 138 (osvobozené
  dodání) podle promptu `vat.reverseCharge` nezapíná; samovyměření
  u zboží z EU zapne místo plnění (`docs/exchange-format.md` §8.4).
- **Účtenka v podobě `pdfIsdoc`** je v pořádku — ISDOC zná zjednodušený
  daňový doklad jako samostatný typ dokumentu.
- **Žádný `float` ve výpočtu.** Jen `Decimal`; na `float` se nepřevádí
  ani při zápisu JSON (vlastní serializace čísel).

## Odchylky při implementaci

- `vatRecap` u dokladu bez DPH je `[]`, ne `null` — schéma formátu
  u polí `null` nedovolí (očekávání musí projít `SchemaValidator`);
  rovnocenné tvary o to rozšířené. `null` jako celek smí být jen
  `customer` (`nullableNodes` v projekci).
- Moduly generátoru navíc k seznamu: `corpus.py` (datový soubor, přepisy,
  matice) a `registries.py` (ARES / VIES) — aby byly testovatelné
  z `tools/invoice-generator/tests/`.
- `overrides` umí alias: hodnota = jméno jiného profilu přebírá jeho
  přepis (`"association": "non-payer"`), jak říká matice.
- `rows[].discountAmount` je vždy `null` (šablony tisknou slevu v %);
  jednotky se tisknou doslova z dat (demo mapování `m2 → m²` zůstává demu);
  `notes.onDocument` nese jen volnou poznámku, věta k DPH je blok šablony.
- Tvary navíc k tabulce: `ean`, symboly (jen číslice), `sk` k `cz`
  u PSČ a IČO; klíče jen pro generátor v identitách (`accent`,
  `print.state` pro tisk americké adresy) do očekávání nejdou.
- Varianta 10 je velkoobchod provozních potřeb (úklid 21 % + potraviny
  12 % jako `people.catering`) — železářství by druhou sazbu nemělo.
- Vícestránkový doklad: okraje přes `@page`, číslo strany v `@bottom-right`
  (Chromium 149 to umí, ověřeno v PDF); účtenka s pevnou výškou 200 mm.

## Rozhodnutí k designu (potvrzená)

- ✓ **D12 — Umístění a generátor.** Korpus v `tests/corpus/` (pod
  adresářem země, D7), generátor vytažený do `tools/invoice-generator/`,
  demo beze změny výstupu, Python kvůli D5.
- ✓ **D13 — Datový soubor je ruční vstup, očekávaný výsledek je výstup
  buildu.** Výstupy se commitují; PDF se přegeneruje jen při změně HTML.
- ✓ **D14 — „Na dokladu není“ bez rozšíření formátu.** Očekávání je úplná
  papírová projekce: `null` = na dokladu není, chybějící klíč = neporovnává
  se (jen pro ručně psaná částečná očekávání v soukromých sadách).
- ✓ **D15 — Kanonický tvar hodnot stanoví F1** (oddíl *Tvary hodnot*),
  převezme ho #118; do té doby porovnávač normalizuje obě strany.
- ✓ **D16 — Varianty první vlny** podle tabulky. Dobropis a zálohová
  faktura s odpočtem zálohy ve druhé vlně.
- ✓ **D17 — Realistická matice variant × profil** s přepisy podle
  profilu. Identifikovaná osoba se v první vlně neřeší; varianty 5 a 8
  jsou jen pro plátce.
- ✓ **D18 — ISDOC ve F1 nečeká.** Podoby `pdfIsdoc` mají PDF a úplný
  očekávaný výsledek hned; soubor ISDOC (samostatná příloha) přidá
  build po první fázi #119. Vložení do PDF později.
