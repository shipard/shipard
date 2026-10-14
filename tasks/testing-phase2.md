# Testování F2: profily a datové sady z korpusu

**Stav:** naplánováno — design schválen 2026-10-14 (#103 D19–D25), čeká na implementaci

## Kontext / Cíl

F1 (`tasks/testing-phase1.md`) vyrobila korpus `tests/corpus/cz/`: pro každý
doklad a profil vlastní firmy PDF a očekávaný výsledek v canonicalu. F2
z něj sestaví **datové sady** — jednu na profil — které jde naseedovat
příkazem `dataset-seed` do čistého zdroje dat.

Každý doklad korpusu je v sadě **došlá zpráva s PDF a s ideální analýzou**:
očekávaný výsledek vložený jako snapshot analýzy (*snapshot mode*, #40 D2).
Zpráva je ve stavu Analyzováno a návrh dokladu čeká na Použít — bez
jediného volání AI. Sada tak slouží:

- **F3** — apply všech návrhů → doklad, deník, přiznání ≟ očekávání,
- **testerovi** — ruční proklikání návrhů a dokladů na realistických datech,
- **F4** — zdroj zpráv pro evaluaci (PDF a očekávání pohromadě).

## Návaznost

- **#103** — rozhodnutí D1–D25; D19–D25 jsou rozhodnutí tohoto tasku
  (oddíl na konci)
- **`tasks/testing-phase1.md`**, `docs/test-corpus.md` — korpus, identity,
  matice, projekce
- **`tasks/dataset-phase1.md`**, `docs/datasets.md` — formát sad, seed,
  snapshot mode
- **#121** — kurzy ČNB; F2 je nepotřebuje, F3 na nich u dokladů v cizí měně
  závisí (D25)
- **#119** — generátor ISDOC; až bude, sestavení přidá ISDOC jako druhou
  přílohu zpráv v podobě `pdfIsdoc`
- `docs/ds-setup.md` D5 (plátcovství = registrace DPH), D18 (nabídky
  položek jsou jednorázové akce, ne provisionery)

## Před implementací přečti

- `docs/datasets.md` celý — struktura sady, `setup/`, `shpd.mail.incomingMessage.v1`,
  průběh `dataset-seed`, konvence JSON
- `docs/test-corpus.md` — korpus, identity, matice, projekce
- `modules/core/mail/schemas/shpd.mail.incomingMessage.v1.json`,
  `modules/core/mail/tables/core_mail_message_analyses.md` (status,
  resolution, `canonical_json`, `content_tag`)
- `modules/core/exchange/src/Dataset/` — `DatasetManifest`, `DatasetSeeder`,
  `DatasetPreflight`; `modules/core/mail/src/Dataset/MailSeeder.php`
- `src/Api/Controller/SetupController.php` → `generateAccountingItems()`
  a služba, kterou volá (`materializeOfferCode`); `AccountingItemsOffer`
- `modules/economy/codebooks/src/FiscalYearsProvisioner.php`
- `src/Core/Settings/LayerCParameters.php` — parametry vrstvy C
  (`economy.accountChart`, `economy.vatAgenda`, …)

## Rozsah

**V rozsahu:**

- základ profilu `tests/corpus/cz/profiles/<profil>/` (ručně)
- sestavení sad v `tests/datasets/cz/<profil>/` jako součást buildu korpusu;
  sady se commitují (D19)
- ideální analýza jako snapshot (D20)
- manifest: `country`, `profile`, `provision` (D22, D24)
- `dataset-seed`: kontrola země, fiskální roky pro data sady (D23),
  účetní položky z nabídky osnovy (D22)
- dokumentace

**Mimo rozsah:**

- apply návrhů, porovnání s očekáváním, očekávané sestavy (F3)
- evaluace AI (F4)
- registr sad na serveru, sada podle jména, založení zdroje dat ze sady
  jedním příkazem (F5)
- kurzy (#121), soubory ISDOC (#119)
- dodavatelé jako osoby v sadě (D21)

## Struktura

```
tests/corpus/cz/profiles/<profil>/       # ručně — základ profilu
  profile.jsonc                          # název sady, titulek, provision, created
  setup/settings.jsonc                   # shpd.dataset.setup.v1
  setup/vat_registrations.jsonc          # jen vat-payer
  setup/bank_accounts.jsonc
  setup/mailboxes.jsonc                  # jen když je potřeba víc než default
tests/datasets/cz/<profil>/              # generované buildem korpusu, commitované
  manifest.jsonc                         # shpd.dataset.v1
  setup/*.jsonc                          # kopie ze základu profilu
  persons/0001-<slug>.jsonc              # vlastní firma (isOwn) z identit
  mail/NNNN-<slug>.jsonc                 # zpráva s ideální analýzou
  mail/NNNN-<slug>.files/<doklad>.pdf    # PDF z tests/corpus/cz/out/<profil>/
```

Sestavení dělá sdílený generátor (`tools/invoice-generator/invoicegen/dataset.py`),
spouští ho běžný build korpusu (`tests/corpus/cz/build.py`) — sady jsou
vždy v souladu s korpusem a změna korpusu je vidět i v diffu sad.
Kopie PDF v gitu nic nestojí (stejný obsah = jeden blob).

## Profily — základ (`profiles/<profil>/`)

| Profil | Název sady | `economy.accountChart` | `economy.vatAgenda` | Registrace DPH | Vlastní bankovní účet |
|--------|-----------|------------------------|---------------------|----------------|-----------------------|
| `vat-payer` | `cz-vat-payer` | `default` | `true` | CZ, měsíční, platná od 1. 1. 2026, DIČ z identity | ano |
| `non-payer` | `cz-non-payer` | `default` | `false` | — | ano |
| `association` | `cz-association` | `npo` | `false` | — | ano |

Společné: `economy.homeCurrency` `czk`, `economy.fiscalYearStartMonth` `1`.
Vlastní bankovní účet je fiktivní (pravidla identit F1 — mod 11, IBAN);
dokladům nevadí jeho absence, ale bez něj svítí položka nastavení.

`profile.jsonc`:

```jsonc
{
	"name": "cz-vat-payer",
	"title": "Testovací sada — plátce DPH s.r.o.",
	"description": "Korpus #103 F1: přijaté doklady s ideální analýzou.",
	"created": "2026-10-14T00:00:00Z",   // pevné — sada je deterministická
	"provision": { "accountingItems": "all" },
}
```

Hodnoty sloupců `setup/*.jsonc` — kontrola proti skutečnosti: nastav
volný dev zdroj dat ručně (průvodce, `ds-setting`), `dataset-dump`
a porovnej sekci `setup/`; základ profilu nese jen to, co se liší
od čerstvého zdroje dat.

## Ideální analýza (D20)

Jedna zpráva na buňku matice (52 zpráv: 20 + 16 + 16). Pole zprávy:

| Pole | Hodnota |
|------|---------|
| `messageId` | `MSG-CORPUS-<VP\|NP\|AS>-NNNN` (deterministické, ≤ 30 znaků) |
| `mailbox` | `default` |
| `subject` | podle jazyka šablony: „Faktura <číslo>“, „Invoice <číslo>“, u účtenky „Účtenka <datum>“ |
| `senderEmail`, `senderName` | e-mail a název dodavatele; dodavatel bez e-mailu (účtenka) → `doklady@<profil>.example`, jméno vlastní firmy (sken poslaný sám sobě) |
| `receivedAt` | den vystavení + 1, 08:00 (pevné) |
| `bodyPlain` | krátká věta („V příloze zasíláme fakturu …“ / anglicky) |
| `docState` | stav nové zprávy (jako zpráva po analýze z mail-routeru) |
| `analysisState` | `30` (Analyzováno) |
| `primaryType` / `primaryTypeSource` | `invoiceReceived` / `ai` |
| `aiTitle` | titulek ve tvaru promptu („Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč“) |
| `partnerName` | název dodavatele |
| `attachments` | PDF (`file`, `name`, `mimeType`, `order` 1); ISDOC jako druhá příloha přibude s #119 |
| `analyses[0]` | viz níže |

`analyses[0]`:

- `status` `2` (success), `resolution` `null` (otevřený návrh),
  `proposedType` `invoiceReceived`, `contentTag` = štítek z očekávání;
- `modelName` `corpus-ideal`, `promptVersion` `corpus`, `profile` = kód
  profilu analýzy pošty, který dostane čerstvý zdroj dat;
  `confidence` `1.0`, `costUsd` / tokeny `0`, `analyzedAt` = `receivedAt` + 1 min;
- `canonicalJson` = očekávaný výsledek z `tests/corpus/cz/expected/<profil>/`
  doplněný o pole, která nese skutečná analýza a projekce je nemá:
  `source` {`kind` `aiExtraction`, `extractedAt`, `confidence` `1.0`,
  `promptVersion` `corpus`}, `attachments` [{`filename`, `mimeType`,
  `kind` `original`, `ref` `att:1`}], `_resolve.contentTag.tagSource`
  `llm` a `tagConfidence` `1.0`. Žádná jiná pole se nemění — co je
  v očekávání `null`, zůstává `null`;
- `analysisJson` = syntetický výstup AI ve tvaru promptu:
  `overall_confidence`, `message_classification` {`primary_type`,
  `confidence`, `title`}, `document` {`doc_type`, `confidence`,
  `extracted_json` = `canonicalJson` bez `_resolve`}.

Odkaz `att:1` se při seedu přepíše na id přílohy (`docs/datasets.md` §1,
`shpd.mail.incomingMessage.v1`). Konvence sad pro JSON platí (null
a prázdná pole vynechaná) — **kromě** `analysisJson` a `canonicalJson`,
které se přenášejí doslova (i s `null`).

## Dodavatelé (D21)

Sada osoby dodavatelů nenese. Applier je při Použít založí z canonicalu
(`PartyResolver` → `canCreate`, bez ARES). Doklad `0V01` dodavatele založí,
`0V02` ho najde — obě cesty se tak testují samy. Vlastní firma v sadě je
(`persons/`, `isOwn: true`) — bez ní se doklad nepotvrdí.

## Úpravy `dataset-seed` (PHP)

**Manifest (D22, D24)** — `DatasetManifest` dostane volitelná pole:

```jsonc
{
	"format": "shpd.dataset.v1",
	"name": "cz-vat-payer",
	…
	"country": "cz",                           // země vlastní firmy (D7)
	"profile": "vat-payer",                    // informativní
	"provision": { "accountingItems": "all" }, // jednorázové akce seedu
}
```

- `country` — seed odmítne zdroj dat jiné země (`DataSourceConfig::getCountry()`)
  ještě v preflightu, nic se nezmění. Bez `country` beze změny chování.
- `provision.accountingItems` — `"all"` = všechny položky nabídky podle
  osnovy zdroje dat; seznam řetězců = jen tyto kódy nabídky. Seed volá
  tutéž službu jako `SetupController::generateAccountingItems()`
  (vytáhnout orchestraci do sdílené služby, controller ji jen volá).
  Chybějící prerekvizita (druh položky, jednotka, nabídka pro osnovu
  `none`) = chyba seedu se srozumitelnou zprávou. Nabídka zůstává
  jednorázová akce — žádný provisioner (`docs/ds-setup.md` D18).
- Neznámý klíč v `provision` = chyba preflightu.

**Fiskální roky (D23)** — po `ds-reset` a `setup/` (osnova a první měsíc
roku už jsou nastavené) seed zjistí roky, do kterých spadají data sady
(data dokladů v `docs/`, DUZP a data vystavení v `canonicalJson`
analýz zpráv, `receivedAt` zpráv), a pro každý zavolá
`FiscalYearsProvisioner::provision(<datum v roce>)`. Období DPH se zakládají
na požádání při uložení dokladu (`vat-report-periods`) — seed je nezakládá.

**Pořadí seedu:** settings → reset → `setup/` → fiskální roky → účetní
položky → osoby → položky → doklady → spisovna → pošta.

## Build

`python3 tests/corpus/cz/build.py` po korpusu sestaví všechny tři sady
(`--no-datasets` přeskočí). Sestavení je deterministické: stejný korpus
= bajtově stejné soubory sad, druhý běh nic nezmění. Výpis: per sada počet
zpráv a vynechaných buněk matice.

Kontrola v buildu: každá zpráva sady má očekávání, každá buňka matice má
zprávu, `canonicalJson` projde (po odebrání doplněných polí) shodou
s očekáváním.

## Kroky (commity)

1. **Manifest a seed** (PHP): `country`, `profile`, `provision`; kontrola
   země; fiskální roky; služba pro účetní položky z nabídky (controller
   ji volá); unit testy manifestu, integrační test seedu.
2. **Základy profilů** — `tests/corpus/cz/profiles/`, ověřené proti dumpu
   ručně nastaveného zdroje dat.
3. **Sestavení sad** — `invoicegen/dataset.py`, napojení do buildu,
   unit testy (`python3 -m unittest`).
4. **Sady** — build, commit `tests/datasets/cz/`.
5. **Ověření na dev zdroji dat** (viz Hotovo když) a **dokumentace**.

## Hotovo když

- [ ] `python3 -m unittest` v `tools/invoice-generator/` zelený;
      PHPUnit testy manifestu a seedu zelené (úzký `--filter`)
- [ ] `python3 tests/corpus/cz/build.py` vyrobí tři sady; druhý běh nezmění
      žádný soubor
- [ ] pro každý profil na novém dev zdroji dat
      (`ds-create --country cz --no-cron`): `dataset-seed` projde bez chyb;
      zdroj dat má vlastní firmu, správnou osnovu (`npo` u spolku),
      registraci DPH jen u plátce, fiskální rok 2026, účetní položky
      z nabídky; zprávy 20 / 16 / 16 ve stavu Analyzováno s otevřeným
      návrhem a PDF
- [ ] `dataset-seed` na zdroj dat jiné země skončí v preflightu chybou
      a nic nezmění
- [ ] `dataset-dump` naseedovaného zdroje dat: sekce `mail/` je bajtově
      shodná se sadou (odchylky vysvětlit v tasku)
- [ ] ruční proklik u každého profilu: návrh tuzemské faktury, faktury
      v EUR a účtenky se otevře bez chyb; tuzemský návrh jde Použít
      (dodavatel se založí) a druhý doklad téhož dodavatele ho najde
- [ ] `docs/datasets.md`: pole manifestu `country`, `profile`, `provision`,
      fiskální roky a účetní položky v průběhu seedu
- [ ] `docs/test-corpus.md`: profily, sestavení sad, ideální analýza,
      jak sadu naseedovat (`ds-create` → `dataset-seed`)
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Sady se needitují ručně** — jako očekávání. Změna = základ profilu,
  korpus nebo generátor a nový build.
- **`canonicalJson` doslova.** Normalizace sad (vynechat `null`) se na
  snapshot analýzy nevztahuje; kdyby ji sestavení použilo, ideální
  analýza by přestala odpovídat očekávání (D14).
- **Doklady v cizí měně** jdou v návrhu otevřít, ale bez kurzu nejdou
  potvrdit (#121). Ruční proklik to nemá hlásit jako chybu F2.
- **`created` v manifestu je pevné** — časové razítko buildu by měnilo
  sadu při každém běhu.
- **Účetní položky nejsou provisioner.** Seed je vytvoří, protože o to
  sada požádala; `ds-upgrade` je nevrací ani nedoplňuje.

## Rozhodnutí k designu (potvrzená)

- ✓ **D19 — Základ profilu ručně, sady generované a commitované.** Základ
  v `tests/corpus/cz/profiles/<profil>/`, sady v `tests/datasets/cz/<profil>/`
  z buildu korpusu — commit kvůli přehledu změn a aby je po `pull`
  nebylo nutné stavět.
- ✓ **D20 — Ideální analýza:** jedna zpráva na buňku matice, PDF jako
  příloha, `analyses[0]` = očekávání jako `canonicalJson`, model
  `corpus-ideal`, stav Analyzováno s otevřeným návrhem.
- ✓ **D21 — Dodavatelé v sadě nejsou**, založí je apply.
- ✓ **D22 — Účetní položky z nabídky osnovy** generuje seed podle
  manifestu stejnou službou jako průvodce nastavením.
- ✓ **D23 — Seed založí fiskální roky** pro roky, do kterých spadají
  data sady.
- ✓ **D24 — Manifest nese `country` a `profile`;** seed odmítne zdroj
  dat jiné země.
- ✓ **D25 — Kurzy samostatně (#121).** F2 je nepotřebuje, F3 na nich
  u dokladů v cizí měně závisí.
