<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_mail_sender_rules`.
 *
 * Zodpovědnosti:
 *   - validace vzoru: povinný, formát dle pattern_kind (e-mail s `@`,
 *     doména bez `@` a s tečkou)
 *   - unikátnost (pattern_kind, pattern) mezi živými pravidly
 *     (docState 10/40/80) — koš/smazání reuse vzoru neblokuje
 *   - normalizace patternu (trim, lowercase) a defaulty v beforeSave
 *     (dispozice `archiveIfOther`, {@see SenderRuleDispositions::DEFAULT})
 *   - audit pole (created, modified)
 *   - přechod docState pro `stateChanged` handlery (vzor
 *     DocDocument::trackStateChange) — potvrzení pravidla (→ 40) spouští
 *     {@see SenderRuleConfirmedHandler} (D8), ať jde o kartu návrhu
 *     nebo uložení formuláře
 */
class SenderRuleDocument extends Document
{
    /** docState hodnoty (core.system.docStatesArchive), ve kterých pravidlo "žije". */
    private const LIVE_DOC_STATES = [10, 40, 80];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $kind = (string) ($data['pattern_kind'] ?? 'email');
        $pattern = strtolower(trim((string) ($data['pattern'] ?? '')));

        if ($pattern === '') {
            $result->addError('pattern', 'Vzor je povinný', 'required');
            return $result;
        }

        if ($kind === 'email') {
            if (filter_var($pattern, FILTER_VALIDATE_EMAIL) === false) {
                $result->addError('pattern', 'Vzor není platná e-mailová adresa', 'invalid_format');
            }
        } elseif ($kind === 'domain') {
            if (str_contains($pattern, '@') || !str_contains($pattern, '.')) {
                $result->addError('pattern', 'Doména se zadává bez @ a musí obsahovat tečku', 'invalid_format');
            }
        }

        if ($result->isValid() && $this->db !== null) {
            $existing = $this->findLiveDuplicate($kind, $pattern, (int) ($data['id'] ?? 0));
            if ($existing !== null) {
                $result->addError(
                    'pattern',
                    "Živé pravidlo pro tento vzor již existuje (id {$existing})",
                    'duplicate_pattern',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        $this->trackStateChange($data, $originalData);

        if (isset($data['pattern'])) {
            $data['pattern'] = strtolower(trim((string) $data['pattern']));
        }

        if ($isNew) {
            if (empty($data['pattern_kind'])) {
                $data['pattern_kind'] = 'email';
            }
            // D9: ručně zakládané pravidlo je bezpečné — archivuje až po
            // analýze, když zpráva nenese doklad. „Archivovat hned“ je
            // vědomá volba (tasks/mail-sender-rules-after-analysis.md).
            if (empty($data['disposition'])) {
                $data['disposition'] = SenderRuleDispositions::DEFAULT;
            }
            if (empty($data['origin'])) {
                $data['origin'] = 'user';
            }
            if (!isset($data['hit_count'])) {
                $data['hit_count'] = 0;
            }
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }
        $data['modified'] = $now;
    }

    /**
     * Přechod docState pro TableGateway → dispatch `stateChanged`. Nový
     * záznam vzniklý rovnou mimo Koncept (např. ručně založené pravidlo
     * uložené jako potvrzené) je taky přechod s old = 0.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $originalData
     */
    private function trackStateChange(array $data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);
        if ($newState !== $oldState) {
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
        }
    }

    /** Vrátí id živého pravidla se stejným (pattern_kind, pattern), nebo null. */
    private function findLiveDuplicate(string $kind, string $pattern, int $excludeId): ?int
    {
        $row = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %s AND %n = %s AND %n IN %in AND %n != %i LIMIT 1',
            'id',
            'core_mail_sender_rules',
            'pattern_kind',
            $kind,
            'pattern',
            $pattern,
            'docState',
            self::LIVE_DOC_STATES,
            'id',
            $excludeId,
        );

        return $row !== null ? (int) ((array) $row)['id'] : null;
    }
}
