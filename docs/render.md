# PDF rendering — služba a klient

Sdílená schopnost renderovat HTML → PDF a konvertovat Office dokumenty
→ PDF. Rozhodnutí D1–D8 viz `tasks/pdf-rendering-service.md` (issue #34).

Konzumenti: preprocess došlé pošty (#33 — akce `renderBodyToPdf` a
`fetchLinkedDocument` s `renderIfHtml`, profil Untrusted bez assetů;
`modules/core/mail/docs/preprocess.md`), HTML renditions příloh
(follow-up #34), tisky (`PrintRenderer`, profil Report se záhlavím
a zápatím — [prints.md](prints.md)) a reporty (samostatný budoucí design —
tady je jen kontrakt, který budou potřebovat).

## Architektura

```
┌──────────── fyzický stroj ────────────┐
│  Shipard (1..N kontejnerů / instancí) │
│    RenderClient ── HTTP push ──┐      │
│                                ▼      │
│  Gotenberg kontejner (127.0.0.1:3000  │
│  nebo interní bridge)                 │
└───────────────────────────────────────┘
```

- **Jedna rendering služba per fyzický stroj** (D1). Gotenberg
  (pinovaná verze, vendorovaná infra dle `docs/services.md`) —
  Chromium + LibreOffice + PDF engines za stateless HTTP API.
  Provoz a topologie: `docs/operations/render-service.md`.
- **Engine-agnostický klient** `src/Core/Render/` (D3). Volající nikdy
  nemluví s Gotenbergem přímo; specifika žijí v jediném adaptéru
  `Engine/GotenbergEngine.php`.
- **Obsah se vždy pushuje** (D4): HTML + assety jako soubory v multipart
  requestu. Nikdy URL konverze — render kontejner nevidí do aplikace,
  odpadá SSRF třída problémů.
- Žádná aplikační autentizace — zabezpečení je síťové (bind na
  localhost / interní bridge, žádný WAN forward).

## Konfigurace

`/etc/shipard/server.json` (per-server infra, ne per-DS):

```json
"render": { "url": "http://127.0.0.1:3000", "timeoutSec": 30 }
```

`ServerConfig::getRender(): ?RenderConfig`. Chybějící klíč = služba
nekonfigurována — **validní stav**, všechna volání vrací
`errorKind=unconfigured` a nic nepadá.

## Kontrakt RenderClient

```php
$client = RenderClient::fromServerConfig($serverConfig);

$result = $client->renderHtml($html, $assets, RenderProfile::Untrusted);
$result = $client->convertOffice('smlouva.docx', $bytes);
$result = $client->postProcess($pdf, [
    ['step' => 'embedIsdoc', 'params' => ['fileName' => 'faktura.isdoc', 'content' => $xml]],
    ['step' => 'appendPdfs', 'params' => ['pdfs' => [$prilohaPdf]]],
]);
$client->isConfigured();   // bool
$client->health();         // bool — GET /health, krátký timeout (doctor)
```

- `$assets` = mapa `filename => content` (obrázky, CSS, fonty);
  z HTML se referencují **relativně** (plochý adresář). Ikony v šablonách
  řešit inline SVG, ne ikonovým fontem; Gotenberg image balí Noto stack
  + metricky kompatibilní náhrady MS fontů — pro CZ výstupy dostačuje,
  firemní font lze přiložit jako asset.
- `RenderResult` (readonly): `ok`, `pdfContent`, `errorKind`
  (`unconfigured | unreachable | timeout | engineError | invalidInput`),
  `note`. **Provozní stavy nikdy nejdou ven výjimkou** — výjimky jen
  na programátorské chyby (nevalidní `PdfOptions`).
- `PdfOptions` (readonly): `paperFormat` (A3/A4/A5/Letter/Legal, default
  A4), `orientation`, okraje per strana (CSS délky, null = default
  profilu), `headerTemplate`/`footerTemplate` (kompletní HTML dokumenty;
  přítomnost sama zapíná tisk), `printBackground`. Sémantika převzata ze
  starého `PdfCreator` kvůli budoucí migraci šablon.

## Profily (D5)

| | `Untrusted` | `Report` |
|---|---|---|
| Určení | e-mailová HTML těla, stažené HTML | vlastní server-side šablony |
| header/footer | ne (`invalidInput`) | ano |
| printBackground | ne (`invalidInput`) | ano |
| timeout | strop 30 s | plný `timeoutSec` z konfigurace |
| default okraje | 1cm | 1.6cm |

JS je vypnutý **globálně** flagem kontejneru
(`--chromium-disable-javascript`) — per-request to Gotenberg neumí.
Odchozí síť Chromia zakazují env proměnné kontejneru
`CHROMIUM_DENY_PRIVATE_IPS` + `CHROMIUM_DENY_PUBLIC_IPS` (push-only
obsah, assety jdou v requestu; ne `--chromium-deny-list=.*`, ten blokuje
i interní `file://` — viz `docs/operations/render-service.md`). Hlavní
HTML soubor jde v multipartu vždy pod jménem `index.html` — Gotenberg
jiný název odmítne (400); assety se z něj referencují relativně.
Šablony jsou server-side a JS nepotřebují; kdyby ho budoucí sestavy
vyžadovaly, poběží druhá instance s jinými flagy — kontrakt klienta se
nemění.

## Post-processing (D8)

Řetěz kroků nad vráceným PDF, běží vždy u nás (budoucí el. podpis nesmí
pouštět klíče mimo kontejner zákazníka). Krok = implementace
`PostProcess/PostProcessStepInterface` (`apply(pdf, params): pdf`,
selhání výjimkou → klient mapuje na `engineError` s poznámkou kroku).

- **`embedIsdoc`** — vloží soubor jako PDF attachment. Primárně
  Gotenberg routa `forms/pdfengines/embed` (`files` + `embeds` pole);
  když engine chybí nebo selže, fallback `pdfattach` (poppler-utils).
  Obě cesty dávají běžný attachment ověřitelný `pdfdetach -list`;
  PDF/A-3 sémantiku (AFRelationship) v1 neřeší — až ji bude potřeba
  Factur-X/ISDOC validace, doplní se `embedsMetadata` na engine cestě.
- **`appendPdfs`** — `pdfunite` (poppler-utils), náhrada
  `PdfCreator::appendFiles` ze starého Shipardu.

## Degradace (D6)

Vzor `pdfdetach`/`TextExtractor`: služba nedostupná → typované selhání,
volající přeskočí s poznámkou a pokračuje. První selhání za proces
zaloguje warning (`render: …`), další jen debug. `shpd-server doctor`
má sekci Render service: nekonfigurováno = info, konfigurováno bez
odpovědi na `/health` = error; binárky `pdfattach`/`pdfunite` hlídá
sekce Attachment tools.

## Gotenberg specifika (jen v adaptéru)

- Routy: `forms/chromium/convert/html`, `forms/libreoffice/convert`,
  `forms/pdfengines/embed`, `GET /health`.
- Papír se zadává rozměrově (`paperWidth`/`paperHeight` s jednotkou),
  jmenované formáty Gotenberg nezná — adaptér mapuje `paperFormat`
  na rozměry.
- Multipart tělo se staví ručně (opakovaná form pole `files`/`embeds`
  s `CURLOPT_POSTFIELDS` polem nejdou); HTTP přes curl v protected
  seamu — testy podstrkují transport, žádná composer závislost
  (`gotenberg/gotenberg-php` záměrně nepřidán — potřebujeme zlomek API
  a vlastní error mapping).

## Jak přidat engine

Implementovat `Engine/RenderEngineInterface` (`renderHtml`,
`convertOffice`, `embedFiles`, `health`) a předat instanci konstruktoru
`RenderClient`. Výhled: Apache FOP pro XSL-FO sestavy — další adaptér
za týmž kontraktem, volajících se nedotkne.

## Testy

- Unit: `tests/Unit/Core/Render/` — mock engine, capturing transport,
  post-processing se syntetickým minimálním PDF (skip bez poppleru).
- Integrační proti živému Gotenbergu: `tests/Integration/Render/`,
  gated env `SHIPARD_INTEGRATION_GOTENBERG_URL` — mimo běžný CI běh:

```bash
SHIPARD_INTEGRATION_GOTENBERG_URL=http://127.0.0.1:3000 \
  vendor/bin/phpunit --testsuite Integration --filter Gotenberg
```
