<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Výsledek jedné akce předzpracování. Selhání je provozní stav (expirovaný
 * odkaz, cizí doména, timeout) — zapisuje se do `preprocess_log.results`,
 * nikdy nevyletí jako výjimka (D6). Selhání nese vedle volné poznámky
 * i strukturovaný kód {@see PreprocessFailureCode}, podle kterého se
 * vybírá hláška pro uživatele (tasks/mail-preprocess-error-messages.md D1).
 */
final readonly class ActionResult
{
    /**
     * @param list<int> $attachmentIds Id vygenerovaných příloh (obsahové
     *        přílohy zprávy s provenance metadaty).
     * @param string $code Kód selhání (PreprocessFailureCode::*); u úspěchu prázdný.
     */
    public function __construct(
        public bool $ok,
        public string $note = '',
        public array $attachmentIds = [],
        public string $code = '',
    ) {
    }

    /** @param list<int> $attachmentIds */
    public static function success(string $note = '', array $attachmentIds = []): self
    {
        return new self(true, $note, $attachmentIds);
    }

    /**
     * @param string $code Jeden z PreprocessFailureCode::* — povinný, bez
     *        něj by uživatel dostal jen obecné „skončilo s chybou".
     */
    public static function failure(string $note, string $code): self
    {
        return new self(false, $note, [], $code);
    }
}
