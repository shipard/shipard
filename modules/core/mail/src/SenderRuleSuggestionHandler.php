<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\AbstractDocumentEventHandler;

/**
 * Učení pravidel odesílatelů ze zpětné vazby (Fáze 3 — šum, design §8):
 * když uživatel ručně odklidí (Archiv 80 / Koš 90) opakovaně poštu od
 * téhož odesílatele, handler navrhne pravidlo — vznikne jako Koncept (10)
 * s `origin='suggested'` a na dashboardu se objeví karta k potvrzení.
 *
 * Počítají se výhradně ruční akce: auto-archivované zprávy
 * (`auto_disposed_by` NOT NULL) se ignorují — jak spouštěcí přechod, tak
 * v COUNT. Ingest handler nespouští vůbec (vkládá mimo TableGateway),
 * takže pre-triage zásahy se neučí samy ze sebe.
 *
 * Návrhy jsou vždy exact e-mail; doménové pravidlo zakládá uživatel ručně.
 * Duplicitní návrhy nevznikají — existuje-li živé pravidlo (10/40/80) pro
 * e-mail nebo jeho doménu, handler končí.
 *
 * Pojistka pro smíšené odesílatele (tasks/mail-sender-rules-after-analysis.md
 * D1, D2): když od adresy už přišel doklad nebo dokument — zpráva navázaná
 * na cílovou entitu (`target_row`), nebo s `primary_type` ≠ `other` určeným
 * AI, ISDOC importem či uživatelem, v libovolném stavu včetně Koše a Archivu
 * — navrhne se dispozice `archiveIfOther` (archivovat až po analýze, jen
 * zprávy bez dokladu). Výchozí typ schránky bez navázané entity nic
 * nedokazuje a nepočítá se. Jinak `archive` jako dřív.
 *
 * Nikdy neblokuje přechod zprávy: běží po commitu a dispatcher výjimky
 * z onStateChanged loguje a polyká.
 *
 * Ne-final kvůli testům — Connection::query je final, testy přepisují
 * executeSql subclassingem (vzor SupplierCodeCaptureHandler).
 */
class SenderRuleSuggestionHandler extends AbstractDocumentEventHandler
{
    /** Práh ručních odklizení, od kterého vzniká návrh (Otevřený bod 1 PRD). */
    private const THRESHOLD = 3;

    /** docState zpráv (core.mail.docStatesIncoming). */
    private const MSG_STATE_ARCHIVED = 80;
    private const MSG_STATE_TRASH = 90;

    /** docState pravidel (core.system.docStatesArchive). */
    private const RULE_STATE_DRAFT = 10;
    private const RULE_LIVE_STATES = [10, 40, 80];

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        if ($newState !== self::MSG_STATE_ARCHIVED && $newState !== self::MSG_STATE_TRASH) {
            return;
        }

        // Audit sloupce čteme z DB — $data nemusí nést všechny sloupce.
        $row = $this->db->fetch(
            'SELECT [sender_email], [auto_disposed_by]
             FROM [core_mail_incoming_messages] WHERE [id] = %i',
            (int) $data['id'],
        );
        if ($row === null || $row['auto_disposed_by'] !== null) {
            return;
        }

        $email = strtolower(trim((string) ($row['sender_email'] ?? '')));
        if ($email === '' || !str_contains($email, '@')) {
            return;
        }

        if ($this->countManualDisposals($email) < self::THRESHOLD) {
            return;
        }

        $domain = substr($email, (int) strrpos($email, '@') + 1);
        if ($this->liveRuleExists($email, $domain)) {
            return;
        }

        $this->insertSuggestion($email);
    }

    /** Ruční odklizení (Archiv/Koš) zpráv téhož odesílatele, bez auto-archivu. */
    private function countManualDisposals(string $email): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM [core_mail_incoming_messages]
             WHERE LOWER([sender_email]) = %s
               AND [docState] IN %in
               AND [auto_disposed_by] IS NULL',
            $email,
            [self::MSG_STATE_ARCHIVED, self::MSG_STATE_TRASH],
        );

        return $row !== null ? (int) ((array) $row)['cnt'] : 0;
    }

    private function liveRuleExists(string $email, string $domain): bool
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_mail_sender_rules]
             WHERE [docState] IN %in
               AND (([pattern_kind] = %s AND [pattern] = %s)
                 OR ([pattern_kind] = %s AND [pattern] = %s))
             LIMIT 1',
            self::RULE_LIVE_STATES,
            'email',
            $email,
            'domain',
            $domain,
        );

        return $row !== null;
    }

    /**
     * D1: od adresy existuje zpráva navázaná na cílovou entitu, nebo
     * s typem jiným než `other`, který určila AI, ISDOC nebo uživatel.
     * Historie z importu se počítá přes navázanou entitu.
     */
    private function senderHasDocuments(string $email): bool
    {
        $row = $this->db->fetch(
            'SELECT 1 FROM [core_mail_incoming_messages]
             WHERE LOWER([sender_email]) = %s
               AND ([target_row] IS NOT NULL
                    OR ([primary_type] <> %s AND [primary_type_source] IN %in))
             LIMIT 1',
            $email,
            PrimaryTypes::OTHER,
            ['ai', 'isdoc', 'user'],
        );

        return $row !== null;
    }

    private function insertSuggestion(string $email): void
    {
        // D2: odesílateli s doklady se navrhne bezpečná dispozice.
        $hasDocuments = $this->senderHasDocuments($email);
        $data = [
            'pattern_kind' => 'email',
            'pattern' => $email,
            'disposition' => $hasDocuments
                ? SenderRuleDispositions::ARCHIVE_IF_OTHER
                : SenderRuleDispositions::ARCHIVE,
            'origin' => 'suggested',
            'notice' => $hasDocuments
                ? sprintf('Navrženo po %d ručních odklizeních; od adresy chodí i doklady', self::THRESHOLD)
                : sprintf('Navrženo po %d ručních odklizeních', self::THRESHOLD),
            'docState' => self::RULE_STATE_DRAFT,
            'docStateMain' => 1,
        ];

        // Normalizace + audit pole přes Document (jednotné chování se saveDocument).
        $doc = new SenderRuleDocument();
        $doc->beforeSave($data);

        $this->executeSql('INSERT INTO [core_mail_sender_rules] %v', $data);
    }

    /**
     * Wrapper nad Connection::query (final, nelze mockovat) — testy
     * přepisují subclassingem, stejný vzor jako SupplierCodeCaptureHandler.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db?->query(...$args);
    }
}
