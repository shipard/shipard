<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Values\CanonicalValueValidator;

/**
 * Runtime resolver confidence pásem dokumentového návrhu: mapování
 * confidence na pásmo podle prahů profilu + strop podle pokrytí řádků
 * položkami (D7) a podle nálezů hodnot (#118 D15) — jedna metoda
 * {@see capBand()} pro feed i detail zprávy. Pásmo NENÍ perzistentní stav
 * (D3 z mail-message-centric) — počítá se za běhu; používá dashboard
 * (kind karty), detail zprávy (badge) a preview. Nikam se nezapisuje.
 */
class AnalysisConfidenceResolver
{
    public const BAND_READY = 'ready';
    public const BAND_REVIEW = 'review';
    public const BAND_LOW = 'low';

    /**
     * Fallback prahy, když profil neexistuje nebo nemá confidence_thresholds.
     * ISDOC import tak funguje i v DS bez nakonfigurované AI.
     */
    public const DEFAULT_THRESHOLDS = ['ready' => 0.9, 'review' => 0.6];

    private const PROFILES_TABLE = 'core_mail_ai_profiles';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Prahy daného profilu; null nebo profil bez prahů → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForProfile(?int $profileNdx): array
    {
        if ($profileNdx === null) {
            return self::DEFAULT_THRESHOLDS;
        }

        $row = $this->db->fetchRow(
            'SELECT confidence_thresholds FROM %n WHERE id = %i',
            self::PROFILES_TABLE,
            $profileNdx,
        );
        if ($row === null || empty($row['confidence_thresholds'])) {
            return self::DEFAULT_THRESHOLDS;
        }

        $decoded = json_decode((string) $row['confidence_thresholds'], true);
        if (!is_array($decoded)) {
            return self::DEFAULT_THRESHOLDS;
        }

        return [
            'ready' => isset($decoded['ready']) ? (float) $decoded['ready'] : self::DEFAULT_THRESHOLDS['ready'],
            'review' => isset($decoded['review']) ? (float) $decoded['review'] : self::DEFAULT_THRESHOLDS['review'],
        ];
    }

    /**
     * Prahy default aktivního profilu DS — pro čtení pásma bez vazby na
     * konkrétní profil (dashboard, detail zprávy). Bez profilu → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForDefaultProfile(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
        return $this->thresholdsForProfile($row !== null ? (int) $row['id'] : null);
    }

    /**
     * @param array{ready: float, review: float} $thresholds
     */
    public function bandFor(float $confidence, array $thresholds): string
    {
        if ($confidence >= $thresholds['ready']) {
            return self::BAND_READY;
        }
        if ($confidence >= $thresholds['review']) {
            return self::BAND_REVIEW;
        }
        return self::BAND_LOW;
    }

    /**
     * Jediný strop pásma (D7 + #118 D15): `ready` smí zůstat, jen když je
     * každý item řádek pokrytý položkou a validace hodnot canonicalu
     * nevrátí žádný nález — nevalidní IČO, DIČ, PSČ, účet, IBAN, BIC ani
     * oprava IBAN ↔ číslo účtu (`_resolve.valueRepairs` →
     * `bank_account_repaired`). Nižší pásma se nemění.
     *
     * @param array<string, mixed> $canonical
     */
    public function capBand(string $band, array $canonical): string
    {
        if ($band !== self::BAND_READY) {
            return $band;
        }
        if (!$this->rowsCoveredByItems($canonical)) {
            return self::BAND_REVIEW;
        }
        if (CanonicalValueValidator::validate($canonical) !== []) {
            return self::BAND_REVIEW;
        }
        return $band;
    }

    /**
     * Pokrytí řádků (D7): každý item řádek má položku — z extrakce, nebo
     * návrhem enrichmentu. Kontační řádky (acc.record) položku nemají
     * validně, stropu se netýkají.
     *
     * Řádek doplněný enrichmentem s confidence `low` (dominantní položka
     * partnera, bez textového signálu) se jako pokrytý nepočítá — návrh
     * zůstává review a uživatel ho potvrzuje
     * (`tasks/enrichment-dominant-item.md`, D5). Textové matche
     * (`high`/`medium`) beze změny; řádky s ourCode od AI mají
     * `confidence: null` → stropu se netýkají.
     *
     * Řádky doplněné obsahovou eskalací (`matchedBy: 'contentTag'`) stropují
     * vždy (D14, `tasks/content-tag-enrichment.md`) — obsahový návrh vždy
     * potvrzuje člověk, bez ohledu na zdroj štítku (rule/llm).
     *
     * @param array<string, mixed> $canonical
     */
    private function rowsCoveredByItems(array $canonical): bool
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $enrichments = $this->enrichmentsByRowIndex($canonical);
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !RowHistoryEnricher::rowExpectsItem($row)) {
                continue;
            }
            if (trim((string) ($row['item']['ourCode'] ?? '')) === '') {
                return false;
            }
            if (($enrichments[$idx]['confidence'] ?? null) === 'low') {
                return false;
            }
            if (($enrichments[$idx]['matchedBy'] ?? null) === 'contentTag') {
                return false;
            }
        }
        return true;
    }

    /**
     * Pásmo návrhu z řádku analýzy: confidence + prahy profilu běhu
     * (fallback default profil) + strop pokrytí. Convenience pro čtecí
     * místa (feed, viewer, preview).
     *
     * @param array<string, mixed> $canonical
     */
    public function bandForAnalysis(?float $confidence, ?int $profileNdx, array $canonical): string
    {
        $thresholds = $profileNdx !== null
            ? $this->thresholdsForProfile($profileNdx)
            : $this->thresholdsForDefaultProfile();
        $band = $this->bandFor($confidence ?? 0.0, $thresholds);
        return $this->capBand($band, $canonical);
    }

    /**
     * Enrichment audit bloky z `_resolve.rows` mapované na index řádku
     * canonical (jen array entries s array `enrichment`).
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array<string, mixed>>
     */
    private function enrichmentsByRowIndex(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return [];
        }
        $map = [];
        foreach ($resolveRows as $entry) {
            if (!is_array($entry) || !is_array($entry['enrichment'] ?? null)) {
                continue;
            }
            $idx = $entry['index'] ?? null;
            if (is_int($idx)) {
                $map[$idx] = $entry['enrichment'];
            }
        }
        return $map;
    }
}
