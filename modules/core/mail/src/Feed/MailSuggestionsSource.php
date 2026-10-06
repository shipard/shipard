<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Feed;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;
use Shipard\Core\Feed\FeedTexts;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;
use Shipard\Module\Core\Mail\AnalysisErrorInfo;
use Shipard\Module\Core\Mail\AnalysisErrorPresenter;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\IncomingMessageTitle;
use Shipard\Module\Core\Mail\Preprocess\PreprocessErrorPresenter;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;
use Shipard\Module\Core\Mail\PrimaryTypes;

/**
 * Feed zdroj došlé pošty — message-centricky (tasks/mail-message-centric.md
 * D10): karta = zpráva s otevřeným dokumentovým návrhem poslední úspěšné
 * analýzy, chybové karty per zpráva, u které selhala AI (nebo poslední běh
 * vrátil nevalidní canonical), a info karty ostatní pošty pro zprávy,
 * ve kterých AI nenašla doklad ani dokument Spisovny.
 *
 * Návrhové karty: zprávy v docState 10/20 s poslední úspěšnou analýzou
 * (`canonical_json` NOT NULL, `resolution` IS NULL). Confidence pásmo se
 * počítá za běhu (AnalysisConfidenceResolver, prahy profilu běhu):
 *   - ready → kind=ready, akce apply (primary, jednoklik safe) + review + reject
 *   - review/low → kind=review, akce review (primary) + reject
 * Návrhy s `proposed_type='other'` se ignorují (pojistka — prompt je
 * zakazuje, starší analýzy je mohly vytvořit).
 * Chybové karty: zpráva `analysis_state=70` (Analýza selhala) mimo Archiv/Koš
 * → kind=urgent, akce reanalyze + open_detail; degradace na review, když
 * klasifikace určila `primary_type='other'`. Otevřený návrh s ai_failed
 * wrapperem (`_validationError` v canonical_json) emituje chybovou kartu
 * (obě chybové karty: titulek, „Co se stalo" / „Co dělat" v `details`
 * a primární akce podle doporučení reanalýzy z AnalysisErrorPresenter —
 * tasks/mail-analysis-error-messages.md D3c, D4, D5)
 * také — akce reanalyze.
 * Karty ostatní pošty: zpráva `analysis_state=30`, `docState=10` (Nová),
 * `primary_type='other'` bez otevřeného návrhu → kind=info s akcemi
 * Koš (primary) / Archiv / otevřít read-only náhled zprávy. Titulek je
 * `ai_title` zprávy (AI popis obsahu), bez něj konstanta `other.title`
 * z katalogu (tasks/dashboard-other-row-title.md D1, D2).
 *
 * Návrhové karty s partnerem nesou strukturovanou hlavičku `headline`
 * ({partnerName, typeLabel, amountText?}) + volitelná pole `confidencePct`
 * (int 0–100) a `details` ({label, value} — číslo dokladu / splatnost /
 * variabilní symbol; u registry „Platí do"); `subtitle` se u nich neposílá.
 * Bez partnera karta padá na složený `title`/`subtitle` fallback.
 * `headline.partnerName` preferuje partnera zprávy jako Osobu
 * (`partner_person` — ruční volba / Použít mají přednost, D8), pak
 * protistranu z canonicalu, pak snapshot `partner_name`.
 * Všechny tři druhy mail karet nesou `emailSubject` — lidský titulek zprávy
 * (předmět; u generického / prázdného předmětu a ručních zpráv `ai_title`,
 * pravidlo D3 `IncomingMessageTitle`) — a volitelné `receivedDateText`.
 * Karta ostatní pošty `emailSubject` vynechá, když se shoduje s titulkem
 * (sken, ruční nahrání, generický předmět — tasks/dashboard-other-row-title.md D3).
 * Subtitle chybové karty a karty ostatní pošty: partner zprávy · od: odesílatel,
 * bez partnera jen odesílatel (D7). Neprázdné `secondary_findings` běhu →
 * pole `secondaryFindings` ({type, type_label, note}) — hint na kartě (D7).
 * Zpráva s předzpracováním ve stavu 40 „Hotovo s chybami" nese na všech
 * třech druzích karet `warning` — lokalizovaný řádek „Předzpracování:
 * {titulek kategorie}" z PreprocessErrorPresenter (tasks/mail-preprocess-
 * error-messages.md D3c); `preprocess_log` se čte jen ve stavu 40.
 * Data jdou z `canonical_json` (kanonický doklad) — dotazy zdroje omezuje
 * pojistný `sourceLimit` (řádově stovky), takže N `json_decode` je únosné;
 * strop toho, co uživatel uvidí, dělá collector per sekce (#101 D3a).
 *
 * Sekce feedu (#101 D4): chybové karty (`mail_message`, včetně degradované
 * review varianty) a karta nevalidního výstupu (`mail_invalid`) nesou
 * `feedSection = failed` (Nepodařilo se zpracovat). Návrhové karty a karty
 * ostatní pošty pole nemají — výchozí mapování z `kind` (ready → Připraveno,
 * review → Ke kontrole, info → Ostatní).
 *
 * Akce se emitují bez `label` — frontend je lokalizuje podle `action.id`
 * (i18n klíče `dashboard.card.action.*`). Podtitulek a titulek jsou naopak
 * složené na serveru (data-driven) z katalogu `core.mail.feedTexts` přes
 * `FeedTexts` (ICU plurály, #101 D11–D15; bez katalogu anglický fallback);
 * jen formát data řídí `ctx->language` přímo (D17).
 *
 * Přílohy: každá karta s ≥1 obsahovou přílohou zprávy nese volitelná pole
 * `attachments` (max MAX_CARD_ATTACHMENTS položek `{id, name, mime_type,
 * file_size}`) + `attachmentsTotal` (počet před stropem) — vždy **všechny**
 * obsahové přílohy zprávy (D10; `source_attachments` filtr zanikl). Raw
 * `.eml` (`raw_source_attachment`) se vylučuje vždy. Jeden batch dotaz.
 */
final class MailSuggestionsSource implements FeedSource
{
    private const MESSAGES_TABLE  = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE  = 'core_mail_message_analyses';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';

    /** Viewer id Došlé pošty — cíl akce openMail (read-only detail v modalu). */
    private const INCOMING_VIEWER_ID = 'core.mail.incoming';

    /**
     * tableId tabulky `core_mail_incoming_messages` — viewer používá literál
     * 303 přímo (IncomingMessagesViewer::fetchContentAttachments), nesjednocovat teď.
     */
    private const MESSAGES_TABLE_ID = 303;

    /** Strop počtu příloh na kartě; nad strop frontend kreslí „+N". */
    private const MAX_CARD_ATTACHMENTS = 3;

    private const PRIMARY_TYPES_CFG_ITEM = 'core.mail.primaryTypes';

    /** Katalog textů karet (`config/feedTexts.jsonc`, #101 D11–D15). */
    private const FEED_TEXTS_CFG_ITEM = 'core.mail.feedTexts';

    /**
     * Jméno Osoby partnera zprávy jako korelovaný subselect (ne JOIN —
     * smazaná Osoba řádek nefiltruje a tvar dotazů zůstává rozlišitelný
     * podle klíčových slov). Vyžaduje alias `m` na messages.
     */
    private const PARTNER_FULL_NAME_SQL = ' (SELECT `p`.`full_name` FROM `base_persons_persons` `p`'
        . ' WHERE `p`.`id` = `m`.`partner_person`) AS `partner_full_name`';

    private const DOC_KINDS_CFG_ITEM = 'base.registry.docKinds';

    /**
     * Sloupce předzpracování pro `warning` karty: stav vždy, log jen ve
     * stavu 40 (JSON může být dlouhý, jinde ho karta nepotřebuje).
     */
    private const PREPROCESS_SQL = ', `m`.`preprocess_state`,'
        . ' IF(`m`.`preprocess_state` = ' . PreprocessRunner::STATE_DONE_WITH_ERRORS
        . ', `m`.`preprocess_log`, NULL) AS `preprocess_log`';

    public function collectCards(FeedContext $ctx): array
    {
        $suggestionRows = $this->fetchSuggestionRows($ctx);
        $errorRows      = $this->fetchErrorRows($ctx);
        $notInvoiceRows = $this->fetchNotInvoiceRows($ctx);

        $attachmentsByMessage = $this->fetchAttachmentsByMessage(
            $ctx,
            [...$suggestionRows, ...$errorRows, ...$notInvoiceRows],
        );

        $resolver = new AnalysisConfidenceResolver($ctx->db);
        $thresholdsByProfile = [];
        // Jeden presenter per sběr — verzi výchozího profilu čte jednou.
        $presenter = new AnalysisErrorPresenter($ctx->db, $ctx->config);
        $preprocess = new PreprocessErrorPresenter($ctx->config);
        $texts = FeedTexts::forContext($ctx, self::FEED_TEXTS_CFG_ITEM);

        $cards = [];
        foreach ($suggestionRows as $row) {
            $attachments = $attachmentsByMessage[(int) $row['message_ndx']] ?? [];
            $canonical = json_decode((string) ($row['canonical_json'] ?? ''), true);
            $canonical = is_array($canonical) ? $canonical : [];

            // Nevalidní výstup běhu (forenzní wrapper z /result) → chybová
            // karta s reanalyze, návrh nelze použít.
            if (isset($canonical['_validationError'])) {
                $cards[] = $this->withPreprocessWarning(
                    $this->withAttachments($this->buildInvalidOutputCard($ctx, $texts, $row, $presenter), $attachments),
                    $row,
                    $preprocess,
                );
                continue;
            }

            $profileKey = $row['profile'] !== null ? (int) $row['profile'] : 0;
            if (!array_key_exists($profileKey, $thresholdsByProfile)) {
                $thresholdsByProfile[$profileKey] = $profileKey > 0
                    ? $resolver->thresholdsForProfile($profileKey)
                    : $resolver->thresholdsForDefaultProfile();
            }
            $band = $resolver->capBandByRowCoverage(
                $resolver->bandFor((float) ($row['confidence'] ?? 0.0), $thresholdsByProfile[$profileKey]),
                $canonical,
            );

            $cards[] = $this->withPreprocessWarning(
                $this->withAttachments($this->buildSuggestionCard($ctx, $texts, $row, $canonical, $band), $attachments),
                $row,
                $preprocess,
            );
        }
        foreach ($errorRows as $row) {
            $cards[] = $this->withPreprocessWarning(
                $this->withAttachments($this->buildErrorCard($ctx, $texts, $row, $presenter), $attachmentsByMessage[(int) $row['message_ndx']] ?? []),
                $row,
                $preprocess,
            );
        }
        foreach ($notInvoiceRows as $row) {
            $cards[] = $this->withPreprocessWarning(
                $this->withAttachments($this->buildNotInvoiceCard($ctx, $texts, $row), $attachmentsByMessage[(int) $row['message_ndx']] ?? []),
                $row,
                $preprocess,
            );
        }
        return $cards;
    }

    /**
     * Zprávy s otevřeným dokumentovým návrhem poslední úspěšné analýzy.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSuggestionRows(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `m`.`id` AS `message_ndx`, `m`.`subject`, `m`.`ai_title`, `m`.`source_type`,'
            . ' `m`.`sender_name`, `m`.`partner_name`,' . self::PARTNER_FULL_NAME_SQL . ','
            . ' `m`.`received_at`, `m`.`raw_source_attachment`' . self::PREPROCESS_SQL . ','
            . ' `a`.`id` AS `analysis_ndx`, `a`.`proposed_type`, `a`.`canonical_json`,'
            . ' `a`.`analysis_json`, `a`.`confidence`, `a`.`profile`, `a`.`prompt_version`'
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' JOIN `' . self::ANALYSES_TABLE . '` `a` ON `a`.`id` = ('
            . '     SELECT `a2`.`id` FROM `' . self::ANALYSES_TABLE . '` `a2`'
            . '     WHERE `a2`.`message` = `m`.`id` AND `a2`.`status` = 2'
            . '     ORDER BY `a2`.`analyzed_at` DESC, `a2`.`id` DESC LIMIT 1'
            . ' )'
            . ' WHERE `m`.`docState` IN %in'
            . ' AND `m`.`analysis_state` = %i'
            . ' AND `a`.`canonical_json` IS NOT NULL'
            . ' AND `a`.`resolution` IS NULL'
            . ' AND COALESCE(`a`.`proposed_type`, \'other\') != \'other\''
            . ' ORDER BY `m`.`received_at` DESC, `m`.`id` DESC'
            . ' LIMIT %i',
            [IncomingMessageDocument::DOC_STATE_NEW, IncomingMessageDocument::DOC_STATE_OPEN],
            IncomingMessageDocument::ANALYSIS_ANALYZED,
            $ctx->sourceLimit,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $canonical
     * @return array<string,mixed>
     */
    private function buildSuggestionCard(FeedContext $ctx, FeedTexts $texts, array $row, array $canonical, string $band): array
    {
        $messageNdx  = (int) $row['message_ndx'];
        $analysisNdx = (int) $row['analysis_ndx'];
        $confidence  = isset($row['confidence']) ? (float) $row['confidence'] : null;
        $docType     = (string) ($row['proposed_type'] ?? '');
        $subject     = $this->messageTitle($ctx, $row);

        // Target typu řídí prezentaci karty (titulek/podtitulek) — action
        // kinds i endpointy jsou pro oba targety shodné.
        $extractionTarget = PrimaryTypes::targetFor($ctx->config, $docType);
        $isRegistry = $extractionTarget === PrimaryTypes::TARGET_REGISTRY;

        [$kind, $stateStyle, $icon] = match ($band) {
            AnalysisConfidenceResolver::BAND_READY => ['ready', 'done', 'check'],
            AnalysisConfidenceResolver::BAND_LOW   => ['review', 'edit', 'warning'],
            default                                => ['review', 'confirmed', 'question'],
        };

        $target = ['messageNdx' => $messageNdx];
        // Pásmo ready → jednoklikové apply (safe; 422 unresolved_required
        // klient řeší fall-through do review modalu). Review/low jde přes
        // review modal (kontrola náhledu před potvrzením).
        $actions = $band === AnalysisConfidenceResolver::BAND_READY
            ? [
                ['id' => 'apply',  'kind' => 'apply_message',  'target' => $target, 'primary' => true],
                ['id' => 'review', 'kind' => 'review_message', 'target' => $target],
                ['id' => 'reject', 'kind' => 'reject_message', 'target' => $target],
            ]
            : [
                ['id' => 'review', 'kind' => 'review_message', 'target' => $target, 'primary' => true],
                ['id' => 'reject', 'kind' => 'reject_message', 'target' => $target],
            ];

        $card = [
            'id'         => 'mail_suggestion:' . $messageNdx,
            'source'     => 'mail',
            'kind'       => $kind,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => $isRegistry ? FeedSource::CATEGORY_REGISTRY : FeedSource::CATEGORY_INVOICES,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $isRegistry
                ? $this->registryCardTitle($ctx, $texts, $docType, $canonical)
                : $this->cardTitle($ctx, $texts, $docType, $canonical),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => [
                'messageNdx'  => $messageNdx,
                'analysisNdx' => $analysisNdx,
                'confidence'  => $confidence,
                'target'      => $extractionTarget,
            ],
            'actions'    => $actions,
        ];

        // Strukturovaná hlavička jen když známe partnera — bez něj karta
        // padá na složený title/subtitle fallback (bez headline se subtitle
        // posílá dál, u headline karet už ne — data jsou v ní). Priorita:
        // Osoba zprávy (ruční volba / Použít, D8) > protistrana canonicalu
        // > snapshot partner_name zprávy.
        $partnerName = $this->messagePersonName($row)
            ?? ($isRegistry ? $this->registryPartyName($canonical) : $this->counterpartyName($canonical))
            ?? $this->messagePartnerSnapshot($row);
        if ($partnerName !== null) {
            $headline = [
                'partnerName' => $partnerName,
                'typeLabel'   => $isRegistry
                    ? $this->docKindLabel($ctx, $texts, $docType)
                    : $this->docTypeLabel($ctx, $texts, $docType),
            ];
            $amountText = $isRegistry ? null : $this->formatAmount($canonical);
            if ($amountText !== null) {
                $headline['amountText'] = $amountText;
            }
            $card['headline'] = $headline;
        } else {
            $card['subtitle'] = $isRegistry
                ? $this->registryCardSubtitle($ctx, $texts, $docType, $canonical, $confidence, $subject)
                : $this->cardSubtitle($ctx, $texts, $canonical, $confidence, $subject);
        }

        // Interní numerická pole pro readySummary (Issue #32/2, D8) — jen
        // návrhy dokladů (docs) s částkou i měnou. Controller je po agregaci
        // z karet odstraní; do kartového kontraktu (docs/dashboard.md §4)
        // nepatří.
        if (!$isRegistry) {
            $amount   = $this->amountValue($canonical);
            $currency = is_string($canonical['currency'] ?? null) ? trim((string) $canonical['currency']) : '';
            if ($amount !== null && $currency !== '') {
                $card['amount']   = $amount;
                $card['currency'] = $currency;
            }
        }

        if ($confidence !== null) {
            $card['confidencePct'] = (int) round($confidence * 100);
        }
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        $details = $isRegistry
            ? $this->registryDetails($ctx, $texts, $docType, $canonical)
            : $this->docsDetails($ctx, $texts, $canonical);
        if ($details !== []) {
            $card['details'] = $details;
        }
        $findings = $this->secondaryFindings($ctx, $texts, (string) ($row['analysis_json'] ?? ''));
        if ($findings !== []) {
            $card['secondaryFindings'] = $findings;
        }

        return $card;
    }

    /**
     * Chybová karta pro otevřený návrh s nevalidním výstupem AI (forenzní
     * wrapper z /result) — jediná smysluplná akce je reanalyze.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildInvalidOutputCard(FeedContext $ctx, FeedTexts $texts, array $row, AnalysisErrorPresenter $presenter): array
    {
        $messageNdx = (int) $row['message_ndx'];
        $subject    = $this->messageTitle($ctx, $row);
        $info       = $presenter->forInvalidOutput(
            isset($row['prompt_version']) ? (string) $row['prompt_version'] : null,
        );
        $card = [
            'id'          => 'mail_invalid:' . $messageNdx,
            'source'      => 'mail',
            'kind'        => 'urgent',
            'feedSection' => FeedSource::SECTION_FAILED,
            'icon'       => 'warning',
            'stateStyle' => 'error',
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $info->title,
            'subtitle'   => $this->senderSubtitle($texts, $row, trim((string) ($row['sender_name'] ?? ''))),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => ['messageNdx' => $messageNdx],
            'details'    => $presenter->cardDetails($info),
            'actions'    => $this->failureActions($messageNdx, $info),
        ];
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        return $card;
    }

    /**
     * Řádky zpráv, u kterých permanentně selhala AI (analysis_state=70),
     * mimo Archiv/Koš — pro chybové karty.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchErrorRows(FeedContext $ctx): array
    {
        // Hláška a verze promptu posledního selhaného běhu jako korelované
        // subselecty (ne JOIN — viz PARTNER_FULL_NAME_SQL); feed je
        // stropovaný, žádné N+1.
        $lastFailed = ' FROM `' . self::ANALYSES_TABLE . '` `fa`'
            . ' WHERE `fa`.`message` = `m`.`id` AND `fa`.`status` = 3'
            . ' ORDER BY `fa`.`analyzed_at` DESC, `fa`.`id` DESC LIMIT 1';

        return $ctx->db->fetchAll(
            'SELECT `m`.`id` AS `message_ndx`, `m`.`subject`, `m`.`ai_title`, `m`.`source_type`,'
            . ' `m`.`sender_name`, `m`.`partner_name`,' . self::PARTNER_FULL_NAME_SQL . ','
            . ' `m`.`received_at`, `m`.`primary_type`, `m`.`raw_source_attachment`' . self::PREPROCESS_SQL . ','
            . ' (SELECT `fa`.`error_message`' . $lastFailed . ') AS `error_message`,'
            . ' (SELECT `fa`.`prompt_version`' . $lastFailed . ') AS `failed_prompt_version`'
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' WHERE `m`.`analysis_state` = %i AND `m`.`docState` NOT IN %in'
            . ' ORDER BY `m`.`received_at` DESC, `m`.`id` DESC'
            . ' LIMIT %i',
            IncomingMessageDocument::ANALYSIS_FAILED,
            [IncomingMessageDocument::DOC_STATE_ARCHIVED, IncomingMessageDocument::DOC_STATE_TRASH],
            $ctx->sourceLimit,
        );
    }

    /**
     * Chybová karta — AI selhala; kind=urgent, degradace na review, když
     * dřívější klasifikace už určila `primary_type='other'`. Titulek
     * a `details` z katalogu hlášek podle `error_message` posledního
     * selhaného běhu; subtitle zůstává odesílatel (D3c).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildErrorCard(FeedContext $ctx, FeedTexts $texts, array $row, AnalysisErrorPresenter $presenter): array
    {
        $messageNdx = (int) $row['message_ndx'];
        $subject    = $this->messageTitle($ctx, $row);
        $isOther    = (string) ($row['primary_type'] ?? '') === 'other';
        $info       = $presenter->fromErrorMessage(
            isset($row['error_message']) ? (string) $row['error_message'] : null,
            isset($row['failed_prompt_version']) ? (string) $row['failed_prompt_version'] : null,
        );
        $card = [
            'id'          => 'mail_message:' . $messageNdx,
            'source'      => 'mail',
            'kind'        => $isOther ? 'review' : 'urgent',
            // Nepodařilo se zpracovat (#101 D4) — i degradovaná review karta.
            'feedSection' => FeedSource::SECTION_FAILED,
            'icon'       => 'warning',
            'stateStyle' => 'error',
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $info->title,
            'subtitle'   => $this->senderSubtitle($texts, $row, trim((string) ($row['sender_name'] ?? ''))),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => ['messageNdx' => $messageNdx],
            'details'    => $presenter->cardDetails($info),
            'actions'    => $this->failureActions($messageNdx, $info),
        ];
        if ($subject !== '') {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        return $card;
    }

    /**
     * Akce chybové karty (D4): reanalýza primární, jen když ji katalog
     * doporučuje (výchozí profil má novější prompt); jinak je primární
     * otevření zprávy a reanalýza zůstává sekundární. Primární akce vždy
     * první v poli.
     *
     * @return list<array<string,mixed>>
     */
    private function failureActions(int $messageNdx, AnalysisErrorInfo $info): array
    {
        $reanalyze = ['id' => 'reanalyze', 'kind' => 'reanalyze', 'target' => ['messageNdx' => $messageNdx]];
        $openMail  = ['id' => 'openMail',  'kind' => 'open_detail', 'target' => ['viewerId' => self::INCOMING_VIEWER_ID, 'recordId' => $messageNdx, 'tabId' => 'content']];

        if ($info->reanalysisRecommended) {
            return [$reanalyze + ['primary' => true], $openMail];
        }
        return [$openMail + ['primary' => true], $reanalyze];
    }

    /**
     * Řádky zpráv pro karty ostatní pošty — AI klasifikovala zprávu jako
     * `other`, zpráva zůstala v Nové a nemá otevřený dokumentový návrh.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchNotInvoiceRows(FeedContext $ctx): array
    {
        return $ctx->db->fetchAll(
            'SELECT `m`.`id` AS `message_ndx`, `m`.`subject`, `m`.`ai_title`, `m`.`source_type`,'
            . ' `m`.`sender_name`, `m`.`partner_name`,' . self::PARTNER_FULL_NAME_SQL . ','
            . ' `m`.`sender_email`, `m`.`received_at`, `m`.`primary_type`, `m`.`raw_source_attachment`' . self::PREPROCESS_SQL
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' WHERE `m`.`analysis_state` = %i'
            . ' AND `m`.`docState` = %i'
            . ' AND `m`.`primary_type` = \'other\''
            . ' AND COALESCE(('
            . '     SELECT `a`.`canonical_json` IS NOT NULL AND `a`.`resolution` IS NULL'
            . '     FROM `' . self::ANALYSES_TABLE . '` `a`'
            . '     WHERE `a`.`message` = `m`.`id` AND `a`.`status` = 2'
            . '     ORDER BY `a`.`analyzed_at` DESC, `a`.`id` DESC LIMIT 1'
            . ' ), 0) = 0'
            . ' ORDER BY `m`.`received_at` DESC, `m`.`id` DESC'
            . ' LIMIT %i',
            IncomingMessageDocument::ANALYSIS_ANALYZED,
            IncomingMessageDocument::DOC_STATE_NEW,
            $ctx->sourceLimit,
        );
    }

    /**
     * Karta ostatní pošty — jednoklikový úklid: Koš (primary) / Archiv /
     * otevřít read-only náhled zprávy. Titulek = `ai_title` (AI popis
     * obsahu zprávy), bez něj konstanta `other.title` z katalogu;
     * `emailSubject` jen když se od titulku liší
     * (tasks/dashboard-other-row-title.md D1–D3).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildNotInvoiceCard(FeedContext $ctx, FeedTexts $texts, array $row): array
    {
        $messageNdx = (int) $row['message_ndx'];
        $target = ['messageNdx' => $messageNdx];

        $subject = $this->messageTitle($ctx, $row);
        // D1, D2: titulek = AI popis obsahu zprávy; bez něj konstanta z katalogu.
        $aiTitle = trim((string) ($row['ai_title'] ?? ''));
        $title = $aiTitle !== ''
            ? $aiTitle
            : $texts->t('other.title', 'Contains no document');
        $sender = trim((string) ($row['sender_name'] ?? '')) !== ''
            ? trim((string) $row['sender_name'])
            : trim((string) ($row['sender_email'] ?? ''));

        $card = [
            'id'         => 'mail_notinvoice:' . $messageNdx,
            'source'     => 'mail',
            'kind'       => 'info',
            'icon'       => 'info',
            'stateStyle' => 'archive',
            'category'   => FeedSource::CATEGORY_OTHER,
            'navSection' => FeedSource::NAV_SECTION_TOP,
            'title'      => $title,
            'subtitle'   => $this->senderSubtitle($texts, $row, $sender),
            'timestamp'  => $this->toAtom($row['received_at'] ?? null),
            'context'    => ['messageNdx' => $messageNdx],
            'actions'    => [
                ['id' => 'trash',    'kind' => 'trash_message',   'target' => $target, 'primary' => true],
                ['id' => 'archive',  'kind' => 'archive_message', 'target' => $target],
                ['id' => 'openMail', 'kind' => 'open_detail',     'target' => ['viewerId' => self::INCOMING_VIEWER_ID, 'recordId' => $messageNdx, 'tabId' => 'content']],
            ],
        ];
        // D3: předmět jen když se od titulku liší — u skenů, ručního nahrání
        // a generických předmětů vrací messageTitle() právě ai_title.
        if ($subject !== '' && $subject !== $title) {
            $card['emailSubject'] = $subject;
        }
        $receivedDateText = $this->formatDate($ctx, (string) ($row['received_at'] ?? ''));
        if ($receivedDateText !== null) {
            $card['receivedDateText'] = $receivedDateText;
        }
        return $card;
    }

    /**
     * `warning` karty (tasks/mail-preprocess-error-messages.md D3c): zpráva
     * s předzpracováním ve stavu 40 „Hotovo s chybami" dostane řádek
     * „Předzpracování: {titulek kategorie}" — jen titulek z katalogu,
     * poznámky akcí (nesou URL) na kartu nejdou. Mimo stav 40 beze změny;
     * druh karty (`kind`) se nemění.
     *
     * @param array<string,mixed> $card
     * @param array<string,mixed> $row řádek s `preprocess_state` + `preprocess_log`
     * @return array<string,mixed>
     */
    private function withPreprocessWarning(array $card, array $row, PreprocessErrorPresenter $presenter): array
    {
        $state = (int) ($row['preprocess_state'] ?? 0);
        if ($state !== PreprocessRunner::STATE_DONE_WITH_ERRORS) {
            return $card;
        }
        $info = $presenter->fromLog($state, $row['preprocess_log'] ?? null);
        if ($info !== null) {
            $card['warning'] = $presenter->cardWarning($info);
        }
        return $card;
    }

    /**
     * Batch obsahových příloh pro všechny karty — jeden dotaz na celý collect.
     * Vrací mapu messageNdx → seznam příloh (bez raw `.eml`, řazení
     * `att_order ASC, name ASC`); struktura položky zrcadlí
     * IncomingMessagesViewer::fetchContentAttachments().
     *
     * @param list<array<string,mixed>> $rows řádky s `message_ndx` + `raw_source_attachment`
     * @return array<int, list<array{id: int, name: string, mime_type: string, file_size: int}>>
     */
    private function fetchAttachmentsByMessage(FeedContext $ctx, array $rows): array
    {
        $rawByMessage = [];
        foreach ($rows as $row) {
            $messageNdx = (int) $row['message_ndx'];
            $rawByMessage[$messageNdx] = isset($row['raw_source_attachment']) && $row['raw_source_attachment'] !== null
                ? (int) $row['raw_source_attachment']
                : null;
        }
        if ($rawByMessage === []) {
            return [];
        }

        $files = $ctx->db->fetchAll(
            'SELECT `id`, `record_id`, `name`, `file_name`, `mime_type`, `file_size`'
            . ' FROM `' . self::ATTACHMENTS_TABLE . '`'
            . ' WHERE `table_id` = %i AND `record_id` IN %in AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            self::MESSAGES_TABLE_ID,
            array_keys($rawByMessage),
        );

        $byMessage = [];
        foreach ($files as $f) {
            $messageNdx = (int) $f['record_id'];
            $id = (int) $f['id'];
            if (($rawByMessage[$messageNdx] ?? null) === $id) {
                continue; // raw .eml není obsahová příloha
            }
            $byMessage[$messageNdx][] = [
                'id'        => $id,
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }
        return $byMessage;
    }

    /**
     * Doplní do karty volitelná pole `attachments` (strop MAX_CARD_ATTACHMENTS)
     * + `attachmentsTotal` (počet před stropem). Karta bez příloh pole nemá.
     *
     * @param array<string,mixed> $card
     * @param list<array{id: int, name: string, mime_type: string, file_size: int}> $attachments
     * @return array<string,mixed>
     */
    private function withAttachments(array $card, array $attachments): array
    {
        if ($attachments === []) {
            return $card;
        }
        $card['attachments']      = array_slice($attachments, 0, self::MAX_CARD_ATTACHMENTS);
        $card['attachmentsTotal'] = count($attachments);
        return $card;
    }

    /**
     * Hint dalších nálezů běhu (D7): `secondary_findings` z analysis_json —
     * informativní seznam {type, type_label, note}, žádné entity, žádný stav.
     *
     * @return list<array{type: string, type_label: string, note: string}>
     */
    private function secondaryFindings(FeedContext $ctx, FeedTexts $texts, string $analysisJson): array
    {
        $decoded = json_decode($analysisJson, true);
        $findings = is_array($decoded) ? ($decoded['secondary_findings'] ?? null) : null;
        if (!is_array($findings)) {
            return [];
        }
        $out = [];
        foreach ($findings as $f) {
            if (!is_array($f)) {
                continue;
            }
            $type = trim((string) ($f['type'] ?? ''));
            $out[] = [
                'type' => $type,
                'type_label' => $this->primaryTypeLabel($ctx, $texts, $type),
                'note' => trim((string) ($f['note'] ?? '')),
            ];
        }
        return $out;
    }

    /** Lokalizovaný label primárního typu z cfgItem; fallback na holý key. */
    private function primaryTypeLabel(FeedContext $ctx, FeedTexts $texts, string $primaryType): string
    {
        $cfg = $ctx->config?->cfgItem(self::PRIMARY_TYPES_CFG_ITEM);
        if (is_array($cfg) && isset($cfg[$primaryType]['name']) && is_string($cfg[$primaryType]['name'])) {
            return $cfg[$primaryType]['name'];
        }
        return $primaryType === 'other' ? $texts->t('primaryType.other', 'Other') : $primaryType;
    }

    /**
     * Titulek karty: „{typ dokladu} — {partner}" (partner odvozen z kanonického
     * dokladu podle self-party). Bez partnera jen typ dokladu.
     *
     * @param array<string,mixed> $canonical
     */
    private function cardTitle(FeedContext $ctx, FeedTexts $texts, string $docType, array $canonical): string
    {
        $typeLabel = $this->docTypeLabel($ctx, $texts, $docType);
        $partner   = $this->counterpartyName($canonical);
        return $partner !== null ? ($typeLabel . ' — ' . $partner) : $typeLabel;
    }

    /**
     * Podtitulek: částka · jistota · zdrojový e-mail (jen neprázdné části).
     *
     * @param array<string,mixed> $canonical
     */
    private function cardSubtitle(FeedContext $ctx, FeedTexts $texts, array $canonical, ?float $confidence, string $subject): string
    {
        $parts = [];

        $amount = $this->formatAmount($canonical);
        if ($amount !== null) {
            $parts[] = $amount;
        }
        if ($confidence !== null) {
            $pct = (int) round($confidence * 100);
            $parts[] = $texts->t('confidence', 'confidence {pct} %', ['pct' => $pct]);
        }
        $subject = trim($subject);
        if ($subject !== '') {
            $parts[] = $this->emailSubjectLabel($texts, $subject);
        }

        return implode(' · ', $parts);
    }

    private function emailSubjectLabel(FeedTexts $texts, string $subject): string
    {
        // Dnešní znění končí ASCII uvozovkou; sjednocení je samostatná změna.
        return $texts->t('emailSubject', 'email „{subject}"', ['subject' => $subject]);
    }

    /**
     * Titulek registry karty: „{druh dokumentu} — {protistrana}" (druh
     * z `base.registry.docKinds`, protistrana z `party.name` canonicalu).
     * Bez protistrany jen label druhu.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryCardTitle(FeedContext $ctx, FeedTexts $texts, string $docType, array $canonical): string
    {
        $label = $this->docKindLabel($ctx, $texts, $docType);
        $party = $this->registryPartyName($canonical);
        return $party !== null ? ($label . ' — ' . $party) : $label;
    }

    /**
     * Jméno protistrany registry karty z `party.name` canonicalu; null pro
     * chybějící/prázdné.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryPartyName(array $canonical): ?string
    {
        $party = $canonical['party']['name'] ?? null;
        return is_string($party) && trim($party) !== '' ? trim($party) : null;
    }

    /**
     * Podtitulek registry karty: „platí do {datum}" · jistota · zdrojový
     * e-mail. Klíč kindFields nesoucí konec platnosti se hledá **inverzí**
     * `docKinds[docKind].promote` (hodnota `valid_to`) — jediné místo pravdy
     * pro mapování polí, žádná duplikace výčtu.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryCardSubtitle(
        FeedContext $ctx,
        FeedTexts $texts,
        string $docType,
        array $canonical,
        ?float $confidence,
        string $subject,
    ): string {
        $parts = [];

        $validTo = $this->registryValidTo($ctx, $docType, $canonical);
        if ($validTo !== null) {
            $parts[] = $texts->t('validUntil', 'valid until {date}', ['date' => $validTo]);
        }
        if ($confidence !== null) {
            $pct = (int) round($confidence * 100);
            $parts[] = $texts->t('confidence', 'confidence {pct} %', ['pct' => $pct]);
        }
        $subject = trim($subject);
        if ($subject !== '') {
            $parts[] = $this->emailSubjectLabel($texts, $subject);
        }

        return implode(' · ', $parts);
    }

    /** Lokalizovaný label druhu dokumentu z `base.registry.docKinds`; fallback docTypeLabel. */
    private function docKindLabel(FeedContext $ctx, FeedTexts $texts, string $docType): string
    {
        $docKind = PrimaryTypes::docKindFor($ctx->config, $docType);
        if ($docKind !== null) {
            $kinds = $ctx->config?->cfgItem(self::DOC_KINDS_CFG_ITEM);
            if (is_array($kinds) && isset($kinds[$docKind]['name']) && is_string($kinds[$docKind]['name'])) {
                return $kinds[$docKind]['name'];
            }
        }
        return $this->docTypeLabel($ctx, $texts, $docType);
    }

    /**
     * Konec platnosti z kindFields: klíč = inverze promote mapy druhu
     * (metaKey → 'valid_to'). Vrací lokalizované datum, nebo null.
     *
     * @param array<string,mixed> $canonical
     */
    private function registryValidTo(FeedContext $ctx, string $docType, array $canonical): ?string
    {
        $docKind = PrimaryTypes::docKindFor($ctx->config, $docType);
        if ($docKind === null) {
            return null;
        }
        $kinds = $ctx->config?->cfgItem(self::DOC_KINDS_CFG_ITEM);
        $promote = is_array($kinds) ? ($kinds[$docKind]['promote'] ?? null) : null;
        if (!is_array($promote)) {
            return null;
        }
        $metaKey = array_search('valid_to', $promote, true);
        if (!is_string($metaKey)) {
            return null;
        }

        $value = $canonical['kindFields'][$metaKey] ?? null;
        if (!is_string($value)) {
            return null;
        }
        return $this->formatDate($ctx, $value);
    }

    /** Lokalizované datum (cs `j. n. Y`, en `Y-m-d`); prázdný/nevalidní vstup → null. */
    private function formatDate(FeedContext $ctx, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        return $ctx->language === 'cs' ? $date->format('j. n. Y') : $date->format('Y-m-d');
    }

    /**
     * Řádky expanderu návrhové karty (docs target): číslo dokladu, splatnost,
     * variabilní symbol — jen neprázdné hodnoty, fixní pořadí. Labely
     * lokalizuje server (konzistentní s lokalizací titulků karet).
     *
     * @param array<string,mixed> $canonical
     * @return list<array{label: string, value: string}>
     */
    private function docsDetails(FeedContext $ctx, FeedTexts $texts, array $canonical): array
    {
        $rows = [];

        $docNumber = $canonical['docNumber'] ?? null;
        if (is_string($docNumber) && trim($docNumber) !== '') {
            $rows[] = [
                'label' => $texts->t('detail.docNumber', 'Document number'),
                'value' => trim($docNumber),
            ];
        }

        $dueDate = $canonical['dates']['dueDate'] ?? null;
        $dueDate = is_string($dueDate) ? $this->formatDate($ctx, $dueDate) : null;
        if ($dueDate !== null) {
            $rows[] = [
                'label' => $texts->t('detail.dueDate', 'Due date'),
                'value' => $dueDate,
            ];
        }

        $reference = $canonical['payment']['paymentReference'] ?? null;
        if (is_int($reference)) {
            $reference = (string) $reference;
        }
        if (is_string($reference) && trim($reference) !== '') {
            $rows[] = [
                'label' => $texts->t('detail.paymentReference', 'Payment reference'),
                'value' => trim($reference),
            ];
        }

        return $rows;
    }

    /**
     * Expander registry karty: jediný řádek „Platí do" z konce platnosti
     * (`registryValidTo`); bez něj se `details` neposílá.
     *
     * @param array<string,mixed> $canonical
     * @return list<array{label: string, value: string}>
     */
    private function registryDetails(FeedContext $ctx, FeedTexts $texts, string $docType, array $canonical): array
    {
        $validTo = $this->registryValidTo($ctx, $docType, $canonical);
        if ($validTo === null) {
            return [];
        }
        return [[
            'label' => $texts->t('detail.validUntil', 'Valid until'),
            'value' => $validTo,
        ]];
    }

    /** Lokalizovaný label typu dokladu z cfgItem; fallback na holý key. */
    private function docTypeLabel(FeedContext $ctx, FeedTexts $texts, string $docType): string
    {
        if ($docType === '') {
            return $texts->t('docType.fallback', 'Document');
        }
        $cfg = $ctx->config?->cfgItem(self::PRIMARY_TYPES_CFG_ITEM);
        if (is_array($cfg) && isset($cfg[$docType]['name']) && is_string($cfg[$docType]['name'])) {
            return $cfg[$docType]['name'];
        }
        return $docType;
    }

    /**
     * Lidský titulek zprávy pro `emailSubject` — předmět, u generického /
     * prázdného předmětu a ručních zpráv `ai_title` (pravidlo D3,
     * tasks/mail-message-title-partner.md). Prázdný řetězec = bez titulku.
     *
     * @param array<string,mixed> $row
     */
    private function messageTitle(FeedContext $ctx, array $row): string
    {
        return IncomingMessageTitle::display(
            (string) ($row['subject'] ?? ''),
            isset($row['ai_title']) ? (string) $row['ai_title'] : null,
            (int) ($row['source_type'] ?? 0),
            IncomingMessageTitle::patternsFrom($ctx->config),
        );
    }

    /**
     * Jméno Osoby partnera zprávy (`partner_person` → `partner_full_name`
     * ze subselectu); null bez Osoby. Má přednost před canonicalem — ruční
     * volba i Použít jsou autoritativní (D8).
     *
     * @param array<string,mixed> $row
     */
    private function messagePersonName(array $row): ?string
    {
        $name = trim((string) ($row['partner_full_name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    /**
     * Snapshot jména protistrany na zprávě (`partner_name`); null bez něj.
     *
     * @param array<string,mixed> $row
     */
    private function messagePartnerSnapshot(array $row): ?string
    {
        $name = trim((string) ($row['partner_name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    /**
     * Subtitle chybové karty a karty ostatní pošty: „partner · od: odesílatel"
     * když zpráva partnera má (Osoba, jinak snapshot), jinak jen odesílatel
     * (D7 — zrcadlí t2/t3 vieweru).
     *
     * @param array<string,mixed> $row
     */
    private function senderSubtitle(FeedTexts $texts, array $row, string $sender): string
    {
        $partner = $this->messagePersonName($row) ?? $this->messagePartnerSnapshot($row);
        if ($partner === null) {
            return $sender;
        }
        if ($sender === '') {
            return $partner;
        }
        return $texts->t('partnerFrom', '{partner} · from: {sender}', ['partner' => $partner, 'sender' => $sender]);
    }

    /**
     * Jméno protistrany z kanonického dokladu. Protistrana = strana, kterou
     * nejsme my (`selfParty`); default supplier (přijatá faktura).
     *
     * @param array<string,mixed> $canonical
     */
    private function counterpartyName(array $canonical): ?string
    {
        $selfParty = $canonical['selfParty'] ?? null;
        $key = $selfParty === 'supplier' ? 'customer' : 'supplier';
        $name = $canonical[$key]['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        // Fallback — zkus druhou stranu, kdyby self-party chybělo.
        $other = $key === 'supplier' ? 'customer' : 'supplier';
        $name = $canonical[$other]['name'] ?? null;
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Číselná hodnota celkové částky z canonical totals; null když chybí nebo
     * není číselná. Sdílené mezi formatAmount() a interními poli pro
     * readySummary — text na kartě a serverový souhrn musí vycházet z téže
     * hodnoty.
     *
     * @param array<string,mixed> $canonical
     */
    private function amountValue(array $canonical): ?float
    {
        $total = $canonical['totals']['totalAmount'] ?? null;
        if (!is_int($total) && !is_float($total) && !(is_string($total) && is_numeric($total))) {
            return null;
        }
        return (float) $total;
    }

    /**
     * Naformátuje celkovou částku „{amount} {currency}" z canonical totals.
     *
     * @param array<string,mixed> $canonical
     */
    private function formatAmount(array $canonical): ?string
    {
        $amount = $this->amountValue($canonical);
        if ($amount === null) {
            return null;
        }
        $currency = is_string($canonical['currency'] ?? null) ? (string) $canonical['currency'] : '';
        $formatted = number_format($amount, 2, ',', ' ');
        return $currency !== '' ? ($formatted . ' ' . $currency) : $formatted;
    }

    /** DB datetime → ATOM; null/prázdné → null. */
    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
