<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;

/**
 * Archivace ostatní pošty podle pravidla odesílatele
 * (tasks/mail-sender-rules-after-analysis.md D4–D6, D8) — jediné místo
 * s logikou „zpráva bez dokladu od odesílatele s potvrzeným pravidlem jde
 * do Archivu“. Volají ji dva vstupy:
 *
 *   - {@see afterResult()} z `AnalysisController::result` uvnitř transakce
 *     výsledku (D4): první úspěšná analýza zprávy v Nové bez dokumentu;
 *   - {@see applyToWaiting()} z {@see SenderRuleConfirmedHandler} po
 *     potvrzení pravidla (D8): čekající řádky Ostatní od adresy (domény).
 *
 * Společné podmínky: `primary_type = other` (ruční volba uživatele má
 * přednost — čte se až po zápisu klasifikace), zpráva v Nové (10), ne ruční
 * nahrání (`source_type` ≠ 1), jistota klasifikace
 * (`message_classification.confidence`) ≥ `review` práh AI profilu běhu
 * (D5; chybí-li, zpráva zůstává), odesílatel má potvrzené pravidlo —
 * **jakékoli** dispozice (D6: `archive` znamená „všechno“, tedy i ostatní).
 * Nejkonkrétnější pravidlo (e-mail > doména) určuje {@see SenderRuleMatcher}.
 * Zpráva s pozorností `action` (tasks/mail-other-attention.md D6, #105 —
 * expirace, výzva k platbě, žádost) se pravidlem **nikdy neodklidí**:
 * zůstává v K vyřízení; `info` / `promo` / NULL (starší analýza) se
 * archivují jako dosud.
 *
 * Archivace jde přímým UPDATE (jako pre-triage při příjmu), ne přes
 * TableGateway — `stateChanged` handlery zpráv se nespouštějí; učící
 * handler auto-archiv stejně ignoruje (`auto_disposed_by`). Audit
 * `auto_disposed_by/at` je shodný s pre-triage, takže zprávy vidí digest
 * a „Vrátit vše“ (D7 je vrací bez nové analýzy — `analysis_state` zůstává 30).
 * Žádná transakce uvnitř — řídí ji volající.
 *
 * Ne-final kvůli testům handleru potvrzení (mock přes `disposer()`).
 */
class PostAnalysisDisposer
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const RULES_TABLE = 'core_mail_sender_rules';

    private const DOC_STATES_CFG_ITEM = 'core.mail.docStatesIncoming';

    /** docState pravidel (core.system.docStatesArchive). */
    private const RULE_STATE_CONFIRMED = 40;

    /** `core_mail_message_analyses.status` úspěšného běhu. */
    private const ANALYSIS_STATUS_SUCCESS = 2;

    /** `source_type` ručního nahrání (D4: nikdy se neodklízí). */
    private const SOURCE_TYPE_MANUAL = 1;

    /** JSON cesta k jistotě klasifikace v `analysis_json` (D8). */
    private const CLASSIFICATION_CONFIDENCE_PATH = '$.message_classification.confidence';

    /** @var array<int, float> review práh per profil (0 = bez profilu) */
    private array $reviewThresholdByProfile = [];

    private ?SenderRuleMatcher $matcher;
    private ?AnalysisConfidenceResolver $resolver;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
        ?SenderRuleMatcher $matcher = null,
        ?AnalysisConfidenceResolver $resolver = null,
    ) {
        $this->matcher = $matcher;
        $this->resolver = $resolver;
    }

    /**
     * D4–D6: po zápisu výsledku analýzy, uvnitř transakce resultu a nad
     * stejným spojením (čte stav po `applyMessageClassification`).
     * Vrací id pravidla, které zprávu archivovalo, jinak null.
     *
     * Pořadí kontrol (levné první): dokument / chybějící jistota → řádek
     * zprávy → počet úspěšných analýz (včetně právě vloženého řádku musí být
     * přesně 1 — zprávu vrácenou z Archivu nebo ručně reanalyzovanou pravidlo
     * znovu neodklidí) → práh → pravidlo.
     */
    public function afterResult(
        int $messageNdx,
        bool $hasDocument,
        ?float $classificationConfidence,
        ?int $profileNdx,
    ): ?int {
        if ($hasDocument || $classificationConfidence === null) {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT sender_email, primary_type, docState, source_type, attention FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($row === null
            || (string) ($row['primary_type'] ?? '') !== PrimaryTypes::OTHER
            || (int) ($row['docState'] ?? 0) !== IncomingMessageDocument::DOC_STATE_NEW
            || (int) ($row['source_type'] ?? 0) === self::SOURCE_TYPE_MANUAL
            || (string) ($row['attention'] ?? '') === AttentionKinds::ACTION
        ) {
            return null;
        }

        $successfulRuns = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE message = %i AND status = %i',
            self::ANALYSES_TABLE,
            $messageNdx,
            self::ANALYSIS_STATUS_SUCCESS,
        );
        if ($successfulRuns !== 1) {
            return null;
        }

        if ($classificationConfidence < $this->reviewThreshold($profileNdx)) {
            return null;
        }

        $rule = $this->matcher()->match((string) ($row['sender_email'] ?? ''));
        if ($rule === null) {
            return null;
        }

        $ruleId = (int) $rule['id'];
        return $this->archive([$messageNdx], $ruleId) > 0 ? $ruleId : null;
    }

    /**
     * D8: odklidí čekající řádky Ostatní (stejná množina jako karty
     * `MailSuggestionsSource::fetchNotInvoiceRows()`, navíc bez ručního
     * nahrání), pro které je pravidlo nejkonkrétnějším zásahem (D6) a jistota
     * poslední úspěšné analýzy prošla prahem jejího profilu (D5).
     * Pravidlo mimo stav 40 → 0. Vrací počet archivovaných zpráv. Řádky
     * K vyřízení (`attention = action`) do kandidátů nevstupují (D6 #105).
     *
     * Doménový vzor = přesná doména za posledním `@` (jako matcher), ne
     * subdomény. `LOWER(sender_email)` neumí index — kandidáty zužuje
     * `docState = 10`.
     */
    public function applyToWaiting(int $ruleId): int
    {
        $rule = $this->db->fetchRow(
            'SELECT id, pattern_kind, pattern, docState FROM %n WHERE id = %i',
            self::RULES_TABLE,
            $ruleId,
        );
        if ($rule === null || (int) ($rule['docState'] ?? 0) !== self::RULE_STATE_CONFIRMED) {
            return 0;
        }

        $pattern = strtolower(trim((string) ($rule['pattern'] ?? '')));
        if ($pattern === '') {
            return 0;
        }
        $patternSql = (string) $rule['pattern_kind'] === 'domain'
            ? 'SUBSTRING_INDEX(LOWER(m.sender_email), \'@\', -1) = %s'
            : 'LOWER(m.sender_email) = %s';

        $candidates = $this->db->fetchAll(
            'SELECT m.id, m.sender_email, a.profile,'
            . ' JSON_VALUE(a.analysis_json, %s) AS cls_confidence'
            . ' FROM %n m'
            . ' JOIN %n a ON a.id = ('
            . '   SELECT a2.id FROM %n a2'
            . '   WHERE a2.message = m.id AND a2.status = %i'
            . '   ORDER BY a2.analyzed_at DESC, a2.id DESC LIMIT 1)'
            . ' WHERE m.docState = %i AND m.analysis_state = %i'
            . ' AND m.primary_type = %s AND m.source_type <> %i'
            . ' AND NOT (a.canonical_json IS NOT NULL AND a.resolution IS NULL)'
            . ' AND (m.attention IS NULL OR m.attention <> %s)'
            . ' AND ' . $patternSql,
            self::CLASSIFICATION_CONFIDENCE_PATH,
            self::MESSAGES_TABLE,
            self::ANALYSES_TABLE,
            self::ANALYSES_TABLE,
            self::ANALYSIS_STATUS_SUCCESS,
            IncomingMessageDocument::DOC_STATE_NEW,
            IncomingMessageDocument::ANALYSIS_ANALYZED,
            PrimaryTypes::OTHER,
            self::SOURCE_TYPE_MANUAL,
            AttentionKinds::ACTION,
            $pattern,
        );

        $ids = [];
        foreach ($candidates as $candidate) {
            $confidence = $candidate['cls_confidence'] ?? null;
            if (!is_numeric($confidence)) {
                continue;
            }
            $profileNdx = isset($candidate['profile']) ? (int) $candidate['profile'] : null;
            if ((float) $confidence < $this->reviewThreshold($profileNdx)) {
                continue;
            }
            // D6: archivuje jen pravidlo, které je pro adresu nejkonkrétnější.
            $match = $this->matcher()->match((string) ($candidate['sender_email'] ?? ''));
            if ($match === null || (int) $match['id'] !== $ruleId) {
                continue;
            }
            $ids[] = (int) $candidate['id'];
        }

        return $ids === [] ? 0 : $this->archive($ids, $ruleId);
    }

    /**
     * Archivace zpráv s auditem + zásah pravidla. Podmínka `docState = 10`
     * v UPDATE je pojistka proti souběhu (uživatel zprávu mezitím odklidil
     * sám). Vrací počet skutečně archivovaných řádků.
     *
     * @param list<int> $messageIds
     */
    private function archive(array $messageIds, int $ruleId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE %n SET docState = %i, docStateMain = %i,'
            . ' auto_disposed_by = %i, auto_disposed_at = %s, modified = %s'
            . ' WHERE id IN %in AND docState = %i',
            self::MESSAGES_TABLE,
            IncomingMessageDocument::DOC_STATE_ARCHIVED,
            $this->archivedMainState(),
            $ruleId,
            $now,
            $now,
            $messageIds,
            IncomingMessageDocument::DOC_STATE_NEW,
        );
        $archived = $this->db->getAffectedRows();
        if ($archived > 0) {
            $this->db->execute(
                'UPDATE %n SET hit_count = hit_count + %i, last_hit_at = %s WHERE id = %i',
                self::RULES_TABLE,
                $archived,
                $now,
                $ruleId,
            );
        }

        return $archived;
    }

    /** `review` práh profilu, cachovaný per profil (0 = bez profilu → defaulty). */
    private function reviewThreshold(?int $profileNdx): float
    {
        $key = $profileNdx ?? 0;
        if (!array_key_exists($key, $this->reviewThresholdByProfile)) {
            $thresholds = $this->resolver()->thresholdsForProfile($profileNdx);
            $this->reviewThresholdByProfile[$key] = (float) $thresholds['review'];
        }
        return $this->reviewThresholdByProfile[$key];
    }

    /**
     * `docStateMain` Archivu z `core.mail.docStatesIncoming`; bez compiled
     * configu pevná hodnota (vzor `MailController::resolveIncomingMainState`).
     */
    private function archivedMainState(): int
    {
        if ($this->config !== null) {
            return DocStateConfig::fromCfgItem($this->config->cfgItem(self::DOC_STATES_CFG_ITEM))
                ->getMainState(IncomingMessageDocument::DOC_STATE_ARCHIVED);
        }
        return 4;
    }

    private function matcher(): SenderRuleMatcher
    {
        return $this->matcher ??= new SenderRuleMatcher($this->db->getDibiConnection());
    }

    private function resolver(): AnalysisConfidenceResolver
    {
        return $this->resolver ??= new AnalysisConfidenceResolver($this->db);
    }
}
