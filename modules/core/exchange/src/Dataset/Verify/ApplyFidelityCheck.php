<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Kontrola 1 — věrnost apply (tasks/testing-phase3.md D27): export
 * potvrzeného dokladu (`DocumentExporter` → canonical, přes adaptér
 * {@see SavedDocumentProjection}) ≟ `canonicalJson` použitého návrhu na
 * polích papírové projekce sady (`expected/projection.jsonc`).
 *
 * Výjimky věrnosti = pole, která apply mění záměrně; každá s důvodem
 * a odkazem na kód, který změnu dělá. Schválené 2026-10-14 (#103 F3):
 * {@see EXCEPTIONS} platí vždy, {@see EXCEPTIONS_NO_VAT} u dokladu bez DPH
 * (`vat.mode` none), {@see EXCEPTIONS_NO_DUE_DATE} a
 * {@see EXCEPTIONS_NO_PAYMENT_REFERENCE} u dokladu bez splatnosti / VS
 * (účtenka — výchozí hodnoty `DocDocument`), {@see EXCEPTIONS_NON_PAYER}
 * u zdroje dat bez agendy DPH (#97), {@see EXCEPTIONS_FOREIGN_CURRENCY}
 * u dokladu v cizí měně (#103 D79). Rovnocenné tvary, které adaptér
 * dopočítá ze zdroje dat (vlastní strana, text řádku, jednotka přes resolver
 * i jednotka mimo číselník, papírová podoba samovyměření a dokladu bez DPH),
 * nejsou výjimky — porovnávají se.
 * **Další výjimku nepřidávej bez schválení**: kandidáti z běhu se sepíší
 * a člověk rozhodne, chyba applieru se nesmí schovat do výjimky.
 */
final class ApplyFidelityCheck implements VerifyCheck
{
    public const ID = 'applyFidelity';

    /** @var array<string, string> cesta uzlu projekce → důvod (odkaz na kód) */
    public const EXCEPTIONS = [
        'rows[].item.supplierCode' => 'kód dodavatele se učí per položka a dodavatel (`economy_items_supplier_codes`, DocumentApplier::learnSupplierCodes, INSERT IGNORE) — účetní položka sdílená více řádky nese jen první; export ho nenese (DocumentExporter::rows)',
        'rows[].vat.reverseChargeCode' => 'kód předmětu plnění nese kód DPH řádku (VatCodeDerivation: cz-115 ↔ kód 4), řádek ho neukládá; hlídá kontrola DPH přes KH B.1 (kodPredPl)',
    ];

    /** @var array<string, string> doklad bez DPH (`vat.mode` none — dodavatel neplátce) */
    public const EXCEPTIONS_NO_VAT = [
        'dates.taxPointDate' => 'DocDocument::applyDateDefaults doplní DUZP datem vystavení i u dokladu bez DPH (typ dokladu je daňový)',
    ];

    /** @var array<string, string> doklad bez splatnosti (účtenka) */
    public const EXCEPTIONS_NO_DUE_DATE = [
        'dates.dueDate' => 'DocDocument::applyDateDefaults doplní splatnost datem vystavení + lhůta partnera (účtenka splatnost nemá)',
    ];

    /** @var array<string, string> doklad bez variabilního symbolu (účtenka) */
    public const EXCEPTIONS_NO_PAYMENT_REFERENCE = [
        'payment.paymentReference' => 'DocDocument::applyPaymentReferenceDefault doplní VS pořadovým číslem řady (účtenka VS nemá)',
    ];

    /** @var array<string, string> zdroj dat bez agendy DPH — neplátce, spolek (#97) */
    public const EXCEPTIONS_NON_PAYER = [
        'rows[].unitPrice'     => 'neplátce ukládá cenu řádku s DPH dodavatele (#97, DocumentApplier::transform / VatModeDerivation)',
        'rows[].totalPrice'    => 'neplátce ukládá cenu řádku s DPH dodavatele (#97)',
        'rows[].priceCalcMode' => 'převod na cenu s DPH zapíše fromTotal (#97)',
        'rows[].discountPct'   => 'sleva se rozpustí do ceny s DPH, procento se neukládá (#97)',
        'rows[].vat'           => 'řádek neplátce nemá kód ani sazbu DPH (#97)',
        'vat.mode'             => 'neplátce ukládá vat_mode 0 = none (#97)',
        'vatRecap'             => 'neplátce nemá rekapitulaci DPH (#97)',
        'totals.totalBase'     => 'základ neplátce = částka včetně DPH dodavatele (#97)',
        'totals.totalVat'      => 'neplátce DPH neeviduje (#97)',
    ];

    /** @var array<string, string> doklad v cizí měně (#103 D79, schváleno 2026-10-20) */
    public const EXCEPTIONS_FOREIGN_CURRENCY = [
        'exchangeRate' => 'papír kurz nenese, applier ho doplní z kurzovního lístku k účetnímu datu (#121 D6, DocumentApplier::exchangeRateDecision); hodnotu i datum hlídá kontrola Kurzy (ExchangeRateCheck) proti expected/exchange-rates.json',
    ];

    /** @var array<string, string> podstromy, které export z principu nenese (ne výjimky apply) */
    public const STRUCTURAL = [
        '_resolve' => 'export doklad nenese `_resolve` — štítek per řádek hlídá kontrola položek a účtů přes položku',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Věrnost apply (doklad ≟ návrh)';
    }

    /**
     * Výjimky platné pro doklad: vždy + bez DPH + bez splatnosti / VS
     * + zdroj dat bez agendy DPH + cizí měna (jen když je známa domácí měna).
     *
     * @param array<string, mixed>|null $proposal canonical návrhu
     * @return array<string, string> cesta uzlu projekce → důvod
     */
    public static function exceptionsFor(?array $proposal, bool $vatAgenda, ?string $homeCurrency = null): array
    {
        $out = self::EXCEPTIONS;
        $currency = strtoupper(trim((string) ($proposal['currency'] ?? '')));
        if ($homeCurrency !== null && $currency !== '' && $currency !== strtoupper(trim($homeCurrency))) {
            $out += self::EXCEPTIONS_FOREIGN_CURRENCY;
        }
        if (($proposal['vat']['mode'] ?? null) === 'none') {
            $out += self::EXCEPTIONS_NO_VAT;
        }
        if (array_key_exists('dates', (array) $proposal) && ($proposal['dates']['dueDate'] ?? null) === null) {
            $out += self::EXCEPTIONS_NO_DUE_DATE;
        }
        if (array_key_exists('payment', (array) $proposal) && ($proposal['payment']['paymentReference'] ?? null) === null) {
            $out += self::EXCEPTIONS_NO_PAYMENT_REFERENCE;
        }
        if (!$vatAgenda) {
            $out += self::EXCEPTIONS_NON_PAYER;
        }
        return $out;
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $docs = array_values(array_filter($ctx->appliedDocuments(), static fn (SetDocument $d): bool => $d->canonical !== null));
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad s návrhem');
        }

        $w = $ctx->wiring;
        $exporter = new DocumentExporter($w->db, $w->config);
        $adapter = new SavedDocumentProjection(
            $w->db,
            $w->config !== null ? new VatRateResolver($w->config) : null,
            $w->dsConfig->getCountry(),
        );
        $comparator = new CanonicalComparator($ctx->expectations->projection);
        $vatAgenda = $ctx->expectations->vat !== [];

        $differences = [];
        $used = [];
        foreach ($docs as $doc) {
            $records = $exporter->exportByIds([(int) $doc->docId]);
            if ($records === []) {
                $differences[] = "{$doc->messageId}: doklad #{$doc->docId} nejde exportovat";
                continue;
            }
            $exceptions = self::exceptionsFor($doc->canonical, $vatAgenda, $ctx->homeCurrency);
            $used += $exceptions;
            $actual = $adapter->build($records[0]->data, (array) $doc->canonical, (int) $doc->docId);
            $ignore = array_keys($exceptions + self::STRUCTURAL);
            foreach ($comparator->compare((array) $doc->canonical, $actual, $ignore) as $difference) {
                $differences[] = "{$doc->messageId} ({$doc->docNumber}): {$difference}";
            }
        }

        $notes = [count($docs) . ' dokladů, výjimek věrnosti: ' . count($used) . ($used !== [] ? ' (' . implode(', ', array_keys($used)) . ')' : '')];
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }
}
